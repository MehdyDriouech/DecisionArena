<?php
namespace Domain\Orchestration;

use Domain\DecisionReliability\MemorySummaryBuilder;
use Infrastructure\Persistence\DebateRepository;

class DebateMemoryService {
    private DebateRepository $repo;

    public function __construct(?DebateRepository $repo = null) {
        $this->repo = $repo ?? new DebateRepository();
    }

    public function loadState(string $sessionId): array {
        $arguments = $this->repo->findArgumentsBySession($sessionId);
        $positions = $this->repo->findPositionsBySession($sessionId);
        $edges     = $this->repo->findEdgesBySession($sessionId);
        return [
            'arguments' => $arguments,
            'positions' => $positions,
            'edges'     => $edges,
        ];
    }

    public function buildPromptContext(array $state): array {
        $summary = $this->buildArgumentMemorySummary($state['arguments'] ?? [], $state['positions'] ?? []);
        return [
            'argument_memory_summary' => $summary,
            'weighted_analysis'       => $this->buildWeightedAnalysis($state),
        ];
    }

    public function processMessage(
        string $sessionId,
        int $round,
        string $agentId,
        string $content,
        ?string $targetAgentId,
        array &$state,
        string $edgeSource = 'unknown'
    ): void {
        $lastPosition = $this->findLatestPositionForAgent($state['positions'] ?? [], $agentId);
        $position = $this->extractPosition($content, $lastPosition);
        $positionRow = $this->repo->createPosition([
            'id'                      => $this->uuid(),
            'session_id'              => $sessionId,
            'round'                   => $round,
            'agent_id'                => $agentId,
            'stance'                  => $position['stance'],
            'confidence'              => $position['confidence'],
            'impact'                  => $position['impact'],
            'domain_weight'           => $position['domain_weight'],
            'weight_score'            => $position['weight_score'],
            'main_argument'           => $position['main_argument'],
            'biggest_risk'            => $position['biggest_risk'],
            'change_since_last_round' => $position['change_since_last_round'],
            'created_at'              => date('c'),
        ]);
        $state['positions'][] = $positionRow;

        $arguments = $this->extractArguments($content);
        if (empty($arguments)) {
            $arguments = [['type' => 'claim', 'text' => $this->truncate($content, 500)]];
        }

        $primaryArgumentId = null;
        foreach ($arguments as $idx => $argument) {
            $linkedTarget = $this->resolveTargetArgumentId(
                $argument['type'],
                $targetAgentId,
                $state['arguments'] ?? []
            );
            $strength = min(10, max(1, $position['confidence']));
            $row = $this->repo->createArgument([
                'id'                 => $this->uuid(),
                'session_id'         => $sessionId,
                'round'              => $round,
                'agent_id'           => $agentId,
                'argument_text'      => $argument['text'],
                'argument_type'      => $argument['type'],
                'target_argument_id' => $linkedTarget,
                'strength'           => $strength,
                'created_at'         => date('c'),
            ]);
            if ($idx === 0) {
                $primaryArgumentId = $row['id'];
            }
            $state['arguments'][] = $row;
        }

        $contract = $this->parseInteractionContract($content);
        $targetResolution = $this->resolveEdgeTarget($targetAgentId, $edgeSource, $content, $agentId, $state);
        $targetAgentId = $targetResolution['target_agent_id'];
        $edgeSource = $targetResolution['edge_source'];

        if ($targetAgentId) {
            $edgeType = $this->resolveEdgeType($content);
            $edgeConfidence = $this->computeEdgeConfidence($edgeSource, $content);
            $edgeWeight = (int)round(($position['confidence'] + ($edgeType === 'challenge' ? 8 : 5)) / 2);

            $targetMismatch = 0;
            $parsedTarget = $contract['target_agent'];
            if ($parsedTarget !== null && $parsedTarget !== '') {
                if (mb_strtolower(trim($parsedTarget), 'UTF-8') !== mb_strtolower(trim($targetAgentId), 'UTF-8')) {
                    $targetMismatch = 1;
                }
            }

            $edgePayload = [
                'id'              => $this->uuid(),
                'session_id'      => $sessionId,
                'round'           => $round,
                'source_agent_id' => $agentId,
                'target_agent_id' => $targetAgentId,
                'edge_type'       => $edgeType,
                'edge_source'     => $edgeSource,
                'edge_confidence' => $edgeConfidence,
                'weight'          => min(10, max(1, $edgeWeight)),
                'argument_id'     => $primaryArgumentId,
                'claim_challenged'=> $contract['claim_challenged'],
                'objection'       => $contract['objection'],
                'concession'      => $contract['concession'],
                'position_change' => $contract['position_change'],
                'target_mismatch' => $targetMismatch,
                'created_at'      => date('c'),
            ];
            $edgePayload['verified_interaction'] = MemorySummaryBuilder::isVerifiedStructuredInteraction($edgePayload) ? 1 : 0;

            $edge = $this->repo->createEdge($edgePayload);
            $state['edges'][] = $edge;
        }
    }

    /**
     * Parses explicit interaction-contract sections from agent markdown (prompt contract).
     *
     * @return array{
     *   target_agent:?string,
     *   claim_challenged:?string,
     *   objection:?string,
     *   concession:?string,
     *   position_change:?string
     * }
     */
    private function parseInteractionContract(string $content): array {
        $targetBody = $this->extractMarkdownHeadingBody($content, 'Target Agent');
        $claimBody = $this->extractMarkdownHeadingBody($content, 'Claim Challenged');
        $objectionBody = $this->extractMarkdownHeadingBody($content, 'Objection');
        $concessionBody = $this->extractMarkdownHeadingBody($content, 'Concession');
        $positionBody = $this->extractMarkdownHeadingBody($content, 'Position Change');

        $target = $targetBody !== null
            ? $this->normalizeContractScalar($targetBody, 120, true)
            : null;

        $claim = null;
        if ($claimBody !== null) {
            $claim = $this->normalizeContractScalar($this->stripBalancedQuotes($claimBody), 500, false);
        }
        $objection = null;
        if ($objectionBody !== null) {
            $objection = $this->normalizeContractScalar($this->stripBalancedQuotes($objectionBody), 1000, false);
        }
        $concession = null;
        if ($concessionBody !== null) {
            $concession = $this->normalizeContractScalar($this->stripBalancedQuotes($concessionBody), 1000, false);
        }
        $positionChange = null;
        if ($positionBody !== null) {
            $positionChange = $this->normalizeInteractionPositionChange($positionBody);
        }

        return [
            'target_agent' => $target,
            'claim_challenged' => $claim,
            'objection' => $objection,
            'concession' => $concession,
            'position_change' => $positionChange,
        ];
    }

    private function extractMarkdownHeadingBody(string $content, string $headingText): ?string {
        $h = preg_quote($headingText, '/');
        if (!preg_match('/^##\s*' . $h . '\s*$/mi', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $tail = substr($content, $start);
        if ($tail === '') {
            return null;
        }
        if (preg_match('/\A([\s\S]*?)(?=(?:\r\n|\n|\r)##\s)/', $tail, $bm)) {
            $body = $bm[1];
        } else {
            $body = $tail;
        }
        $body = trim($body);
        return $body === '' ? null : $body;
    }

    private function normalizeContractScalar(?string $raw, int $maxLen, bool $firstLineOnly): ?string {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        if ($firstLineOnly) {
            $lines = preg_split('/\R/u', $s) ?: [];
            $s = trim((string)($lines[0] ?? ''));
        }
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(none|n\/a|n\.a\.|null)$/iu', $s)) {
            return null;
        }
        return $this->truncate($s, $maxLen);
    }

    private function stripBalancedQuotes(string $s): string {
        $s = trim($s);
        if (preg_match('/^(\x{201C}|")([\s\S]+)(\x{201D}|")$/u', $s, $m)) {
            return trim($m[2]);
        }
        return $s;
    }

    private function normalizeInteractionPositionChange(string $body): ?string {
        $lines = preg_split('/\R/u', trim($body)) ?: [];
        $raw = trim((string)($lines[0] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(none|n\/a|n\.a\.|null)$/iu', $raw)) {
            return null;
        }
        $v = mb_strtolower($raw, 'UTF-8');
        if (in_array($v, ['unchanged', 'weakened', 'strengthened', 'changed'], true)) {
            return $v;
        }
        return null;
    }

    public function buildArgumentMemorySummary(array $arguments, array $positions): string {
        if (empty($arguments) && empty($positions)) {
            return "No prior arguments yet.\n- Start with clear claims and explicit risks.";
        }

        $bySignature = [];
        foreach ($arguments as $arg) {
            $text = trim((string)($arg['argument_text'] ?? ''));
            if ($text === '') continue;
            $signature = mb_strtolower($this->truncate($text, 120), 'UTF-8');
            if (!isset($bySignature[$signature])) {
                $bySignature[$signature] = ['text' => $text, 'count' => 0, 'strength' => 0];
            }
            $bySignature[$signature]['count'] += 1;
            $bySignature[$signature]['strength'] += (int)($arg['strength'] ?? 1);
        }
        uasort($bySignature, fn($a, $b) => (($b['count'] * 2 + $b['strength']) <=> ($a['count'] * 2 + $a['strength'])));
        $topArguments = array_slice(array_values($bySignature), 0, 5);

        $risks = array_values(array_filter($arguments, fn($a) => ($a['argument_type'] ?? '') === 'risk'));
        usort($risks, fn($a, $b) => ((int)($b['strength'] ?? 1) <=> (int)($a['strength'] ?? 1)));
        $keyRisks = array_slice($risks, 0, 3);

        $disagreements = $this->findDisagreements($positions);

        $lines = [];
        $lines[] = "Top arguments so far:";
        if (empty($topArguments)) {
            $lines[] = "- None yet";
        } else {
            foreach ($topArguments as $arg) {
                $lines[] = '- ' . $this->truncate($arg['text'], 170);
            }
        }
        $lines[] = "";
        $lines[] = "Unresolved disagreements:";
        if (empty($disagreements)) {
            $lines[] = "- No major disagreement yet";
        } else {
            foreach (array_slice($disagreements, 0, 3) as $d) {
                $lines[] = '- ' . $d;
            }
        }
        $lines[] = "";
        $lines[] = "Key risks:";
        if (empty($keyRisks)) {
            $lines[] = "- No explicit risk registered yet";
        } else {
            foreach ($keyRisks as $risk) {
                $lines[] = '- ' . $this->truncate((string)$risk['argument_text'], 170);
            }
        }
        return implode("\n", $lines);
    }

    public function buildWeightedAnalysis(array $state): array {
        $positions = $state['positions'] ?? [];
        $arguments = $state['arguments'] ?? [];
        if (empty($positions)) {
            return [
                'dominant_position' => 'needs-more-info',
                'strongest_arguments' => [],
                'conflicting_high_weight_opinions' => [],
                'weak_signals' => [],
            ];
        }

        $latestByAgent = [];
        foreach ($positions as $pos) {
            $agent = $pos['agent_id'] ?? 'agent';
            if (!isset($latestByAgent[$agent]) || (int)$pos['round'] >= (int)$latestByAgent[$agent]['round']) {
                $latestByAgent[$agent] = $pos;
            }
        }

        $stanceScores = [];
        foreach ($latestByAgent as $pos) {
            $stance = $pos['stance'] ?? 'needs-more-info';
            $stanceScores[$stance] = ($stanceScores[$stance] ?? 0) + (float)($pos['weight_score'] ?? 0);
        }
        arsort($stanceScores);
        $dominant = array_key_first($stanceScores) ?? 'needs-more-info';

        $strongestArguments = $this->computeStrongestArguments($arguments, $latestByAgent);
        $conflicts = $this->computeConflictingOpinions($latestByAgent);
        $weakSignals = array_values(array_filter($latestByAgent, fn($p) => (float)($p['weight_score'] ?? 0) <= 4.0));

        return [
            'dominant_position' => $dominant,
            'strongest_arguments' => array_map(function ($a) {
                return [
                    'argument' => $a['text'],
                    'reuse_count' => $a['count'],
                    'score' => round($a['score'], 2),
                ];
            }, array_slice($strongestArguments, 0, 5)),
            'conflicting_high_weight_opinions' => array_slice($conflicts, 0, 5),
            'weak_signals' => array_map(function ($p) {
                return [
                    'agent_id' => $p['agent_id'],
                    'stance' => $p['stance'],
                    'weight_score' => (float)$p['weight_score'],
                ];
            }, array_values($weakSignals)),
        ];
    }

    public function buildDominanceIndicator(array $state): string {
        $analysis = $this->buildWeightedAnalysis($state);
        $positions = $state['positions'] ?? [];
        if (empty($positions)) {
            return 'No dominant signal yet.';
        }
        $latestByAgent = [];
        foreach ($positions as $p) {
            $agent = $p['agent_id'] ?? 'agent';
            if (!isset($latestByAgent[$agent]) || (int)$p['round'] >= (int)$latestByAgent[$agent]['round']) {
                $latestByAgent[$agent] = $p;
            }
        }
        usort($latestByAgent, fn($a, $b) => ((float)$b['weight_score'] <=> (float)$a['weight_score']));
        $leaders = array_slice($latestByAgent, 0, 2);
        $names = array_map(fn($p) => (string)$p['agent_id'], $leaders);
        $highConflict = count($analysis['conflicting_high_weight_opinions'] ?? []) > 0;
        if ($highConflict && count($names) >= 2) {
            return 'High disagreement between ' . $names[0] . ' and ' . $names[1] . '.';
        }
        if (count($names) >= 2) {
            return $names[0] . ' + ' . $names[1] . ' dominate the decision momentum.';
        }
        return $names[0] . ' dominates the decision momentum.';
    }

    private function extractArguments(string $content): array {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $map = [
            'claims' => 'claim',
            'claim' => 'claim',
            'risks' => 'risk',
            'risk' => 'risk',
            'assumptions' => 'assumption',
            'assumption' => 'assumption',
            'counter arguments' => 'counter_argument',
            'counter argument' => 'counter_argument',
            'counter-arguments' => 'counter_argument',
            'counter-argument' => 'counter_argument',
            'open questions' => 'question',
            'open question' => 'question',
            'questions' => 'question',
            'question' => 'question',
        ];

        $currentType = null;
        $arguments = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            $heading = mb_strtolower(trim(str_replace('#', '', $trimmed), " \t\n\r\0\x0B:-"), 'UTF-8');
            if (isset($map[$heading])) {
                $currentType = $map[$heading];
                continue;
            }

            if (preg_match('/^[-*•]\s+(.+)$/u', $trimmed, $m) || preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m)) {
                $text = trim($m[1]);
                if ($text !== '') {
                    $arguments[] = ['type' => $currentType ?? 'claim', 'text' => $this->truncate($text, 500)];
                }
            }
        }

        if (empty($arguments)) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
                $arguments[] = ['type' => 'claim', 'text' => $this->truncate($trimmed, 500)];
                break;
            }
        }
        return $arguments;
    }

    private function extractPosition(string $content, ?array $previous): array {
        $stance = $this->parseTextValue($content, ['stance']) ?? ($previous['stance'] ?? 'needs-more-info');
        $stance = $this->normalizeStance($stance);
        $confidence = $this->parseScaleValue($content, ['confidence'], (int)($previous['confidence'] ?? 5));
        $impact = $this->parseScaleValue($content, ['impact'], (int)($previous['impact'] ?? 5));
        $domainWeight = $this->parseScaleValue($content, ['domain weight', 'domain_weight'], (int)($previous['domain_weight'] ?? 5));
        $mainArgument = $this->parseTextValue($content, ['main argument']) ?? '';
        $biggestRisk = $this->parseTextValue($content, ['biggest risk']) ?? '';
        $change = $this->parseTextValue($content, ['change since last round']) ?? '';
        $weightScore = round(($confidence + $impact + $domainWeight) / 3, 2);

        return [
            'stance'                  => $stance,
            'confidence'              => $confidence,
            'impact'                  => $impact,
            'domain_weight'           => $domainWeight,
            'weight_score'            => $weightScore,
            'main_argument'           => $this->truncate($mainArgument, 500),
            'biggest_risk'            => $this->truncate($biggestRisk, 500),
            'change_since_last_round' => $this->truncate($change, 500),
        ];
    }

    private function parseTextValue(string $content, array $labels): ?string {
        foreach ($labels as $label) {
            $escaped = preg_quote($label, '/');
            if (preg_match('/(?:^|\n)\s*(?:##\s*)?' . $escaped . '\s*\n+([^\n#][^\n]*)/i', $content, $m)) {
                $value = trim($m[1]);
                if ($value !== '') return $value;
            }
            if (preg_match('/' . $escaped . '\s*:\s*([^\n]+)/i', $content, $m)) {
                $value = trim($m[1]);
                if ($value !== '') return $value;
            }
        }
        return null;
    }

    private function parseScaleValue(string $content, array $labels, int $default): int {
        $value = $default;
        foreach ($labels as $label) {
            $escaped = preg_quote($label, '/');
            if (preg_match('/' . $escaped . '\s*:\s*(\d{1,2})/i', $content, $m) ||
                preg_match('/(?:^|\n)\s*(?:##\s*)?' . $escaped . '\s*\n+\s*(\d{1,2})\b/i', $content, $m)) {
                $value = (int)$m[1];
                break;
            }
        }
        return min(10, max(0, $value));
    }

    private function normalizeStance(string $stance): string {
        $raw = mb_strtolower(trim($stance), 'UTF-8');
        if (str_contains($raw, 'support')) return 'support';
        if (str_contains($raw, 'oppose')) return 'oppose';
        if (str_contains($raw, 'reduce')) return 'reduce-scope';
        if (str_contains($raw, 'alternative')) return 'alternative';
        return 'needs-more-info';
    }

    private function resolveTargetArgumentId(string $argumentType, ?string $targetAgentId, array $existingArguments): ?string {
        if (!$targetAgentId || $argumentType !== 'counter_argument') {
            return null;
        }
        for ($i = count($existingArguments) - 1; $i >= 0; $i--) {
            $arg = $existingArguments[$i];
            if (($arg['agent_id'] ?? null) === $targetAgentId) {
                return $arg['id'] ?? null;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{target_agent_id:?string,edge_source:string}
     */
    private function resolveEdgeTarget(?string $targetAgentId, string $edgeSource, string $content, string $authorAgentId, array $state): array {
        $edgeSource = $this->normalizeEdgeSource($edgeSource);
        if ($targetAgentId !== null && trim($targetAgentId) !== '') {
            return [
                'target_agent_id' => trim($targetAgentId),
                'edge_source' => $edgeSource,
            ];
        }

        $inferred = $this->inferMentionedTargetAgent($content, $authorAgentId, $state);
        if ($inferred !== null) {
            return [
                'target_agent_id' => $inferred,
                'edge_source' => 'inferred_mention',
            ];
        }

        return [
            'target_agent_id' => null,
            'edge_source' => 'unknown',
        ];
    }

    private function normalizeEdgeSource(string $source): string {
        $source = strtolower(trim($source));
        return in_array($source, ['explicit_target', 'assigned_fallback', 'inferred_mention', 'unknown'], true)
            ? $source
            : 'unknown';
    }

    private function computeEdgeConfidence(string $source, string $content): float {
        $source = $this->normalizeEdgeSource($source);
        if ($source === 'explicit_target') {
            return $this->hasQuotedClaim($content) ? 0.9 : 0.75;
        }
        return match ($source) {
            'assigned_fallback' => 0.45,
            'inferred_mention'  => 0.6,
            default             => 0.3,
        };
    }

    private function hasQuotedClaim(string $content): bool {
        return preg_match('/(?:^|\n)\s*#{1,3}\s*(?:claim challenged|quoted claim)\s*\n+\s*(?:[-*]\s*)?\S.{10,}/iu', $content) === 1
            || preg_match('/\b(?:claim challenged|quoted claim)\s*:\s*\S.{10,}/iu', $content) === 1
            || preg_match('/^\s*>\s+\S.+$/mu', $content) === 1
            || preg_match('/["“][^"”]{12,}["”]/u', $content) === 1;
    }

    /**
     * Mention inference is intentionally conservative: only @agent-id and
     * [agent-id] count. Plain words are too ambiguous for honest graph edges.
     *
     * @param array<string,mixed> $state
     */
    private function inferMentionedTargetAgent(string $content, string $authorAgentId, array $state): ?string {
        $candidateIds = [];
        foreach (['positions', 'arguments', 'edges'] as $bucket) {
            foreach (($state[$bucket] ?? []) as $row) {
                foreach (['agent_id', 'source_agent_id', 'target_agent_id'] as $key) {
                    $id = trim((string)($row[$key] ?? ''));
                    if ($id !== '' && $id !== $authorAgentId && $id !== 'synthesizer') {
                        $candidateIds[] = $id;
                    }
                }
            }
        }
        $candidateIds = array_values(array_unique($candidateIds));
        foreach ($candidateIds as $id) {
            $quoted = preg_quote($id, '/');
            if (preg_match('/@' . $quoted . '\b/i', $content) === 1
                || preg_match('/\[' . $quoted . '\]/i', $content) === 1) {
                return $id;
            }
        }
        return null;
    }

    private function resolveEdgeType(string $content): string {
        $lc = mb_strtolower($content, 'UTF-8');

        // Defense header has highest priority (before challenge keywords)
        if (preg_match('/##\s*response\s+to\s+challenges?\b/i', $content)) {
            return 'defense';
        }

        // Explicit adversarial section headers take next priority
        if (preg_match('/##\s*challenge\b/i', $content)) {
            return 'challenge';
        }
        if (preg_match('/##\s*minority report\b/i', $content)) {
            return 'challenge';
        }

        // Strong challenge keywords
        $challengeKeywords = [
            'disagree', 'counter', 'objection', 'i challenge', 'i contest',
            'i refute', 'this is incorrect', 'this is wrong', 'weak assumption',
            'unsupported claim', 'unsupported assumption', 'missing evidence',
            'invalid assumption', 'insufficient evidence', 'fails to account',
            'overlooks the risk', 'ignores', 'does not address', 'position changed: yes',
            'this claim fails', 'this overlooks', 'no evidence', 'unsubstantiated',
            'je conteste', 'je réfute', 'je ne suis pas', 'hypothèse faible',
            'preuve manquante', 'insuffisant', 'en désaccord',
        ];
        foreach ($challengeKeywords as $kw) {
            if (str_contains($lc, $kw)) {
                return 'challenge';
            }
        }

        // Defense markers (secondary defense phrases — headers already caught above)
        if (str_contains($lc, 'responding to the challenge')
            || str_contains($lc, 'addressing the objection')
            || str_contains($lc, 'to defend my position')) {
            return 'defense';
        }

        // Alliance / explicit support
        if (preg_match('/##\s*(alliance|alignment)\b/i', $content)) {
            return 'support';
        }
        $supportKeywords = [
            'agree', 'support', 'align with', 'concur', 'endorse',
            'reinforce', 'this is correct', 'validates', 'confirms',
            'je suis d\'accord', 'je confirme', 'je soutiens', 'en accord',
        ];
        foreach ($supportKeywords as $kw) {
            if (str_contains($lc, $kw)) {
                return 'support';
            }
        }

        return 'neutral';
    }

    private function findLatestPositionForAgent(array $positions, string $agentId): ?array {
        $latest = null;
        foreach ($positions as $position) {
            if (($position['agent_id'] ?? '') !== $agentId) continue;
            if ($latest === null || (int)$position['round'] >= (int)$latest['round']) {
                $latest = $position;
            }
        }
        return $latest;
    }

    private function computeStrongestArguments(array $arguments, array $latestByAgent): array {
        $weightsByAgent = [];
        foreach ($latestByAgent as $agent => $pos) {
            $weightsByAgent[$agent] = (float)($pos['weight_score'] ?? 1.0);
        }
        $bucket = [];
        foreach ($arguments as $arg) {
            $text = trim((string)($arg['argument_text'] ?? ''));
            if ($text === '') continue;
            $signature = mb_strtolower($this->truncate($text, 120), 'UTF-8');
            if (!isset($bucket[$signature])) {
                $bucket[$signature] = ['text' => $text, 'count' => 0, 'score' => 0.0];
            }
            $bucket[$signature]['count'] += 1;
            $agent = $arg['agent_id'] ?? '';
            $bucket[$signature]['score'] += ($weightsByAgent[$agent] ?? 1.0);
        }
        uasort($bucket, fn($a, $b) => (($b['count'] + $b['score']) <=> ($a['count'] + $a['score'])));
        return array_values($bucket);
    }

    private function computeConflictingOpinions(array $latestByAgent): array {
        $high = array_values(array_filter($latestByAgent, fn($p) => (float)($p['weight_score'] ?? 0) >= 7.0));
        $conflicts = [];
        for ($i = 0; $i < count($high); $i++) {
            for ($j = $i + 1; $j < count($high); $j++) {
                if (($high[$i]['stance'] ?? '') === ($high[$j]['stance'] ?? '')) {
                    continue;
                }
                $conflicts[] = [
                    'agent_a' => $high[$i]['agent_id'],
                    'stance_a' => $high[$i]['stance'],
                    'weight_a' => (float)$high[$i]['weight_score'],
                    'agent_b' => $high[$j]['agent_id'],
                    'stance_b' => $high[$j]['stance'],
                    'weight_b' => (float)$high[$j]['weight_score'],
                ];
            }
        }
        return $conflicts;
    }

    private function findDisagreements(array $positions): array {
        if (empty($positions)) return [];
        $latestByAgent = [];
        foreach ($positions as $pos) {
            $agent = $pos['agent_id'] ?? '';
            if ($agent === '') continue;
            if (!isset($latestByAgent[$agent]) || (int)$pos['round'] >= (int)$latestByAgent[$agent]['round']) {
                $latestByAgent[$agent] = $pos;
            }
        }
        $stances = [];
        foreach ($latestByAgent as $agent => $pos) {
            $stances[$pos['stance'] ?? 'needs-more-info'][] = $agent;
        }
        if (count(array_keys($stances)) <= 1) {
            return [];
        }
        $lines = [];
        foreach ($stances as $stance => $agents) {
            $lines[] = $stance . ': ' . implode(', ', $agents);
        }
        return $lines;
    }

    private function truncate(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text, 'UTF-8') <= $max) return $text;
        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

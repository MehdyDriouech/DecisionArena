<?php
namespace Domain\Orchestration;

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
        array &$state
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

        if ($targetAgentId) {
            $edgeType = $this->resolveEdgeType($content);
            $edgeWeight = (int)round(($position['confidence'] + ($edgeType === 'challenge' ? 8 : 5)) / 2);
            $edge = $this->repo->createEdge([
                'id'              => $this->uuid(),
                'session_id'      => $sessionId,
                'round'           => $round,
                'source_agent_id' => $agentId,
                'target_agent_id' => $targetAgentId,
                'edge_type'       => $edgeType,
                'weight'          => min(10, max(1, $edgeWeight)),
                'argument_id'     => $primaryArgumentId,
                'created_at'      => date('c'),
            ]);
            $state['edges'][] = $edge;
        }
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

    private function resolveEdgeType(string $content): string {
        $lc = mb_strtolower($content, 'UTF-8');
        if (str_contains($lc, 'disagree') || str_contains($lc, 'counter') || str_contains($lc, 'objection')) {
            return 'challenge';
        }
        if (str_contains($lc, 'agree') || str_contains($lc, 'support')) {
            return 'support';
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

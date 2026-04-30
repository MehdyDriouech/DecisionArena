<?php
namespace Domain\Orchestration;

class ArgumentHeatmapService {

    private const KEYWORDS = ['risk', 'concern', 'assumption', 'argument', 'recommendation', 'critical', 'important', 'problem'];

    public function audit(
        string $sessionId,
        array  $arguments,
        array  $messages,
        array  $votes,
        array  $edges,
        array  $positions
    ): array {
        if (!empty($arguments)) {
            $items = $this->buildFromArguments($arguments, $messages, $edges, $positions);
        } else {
            $items = $this->buildFromMessages($messages);
        }

        // Sort by dominance_score DESC
        usort($items, fn($a, $b) => $b['dominance_score'] <=> $a['dominance_score']);

        return ['items' => array_slice($items, 0, 20)];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function buildFromArguments(
        array $arguments,
        array $messages,
        array $edges,
        array $positions
    ): array {
        // Pre-compute max values for normalization
        $mentionCounts   = [];
        $challengeCounts = [];
        $supportCounts   = [];

        foreach ($arguments as $arg) {
            $argId    = $arg['id'];
            $snippet  = mb_strtolower(mb_substr($arg['argument_text'] ?? '', 0, 30));

            // Mentions: count messages containing snippet
            $mentions = 0;
            if ($snippet !== '') {
                foreach ($messages as $msg) {
                    if (mb_stripos($msg['content'] ?? '', $snippet) !== false) {
                        $mentions++;
                    }
                }
            }
            $mentionCounts[$argId] = $mentions;

            // Challenge / support from edges
            $challengeCount = 0;
            $supportCount   = 0;
            foreach ($edges as $edge) {
                if (($edge['argument_id'] ?? null) === $argId) {
                    if ($edge['edge_type'] === 'challenge') $challengeCount++;
                    if ($edge['edge_type'] === 'support')   $supportCount++;
                }
            }
            $challengeCounts[$argId] = $challengeCount;
            $supportCounts[$argId]   = $supportCount;
        }

        $maxMentions  = max(1, max($mentionCounts) ?: 1);
        $maxChallenge = max(1, max($challengeCounts) ?: 1);
        $maxSupport   = max(1, max($supportCounts) ?: 1);

        $items = [];
        foreach ($arguments as $arg) {
            $argId   = $arg['id'];
            $agentId = $arg['agent_id'] ?? 'unknown';
            $text    = $arg['argument_text'] ?? '';
            $snippet = mb_strtolower(mb_substr($text, 0, 30));

            $mentions      = $mentionCounts[$argId];
            $challengeCount = $challengeCounts[$argId];
            $supportCount   = $supportCounts[$argId];

            // Agents that mentioned this argument
            $agentSet = [$agentId];
            if ($snippet !== '') {
                foreach ($messages as $msg) {
                    $msgAgentId = $msg['agent_id'] ?? null;
                    if ($msgAgentId && mb_stripos($msg['content'] ?? '', $snippet) !== false) {
                        $agentSet[] = $msgAgentId;
                    }
                }
            }
            $agentSet = array_values(array_unique(array_filter($agentSet)));

            // Average weight_score from positions of agents who mentioned this argument
            $weight = 5.0;
            $relevantWeights = [];
            foreach ($positions as $pos) {
                if (in_array($pos['agent_id'] ?? null, $agentSet, true)) {
                    $relevantWeights[] = (float)($pos['weight_score'] ?? 5.0);
                }
            }
            if (!empty($relevantWeights)) {
                $weight = array_sum($relevantWeights) / count($relevantWeights);
            }

            // dominance_score: normalized composite
            $rawScore = ($mentions / $maxMentions) * 3
                      + ($challengeCount / $maxChallenge) * 2
                      + ($supportCount / $maxSupport)
                      + ($weight / 10.0) * 4;
            $maxRaw = 3 + 2 + 1 + 4; // 10
            $dominance = min(1.0, max(0.0, $rawScore / $maxRaw));

            $type = $this->classifyType($arg['argument_type'] ?? 'claim', $text);

            $items[] = [
                'id'              => $argId,
                'label'           => mb_substr($text, 0, 80),
                'type'            => $type,
                'agents'          => $agentSet,
                'mentions'        => $mentions,
                'challenge_count' => $challengeCount,
                'support_count'   => $supportCount,
                'weight'          => round($weight, 2),
                'dominance_score' => round($dominance, 4),
                'summary'         => mb_substr($text, 0, 200),
            ];
        }

        return $items;
    }

    private function buildFromMessages(array $messages): array {
        $seen  = [];
        $items = [];

        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $agentId = $msg['agent_id'] ?? 'unknown';
            $lines   = preg_split('/\r?\n/', $content);

            foreach ($lines as $line) {
                $line = trim($line);
                if (mb_strlen($line) < 20) continue;

                $lineLower = mb_strtolower($line);
                $hasKeyword = false;
                foreach (self::KEYWORDS as $kw) {
                    if (mb_strpos($lineLower, $kw) !== false) {
                        $hasKeyword = true;
                        break;
                    }
                }
                if (!$hasKeyword) continue;

                $dedup = mb_substr($line, 0, 60);
                if (in_array($dedup, $seen, true)) continue;
                $seen[] = $dedup;

                $type = $this->classifyType('claim', $line);
                $items[] = [
                    'id'              => md5($dedup),
                    'label'           => mb_substr($line, 0, 80),
                    'type'            => $type,
                    'agents'          => [$agentId],
                    'mentions'        => 1,
                    'challenge_count' => 0,
                    'support_count'   => 0,
                    'weight'          => 5.0,
                    'dominance_score' => 0.0,
                    'summary'         => mb_substr($line, 0, 200),
                ];
            }
        }

        return $items;
    }

    private function classifyType(string $argType, string $text): string {
        if ($argType === 'counter_argument') return 'counter_argument';
        if ($argType === 'assumption')       return 'assumption';

        $lower = mb_strtolower($text);
        if (mb_strpos($lower, 'risk') !== false || mb_strpos($lower, 'danger') !== false) {
            return 'risk';
        }
        if (mb_strpos($lower, 'assume') !== false || mb_strpos($lower, 'assumption') !== false) {
            return 'assumption';
        }
        if (mb_strpos($lower, 'counter') !== false || mb_strpos($lower, 'disagree') !== false) {
            return 'counter_argument';
        }
        return 'claim';
    }
}

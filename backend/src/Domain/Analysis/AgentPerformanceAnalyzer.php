<?php
declare(strict_types=1);
namespace Domain\Analysis;

/**
 * Read-only heuristics on post-mortem history — produces suggestions only (never applied here).
 */
final class AgentPerformanceAnalyzer {
    private const MIN_CORRECT_TOPIC = 3;
    private const MIN_INCORRECT_TOPIC = 2;
    private const MIN_CORRECT_GLOBAL = 5;
    private const DELTA_INCREASE = 0.1;
    private const DELTA_DECREASE = -0.1;

    /**
     * @param list<array<string,mixed>> $postmortemRows {@see PostmortemRepository::findAllWithSessions()}
     * @return list<array<string,mixed>>
     */
    public function buildSuggestions(array $postmortemRows): array {
        if ($postmortemRows === []) {
            return [];
        }

        /** @var array<string, array<string, array{correct:int,incorrect:int,partial:int,total:int}>> */
        $byTopicAgent = [];
        /** @var array<string, array{correct:int,incorrect:int,partial:int,total:int}> */
        $globalAgent = [];

        foreach ($postmortemRows as $row) {
            $agents = $this->parseAgents($row['selected_agents'] ?? '[]');
            if ($agents === []) {
                continue;
            }
            $topicKey = $this->topicKey(
                (string)($row['mode'] ?? ''),
                (string)($row['initial_prompt'] ?? '')
            );
            $outcome = (string)($row['outcome'] ?? '');

            foreach ($agents as $agentId) {
                if (!isset($byTopicAgent[$topicKey][$agentId])) {
                    $byTopicAgent[$topicKey][$agentId] = [
                        'correct' => 0, 'incorrect' => 0, 'partial' => 0, 'total' => 0,
                    ];
                }
                if (!isset($globalAgent[$agentId])) {
                    $globalAgent[$agentId] = [
                        'correct' => 0, 'incorrect' => 0, 'partial' => 0, 'total' => 0,
                    ];
                }
                $byTopicAgent[$topicKey][$agentId]['total']++;
                $globalAgent[$agentId]['total']++;
                if ($outcome === 'correct') {
                    $byTopicAgent[$topicKey][$agentId]['correct']++;
                    $globalAgent[$agentId]['correct']++;
                } elseif ($outcome === 'incorrect') {
                    $byTopicAgent[$topicKey][$agentId]['incorrect']++;
                    $globalAgent[$agentId]['incorrect']++;
                } elseif ($outcome === 'partial') {
                    $byTopicAgent[$topicKey][$agentId]['partial']++;
                    $globalAgent[$agentId]['partial']++;
                }
            }
        }

        /** @var list<array<string,mixed>> */
        $out = [];

        foreach ($byTopicAgent as $topicKey => $agents) {
            foreach ($agents as $agentId => $st) {
                $mode = $this->modeFromTopicKey($topicKey, $postmortemRows);
                if ($st['correct'] >= self::MIN_CORRECT_TOPIC && $st['incorrect'] === 0) {
                    $out[] = $this->mkIncrease(
                        $agentId,
                        self::DELTA_INCREASE,
                        $st['correct'],
                        'topic_bucket',
                        $topicKey,
                        (string)$mode,
                        $this->confidenceFromCounts($st['correct'], max(1, $st['total']))
                    );
                }
                if ($st['incorrect'] >= self::MIN_INCORRECT_TOPIC && $st['correct'] === 0) {
                    $out[] = $this->mkDecrease(
                        $agentId,
                        self::DELTA_DECREASE,
                        $st['incorrect'],
                        'topic_bucket',
                        $topicKey,
                        (string)$mode,
                        $this->confidenceFromCounts($st['incorrect'], max(1, $st['total']))
                    );
                }
            }
        }

        foreach ($globalAgent as $agentId => $st) {
            if ($st['correct'] >= self::MIN_CORRECT_GLOBAL
                && $st['incorrect'] <= 1
                && !$this->hasSuggestionForAgent($out, $agentId, 'increase_reputation')
            ) {
                $mode = '~';
                $out[] = $this->mkIncrease(
                    $agentId,
                    self::DELTA_INCREASE,
                    $st['correct'],
                    'global',
                    '*',
                    $mode,
                    $this->confidenceFromCounts($st['correct'], max(1, $st['total']), 0.12)
                );
            }
            if (
                ($st['incorrect'] >= max(self::MIN_INCORRECT_TOPIC, 3))
                && ($st['correct'] === 0)
                && !$this->hasSuggestionForAgent($out, $agentId, 'decrease_reputation')
            ) {
                $out[] = $this->mkDecrease(
                    $agentId,
                    self::DELTA_DECREASE,
                    $st['incorrect'],
                    'global',
                    '*',
                    '~',
                    $this->confidenceFromCounts($st['incorrect'], max(1, $st['total']), 0.11)
                );
            }
        }

        usort($out, fn($a, $b) => (float)($b['confidence'] ?? 0) <=> (float)($a['confidence'] ?? 0));

        return $this->dedupeByAgentPreferTopic($out);
    }

    public function topicKeyForSession(string $mode, string $initialPrompt): string {
        return $this->topicKey($mode, $initialPrompt);
    }

    private function topicKey(string $mode, string $prompt): string {
        $norm = preg_replace('/\s+/u', ' ', mb_strtolower(trim(mb_substr($prompt, 0, 420))));
        $norm = is_string($norm) ? $norm : '';
        return hash('sha256', $mode . "\n" . $norm);
    }

    /** @param list<array<string,mixed>> $suggestions */
    private function dedupeByAgentPreferTopic(array $suggestions): array {
        /** @var array<string,array<string,mixed>> $best */
        $best = [];
        foreach ($suggestions as $s) {
            $atype = (($s['suggestion'] ?? '') === 'decrease_reputation') ? 'dec' : 'inc';
            $key   = (($s['agent_id'] ?? '') . '|' . $atype);
            $prev  = $best[$key] ?? null;
            if ($prev === null) {
                $best[$key] = $s;
                continue;
            }
            $prevTopic = (($prev['evidence']['similarity_basis'] ?? '') === 'topic_bucket');
            $newTopic  = (($s['evidence']['similarity_basis'] ?? '') === 'topic_bucket');
            if ($newTopic && !$prevTopic) {
                $best[$key] = $s;
                continue;
            }
            if ($newTopic === $prevTopic && (float)($s['confidence'] ?? 0) > (float)($prev['confidence'] ?? 0)) {
                $best[$key] = $s;
            }
        }
        return array_values($best);
    }

    /** @param list<array<string,mixed>> $out */
    private function hasSuggestionForAgent(array $out, string $agentId, string $type): bool {
        foreach ($out as $s) {
            if (($s['agent_id'] ?? '') === $agentId && ($s['suggestion'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    private function modeFromTopicKey(string $topicKey, array $rows): string {
        foreach ($rows as $row) {
            if ($this->topicKey((string)($row['mode'] ?? ''), (string)($row['initial_prompt'] ?? '')) === $topicKey) {
                return (string)($row['mode'] ?? '');
            }
        }
        return '';
    }

    private function confidenceFromCounts(int $hits, int $total, float $scale = 0.14): float {
        return round(min(0.92, 0.42 + ($hits / max(4, $total)) * ($scale * 25)), 2);
    }

    /**
     * @return list<string>
     */
    private function parseAgents(mixed $json): array {
        if (!is_string($json) && !is_array($json)) {
            return [];
        }
        $arr = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($arr)) {
            return [];
        }
        $ids = [];
        foreach ($arr as $x) {
            $id = is_string($x) ? preg_replace('/[^a-z0-9\-]/', '', $x) : '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<string,mixed>|string $personaAgentsIdList */
    public function filterSuggestionsForSession(
        array $suggestions,
        string $currentTopicKey,
        array $sessionAgentIds
    ): array {
        $want = [];
        foreach ($sessionAgentIds as $x) {
            $id = is_string($x) ? preg_replace('/[^a-z0-9\-]/', '', $x) : '';
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        if ($want === []) {
            return [];
        }

        return array_values(array_filter($suggestions, function ($s) use ($want, $currentTopicKey) {
            if (!isset($want[(string)($s['agent_id'] ?? '')])) {
                return false;
            }
            $basis = $s['evidence']['similarity_basis'] ?? '';
            $tk    = $s['evidence']['topic_key'] ?? '';
            return $basis === 'global'
                || ($basis === 'topic_bucket' && $tk === $currentTopicKey);
        }));
    }

    /** @internal */
    public function suggestionId(string $agentId, string $suggestion, string $topicKeyOrStar): string {
        return 'dda_' . substr(hash('sha256', $agentId . '|' . $suggestion . '|' . $topicKeyOrStar), 0, 20);
    }

    private function mkIncrease(
        string $agentId,
        float $delta,
        int $sessionCount,
        string $basis,
        string $topicKey,
        string $modeLabel,
        float $confidence
    ): array {
        return [
            'id'                      => $this->suggestionId($agentId, 'increase_reputation', $topicKey),
            'agent_id'                => $agentId,
            'suggestion'              => 'increase_reputation',
            'reputation_delta'        => $delta,
            'reason_key'              => $basis === 'global'
                ? 'dynamicsReco.reasonIncreaseGlobal'
                : 'dynamicsReco.reasonIncreaseTopic',
            'reason_args'             => [
                'count'           => $sessionCount,
                'delta'           => $delta,
                'mode'            => $modeLabel,
                'similar_sessions'=> $sessionCount,
            ],
            'confidence'              => min(0.95, max(0.35, $confidence)),
            'evidence'                => [
                'similarity_basis'  => $basis,
                'topic_key'        => $topicKey,
                'sessions_count'    => $sessionCount,
                'mode'             => $modeLabel,
                'postmortem_filter'=> 'correct',
            ],
            'explicit_notice' => 'Recommendation only — confirm before applying.',
        ];
    }

    private function mkDecrease(
        string $agentId,
        float $delta,
        int $sessionCount,
        string $basis,
        string $topicKey,
        string $modeLabel,
        float $confidence
    ): array {
        return [
            'id'                      => $this->suggestionId($agentId, 'decrease_reputation', $topicKey),
            'agent_id'                => $agentId,
            'suggestion'              => 'decrease_reputation',
            'reputation_delta'        => $delta,
            'reason_key'              => $basis === 'global'
                ? 'dynamicsReco.reasonDecreaseGlobal'
                : 'dynamicsReco.reasonDecreaseTopic',
            'reason_args'             => [
                'count' => $sessionCount,
                'delta' => abs($delta),
                'mode'  => $modeLabel,
            ],
            'confidence'              => min(0.92, max(0.38, $confidence)),
            'evidence'                => [
                'similarity_basis'  => $basis,
                'topic_key'        => $topicKey,
                'sessions_count'    => $sessionCount,
                'mode'             => $modeLabel,
                'postmortem_filter'=> 'incorrect',
            ],
            'explicit_notice' => 'Recommendation only — confirm before applying.',
        ];
    }
}

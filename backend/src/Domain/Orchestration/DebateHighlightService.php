<?php
namespace Domain\Orchestration;

/**
 * Context-aware panel recommendations from debate heuristics (no LLM).
 */
class DebateHighlightService {

    /**
     * @param array<int,array> $edges
     * @param array<int,array> $arguments
     * @param array<string,mixed> $auditResult — output of DebateAuditService::audit()
     * @param array<int,array> $votes
     * @param ?array<string,mixed> $decision session_decisions row or null
     * @return list<array{type: string, reason_key: string}>
     */
    public function compute(
        array $edges,
        array $arguments,
        array $auditResult,
        int $agentMessageCount,
        array $votes,
        ?array $decision
    ): array {
        $metrics = $auditResult['metrics'] ?? [];
        $score   = (int)($auditResult['score'] ?? 0);
        $warns   = $auditResult['warnings'] ?? [];

        $byType = [];

        $dq = (float)($metrics['disagreement_quality'] ?? 0);
        if ($dq >= 4.0 || $this->challengedArgumentRatio($arguments) >= 0.28) {
            $byType['heatmap'] = ['type' => 'heatmap', 'reason_key' => 'strong_disagreement'];
        }

        $idens = (float)($metrics['interaction_density'] ?? 0);
        if (count($edges) >= 5 || $idens >= 5.5) {
            $byType['graph'] = ['type' => 'graph', 'reason_key' => 'complex_interactions'];
        }

        if ($score < 58 || count($warns) >= 2) {
            $byType['audit'] = ['type' => 'audit', 'reason_key' => 'quality_concerns'];
        }

        if ($agentMessageCount >= 8 && count($edges) >= 2) {
            $byType['replay'] = ['type' => 'replay', 'reason_key' => 'rich_timeline'];
        }

        if (count($votes) >= 2 && $decision) {
            $ds = (float)($decision['decision_score'] ?? 0.5);
            if ($ds > 0.38 && $ds < 0.72) {
                $byType['votes'] = ['type' => 'votes', 'reason_key' => 'close_decision'];
            }
        }

        return array_values($byType);
    }

    /** @param array<int,array> $arguments */
    private function challengedArgumentRatio(array $arguments): float {
        if ($arguments === []) return 0.0;
        $ch = 0;
        foreach ($arguments as $a) {
            if (!empty($a['target_argument_id'])) {
                $ch++;
            }
        }
        return $ch / count($arguments);
    }
}

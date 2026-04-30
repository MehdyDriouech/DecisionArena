<?php
namespace Domain\DecisionReliability;

class DevilAdvocateTriggerPolicy {
    /**
     * @param array{partial_confidence_history?:array<int,float>,context_quality?:array<string,mixed>} $state
     */
    public function shouldTrigger(
        int $round,
        float $partialConfidence,
        float $configuredThreshold,
        array $state = []
    ): bool {
        $history = $state['partial_confidence_history'] ?? [];
        $contextQuality = $state['context_quality'] ?? ['level' => 'strong'];
        $level = (string)($contextQuality['level'] ?? 'strong');

        // Existing behavior
        if ($partialConfidence > $configuredThreshold) {
            return true;
        }

        // Consensus rises too quickly
        if ($round <= 2 && $partialConfidence >= 0.58) {
            return true;
        }

        // Early convergence trajectory (proxy for confidence timeline)
        if (count($history) >= 1) {
            $previous = (float)end($history);
            if ($round <= 3 && ($partialConfidence - $previous) >= 0.18 && $partialConfidence >= 0.55) {
                return true;
            }
        }

        // Context-sensitive pressure
        if (in_array($level, ['weak', 'medium'], true) && $partialConfidence >= 0.50) {
            return true;
        }

        return false;
    }
}

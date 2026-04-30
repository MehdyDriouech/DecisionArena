<?php
namespace Domain\Orchestration;

class RoundPolicy {
    public const DEFAULT_ROUNDS = 2;
    public const MAX_ROUNDS = 5;

    public function getRoundInstruction(int $round, int $totalRounds): string {
        return match(true) {
            $round === 1 => 'Round 1 - Independent Analysis: Provide your independent analysis. Do not react to other agents yet. Be concise and structured.',
            $round === 2 => 'Round 2 - Cross-Challenge: React to the strongest disagreement, risk, or missing point from the previous round. Challenge assumptions.',
            $round >= 3  => 'Round 3+ - Consolidation: Provide your final position, confidence level, one must-do, and one thing to avoid.',
            default      => 'Provide your analysis.',
        };
    }

    public function isSynthesizerRound(int $round, int $totalRounds): bool {
        return $round === $totalRounds;
    }
}

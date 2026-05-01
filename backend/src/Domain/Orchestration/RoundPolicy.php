<?php
namespace Domain\Orchestration;

class RoundPolicy {
    public const DEFAULT_ROUNDS = 2;
    public const MAX_ROUNDS = 5;

    public const ROUND_OPENING   = 'opening';
    public const ROUND_CHALLENGE = 'challenge';
    public const ROUND_ALLIANCE  = 'alliance';
    public const ROUND_DEFENSE   = 'defense';
    public const ROUND_SYNTHESIS = 'synthesis';

    /** Debate-phase type (excluding synthesizer special cases handled in callers). */
    public function getRoundType(int $round, int $totalRounds): string {
        if ($totalRounds <= 1) {
            return self::ROUND_OPENING;
        }
        if ($round === 1) {
            return self::ROUND_OPENING;
        }
        if ($round === $totalRounds) {
            return self::ROUND_SYNTHESIS;
        }
        if ($round === 2) {
            return self::ROUND_CHALLENGE;
        }
        // Middle rounds alternate defense / alliance
        return (($round - 3) % 2 === 0) ? self::ROUND_DEFENSE : self::ROUND_ALLIANCE;
    }

    public function getRoundTypeDirective(string $roundType, bool $forceStrongContradiction = false): string {
        $directive = match ($roundType) {
            self::ROUND_OPENING => 'Opening: State your independent view, key assumptions and main risks.',
            self::ROUND_CHALLENGE => 'Challenge: Directly challenge the weakest assumption from another agent (evidence-backed). Name the reasoning you contest.',
            self::ROUND_ALLIANCE => 'Alliance: Identify one agent whose reasoning partially matches yours and explain the shared inference chain.',
            self::ROUND_DEFENSE => 'Defense: Defend your position against the strongest opposing argument surfaced so far.',
            self::ROUND_SYNTHESIS => 'Synthesis posture: Clarify whether your position changed and why (what evidence or argument moved you).',
            default => '',
        };
        $suffix = '';
        if ($forceStrongContradiction && $roundType !== self::ROUND_OPENING) {
            $suffix = ' **Extra contradiction pass (moderator-flagged):** press the strongest counter-case; do not soften with generic agreement.';
        }
        return trim($directive . $suffix);
    }

    public function getRoundInstruction(int $round, int $totalRounds, bool $forceStrongContradiction = false): string {
        $legacy = match(true) {
            $round === 1 => 'Round 1 - Independent Analysis: Provide your independent analysis. Do not react to other agents yet. Be concise and structured.',
            $round === 2 => 'Round 2 - Cross-Challenge: React to the strongest disagreement, risk, or missing point from the previous round. Challenge assumptions.',
            $round >= 3  => 'Round 3+ - Consolidation: Provide your final position, confidence level, one must-do, and one thing to avoid.',
            default      => 'Provide your analysis.',
        };
        if ($totalRounds <= 1) {
            return $legacy;
        }
        return $legacy . "\n\n**Round mindset:** " . $this->getRoundTypeDirective($this->getRoundType($round, $totalRounds), $forceStrongContradiction);
    }

    public function isSynthesizerRound(int $round, int $totalRounds): bool {
        return $round === $totalRounds;
    }
}

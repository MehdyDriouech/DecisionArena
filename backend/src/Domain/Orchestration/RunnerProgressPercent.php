<?php
namespace Domain\Orchestration;

/**
 * Run-status progress percent (estimated) shared across orchestration runners.
 */
final class RunnerProgressPercent
{
    /**
     * Decision Room, Jury, Stress Test — base tour + fraction intra-tour (0..1), plafonné à 99 %.
     */
    public static function roundRunPercent(int $currentRound, int $totalRounds, float $withinRound): int
    {
        $safeTotal = max(1, $totalRounds);
        $base = max(0.02, (($currentRound - 1) / $safeTotal));
        $intra = max(0.0, min(1.0, $withinRound)) * (1.0 / $safeTotal);
        return (int)round(min(0.99, $base + $intra) * 100);
    }

    /**
     * Confrontation — courbe historique (floor, within plafonné à 0.99 ajouté au tour courant).
     */
    public static function confrontationRunPercent(int $currentRound, int $totalRounds, float $withinRound): int
    {
        $safeTotal = max(1, $totalRounds);
        $safeCurrent = min(max(1, $currentRound), $safeTotal);
        $ratio = (($safeCurrent - 1) + min(max($withinRound, 0.0), 0.99)) / $safeTotal;
        $pct = (int)floor($ratio * 100);
        return max(1, min(99, $pct));
    }
}

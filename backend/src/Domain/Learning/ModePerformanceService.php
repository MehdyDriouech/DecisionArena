<?php

declare(strict_types=1);

namespace Domain\Learning;

/**
 * Computes per-mode performance from enriched outcome records.
 */
class ModePerformanceService
{
    private const MIN_SESSIONS = 3;

    private const MODE_LABELS = [
        'decision-room'  => 'Decision Room',
        'confrontation'  => 'Confrontation',
        'quick-decision' => 'Quick Decision',
        'stress-test'    => 'Stress Test',
        'jury'           => 'Jury',
        'chat'           => 'Chat',
    ];

    /**
     * @param list<array<string,mixed>> $outcomes
     * @return list<array<string,mixed>>
     */
    public function compute(array $outcomes): array
    {
        $byMode = [];
        foreach ($outcomes as $o) {
            $mode = (string)($o['mode'] ?? 'unknown');
            if (!isset($byMode[$mode])) {
                $byMode[$mode] = [
                    'total'     => 0,
                    'correct'   => 0,
                    'partial'   => 0,
                    'incorrect' => 0,
                    // context quality signals
                    'weak_ctx_total'     => 0,
                    'weak_ctx_correct'   => 0,
                    // threshold info
                    'threshold_sum'      => 0.0,
                ];
            }
            $byMode[$mode]['total']++;
            $o_out = $o['outcome'];
            if ($o_out === 'correct')        { $byMode[$mode]['correct']++; }
            elseif ($o_out === 'partial')    { $byMode[$mode]['partial']++; }
            elseif ($o_out === 'incorrect')  { $byMode[$mode]['incorrect']++; }

            if (($o['context_quality_level'] ?? '') === 'weak') {
                $byMode[$mode]['weak_ctx_total']++;
                if ($o_out === 'correct') {
                    $byMode[$mode]['weak_ctx_correct']++;
                }
            }
            $byMode[$mode]['threshold_sum'] += $o['decision_threshold'];
        }

        $result = [];
        foreach ($byMode as $mode => $agg) {
            $total   = $agg['total'];
            $lowData = $total < self::MIN_SESSIONS;

            $correctRate  = $total > 0 ? round($agg['correct'] / $total, 3) : 0.0;
            $incorrectRate= $total > 0 ? round($agg['incorrect'] / $total, 3) : 0.0;
            $avgThreshold = $total > 0 ? round($agg['threshold_sum'] / $total, 3) : 0.55;

            $weakCtxSuccessRate = $agg['weak_ctx_total'] > 0
                ? round($agg['weak_ctx_correct'] / $agg['weak_ctx_total'], 3)
                : null;

            $bestFor  = $this->computeBestFor($mode, $correctRate, $total, $lowData);
            $riskyWhen= $this->computeRiskyWhen($mode, $incorrectRate, $weakCtxSuccessRate, $agg);
            $rec      = $this->buildRecommendation($mode, $correctRate, $incorrectRate, $lowData, $riskyWhen);

            $result[] = [
                'mode'                  => $mode,
                'mode_label'            => self::MODE_LABELS[$mode] ?? ucfirst($mode),
                'sessions_count'        => $total,
                'correct_rate'          => $correctRate,
                'partial_rate'          => $total > 0 ? round($agg['partial'] / $total, 3) : 0.0,
                'incorrect_rate'        => $incorrectRate,
                'avg_threshold'         => $avgThreshold,
                'weak_ctx_success_rate' => $weakCtxSuccessRate,
                'insufficient_data'     => $lowData,
                'best_for'              => $bestFor,
                'risky_when'            => $riskyWhen,
                'recommendation'        => $rec,
            ];
        }

        usort($result, fn($a, $b) => $b['sessions_count'] <=> $a['sessions_count']);

        return $result;
    }

    /** @return list<string> */
    private function computeBestFor(string $mode, float $correctRate, int $total, bool $lowData): array
    {
        if ($lowData) {
            return [];
        }
        $items = [];
        if ($mode === 'decision-room' && $correctRate >= 0.6) {
            $items[] = 'Complex multi-stakeholder decisions';
        }
        if ($mode === 'confrontation' && $correctRate >= 0.6) {
            $items[] = 'Decisions benefiting from direct challenge';
        }
        if ($mode === 'quick-decision' && $correctRate >= 0.6) {
            $items[] = 'Low-risk, reversible decisions';
        }
        if ($mode === 'stress-test' && $correctRate >= 0.6) {
            $items[] = 'Pre-launch robustness checks';
        }
        if ($mode === 'jury' && $correctRate >= 0.6) {
            $items[] = 'High-stakes irreversible decisions';
        }
        if ($correctRate >= 0.75) {
            $items[] = 'High reliability across contexts';
        }
        return $items;
    }

    /** @return list<string> */
    private function computeRiskyWhen(string $mode, float $incorrectRate, ?float $weakCtxSuccessRate, array $agg): array
    {
        $items = [];
        if ($incorrectRate > 0.35) {
            $items[] = 'Incorrect rate above 35%';
        }
        if ($weakCtxSuccessRate !== null && $weakCtxSuccessRate < 0.3 && $agg['weak_ctx_total'] >= 2) {
            $items[] = 'Context quality is weak';
        }
        if ($mode === 'quick-decision' && $incorrectRate > 0.25) {
            $items[] = 'High-risk or irreversible decisions';
        }
        return $items;
    }

    private function buildRecommendation(
        string $mode,
        float  $correctRate,
        float  $incorrectRate,
        bool   $lowData,
        array  $riskyWhen
    ): string {
        if ($lowData) {
            return 'Not enough data yet — run more sessions with post-mortems.';
        }
        if ($mode === 'quick-decision' && $incorrectRate > 0.3) {
            return 'Avoid for high-risk irreversible decisions. Consider Decision Room or Jury instead.';
        }
        if ($correctRate >= 0.7) {
            return 'Reliable mode — continue using for similar decision types.';
        }
        if (!empty($riskyWhen)) {
            return 'Use with caution: ' . implode('; ', $riskyWhen) . '.';
        }
        return 'Average performance — review past sessions to identify improvement areas.';
    }
}

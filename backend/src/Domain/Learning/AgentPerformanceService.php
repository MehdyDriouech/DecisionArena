<?php

declare(strict_types=1);

namespace Domain\Learning;

/**
 * Computes per-agent performance from enriched outcome records.
 *
 * Rule: if sessions_count < 5 → flag as insufficient_data, no strong conclusions.
 */
class AgentPerformanceService
{
    private const MIN_SESSIONS = 5;

    /**
     * @param list<array<string,mixed>> $outcomes from DecisionOutcomeAnalyzer
     * @return list<array<string,mixed>>
     */
    public function compute(array $outcomes): array
    {
        // Aggregate per agent
        $byAgent = [];
        foreach ($outcomes as $o) {
            foreach ($o['agents'] as $agentId) {
                if (!isset($byAgent[$agentId])) {
                    $byAgent[$agentId] = [
                        'total'          => 0,
                        'correct'        => 0,
                        'partial'        => 0,
                        'incorrect'      => 0,
                        'retro_conf_sum' => 0.0,  // sum of confidence_in_retrospect when wrong
                        'wrong_conf_count' => 0,
                    ];
                }
                $byAgent[$agentId]['total']++;
                $outcome = $o['outcome'];
                if ($outcome === 'correct') {
                    $byAgent[$agentId]['correct']++;
                } elseif ($outcome === 'partial') {
                    $byAgent[$agentId]['partial']++;
                } elseif ($outcome === 'incorrect') {
                    $byAgent[$agentId]['incorrect']++;
                    $byAgent[$agentId]['retro_conf_sum']   += $o['confidence_in_retrospect'];
                    $byAgent[$agentId]['wrong_conf_count'] += 1;
                }
            }
        }

        $result = [];
        foreach ($byAgent as $agentId => $agg) {
            $total     = $agg['total'];
            $lowData   = $total < self::MIN_SESSIONS;

            $correctRate  = $total > 0 ? round($agg['correct'] / $total, 3) : 0.0;
            $incorrectRate= $total > 0 ? round($agg['incorrect'] / $total, 3) : 0.0;
            $partialRate  = $total > 0 ? round($agg['partial'] / $total, 3) : 0.0;

            $avgConfWrong = $agg['wrong_conf_count'] > 0
                ? round($agg['retro_conf_sum'] / $agg['wrong_conf_count'], 3)
                : null;

            // Calibration warning
            $calibrationWarning = null;
            if (!$lowData) {
                if ($incorrectRate > 0.3 && $avgConfWrong !== null && $avgConfWrong > 0.65) {
                    $calibrationWarning = 'overconfident_when_wrong';
                } elseif ($incorrectRate > 0.3) {
                    $calibrationWarning = 'high_incorrect_rate';
                } elseif ($correctRate > 0.7) {
                    $calibrationWarning = null; // performing well
                }
            }

            // Recommendation
            $recommendation = null;
            if ($lowData) {
                $recommendation = 'Insufficient data — fewer than ' . self::MIN_SESSIONS . ' evaluated sessions.';
            } elseif ($calibrationWarning === 'overconfident_when_wrong') {
                $recommendation = 'Use as challenger, not final authority. Agent tends to be overconfident on incorrect assessments.';
            } elseif ($calibrationWarning === 'high_incorrect_rate') {
                $recommendation = 'Review agent configuration — incorrect rate above 30%.';
            } elseif ($correctRate > 0.75) {
                $recommendation = 'High reliability — suitable for high-stakes decisions.';
            } else {
                $recommendation = 'Average performance — monitor over time.';
            }

            $result[] = [
                'agent_id'                  => $agentId,
                'sessions_count'            => $total,
                'correct_rate'              => $correctRate,
                'partial_rate'              => $partialRate,
                'incorrect_rate'            => $incorrectRate,
                'avg_confidence_when_wrong' => $avgConfWrong,
                'calibration_warning'       => $calibrationWarning,
                'insufficient_data'         => $lowData,
                'recommendation'            => $recommendation,
            ];
        }

        // Sort: most sessions first
        usort($result, fn($a, $b) => $b['sessions_count'] <=> $a['sessions_count']);

        return $result;
    }
}

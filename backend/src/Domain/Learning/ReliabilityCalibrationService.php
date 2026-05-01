<?php

declare(strict_types=1);

namespace Domain\Learning;

/**
 * Analyses how well the system's confidence correlates with real outcomes.
 *
 * Detects:
 *   - overconfidence (system was confident, decision was wrong)
 *   - underconfidence (system was uncertain, decision was actually correct)
 *   - false positive GO (system said GO, outcome was incorrect)
 *   - missed NO-GO (outcome was incorrect but decision was GO)
 *   - weak context failures (weak context quality + incorrect outcome)
 */
class ReliabilityCalibrationService
{
    /**
     * @param list<array<string,mixed>> $outcomes      enriched outcomes
     * @param list<array<string,mixed>> $decisions     decision rows with confidence
     * @return array<string,mixed>
     */
    public function compute(array $outcomes, array $decisions): array
    {
        $total = count($outcomes);
        if ($total === 0) {
            return $this->emptyReport();
        }

        // Index decisions by session_id
        $decBySession = [];
        foreach ($decisions as $d) {
            $decBySession[$d['session_id']] = $d;
        }

        $highConfidenceCount = 0;
        $highConfidenceWrong = 0;
        $goCount             = 0;
        $goIncorrect         = 0;
        $noGoCount           = 0;
        $noGoIncorrectInverse= 0; // system said NO-GO but outcome was marked correct (underconf.)
        $weakCtxTotal        = 0;
        $weakCtxIncorrect    = 0;
        $falseConsensusHigh  = 0;
        $falseConsensusWrong = 0;

        foreach ($outcomes as $o) {
            $sid     = $o['session_id'];
            $outcome = $o['outcome'];
            $isWrong = $outcome === 'incorrect';

            $dec = $decBySession[$sid] ?? null;
            $confLevel = $dec !== null ? strtolower((string)($dec['confidence_level'] ?? '')) : '';
            $label     = $dec !== null ? strtolower((string)($dec['decision_label']   ?? '')) : '';

            // High-confidence check
            $isHighConf = in_array($confLevel, ['high', 'very_high'], true)
                || (isset($dec['decision_score']) && (float)$dec['decision_score'] >= 0.75);
            if ($isHighConf) {
                $highConfidenceCount++;
                if ($isWrong) {
                    $highConfidenceWrong++;
                }
            }

            // GO / NO-GO outcome analysis
            if (str_contains($label, 'go') && !str_contains($label, 'no-go') && !str_contains($label, 'nogo')) {
                $goCount++;
                if ($isWrong) {
                    $goIncorrect++;
                }
            }
            if (str_contains($label, 'no-go') || str_contains($label, 'nogo')) {
                $noGoCount++;
            }

            // Weak context signal
            if (($o['context_quality_level'] ?? '') === 'weak') {
                $weakCtxTotal++;
                if ($isWrong) {
                    $weakCtxIncorrect++;
                }
            }

            // Reliability cap as proxy for false consensus (cap < 0.7 → low trust)
            if (($o['reliability_cap'] ?? 1.0) < 0.7) {
                $falseConsensusHigh++;
                if ($isWrong) {
                    $falseConsensusWrong++;
                }
            }
        }

        $overconfidenceRate = $highConfidenceCount > 0
            ? round($highConfidenceWrong / $highConfidenceCount, 3)
            : 0.0;

        $goFailureRate = $goCount > 0
            ? round($goIncorrect / $goCount, 3)
            : 0.0;

        $weakCtxSuccessRate = $weakCtxTotal > 0
            ? round(($weakCtxTotal - $weakCtxIncorrect) / $weakCtxTotal, 3)
            : null;

        $falseConsensusFailureRate = $falseConsensusHigh > 0
            ? round($falseConsensusWrong / $falseConsensusHigh, 3)
            : 0.0;

        $recommendations = $this->buildRecommendations(
            $overconfidenceRate,
            $highConfidenceWrong,
            $goFailureRate,
            $weakCtxSuccessRate,
            $falseConsensusFailureRate,
            $total
        );

        return [
            'total_sessions_analyzed'   => $total,
            'high_confidence_count'     => $highConfidenceCount,
            'high_confidence_wrong_count' => $highConfidenceWrong,
            'overconfidence_rate'       => $overconfidenceRate,
            'go_decision_count'         => $goCount,
            'go_failure_rate'           => $goFailureRate,
            'weak_context_session_count'=> $weakCtxTotal,
            'weak_context_success_rate' => $weakCtxSuccessRate,
            'low_reliability_cap_count' => $falseConsensusHigh,
            'false_consensus_failure_rate' => $falseConsensusFailureRate,
            'recommendations'           => $recommendations,
        ];
    }

    /** @return list<string> */
    private function buildRecommendations(
        float  $overconfidenceRate,
        int    $highConfWrong,
        float  $goFailureRate,
        ?float $weakCtxSuccessRate,
        float  $fcFailureRate,
        int    $total
    ): array {
        $recs = [];

        if ($total < 5) {
            $recs[] = 'Insufficient data — add at least 5 post-mortems for reliable calibration insights.';
            return $recs;
        }

        if ($overconfidenceRate > 0.25 && $highConfWrong >= 2) {
            $recs[] = "System is overconfident {$highConfWrong} time(s) when highly confident. Consider raising the default threshold.";
        }
        if ($goFailureRate > 0.25) {
            $pct = round($goFailureRate * 100);
            $recs[] = "GO decisions are incorrect {$pct}% of the time. Review context quality requirements before validating a GO.";
        }
        if ($weakCtxSuccessRate !== null && $weakCtxSuccessRate < 0.35) {
            $recs[] = 'Decisions made with weak context quality have a very low success rate. Force context clarification before debate.';
        }
        if ($fcFailureRate > 0.4) {
            $recs[] = 'Sessions with low reliability cap (false consensus risk) frequently result in incorrect outcomes. Enable Devil\'s Advocate or increase rounds.';
        }
        if (empty($recs)) {
            $recs[] = 'Calibration looks healthy. Continue monitoring as more post-mortems are added.';
        }

        return $recs;
    }

    private function emptyReport(): array
    {
        return [
            'total_sessions_analyzed'     => 0,
            'high_confidence_count'       => 0,
            'high_confidence_wrong_count' => 0,
            'overconfidence_rate'         => 0.0,
            'go_decision_count'           => 0,
            'go_failure_rate'             => 0.0,
            'weak_context_session_count'  => 0,
            'weak_context_success_rate'   => null,
            'low_reliability_cap_count'   => 0,
            'false_consensus_failure_rate'=> 0.0,
            'recommendations'             => ['No post-mortems found. Add post-mortems to sessions to enable calibration analysis.'],
        ];
    }
}

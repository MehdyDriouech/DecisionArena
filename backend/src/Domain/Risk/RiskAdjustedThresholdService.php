<?php

declare(strict_types=1);

namespace Domain\Risk;

/**
 * Computes the risk-adjusted decision threshold.
 *
 * Rule:
 *   low      → keep user threshold
 *   medium   → max(user, 0.60)
 *   high     → max(user, 0.70)
 *   critical → max(user, 0.80)
 */
class RiskAdjustedThresholdService
{
    /**
     * @return array{configured_threshold:float, risk_adjusted_threshold:float, threshold_reason:string, was_adjusted:bool}
     */
    public function compute(string $riskLevel, float $configuredThreshold): array
    {
        $floor = DecisionRiskProfile::THRESHOLD_FLOOR[$riskLevel]
              ?? DecisionRiskProfile::THRESHOLD_FLOOR[DecisionRiskProfile::LEVEL_MEDIUM];

        $adjusted   = max($configuredThreshold, $floor);
        $wasAdjusted = $adjusted > $configuredThreshold;

        $reason = match (true) {
            $riskLevel === DecisionRiskProfile::LEVEL_CRITICAL
                => 'Critical-risk decision: minimum threshold raised to 80% consensus to protect against irreversible, high-stakes outcomes.',
            $riskLevel === DecisionRiskProfile::LEVEL_HIGH
                => 'High-risk decision: minimum threshold raised to 70% to ensure stronger consensus.',
            $riskLevel === DecisionRiskProfile::LEVEL_MEDIUM && $wasAdjusted
                => 'Medium-risk decision: minimum threshold raised to 60% as a precautionary measure.',
            default
                => 'Configured threshold retained — risk level does not require adjustment.',
        };

        return [
            'configured_threshold'   => round($configuredThreshold, 4),
            'risk_adjusted_threshold'=> round($adjusted, 4),
            'threshold_reason'       => $reason,
            'was_adjusted'           => $wasAdjusted,
        ];
    }
}

<?php
declare(strict_types=1);

namespace Domain\DecisionReliability;

class DecisionGuardrailService
{
    private const STATUS_PRIORITY = [
        'blocked'              => 4,
        'auto_retry_triggered' => 3,
        'retry_recommended'    => 2,
        'pass'                 => 1,
    ];

    public function evaluate(
        array $rawDecision,
        array $adjustedDecision,
        array $contextQuality,
        array $falseConsensus,
        float $debateQualityScore,
        ?array $evidenceReport,
        ?array $riskProfile,
        string $mode,
        array $sessionOptions
    ): array {
        $result = [
            'guardrail_status'      => 'pass',
            'blocked_reason'        => null,
            'warnings'              => [],
            'recommended_action'    => '',
            'should_auto_retry'     => false,
            'final_outcome_override'=> null,
        ];

        $currentOutcome      = $adjustedDecision['final_outcome'] ?? '';
        $decisionLabel       = $adjustedDecision['decision_label'] ?? '';
        $contextLevel        = $contextQuality['level'] ?? 'medium';
        $criticalMissing     = $contextQuality['critical_missing'] ?? [];
        $fcRisk              = $falseConsensus['false_consensus_risk'] ?? 'low';
        $interactionDensity  = (float)($falseConsensus['interaction_density'] ?? 0.5);
        $explicitDisagreement= (bool)($falseConsensus['explicit_disagreement_observed'] ?? false);

        // Rule 3.1 — Context block
        if ($contextLevel === 'weak' && !empty($criticalMissing)) {
            $result = $this->applyStatus($result, 'blocked');
            $result['blocked_reason']         = 'insufficient_context';
            $result['recommended_action']     = 'complete_context';
            $result['final_outcome_override'] = 'INSUFFICIENT_CONTEXT';
        }

        // Rule 3.2 — Fake consensus kill switch
        if ($fcRisk === 'high' && in_array($decisionLabel, ['GO', 'NO_GO'], true)) {
            $result = $this->applyStatus($result, 'retry_recommended');
            $result['warnings'][] = 'false_consensus_risk_high';
            if (empty($result['recommended_action'])) {
                $result['recommended_action'] = 'rerun_with_stronger_debate';
            }
            if ($result['final_outcome_override'] === null) {
                $result['final_outcome_override'] = str_replace('_CONFIDENT', '_FRAGILE', $currentOutcome);
            }
        }

        // Rule 3.3 — Weak debate kill switch
        if ($debateQualityScore < 50.0 && $interactionDensity < 0.3 && !$explicitDisagreement) {
            $shouldAutoRetry = (bool)($sessionOptions['auto_retry_on_weak_debate'] ?? false);
            $newStatus = $shouldAutoRetry ? 'auto_retry_triggered' : 'retry_recommended';
            $result = $this->applyStatus($result, $newStatus);
            $result['warnings'][] = 'weak_parallel_debate';
            if (empty($result['recommended_action'])) {
                $result['recommended_action'] = 'rerun_with_adversarial_mode';
            }
            $result['should_auto_retry'] = $shouldAutoRetry;
            if ($result['final_outcome_override'] === null) {
                $result['final_outcome_override'] = str_replace('_CONFIDENT', '_FRAGILE', $currentOutcome);
            }
        }

        // Rule 3.4 — Multiple weak signals block confident decision
        $weakSignals = 0;
        if ($contextLevel === 'weak')      $weakSignals++;
        if ($debateQualityScore < 50.0)   $weakSignals++;
        if ($fcRisk === 'high')            $weakSignals++;

        if ($weakSignals >= 2 && in_array($currentOutcome, ['GO_CONFIDENT', 'NO_GO_CONFIDENT'], true)) {
            $result = $this->applyStatus($result, 'blocked');
            $result['blocked_reason']         = 'multiple_weak_signals';
            $result['final_outcome_override'] = 'NO_CONSENSUS_FRAGILE';
            if (empty($result['recommended_action'])) {
                $result['recommended_action'] = 'rerun_with_adversarial_mode';
            }
        }

        return $result;
    }

    private function applyStatus(array $result, string $newStatus): array
    {
        $current = self::STATUS_PRIORITY[$result['guardrail_status']] ?? 0;
        $new     = self::STATUS_PRIORITY[$newStatus] ?? 0;
        if ($new > $current) {
            $result['guardrail_status'] = $newStatus;
        }
        return $result;
    }
}

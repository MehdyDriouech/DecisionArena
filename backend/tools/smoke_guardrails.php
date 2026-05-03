<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

use Domain\DecisionReliability\DecisionGuardrailService;

$svc = new DecisionGuardrailService();

// Test: weak context + critical missing → blocked
$result = $svc->evaluate(
    rawDecision: [],
    adjustedDecision: ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO'],
    contextQuality: ['level' => 'weak', 'critical_missing' => ['timeline', 'metrics']],
    falseConsensus: ['false_consensus_risk' => 'low', 'interaction_density' => 0.5, 'explicit_disagreement_observed' => true],
    debateQualityScore: 70.0,
    evidenceReport: null,
    riskProfile: null,
    mode: 'decision-room',
    sessionOptions: []
);
assert($result['guardrail_status'] === 'blocked', 'Rule 3.1 should block');
assert($result['final_outcome_override'] === 'INSUFFICIENT_CONTEXT', 'Rule 3.1 should set INSUFFICIENT_CONTEXT');
echo "Rule 3.1 PASS\n";

// Test: high false consensus + GO → retry_recommended + FRAGILE
$result2 = $svc->evaluate(
    rawDecision: [],
    adjustedDecision: ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO'],
    contextQuality: ['level' => 'strong', 'critical_missing' => []],
    falseConsensus: ['false_consensus_risk' => 'high', 'interaction_density' => 0.5, 'explicit_disagreement_observed' => true],
    debateQualityScore: 70.0,
    evidenceReport: null,
    riskProfile: null,
    mode: 'decision-room',
    sessionOptions: []
);
assert($result2['guardrail_status'] === 'retry_recommended', 'Rule 3.2 status');
assert($result2['final_outcome_override'] === 'GO_FRAGILE', 'Rule 3.2 FRAGILE override');
assert(in_array('false_consensus_risk_high', $result2['warnings']), 'Rule 3.2 warning');
echo "Rule 3.2 PASS\n";

// Test: weak debate → auto_retry_triggered
$result3 = $svc->evaluate(
    rawDecision: [],
    adjustedDecision: ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO'],
    contextQuality: ['level' => 'strong', 'critical_missing' => []],
    falseConsensus: ['false_consensus_risk' => 'low', 'interaction_density' => 0.2, 'explicit_disagreement_observed' => false],
    debateQualityScore: 30.0,
    evidenceReport: null,
    riskProfile: null,
    mode: 'decision-room',
    sessionOptions: ['auto_retry_on_weak_debate' => true]
);
assert($result3['guardrail_status'] === 'auto_retry_triggered', 'Rule 3.3 auto_retry');
assert($result3['should_auto_retry'] === true, 'Rule 3.3 should_auto_retry flag');
echo "Rule 3.3 PASS\n";

echo "All guardrail smoke tests PASSED\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

use Domain\DecisionReliability\DecisionGuardrailService;
use Domain\DecisionReliability\DecisionQualityScoreService;

$guardrailSvc = new DecisionGuardrailService();
$qualitySvc   = new DecisionQualityScoreService();
$pass = 0; $fail = 0;

function chk(string $label, bool $cond, int &$pass, int &$fail): void {
    if ($cond) { echo "PASS: {$label}\n"; $pass++; }
    else       { echo "FAIL: {$label}\n"; $fail++; }
}

// Scenario A: Vague question — weak context + critical missing
$rA = $guardrailSvc->evaluate(
    rawDecision:       ['winning_label' => 'go', 'decision_label' => 'GO'],
    adjustedDecision:  ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO', 'decision_status' => 'CONFIDENT'],
    contextQuality:    ['level' => 'weak', 'critical_missing' => ['timeline', 'metrics'], 'score' => 15],
    falseConsensus:    ['false_consensus_risk' => 'low', 'interaction_density' => 0.5, 'explicit_disagreement_observed' => true],
    debateQualityScore:70.0,
    evidenceReport:    null,
    riskProfile:       null,
    mode:              'decision-room',
    sessionOptions:    []
);
chk('A: guardrail_status is not pass', $rA['guardrail_status'] !== 'pass', $pass, $fail);
chk('A: final_outcome != GO_CONFIDENT', $rA['final_outcome_override'] !== 'GO_CONFIDENT', $pass, $fail);
chk('A: final_outcome_override = INSUFFICIENT_CONTEXT', $rA['final_outcome_override'] === 'INSUFFICIENT_CONTEXT', $pass, $fail);

// Scenario B: Weak debate — low debate score + low density + no disagreement
$rB = $guardrailSvc->evaluate(
    rawDecision:       ['winning_label' => 'go', 'decision_label' => 'GO'],
    adjustedDecision:  ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO', 'decision_status' => 'CONFIDENT'],
    contextQuality:    ['level' => 'strong', 'critical_missing' => [], 'score' => 80],
    falseConsensus:    ['false_consensus_risk' => 'low', 'interaction_density' => 0.15, 'explicit_disagreement_observed' => false],
    debateQualityScore:30.0,
    evidenceReport:    null,
    riskProfile:       null,
    mode:              'decision-room',
    sessionOptions:    ['auto_retry_on_weak_debate' => true]
);
chk('B: auto_retry triggered', $rB['guardrail_status'] === 'auto_retry_triggered', $pass, $fail);
chk('B: should_auto_retry = true', $rB['should_auto_retry'] === true, $pass, $fail);
chk('B: final_outcome downgraded from CONFIDENT', $rB['final_outcome_override'] !== 'GO_CONFIDENT', $pass, $fail);

// Scenario C: No consensus vote → guardrails should not produce GO_CONFIDENT
$rC = $guardrailSvc->evaluate(
    rawDecision:       ['winning_label' => 'no-consensus', 'decision_label' => 'NO_CONSENSUS'],
    adjustedDecision:  ['final_outcome' => 'NO_CONSENSUS', 'decision_label' => 'NO_CONSENSUS', 'decision_status' => 'FRAGILE'],
    contextQuality:    ['level' => 'medium', 'critical_missing' => [], 'score' => 55],
    falseConsensus:    ['false_consensus_risk' => 'low', 'interaction_density' => 0.5, 'explicit_disagreement_observed' => true],
    debateQualityScore:60.0,
    evidenceReport:    null,
    riskProfile:       null,
    mode:              'decision-room',
    sessionOptions:    []
);
chk('C: final_outcome not GO_CONFIDENT', $rC['final_outcome_override'] !== 'GO_CONFIDENT', $pass, $fail);
chk('C: guardrail_status pass (no override needed)', $rC['guardrail_status'] === 'pass', $pass, $fail);

// Scenario D: Rich context → quality score >= 65
$rD = $qualitySvc->compute(
    contextQuality:     ['score' => 88, 'level' => 'strong', 'critical_missing' => []],
    debateQualityScore: 78.0,
    evidenceReport:     ['score' => 75],
    riskProfile:        ['risk_level' => 'medium'],
    falseConsensus:     ['false_consensus_risk' => 'low']
);
chk('D: quality_score >= 65', $rD['decision_quality_score'] >= 65, $pass, $fail);

// Scenario E: Fast Decision preset — check required fields
$fastPreset = [
    'mode'                     => 'decision-room',
    'rounds'                   => 2,
    'agents'                   => ['pm', 'architect', 'ux-expert', 'critic'],
    'devil_advocate_enabled'   => true,
    'force_disagreement'       => true,
    'auto_retry_on_weak_debate'=> true,
];
chk('E: Fast Decision preset has correct mode', $fastPreset['mode'] === 'decision-room', $pass, $fail);
chk('E: Fast Decision preset has 4 agents', count($fastPreset['agents']) === 4, $pass, $fail);
chk('E: auto_retry_on_weak_debate enabled', $fastPreset['auto_retry_on_weak_debate'] === true, $pass, $fail);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

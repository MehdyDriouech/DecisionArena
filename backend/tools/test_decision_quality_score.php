<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

use Domain\DecisionReliability\DecisionQualityScoreService;

$svc  = new DecisionQualityScoreService();
$pass = 0;
$fail = 0;

function assert_eq(string $label, mixed $expected, mixed $actual, int &$pass, int &$fail): void {
    if ($expected === $actual) {
        echo "PASS: {$label}\n";
        $pass++;
    } else {
        echo "FAIL: {$label} — expected " . json_encode($expected) . ", got " . json_encode($actual) . "\n";
        $fail++;
    }
}
function assert_lt(string $label, float $threshold, float $actual, int &$pass, int &$fail): void {
    if ($actual < $threshold) { echo "PASS: {$label} ({$actual} < {$threshold})\n"; $pass++; }
    else { echo "FAIL: {$label} — {$actual} not < {$threshold}\n"; $fail++; }
}
function assert_gte(string $label, float $threshold, float $actual, int &$pass, int &$fail): void {
    if ($actual >= $threshold) { echo "PASS: {$label} ({$actual} >= {$threshold})\n"; $pass++; }
    else { echo "FAIL: {$label} — {$actual} not >= {$threshold}\n"; $fail++; }
}

// Scenario 1: Weak context + high false consensus + no evidence → poor
$r1 = $svc->compute(
    contextQuality:     ['score' => 20, 'level' => 'weak', 'critical_missing' => ['timeline', 'metrics', 'risks', 'budget']],
    debateQualityScore: 25.0,
    evidenceReport:     null,
    riskProfile:        ['risk_level' => 'high'],
    falseConsensus:     ['false_consensus_risk' => 'high']
);
assert_lt('Scenario 1: score < 40', 40, $r1['decision_quality_score'], $pass, $fail);
assert_eq('Scenario 1: level = poor', 'poor', $r1['level'], $pass, $fail);

// Scenario 2: Medium context + medium false consensus + some evidence → fragile (40–64)
$r2 = $svc->compute(
    contextQuality:     ['score' => 55, 'level' => 'medium', 'critical_missing' => []],
    debateQualityScore: 55.0,
    evidenceReport:     ['score' => 50],
    riskProfile:        ['risk_level' => 'medium'],
    falseConsensus:     ['false_consensus_risk' => 'medium']
);
assert_gte('Scenario 2: score >= 40', 40, $r2['decision_quality_score'], $pass, $fail);
assert_lt('Scenario 2: score < 65', 65, $r2['decision_quality_score'], $pass, $fail);

// Scenario 3: Strong context + low false consensus + good evidence + low risk → medium/strong
$r3 = $svc->compute(
    contextQuality:     ['score' => 90, 'level' => 'strong', 'critical_missing' => []],
    debateQualityScore: 80.0,
    evidenceReport:     ['score' => 85],
    riskProfile:        ['risk_level' => 'low'],
    falseConsensus:     ['false_consensus_risk' => 'low']
);
assert_gte('Scenario 3: score >= 65', 65, $r3['decision_quality_score'], $pass, $fail);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

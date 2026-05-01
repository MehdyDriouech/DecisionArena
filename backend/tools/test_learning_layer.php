<?php

/**
 * Learning Layer — manual validation script
 *
 * Tests 5 scenarios:
 * A. 0 post-mortem → empty state
 * B. <5 post-mortems → data shown but low confidence
 * C. Agent often incorrect → calibration warning
 * D. Quick Decision incorrect on high risk → recommendation
 * E. false_consensus (low reliability cap) correlated with failure → global insight
 *
 * Usage: php backend/tools/test_learning_layer.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Domain\Learning\DecisionOutcomeAnalyzer;
use Domain\Learning\AgentPerformanceService;
use Domain\Learning\ModePerformanceService;
use Domain\Learning\ReliabilityCalibrationService;
use Domain\Learning\LearningInsightService;

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "  ✅ PASS: $label\n";
        $pass++;
    } else {
        echo "  ❌ FAIL: $label" . ($detail ? " — $detail" : '') . "\n";
        $fail++;
    }
}

echo "\n====== Learning Layer — Validation ======\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// A. Empty state: 0 post-mortem
// ──────────────────────────────────────────────────────────────────────────────
echo "── Scénario A: 0 post-mortem → empty state ──\n";

$svc = new LearningInsightService();
$report = $svc->recompute(); // Force fresh compute

check('overview.total_postmortems = 0', ($report['overview']['total_postmortems'] ?? -1) === 0);
check('agent_performance is empty', empty($report['agent_performance']));
check('mode_performance is empty',  empty($report['mode_performance']));
check('sufficient_data = false',    $report['sufficient_data'] === false);
check('data_confidence = none',     ($report['overview']['data_confidence'] ?? '') === 'none');

// ──────────────────────────────────────────────────────────────────────────────
// B. AgentPerformanceService with < 5 sessions → insufficient_data flag
// ──────────────────────────────────────────────────────────────────────────────
echo "\n── Scénario B: <5 sessions → insufficient_data flag ──\n";

$agentSvc = new AgentPerformanceService();
$smallOutcomes = [
    ['agents' => ['critic', 'pm'], 'outcome' => 'incorrect', 'confidence_in_retrospect' => 0.8, 'context_quality_level' => 'weak', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null],
    ['agents' => ['critic'],       'outcome' => 'correct',   'confidence_in_retrospect' => 0.7, 'context_quality_level' => 'good', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null],
    ['agents' => ['pm'],           'outcome' => 'partial',   'confidence_in_retrospect' => 0.6, 'context_quality_level' => 'medium', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null],
];
$agentPerf = $agentSvc->compute($smallOutcomes);

$criticEntry = null;
foreach ($agentPerf as $a) {
    if ($a['agent_id'] === 'critic') { $criticEntry = $a; break; }
}
check('critic entry exists', $criticEntry !== null);
check('critic insufficient_data = true (2 sessions < 5)', $criticEntry !== null && $criticEntry['insufficient_data'] === true);
check('critic recommendation mentions insufficient', $criticEntry !== null && stripos($criticEntry['recommendation'], 'insufficient') !== false);

// ──────────────────────────────────────────────────────────────────────────────
// C. Agent often incorrect → calibration_warning = overconfident_when_wrong
// ──────────────────────────────────────────────────────────────────────────────
echo "\n── Scénario C: agent souvent incorrect + haute confiance → warning ──\n";

$manyOutcomes = [];
for ($i = 0; $i < 8; $i++) {
    $manyOutcomes[] = ['agents' => ['critic'], 'outcome' => 'incorrect', 'confidence_in_retrospect' => 0.85, 'context_quality_level' => 'medium', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null];
}
for ($i = 0; $i < 2; $i++) {
    $manyOutcomes[] = ['agents' => ['critic'], 'outcome' => 'correct', 'confidence_in_retrospect' => 0.6, 'context_quality_level' => 'medium', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null];
}
$agentPerf2 = $agentSvc->compute($manyOutcomes);
$crit2 = null;
foreach ($agentPerf2 as $a) { if ($a['agent_id'] === 'critic') { $crit2 = $a; break; } }

check('critic entry exists (10 sessions)', $crit2 !== null);
check('critic insufficient_data = false',  $crit2 !== null && $crit2['insufficient_data'] === false);
check('critic calibration_warning set',    $crit2 !== null && $crit2['calibration_warning'] !== null);
check('critic warning is overconfident_when_wrong', $crit2 !== null && $crit2['calibration_warning'] === 'overconfident_when_wrong');

// ──────────────────────────────────────────────────────────────────────────────
// D. Quick Decision incorrect on high risk → recommendation flagged
// ──────────────────────────────────────────────────────────────────────────────
echo "\n── Scénario D: Quick Decision souvent incorrect → recommandation ──\n";

$modeSvc = new ModePerformanceService();
$qdOutcomes = [];
for ($i = 0; $i < 7; $i++) {
    $qdOutcomes[] = ['mode' => 'quick-decision', 'agents' => ['pm'], 'outcome' => 'incorrect', 'confidence_in_retrospect' => 0.7, 'context_quality_level' => 'medium', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null];
}
for ($i = 0; $i < 3; $i++) {
    $qdOutcomes[] = ['mode' => 'quick-decision', 'agents' => ['pm'], 'outcome' => 'correct', 'confidence_in_retrospect' => 0.6, 'context_quality_level' => 'good', 'decision_threshold' => 0.55, 'context_quality_score' => null, 'reliability_cap' => null];
}
$modePerf = $modeSvc->compute($qdOutcomes);
$qdMode = null;
foreach ($modePerf as $m) { if ($m['mode'] === 'quick-decision') { $qdMode = $m; break; } }

check('quick-decision entry exists', $qdMode !== null);
check('quick-decision incorrect_rate > 0.3', $qdMode !== null && $qdMode['incorrect_rate'] > 0.3);
check('quick-decision recommendation not empty', $qdMode !== null && !empty($qdMode['recommendation']));
check('quick-decision recommendation mentions high-risk', $qdMode !== null && stripos($qdMode['recommendation'], 'high-risk') !== false);

// ──────────────────────────────────────────────────────────────────────────────
// E. False consensus (low reliability_cap) correlated with failure → insight
// ──────────────────────────────────────────────────────────────────────────────
echo "\n── Scénario E: false_consensus corrélé à l'échec → insight ──\n";

$calibSvc = new ReliabilityCalibrationService();
$fcOutcomes = [];
for ($i = 0; $i < 6; $i++) {
    $fcOutcomes[] = [
        'session_id'             => "fc-$i",
        'outcome'                => 'incorrect',
        'confidence_in_retrospect' => 0.7,
        'context_quality_level'  => 'medium',
        'decision_threshold'     => 0.55,
        'context_quality_score'  => null,
        'reliability_cap'        => 0.5, // low → false consensus proxy
    ];
}
for ($i = 0; $i < 2; $i++) {
    $fcOutcomes[] = [
        'session_id'             => "fc-ok-$i",
        'outcome'                => 'correct',
        'confidence_in_retrospect' => 0.6,
        'context_quality_level'  => 'good',
        'decision_threshold'     => 0.55,
        'context_quality_score'  => null,
        'reliability_cap'        => 0.5,
    ];
}
$decisions = [];
$calibReport = $calibSvc->compute($fcOutcomes, $decisions);

check('false_consensus_failure_rate > 0', ($calibReport['false_consensus_failure_rate'] ?? 0) > 0);
check('false_consensus_failure_rate > 0.4', ($calibReport['false_consensus_failure_rate'] ?? 0) > 0.4);
check('calibration has recommendations', !empty($calibReport['recommendations']));
$fcRec = implode(' ', $calibReport['recommendations'] ?? []);
check('recommendation mentions false consensus or reliability', stripos($fcRec, 'reliability') !== false || stripos($fcRec, 'consensus') !== false);

// ──────────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────────
echo "\n====== Results: $pass passed, $fail failed ======\n\n";

exit($fail > 0 ? 1 : 0);

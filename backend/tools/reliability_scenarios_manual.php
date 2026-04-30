<?php
declare(strict_types=1);
/**
 * Scénarios manuels fiabilité (sans framework). Exécution :
 *   php backend/tools/reliability_scenarios_manual.php
 */
require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;

$svc = new DecisionReliabilityService();
$th = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD;

function dumpScenario(string $name, array $env): void {
    echo "\n=== {$name} ===\n";
    echo json_encode($env, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

$rA = $svc->buildEnvelope('', null, null, [], [], [], $th);
dumpScenario('A prompt vide / pas de décision brute', [
    'final_outcome' => $rA['adjusted_decision']['final_outcome'] ?? null,
    'clarification_count' => count($rA['context_clarification']['questions'] ?? []),
    'top_issues' => $rA['decision_reliability_summary']['top_issues'] ?? [],
]);

$longVague = str_repeat(
    'We want a highly innovative scalable disruptive platform that maximizes synergy and holistic value. ',
    12
);
$rB = $svc->buildEnvelope($longVague, null, [
    'decision_label' => 'go',
    'decision_score' => 0.82,
], [], [], [], $th);
dumpScenario('B texte long mais vague + GO', [
    'semantic_density' => $rB['context_quality']['semantic_density'] ?? null,
    'level' => $rB['context_quality']['level'] ?? null,
    'final_outcome' => $rB['adjusted_decision']['final_outcome'] ?? null,
]);

$positionsC = [
    ['round' => 1, 'agent_id' => 'a1', 'stance' => 'go', 'weight_score' => 5],
    ['round' => 1, 'agent_id' => 'a2', 'stance' => 'go', 'weight_score' => 5],
    ['round' => 1, 'agent_id' => 'a3', 'stance' => 'go', 'weight_score' => 5],
];
$votesC = [
    ['agent_id' => 'a1', 'vote' => 'go', 'rationale' => 'I agree this is the best path forward for success.'],
    ['agent_id' => 'a2', 'vote' => 'go', 'rationale' => 'I agree this is the best path forward for success.'],
    ['agent_id' => 'a3', 'vote' => 'go', 'rationale' => 'I agree this is the best path forward for success.'],
];
$edgesC = [['edge_type' => 'support', 'weight' => 1], ['edge_type' => 'support', 'weight' => 1]];
$rC = $svc->buildEnvelope('Should we launch?', null, [
    'decision_label' => 'go',
    'decision_score' => 0.9,
], $votesC, $positionsC, $edgesC, $th);
dumpScenario('C consensus rapide + rationales similaires', [
    'false_consensus_risk' => $rC['false_consensus_risk'],
    'diversity_score' => $rC['false_consensus']['diversity_score'] ?? null,
    'lexical_uniformity' => $rC['false_consensus']['lexical_uniformity'] ?? null,
]);

$rich = 'B2B SaaS for IT teams. Goal: reduce ticket resolution time by 25% within 6 months. Budget 80k€, GDPR compliance required. Target: mid-market EU.';
$rD = $svc->buildEnvelope($rich, ['content' => 'Spec v1: API limits, SLA 99.5%'], [
    'decision_label' => 'go',
    'decision_score' => 0.88,
], [['agent_id' => 'x', 'vote' => 'go', 'rationale' => 'I disagree with the risk framing; KPI is clear.']], [], [['edge_type' => 'challenge', 'weight' => 2]], $th);
dumpScenario('D contexte riche', [
    'level' => $rD['context_quality']['level'] ?? null,
    'final_outcome' => $rD['adjusted_decision']['final_outcome'] ?? null,
    'decision_status' => $rD['adjusted_decision']['decision_status'] ?? null,
]);

echo "\n=== E ancienne forme adjusted (champs partiels) ===\n";
echo json_encode(['has_final_outcome_key' => array_key_exists('final_outcome', ['decision_label' => 'go'])], JSON_PRETTY_PRINT) . "\n";

echo "\nTerminé.\n";

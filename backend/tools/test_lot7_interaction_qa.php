<?php
declare(strict_types=1);

/**
 * Lot 7 — interaction contract QA metrics + label/i18n consistency (backend slice).
 *
 * Usage: php backend/tools/test_lot7_interaction_qa.php
 */

$base = __DIR__ . '/../src';

require_once $base . '/mbstring-polyfill.php';
require_once $base . '/Domain/DecisionReliability/MemorySummaryBuilder.php';

use Domain\DecisionReliability\MemorySummaryBuilder;

$pass = 0;
$fail = 0;

function assert_same(string $label, mixed $expected, mixed $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}\n";
    echo '  expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo '  actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    $fail++;
}

function assert_true(string $label, bool $ok): void {
    assert_same($label, true, $ok);
}

// TEST 1 — verification_rate with verified_interaction semantics
$edgesVerified = [
    [
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'claim_challenged' => 'a',
        'objection' => '',
        'concession' => '',
        'position_change' => null,
        'target_mismatch' => 0,
    ],
    [
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'claim_challenged' => 'b',
        'objection' => '',
        'concession' => 'x',
        'position_change' => null,
        'target_mismatch' => 0,
    ],
];
$s1 = MemorySummaryBuilder::buildMemorySummary($edgesVerified, []);
assert_same('TEST1 verification rate 1.0 (2 contract edges, 2 verified)', 1.0, $s1['interaction_contract_verification_rate']);
assert_same('TEST1 interaction_contract_total', 2, $s1['interaction_contract_total']);
assert_same('TEST1 interaction_contract_verified', 2, $s1['interaction_contract_verified']);

// TEST 2 — unverified contract + mismatch
$edgesMix = [
    [
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'claim_challenged' => '',
        'objection' => '',
        'concession' => 'only concession',
        'position_change' => '',
        'target_mismatch' => 1,
    ],
    [
        'edge_source' => 'assigned_fallback',
        'edge_confidence' => 0.45,
        'claim_challenged' => 'x',
        'objection' => '',
        'concession' => '',
        'position_change' => '',
        'target_mismatch' => 0,
    ],
];
$s2 = MemorySummaryBuilder::buildMemorySummary($edgesMix, []);
assert_true('TEST2 target_mismatch_count', $s2['target_mismatch_count'] === 1);
assert_true('TEST2 contract total counts edges with any contract field', $s2['interaction_contract_total'] === 2);
assert_true('TEST2 verified count zero (no explicit+conf+claim/objection)', $s2['interaction_contract_verified'] === 0);
assert_same('TEST2 rate 0', 0.0, $s2['interaction_contract_verification_rate']);

// TEST 3 — unknown provenance still counted in unknown_edge_count
$edgesUnknown = [
    [
        'edge_source' => 'unknown',
        'edge_confidence' => 0.3,
        'claim_challenged' => '',
        'objection' => 'obj',
        'concession' => '',
        'position_change' => '',
        'target_mismatch' => 0,
    ],
];
$s3 = MemorySummaryBuilder::buildMemorySummary($edgesUnknown, []);
assert_same('TEST3 unknown_edge_count', 1, $s3['unknown_edge_count']);

// TEST 4 — i18n FR: provenance unknown + contract unverified labels
$i18nPath = realpath(__DIR__ . '/../../frontend/i18n.js');
assert_true('TEST4 i18n.js readable', $i18nPath !== false && is_readable($i18nPath));
$i18n = (string)file_get_contents($i18nPath);
assert_true('TEST4 label Provenance inconnue', str_contains($i18n, 'Provenance inconnue'));
assert_true('TEST4 label Contrat non vérifié', str_contains($i18n, 'Contrat non vérifié'));

// TEST 5 — interaction_density formula unchanged (spot check vs manual)
$positions = [
    ['agent_id' => 'a', 'round' => 1],
    ['agent_id' => 'b', 'round' => 1],
];
$edgesDense = [
    [
        'source_agent_id' => 'a',
        'target_agent_id' => 'b',
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'claim_challenged' => 'c',
    ],
    [
        'source_agent_id' => 'b',
        'target_agent_id' => 'a',
        'edge_source' => 'inferred_mention',
        'edge_confidence' => 0.65,
        'claim_challenged' => '',
    ],
];
$s5a = MemorySummaryBuilder::buildMemorySummary($edgesDense, $positions);
$s5b = MemorySummaryBuilder::buildMemorySummary($edgesDense, $positions);
assert_same('TEST5 density stable across duplicate build', $s5a['interaction_density'], $s5b['interaction_density']);
$reliable = (int)MemorySummaryBuilder::isReliableInteractionEdge($edgesDense[0])
    + (int)MemorySummaryBuilder::isReliableInteractionEdge($edgesDense[1]);
$possible = 2 * 1;
$expectedDensity = round(min(1.0, $reliable / $possible), 4);
assert_same('TEST5 density matches reliable/possible', $expectedDensity, $s5a['interaction_density']);

// claim_challenged_count alias
assert_same('TEST claim_challenged_count alias', $s1['challenged_claim_count'], $s1['claim_challenged_count']);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

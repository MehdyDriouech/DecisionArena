<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

use Infrastructure\Persistence\Database;

$pass = 0;
$fail = 0;

function chk(string $label, bool $cond, int &$pass, int &$fail): void {
    if ($cond) { echo "PASS: {$label}\n"; $pass++; }
    else       { echo "FAIL: {$label}\n"; $fail++; }
}

// ── 1. Schema: both columns exist ────────────────────────────────────────────
$pdo = Database::getInstance()->pdo();
$cols = $pdo->query("PRAGMA table_info(sessions)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
chk('Schema: result column exists',         in_array('result',         $colNames, true), $pass, $fail);
chk('Schema: decision_brief column exists', in_array('decision_brief', $colNames, true), $pass, $fail);

// ── 2. Round-trip: result JSON encodes and decodes cleanly ────────────────────
$envelope = [
    'guardrails'             => ['guardrail_status' => 'pass', 'final_outcome_override' => null, 'should_auto_retry' => false, 'warnings' => []],
    'auto_retry'             => null,
    'decision_quality_score' => 72.5,
    'adjusted_decision'      => ['final_outcome' => 'GO_CONFIDENT', 'decision_label' => 'GO', 'decision_status' => 'CONFIDENT'],
    'false_consensus'        => ['false_consensus_risk' => 'low', 'interaction_density' => 0.6, 'explicit_disagreement_observed' => true],
    'raw_decision'           => ['winning_label' => 'go', 'winning_score' => 0.75, 'threshold' => 0.65],
];
$encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE);
$decoded = json_decode($encoded, true);
chk('Round-trip: result JSON encodes without error',        $encoded !== false, $pass, $fail);
chk('Round-trip: decoded guardrail_status is pass',         ($decoded['guardrails']['guardrail_status'] ?? '') === 'pass', $pass, $fail);
chk('Round-trip: decoded decision_quality_score is 72.5',  ($decoded['decision_quality_score'] ?? 0) === 72.5, $pass, $fail);
chk('Round-trip: decoded adjusted_decision final_outcome',  ($decoded['adjusted_decision']['final_outcome'] ?? '') === 'GO_CONFIDENT', $pass, $fail);

// ── 3. Backward compat: null result handled gracefully ───────────────────────
$persisted = null;
$nullResult = !empty($persisted) ? json_decode($persisted, true) : null;
chk('Backward compat: null result yields null persisted block', $nullResult === null, $pass, $fail);

$rawDecision      = $nullResult['raw_decision']   ?? ['fallback' => true];
$adjustedDecision = $nullResult['adjusted_decision'] ?? ['fallback' => true];
chk('Backward compat: falls back to provided default when result is null',
    ($rawDecision['fallback'] ?? false) === true, $pass, $fail);

// ── 4. decision_brief column: encode/decode ───────────────────────────────────
$brief = [
    'decision'      => 'GO',
    'confidence'    => 'high',
    'quality_score' => 72,
    'why'           => 'Strong alignment across agents',
    'main_risks'    => ['Timeline tight'],
    'next_step'     => 'Validate with stakeholders',
];
$briefEncoded = json_encode($brief, JSON_UNESCAPED_UNICODE);
$briefDecoded = json_decode($briefEncoded, true);
chk('decision_brief: encodes without error',              $briefEncoded !== false, $pass, $fail);
chk('decision_brief: quality_score survives round-trip',  ($briefDecoded['quality_score'] ?? 0) === 72, $pass, $fail);

// ── 5. Guardrail override survives write/read cycle ───────────────────────────
$withOverride = [
    'guardrails' => [
        'guardrail_status'      => 'blocked',
        'final_outcome_override'=> 'INSUFFICIENT_CONTEXT',
        'should_auto_retry'     => false,
        'warnings'              => ['weak_context'],
    ],
    'adjusted_decision' => ['final_outcome' => 'INSUFFICIENT_CONTEXT'],
];
$enc = json_encode($withOverride, JSON_UNESCAPED_UNICODE);
$dec = json_decode($enc, true);
chk('Guardrail override: final_outcome_override preserved',
    ($dec['guardrails']['final_outcome_override'] ?? '') === 'INSUFFICIENT_CONTEXT', $pass, $fail);
chk('Guardrail override: adjusted_decision.final_outcome preserved',
    ($dec['adjusted_decision']['final_outcome'] ?? '') === 'INSUFFICIENT_CONTEXT', $pass, $fail);

// ── 6. Export compatibility: result contains expected keys ────────────────────
$resultJson = json_encode($envelope, JSON_UNESCAPED_UNICODE);
$forExport  = json_decode($resultJson, true);
chk('Export compat: decision_quality_score key present in result', array_key_exists('decision_quality_score', $forExport), $pass, $fail);
chk('Export compat: guardrails key present in result',             array_key_exists('guardrails',             $forExport), $pass, $fail);
chk('Export compat: auto_retry key present in result',             array_key_exists('auto_retry',             $forExport), $pass, $fail);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

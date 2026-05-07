<?php
/**
 * Lightweight drift guard: frontend playbooks.js vs backend PlaybookRuntime hints.
 *
 * No sync system; just detects likely drift and prints warnings for developers.
 *
 * Usage:
 *   php backend/tools/playbook_drift_guard.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Orchestration\PlaybookRuntime;

$frontendPath = realpath(__DIR__ . '/../../frontend/src/core/playbooks.js') ?: (__DIR__ . '/../../frontend/src/core/playbooks.js');
$backendContracts = PlaybookRuntime::allContracts();

echo "Playbook Drift Guard\n";
echo "frontend: {$frontendPath}\n";

if (!is_file($frontendPath)) {
    fwrite(STDERR, "ERROR: frontend playbooks.js not found.\n");
    exit(2);
}

$src = (string)file_get_contents($frontendPath);
$frontendSha1 = sha1($src);
$backendSha1 = sha1(json_encode($backendContracts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "frontend_sha1: {$frontendSha1}\n";
echo "backend_runtime_sha1: {$backendSha1}\n\n";

// Very lightweight extraction: find output_contract blocks per playbook id in PLAYBOOK_COPY.fr
$frontendContracts = extract_frontend_output_contracts($src);

$warnings = [];
foreach ($backendContracts as $id => $contract) {
    $expected = array_values((array)($contract['expected_sections'] ?? []));
    $front = $frontendContracts[$id] ?? null;
    if ($front === null) {
        $warnings[] = "{$id}: missing in frontend snapshot extraction (output_contract not found)";
        continue;
    }
    $frontNorm = array_values(array_map('normalize_key', $front));
    $expectedNorm = array_values(array_map('normalize_key', $expected));
    sort($frontNorm);
    sort($expectedNorm);
    if ($frontNorm !== $expectedNorm) {
        $warnings[] = "{$id}: output_contract mismatch. frontend=[" . implode(',', $frontNorm) . "] backend_expected_sections=[" . implode(',', $expectedNorm) . "]";
    }
}

if ($warnings === []) {
    echo "OK: no drift detected (output_contract vs expected_sections).\n";
    exit(0);
}

echo "WARNINGS (" . count($warnings) . ")\n";
foreach ($warnings as $w) {
    echo "- {$w}\n";
}

exit(1);

/**
 * @return array<string,list<string>>
 */
function extract_frontend_output_contracts(string $src): array
{
    $out = [];
    // Pass 1: quoted ids: 'founder-sprint': { ... output_contract: [ ... ], ... }
    if (preg_match_all("/'([a-z0-9-]+)'\\s*:\\s*\\{[\\s\\S]*?output_contract\\s*:\\s*\\[([\\s\\S]*?)\\]\\s*,/i", $src, $m1, PREG_SET_ORDER)) {
        foreach ($m1 as $hit) {
            $id = (string)($hit[1] ?? '');
            $arr = (string)($hit[2] ?? '');
            if ($id === '' || $arr === '') continue;
            $items = [];
            if (preg_match_all("/'([^']+)'/m", $arr, $im)) {
                foreach (($im[1] ?? []) as $raw) {
                    $items[] = (string)$raw;
                }
            }
            if ($items !== []) {
                $out[$id] = array_values(array_unique($items));
            }
        }
    }

    // Pass 2: unquoted ids (notably jury/confrontation): jury: { ... output_contract: [...] }
    if (preg_match_all("/\\b([a-z][a-z0-9-]*)\\b\\s*:\\s*\\{[\\s\\S]*?output_contract\\s*:\\s*\\[([\\s\\S]*?)\\]\\s*,/i", $src, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $hit) {
            $id = (string)($hit[1] ?? '');
            $arr = (string)($hit[2] ?? '');
            if ($id === '' || $arr === '' || isset($out[$id])) continue;
            $items = [];
            if (preg_match_all("/'([^']+)'/m", $arr, $im)) {
                foreach (($im[1] ?? []) as $raw) {
                    $items[] = (string)$raw;
                }
            }
            if ($items !== []) {
                $out[$id] = array_values(array_unique($items));
            }
        }
    }

    return $out;
}

function normalize_key(string $s): string
{
    $k = strtolower(trim($s));
    $k = preg_replace('/[^a-z0-9]+/', '_', $k) ?? $k;
    $k = trim($k, '_');
    // Bridge common frontend label normalization to backend snake keys
    if ($k === 'trade_off_analysis') $k = 'tradeoff_analysis';
    // Minimal translation bridge for known output_contract display labels → backend keys
    $map = [
        'wedge_critique' => ['wedge_critique', 'wedge_critique', 'wedge_critique'],
        'icp_challenge' => ['icp_challenge', 'icp_challenge'],
        'validation_signal' => ['validation_signal', 'validation_signal'],
        'kill_criteria' => ['kill_criteria', 'kill_criteria'],
        'next_experiment' => ['next_experiment', 'next_experiment'],
        'strategic_assumptions' => ['strategic_assumptions'],
        'blind_spots' => ['blind_spots'],
        'execution_risks' => ['execution_risks'],
        'tradeoff_analysis' => ['tradeoff_analysis'],
        'leadership_decision_memo' => ['leadership_decision_memo'],
        'core_hypothesis' => ['core_hypothesis'],
        'failure_scenarios' => ['failure_scenarios'],
        'weakest_assumptions' => ['weakest_assumptions'],
        'evidence_gaps' => ['evidence_gaps'],
        'pivot_kill_signals' => ['pivot_kill_signals'],
        'decision_options' => ['decision_options'],
        'evaluation_criteria' => ['evaluation_criteria'],
        'pros_cons_by_perspective' => ['pros_cons_by_perspective'],
        'final_recommendation' => ['final_recommendation'],
        'confidence_level' => ['confidence_level'],
        'position_a' => ['position_a'],
        'position_b' => ['position_b'],
        'conflict_points' => ['conflict_points'],
        'strongest_arguments' => ['strongest_arguments'],
        'synthesis_or_decision_path' => ['synthesis_or_decision_path'],
        'decision_framing' => ['decision_framing'],
        'key_constraint' => ['key_constraint'],
        'best_available_option' => ['best_available_option'],
        'main_risk' => ['main_risk'],
        'immediate_next_action' => ['immediate_next_action'],
    ];
    // If already a backend key shape, keep it.
    if (preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $k)) {
        return $k;
    }
    foreach ($map as $target => $aliases) {
        foreach ($aliases as $a) {
            if ($k === $a) return $target;
        }
    }
    return $k;
}


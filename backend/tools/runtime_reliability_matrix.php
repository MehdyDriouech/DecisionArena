<?php
/**
 * Runtime Reliability Matrix.
 *
 * Deterministic by default: evaluates golden model-output fixtures through the
 * real canonical synthesis parser, playbook runtime diagnostics, and executable
 * DecisionOutcome projection.
 *
 * Usage:
 *   php backend/tools/runtime_reliability_matrix.php
 *   php backend/tools/runtime_reliability_matrix.php --json
 *   php backend/tools/runtime_reliability_matrix.php --fixture backend/fixtures/reliability_matrix_golden.php
 *
 * To compare real providers/models, duplicate the fixture shape with captured
 * model outputs and set provider/model per case. The report will group by
 * provider/model automatically.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Orchestration\CanonicalSynthesisExtractor;
use Domain\Orchestration\DecisionOutcomeProjector;
use Domain\Orchestration\PlaybookRuntime;

$args = $argv ?? [];
$jsonMode = in_array('--json', $args, true);
$fixturePath = option_value($args, '--fixture') ?? (__DIR__ . '/../fixtures/reliability_matrix_golden.php');

if (!is_file($fixturePath)) {
    fwrite(STDERR, "Fixture file not found: {$fixturePath}\n");
    exit(2);
}

$fixture = require $fixturePath;
$cases = is_array($fixture['cases'] ?? null) ? $fixture['cases'] : [];
$providerMatrix = is_array($fixture['provider_matrix'] ?? null) ? $fixture['provider_matrix'] : [];

if ($cases === []) {
    fwrite(STDERR, "No reliability cases found in fixture.\n");
    exit(2);
}

$runtime = new PlaybookRuntime();
$rows = [];
foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }
    $rows[] = evaluate_case($case, $runtime);
}

$summary = summarize_rows($rows, $providerMatrix);
$report = [
    'generated_at' => gmdate('c'),
    'fixture' => str_replace('\\', '/', $fixturePath),
    'summary' => $summary,
    'provider_matrix' => $providerMatrix,
    'rows' => $rows,
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($summary['blocking_regression_count'] ?? 0) > 0 ? 1 : 0);
}

print_text_report($report);
exit(($summary['blocking_regression_count'] ?? 0) > 0 ? 1 : 0);

/** @param list<string> $args */
function option_value(array $args, string $name): ?string
{
    foreach ($args as $i => $arg) {
        if ($arg === $name && isset($args[$i + 1])) {
            return (string)$args[$i + 1];
        }
        if (str_starts_with((string)$arg, $name . '=')) {
            return substr((string)$arg, strlen($name) + 1);
        }
    }
    return null;
}

/** @param array<string,mixed> $case @return array<string,mixed> */
function evaluate_case(array $case, PlaybookRuntime $runtime): array
{
    $content = (string)($case['content'] ?? '');
    $playbookId = (string)($case['playbook_id'] ?? '');
    $canonical = CanonicalSynthesisExtractor::extract($content, $playbookId);
    $runtimeDiagnostics = $runtime->extractDiagnostics($content, $playbookId);
    $outcome = DecisionOutcomeProjector::fromCanonical($canonical, [
        'playbook_runtime' => $runtimeDiagnostics,
    ]);
    $parserDiagnostics = is_array($canonical['parser_diagnostics'] ?? null) ? $canonical['parser_diagnostics'] : [];

    $scores = score_case($canonical, $outcome, $runtimeDiagnostics);
    $issues = detect_issues($case, $canonical, $outcome, $runtimeDiagnostics, $scores);
    $blockingRegressions = array_values(array_filter(
        $issues,
        fn($issue) => ($case['quality'] ?? '') === 'good' || in_array($issue, ['diagnostics_missing'], true)
    ));

    return [
        'id' => (string)($case['id'] ?? ''),
        'playbook_id' => $playbookId,
        'contract_versions' => [
            'taxonomy' => \Domain\Orchestration\RuntimeContracts::TAXONOMY_VERSION,
            'playbook_runtime' => PlaybookRuntime::CONTRACT_VERSION,
            'canonical_synthesis' => CanonicalSynthesisExtractor::CONTRACT_VERSION,
            'decision_outcome' => DecisionOutcomeProjector::CONTRACT_VERSION,
        ],
        'provider' => (string)($case['provider'] ?? 'fixture'),
        'model' => (string)($case['model'] ?? 'fixture'),
        'shape' => (string)($case['shape'] ?? 'unknown'),
        'quality' => (string)($case['quality'] ?? 'unknown'),
        'scores' => $scores,
        'status' => (string)($outcome['status'] ?? ''),
        'confidence' => (string)($outcome['confidence'] ?? ''),
        'safe_to_persist' => (bool)($outcome['persistence_safety']['safe_to_persist'] ?? false),
        'requires_user_confirmation' => (bool)($outcome['persistence_safety']['requires_user_confirmation'] ?? false),
        'derived_from_fallback' => (bool)($outcome['persistence_safety']['derived_from_fallback'] ?? false),
        'parser_confidence' => round((float)($parserDiagnostics['parser_confidence'] ?? 0.0), 3),
        'extraction_strategy_used' => array_values((array)($parserDiagnostics['extraction_strategy_used'] ?? [])),
        'fallback_used' => (bool)($parserDiagnostics['fallback_used'] ?? false),
        'warnings' => array_values(array_unique(array_merge(
            (array)($parserDiagnostics['warnings'] ?? []),
            (array)($runtimeDiagnostics['warnings'] ?? []),
            (array)($outcome['diagnostics']['warnings'] ?? [])
        ))),
        'missing_fields' => array_values((array)($parserDiagnostics['missing_fields'] ?? [])),
        'repaired_fields' => array_values((array)($parserDiagnostics['repaired_fields'] ?? [])),
        'playbook_sections_found' => array_values((array)($runtimeDiagnostics['sections_found'] ?? [])),
        'playbook_missing_sections' => array_values((array)($runtimeDiagnostics['missing_sections'] ?? [])),
        'outcome_fields' => [
            'next_actions' => count((array)($outcome['required_next_actions'] ?? [])),
            'blocking_unknowns' => count((array)($outcome['blocking_unknowns'] ?? [])),
            'playbook_specific' => array_keys((array)($outcome['playbook_specific_outcomes'] ?? [])),
            'validation_logic' => non_empty_validation_keys((array)($outcome['validation_logic'] ?? [])),
        ],
        'issues' => $issues,
        'blocking_regressions' => $blockingRegressions,
    ];
}

/** @param array<string,mixed> $canonical @param array<string,mixed> $outcome @param array<string,mixed> $runtimeDiagnostics @return array<string,float|int> */
function score_case(array $canonical, array $outcome, array $runtimeDiagnostics): array
{
    $parserDiagnostics = is_array($canonical['parser_diagnostics'] ?? null) ? $canonical['parser_diagnostics'] : [];
    $required = ['decision', 'confidence', 'why', 'risks', 'recommended_next_actions', 'validation_logic'];
    $filled = array_flip((array)($parserDiagnostics['extracted_fields'] ?? []));
    $extracted = 0;
    foreach ($required as $field) {
        if (isset($filled[$field])) {
            $extracted++;
        }
    }

    $validationKeys = non_empty_validation_keys((array)($outcome['validation_logic'] ?? []));
    $playbookSpecific = array_filter((array)($outcome['playbook_specific_outcomes'] ?? []), fn($v) => trim((string)$v) !== '');
    $outcomeUnits = 0;
    $outcomeUnits += trim((string)($outcome['status'] ?? '')) !== '' ? 1 : 0;
    $outcomeUnits += trim((string)($outcome['confidence'] ?? '')) !== '' ? 1 : 0;
    $outcomeUnits += count((array)($outcome['required_next_actions'] ?? [])) > 0 ? 1 : 0;
    $outcomeUnits += count($validationKeys) > 0 ? 1 : 0;
    $outcomeUnits += count($playbookSpecific) > 0 ? 1 : 0;

    $warnings = array_merge(
        (array)($parserDiagnostics['warnings'] ?? []),
        (array)($runtimeDiagnostics['warnings'] ?? []),
        (array)($outcome['diagnostics']['warnings'] ?? [])
    );

    return [
        'parser_reliability' => round((float)($parserDiagnostics['parser_confidence'] ?? 0.0), 3),
        'outcome_completeness' => round($outcomeUnits / 5, 3),
        'extraction_success' => round($extracted / max(1, count($required)), 3),
        'playbook_completeness' => round((float)($runtimeDiagnostics['completeness_score'] ?? 0.0), 3),
        'warning_count' => count(array_unique($warnings)),
        'repair_count' => count(array_unique((array)($parserDiagnostics['repaired_fields'] ?? []))),
    ];
}

/** @param array<string,mixed> $case @param array<string,mixed> $canonical @param array<string,mixed> $outcome @param array<string,mixed> $runtimeDiagnostics @param array<string,mixed> $scores @return list<string> */
function detect_issues(array $case, array $canonical, array $outcome, array $runtimeDiagnostics, array $scores): array
{
    $quality = (string)($case['quality'] ?? 'unknown');
    $playbookId = (string)($case['playbook_id'] ?? '');
    $parserDiagnostics = is_array($canonical['parser_diagnostics'] ?? null) ? $canonical['parser_diagnostics'] : [];
    $issues = [];

    if ($parserDiagnostics === []) {
        $issues[] = 'diagnostics_missing';
    }
    if (trim((string)($outcome['status'] ?? '')) === '') {
        $issues[] = 'status_not_normalized';
    }
    if ($quality === 'good' && (float)($scores['parser_reliability'] ?? 0) < 0.55) {
        $issues[] = 'good_fixture_low_parser_confidence';
    }
    if ($quality === 'good' && (float)($scores['outcome_completeness'] ?? 0) < 0.8) {
        $issues[] = 'good_fixture_incomplete_outcome';
    }
    if (
        $quality === 'good'
        && (float)($scores['playbook_completeness'] ?? 0) < 0.6
        && count((array)($outcome['playbook_specific_outcomes'] ?? [])) === 0
    ) {
        $issues[] = 'good_fixture_low_playbook_completeness';
    }
    if (count((array)($outcome['required_next_actions'] ?? [])) === 0) {
        $issues[] = 'next_actions_missing';
    }
    if ($playbookId !== '' && $quality === 'good' && count((array)($outcome['playbook_specific_outcomes'] ?? [])) === 0) {
        $issues[] = 'playbook_outcomes_missing';
    }
    if (($case['shape'] ?? '') === 'broken_json' && count((array)($parserDiagnostics['repaired_fields'] ?? [])) === 0 && in_array('structured_json', (array)($parserDiagnostics['extraction_strategy_used'] ?? []), true)) {
        $issues[] = 'broken_json_not_marked_repaired';
    }

    return array_values(array_unique($issues));
}

/** @param array<string,mixed> $validation @return list<string> */
function non_empty_validation_keys(array $validation): array
{
    $keys = [];
    foreach ($validation as $key => $value) {
        if (trim((string)$value) !== '') {
            $keys[] = (string)$key;
        }
    }
    return $keys;
}

/** @param list<array<string,mixed>> $rows @param array<string,mixed> $providerMatrix @return array<string,mixed> */
function summarize_rows(array $rows, array $providerMatrix): array
{
    $count = count($rows);
    $sum = [
        'parser_reliability' => 0.0,
        'outcome_completeness' => 0.0,
        'extraction_success' => 0.0,
        'playbook_completeness' => 0.0,
    ];
    $warningTotal = 0;
    $repairCases = 0;
    $issueCount = 0;
    $blockingRegressionCount = 0;
    $byPlaybook = [];
    $byShape = [];
    $byProviderModel = [];

    foreach ($rows as $row) {
        foreach ($sum as $key => $_) {
            $sum[$key] += (float)($row['scores'][$key] ?? 0);
        }
        $warningTotal += (int)($row['scores']['warning_count'] ?? 0);
        $repairCases += ((int)($row['scores']['repair_count'] ?? 0) > 0) ? 1 : 0;
        $issueCount += count((array)($row['issues'] ?? []));
        $blockingRegressionCount += count((array)($row['blocking_regressions'] ?? []));
        bucket_add($byPlaybook, (string)$row['playbook_id'], $row);
        bucket_add($byShape, (string)$row['shape'], $row);
        bucket_add($byProviderModel, (string)$row['provider'] . '/' . (string)$row['model'], $row);
    }

    return [
        'case_count' => $count,
        'provider_targets' => array_sum(array_map('count', $providerMatrix)),
        'averages' => [
            'parser_reliability' => $count ? round($sum['parser_reliability'] / $count, 3) : 0,
            'outcome_completeness' => $count ? round($sum['outcome_completeness'] / $count, 3) : 0,
            'extraction_success' => $count ? round($sum['extraction_success'] / $count, 3) : 0,
            'playbook_completeness' => $count ? round($sum['playbook_completeness'] / $count, 3) : 0,
            'warning_frequency' => $count ? round($warningTotal / $count, 3) : 0,
            'repair_frequency' => $count ? round($repairCases / $count, 3) : 0,
        ],
        'issue_count' => $issueCount,
        'blocking_regression_count' => $blockingRegressionCount,
        'by_playbook' => bucket_summary($byPlaybook),
        'by_shape' => bucket_summary($byShape),
        'by_provider_model' => bucket_summary($byProviderModel ?? []),
    ];
}

/** @param array<string,list<array<string,mixed>>> $bucket @param array<string,mixed> $row */
function bucket_add(array &$bucket, string $key, array $row): void
{
    $key = $key !== '' ? $key : 'unknown';
    if (!isset($bucket[$key])) {
        $bucket[$key] = [];
    }
    $bucket[$key][] = $row;
}

/** @param array<string,list<array<string,mixed>>> $bucket @return array<string,mixed> */
function bucket_summary(array $bucket): array
{
    $out = [];
    foreach ($bucket as $key => $rows) {
        $n = count($rows);
        $parser = 0.0;
        $outcome = 0.0;
        $issues = 0;
        $blocking = 0;
        foreach ($rows as $row) {
            $parser += (float)($row['scores']['parser_reliability'] ?? 0);
            $outcome += (float)($row['scores']['outcome_completeness'] ?? 0);
            $issues += count((array)($row['issues'] ?? []));
            $blocking += count((array)($row['blocking_regressions'] ?? []));
        }
        $out[$key] = [
            'case_count' => $n,
            'parser_reliability' => $n ? round($parser / $n, 3) : 0,
            'outcome_completeness' => $n ? round($outcome / $n, 3) : 0,
            'issue_count' => $issues,
            'blocking_regression_count' => $blocking,
        ];
    }
    ksort($out);
    return $out;
}

/** @param array<string,mixed> $report */
function print_text_report(array $report): void
{
    $summary = $report['summary'];
    echo "Runtime Reliability Matrix\n";
    echo "Fixture: {$report['fixture']}\n";
    echo "Cases: {$summary['case_count']} | Provider targets listed: {$summary['provider_targets']} | Issues: {$summary['issue_count']} | Blocking regressions: {$summary['blocking_regression_count']}\n\n";

    echo "Averages\n";
    foreach ($summary['averages'] as $key => $value) {
        echo "- {$key}: {$value}\n";
    }

    echo "\nProvider Test Matrix (targets to run live against the same prompts)\n";
    foreach (($report['provider_matrix'] ?? []) as $provider => $models) {
        $labels = array_map(fn($m) => ($m['model'] ?? '') . ' [' . ($m['profile'] ?? '') . ']', (array)$models);
        echo "- {$provider}: " . implode('; ', $labels) . "\n";
    }

    echo "\nRows\n";
    echo str_pad('case', 34)
        . str_pad('pb', 18)
        . str_pad('shape', 14)
        . str_pad('parser', 9)
        . str_pad('outcome', 10)
        . str_pad('persist', 9)
        . "issues\n";
    foreach (($report['rows'] ?? []) as $row) {
        $persist = ($row['safe_to_persist'] ?? false) ? 'safe' : (($row['requires_user_confirmation'] ?? false) ? 'confirm' : 'unsafe');
        echo str_pad(substr((string)$row['id'], 0, 32), 34)
            . str_pad((string)$row['playbook_id'], 18)
            . str_pad((string)$row['shape'], 14)
            . str_pad((string)$row['scores']['parser_reliability'], 9)
            . str_pad((string)$row['scores']['outcome_completeness'], 10)
            . str_pad($persist, 9)
            . implode(',', (array)$row['issues'])
            . "\n";
    }

    echo "\nBy Playbook\n";
    foreach (($summary['by_playbook'] ?? []) as $key => $row) {
        echo "- {$key}: parser={$row['parser_reliability']} outcome={$row['outcome_completeness']} issues={$row['issue_count']} blocking={$row['blocking_regression_count']}\n";
    }

    echo "\nBy Provider/Model\n";
    foreach (($summary['by_provider_model'] ?? []) as $key => $row) {
        echo "- {$key}: parser={$row['parser_reliability']} outcome={$row['outcome_completeness']} issues={$row['issue_count']} blocking={$row['blocking_regression_count']}\n";
    }

    echo "\nDiagnostics: use --json for per-case warnings, extraction strategies, fallback_used, repaired_fields, and missing fields.\n";
}

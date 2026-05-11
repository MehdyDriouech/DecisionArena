<?php
declare(strict_types=1);

/**
 * Test: dashboard cognitive summary contract (MVP hardening).
 *
 * Usage:
 *   php backend/tools/test_dashboard_cognitive_summary_contract.php
 *
 * Requirement:
 *   local backend reachable at http://localhost/decision-room-ai/backend/public
 */

$base = 'http://localhost/decision-room-ai/backend/public';
$cases = [
    '/api/dashboard/cognitive-summary',
    '/api/dashboard/cognitive-summary?context_id=all',
];

$failures = [];

function expect_key(array $data, string $key, array &$errors): void
{
    if (!array_key_exists($key, $data)) {
        $errors[] = "missing key: {$key}";
    }
}

function expect_array_key(array $data, string $key, array &$errors): array
{
    expect_key($data, $key, $errors);
    $value = $data[$key] ?? null;
    if (!is_array($value)) {
        $errors[] = "key {$key} must be object";
        return [];
    }
    return $value;
}

function expect_number_or_null(mixed $value, string $key, array &$errors): void
{
    if ($value !== null && !is_int($value) && !is_float($value)) {
        $errors[] = "key {$key} must be number|null";
    }
}

foreach ($cases as $path) {
    $url = $base . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        fwrite(STDERR, "[SKIP] curl error: {$curlErr}\n");
        exit(0);
    }

    if ($http !== 200) {
        $failures[] = "[FAIL] {$path}: expected HTTP 200, got {$http} — " . substr((string)$raw, 0, 320);
        continue;
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $failures[] = "[FAIL] {$path}: response is not valid JSON object";
        continue;
    }

    $errors = [];

    expect_key($data, 'generated_at', $errors);
    $scope = expect_array_key($data, 'scope', $errors);
    $activity = expect_array_key($data, 'activity', $errors);
    $quality = expect_array_key($data, 'decision_quality', $errors);
    $risks = expect_array_key($data, 'risks', $errors);
    $runtime = expect_array_key($data, 'runtime_expert', $errors);
    $contextsRoot = expect_array_key($data, 'strategic_contexts', $errors);
    $reliability = expect_array_key($data, 'reliability', $errors);

    expect_key($scope, 'mode', $errors);
    expect_key($scope, 'active_context_id', $errors);
    expect_key($scope, 'selected_context_id', $errors);
    expect_key($scope, 'requested_context_id', $errors);
    expect_key($scope, 'sessions_count', $errors);

    expect_key($activity, 'active_analyses', $errors);
    expect_key($activity, 'completed_analyses', $errors);
    expect_key($activity, 'verdict_breakdown', $errors);
    expect_key($activity, 'rerun_analyses', $errors);
    expect_key($activity, 'contexts_recent_activity', $errors);

    expect_number_or_null($quality['avg_quality_score'] ?? null, 'decision_quality.avg_quality_score', $errors);
    expect_number_or_null($quality['false_consensus_rate'] ?? null, 'decision_quality.false_consensus_rate', $errors);
    expect_number_or_null($quality['avg_confidence_score'] ?? null, 'decision_quality.avg_confidence_score', $errors);
    expect_number_or_null($quality['fragile_rate'] ?? null, 'decision_quality.fragile_rate', $errors);
    expect_number_or_null($quality['blocked_rate'] ?? null, 'decision_quality.blocked_rate', $errors);
    expect_number_or_null($quality['avg_debate_depth'] ?? null, 'decision_quality.avg_debate_depth', $errors);

    expect_key($risks, 'critical_open_analyses', $errors);
    expect_key($risks, 'high_risks_detected', $errors);
    expect_key($risks, 'active_contradictions', $errors);
    expect_key($risks, 'inter_agent_conflicts', $errors);
    expect_key($risks, 'contexts_high_risk', $errors);
    $riskDetails = expect_array_key($risks, 'details', $errors);
    expect_key($riskDetails, 'active_contradictions', $errors);
    expect_key($riskDetails, 'inter_agent_conflicts', $errors);
    expect_key($riskDetails, 'high_risks', $errors);
    if (isset($riskDetails['active_contradictions']) && !is_array($riskDetails['active_contradictions'])) {
        $errors[] = 'risks.details.active_contradictions must be array';
    }
    if (isset($riskDetails['inter_agent_conflicts']) && !is_array($riskDetails['inter_agent_conflicts'])) {
        $errors[] = 'risks.details.inter_agent_conflicts must be array';
    }
    if (isset($riskDetails['high_risks']) && !is_array($riskDetails['high_risks'])) {
        $errors[] = 'risks.details.high_risks must be array';
    }

    expect_key($runtime, 'coverage_ratio', $errors);
    expect_key($runtime, 'runtime_warnings', $errors);
    expect_key($runtime, 'retries', $errors);
    expect_key($runtime, 'budget_pressure', $errors);
    expect_key($runtime, 'pruning_events', $errors);
    expect_key($runtime, 'large_traces', $errors);
    expect_key($runtime, 'truncated_payloads', $errors);
    expect_key($runtime, 'qa_mode_active', $errors);

    expect_key($contextsRoot, 'items', $errors);
    $items = $contextsRoot['items'] ?? null;
    if (!is_array($items)) {
        $errors[] = 'strategic_contexts.items must be array';
    }

    expect_key($reliability, 'notes', $errors);
    expect_key($reliability, 'kpi_quality', $errors);

    if (is_array($items) && count($items) > 0) {
        $first = $items[0];
        if (!is_array($first)) {
            $errors[] = 'strategic_contexts.items[0] must be object';
        } else {
            $requiredCtxKeys = [
                'context_id',
                'title',
                'status',
                'analyses_count',
                'major_decisions_count',
                'open_risks_count',
                'reruns_count',
                'last_snapshot_at',
                'last_memory_compilation_at',
            ];
            foreach ($requiredCtxKeys as $k) {
                expect_key($first, $k, $errors);
            }
        }
    }

    if ($errors !== []) {
        $failures[] = "[FAIL] {$path}: contract mismatch\n - " . implode("\n - ", $errors);
    } else {
        echo "[PASS] {$path} contract is valid\n";
        echo "  scope.mode = " . ($scope['mode'] ?? 'n/a') . "\n";
        echo "  sessions_count = " . (string)($scope['sessions_count'] ?? 'n/a') . "\n";
        echo "  contexts.items = " . (is_array($items) ? count($items) : 0) . "\n";
    }
}

if ($failures !== []) {
    foreach ($failures as $f) {
        fwrite(STDERR, $f . "\n");
    }
    exit(1);
}
exit(0);

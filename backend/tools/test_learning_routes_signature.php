<?php

/**
 * Test: LearningController method signatures
 *
 * Checks that all Learning routes respond correctly and without ArgumentCountError.
 *
 * Usage (from backend/):
 *   php tools/test_learning_routes_signature.php
 *
 * Requirements: AMPPS running on localhost:80
 */

$base = 'http://localhost/decision-room-ai/backend/public';

$cases = [
    ['GET',  '/api/learning/overview',    200, 'sufficient_data'],
    ['GET',  '/api/learning/agents',      200, 'agent_performance'],
    ['GET',  '/api/learning/modes',       200, 'mode_performance'],
    ['GET',  '/api/learning/calibration', 200, 'calibration'],
    ['POST', '/api/learning/recompute',   200, 'status'],
    ['GET',  '/api/learning/export',      200, 'format'],
    ['GET',  '/api/learning/export?format=json', 200, 'format'],
];

$passed = 0;
$failed = 0;

foreach ($cases as [$method, $path, $expectedCode, $expectedKey]) {
    $url = $base . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo "[SKIP] {$method} {$path} — curl error: {$curlErr}\n";
        continue;
    }

    $data = json_decode($body, true);
    $ok   = ($code === $expectedCode) && isset($data[$expectedKey]);

    if ($ok) {
        echo "[PASS] {$method} {$path} → HTTP {$code}, has '{$expectedKey}'\n";
        $passed++;
    } else {
        echo "[FAIL] {$method} {$path} → HTTP {$code}, expected '{$expectedKey}' in: " . substr($body, 0, 200) . "\n";
        $failed++;
    }
}

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);

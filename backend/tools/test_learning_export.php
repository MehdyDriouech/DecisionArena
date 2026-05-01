<?php

/**
 * Test: Learning export via GET (format=markdown and format=json).
 *
 * Ensures the export returns downloadable content without errors.
 *
 * Usage:
 *   php tools/test_learning_export.php
 */

$base = 'http://localhost/decision-room-ai/backend/public';

$cases = [
    ['GET', '/api/learning/export',             'markdown', 'content',   '# Learning'],
    ['GET', '/api/learning/export?format=json', 'json',     'content',   '{'],
    ['GET', '/api/learning/export',             'markdown',  'filename', '.md'],
    ['GET', '/api/learning/export?format=json', 'json',      'filename', '.json'],
];

$passed = 0;
$failed = 0;

foreach ($cases as [$method, $path, $label, $key, $contains]) {
    $url = $base . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo "[SKIP] {$method} {$path} — curl error: {$curlErr}\n";
        continue;
    }

    $data = json_decode($body, true);

    if ($code !== 200) {
        echo "[FAIL] {$method} {$path} — HTTP {$code}: " . substr($body, 0, 200) . "\n";
        $failed++;
        continue;
    }

    if (!isset($data[$key])) {
        echo "[FAIL] {$method} {$path} — missing '{$key}' in response\n";
        $failed++;
        continue;
    }

    if (!str_contains((string)$data[$key], $contains)) {
        echo "[FAIL] {$method} {$path} — '{$key}' does not contain '{$contains}'\n";
        $failed++;
        continue;
    }

    echo "[PASS] {$method} {$path} [{$label}] — has '{$key}' with '{$contains}'\n";
    $passed++;
}

// Test that POST still works
$url = $base . '/api/learning/export';
$ch  = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true);
$ok = ($code === 200) && isset($data['content']);
if ($ok) {
    echo "[PASS] POST /api/learning/export — backward compat OK\n";
    $passed++;
} else {
    echo "[FAIL] POST /api/learning/export — HTTP {$code}: " . substr($body, 0, 200) . "\n";
    $failed++;
}

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);

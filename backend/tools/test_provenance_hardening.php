<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Domain\CognitiveGovernance\DeterministicHash;
use Domain\Orchestration\PromptInjectionTraceCollector;
use Domain\StrategicContext\ContextSnapshotService;

function check(bool $ok, string $name): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__prov_failures'] = ($GLOBALS['__prov_failures'] ?? 0) + 1;
    }
}

$GLOBALS['__prov_failures'] = 0;

$hashA = DeterministicHash::sha256(['b' => 2, 'a' => 1, 'arr' => ['x' => 1, 'y' => 2]]);
$hashB = DeterministicHash::sha256(['arr' => ['y' => 2, 'x' => 1], 'a' => 1, 'b' => 2]);
check($hashA === $hashB, 'DeterministicHash is stable across key ordering');

$prov = CognitiveProvenanceEnvelope::normalize([
    'cognitive_kind' => 'test_provenance',
    'input_hashes' => ['abc', 'def'],
    'selected_sources' => ['s1', 's2'],
]);
check(isset($prov['input_hash'], $prov['source_hash'], $prov['runtime_hash'], $prov['provenance_fingerprint']), 'Provenance envelope exposes hardening hashes');
check(is_string($prov['provenance_fingerprint']) && strlen((string)$prov['provenance_fingerprint']) === 64, 'Provenance fingerprint is SHA-256 length');

PromptInjectionTraceCollector::begin([
    'mode' => 'chat',
    'session_id' => 'qa-provenance',
    'strategic_context_id' => 'ctx-provenance',
    'round' => 1,
    'agent_id' => 'pm',
]);
PromptInjectionTraceCollector::addStep(
    'chat_user_payload',
    'orchestration',
    42,
    'qa_trace_step',
    null,
    ['content_hash' => DeterministicHash::sha256('payload')]
);
$trace1 = PromptInjectionTraceCollector::finish();

PromptInjectionTraceCollector::begin([
    'mode' => 'chat',
    'session_id' => 'qa-provenance',
    'strategic_context_id' => 'ctx-provenance',
    'round' => 1,
    'agent_id' => 'pm',
]);
PromptInjectionTraceCollector::addStep(
    'chat_user_payload',
    'orchestration',
    42,
    'qa_trace_step',
    null,
    ['content_hash' => DeterministicHash::sha256('payload')]
);
$trace2 = PromptInjectionTraceCollector::finish();

check(is_array($trace1) && isset($trace1['input_hash'], $trace1['source_hash'], $trace1['runtime_hash'], $trace1['replay_fingerprint']), 'Prompt trace exposes runtime hardening hashes');
check(is_array($trace1) && is_array($trace2) && ($trace1['runtime_hash'] ?? '') === ($trace2['runtime_hash'] ?? ''), 'Prompt trace runtime hash is deterministic for identical trace');

$snapshotService = new ContextSnapshotService();
$compute = new ReflectionMethod(ContextSnapshotService::class, 'computeSnapshotHash');
$compute->setAccessible(true);

$payloadA = [
    'beliefs' => ['counts' => ['total' => 1], 'samples' => []],
    'strategic_narrative' => ['headline_echo' => 'h1'],
    'risks' => ['risk_profiles_count' => 1],
    'evidence' => ['evidence_reports_count' => 1],
    'social' => ['relationship_rows' => 1],
    'timeline' => ['counts_by_type' => ['risk' => 1]],
    'memory_compilations' => ['active_count' => 1, 'items' => []],
    'source_summary' => ['beliefs' => 1, 'sessions' => 1],
    'metadata' => [
        'context' => ['title' => 'Ctx', 'status' => 'active', 'description' => 'desc-A'],
        'current_state_echo' => ['current_decision_status' => 'open', 'current_confidence' => '0.6', 'latest_next_step' => 'step A'],
        'tags' => ['t1'],
    ],
];
$payloadB = $payloadA;
$payloadB['metadata']['context']['description'] = 'desc-B';

$hashSnapshotA = $compute->invoke($snapshotService, 'markdown', $payloadA);
$hashSnapshotB = $compute->invoke($snapshotService, 'markdown', $payloadB);
check($hashSnapshotA !== $hashSnapshotB, 'Snapshot hash is sensitive to context description changes');

$row = [
    'snapshot_markdown' => 'markdown',
    'strategic_narrative_json' => json_encode($payloadA['strategic_narrative'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'beliefs_snapshot_json' => json_encode($payloadA['beliefs'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'risks_snapshot_json' => json_encode($payloadA['risks'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'evidence_snapshot_json' => json_encode($payloadA['evidence'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'social_snapshot_json' => json_encode($payloadA['social'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'timeline_snapshot_json' => json_encode($payloadA['timeline'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'memory_compilations_json' => json_encode($payloadA['memory_compilations'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'source_summary_json' => json_encode($payloadA['source_summary'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'metadata_json' => json_encode($payloadA['metadata'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'snapshot_hash' => $hashSnapshotA,
];
$okIntegrity = $snapshotService->verifySnapshotIntegrityRecord($row);
check(($okIntegrity['valid'] ?? false) === true, 'Snapshot integrity check validates untampered payload');

$rowTampered = $row;
$rowTampered['beliefs_snapshot_json'] = json_encode(['counts' => ['total' => 99], 'samples' => []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$tamperedIntegrity = $snapshotService->verifySnapshotIntegrityRecord($rowTampered);
check(($tamperedIntegrity['valid'] ?? true) === false, 'Snapshot integrity check detects corruption');

if (($GLOBALS['__prov_failures'] ?? 0) > 0) {
    echo 'Provenance hardening checks failed: ' . (int)$GLOBALS['__prov_failures'] . PHP_EOL;
    exit(1);
}

echo 'Provenance hardening checks passed.' . PHP_EOL;
exit(0);


<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\DecisionMemory\DecisionMemoryAgentPropagationService;
use Domain\Sessions\SessionAgentResolver;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

echo "Agent memory propagation + workspace coherence checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$ctxRepo = new StrategicContextRepository();
$sessions = new SessionRepository();
$agentMem = new AgentContextMemoryService();
$prop = new DecisionMemoryAgentPropagationService();
$resolver = new SessionAgentResolver();

$ctx = $ctxRepo->create('Propagation test', 'tmp', 'active');
$cid = (string)($ctx['context_id'] ?? '');
if ($cid === '') {
    echo "FAIL: context\n";
    exit(1);
}
echo "PASS: context created\n";

$sid = 'sess-prop-' . substr(bin2hex(random_bytes(6)), 0, 12);
$sessions->create([
    'id' => $sid,
    'title' => 'Prop test',
    'mode' => 'decision-room',
    'initial_prompt' => '',
    'selected_agents' => json_encode(['pm', 'synthesizer', 'devil_advocate']),
    'rounds' => 1,
    'language' => 'en',
    'status' => 'completed',
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'strategic_context_id' => $cid,
]);

$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-' . $sid, $sid, 'assistant', 'architect', 'x', gmdate('c')]);

$parts = $resolver->resolveParticipants($sid);
if (in_array('synthesizer', $parts, true) || in_array('devil_advocate', $parts, true)) {
    echo "FAIL: synthesizer/devil_advocate should be excluded by default\n";
    exit(1);
}
if (!in_array('pm', $parts, true) || !in_array('architect', $parts, true)) {
    echo "FAIL: expected pm+architect participants\n";
    exit(1);
}
echo "PASS: SessionAgentResolver participants (exclusions + messages)\n";

$memoryId = 'dm-prop-' . substr(bin2hex(random_bytes(8)), 0, 16);
$now = gmdate('c');
$pdo->prepare(
    'INSERT INTO decision_memories (
        memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
        validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps, historical_outcome,
        contract_version, taxonomy_version, persistence_safety, user_confirmed, created_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $memoryId,
    $sid,
    'test-playbook',
    'proceed',
    'strong',
    'Summary line for propagation test',
    '[]',
    '[]',
    json_encode(['Risk A']),
    json_encode(['Next 1']),
    '{}',
    'cv1',
    'tv1',
    json_encode(['safe_to_persist' => true]),
    1,
    $now,
]);

$pdo->prepare('INSERT INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $memoryId, $now]);

$preview = $prop->preview($sid, $memoryId);
if (isset($preview['error'])) {
    echo "FAIL: preview error " . ($preview['error'] ?? '') . "\n";
    exit(1);
}
$previewAgents = $preview['agents'] ?? [];
if (count($previewAgents) < 1) {
    echo "FAIL: preview agents empty\n";
    exit(1);
}
echo "PASS: preview returns agents\n";

$noWrite = $prop->propagate($sid, $memoryId, [], false, false);
if (!isset($noWrite['error']) || $noWrite['error'] !== 'confirm_required') {
    echo "FAIL: confirm_required when confirm false\n";
    exit(1);
}
echo "PASS: no write without confirm\n";

$ctxRepo->unlinkMemory($cid, $memoryId);
$bad = $prop->preview($sid, $memoryId);
if (!isset($bad['error']) || $bad['error'] !== 'memory_not_linked_to_session_context') {
    echo "FAIL: unlinked memory should be rejected\n";
    exit(1);
}
echo "PASS: unlink blocks propagation preview\n";

$pdo->prepare('INSERT INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $memoryId, $now]);

$pmPath = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($cid) . '/agents/pm';
if (is_dir($pmPath)) {
    foreach (glob($pmPath . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($pmPath);
}

$res = $prop->propagate($sid, $memoryId, ['pm'], true, false);
$r0 = $res['results'][0] ?? null;
if (!$r0 || ($r0['ok'] ?? false) !== true || ($r0['changed'] ?? false) !== true) {
    echo "FAIL: propagate pm " . json_encode($r0) . "\n";
    exit(1);
}
$body = $agentMem->read($cid, 'pm');
if (!str_contains($body, 'da-propagated-decision:' . $memoryId)) {
    echo "FAIL: marker missing in agent memory.md\n";
    exit(1);
}
if (!str_contains($body, 'Decisions Remembered')) {
    echo "FAIL: decisions remembered section missing\n";
    exit(1);
}
echo "PASS: propagate writes agent memory.md\n";

$dup = $prop->propagate($sid, $memoryId, ['pm'], true, false);
$r1 = $dup['results'][0] ?? null;
if (!$r1 || ($r1['skipped_duplicate'] ?? false) !== true) {
    echo "FAIL: duplicate propagation should skip\n";
    exit(1);
}
echo "PASS: duplicate propagation skipped\n";

$non = $prop->propagate($sid, $memoryId, ['not-a-real-agent-in-session'], true, false);
$rn = $non['results'][0] ?? null;
if (!$rn || ($rn['ok'] ?? false) !== false) {
    echo "FAIL: non-participant without override should fail per agent\n";
    exit(1);
}
echo "PASS: non-participant rejected\n";

$mdGen = new \Domain\Memory\MemorySnapshotGenerator();
$md = $mdGen->generateContextMarkdown($cid);
if (!str_contains($md, '## Decisions Remembered')) {
    echo "FAIL: context memory.md snapshot missing Decisions Remembered\n";
    exit(1);
}
if (!str_contains($md, $memoryId)) {
    echo "FAIL: context markdown missing memory id\n";
    exit(1);
}
echo "PASS: context markdown lists linked decision memory\n";

echo "\nAll propagation contract checks passed.\n";

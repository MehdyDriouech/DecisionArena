<?php
declare(strict_types=1);

/**
 * Tests CLI sans LLM : comparaison de contextes + chat situé avec ProviderRouter mocké.
 * php backend/tools/test_compare_and_situated_chat.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Agents\Agent;
use Domain\Providers\ProviderRouter;
use Domain\StrategicContext\AgentContextChatService;
use Domain\StrategicContext\StrategicContextComparisonService;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class DaStubSituatedChatRouter extends ProviderRouter
{
    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        return [
            'content' => 'TEST_STUB_SITUATED',
            'provider_id' => 'stub',
            'provider_name' => 'Stub',
            'provider_type' => 'stub',
            'model' => 'stub',
            'routing_mode' => 'stub',
        ];
    }
}

final class DaThrowingSituatedChatRouter extends ProviderRouter
{
    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        throw new \RuntimeException('simulated_provider_failure');
    }
}

echo "Compare + Situated Chat (stub) checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$repo = new StrategicContextRepository();
$a = $repo->create('CompareCtxA', 'A', 'active');
$b = $repo->create('CompareCtxB', 'B', 'active');
$aid = (string)($a['context_id'] ?? '');
$bid = (string)($b['context_id'] ?? '');
if ($aid === '' || $bid === '') {
    echo "FAIL: create contexts\n";
    exit(1);
}

$sessionsCmp = new SessionRepository();
$sidUnion = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
$now = gmdate('c');
$sessionsCmp->create([
    'id' => $sidUnion,
    'title' => 'Union compare session',
    'mode' => 'chat',
    'initial_prompt' => 'compare union',
    'selected_agents' => ['custom_a'],
    'rounds' => 1,
    'language' => 'en',
    'status' => 'completed',
    'strategic_context_id' => $aid,
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-union-' . $sidUnion, $sidUnion, 'assistant', 'custom_a', 'x', $now]);

$memSvc = new \Domain\StrategicContext\AgentContextMemoryService();
$memSvc->write($aid, 'custom_a', "# Agent Context Memory\n\n## Open Questions\n\n- union-tag-a\n");
$memSvc->write($bid, 'custom_b', "# Agent Context Memory\n\n## Open Questions\n\n- union-tag-b\n");

$cmp = new StrategicContextComparisonService();
$activeBefore = $repo->getActiveContext();
$r = $cmp->compare($aid, $bid, false, false, true, false, false);
if (($r['ok'] ?? false) !== true) {
    echo "FAIL: compare not ok: " . json_encode($r) . "\n";
    exit(1);
}
$activeAfter = $repo->getActiveContext();
$ab = strtolower(trim((string)($activeBefore['context_id'] ?? '')));
$aa = strtolower(trim((string)($activeAfter['context_id'] ?? '')));
if ($ab !== $aa) {
    echo "FAIL: active workspace context changed by compare (before=$ab after=$aa)\n";
    exit(1);
}
$seenAgents = [];
foreach (($r['diff']['agent_memory_differences'] ?? []) as $d) {
    if (!empty($d['agent_id'])) {
        $seenAgents[strtolower((string)$d['agent_id'])] = true;
    }
}
if (!isset($seenAgents['custom_a']) || !isset($seenAgents['custom_b'])) {
    echo "FAIL: expected custom_a and custom_b in agent_memory_differences, got keys: " . implode(',', array_keys($seenAgents)) . "\n";
    exit(1);
}
$amMeta = $r['diff']['runtime_meta']['agent_memory_compare'] ?? null;
if (!is_array($amMeta) || ($amMeta['source'] ?? '') === '') {
    echo "FAIL: runtime_meta.agent_memory_compare missing\n";
    exit(1);
}
if (($amMeta['source'] ?? '') !== 'StrategicContextWorkspaceAgentsCatalog::unionAgentIdsForCrossContextMemoryDiff') {
    echo "FAIL: unexpected agent_memory_compare source\n";
    exit(1);
}
echo "PASS: compare uses dynamic agent union (custom_a, custom_b) + read-only (active context unchanged)\n";

if (($r['left']['context_id'] ?? '') !== strtolower($aid) || ($r['right']['context_id'] ?? '') !== strtolower($bid)) {
    echo "FAIL: compare left/right context ids\n";
    exit(1);
}
if (!isset($r['diff']['agent_memory_differences']) || !is_array($r['diff']['agent_memory_differences'])) {
    echo "FAIL: compare missing agent_memory_differences\n";
    exit(1);
}
echo "PASS: StrategicContextComparisonService::compare (read-only)\n";

$memSvc->write($aid, 'pm', "# Agent Context Memory\n\n## Open Questions\n\n- Q unique A\n");
$memSvc->write($bid, 'pm', "# Agent Context Memory\n\n## Open Questions\n\n- Q unique B\n");

$r2 = $cmp->compare($aid, $bid, false, false, true, false, false);
$memDiff = $r2['diff']['agent_memory_differences'] ?? [];
if (!is_array($memDiff) || count($memDiff) < 1) {
    echo "FAIL: expected agent_memory_differences after distinct memory.md\n";
    exit(1);
}
echo "PASS: agent memory diff non-empty for A vs B\n";

$chatOk = new AgentContextChatService(new DaStubSituatedChatRouter());
$out = $chatOk->exchange($aid, 'pm', 'Hello stub', false, false, false, null, null, 'en');
if (($out['ok'] ?? false) !== true || ($out['answer'] ?? '') !== 'TEST_STUB_SITUATED') {
    echo "FAIL: situated chat stub answer\n";
    exit(1);
}
if (($out['memory_used'] ?? true) !== false) {
    echo "FAIL: memory_used should be false when includeMemory false\n";
    exit(1);
}
if (($out['social_context_used'] ?? true) !== false) {
    echo "FAIL: social_context_used false when includeSocial false\n";
    exit(1);
}
if (($out['decisions_used'] ?? null) !== []) {
    echo "FAIL: decisions_used empty when includeDecisions false\n";
    exit(1);
}
echo "PASS: AgentContextChatService with stub router (no LLM)\n";

$chatThrow = new AgentContextChatService(new DaThrowingSituatedChatRouter());
$outBad = $chatThrow->exchange($aid, 'architect', 'This will fail', true, true, true, null, null, 'fr');
if (($outBad['ok'] ?? true) !== false) {
    echo "FAIL: situated chat should error when router throws\n";
    exit(1);
}
$pdo = Database::getConnection();
$stmt = $pdo->query('SELECT COUNT(*) FROM agent_context_chat_messages WHERE content = ' . $pdo->quote('This will fail'));
$n = (int)$stmt->fetchColumn();
if ($n !== 0) {
    echo "FAIL: orphan user message should be removed after provider failure (found $n)\n";
    exit(1);
}
echo "PASS: user message rolled back on provider failure\n";

echo "\nOK\n";

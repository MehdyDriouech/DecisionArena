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

use Controllers\OpenSpaceController;
use Controllers\StrategicContextController;
use Domain\Agents\AgentAssembler;
use Domain\OpenSpace\OpenSpaceAgentChatPromptBuilder;
use Domain\Providers\ProviderRouter;
use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\OpenSpaceRepository;
use Infrastructure\Persistence\StrategicContextAgentsRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class TestRequestChat extends Http\Request
{
    public function __construct(private array $bodyData = [], private array $queryData = [], private array $paramData = [])
    {
    }
    public function body(): array { return $this->bodyData; }
    public function query(string $key, mixed $default = null): mixed { return $this->queryData[$key] ?? $default; }
    public function param(string $key, mixed $default = null): mixed { return $this->paramData[$key] ?? $default; }
}

final class MockProviderRouterChat extends ProviderRouter
{
    /** @var list<array{role: string, content: string}>|null */
    public static ?array $lastMessages = null;

    public function chat(
        array $messages,
        ?\Domain\Agents\Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        self::$lastMessages = $messages;
        return [
            'content' => 'Réponse située mock depuis persona.',
            'provider_id' => 'mock-provider',
            'provider_name' => 'Mock Provider',
            'provider_type' => 'mock',
            'model' => 'mock-model-v1',
            'routing_mode' => 'single-primary',
            'routing_source' => 'global_routing',
            'fallback_used' => false,
            'fallback_reason' => null,
            'requested_provider_id' => null,
            'requested_model' => null,
        ];
    }
}

function chat_check(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__chat_fail'] = ($GLOBALS['__chat_fail'] ?? 0) + 1;
    }
}

function chat_is_error(array $res): bool
{
    return !empty($res['error']);
}

$GLOBALS['__chat_fail'] = 0;

$builderSmoke = new OpenSpaceAgentChatPromptBuilder();
$smokeMsgs = $builderSmoke->buildChatMessages([
    'strategic_context_id' => '00000000-0000-4000-8000-000000000001',
    'context_title' => 'T',
    'context_description' => 'D',
    'task_block' => 'No linked task.',
    'persona_id' => 'pm',
    'persona_name' => 'John',
    'persona_title' => 'PM',
    'persona_content' => '# Role\nTest persona body.',
    'soul_content' => '',
    'memory_md_block' => 'No context memory available for this agent yet.',
    'decision_memories_block' => '- none',
    'history_block' => '- none',
    'user_message' => 'Hello',
]);
chat_check(str_contains((string)($smokeMsgs[0]['content'] ?? ''), 'open_space_task_messages'), '0a) policy loads (persistence rule)');
chat_check(str_contains((string)($smokeMsgs[1]['content'] ?? ''), '## Full persona document') && str_contains((string)($smokeMsgs[1]['content'] ?? ''), '# Role'), '0b) user payload includes full persona section');

$migration = new Migration(Database::getInstance());
$migration->run();

$contexts = new StrategicContextRepository();
$repo = new OpenSpaceRepository();
$controller = new OpenSpaceController(new MockProviderRouterChat(), new AgentAssembler());
MockProviderRouterChat::$lastMessages = null;
$memorySvc = new AgentContextMemoryService();

$ctx = $contexts->create('OS Agent Chat Test ' . substr(bin2hex(random_bytes(4)), 0, 8), 'desc', 'active');
$contextId = (string)($ctx['context_id'] ?? '');
$board = $repo->ensureContextBoard($contextId);
$task = $repo->createTask([
    'board_id' => (string)($board['id'] ?? ''),
    'strategic_context_id' => $contextId,
    'title' => 'Task chat',
    'description' => 'desc',
    'status' => 'backlog',
    'priority' => 'medium',
    'assignee_agent_id' => 'pm',
    'source_type' => 'manual',
    'source_id' => null,
    'linked_session_id' => null,
    'linked_decision_memory_id' => null,
    'acceptance_criteria' => null,
    'created_by' => 'test',
]);
$taskId = (string)($task['id'] ?? '');

$scAgents = new StrategicContextAgentsRepository();
$scAgents->insert($contextId, 'pm', 'manual');
$memorySvc->ensureFile($contextId, 'pm');

$beforeMemory = $memorySvc->readIfExistsNoSideEffects($contextId, 'pm');
$existsBefore = ($beforeMemory['exists'] ?? false) === true;
$contentBefore = (string)($beforeMemory['content'] ?? '');

$res = $controller->createTaskMessage(new TestRequestChat([
    'strategic_context_id' => $contextId,
    'context_id' => $contextId,
    'role' => 'user',
    'content' => 'Donne-moi une recommandation.',
    'agent_id' => 'pm',
    'generate_reply' => true,
], [], ['id' => $taskId]));

chat_check(!chat_is_error($res), '1) chat message with generate_reply succeeds');
chat_check(is_array($res['reply'] ?? null), '2) assistant reply is persisted');
chat_check(is_array($res['reply_diagnostics'] ?? null), '1b) reply_diagnostics present');
chat_check(($res['reply_diagnostics']['agent_id'] ?? '') === 'pm', '1c) diagnostics agent_id');

$reply = $res['reply'] ?? [];
$meta = json_decode((string)($reply['metadata_json'] ?? ''), true);
chat_check(is_array($meta) && ($meta['provider_id'] ?? '') === 'mock-provider', '3) provider metadata persisted on assistant message');

$messages = $repo->listTaskMessages($contextId, $taskId);
chat_check(count($messages) >= 2, '4) messages are stored in open_space_task_messages');

$afterMemory = $memorySvc->readIfExistsNoSideEffects($contextId, 'pm');
$existsAfter = ($afterMemory['exists'] ?? false) === true;
$contentAfter = (string)($afterMemory['content'] ?? '');
chat_check($existsBefore === $existsAfter && $contentBefore === $contentAfter, '5) memory.md unchanged by OpenSpace chat');

$lm = MockProviderRouterChat::$lastMessages;
chat_check(is_array($lm) && count($lm) === 2, '6) router received two messages');
$u = is_array($lm) ? (string)($lm[1]['content'] ?? '') : '';
$s = is_array($lm) ? (string)($lm[0]['content'] ?? '') : '';
chat_check(str_contains($s, 'OpenSpace Agent Chat') || str_contains($s, 'open_space_task_messages'), '7) system prompt includes policy markdown');
chat_check(str_contains($u, '## Full persona document') && str_contains($u, '# Role'), '8) user message embeds full persona document body');
chat_check(str_contains($u, '## Agent context memory (memory.md excerpt)'), '9) user message embeds memory.md section');

$catalog = new StrategicContextWorkspaceAgentsCatalog();
$osList = $catalog->buildForOpenSpaceAgentChat($contextId);
chat_check(count($osList) >= 1 && ($osList[0]['agent_id'] ?? '') !== '', '10) open space agent list non-empty');
$ids = array_map(static fn ($r) => strtolower((string)($r['agent_id'] ?? '')), $osList);
chat_check(in_array('pm', $ids, true), '10b) pm in contextual list');

$ctrlSc = new StrategicContextController();
$addAnalyst = $ctrlSc->addContextAgent(new TestRequestChat(['agent_id' => 'analyst', 'source' => 'manual'], [], ['id' => $contextId]));
chat_check(($addAnalyst['success'] ?? false) === true, '11) POST add analyst to context');
$memAnalyst = $memorySvc->read($contextId, 'analyst');
chat_check(str_contains($memAnalyst, 'manual_context_agent_add'), '12) manual_context_agent_add note');
foreach (['## Stable Beliefs', '## Recent Notes', '## Strategic Assumptions'] as $sec) {
    chat_check(str_contains($memAnalyst, $sec), '13) canonical section ' . $sec);
}

$osCtrl = new OpenSpaceController(new MockProviderRouterChat(), new AgentAssembler());
$listAgents = $osCtrl->listContextAgents(new TestRequestChat([], ['context_id' => $contextId], []));
chat_check(!isset($listAgents['error']) && is_array($listAgents['agents'] ?? null), '14) listContextAgents ok');
$listIds = array_map(static fn ($r) => strtolower((string)($r['agent_id'] ?? '')), $listAgents['agents'] ?? []);
chat_check(in_array('analyst', $listIds, true), '15) analyst in list after add');

$bad = $controller->createTaskMessage(new TestRequestChat([
    'strategic_context_id' => $contextId,
    'context_id' => $contextId,
    'role' => 'user',
    'content' => 'x',
    'agent_id' => 'nonexistent-persona-xyz',
    'generate_reply' => true,
], [], ['id' => $taskId]));
chat_check(chat_is_error($bad), '16) unknown agent rejected for generate_reply');

try { $contexts->delete($contextId); } catch (\Throwable) {}

if (($GLOBALS['__chat_fail'] ?? 0) > 0) {
    echo 'OpenSpace agent chat tests failed: ' . (int)$GLOBALS['__chat_fail'] . PHP_EOL;
    exit(1);
}
echo 'OpenSpace agent chat tests passed.' . PHP_EOL;
exit(0);


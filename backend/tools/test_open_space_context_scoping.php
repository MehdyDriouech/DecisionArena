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
use Domain\Agents\Agent;
use Domain\Providers\ProviderRouter;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\OpenSpaceRepository;
use Infrastructure\Persistence\StrategicContextAgentsRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class TestRequest extends Http\Request
{
    private array $bodyData;
    private array $queryData;
    private array $paramData;

    public function __construct(array $bodyData = [], array $queryData = [], array $paramData = [])
    {
        $this->bodyData = $bodyData;
        $this->queryData = $queryData;
        $this->paramData = $paramData;
    }

    public function body(): array
    {
        return $this->bodyData;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryData[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->paramData[$key] ?? $default;
    }
}

final class OpenSpaceScopingMockProviderRouter extends ProviderRouter
{
    /** @var list<array{role:string,content:string}>|null */
    public static ?array $lastMessages = null;

    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        self::$lastMessages = $messages;
        return [
            'content' => 'Mock OpenSpace answer.',
            'provider_id' => 'mock-provider',
            'provider_name' => 'Mock Provider',
            'provider_type' => 'mock',
            'model' => 'mock-model',
            'routing_mode' => 'single-primary',
            'routing_source' => 'test',
            'fallback_used' => false,
            'fallback_reason' => null,
            'requested_provider_id' => null,
            'requested_model' => null,
        ];
    }
}

function os_check(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__os_fail'] = ($GLOBALS['__os_fail'] ?? 0) + 1;
    }
}

function os_is_error(array $res): bool
{
    return !empty($res['error']);
}

$GLOBALS['__os_fail'] = 0;

$db = Database::getInstance();
$migration = new Migration($db);
$migration->run();
$pdo = Database::getConnection();
$ctxRepo = new StrategicContextRepository();
$openRepo = new OpenSpaceRepository();
$controller = new OpenSpaceController(new OpenSpaceScopingMockProviderRouter());
$memory = new AgentContextMemoryService();
$contextAgents = new StrategicContextAgentsRepository();

$ctxA = $ctxRepo->create(
    'OpenSpace Test A ' . substr(bin2hex(random_bytes(4)), 0, 8),
    'A',
    'active'
);
$ctxB = $ctxRepo->create(
    'OpenSpace Test B ' . substr(bin2hex(random_bytes(4)), 0, 8),
    'B',
    'active'
);
$ctxAId = (string)($ctxA['context_id'] ?? '');
$ctxBId = (string)($ctxB['context_id'] ?? '');

$countsBefore = [
    'beliefs' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_beliefs")->fetchColumn(),
    'narratives' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_narratives")->fetchColumn(),
    'compilations' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_memory_compilations")->fetchColumn(),
    'snapshots' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_snapshots")->fetchColumn(),
];
$promptBuilderPath = realpath(__DIR__ . '/../src/Domain/StrategicContext/PromptBuilder.php') ?: '';
$promptBuilderMtimeBefore = $promptBuilderPath !== '' ? @filemtime($promptBuilderPath) : false;

// 1. Créer board avec context_id valide → OK.
$boardRes = $controller->createBoard(new TestRequest([
    'context_id' => $ctxAId,
    'title' => 'Board A',
]));
os_check(!os_is_error($boardRes) && !empty($boardRes['board']['id']), '1) create board with valid context');
$boardAId = (string)($boardRes['board']['id'] ?? '');

// 2. Créer board sans context_id → refus.
$boardNoCtx = $controller->createBoard(new TestRequest(['title' => 'Board X']));
os_check(os_is_error($boardNoCtx), '2) reject board without context_id');

// 3. Créer task avec context_id → OK.
$taskRes = $controller->createTask(new TestRequest([
    'context_id' => $ctxAId,
    'board_id' => $boardAId,
    'title' => 'Task A1',
    'status' => 'triage',
]));
os_check(!os_is_error($taskRes) && !empty($taskRes['task']['id']), '3) create task with context_id');
$taskAId = (string)($taskRes['task']['id'] ?? '');

// 4. Créer task sans context_id → refus.
$taskNoCtx = $controller->createTask(new TestRequest([
    'board_id' => $boardAId,
    'title' => 'Task no ctx',
]));
os_check(os_is_error($taskNoCtx), '4) reject task without context_id');

// 5. Lire tasks contexte A → ne retourne pas contexte B.
$boardB = $controller->createBoard(new TestRequest([
    'context_id' => $ctxBId,
    'title' => 'Board B',
]));
$boardBId = (string)($boardB['board']['id'] ?? '');
$controller->createTask(new TestRequest([
    'context_id' => $ctxBId,
    'board_id' => $boardBId,
    'title' => 'Task B1',
    'status' => 'triage',
]));
$listA = $controller->listTasks(new TestRequest([], ['context_id' => $ctxAId]));
$onlyA = true;
foreach (($listA['tasks'] ?? []) as $row) {
    if (strtolower((string)($row['strategic_context_id'] ?? '')) !== strtolower($ctxAId)) {
        $onlyA = false;
        break;
    }
}
os_check(!os_is_error($listA) && $onlyA, '5) list tasks returns only selected context');

// 6. Move task avec mauvais context_id → refus.
$badMove = $controller->moveTask(new TestRequest([
    'context_id' => $ctxBId,
    'status' => 'ready',
], [], ['id' => $taskAId]));
os_check(os_is_error($badMove), '6) reject move with wrong context_id');

// 7. Message task cross-context → refus.
$badMsg = $controller->createTaskMessage(new TestRequest([
    'context_id' => $ctxBId,
    'content' => 'cross',
    'role' => 'user',
], [], ['id' => $taskAId]));
os_check(os_is_error($badMsg), '7) reject cross-context task message');

// 8. Orchestrator proposal sans context_id → refus.
$orchestrationNoCtx = $controller->orchestrate(new TestRequest([
    'objective' => 'Test objective',
]));
os_check(os_is_error($orchestrationNoCtx), '8) reject orchestrate without context_id');

// 9. Orchestrator proposal ne crée pas de tasks automatiquement.
$beforeTasksA = count($openRepo->listTasks($ctxAId));
$proposalRes = $controller->orchestrate(new TestRequest([
    'context_id' => $ctxAId,
    'objective' => 'Ship OpenSpace MVP',
    'constraints' => 'No autonomous writes',
]));
$afterOrchestrateTasksA = count($openRepo->listTasks($ctxAId));
os_check(!os_is_error($proposalRes) && $beforeTasksA === $afterOrchestrateTasksA, '9) orchestrate does not auto-create tasks');
$proposalId = (string)($proposalRes['proposal']['id'] ?? '');

// 10. Accept proposal crée tasks uniquement après action explicite.
$acceptRes = $controller->acceptProposal(new TestRequest([
    'context_id' => $ctxAId,
], [], ['id' => $proposalId]));
$afterAcceptTasksA = count($openRepo->listTasks($ctxAId));
os_check(!os_is_error($acceptRes) && $afterAcceptTasksA > $afterOrchestrateTasksA, '10) accept proposal creates tasks after explicit action');

// 11. Agent chat ne crée pas memory.md automatiquement.
$contextAgents->insert($ctxAId, 'pm', 'test_openspace_presence_without_memory_file');
$memoryBefore = $memory->readIfExistsNoSideEffects($ctxAId, 'pm');
$chatRes = $controller->createTaskMessage(new TestRequest([
    'context_id' => $ctxAId,
    'content' => 'Donne un plan court',
    'role' => 'user',
    'agent_id' => 'pm',
    'generate_reply' => true,
], [], ['id' => '_context']));
$memoryAfter = $memory->readIfExistsNoSideEffects($ctxAId, 'pm');
os_check(!os_is_error($chatRes) && ($memoryBefore['exists'] ?? false) === ($memoryAfter['exists'] ?? false), '11) chat does not auto-create memory.md');
$lastMessages = OpenSpaceScopingMockProviderRouter::$lastMessages;
$lastUserPrompt = is_array($lastMessages) ? (string)($lastMessages[1]['content'] ?? '') : '';
os_check(str_contains($lastUserPrompt, 'No context memory available for this agent yet.'), '11b) prompt states no context memory available');

// 12. Aucun belief/narrative/compiler/snapshot muté.
$countsAfter = [
    'beliefs' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_beliefs")->fetchColumn(),
    'narratives' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_narratives")->fetchColumn(),
    'compilations' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_memory_compilations")->fetchColumn(),
    'snapshots' => (int)$pdo->query("SELECT COUNT(*) FROM strategic_context_snapshots")->fetchColumn(),
];
$sameGovernanceCounts = $countsBefore === $countsAfter;
os_check($sameGovernanceCounts, '12) no belief/narrative/compiler/snapshot mutation');

// 13. Aucun PromptBuilder write.
$promptBuilderMtimeAfter = $promptBuilderPath !== '' ? @filemtime($promptBuilderPath) : false;
os_check($promptBuilderMtimeBefore === $promptBuilderMtimeAfter, '13) PromptBuilder file not mutated');

// 14. Aucun llmAssignmentMode.
$llmAssignmentLeak = false;
foreach ([$proposalRes, $acceptRes, $chatRes] as $res) {
    $json = json_encode($res, JSON_UNESCAPED_UNICODE);
    if (is_string($json) && stripos($json, 'llmAssignmentMode') !== false) {
        $llmAssignmentLeak = true;
        break;
    }
}
os_check(!$llmAssignmentLeak, '14) no llmAssignmentMode in OpenSpace responses');

// Cleanup test contexts and dependent OpenSpace rows.
try {
    $ctxRepo->delete($ctxAId);
} catch (\Throwable) {
}
try {
    $ctxRepo->delete($ctxBId);
} catch (\Throwable) {
}

if (($GLOBALS['__os_fail'] ?? 0) > 0) {
    echo 'OpenSpace context scoping checks failed: ' . (int)$GLOBALS['__os_fail'] . PHP_EOL;
    exit(1);
}

echo 'OpenSpace context scoping checks passed.' . PHP_EOL;
exit(0);


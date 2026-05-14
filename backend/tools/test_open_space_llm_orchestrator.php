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
use Domain\Agents\AgentAssembler;
use Domain\OpenSpace\OpenSpaceOrchestratorPromptBuilder;
use Domain\Providers\ProviderRouter;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\OpenSpaceRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class TestRequestOrchestrator extends Http\Request
{
    public function __construct(private array $bodyData = [], private array $queryData = [], private array $paramData = [])
    {
    }
    public function body(): array { return $this->bodyData; }
    public function query(string $key, mixed $default = null): mixed { return $this->queryData[$key] ?? $default; }
    public function param(string $key, mixed $default = null): mixed { return $this->paramData[$key] ?? $default; }
}

final class MockProviderRouterOrchestrator extends ProviderRouter
{
    public static ?string $lastOrchestratorAgentId = null;

    public function chat(
        array $messages,
        ?\Domain\Agents\Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        self::$lastOrchestratorAgentId = $agent?->id ?? null;
        $json = json_encode([
            'recommended_mode' => 'decision-room',
            'mode_rationale' => 'Complex objective with risks and dependencies.',
            'recommended_agents' => [
                ['agent_id' => 'pm', 'reason' => 'coordination'],
                ['agent_id' => 'architect', 'reason' => 'system design'],
            ],
            'proposed_tasks' => [
                [
                    'title' => 'Align objective and constraints',
                    'description' => 'Create a validated objective envelope.',
                    'status' => 'backlog',
                    'priority' => 'high',
                    'assignee_agent_id' => 'pm',
                    'acceptance_criteria' => ['Validated by human owner'],
                    'jira' => [
                        'issue_type' => 'Task',
                        'labels' => ['decision-arena', 'openspace'],
                        'summary' => 'Align objective and constraints',
                        'description' => 'Create validated objective envelope',
                    ],
                ],
            ],
            'risks' => ['Ambiguous scope'],
            'open_questions' => ['What is done criteria?'],
            'assumptions' => ['Manual validation before execution'],
            'next_recommended_action' => 'Validate proposal and create tasks.',
        ], JSON_UNESCAPED_UNICODE);
        return [
            'content' => (string)$json,
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

final class MockBadJsonOrchestrator extends ProviderRouter
{
    public function chat(
        array $messages,
        ?\Domain\Agents\Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        return [
            'content' => 'This is not JSON and has no extractable object { broken',
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

function orch_check(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__orch_fail'] = ($GLOBALS['__orch_fail'] ?? 0) + 1;
    }
}

function orch_is_error(array $res): bool
{
    return !empty($res['error']);
}

$GLOBALS['__orch_fail'] = 0;

// —— Prompt builder (no DB) ——
$builder = new OpenSpaceOrchestratorPromptBuilder();
$sampleUuid = '00000000-0000-4000-8000-000000000001';
$msgs = $builder->buildProposalMessages([
    'context_id' => $sampleUuid,
    'context_title' => 'Test context',
    'context_description' => 'Desc',
    'objective' => 'Ship faster',
    'constraints' => 'Budget',
    'existing_task_lines' => ['- t1 | backlog | A'],
    'decision_lines' => ['- dm | confirmed | Summary'],
    'agent_lines' => ['- pm | PM'],
    'memory_snippets' => ['### pm memory.md (excerpt)', 'note'],
]);
$sys = (string)($msgs[0]['content'] ?? '');
$usr = (string)($msgs[1]['content'] ?? '');
orch_check(str_contains($sys, 'Multi-Agent Orchestrator Policy') || str_contains($sys, 'orchestrator'), 'A) system includes common orchestrator policy file body');
orch_check(str_contains($sys, 'OpenSpace orchestrator') && str_contains($sys, 'Non-actions'), 'B) system includes OpenSpace contract body');
orch_check(stripos($sys, 'user validates') !== false, 'C) system contains governance (user validates)');
orch_check(str_contains($sys, 'recommended_mode'), 'D) system JSON contract mentions recommended_mode');
orch_check(str_contains($usr, '### Objective') && str_contains($usr, 'Ship faster'), 'E) user message carries runtime objective');

$controllerPhp = (string)file_get_contents(__DIR__ . '/../src/Controllers/OpenSpaceController.php');
orch_check(!str_contains($controllerPhp, 'You are OpenSpace Orchestrator for Decision Arena'), 'F) controller no longer embeds legacy hardcoded OpenSpace system one-liner');

// —— DB-backed flow ——
$migration = new Migration(Database::getInstance());
$migration->run();

$contexts = new StrategicContextRepository();
$repo = new OpenSpaceRepository();
MockProviderRouterOrchestrator::$lastOrchestratorAgentId = null;
$controller = new OpenSpaceController(new MockProviderRouterOrchestrator(), new AgentAssembler());

$ctx = $contexts->create('OS Orchestrator Test ' . substr(bin2hex(random_bytes(4)), 0, 8), 'desc', 'active');
$contextId = (string)($ctx['context_id'] ?? '');

$before = count($repo->listTasks($contextId));
$res = $controller->orchestrate(new TestRequestOrchestrator([
    'strategic_context_id' => $contextId,
    'objective' => 'Prepare launch decision quickly',
    'constraints' => 'Budget and legal constraints',
    'mode' => 'proposal_only',
]));
orch_check(!orch_is_error($res), '1) orchestrate calls LLM/mock and succeeds');

$proposal = $res['proposal'] ?? null;
orch_check(is_array($proposal) && ($proposal['status'] ?? '') === 'draft', '2) proposal saved in draft');
orch_check((int)count($repo->listTasks($contextId)) === $before, '3) orchestrate does not auto-create tasks');

$metaRaw = (string)($proposal['proposal_metadata_json'] ?? '');
$meta = $metaRaw !== '' ? json_decode($metaRaw, true) : [];
orch_check(is_array($meta) && ($meta['provider_id'] ?? '') === 'mock-provider', '4) provider metadata stored');
orch_check(($proposal['proposal_source'] ?? '') === 'llm' && (int)($proposal['warning'] ?? 0) === 0, '5) llm source persisted');

orch_check(MockProviderRouterOrchestrator::$lastOrchestratorAgentId === 'pm', '6) orchestrator agent fallback uses pm when orchestrator personas absent');

$accept = $controller->acceptProposal(new TestRequestOrchestrator([
    'context_id' => $contextId,
], [], ['id' => (string)($proposal['id'] ?? '')]));
orch_check(!orch_is_error($accept), '7) accept proposal succeeds');
$after = count($repo->listTasks($contextId));
orch_check($after > $before, '8) tasks are created only after explicit accept');

$ctxBad = $contexts->create('OS Bad JSON ' . substr(bin2hex(random_bytes(4)), 0, 8), 'd', 'active');
$badContextId = (string)($ctxBad['context_id'] ?? '');
$badController = new OpenSpaceController(new MockBadJsonOrchestrator(), new AgentAssembler());
$resBad = $badController->orchestrate(new TestRequestOrchestrator([
    'strategic_context_id' => $badContextId,
    'objective' => 'x',
    'constraints' => '',
    'mode' => 'proposal_only',
]));
orch_check(($resBad['proposal_source'] ?? '') === 'fallback_static', '9) invalid LLM JSON triggers explicit static fallback');
orch_check(!empty($resBad['warning']) && str_contains((string)($resBad['warning_message'] ?? ''), 'LLM response is not valid JSON'), '10) fallback warning_message cites parse failure');

try { $contexts->delete($contextId); } catch (\Throwable) {}
try { $contexts->delete($badContextId); } catch (\Throwable) {}

if (($GLOBALS['__orch_fail'] ?? 0) > 0) {
    echo 'OpenSpace orchestrator tests failed: ' . (int)$GLOBALS['__orch_fail'] . PHP_EOL;
    exit(1);
}
echo 'OpenSpace orchestrator tests passed.' . PHP_EOL;
exit(0);

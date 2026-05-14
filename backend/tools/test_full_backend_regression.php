<?php
declare(strict_types=1);

/**
 * Full backend/API non-regression suite.
 *
 * Usage from backend/:
 *   php tools/test_full_backend_regression.php
 */

require_once __DIR__ . '/bootstrap.php';

use Controllers\SessionController;
use Controllers\StrategicContextController;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;

final class FullRegressionRequest extends Request
{
    public function __construct(
        private array $bodyData = [],
        private array $queryData = [],
        private array $paramData = [],
    ) {
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

$PASS = 0;
$WARN = 0;
$FAIL = 0;

function fr_pass(string $label): void
{
    global $PASS;
    ++$PASS;
    echo "[PASS] {$label}\n";
}

function fr_warn(string $label, string $detail = ''): void
{
    global $WARN;
    ++$WARN;
    echo "[WARN] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
}

function fr_fail(string $label, string $detail = ''): void
{
    global $FAIL;
    ++$FAIL;
    echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
}

function fr_check(bool $ok, string $label, string $detail = ''): void
{
    if ($ok) {
        fr_pass($label);
    } else {
        fr_fail($label, $detail);
    }
}

function fr_is_error(array $res): bool
{
    return !empty($res['error']);
}

function fr_run_subtest(string $label, string $command, ?array $env = null): void
{
    $previousEnv = [];
    if ($env !== null) {
        foreach ($env as $key => $value) {
            $previousEnv[$key] = getenv($key);
            putenv($key . '=' . (string)$value);
            $_ENV[$key] = (string)$value;
            $_SERVER[$key] = (string)$value;
        }
    }

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);

    if ($env !== null) {
        foreach ($env as $key => $_value) {
            if ($previousEnv[$key] === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv($key . '=' . (string)$previousEnv[$key]);
            $_ENV[$key] = (string)$previousEnv[$key];
            $_SERVER[$key] = (string)$previousEnv[$key];
        }
    }

    if ($code === 0) {
        fr_pass($label);
        return;
    }
    $tail = implode("\n", array_slice($output, -12));
    fr_fail($label, "exit={$code}\n{$tail}");
}

function fr_read(string $relativePath): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

function fr_project_read(string $relativePath): string
{
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

echo "Decision Arena full backend regression\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

// A. Sessions / modes / API route registry.
$index = fr_read('public/index.php');
foreach ([
    '/api/chat/send',
    '/api/decision-room/run',
    '/api/confrontation/run',
    '/api/quick-decision/run',
    '/api/stress-test/run',
    '/api/jury/run',
] as $route) {
    fr_check(str_contains($index, "'" . $route . "'") || str_contains($index, '"' . $route . '"'), "route registered {$route}");
}

$sc = new StrategicContextController();
$ctxRes = $sc->create(new FullRegressionRequest([
    'title' => 'Full regression context ' . substr(bin2hex(random_bytes(4)), 0, 8),
    'description' => 'Backend regression fixture',
    'status' => 'active',
]));
$ctxId = (string)($ctxRes['context']['context_id'] ?? '');
fr_check($ctxId !== '', 'strategic context fixture created');
$activate = $sc->activate(new FullRegressionRequest([], [], ['id' => $ctxId]));
fr_check(!fr_is_error($activate) && (($activate['active_context']['context_id'] ?? '') === $ctxId), 'activation context atomique');

$sessionController = new SessionController();
$minimal = $sessionController->store(new FullRegressionRequest([
    'title' => 'Full regression minimal session',
    'mode' => 'chat',
    'initial_prompt' => 'Hello',
    'selected_agents' => ['pm'],
    'confirm_legacy_no_active_strategic_context' => true,
]));
$minimalId = (string)($minimal['id'] ?? '');
fr_check($minimalId !== '', 'creation session minimale');
$showMinimal = $sessionController->show(new FullRegressionRequest([], [], ['id' => $minimalId]));
fr_check(!fr_is_error($showMinimal) && (($showMinimal['session']['id'] ?? '') === $minimalId), 'GET /api/sessions/{id} deep-link API support');

$contextual = $sessionController->store(new FullRegressionRequest([
    'title' => 'Full regression contextual session',
    'mode' => 'decision-room',
    'initial_prompt' => 'Should we ship?',
    'selected_agents' => ['pm', 'architect'],
    'strategic_context_id' => $ctxId,
]));
$contextualId = (string)($contextual['id'] ?? '');
fr_check($contextualId !== '' && (($contextual['strategic_context_id'] ?? '') === $ctxId), 'creation session avec strategic_context_id');
$invalidContext = $sessionController->store(new FullRegressionRequest([
    'title' => 'Full regression invalid context session',
    'mode' => 'decision-room',
    'initial_prompt' => 'Should fail cleanly',
    'selected_agents' => ['pm'],
    'strategic_context_id' => 'not-a-valid-context',
]));
fr_check(fr_is_error($invalidContext), 'contexte absent/invalide refuse ou fallback controle');

// B-I. Domain suites: each existing focused script owns its deep contract.
fr_run_subtest('Run Progress / run-status matrix', 'php tools/test_run_progress_status.php');
fr_run_subtest('Providers override/routing blackbox', 'php tools/test_runner_llm_override_blackbox.php');
fr_run_subtest('Provider disabled ignored', 'php tools/test_provider_enable_disable.php');
fr_run_subtest('No llmAssignmentMode static assertion', 'php tools/assert_no_llm_assignment_mode.php');
fr_run_subtest('Decision Memory persistence contract', 'php tools/test_decision_memory_persistence_contract.php');
fr_run_subtest('Decision Memory strict outcome projection', 'php tools/test_decision_outcome_projection.php');
fr_run_subtest('Agent Context Memory auto sync', 'php tools/test_agent_context_memory_auto_sync.php');
fr_run_subtest('Agent Context Memory force sync', 'php tools/test_agent_context_memory_force_sync.php');
fr_run_subtest('Strategic Context core invariants', 'php tools/test_strategic_contexts.php');
fr_run_subtest('Strategic Context memory UX contract', 'php tools/test_strategic_context_memory_ux_contract.php');
fr_run_subtest('Cognitive Runtime QA', 'php tools/test_cognitive_runtime_qa.php');
fr_run_subtest('Cognitive Runtime QA guarded', 'php tools/test_cognitive_runtime_qa.php', ['QA_RUNTIME_GUARD' => '1']);
fr_run_subtest('Runtime safety layer', 'php tools/test_runtime_safety_layer.php');
fr_run_subtest('OpenSpace context scoping', 'php tools/test_open_space_context_scoping.php');
fr_run_subtest('OpenSpace LLM orchestrator', 'php tools/test_open_space_llm_orchestrator.php');
fr_run_subtest('OpenSpace agent chat', 'php tools/test_open_space_agent_chat.php');
fr_run_subtest('OpenSpace Kanban Jira export', 'php tools/test_open_space_kanban_jira_export.php');

// I. Feature flags / experimental UI static contract.
$storeJs = fr_project_read('frontend/src/core/store.js');
$routerJs = fr_project_read('frontend/src/core/router.js');
$rendererJs = fr_project_read('frontend/src/core/renderer.js');
fr_check(str_contains($storeJs, 'return false;') && str_contains($storeJs, 'da_experimental_features'), 'experimentalFeaturesEnabled defaults false');
fr_check(str_contains($storeJs, "normalizeUiMode(stateRef.uiMode) === 'expert'") && str_contains($storeJs, 'experimentalFeaturesEnabled === true'), 'OpenSpace visible only expert + experimental flag');
fr_check(str_contains($routerJs, 'OPENSPACE_GATED_VIEWS') && str_contains($routerJs, 'experimental-gate'), 'OpenSpace hash routes gated in router');
fr_check(str_contains($rendererJs, 'canShowExperimentalFeatures') && str_contains($rendererJs, 'showOpenSpaceNav'), 'OpenSpace nav hidden when gate off');
fr_check(!str_contains($storeJs, "uiMode: 'advanced'") && !str_contains($routerJs, 'advanced'), 'no advanced ui mode reintroduced in core routing/store');

// Static route/API coverage for Strategic Context and OpenSpace endpoints.
foreach ([
    '/api/strategic-contexts/{context_id}/timeline',
    '/api/strategic-contexts/compare',
    '/api/strategic-contexts/{id}/narrative',
    '/api/strategic-contexts/{id}/memory-compilations/compile',
    '/api/strategic-contexts/{id}/snapshots',
    '/api/open-space/boards',
    '/api/open-space/tasks',
    '/api/open-space/orchestrate',
    '/api/open-space/boards/{id}/jira-export',
    '/api/open-space/tasks/{id}/jira-export',
] as $route) {
    fr_check(str_contains($index, "'" . $route . "'") || str_contains($index, '"' . $route . '"'), "critical API route registered {$route}");
}

// Basic DB sanity after the suite.
$tables = ['sessions', 'strategic_contexts', 'decision_memories', 'open_space_tasks'];
foreach ($tables as $table) {
    try {
        $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        fr_pass("SQLite table readable {$table}");
    } catch (Throwable $e) {
        fr_fail("SQLite table readable {$table}", $e->getMessage());
    }
}

echo "\nSummary\n";
echo "PASS={$PASS} WARN={$WARN} FAIL={$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);

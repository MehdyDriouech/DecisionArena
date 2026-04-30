<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Http/Router.php';
require_once __DIR__ . '/../src/Http/Request.php';
require_once __DIR__ . '/../src/Http/Response.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Http\Router;
use Http\Request;

$db = Database::getInstance();
$migration = new Migration($db);
$migration->run();

$router = new Router();

// Health
$router->get('/api/health', function(Request $req) {
    return ['status' => 'ok', 'app' => 'Decision Arena'];
});

// Personas — specific routes BEFORE parameterized routes
$router->get('/api/personas/custom', [Controllers\PersonaController::class, 'custom']);
$router->post('/api/personas/build-draft', [Controllers\PersonaController::class, 'buildDraft']);
$router->post('/api/personas/save-custom', [Controllers\PersonaController::class, 'saveCustom']);
$router->post('/api/personas/modes', [Controllers\PersonaController::class, 'saveModes']);
$router->post('/api/personas/make', [Controllers\PersonaMakerController::class, 'make']);
$router->get('/api/personas', [Controllers\PersonaController::class, 'index']);
$router->get('/api/personas/{id}', [Controllers\PersonaController::class, 'show']);

// Souls & Prompts
$router->get('/api/souls', [Controllers\PersonaController::class, 'souls']);
$router->get('/api/prompts', [Controllers\PersonaController::class, 'prompts']);

// Providers
$router->get('/api/providers', [Controllers\ProviderController::class, 'index']);
$router->post('/api/providers', [Controllers\ProviderController::class, 'store']);
$router->post('/api/providers/test', [Controllers\ProviderController::class, 'test']);
$router->post('/api/providers/models', [Controllers\ProviderController::class, 'models']);
$router->get('/api/providers/routing', [Controllers\ProviderRoutingController::class, 'show']);
$router->put('/api/providers/routing', [Controllers\ProviderRoutingController::class, 'update']);
$router->delete('/api/providers/{id}', [Controllers\ProviderController::class, 'destroy']);

// Logs (Admin)
$router->get('/api/logs', [Controllers\LogsController::class, 'index']);
$router->get('/api/logs/{id}', [Controllers\LogsController::class, 'show']);
$router->post('/api/logs/frontend', [Controllers\LogsController::class, 'frontend']);
$router->delete('/api/logs', [Controllers\LogsController::class, 'delete']);
$router->post('/api/logs/export', [Controllers\LogsController::class, 'export']);

// Sessions — specific routes BEFORE parameterized routes
$router->get('/api/sessions', [Controllers\SessionController::class, 'index']);
$router->post('/api/sessions', [Controllers\SessionController::class, 'store']);
$router->post('/api/sessions/delete-all', [Controllers\SessionController::class, 'deleteAll']);
$router->post('/api/sessions/from-template', [Controllers\TemplateController::class, 'fromTemplate']);
$router->get('/api/sessions/{id}', [Controllers\SessionController::class, 'show']);
$router->delete('/api/sessions/{id}', [Controllers\SessionController::class, 'delete']);
$router->post('/api/sessions/{id}/status', [Controllers\SessionController::class, 'updateStatus']);
$router->put('/api/sessions/{id}/memory', [Controllers\SessionController::class, 'memory']);
$router->put('/api/sessions/{id}/decision-threshold', [Controllers\SessionController::class, 'updateThreshold']);
$router->get('/api/sessions/{id}/verdict', [Controllers\VerdictController::class, 'show']);
$router->get('/api/sessions/{id}/votes', [Controllers\VoteController::class, 'show']);
$router->get('/api/sessions/{id}/votes/explanation', [Controllers\VoteController::class, 'explanation']);
$router->post('/api/sessions/{id}/votes/recompute', [Controllers\VoteController::class, 'recompute']);

// Deliberation Intelligence
$router->get('/api/sessions/{id}/decision-summary', [Controllers\DecisionSummaryController::class, 'show']);
$router->get('/api/sessions/{id}/audit', [Controllers\AuditController::class, 'audit']);
$router->get('/api/sessions/{id}/graph', [Controllers\GraphController::class, 'show']);

// Context Document — specific sub-routes BEFORE generic {id} routes
$router->get('/api/sessions/{id}/context-document', [Controllers\ContextDocumentController::class, 'show']);
$router->post('/api/sessions/{id}/context-document/manual', [Controllers\ContextDocumentController::class, 'saveManual']);
$router->post('/api/sessions/{id}/context-document/upload', [Controllers\ContextDocumentController::class, 'upload']);
$router->delete('/api/sessions/{id}/context-document', [Controllers\ContextDocumentController::class, 'destroy']);

// Export & Snapshots
$router->get('/api/sessions/{id}/export', [Controllers\ExportController::class, 'export']);
$router->post('/api/sessions/{id}/snapshot', [Controllers\ExportController::class, 'snapshot']);

// Chat
$router->post('/api/chat/send', [Controllers\ChatController::class, 'send']);

// Decision Room
$router->post('/api/decision-room/run', [Controllers\DecisionRoomController::class, 'run']);

// Confrontation
$router->post('/api/confrontation/run', [Controllers\ConfrontationController::class, 'run']);

// Quick Decision
$router->post('/api/quick-decision/run', [Controllers\QuickDecisionController::class, 'run']);

// Stress Test
$router->post('/api/stress-test/run', [Controllers\StressTestController::class, 'run']);

// Action Plans — specific sub-routes BEFORE generic {id} routes
$router->post('/api/sessions/{id}/action-plan/generate', [Controllers\ActionPlanController::class, 'generate']);
$router->get('/api/sessions/{id}/action-plan', [Controllers\ActionPlanController::class, 'show']);
$router->put('/api/sessions/{id}/action-plan', [Controllers\ActionPlanController::class, 'update']);

// Jury / Committee Mode
$router->post('/api/jury/run', [Controllers\JuryController::class, 'run']);

// Argument Heatmap
$router->get('/api/sessions/{id}/argument-heatmap', [Controllers\HeatmapController::class, 'show']);

// Debate Replay
$router->get('/api/sessions/{id}/replay', [Controllers\ReplayController::class, 'show']);

// Rerun
$router->post('/api/sessions/{id}/rerun', [Controllers\RerunController::class, 'rerun']);

// Session Comparisons
$router->get('/api/session-comparisons', [Controllers\SessionComparisonController::class, 'index']);
$router->post('/api/session-comparisons', [Controllers\SessionComparisonController::class, 'create']);
$router->get('/api/session-comparisons/{id}', [Controllers\SessionComparisonController::class, 'show']);
$router->delete('/api/session-comparisons/{id}', [Controllers\SessionComparisonController::class, 'destroy']);

// Launch Assistant
$router->post('/api/launch-assistant/recommend', [Controllers\LaunchAssistantController::class, 'recommend']);

// Scenario Packs — specific routes BEFORE parameterized routes
$router->post('/api/scenario-packs/prefill', [Controllers\ScenarioPackController::class, 'prefill']);
$router->get('/api/scenario-packs', [Controllers\ScenarioPackController::class, 'index']);
$router->post('/api/scenario-packs', [Controllers\ScenarioPackController::class, 'store']);
$router->get('/api/scenario-packs/{id}', [Controllers\ScenarioPackController::class, 'show']);
$router->put('/api/scenario-packs/{id}', [Controllers\ScenarioPackController::class, 'update']);
$router->delete('/api/scenario-packs/{id}', [Controllers\ScenarioPackController::class, 'destroy']);
$router->post('/api/scenario-packs/{id}/duplicate', [Controllers\ScenarioPackController::class, 'duplicate']);

// kept for backward compat — maps to prefill (no session creation on server)
$router->post('/api/sessions/from-scenario-pack', [Controllers\ScenarioPackController::class, 'prefill']);

// Deliberation Intelligence v2 — specific sub-routes BEFORE generic {id}
$router->get('/api/sessions/{id}/persona-scores',       [Controllers\PersonaScoreController::class,      'show']);
$router->get('/api/sessions/{id}/confidence-timeline',  [Controllers\ConfidenceTimelineController::class, 'show']);
$router->get('/api/sessions/{id}/bias-report',          [Controllers\BiasDetectionController::class,      'show']);
$router->get('/api/sessions/{id}/agent-providers',      [Controllers\SessionController::class,            'agentProviders']);
$router->post('/api/sessions/{id}/devil-advocate/run',  [Controllers\DevilAdvocateController::class,      'run']);
$router->get('/api/sessions/{id}/postmortem',           [Controllers\PostmortemController::class,         'show']);
$router->post('/api/sessions/{id}/postmortem',          [Controllers\PostmortemController::class,         'store']);

// Post-mortem stats (global — no session id)
$router->get('/api/postmortems/stats', [Controllers\PostmortemController::class, 'stats']);

// Templates — specific routes BEFORE parameterized routes
$router->post('/api/templates/make', [Controllers\TemplateMakerController::class, 'make']);
$router->get('/api/templates', [Controllers\TemplateController::class, 'index']);
$router->post('/api/templates', [Controllers\TemplateController::class, 'store']);
$router->get('/api/templates/{id}', [Controllers\TemplateController::class, 'show']);
$router->put('/api/templates/{id}', [Controllers\TemplateController::class, 'update']);
$router->delete('/api/templates/{id}', [Controllers\TemplateController::class, 'destroy']);
$router->post('/api/templates/{id}/duplicate', [Controllers\TemplateController::class, 'duplicate']);

$request = new Request();
$logger = new Infrastructure\Logging\Logger();
try {
    // Avoid logging the logs endpoint itself to prevent recursion
    $path = $request->uri();
    if (!str_starts_with($path, '/api/logs')) {
        $logger->logBackendEvent('route_called', [
            'metadata' => [
                'method' => $request->method(),
                'path' => $path,
            ],
        ], 'debug');
    }
} catch (\Throwable $e) {}
try {
    $response = $router->dispatch($request);
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => 'Response could not be encoded as JSON'], JSON_UNESCAPED_UNICODE);
    } else {
        echo $json;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

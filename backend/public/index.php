<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
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
require_once __DIR__ . '/../src/Http/RawResponse.php';
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
$router->post('/api/personas/default-llm', [Controllers\PersonaController::class, 'updateDefaultLlm']);
$router->post('/api/personas/modes', [Controllers\PersonaController::class, 'saveModes']);
$router->post('/api/personas/decision-dynamics', [Controllers\PersonaController::class, 'saveDecisionDynamics']);
$router->post('/api/personas/sandbox-test', [Controllers\PersonaSandboxController::class, 'test']);
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
$router->post('/api/providers/{id}/disable', [Controllers\ProviderController::class, 'disable']);
$router->post('/api/providers/{id}/enable', [Controllers\ProviderController::class, 'enable']);
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
$router->get('/api/sessions/{id}/decision-memory', [Controllers\DecisionMemoryController::class, 'bySession']);
$router->post('/api/sessions/{id}/decision-memory/confirm', [Controllers\DecisionMemoryController::class, 'confirm']);
$router->delete('/api/sessions/{id}', [Controllers\SessionController::class, 'delete']);
$router->post('/api/sessions/{id}/status', [Controllers\SessionController::class, 'updateStatus']);
$router->put('/api/sessions/{id}/memory', [Controllers\SessionController::class, 'memory']);
$router->put('/api/sessions/{id}/decision-threshold', [Controllers\SessionController::class, 'updateThreshold']);
$router->get('/api/sessions/{id}/run-status', [Controllers\SessionController::class, 'runStatus']);
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
$router->post('/api/chat/send',     [Controllers\ChatController::class, 'send']);
$router->post('/api/chat/reactive', [Controllers\ChatController::class, 'reactive']);

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

// Dashboard (cognitive cockpit)
$router->get('/api/dashboard/cognitive-summary', [Controllers\DashboardController::class, 'cognitiveSummary']);

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
$router->get('/api/sessions/{id}/relationships',        [Controllers\SocialDynamicsController::class,     'relationships']);
$router->get('/api/sessions/{id}/relationship-events',[Controllers\SocialDynamicsController::class,     'relationshipEvents']);
$router->get('/api/sessions/{id}/agent-providers',      [Controllers\SessionController::class,            'agentProviders']);
$router->post('/api/sessions/{id}/devil-advocate/run',  [Controllers\DevilAdvocateController::class,      'run']);
$router->get('/api/sessions/{id}/postmortem',           [Controllers\PostmortemController::class,         'show']);
$router->post('/api/sessions/{id}/postmortem',          [Controllers\PostmortemController::class,         'store']);

// Post-mortem stats (global — no session id)
$router->get('/api/postmortems/stats', [Controllers\PostmortemController::class, 'stats']);

// Agent dynamics — suggestions from post-mortem history (never auto-applied)
$router->get('/api/analysis/agent-dynamics-suggestions', [Controllers\AgentDynamicsRecommendationController::class, 'suggestions']);
$router->post('/api/analysis/agent-dynamics-suggestions/apply', [Controllers\AgentDynamicsRecommendationController::class, 'apply']);

// Evidence Layer
$router->get('/api/sessions/{id}/evidence-report',             [Controllers\EvidenceController::class, 'report']);
$router->get('/api/sessions/{id}/evidence-claims',             [Controllers\EvidenceController::class, 'claims']);
$router->post('/api/sessions/{id}/evidence/recompute',         [Controllers\EvidenceController::class, 'recompute']);

// Risk & Reversibility Layer
$router->get('/api/sessions/{id}/risk-profile',                [Controllers\RiskProfileController::class, 'show']);
$router->post('/api/sessions/{id}/risk-profile/recompute',     [Controllers\RiskProfileController::class, 'recompute']);

// Learning Layer
$router->get('/api/learning/overview',    [Controllers\LearningController::class, 'overview']);
$router->get('/api/learning/agents',      [Controllers\LearningController::class, 'agents']);
$router->get('/api/learning/modes',       [Controllers\LearningController::class, 'modes']);
$router->get('/api/learning/calibration', [Controllers\LearningController::class, 'calibration']);
$router->post('/api/learning/recompute',  [Controllers\LearningController::class, 'recompute']);
$router->get('/api/learning/export',      [Controllers\LearningController::class, 'export']);
$router->post('/api/learning/export',     [Controllers\LearningController::class, 'export']);

// Cognitive governance (catalogue invariants — lecture seule, expert)
$router->get('/api/cognitive-governance', [Controllers\CognitiveGovernanceController::class, 'index']);

// Prompt policies (admin — whitelisted files only)
$router->get('/api/prompt-policies',       [Controllers\PromptPolicyController::class, 'index']);
$router->get('/api/prompt-policies/{id}',  [Controllers\PromptPolicyController::class, 'show']);
$router->put('/api/prompt-policies/{id}',  [Controllers\PromptPolicyController::class, 'update']);

// Context quality check (used by Context Assistant banner)
$router->post('/api/context/check', [Controllers\ContextCheckController::class, 'check']);

// Templates — specific routes BEFORE parameterized routes
$router->post('/api/templates/make', [Controllers\TemplateMakerController::class, 'make']);
$router->get('/api/templates', [Controllers\TemplateController::class, 'index']);
$router->post('/api/templates', [Controllers\TemplateController::class, 'store']);
$router->get('/api/templates/{id}', [Controllers\TemplateController::class, 'show']);
$router->put('/api/templates/{id}', [Controllers\TemplateController::class, 'update']);
$router->delete('/api/templates/{id}', [Controllers\TemplateController::class, 'destroy']);
$router->post('/api/templates/{id}/duplicate', [Controllers\TemplateController::class, 'duplicate']);

// Decision Memory
$router->get('/api/decision-memories', [Controllers\DecisionMemoryController::class, 'index']);
$router->get('/api/decision-memories/search', [Controllers\DecisionMemoryController::class, 'search']);
$router->get('/api/decision-memories/similar', [Controllers\DecisionMemoryController::class, 'similar']);
$router->get('/api/decision-memories/compact', [Controllers\DecisionMemoryController::class, 'compact']);
$router->get('/api/decision-memories/{id}', [Controllers\DecisionMemoryController::class, 'show']);
$router->get('/api/decision-memories/{id}/related', [Controllers\DecisionMemoryController::class, 'related']);
$router->get('/api/decision-memories/{id}/audit', [Controllers\DecisionMemoryController::class, 'audit']);
$router->post('/api/decision-memories/{id}/link', [Controllers\DecisionMemoryController::class, 'link']);
$router->post('/api/decision-memories/{id}/lifecycle', [Controllers\DecisionMemoryController::class, 'lifecycle']);
$router->delete('/api/decision-memories/{id}', [Controllers\DecisionMemoryController::class, 'destroy']);

// Global beliefs expert APIs (strict scoped via context_id query)
$router->get('/api/beliefs', [Controllers\StrategicContextBeliefsController::class, 'indexGlobal']);
$router->get('/api/beliefs/runtime', [Controllers\StrategicContextBeliefsController::class, 'runtimeProjection']);
$router->get('/api/beliefs/{id}', [Controllers\StrategicContextBeliefsController::class, 'showGlobal']);
$router->get('/api/beliefs/{id}/timeline', [Controllers\StrategicContextBeliefsController::class, 'timelineGlobal']);
$router->get('/api/beliefs/{id}/relations', [Controllers\StrategicContextBeliefsController::class, 'relationsGlobal']);

// Strategic Contexts (lightweight organization layer)
$router->get('/api/strategic-contexts', [Controllers\StrategicContextController::class, 'index']);
$router->post('/api/strategic-contexts', [Controllers\StrategicContextController::class, 'create']);
$router->post('/api/strategic-contexts/compare', [Controllers\StrategicContextController::class, 'compare']);
$router->get('/api/strategic-contexts/{context_id}/timeline', [Controllers\StrategicContextController::class, 'timeline']);
$router->get('/api/strategic-contexts/{id}/narrative', [Controllers\StrategicContextController::class, 'narrativeShow']);
$router->get('/api/strategic-contexts/{id}/memory-governance', [Controllers\StrategicContextController::class, 'memoryGovernance']);
$router->post('/api/strategic-contexts/{id}/narrative/recompute', [Controllers\StrategicContextController::class, 'narrativeRecompute']);
$router->get('/api/strategic-contexts/{contextId}/beliefs', [Controllers\StrategicContextBeliefsController::class, 'index']);
$router->get('/api/strategic-contexts/{contextId}/agents/{agentId}/beliefs', [Controllers\StrategicContextBeliefsController::class, 'indexByAgent']);
$router->post('/api/strategic-contexts/{contextId}/beliefs', [Controllers\StrategicContextBeliefsController::class, 'store']);
$router->put('/api/strategic-contexts/{contextId}/beliefs/{beliefId}', [Controllers\StrategicContextBeliefsController::class, 'update']);
$router->post('/api/strategic-contexts/{contextId}/beliefs/{beliefId}/archive', [Controllers\StrategicContextBeliefsController::class, 'archive']);
$router->post('/api/strategic-contexts/{contextId}/beliefs/{beliefId}/deprecate', [Controllers\StrategicContextBeliefsController::class, 'deprecate']);
$router->get('/api/strategic-contexts/{id}/memory-compilations', [Controllers\StrategicContextMemoryCompilationsController::class, 'index']);
$router->post('/api/strategic-contexts/{id}/memory-compilations/compile', [Controllers\StrategicContextMemoryCompilationsController::class, 'compile']);
$router->get('/api/strategic-contexts/{id}/memory-compilations/{compilationId}', [Controllers\StrategicContextMemoryCompilationsController::class, 'show']);
$router->post('/api/strategic-contexts/{id}/memory-compilations/{compilationId}/archive', [Controllers\StrategicContextMemoryCompilationsController::class, 'archive']);
$router->post('/api/strategic-contexts/{id}/memory-compilations/{compilationId}/supersede', [Controllers\StrategicContextMemoryCompilationsController::class, 'supersede']);
$router->get('/api/strategic-contexts/{id}/snapshots/longitudinal', [Controllers\StrategicContextSnapshotsController::class, 'longitudinal']);
$router->post('/api/strategic-contexts/{id}/snapshots/compare', [Controllers\StrategicContextSnapshotsController::class, 'compare']);
$router->get('/api/strategic-contexts/{id}/snapshots', [Controllers\StrategicContextSnapshotsController::class, 'index']);
$router->post('/api/strategic-contexts/{id}/snapshots', [Controllers\StrategicContextSnapshotsController::class, 'store']);
$router->get('/api/strategic-contexts/{id}/snapshots/{snapshotId}', [Controllers\StrategicContextSnapshotsController::class, 'show']);
$router->get('/api/strategic-contexts/{id}/relationships', [Controllers\SocialDynamicsController::class, 'relationshipsByContext']);
$router->get('/api/strategic-contexts/{id}/relationship-events', [Controllers\SocialDynamicsController::class, 'relationshipEventsByContext']);
$router->get('/api/strategic-contexts/{context_id}/rooms', [Controllers\DecisionRoomsController::class, 'indexByContext']);
$router->post('/api/strategic-contexts/{context_id}/rooms', [Controllers\DecisionRoomsController::class, 'createInContext']);
$router->get('/api/strategic-contexts/{context_id}/memory.md', [Controllers\MemoryMarkdownController::class, 'context']);
$router->get('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory', [Controllers\AgentContextMemoryController::class, 'show']);
$router->put('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory', [Controllers\AgentContextMemoryController::class, 'update']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/append', [Controllers\AgentContextMemoryController::class, 'append']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/recent-note', [Controllers\AgentContextMemoryController::class, 'recentNote']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/contradiction', [Controllers\AgentContextMemoryController::class, 'contradiction']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/deprecate', [Controllers\AgentContextMemoryController::class, 'deprecate']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/compact', [Controllers\AgentContextMemoryController::class, 'compact']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/memory/consolidate', [Controllers\AgentContextMemoryController::class, 'consolidate']);
$router->post('/api/strategic-contexts/{context_id}/agents/{agent_id}/chat', [Controllers\AgentContextChatController::class, 'chat']);
$router->get('/api/strategic-contexts/active', [Controllers\StrategicContextController::class, 'active']);
$router->post('/api/strategic-contexts/{id}/activate', [Controllers\StrategicContextController::class, 'activate']);
$router->get('/api/strategic-contexts/{id}', [Controllers\StrategicContextController::class, 'show']);
$router->put('/api/strategic-contexts/{id}', [Controllers\StrategicContextController::class, 'update']);
$router->delete('/api/strategic-contexts/{id}', [Controllers\StrategicContextController::class, 'destroy']);
$router->post('/api/strategic-contexts/{id}/link-memory', [Controllers\StrategicContextController::class, 'linkMemory']);
$router->post('/api/strategic-contexts/{id}/unlink-memory', [Controllers\StrategicContextController::class, 'unlinkMemory']);
$router->post('/api/strategic-contexts/{id}/link-session', [Controllers\StrategicContextController::class, 'linkSession']);
$router->post('/api/strategic-contexts/{id}/unlink-session', [Controllers\StrategicContextController::class, 'unlinkSession']);

// Palace-lite: decision rooms (chains inside a strategic context; join-table links only)
$router->post('/api/decision-rooms/{room_id}/archive', [Controllers\DecisionRoomsController::class, 'archive']);
$router->delete('/api/decision-rooms/{room_id}/memories/{memory_id}', [Controllers\DecisionRoomsController::class, 'unlinkMemory']);
$router->post('/api/decision-rooms/{room_id}/memories', [Controllers\DecisionRoomsController::class, 'linkMemory']);
$router->delete('/api/decision-rooms/{room_id}/sessions/{session_id}', [Controllers\DecisionRoomsController::class, 'unlinkSession']);
$router->post('/api/decision-rooms/{room_id}/sessions', [Controllers\DecisionRoomsController::class, 'linkSession']);
$router->get('/api/decision-rooms/{room_id}', [Controllers\DecisionRoomsController::class, 'show']);
$router->put('/api/decision-rooms/{room_id}', [Controllers\DecisionRoomsController::class, 'update']);
$router->get('/api/decision-rooms/{room_id}/memory.md', [Controllers\MemoryMarkdownController::class, 'room']);
$router->delete('/api/decision-rooms/{room_id}', [Controllers\DecisionRoomsController::class, 'destroy']);

if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    return;
}

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
    if ($response instanceof \Http\RawResponse) {
        http_response_code($response->statusCode);
        header('Content-Type: ' . $response->contentType);
        echo $response->body;
        exit;
    }
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

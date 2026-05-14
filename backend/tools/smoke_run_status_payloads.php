<?php
declare(strict_types=1);
/**
 * Génère des sessions de test et affiche les payloads GET /run-status pour smoke API (scénarios B/C).
 * Ne supprime pas les sessions (préfixe smoke-rs-).
 */
require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Controllers\SessionController;
use Http\Request;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Infrastructure\Persistence\SessionRepository;

$sessionRepo = new SessionRepository();
$runRepo = new RunStatusRepository();
$controller = new SessionController();
$now = date('c');
$tsAgo = static fn(int $s): string => date('c', time() - $s);

$baseRow = static function (string $id, string $title, string $mode, string $status): array {
    return [
        'id' => $id,
        'title' => $title,
        'mode' => $mode,
        'initial_prompt' => 'Smoke test',
        'selected_agents' => json_encode(['pm', 'synthesizer']),
        'rounds' => 3,
        'language' => 'fr',
        'status' => $status,
        'cf_rounds' => 3,
        'cf_interaction_style' => 'sequential',
        'cf_reply_policy' => 'all-agents-reply',
        'is_favorite' => 0,
        'is_reference' => 0,
        'force_disagreement' => 0,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
};

// --- Scénario B : session completed + run_status running ---
$bId = 'smoke-rs-b-' . bin2hex(random_bytes(4));
$sessionRepo->create($baseRow($bId, 'Smoke desync B', 'jury', 'completed'));
$sessionRepo->update($bId, [
    'result' => json_encode(['raw_decision' => ['decision' => 'go']], JSON_UNESCAPED_UNICODE),
]);
$runRepo->save($bId, [
    'session_id' => $bId,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $now,
    'updated_at' => $now,
    'progress' => [
        'percent' => 96,
        'current_round' => 3,
        'total_rounds' => 3,
        'current_phase' => 'jury-verdict',
        'current_phase_label' => 'Jury verdict',
        'current_agent_id' => 'synthesizer',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $now, 'phase' => 'jury-verdict', 'label' => 'Synthèse jury'],
    ],
]);

$reqB = new Request();
$reqB->setParams(['id' => $bId]);
$payloadB = $controller->runStatus($reqB);

// --- Scénario C : staleness quiet (75s) + jury ---
$cQuiet = 'smoke-rs-cq-' . bin2hex(random_bytes(4));
$sessionRepo->create($baseRow($cQuiet, 'Smoke staleness quiet', 'jury', 'running'));
$runRepo->save($cQuiet, [
    'session_id' => $cQuiet,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(400),
    'updated_at' => $tsAgo(75),
    'progress' => [
        'percent' => 50,
        'current_round' => 1,
        'total_rounds' => 3,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(75), 'phase' => 'round_started', 'label' => 'Vieux event'],
    ],
]);
$reqCQ = new Request();
$reqCQ->setParams(['id' => $cQuiet]);
$payloadCQ = $controller->runStatus($reqCQ);

// --- Scénario C : llm_call_started sans completed ---
$cLlm = 'smoke-rs-cllm-' . bin2hex(random_bytes(4));
$sessionRepo->create($baseRow($cLlm, 'Smoke LLM open', 'jury', 'running'));
$runRepo->save($cLlm, [
    'session_id' => $cLlm,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(120),
    'updated_at' => $tsAgo(5),
    'progress' => [
        'percent' => 88,
        'current_round' => 2,
        'total_rounds' => 3,
        'current_phase' => 'jury-deliberation',
        'current_agent_id' => 'pm',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        [
            'ts' => $tsAgo(5),
            'phase' => 'llm_call_started',
            'label' => 'Appel LLM',
            'agent_id' => 'pm',
            'orchestration_phase' => 'jury-deliberation',
            'provider_id' => 'demo-provider',
            'model' => 'demo-model',
        ],
    ],
]);
$reqCL = new Request();
$reqCL->setParams(['id' => $cLlm]);
$payloadCL = $controller->runStatus($reqCL);

$strip = static function (array $p): array {
    return [
        'session_id' => $p['session_id'] ?? null,
        'status' => $p['status'] ?? null,
        'run_coherence_flags' => $p['run_coherence_flags'] ?? [],
        'staleness' => $p['staleness'] ?? null,
        'current_llm_call' => $p['current_llm_call'] ?? null,
        'run_timeout_diagnostics' => $p['run_timeout_diagnostics'] ?? null,
        'run_finalization' => $p['run_finalization'] ?? null,
        'progress' => $p['progress'] ?? null,
    ];
};

echo "--- Scenario B (desync) session_id: {$bId}\n";
echo json_encode($strip($payloadB), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "--- Scenario C quiet session_id: {$cQuiet}\n";
echo json_encode($strip($payloadCQ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "--- Scenario C llm_call_started session_id: {$cLlm}\n";
echo json_encode($strip($payloadCL), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "HTTP check:\n";
$base = 'http://localhost/decision-room-ai/backend/public';
echo "GET {$base}/api/sessions/{$bId}/run-status\n";
echo "GET {$base}/api/sessions/{$cQuiet}/run-status\n";
echo "GET {$base}/api/sessions/{$cLlm}/run-status\n";

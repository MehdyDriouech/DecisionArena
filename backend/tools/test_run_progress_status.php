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

use Controllers\SessionController;
use Domain\Orchestration\RunTimeoutPolicy;
use Http\Request;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Infrastructure\Persistence\SessionRepository;

function rp_ok(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__rp_fail'] = ($GLOBALS['__rp_fail'] ?? 0) + 1;
    }
}

$GLOBALS['__rp_fail'] = 0;
$sessionRepo = new SessionRepository();
$messageRepo = new MessageRepository();
$runRepo = new RunStatusRepository();
$controller = new SessionController();

$sessionId = 'rp-' . bin2hex(random_bytes(5));
$now = date('c');
$sessionRepo->create([
    'id' => $sessionId,
    'title' => 'Run progress test',
    'mode' => 'confrontation',
    'initial_prompt' => 'Test objective',
    'selected_agents' => json_encode(['pm', 'critic']),
    'rounds' => 3,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 3,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);

$missingReq = new Request();
$missingReq->setParams(['id' => 'missing-session']);
$missing = $controller->runStatus($missingReq);
rp_ok(($missing['error'] ?? false) === true, 'run-status session inexistante => erreur');

$runRepo->initialize($sessionId, 'confrontation', 3);
$runRepo->appendEvent($sessionId, [
    'ts' => date('c', strtotime($now . ' +2 seconds')),
    'phase' => 'round_started',
    'round' => 1,
    'label' => 'Tour 1 démarré',
], [
    'current_round' => 1,
    'total_rounds' => 3,
    'current_phase' => 'round_started',
    'percent' => 12,
], 'running');
$runRepo->appendEvent($sessionId, [
    'ts' => date('c', strtotime($now . ' +4 seconds')),
    'phase' => 'blue_opening',
    'round' => 1,
    'team' => 'blue',
    'agent_id' => 'pm',
    'label' => 'Blue opening · pm · appel LLM démarré',
], [
    'current_round' => 1,
    'total_rounds' => 3,
    'current_phase' => 'blue_opening',
    'current_team' => 'blue',
    'current_agent_id' => 'pm',
    'percent' => 24,
], 'running');

$messageRepo->create([
    'id' => 'm-' . bin2hex(random_bytes(4)),
    'session_id' => $sessionId,
    'role' => 'assistant',
    'agent_id' => 'pm',
    'round' => 1,
    'phase' => 'round-1',
    'mode_context' => 'confrontation',
    'message_type' => 'initial-position',
    'content' => 'SECRET_PROMPT_DO_NOT_EXPOSE',
    'created_at' => date('c', strtotime($now . ' +5 seconds')),
]);

$runningReq = new Request();
$runningReq->setParams(['id' => $sessionId]);
$running = $controller->runStatus($runningReq);

rp_ok(($running['status'] ?? '') === 'running', 'run-status running => status running');
rp_ok(isset($running['progress']) && is_array($running['progress']), 'run-status running => progress JSON présent');
rp_ok((int)($running['progress']['current_round'] ?? 0) === 1, 'run-status running => round courant');
rp_ok((int)($running['progress']['total_rounds'] ?? 0) === 3, 'run-status running => total rounds');
rp_ok(is_array($running['events'] ?? null) && count($running['events']) >= 2, 'run-status running => events présents');

$eventTs = array_map(static fn(array $e): string => (string)($e['ts'] ?? ''), $running['events'] ?? []);
$sortedTs = $eventTs;
sort($sortedTs);
rp_ok($eventTs === $sortedTs, 'events ordonnés par timestamp');

$eventsJson = json_encode($running['events'] ?? [], JSON_UNESCAPED_UNICODE);
rp_ok(strpos((string)$eventsJson, 'SECRET_PROMPT_DO_NOT_EXPOSE') === false, 'events ne contiennent pas le contenu prompt/réponse');

$runRepo->appendEvent($sessionId, [
    'phase' => 'session_completed',
    'round' => 3,
    'label' => 'Session terminée',
], [
    'current_round' => 3,
    'total_rounds' => 3,
    'current_phase' => 'session_completed',
    'percent' => 100,
], 'completed');
$sessionRepo->update($sessionId, ['status' => 'completed']);

$completedReq = new Request();
$completedReq->setParams(['id' => $sessionId]);
$completed = $controller->runStatus($completedReq);
rp_ok(($completed['status'] ?? '') === 'completed', 'run-status completed => status completed');
rp_ok((int)($completed['progress']['percent'] ?? 0) === 100, 'run-status completed => 100%');

// Draft sans run_status : statut idle (plus "blocked" 99 %) et progression nulle
$draftId = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $draftId,
    'title' => 'Draft no run_status',
    'mode' => 'confrontation',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 5,
    'language' => 'fr',
    'status' => 'draft',
    'cf_rounds' => 5,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$draftReq = new Request();
$draftReq->setParams(['id' => $draftId]);
$draftPayload = $controller->runStatus($draftReq);
rp_ok(($draftPayload['status'] ?? '') === 'idle', 'draft sans run_status => status idle');
rp_ok((int)($draftPayload['progress']['percent'] ?? 999) <= 5, 'draft sans run_status => faible pourcentage');

// run_status "running" mais round 0 + phase idle + sans événements + percent obsolète élevé => reclamp
$staleId = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $staleId,
    'title' => 'Stale percent',
    'mode' => 'confrontation',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 5,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 5,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($staleId, [
    'session_id' => $staleId,
    'mode' => 'confrontation',
    'status' => 'running',
    'started_at' => $now,
    'updated_at' => $now,
    'progress' => [
        'percent' => 99,
        'current_round' => 0,
        'total_rounds' => 5,
        'current_phase' => 'idle',
        'current_phase_label' => 'idle',
        'current_step' => 'done',
        'estimated' => true,
    ],
    'events' => [],
]);
$staleReq = new Request();
$staleReq->setParams(['id' => $staleId]);
$stalePayload = $controller->runStatus($staleReq);
rp_ok((int)($stalePayload['progress']['percent'] ?? 999) <= 15, 'percent obsolète reclampé (round 0 idle sans events)');

// Session lifecycle "completed" mais run_status JSON encore "running" => completed exposé + flag
$desyncId = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $desyncId,
    'title' => 'Desync session vs run_status',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm', 'synthesizer']),
    'rounds' => 3,
    'language' => 'fr',
    'status' => 'completed',
    'cf_rounds' => 3,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$sessionRepo->update($desyncId, [
    'result' => json_encode(['raw_decision' => ['decision' => 'go']], JSON_UNESCAPED_UNICODE),
]);
$runRepo->save($desyncId, [
    'session_id' => $desyncId,
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
        ['ts' => date('c'), 'phase' => 'jury-verdict', 'label' => 'Synthese jury demarree'],
    ],
]);
$desyncReq = new Request();
$desyncReq->setParams(['id' => $desyncId]);
$desyncPayload = $controller->runStatus($desyncReq);
rp_ok(($desyncPayload['status'] ?? '') === 'completed', 'session completed + run_status running => run-status completed');
rp_ok((int)($desyncPayload['progress']['percent'] ?? 0) === 100, 'coercion completed => 100%');
rp_ok(in_array('session_completed_run_status_pending', $desyncPayload['run_coherence_flags'] ?? [], true), 'flag session_completed_run_status_pending');

// Session "running" mais run_status deja "completed" => diagnostic inverse
$tripId = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $tripId,
    'title' => 'Trip wire',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($tripId, [
    'session_id' => $tripId,
    'mode' => 'jury',
    'status' => 'completed',
    'started_at' => $now,
    'updated_at' => $now,
    'progress' => [
        'percent' => 100,
        'current_round' => 2,
        'total_rounds' => 2,
        'current_phase' => 'session_completed',
        'current_step' => 'done',
        'estimated' => true,
    ],
    'events' => [],
]);
$tripReq = new Request();
$tripReq->setParams(['id' => $tripId]);
$tripPayload = $controller->runStatus($tripReq);
rp_ok(in_array('run_status_completed_session_running', $tripPayload['run_coherence_flags'] ?? [], true), 'flag run_status_completed_session_running');

rp_ok(RunTimeoutPolicy::hardRunWallSecondsForMode('jury') === 1800, 'mur timeout mode jury = 1800s');
rp_ok(RunTimeoutPolicy::hardRunWallSecondsForMode('quick-decision') === 600, 'mur timeout quick-decision = 600s');

$tsAgo = static fn(int $s): string => date('c', time() - $s);

// Staleness: dernier event recent -> normal
$st1 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st1,
    'title' => 'Staleness normal',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st1, [
    'session_id' => $st1,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(120),
    'updated_at' => $tsAgo(30),
    'progress' => [
        'percent' => 40,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(30), 'phase' => 'round_started', 'label' => 'ok'],
    ],
]);
$reqSt1 = new Request();
$reqSt1->setParams(['id' => $st1]);
$rs1 = $controller->runStatus($reqSt1);
rp_ok(($rs1['staleness']['level'] ?? '') === 'normal', 'staleness normal (<60s sans event)');

// quiet ~75s
$st2 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st2,
    'title' => 'Staleness quiet',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st2, [
    'session_id' => $st2,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(400),
    'updated_at' => $tsAgo(75),
    'progress' => [
        'percent' => 50,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(75), 'phase' => 'round_started', 'label' => 'vieux'],
    ],
]);
$reqSt2 = new Request();
$reqSt2->setParams(['id' => $st2]);
$rs2 = $controller->runStatus($reqSt2);
rp_ok(($rs2['staleness']['level'] ?? '') === 'quiet', 'staleness quiet (75s)');

// long ~200s
$st3 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st3,
    'title' => 'Staleness long',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st3, [
    'session_id' => $st3,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(500),
    'updated_at' => $tsAgo(200),
    'progress' => [
        'percent' => 55,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(200), 'phase' => 'round_started', 'label' => 'vieux'],
    ],
]);
$reqSt3 = new Request();
$reqSt3->setParams(['id' => $st3]);
$rs3 = $controller->runStatus($reqSt3);
rp_ok(($rs3['staleness']['level'] ?? '') === 'long', 'staleness long (200s)');

// possibly_stuck ~400s
$st4 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st4,
    'title' => 'Staleness stuck',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st4, [
    'session_id' => $st4,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(500),
    'updated_at' => $tsAgo(400),
    'progress' => [
        'percent' => 60,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(400), 'phase' => 'round_started', 'label' => 'tres vieux'],
    ],
]);
$reqSt4 = new Request();
$reqSt4->setParams(['id' => $st4]);
$rs4 = $controller->runStatus($reqSt4);
rp_ok(($rs4['staleness']['level'] ?? '') === 'possibly_stuck', 'staleness possibly_stuck (400s)');

// Mur orchestration quick 600s -> blocked + staleness timeout
$st5 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st5,
    'title' => 'Wall timeout',
    'mode' => 'quick-decision',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st5, [
    'session_id' => $st5,
    'mode' => 'quick-decision',
    'status' => 'running',
    'started_at' => $tsAgo(700),
    'updated_at' => $tsAgo(700),
    'progress' => [
        'percent' => 80,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'analysis',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        ['ts' => $tsAgo(700), 'phase' => 'session_started', 'label' => 'start'],
    ],
]);
$reqSt5 = new Request();
$reqSt5->setParams(['id' => $st5]);
$rs5 = $controller->runStatus($reqSt5);
rp_ok(strtolower((string)($rs5['status'] ?? '')) === 'blocked', 'mur 600s quick-decision => run_status blocked');
rp_ok(($rs5['staleness']['level'] ?? '') === 'timeout', 'staleness timeout apres mur orchestration');

// llm_call_started sans completed -> active
$st6 = 'rp-' . bin2hex(random_bytes(5));
$sessionRepo->create([
    'id' => $st6,
    'title' => 'LLM open',
    'mode' => 'jury',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 2,
    'language' => 'fr',
    'status' => 'running',
    'cf_rounds' => 2,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$runRepo->save($st6, [
    'session_id' => $st6,
    'mode' => 'jury',
    'status' => 'running',
    'started_at' => $tsAgo(10),
    'updated_at' => $tsAgo(5),
    'progress' => [
        'percent' => 50,
        'current_round' => 1,
        'total_rounds' => 2,
        'current_phase' => 'jury-opening',
        'current_step' => 'llm_call',
        'estimated' => true,
    ],
    'events' => [
        [
            'ts' => $tsAgo(5),
            'phase' => 'llm_call_started',
            'label' => 'Appel',
            'agent_id' => 'pm',
            'orchestration_phase' => 'jury-opening',
            'provider_id' => 'p1',
            'model' => 'm1',
        ],
    ],
]);
$reqSt6 = new Request();
$reqSt6->setParams(['id' => $st6]);
$rs6 = $controller->runStatus($reqSt6);
rp_ok(!empty($rs6['current_llm_call']['active']), 'llm_call_started sans fin => current_llm_call.active');

$runRepo->appendEvent($st6, [
    'ts' => $tsAgo(2),
    'phase' => 'llm_call_completed',
    'label' => 'fini',
    'duration_ms' => 1200,
], [], 'running', null);
$reqSt6b = new Request();
$reqSt6b->setParams(['id' => $st6]);
$rs7 = $controller->runStatus($reqSt6b);
rp_ok(empty($rs7['current_llm_call']['active']), 'llm_call_completed => current_llm_call inactif');

if (($GLOBALS['__rp_fail'] ?? 0) > 0) {
    echo 'Run progress checks failed: ' . (int)$GLOBALS['__rp_fail'] . PHP_EOL;
    exit(1);
}

echo 'Run progress checks passed.' . PHP_EOL;
exit(0);

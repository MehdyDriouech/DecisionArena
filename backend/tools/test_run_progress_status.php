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

if (($GLOBALS['__rp_fail'] ?? 0) > 0) {
    echo 'Run progress checks failed: ' . (int)$GLOBALS['__rp_fail'] . PHP_EOL;
    exit(1);
}

echo 'Run progress checks passed.' . PHP_EOL;
exit(0);

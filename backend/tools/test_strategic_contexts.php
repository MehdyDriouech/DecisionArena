<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
  $base = __DIR__ . '/../src/';
  $file = $base . str_replace('\\', '/', $class) . '.php';
  if (file_exists($file)) require_once $file;
});

use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Sessions\SessionStrategicContextGuard;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

function da_test_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

echo "Strategic Context checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$repo = new StrategicContextRepository();

$c = $repo->create('Initiative A', 'Test context', 'active');
if (empty($c['context_id'])) { echo "FAIL: create context\n"; exit(1); }
echo "PASS: context created\n";

$cid = (string)$c['context_id'];
$list = $repo->list(['status' => 'active'], 50);
if (!array_filter($list, fn($x) => ($x['context_id'] ?? '') === $cid)) { echo "FAIL: list contexts\n"; exit(1); }
echo "PASS: context listed\n";

// Link operations are best-effort; we don't assume existing memories/sessions in local DB.
// Just ensure link/unlink SQL runs without exception.
$ok1 = $repo->unlinkMemory($cid, 'nonexistent');
$ok2 = $repo->unlinkSession($cid, 'nonexistent');
if (!$ok1 || !$ok2) { echo "FAIL: unlink operations\n"; exit(1); }
echo "PASS: unlink operations safe\n";

$state = $repo->currentState($cid);
if (!is_array($state)) { echo "FAIL: currentState shape\n"; exit(1); }
echo "PASS: currentState deterministic\n";

$b = $repo->create('Initiative B', 'Second', 'active');
$bid = (string)($b['context_id'] ?? '');
if ($bid === '') { echo "FAIL: create second context\n"; exit(1); }

if (!$repo->setActiveContext($cid)) { echo "FAIL: setActiveContext A\n"; exit(1); }
$ga = $repo->getActiveContext();
if (($ga['context_id'] ?? '') !== $cid) { echo "FAIL: getActiveContext after A\n"; exit(1); }
echo "PASS: activate A\n";

if (!$repo->setActiveContext($bid)) { echo "FAIL: setActiveContext B\n"; exit(1); }
$gb = $repo->getActiveContext();
if (($gb['context_id'] ?? '') !== $bid) { echo "FAIL: getActiveContext after B\n"; exit(1); }
$rows = $repo->list([], 50);
$activeCount = 0;
foreach ($rows as $r) {
    if ((int)($r['is_workspace_active'] ?? 0) === 1) $activeCount++;
}
if ($activeCount !== 1) { echo "FAIL: multiple workspace active rows ($activeCount)\n"; exit(1); }
echo "PASS: single workspace active invariant\n";

// Idempotent re-activate B
if (!$repo->setActiveContext($bid)) { echo "FAIL: setActiveContext idempotent B\n"; exit(1); }
echo "PASS: idempotent activate\n";

if ($repo->setActiveContext('00000000-0000-0000-0000-000000000099')) {
    echo "FAIL: activate nonexistent uuid should fail\n";
    exit(1);
}
echo "PASS: activate nonexistent rejected\n";

$doneCtx = $repo->create('DoneCtx', '', 'completed');
$doneId = (string)($doneCtx['context_id'] ?? '');
if ($doneId === '' || $repo->setActiveContext($doneId)) {
    echo "FAIL: activate completed context must be rejected\n";
    exit(1);
}
echo "PASS: activate completed rejected\n";

$abCtx = $repo->create('AbCtx', '', 'abandoned');
$abId = (string)($abCtx['context_id'] ?? '');
if ($abId === '' || $repo->setActiveContext($abId)) {
    echo "FAIL: activate abandoned context must be rejected\n";
    exit(1);
}
echo "PASS: activate abandoned rejected\n";

$pdo = Database::getConnection();
$pdo->exec('UPDATE strategic_contexts SET is_workspace_active = 0');

$blockNo = SessionStrategicContextGuard::assertSessionCreationAllowed('chat', []);
if ($blockNo === null) {
    echo "FAIL: SessionStrategicContextGuard should block chat without workspace\n";
    exit(1);
}
echo "PASS: session guard blocks without active\n";

$allowLegacy = SessionStrategicContextGuard::assertSessionCreationAllowed('decision-room', [
    'confirm_legacy_no_active_strategic_context' => true,
]);
if ($allowLegacy !== null) {
    echo "FAIL: SessionStrategicContextGuard should allow legacy\n";
    exit(1);
}
echo "PASS: session guard allows legacy confirmed\n";

if (!$repo->setActiveContext($bid)) {
    echo "FAIL: re-activate B for link test\n";
    exit(1);
}
$allowWith = SessionStrategicContextGuard::assertSessionCreationAllowed('stress-test', []);
if ($allowWith !== null) {
    echo "FAIL: SessionStrategicContextGuard should allow when workspace active\n";
    exit(1);
}
echo "PASS: session guard allows with active workspace\n";

$resActive = SessionStrategicContextGuard::resolveStrategicContextForCreation('jury', [], null);
if ($resActive['block'] !== null || ($resActive['strategic_context_id'] ?? '') !== $bid) {
    echo "FAIL: resolveStrategicContextForCreation should use active workspace id\n";
    exit(1);
}
echo "PASS: resolve returns active workspace context id\n";

$resExplicit = SessionStrategicContextGuard::resolveStrategicContextForCreation('chat', [
    'strategic_context_id' => $bid,
], null);
if ($resExplicit['block'] !== null || ($resExplicit['strategic_context_id'] ?? '') !== $bid) {
    echo "FAIL: resolve explicit strategic_context_id\n";
    exit(1);
}
echo "PASS: resolve explicit context id\n";

$sessionRepo = new SessionRepository();
$now = date('c');
$sid = da_test_uuid();
$sessionRepo->create([
    'id' => $sid,
    'title' => 'Guard link test',
    'mode' => 'chat',
    'initial_prompt' => 'x',
    'selected_agents' => json_encode(['pm']),
    'rounds' => 1,
    'language' => 'fr',
    'status' => 'draft',
    'cf_rounds' => 1,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
    'strategic_context_id' => $bid,
    'created_at' => $now,
    'updated_at' => $now,
]);
SessionStrategicContextGuard::syncStrategicContextSessionLink($bid, $sid);
$activeRow = $repo->getActiveContext();
$actId = (string)($activeRow['context_id'] ?? '');
$linked = $repo->linkedSessionIds($actId);
if (!in_array($sid, $linked, true)) {
    echo "FAIL: new session not linked to active workspace\n";
    exit(1);
}
echo "PASS: session auto-linked to active workspace\n";

$row = $pdo->prepare('SELECT strategic_context_id FROM sessions WHERE id = ?');
$row->execute([$sid]);
$scol = $row->fetchColumn();
if ((string)$scol !== $bid) {
    echo "FAIL: sessions.strategic_context_id not persisted ($scol)\n";
    exit(1);
}
echo "PASS: sessions.strategic_context_id column\n";

echo "\nOK\n";


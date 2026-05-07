<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Orchestration\RuntimeContracts;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\DecisionRoomRepository;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

function uuid(): string
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

echo "Palace-lite decision rooms checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();

$pdo = Database::getConnection();
$strategic = new StrategicContextRepository();
$rooms = new DecisionRoomRepository();
$memRepo = new DecisionMemoryRepository();
$sessions = new SessionRepository();

$ctx = $strategic->create('Palace test wing', '', 'active');
$cid = (string)($ctx['context_id'] ?? '');
if ($cid === '') {
    echo "FAIL: create strategic context\n";
    exit(1);
}
echo "PASS: strategic context created\n";

$room = $rooms->create($cid, 'Chain A', 'test room', null, 'active');
if (!$room || ($room['context_id'] ?? '') !== $cid) {
    echo "FAIL: create room\n";
    exit(1);
}
echo "PASS: decision room created\n";
$rid = (string)$room['room_id'];

$sessionId = uuid();
$now = date('c');
$sessions->create([
    'id' => $sessionId,
    'title' => 'palace-lite fixture',
    'selected_agents' => [],
    'created_at' => $now,
    'updated_at' => $now,
]);
echo "PASS: session created for fixture\n";

$memoryId = uuid();
$ps = json_encode(['safe_to_persist' => true, 'requires_user_confirmation' => false], JSON_UNESCAPED_UNICODE);
$stmt = $pdo->prepare('
    INSERT INTO decision_memories (
        memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
        validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps,
        historical_outcome, contract_version, taxonomy_version, persistence_safety,
        user_confirmed, created_at, memory_state
    ) VALUES (
        :mid, :sid, :pb, :st, :cf, :sum,
        :vh, :fa, :ur, :ns,
        :ho, :cv, :tv, :ps,
        1, :ca, \'active\'
    )
');
$stmt->execute([
    ':mid' => $memoryId,
    ':sid' => $sessionId,
    ':pb' => 'test.pb',
    ':st' => 'decided',
    ':cf' => 'high',
    ':sum' => 'Fixture summary',
    ':vh' => '[]',
    ':fa' => '[]',
    ':ur' => json_encode(['Legal exposure'], JSON_UNESCAPED_UNICODE),
    ':ns' => json_encode(['Ship patch'], JSON_UNESCAPED_UNICODE),
    ':ho' => 'decided',
    ':cv' => RuntimeContracts::DECISION_OUTCOME_CONTRACT_VERSION,
    ':tv' => RuntimeContracts::TAXONOMY_VERSION,
    ':ps' => $ps ?: '{}',
    ':ca' => $now,
]);
if (!$memRepo->findById($memoryId)) {
    echo "FAIL: insert fixture memory\n";
    exit(1);
}
echo "PASS: fixture memory inserted (confirmed)\n";

if (!$rooms->linkMemory($rid, $memoryId)) {
    echo "FAIL: link memory\n";
    exit(1);
}
echo "PASS: memory linked to room\n";

if (!$rooms->linkSession($rid, $sessionId)) {
    echo "FAIL: link session\n";
    exit(1);
}
echo "PASS: session linked to room\n";

$state = $rooms->currentState($rid);
if (($state['latest_memory_id'] ?? '') !== $memoryId) {
    echo "FAIL: current_state latest_memory_id\n";
    exit(1);
}
if (($state['current_decision_status'] ?? '') !== 'decided' || ($state['latest_next_step'] ?? '') !== 'Ship patch') {
    echo "FAIL: current_state derivation\n";
    exit(1);
}
echo "PASS: deterministic current_state from latest confirmed linked memory\n";

$archived = $rooms->archive($rid);
if (($archived['status'] ?? '') !== 'archived') {
    echo "FAIL: archive room\n";
    exit(1);
}
echo "PASS: room archived\n";

if (!$rooms->delete($rid)) {
    echo "FAIL: delete room\n";
    exit(1);
}
echo "PASS: room deleted\n";

if ($rooms->find($rid) !== null) {
    echo "FAIL: room row should be gone\n";
    exit(1);
}

$mAfter = $memRepo->findById($memoryId);
if (!$mAfter || ($mAfter['decision_summary'] ?? '') !== 'Fixture summary') {
    echo "FAIL: memory must stay intact after room delete\n";
    exit(1);
}
echo "PASS: memory untouched after room delete\n";

// Cleanup fixture memory + session + context (room already removed)
$pdo->prepare('DELETE FROM decision_memories WHERE memory_id = ?')->execute([$memoryId]);
$pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);
$strategic->delete($cid);

echo "\nOK\n";

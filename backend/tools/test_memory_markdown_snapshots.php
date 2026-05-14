<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
  $base = __DIR__ . '/../src/';
  $file = $base . str_replace('\\', '/', $class) . '.php';
  if (file_exists($file)) require_once $file;
});

use Domain\Memory\MemorySnapshotGenerator;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Infrastructure\Persistence\DecisionRoomRepository;

function uuid(): string {
  return sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
  );
}

function insertDecisionMemory(PDO $pdo, array $row): void {
  $stmt = $pdo->prepare('
    INSERT OR REPLACE INTO decision_memories (
      memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
      validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps,
      historical_outcome, contract_version, taxonomy_version, persistence_safety,
      user_confirmed, created_at, memory_state, superseded_by, invalidated_reason, last_reviewed_at
    ) VALUES (
      :memory_id, :session_id, :playbook_id, :decision_status, :confidence, :decision_summary,
      :validated_hypotheses, :failed_assumptions, :unresolved_risks, :recommended_next_steps,
      :historical_outcome, :contract_version, :taxonomy_version, :persistence_safety,
      :user_confirmed, :created_at, :memory_state, :superseded_by, :invalidated_reason, :last_reviewed_at
    )
  ');
  $stmt->execute($row);
}

echo "memory.md snapshot checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$contexts = new StrategicContextRepository();
$rooms = new DecisionRoomRepository();
$sessions = new SessionRepository();
$gen = new MemorySnapshotGenerator();

$now = '2026-05-07T15:07:00+02:00';

$ctx = $contexts->create('Snapshot Context', 'desc', 'active');
$cid = (string)($ctx['context_id'] ?? '');
if ($cid === '') { echo "FAIL: create context\n"; exit(1); }

$room = $rooms->create($cid, 'Room A', '', null, 'active');
$rid = (string)($room['room_id'] ?? '');
if ($rid === '') { echo "FAIL: create room\n"; exit(1); }

// sessions
$sFresh = uuid();
$sUnassigned = uuid();
$sStale = uuid();
$sInv = uuid();
foreach ([$sFresh,$sUnassigned,$sStale,$sInv] as $sid) {
  $sessions->create([
    'id' => $sid,
    'title' => 't',
    'selected_agents' => [],
    'created_at' => $now,
    'updated_at' => $now,
  ]);
}

$mFresh = uuid();
$mUnassigned = uuid();
$mStale = uuid();
$mInv = uuid();

insertDecisionMemory($pdo, [
  ':memory_id' => $mFresh,
  ':session_id' => $sFresh,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'proceed',
  ':confidence' => 'moderate',
  ':decision_summary' => 'fresh summary',
  ':validated_hypotheses' => '["H1"]',
  ':failed_assumptions' => '[]',
  ':unresolved_risks' => '["riskA"]',
  ':recommended_next_steps' => '["do X"]',
  ':historical_outcome' => 'proceed',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => $now,
  ':memory_state' => 'active',
  ':superseded_by' => null,
  ':invalidated_reason' => null,
  ':last_reviewed_at' => null,
]);

insertDecisionMemory($pdo, [
  ':memory_id' => $mUnassigned,
  ':session_id' => $sUnassigned,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'pivot',
  ':confidence' => 'weak',
  ':decision_summary' => 'unassigned summary',
  ':validated_hypotheses' => '[]',
  ':failed_assumptions' => '["A1"]',
  ':unresolved_risks' => '[]',
  ':recommended_next_steps' => '[]',
  ':historical_outcome' => 'pivot',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => $now,
  ':memory_state' => 'active',
  ':superseded_by' => null,
  ':invalidated_reason' => null,
  ':last_reviewed_at' => null,
]);

$old = '2026-01-01T00:00:00+02:00';
insertDecisionMemory($pdo, [
  ':memory_id' => $mStale,
  ':session_id' => $sStale,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'validate_first',
  ':confidence' => 'weak',
  ':decision_summary' => 'stale summary',
  ':validated_hypotheses' => '[]',
  ':failed_assumptions' => '[]',
  ':unresolved_risks' => '["riskB"]',
  ':recommended_next_steps' => '["do Y"]',
  ':historical_outcome' => 'validate_first',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => $old,
  ':memory_state' => 'active',
  ':superseded_by' => null,
  ':invalidated_reason' => null,
  ':last_reviewed_at' => null,
]);

insertDecisionMemory($pdo, [
  ':memory_id' => $mInv,
  ':session_id' => $sInv,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'kill',
  ':confidence' => 'strong',
  ':decision_summary' => 'invalidated summary',
  ':validated_hypotheses' => '[]',
  ':failed_assumptions' => '[]',
  ':unresolved_risks' => '[]',
  ':recommended_next_steps' => '[]',
  ':historical_outcome' => 'kill',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => $now,
  ':memory_state' => 'invalidated',
  ':superseded_by' => null,
  ':invalidated_reason' => 'obsolete',
  ':last_reviewed_at' => null,
]);

// Links
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mFresh, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mUnassigned, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mStale, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mInv, $now]);
$pdo->prepare('INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)')->execute([$rid, $mFresh, $now]);

// 1) context markdown generated
$md1 = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20]);
if (!str_contains($md1, '# Snapshot Context')) { echo "FAIL: context title\n"; exit(1); }
if (!str_contains($md1, '## Decision Rooms / Chains')) { echo "FAIL: rooms section\n"; exit(1); }
echo "PASS: context markdown generated\n";

// 2) room markdown generated
$mdR = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20]);
if (!str_contains($mdR, '# Room A')) { echo "FAIL: room title\n"; exit(1); }
if (!str_contains($mdR, '## Decision Chain')) { echo "FAIL: room chain section\n"; exit(1); }
echo "PASS: room markdown generated\n";

// 3) deterministic repeated output (fixed now)
$md1b = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20]);
if ($md1 !== $md1b) { echo "FAIL: context markdown not deterministic\n"; exit(1); }
echo "PASS: deterministic output\n";

// 4) invalidated excluded
if (str_contains($md1, 'invalidated summary')) { echo "FAIL: invalidated included\n"; exit(1); }
echo "PASS: invalidated excluded\n";

// 5) stale excluded by default
if (str_contains($md1, 'stale summary')) { echo "FAIL: stale included by default\n"; exit(1); }
echo "PASS: stale excluded by default\n";

// 6) stale flagged when included
$mdSt = $gen->generateContextMarkdown($cid, ['now' => $now, 'include_stale' => true, 'max_memories' => 20]);
if (!str_contains($mdSt, 'stale summary')) { echo "FAIL: stale not included when requested\n"; exit(1); }
if (!str_contains($mdSt, '⚠ stale')) { echo "FAIL: stale not flagged\n"; exit(1); }
echo "PASS: stale included + flagged\n";

// 7) unassigned appears
if (!str_contains($md1, '## Unassigned Decision Memories')) { echo "FAIL: unassigned section missing\n"; exit(1); }
if (!str_contains($md1, 'unassigned summary')) { echo "FAIL: unassigned summary missing\n"; exit(1); }
echo "PASS: unassigned memories section\n";

// 8) no raw chat markers
if (preg_match('/\brole\b\s*:\s*(user|assistant)/i', $md1)) { echo "FAIL: looks like raw chat\n"; exit(1); }
echo "PASS: no raw chat\n";

echo "\nOK\n";


<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
  $base = __DIR__ . '/../src/';
  $file = $base . str_replace('\\', '/', $class) . '.php';
  if (file_exists($file)) require_once $file;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\DecisionMemoryRepository;

echo "Decision Memory decay checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = $db->pdo();
$repo = new DecisionMemoryRepository();

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

function insertSession(PDO $pdo, string $id): void {
  $now = date('c');
  $stmt = $pdo->prepare('INSERT OR IGNORE INTO sessions (id,title,mode,initial_prompt,selected_agents,rounds,language,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
  $stmt->execute([$id, 't', 'chat', '', '[]', 1, 'fr', $now, $now]);
}

function insertMemory(PDO $pdo, array $row): void {
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

$mFresh = uuid();
$mStale = uuid();
$mInv = uuid();
$mNew = uuid();
$s1 = uuid(); $s2 = uuid(); $s3 = uuid(); $s4 = uuid();
insertSession($pdo, $s1);
insertSession($pdo, $s2);
insertSession($pdo, $s3);
insertSession($pdo, $s4);

// fresh: now
insertMemory($pdo, [
  ':memory_id' => $mFresh,
  ':session_id' => $s1,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'proceed',
  ':confidence' => 'moderate',
  ':decision_summary' => 'fresh summary',
  ':validated_hypotheses' => '[]',
  ':failed_assumptions' => '[]',
  ':unresolved_risks' => '["riskA"]',
  ':recommended_next_steps' => '["do X"]',
  ':historical_outcome' => 'proceed',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => date('c'),
  ':memory_state' => 'active',
  ':superseded_by' => null,
  ':invalidated_reason' => null,
  ':last_reviewed_at' => null,
]);

// stale: 120 days ago
$old = (new DateTimeImmutable('now'))->modify('-120 days')->format('c');
insertMemory($pdo, [
  ':memory_id' => $mStale,
  ':session_id' => $s2,
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

// invalidated
insertMemory($pdo, [
  ':memory_id' => $mInv,
  ':session_id' => $s3,
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
  ':created_at' => date('c'),
  ':memory_state' => 'invalidated',
  ':superseded_by' => null,
  ':invalidated_reason' => 'obsolete',
  ':last_reviewed_at' => null,
]);

// newer memory for supersede suggestion
insertMemory($pdo, [
  ':memory_id' => $mNew,
  ':session_id' => $s4,
  ':playbook_id' => 'founder-sprint',
  ':decision_status' => 'proceed',
  ':confidence' => 'strong',
  ':decision_summary' => 'newer summary',
  ':validated_hypotheses' => '[]',
  ':failed_assumptions' => '[]',
  ':unresolved_risks' => '[]',
  ':recommended_next_steps' => '["ship"]',
  ':historical_outcome' => 'proceed',
  ':contract_version' => 'decision_outcome.v1',
  ':taxonomy_version' => 'taxonomy.v1',
  ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
  ':user_confirmed' => 1,
  ':created_at' => date('c'),
  ':memory_state' => 'active',
  ':superseded_by' => null,
  ':invalidated_reason' => null,
  ':last_reviewed_at' => null,
]);

// Link stale -> new as pivot (should suggest supersede deterministically)
$stmt = $pdo->prepare('INSERT INTO decision_memory_links (from_memory_id,to_memory_id,link_type,created_at) VALUES (?,?,?,?)');
$stmt->execute([$mStale, $mNew, 'pivot', date('c')]);

// 1) stale is blocked without allow_stale
$res = $repo->compactReusableForIdsWithOptions([$mStale], ['allow_stale' => false, 'expert_override' => false]);
if (count($res['allowed']) !== 0) { echo "FAIL: stale allowed unexpectedly\n"; exit(1); }
$r0 = $res['blocked'][0]['reason'] ?? '';
if ($r0 !== 'superseded' && $r0 !== 'stale_requires_confirmation') { echo "FAIL: stale reason unexpected ($r0)\n"; exit(1); }
echo "PASS: stale memory blocked (reason=$r0)\n";

// 2) stale allowed with allow_stale (unless superseded; then still blocked)
$res2 = $repo->compactReusableForIdsWithOptions([$mStale], ['allow_stale' => true, 'expert_override' => false]);
if (count($res2['allowed']) === 0 && (($res2['blocked'][0]['reason'] ?? '') !== 'superseded')) {
  echo "FAIL: stale allow_stale did not allow and not superseded\n"; exit(1);
}
echo "PASS: allow_stale works (or superseded takes precedence)\n";

// 3) invalidated blocked
$res3 = $repo->compactReusableForIdsWithOptions([$mInv], ['allow_stale' => true, 'expert_override' => false]);
if (($res3['blocked'][0]['reason'] ?? '') !== 'invalidated') { echo "FAIL: invalidated not blocked\n"; exit(1); }
echo "PASS: invalidated blocked\n";

// 4) superseded suggests replacement (via link inference)
$res4 = $repo->compactReusableForIdsWithOptions([$mStale], ['allow_stale' => true, 'expert_override' => false]);
if (($res4['blocked'][0]['reason'] ?? '') !== 'superseded') { echo "FAIL: superseded not detected\n"; exit(1); }
echo "PASS: superseded detected\n";

// 5) review updates last_reviewed_at + audit log append
$beforeAudit = $repo->auditFor($mFresh, 20);
$repo->applyLifecycleAction($mFresh, 'review', []);
$after = $repo->findById($mFresh);
if (empty($after['last_reviewed_at'])) { echo "FAIL: last_reviewed_at not set\n"; exit(1); }
$afterAudit = $repo->auditFor($mFresh, 20);
if (count($afterAudit) <= count($beforeAudit)) { echo "FAIL: audit event not appended\n"; exit(1); }
echo "PASS: review updates last_reviewed_at + audit\n";

echo "\nOK\n";


<?php
/**
 * Experimental semantic similarity (feature-flagged; deterministic fake provider only).
 *
 * Usage:
 *   php backend/tools/test_semantic_similarity.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\DecisionMemoryEmbeddingsRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Domain\DecisionMemory\DeterministicFakeEmbeddingProvider;

$passN = 0;
$failN = 0;
$pass = function (string $label) use (&$passN): void { echo "PASS: {$label}\n"; $passN++; };
$fail = function (string $label, string $detail = '') use (&$failN): void { echo "FAIL: {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $failN++; };

function execStmt(PDO $pdo, string $sql, array $params = []): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function setSemanticFlag(string $value): void {
    putenv("SEMANTIC_MEMORY_ENABLED={$value}");
}

echo "Semantic similarity checks (experimental)\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = $db->pdo();

$provider = new DeterministicFakeEmbeddingProvider(['dimensions' => 24]);
$embRepo = new DecisionMemoryEmbeddingsRepository();
$memRepo = new DecisionMemoryRepository();

// 1) Feature flag disabled behavior
setSemanticFlag('false');
$disabled = $embRepo->rebuildEmbeddings($provider, []);
if (($disabled['enabled'] ?? true) === false) $pass('feature disabled: rebuildEmbeddings returns enabled=false');
else $fail('feature disabled: rebuildEmbeddings returns enabled=false', json_encode($disabled, JSON_UNESCAPED_UNICODE) ?: '');

// 2) Deterministic provider
$v1 = $provider->embed('hello world');
$v2 = $provider->embed('hello world');
$v3 = $provider->embed('hello world!');
if ($v1 === $v2 && $v1 !== $v3) $pass('DeterministicFakeEmbeddingProvider deterministic');
else $fail('DeterministicFakeEmbeddingProvider deterministic', 'vectors mismatch');

// 3) Seed fixture memories + scopes
setSemanticFlag('true');
$now = date('c');

$sA = 'test-sem-sA-' . bin2hex(random_bytes(4));
$sB = 'test-sem-sB-' . bin2hex(random_bytes(4));
$sC = 'test-sem-sC-' . bin2hex(random_bytes(4));
$sD = 'test-sem-sD-' . bin2hex(random_bytes(4));
$sE = 'test-sem-sE-' . bin2hex(random_bytes(4));
$sF = 'test-sem-sF-' . bin2hex(random_bytes(4));
foreach ([$sA,$sB,$sC,$sD,$sE,$sF] as $sid) {
    execStmt($pdo, 'INSERT OR IGNORE INTO sessions (id,title,mode,initial_prompt,selected_agents,rounds,language,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$sid, 't', 'chat', '', '[]', 1, 'fr', $now, $now]
    );
}

$ctx1 = 'ctx-sem-' . bin2hex(random_bytes(4));
$ctx2 = 'ctx-sem-' . bin2hex(random_bytes(4));
execStmt($pdo, 'INSERT OR REPLACE INTO strategic_contexts (context_id,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
    [$ctx1, 'CTX SEM 1', '', 'active', $now, $now]
);
execStmt($pdo, 'INSERT OR REPLACE INTO strategic_contexts (context_id,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
    [$ctx2, 'CTX SEM 2', '', 'active', $now, $now]
);

$room1 = 'room-sem-' . bin2hex(random_bytes(4));
$room2 = 'room-sem-' . bin2hex(random_bytes(4));
execStmt($pdo, 'INSERT OR REPLACE INTO decision_rooms (room_id,context_id,title,description,playbook_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
    [$room1, $ctx1, 'Room SEM 1', '', 'founder-sprint', 'active', $now, $now]
);
execStmt($pdo, 'INSERT OR REPLACE INTO decision_rooms (room_id,context_id,title,description,playbook_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
    [$room2, $ctx2, 'Room SEM 2', '', 'founder-sprint', 'active', $now, $now]
);

$mkA = 'MK_SEM_A_' . bin2hex(random_bytes(4));
$mkB = 'MK_SEM_B_' . bin2hex(random_bytes(4));
$mkStale = 'MK_SEM_STALE_' . bin2hex(random_bytes(4));
$mkArchived = 'MK_SEM_ARCH_' . bin2hex(random_bytes(4));
$mkInvalid = 'MK_SEM_INV_' . bin2hex(random_bytes(4));
$rawChatMarker = 'Conversation History: SHOULD_NOT_BE_EMBEDDED';
$providerMarker = 'Provider output: SHOULD_NOT_BE_EMBEDDED';

$mA = 'mem-sem-A-' . bin2hex(random_bytes(4)); // query memory
$mB = 'mem-sem-B-' . bin2hex(random_bytes(4)); // similar-ish
$mStale = 'mem-sem-stale-' . bin2hex(random_bytes(4));
$mArchived = 'mem-sem-arch-' . bin2hex(random_bytes(4));
$mInvalid = 'mem-sem-inv-' . bin2hex(random_bytes(4));
$mOtherCtx = 'mem-sem-other-' . bin2hex(random_bytes(4));

$insertMemory = function (array $row) use ($pdo): void {
    execStmt($pdo, '
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
    ', $row);
};

$insertMemory([
    ':memory_id' => $mA,
    ':session_id' => $sA,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'proceed',
    ':confidence' => 'moderate',
    ':decision_summary' => "We should ship widgets. {$mkA}",
    ':validated_hypotheses' => json_encode(['buyers pay'], JSON_UNESCAPED_UNICODE),
    ':failed_assumptions' => json_encode([], JSON_UNESCAPED_UNICODE),
    ':unresolved_risks' => json_encode(['pricing risk'], JSON_UNESCAPED_UNICODE),
    ':recommended_next_steps' => json_encode(['pilot'], JSON_UNESCAPED_UNICODE),
    ':historical_outcome' => 'proceed',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    ':persistence_safety' => json_encode(['safe_to_persist' => true, 'requires_user_confirmation' => false, 'meta' => $rawChatMarker . '|' . $providerMarker], JSON_UNESCAPED_UNICODE),
    ':user_confirmed' => 1,
    ':created_at' => $now,
    ':memory_state' => 'active',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mB,
    ':session_id' => $sB,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'validate_first',
    ':confidence' => 'weak',
    ':decision_summary' => "We should validate widget demand. {$mkB}",
    ':validated_hypotheses' => json_encode([], JSON_UNESCAPED_UNICODE),
    ':failed_assumptions' => json_encode(['EU same as US'], JSON_UNESCAPED_UNICODE),
    ':unresolved_risks' => json_encode(['regulatory unknown'], JSON_UNESCAPED_UNICODE),
    ':recommended_next_steps' => json_encode(['talk to prospects'], JSON_UNESCAPED_UNICODE),
    ':historical_outcome' => 'validate_first',
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

$insertMemory([
    ':memory_id' => $mStale,
    ':session_id' => $sC,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'validate_first',
    ':confidence' => 'weak',
    ':decision_summary' => "Old widget decision. {$mkStale}",
    ':validated_hypotheses' => '[]',
    ':failed_assumptions' => '[]',
    ':unresolved_risks' => '[]',
    ':recommended_next_steps' => '[]',
    ':historical_outcome' => 'validate_first',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
    ':user_confirmed' => 1,
    ':created_at' => $now,
    ':memory_state' => 'stale',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mArchived,
    ':session_id' => $sD,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'proceed',
    ':confidence' => 'strong',
    ':decision_summary' => "Archived widget decision. {$mkArchived}",
    ':validated_hypotheses' => '[]',
    ':failed_assumptions' => '[]',
    ':unresolved_risks' => '[]',
    ':recommended_next_steps' => '[]',
    ':historical_outcome' => 'proceed',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
    ':user_confirmed' => 1,
    ':created_at' => $now,
    ':memory_state' => 'archived',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mInvalid,
    ':session_id' => $sE,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'pivot',
    ':confidence' => 'moderate',
    ':decision_summary' => "Invalidated widget decision. {$mkInvalid}",
    ':validated_hypotheses' => '[]',
    ':failed_assumptions' => '[]',
    ':unresolved_risks' => '[]',
    ':recommended_next_steps' => '[]',
    ':historical_outcome' => 'pivot',
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

$insertMemory([
    ':memory_id' => $mOtherCtx,
    ':session_id' => $sF,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'kill',
    ':confidence' => 'strong',
    ':decision_summary' => "Different context decision. {$mkB}",
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
    ':memory_state' => 'active',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mA, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mB, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mStale, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mArchived, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mInvalid, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx2, $mOtherCtx, $now]);

execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room1, $mA, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room1, $mB, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room2, $mOtherCtx, $now]);

// 4) Rebuild embeddings
$reb = $embRepo->rebuildEmbeddings($provider, []);
if (($reb['enabled'] ?? false) === true) $pass('rebuild embeddings enabled');
else $fail('rebuild embeddings enabled', json_encode($reb, JSON_UNESCAPED_UNICODE) ?: '');

// 5) Hash change detection (second run should skip unchanged)
$reb2 = $embRepo->rebuildEmbeddings($provider, []);
if ((int)($reb2['skipped_unchanged'] ?? 0) >= 1) $pass('hash change detection skips unchanged');
else $fail('hash change detection skips unchanged', json_encode($reb2, JSON_UNESCAPED_UNICODE) ?: '');

// 6) Similarity API (repository-level) must exist
if (method_exists($embRepo, 'findSimilar')) $pass('DecisionMemoryEmbeddingsRepository::findSimilar exists');
else $fail('DecisionMemoryEmbeddingsRepository::findSimilar exists', 'missing method');

if (method_exists($embRepo, 'findSimilar')) {
    $out = $embRepo->findSimilar($provider, [
        'memory_id' => $mA,
        'context_id' => $ctx1,
        'limit' => 10,
        'include_stale' => false,
        'expert_override' => false,
    ]);
    $ids = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($out['results'] ?? []));
    if (in_array($mB, $ids, true) && !in_array($mStale, $ids, true) && !in_array($mArchived, $ids, true) && !in_array($mInvalid, $ids, true)) {
        $pass('default filters exclude stale/archived/invalidated');
    } else {
        $fail('default filters exclude stale/archived/invalidated', json_encode($ids, JSON_UNESCAPED_UNICODE) ?: '');
    }

    $outSt = $embRepo->findSimilar($provider, [
        'memory_id' => $mA,
        'context_id' => $ctx1,
        'limit' => 20,
        'include_stale' => true,
        'expert_override' => false,
    ]);
    $idsSt = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($outSt['results'] ?? []));
    if (in_array($mStale, $idsSt, true) && !in_array($mArchived, $idsSt, true) && !in_array($mInvalid, $idsSt, true)) {
        $pass('include_stale includes stale only');
    } else {
        $fail('include_stale includes stale only', json_encode($idsSt, JSON_UNESCAPED_UNICODE) ?: '');
    }

    $outEx = $embRepo->findSimilar($provider, [
        'memory_id' => $mA,
        'context_id' => $ctx1,
        'limit' => 30,
        'include_stale' => true,
        'expert_override' => true,
    ]);
    $idsEx = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($outEx['results'] ?? []));
    if (in_array($mArchived, $idsEx, true) && in_array($mInvalid, $idsEx, true)) {
        $pass('expert_override includes archived+invalidated');
    } else {
        $fail('expert_override includes archived+invalidated', json_encode($idsEx, JSON_UNESCAPED_UNICODE) ?: '');
    }

    $outRoom = $embRepo->findSimilar($provider, [
        'q' => $mkB,
        'context_id' => $ctx1,
        'room_id' => $room2,
        'limit' => 10,
        'include_stale' => true,
        'expert_override' => true,
    ]);
    $idsRoom = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($outRoom['results'] ?? []));
    if ($idsRoom === [$mOtherCtx]) $pass('room scope overrides context scope');
    else $fail('room scope overrides context scope', json_encode($idsRoom, JSON_UNESCAPED_UNICODE) ?: '');

    $json = json_encode($outEx, JSON_UNESCAPED_UNICODE);
    if ($json && !str_contains($json, $rawChatMarker) && !str_contains($json, $providerMarker)) {
        $pass('no raw chat/provider output in similarity payload');
    } else {
        $fail('no raw chat/provider output in similarity payload', 'marker leaked');
    }

    $o1 = $embRepo->findSimilar($provider, ['memory_id' => $mA, 'context_id' => $ctx1, 'limit' => 10, 'include_stale' => false, 'expert_override' => false]);
    $o2 = $embRepo->findSimilar($provider, ['memory_id' => $mA, 'context_id' => $ctx1, 'limit' => 10, 'include_stale' => false, 'expert_override' => false]);
    $ids1 = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($o1['results'] ?? []));
    $ids2 = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($o2['results'] ?? []));
    if ($ids1 === $ids2) $pass('deterministic ordering');
    else $fail('deterministic ordering', json_encode(['ids1' => $ids1, 'ids2' => $ids2], JSON_UNESCAPED_UNICODE) ?: '');

    $badProvider = new DeterministicFakeEmbeddingProvider(['dimensions' => 24, 'fail_on_substring' => $mkA]);
    $outFail = $embRepo->findSimilar($badProvider, ['memory_id' => $mA, 'context_id' => $ctx1, 'limit' => 10]);
    if (is_array($outFail) && isset($outFail['results']) && is_array($outFail['results'])) $pass('graceful provider failure returns shape');
    else $fail('graceful provider failure returns shape', json_encode($outFail, JSON_UNESCAPED_UNICODE) ?: '');
}

// Cleanup
try {
    $mids = [$mA,$mB,$mStale,$mArchived,$mInvalid,$mOtherCtx];
    $in = implode(',', array_fill(0, count($mids), '?'));
    execStmt($pdo, "DELETE FROM decision_room_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM strategic_context_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM decision_memory_embeddings WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM decision_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM decision_rooms WHERE room_id IN (?,?)", [$room1,$room2]);
    execStmt($pdo, "DELETE FROM strategic_contexts WHERE context_id IN (?,?)", [$ctx1,$ctx2]);
    execStmt($pdo, "DELETE FROM sessions WHERE id IN (?,?,?,?,?,?)", [$sA,$sB,$sC,$sD,$sE,$sF]);
    $pass('cleanup fixture rows');
} catch (\Throwable $e) {
    $fail('cleanup fixture rows', $e->getMessage());
}

printf("\nDone: %d passed, %d failed\n", $passN, $failN);
exit($failN > 0 ? 1 : 0);


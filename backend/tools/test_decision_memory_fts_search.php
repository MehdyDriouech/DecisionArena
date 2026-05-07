<?php
/**
 * Decision Memory — Scoped FTS5 search (with LIKE fallback).
 *
 * Usage:
 *   php backend/tools/test_decision_memory_fts_search.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;

$passN = 0;
$failN = 0;
$pass = function (string $label) use (&$passN): void { echo "PASS: {$label}\n"; $passN++; };
$fail = function (string $label, string $detail = '') use (&$failN): void { echo "FAIL: {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $failN++; };

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

function execStmt(PDO $pdo, string $sql, array $params = []): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function tableExists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}

echo "Decision Memory FTS scoped search checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = $db->pdo();

// 0) Route is registered (static check)
$indexPhp = @file_get_contents(__DIR__ . '/../public/index.php') ?: '';
if (str_contains($indexPhp, "/api/decision-memories/search")) {
    $pass('route registered: /api/decision-memories/search');
} else {
    $fail('route registered: /api/decision-memories/search', 'missing string in backend/public/index.php');
}

// 1) FTS5 table creation OR clean fallback
$ftsExists = tableExists($pdo, 'decision_memory_fts');
if ($ftsExists) {
    $pass('FTS table exists (decision_memory_fts)');
} else {
    // In fallback mode, table is absent but app must still function.
    $pass('FTS table absent -> fallback expected');
}

// 2) Seed minimal sessions + decision memories + context/room links
$now = date('c');
$sA = 'test-fts-search-sA-' . bin2hex(random_bytes(4));
$sB = 'test-fts-search-sB-' . bin2hex(random_bytes(4));
$sC = 'test-fts-search-sC-' . bin2hex(random_bytes(4));
$sD = 'test-fts-search-sD-' . bin2hex(random_bytes(4));
$sE = 'test-fts-search-sE-' . bin2hex(random_bytes(4));
$sStale = 'test-fts-search-sStale-' . bin2hex(random_bytes(4));
$sessions = [$sA,$sB,$sC,$sD,$sE,$sStale];
foreach ($sessions as $sid) {
    execStmt($pdo, 'INSERT OR IGNORE INTO sessions (id,title,mode,initial_prompt,selected_agents,rounds,language,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$sid, 't', 'chat', '', '[]', 1, 'fr', $now, $now]
    );
}

$ctx1 = 'ctx-fts-' . bin2hex(random_bytes(4));
$ctx2 = 'ctx-fts-' . bin2hex(random_bytes(4));
execStmt($pdo, 'INSERT OR REPLACE INTO strategic_contexts (context_id,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
    [$ctx1, 'CTX 1', '', 'active', $now, $now]
);
execStmt($pdo, 'INSERT OR REPLACE INTO strategic_contexts (context_id,title,description,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
    [$ctx2, 'CTX 2', '', 'active', $now, $now]
);

$room1 = 'room-fts-' . bin2hex(random_bytes(4));
$room2 = 'room-fts-' . bin2hex(random_bytes(4));
execStmt($pdo, 'INSERT OR REPLACE INTO decision_rooms (room_id,context_id,title,description,playbook_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
    [$room1, $ctx1, 'Room 1', '', 'founder-sprint', 'active', $now, $now]
);
execStmt($pdo, 'INSERT OR REPLACE INTO decision_rooms (room_id,context_id,title,description,playbook_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',
    [$room2, $ctx2, 'Room 2', '', 'founder-sprint', 'active', $now, $now]
);

$mOk1 = 'mem-ok1-' . bin2hex(random_bytes(4));
$mOk2 = 'mem-ok2-' . bin2hex(random_bytes(4));
$mUnconfirmed = 'mem-unconfirmed-' . bin2hex(random_bytes(4));
$mInvalidated = 'mem-invalidated-' . bin2hex(random_bytes(4));
$mArchived = 'mem-archived-' . bin2hex(random_bytes(4));
$mStale = 'mem-stale-' . bin2hex(random_bytes(4));

// Unique markers to avoid collisions with existing DB data.
$mkOk1 = 'MK_OK1_' . bin2hex(random_bytes(4));
$mkOk2 = 'MK_OK2_' . bin2hex(random_bytes(4));
$mkStale = 'MK_STALE_' . bin2hex(random_bytes(4));
$mkArchived = 'MK_ARCH_' . bin2hex(random_bytes(4));
$mkInvalidated = 'MK_INV_' . bin2hex(random_bytes(4));

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

$rawChatMarker = 'Conversation History: SHOULD_NOT_BE_INDEXED';
$providerMarker = 'Provider output: SHOULD_NOT_BE_INDEXED';

$insertMemory([
    ':memory_id' => $mOk1,
    ':session_id' => $sA,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'proceed',
    ':confidence' => 'moderate',
    ':decision_summary' => "We should ship widgets for enterprise buyers. {$mkOk1}",
    ':validated_hypotheses' => json_encode(['enterprise buyers pay'], JSON_UNESCAPED_UNICODE),
    ':failed_assumptions' => json_encode([], JSON_UNESCAPED_UNICODE),
    ':unresolved_risks' => json_encode(['pricing risk'], JSON_UNESCAPED_UNICODE),
    ':recommended_next_steps' => json_encode(['run a pilot'], JSON_UNESCAPED_UNICODE),
    ':historical_outcome' => 'proceed',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    // persistence_safety includes markers: must NOT end up in searchable_text
    ':persistence_safety' => json_encode(['safe_to_persist' => true, 'requires_user_confirmation' => false, 'meta' => $rawChatMarker . ' | ' . $providerMarker], JSON_UNESCAPED_UNICODE),
    ':user_confirmed' => 1,
    ':created_at' => $now,
    ':memory_state' => 'active',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mOk2,
    ':session_id' => $sB,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'validate_first',
    ':confidence' => 'weak',
    ':decision_summary' => "Validate widget demand in Europe. {$mkOk2}",
    ':validated_hypotheses' => json_encode([], JSON_UNESCAPED_UNICODE),
    ':failed_assumptions' => json_encode(['EU demand is same as US'], JSON_UNESCAPED_UNICODE),
    ':unresolved_risks' => json_encode(['regulatory unknown'], JSON_UNESCAPED_UNICODE),
    ':recommended_next_steps' => json_encode(['talk to 10 prospects'], JSON_UNESCAPED_UNICODE),
    ':historical_outcome' => 'validate_first',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    ':persistence_safety' => json_encode(['safe_to_persist' => true, 'requires_user_confirmation' => false], JSON_UNESCAPED_UNICODE),
    ':user_confirmed' => 1,
    ':created_at' => $now,
    ':memory_state' => 'active',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mUnconfirmed,
    ':session_id' => $sC,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'kill',
    ':confidence' => 'strong',
    ':decision_summary' => 'Unconfirmed should not appear.',
    ':validated_hypotheses' => '[]',
    ':failed_assumptions' => '[]',
    ':unresolved_risks' => '[]',
    ':recommended_next_steps' => '[]',
    ':historical_outcome' => 'kill',
    ':contract_version' => 'decision_outcome.v1',
    ':taxonomy_version' => 'taxonomy.v1',
    ':persistence_safety' => '{"safe_to_persist":true,"requires_user_confirmation":false}',
    ':user_confirmed' => 0,
    ':created_at' => $now,
    ':memory_state' => 'active',
    ':superseded_by' => null,
    ':invalidated_reason' => null,
    ':last_reviewed_at' => null,
]);

$insertMemory([
    ':memory_id' => $mInvalidated,
    ':session_id' => $sD,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'pivot',
    ':confidence' => 'moderate',
    ':decision_summary' => "Invalidated should not appear. {$mkInvalidated}",
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
    ':memory_id' => $mArchived,
    ':session_id' => $sE,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'proceed',
    ':confidence' => 'strong',
    ':decision_summary' => "Archived should not appear. {$mkArchived}",
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
    ':memory_id' => $mStale,
    ':session_id' => $sStale,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'validate_first',
    ':confidence' => 'weak',
    ':decision_summary' => "Stale should be excluded unless include_stale=1. {$mkStale}",
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

// Link memories into ctx/room scopes
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mOk1, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx1, $mInvalidated, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)', [$ctx2, $mOk2, $now]);

execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room1, $mOk1, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room1, $mInvalidated, $now]);
execStmt($pdo, 'INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)', [$room2, $mOk2, $now]);

// Important: this test inserts memories manually (bypassing repository write hooks),
// so we must rebuild the FTS index before asserting FTS-scoped results.
try {
    $repo = new \Infrastructure\Persistence\DecisionMemoryRepository();
    if (method_exists($repo, 'isDecisionMemoryFtsAvailable') && $repo->isDecisionMemoryFtsAvailable()) {
        $repo->rebuildDecisionMemoryFts();
        $pass('FTS rebuilt after manual inserts');

        // Sanity: index should contain rows + MATCH should work for a seeded token.
        try {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM decision_memory_fts')->fetchColumn();
            if ($n > 0) $pass('FTS contains rows after rebuild');
            else $fail('FTS contains rows after rebuild', "count={$n}");
        } catch (\Throwable $e) {
            $fail('FTS contains rows after rebuild', $e->getMessage());
        }
        try {
            $st = $pdo->prepare('SELECT memory_id FROM decision_memory_fts WHERE decision_memory_fts MATCH ? LIMIT 5');
            $st->execute(['"widgets"']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) >= 1) $pass('FTS MATCH works for seeded token');
            else $fail('FTS MATCH works for seeded token', '0 rows');
        } catch (\Throwable $e) {
            $fail('FTS MATCH works for seeded token', $e->getMessage());
        }
        try {
            $st = $pdo->prepare("SELECT bm25(decision_memory_fts) AS s, snippet(decision_memory_fts, -1, '[', ']', '…', 8) AS sn FROM decision_memory_fts WHERE decision_memory_fts MATCH ? LIMIT 1");
            $st->execute(['"widgets"']);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row && isset($row['s'], $row['sn'])) $pass('FTS bm25/snippet functions available');
            else $fail('FTS bm25/snippet functions available', 'no row');
        } catch (\Throwable $e) {
            $fail('FTS bm25/snippet functions available', $e->getMessage());
        }

        // Diagnostic: join decision_memories <-> FTS should return seeded memory.
        try {
            $st = $pdo->prepare("SELECT m.memory_id FROM decision_memories m INNER JOIN decision_memory_fts ON decision_memory_fts.memory_id = m.memory_id WHERE m.user_confirmed = 1 AND decision_memory_fts MATCH ? ORDER BY m.created_at DESC LIMIT 5");
            $st->execute(['"widgets"']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) >= 1) $pass('FTS join to decision_memories yields rows');
            else $fail('FTS join to decision_memories yields rows', '0 rows');
        } catch (\Throwable $e) {
            $fail('FTS join to decision_memories yields rows', $e->getMessage());
        }
    } else {
        $pass('FTS unavailable -> LIKE fallback mode');
    }
} catch (\Throwable $e) {
    $fail('FTS rebuild after manual inserts', $e->getMessage());
}

// 3) Rebuild script exists (static check)
if (is_file(__DIR__ . '/rebuild_decision_memory_fts.php')) {
    $pass('rebuild script exists: backend/tools/rebuild_decision_memory_fts.php');
} else {
    $fail('rebuild script exists: backend/tools/rebuild_decision_memory_fts.php', 'file missing');
}

// 4) Search API function exists (repository-level contract)
// This MUST exist after implementation: DecisionMemoryRepository::searchScoped(array $params): array
try {
    $repo = $repo ?? new \Infrastructure\Persistence\DecisionMemoryRepository();
    if (method_exists($repo, 'searchScoped')) {
        $pass('DecisionMemoryRepository::searchScoped exists');
    } else {
        $fail('DecisionMemoryRepository::searchScoped exists', 'missing method');
    }
} catch (\Throwable $e) {
    $fail('DecisionMemoryRepository instantiation', $e->getMessage());
}

// 5) If method exists, run scoped searches deterministically.
if (isset($repo) && is_object($repo) && method_exists($repo, 'searchScoped')) {
    $base = [
        'q' => 'widgets',
        'limit' => 50,
        'offset' => 0,
        'include_stale' => 0,
        'expert_override' => 0,
    ];

    // Context scope should return only ctx1 => mOk1, and exclude invalidated
    $resCtx = $repo->searchScoped(array_merge($base, ['context_id' => $ctx1]));
    $idsCtx = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resCtx['results'] ?? []));
    if (in_array($mOk1, $idsCtx, true) && !in_array($mInvalidated, $idsCtx, true)) {
        $pass('scoped context search includes ok + excludes invalidated');
    } else {
        $fail('scoped context search includes ok + excludes invalidated', json_encode($idsCtx, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // Room scope overrides context: asking room2 with context1 must return mOk2 only
    $resRoomOverride = $repo->searchScoped(array_merge($base, ['q' => $mkOk2, 'context_id' => $ctx1, 'room_id' => $room2]));
    $idsRoomOverride = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resRoomOverride['results'] ?? []));
    if ($idsRoomOverride === [$mOk2]) {
        $pass('room scope overrides context scope');
    } else {
        $fail('room scope overrides context scope', json_encode($idsRoomOverride, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // Stale excluded by default, included with include_stale=1
    $resNoStale = $repo->searchScoped(array_merge($base, ['q' => $mkStale, 'include_stale' => 0]));
    $idsNoStale = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resNoStale['results'] ?? []));
    if (!in_array($mStale, $idsNoStale, true)) {
        $pass('stale excluded by default');
    } else {
        $fail('stale excluded by default', json_encode($idsNoStale, JSON_UNESCAPED_UNICODE) ?: '');
    }
    $resYesStale = $repo->searchScoped(array_merge($base, ['q' => $mkStale, 'include_stale' => 1]));
    $idsYesStale = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resYesStale['results'] ?? []));
    if (in_array($mStale, $idsYesStale, true)) {
        $pass('stale included when include_stale=1');
    } else {
        $fail('stale included when include_stale=1', json_encode($idsYesStale, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // Expert override includes archived (but STILL excludes invalidated unless expert_override=1)
    $resArchivedNo = $repo->searchScoped(array_merge($base, ['q' => $mkArchived, 'expert_override' => 0]));
    $idsArchivedNo = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resArchivedNo['results'] ?? []));
    if (!in_array($mArchived, $idsArchivedNo, true)) {
        $pass('archived excluded unless expert_override=1');
    } else {
        $fail('archived excluded unless expert_override=1', json_encode($idsArchivedNo, JSON_UNESCAPED_UNICODE) ?: '');
    }
    $resArchivedYes = $repo->searchScoped(array_merge($base, ['q' => $mkArchived, 'expert_override' => 1]));
    $idsArchivedYes = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resArchivedYes['results'] ?? []));
    if (in_array($mArchived, $idsArchivedYes, true)) {
        $pass('archived included when expert_override=1');
    } else {
        $fail('archived included when expert_override=1', json_encode($idsArchivedYes, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // invalidated is ALWAYS excluded unless expert_override=1
    $resInvNo = $repo->searchScoped(array_merge($base, ['q' => $mkInvalidated, 'expert_override' => 0]));
    $idsInvNo = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resInvNo['results'] ?? []));
    if (!in_array($mInvalidated, $idsInvNo, true)) {
        $pass('invalidated excluded unless expert_override=1');
    } else {
        $fail('invalidated excluded unless expert_override=1', json_encode($idsInvNo, JSON_UNESCAPED_UNICODE) ?: '');
    }
    $resInvYes = $repo->searchScoped(array_merge($base, ['q' => $mkInvalidated, 'expert_override' => 1]));
    $idsInvYes = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($resInvYes['results'] ?? []));
    if (in_array($mInvalidated, $idsInvYes, true)) {
        $pass('invalidated included when expert_override=1');
    } else {
        $fail('invalidated included when expert_override=1', json_encode($idsInvYes, JSON_UNESCAPED_UNICODE) ?: '');
    }

    // Deterministic ordering: same query twice yields same ids order
    $res1 = $repo->searchScoped(array_merge($base, ['q' => $mkOk1]));
    $res2 = $repo->searchScoped(array_merge($base, ['q' => $mkOk1]));
    $ids1 = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($res1['results'] ?? []));
    $ids2 = array_map(fn($r) => (string)($r['memory_id'] ?? ''), (array)($res2['results'] ?? []));
    if ($ids1 === $ids2) {
        $pass('deterministic ordering for repeated calls');
    } else {
        $fail('deterministic ordering for repeated calls', json_encode(['ids1' => $ids1, 'ids2' => $ids2], JSON_UNESCAPED_UNICODE) ?: '');
    }

    // Safety: results never include raw chat/provider markers (heuristic)
    $json = json_encode($res1, JSON_UNESCAPED_UNICODE);
    if ($json && (!str_contains($json, $rawChatMarker) && !str_contains($json, $providerMarker))) {
        $pass('no raw chat/provider markers in results payload');
    } else {
        $fail('no raw chat/provider markers in results payload', 'marker leaked');
    }
}

// Cleanup: keep DB stable across repeated runs
try {
    $mids = [$mOk1,$mOk2,$mUnconfirmed,$mInvalidated,$mArchived,$mStale];
    $in = implode(',', array_fill(0, count($mids), '?'));
    execStmt($pdo, "DELETE FROM decision_room_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM strategic_context_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM decision_memories WHERE memory_id IN ($in)", $mids);
    execStmt($pdo, "DELETE FROM decision_rooms WHERE room_id IN (?,?)", [$room1,$room2]);
    execStmt($pdo, "DELETE FROM strategic_contexts WHERE context_id IN (?,?)", [$ctx1,$ctx2]);
    execStmt($pdo, "DELETE FROM sessions WHERE id IN (?,?,?,?,?,?)", [$sA,$sB,$sC,$sD,$sE,$sStale]);
    if (tableExists($pdo, 'decision_memory_fts')) {
        execStmt($pdo, "DELETE FROM decision_memory_fts WHERE memory_id IN ($in)", $mids);
    }
    $pass('cleanup test rows');
} catch (\Throwable $e) {
    $fail('cleanup test rows', $e->getMessage());
}

printf("\nDone: %d passed, %d failed\n", $passN, $failN);
exit($failN > 0 ? 1 : 0);


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
use Infrastructure\Persistence\Database;

$pdo = Database::getConnection();

echo "=== strategic_contexts (recent) ===\n";
$ctxs = $pdo->query(
    'SELECT context_id, title, status, is_workspace_active, updated_at FROM strategic_contexts ORDER BY updated_at DESC LIMIT 20'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($ctxs as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

$sada = null;
foreach ($ctxs as $row) {
    if (stripos((string)($row['title'] ?? ''), 'SADA') !== false) {
        $sada = $row;
        break;
    }
}
if (!$sada && $ctxs) {
    $sada = $ctxs[0];
    echo "\n(No title containing SADA; using most recently updated context for demo chain.)\n";
}

$cid = (string)($sada['context_id'] ?? '');
echo "\n=== SELECTED context ===\n";
echo json_encode($sada, JSON_UNESCAPED_UNICODE) . "\n";

$active = $pdo->query('SELECT context_id FROM strategic_contexts WHERE is_workspace_active = 1 LIMIT 1')->fetchColumn();
echo "\nactive_context_id (is_workspace_active=1): " . ($active ?: '(none)') . "\n";

echo "\n=== sessions linked to this context (strategic_context_id column) ===\n";
$stmt = $pdo->prepare('SELECT id, title, mode, status, strategic_context_id, selected_agents, created_at FROM sessions WHERE strategic_context_id = ? ORDER BY updated_at DESC LIMIT 8');
$stmt->execute([$cid]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sessions as $s) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== strategic_context_sessions for context ===\n";
$stmt2 = $pdo->prepare('SELECT * FROM strategic_context_sessions WHERE context_id = ? ORDER BY created_at DESC LIMIT 8');
$stmt2->execute([$cid]);
$scs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
foreach ($scs as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

$sid = (string)($sessions[0]['id'] ?? ($scs[0]['session_id'] ?? ''));
if ($sid === '') {
    echo "\nNo session found for chain.\n";
    exit(0);
}

echo "\n=== PICKED session_id for deep dive: {$sid} ===\n";
$one = $pdo->prepare('SELECT id, title, mode, status, strategic_context_id, selected_agents FROM sessions WHERE id = ?');
$one->execute([$sid]);
$sessionRow = $one->fetch(PDO::FETCH_ASSOC) ?: [];
echo json_encode($sessionRow, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== decision_memories for session ===\n";
$dm = $pdo->prepare('SELECT memory_id, session_id, memory_state, decision_status, user_confirmed FROM decision_memories WHERE session_id = ?');
$dm->execute([$sid]);
$drows = $dm->fetchAll(PDO::FETCH_ASSOC);
foreach ($drows as $d) {
    echo json_encode($d, JSON_UNESCAPED_UNICODE) . "\n";
}

$mid = (string)($drows[0]['memory_id'] ?? '');
if ($mid !== '') {
    $link = $pdo->prepare('SELECT 1 FROM strategic_context_memories WHERE context_id = ? AND memory_id = ?');
    $link->execute([$cid, $mid]);
    echo "\nstrategic_context_memories link SADA+memory: " . ($link->fetchColumn() ? 'yes' : 'no') . "\n";
}

echo "\n=== storage agent dirs for context ===\n";
$base = __DIR__ . '/../storage/strategic-contexts/' . $cid . '/agents';
if (is_dir($base)) {
    foreach (scandir($base) ?: [] as $a) {
        if ($a === '.' || $a === '..') {
            continue;
        }
        $f = $base . '/' . $a . '/memory.md';
        $ex = file_exists($f) ? 'yes' : 'no';
        $sz = $ex === 'yes' ? filesize($f) : 0;
        echo "{$a}: memory.md exists={$ex} size={$sz}\n";
    }
} else {
    echo "(no agents dir)\n";
}

use Domain\Sessions\SessionAgentResolver;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;

echo "\n=== SessionAgentResolver (with sources) ===\n";
$res = new SessionAgentResolver();
$det = $res->resolveParticipantsWithSources($sid);
foreach ($det as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== messages.agent_id distinct ===\n";
$msg = $pdo->prepare('SELECT DISTINCT agent_id FROM messages WHERE session_id = ? AND agent_id IS NOT NULL AND agent_id != \'\' ORDER BY agent_id');
$msg->execute([$sid]);
foreach ($msg->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    echo (string)$aid . "\n";
}

echo "\n=== session_votes.agent_id distinct ===\n";
try {
    $vt = $pdo->prepare('SELECT DISTINCT agent_id FROM session_votes WHERE session_id = ? AND agent_id IS NOT NULL ORDER BY agent_id');
    $vt->execute([$sid]);
    foreach ($vt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        echo (string)$aid . "\n";
    }
} catch (Throwable $e) {
    echo "(votes query error: " . $e->getMessage() . ")\n";
}

echo "\n=== StrategicContextWorkspaceAgentsCatalog (participated OR memory) ===\n";
$cat = new StrategicContextWorkspaceAgentsCatalog();
$all = $cat->buildForContext($cid);
foreach ($all as $row) {
    if (!empty($row['participated']) || !empty($row['memory_md_exists'])) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
echo 'total catalog rows: ' . count($all) . "\n";

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

use Controllers\StrategicContextController;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

echo "Strategic Context memory UX contract (memory-overview)\n\n";

$db = Database::getInstance();
(new Migration($db))->run();

$repo = new StrategicContextRepository();
$c = $repo->create('UX Contract', 'memory overview smoke', 'active');
$cid = (string)($c['context_id'] ?? '');
if ($cid === '') {
    echo "FAIL: no context_id\n";
    exit(1);
}

$req = new Request();
$req->setParams(['id' => $cid]);

$ctrl = new StrategicContextController();
$out = $ctrl->memoryOverview($req);

if (isset($out['error'])) {
    echo 'FAIL: error payload: ' . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
if (($out['ok'] ?? false) !== true) {
    echo "FAIL: ok flag\n";
    exit(1);
}
$ov = $out['overview'] ?? null;
if (!is_array($ov) || ($ov['context_id'] ?? '') !== $cid) {
    echo "FAIL: overview.context_id\n";
    exit(1);
}
if (!isset($ov['sessions_count'], $ov['decision_memories_count'], $ov['memory_health'])) {
    echo "FAIL: overview keys\n";
    exit(1);
}
if (!is_array($out['decisions_preview'] ?? null)) {
    echo "FAIL: decisions_preview array\n";
    exit(1);
}
if (!is_array($out['diagnostics'] ?? null)) {
    echo "FAIL: diagnostics array\n";
    exit(1);
}

echo "PASS: memory-overview contract\n";

function ux_uuid(): string
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

// --- Catalogue : session contextualisée + messages, sans Decision Memory persistée ---
$c2 = $repo->create('UX Catalog SADA-like', 'no DM row', 'active');
$cid2 = (string)($c2['context_id'] ?? '');
if ($cid2 === '') {
    echo "FAIL: context2\n";
    exit(1);
}
$sessions = new SessionRepository();
$pdo = Database::getConnection();
$sid2 = ux_uuid();
$now = gmdate('c');
$sessions->create([
    'id' => $sid2,
    'title' => 'UX no DM',
    'mode' => 'confrontation',
    'initial_prompt' => 'x',
    'selected_agents' => ['pm', 'architect', 'critic'],
    'rounds' => 1,
    'language' => 'en',
    'status' => 'completed',
    'cf_rounds' => 1,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
    'strategic_context_id' => $cid2,
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-ux-' . $sid2, $sid2, 'assistant', 'architect', 'hello', $now]);
$pdo->prepare('INSERT INTO strategic_context_sessions (context_id, session_id, created_at) VALUES (?,?,?)')
    ->execute([$cid2, $sid2, $now]);

$catalog = new StrategicContextWorkspaceAgentsCatalog();
$rows2 = $catalog->buildForContext($cid2);
$archRow = null;
foreach ($rows2 as $row) {
    if (strtolower((string)($row['agent_id'] ?? '')) === 'architect') {
        $archRow = $row;
        break;
    }
}
if ($archRow === null || empty($archRow['participated'])) {
    echo "FAIL: catalog architect should be participant (messages + selected_agents)\n";
    exit(1);
}
if (empty($archRow['memory_md_exists'])) {
    echo "FAIL: architect should have memory.md after session completion (participant sync)\n";
    exit(1);
}
$badges = $archRow['badges'] ?? [];
if (!in_array('participated', $badges, true) || !in_array('memory_md_exists', $badges, true)) {
    echo 'FAIL: architect badges expected participated + memory_md_exists, got ' . json_encode($badges) . "\n";
    exit(1);
}
if (!in_array('participation_context_sync', $badges, true)) {
    echo 'FAIL: architect should have participation_context_sync badge, got ' . json_encode($badges) . "\n";
    exit(1);
}
if (in_array('not_participant', $badges, true)) {
    echo "FAIL: architect should not be flagged not_participant in core catalog\n";
    exit(1);
}
if (in_array('persona_fallback_no_memory', $badges, true)) {
    echo "FAIL: architect should not be persona_fallback_no_memory\n";
    exit(1);
}

$storageUx = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($cid2) . '/agents/architect/memory.md';
$archMd = is_file($storageUx) ? (string)file_get_contents($storageUx) : '';
if ($archMd === '' || !str_contains($archMd, 'participant_context_sync:')) {
    echo "FAIL: architect memory.md should contain participant_context_sync marker\n";
    exit(1);
}
if (!str_contains($archMd, 'Source: participant_context_sync')) {
    echo "FAIL: architect memory.md should list Source: participant_context_sync\n";
    exit(1);
}

$req2 = new Request();
$req2->setParams(['id' => $cid2]);
$out2 = $ctrl->memoryOverview($req2);
$diag2 = $out2['diagnostics'] ?? [];
foreach ($diag2 as $d) {
    if (($d['code'] ?? '') === 'PARTICIPANT_AGENT_MEMORY_MISSING' && strtolower((string)($d['agent_id'] ?? '')) === 'architect') {
        echo "FAIL: architect should not trigger PARTICIPANT_AGENT_MEMORY_MISSING after participant sync\n";
        exit(1);
    }
}

// --- Session hors contexte : pas de faux participant ---
$c3 = $repo->create('UX Orphan session', 'x', 'active');
$cid3 = (string)($c3['context_id'] ?? '');
$sid3 = ux_uuid();
$sessions->create([
    'id' => $sid3,
    'title' => 'Orphan',
    'mode' => 'chat',
    'initial_prompt' => 'x',
    'selected_agents' => ['ghost-only-ux'],
    'rounds' => 1,
    'language' => 'en',
    'status' => 'completed',
    'cf_rounds' => 1,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
    'strategic_context_id' => '',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-ux-o' . $sid3, $sid3, 'assistant', 'ghost-only-ux', 'x', $now]);
$rows3 = $catalog->buildForContext($cid3);
foreach ($rows3 as $row) {
    if (strtolower((string)($row['agent_id'] ?? '')) === 'ghost-only-ux' && !empty($row['participated'])) {
        echo "FAIL: ghost-only-ux must not appear as participant in unrelated empty context\n";
        exit(1);
    }
}

// --- Fichier memory.md sans participation (orphelin disque) ---
$lonelyDir = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($cid2) . '/agents/lonely-ux';
if (!is_dir($lonelyDir)) {
    mkdir($lonelyDir, 0777, true);
}
file_put_contents($lonelyDir . '/memory.md', "# Context memory\n\nOrphan file for UX contract.\n");
$rows4 = $catalog->buildForContext($cid2);
$lonely = null;
foreach ($rows4 as $row) {
    if (strtolower((string)($row['agent_id'] ?? '')) === 'lonely-ux') {
        $lonely = $row;
        break;
    }
}
if ($lonely === null || empty($lonely['memory_md_exists']) || !empty($lonely['participated'])) {
    echo "FAIL: lonely-ux should have memory_md_exists and participated=false\n";
    exit(1);
}

foreach ($catalog->buildExpertPersonaFallbackForContext($cid2) as $erow) {
    if (strtolower((string)($erow['agent_id'] ?? '')) === 'architect') {
        echo "FAIL: expert persona catalog must not duplicate architect from core\n";
        exit(1);
    }
}

echo "PASS: workspace agents catalog (session sans DM + hors contexte + fichier seul)\n";

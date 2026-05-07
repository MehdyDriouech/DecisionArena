<?php
declare(strict_types=1);

/**
 * Phase 1 — Perspective Snapshots tests.
 *
 * Coverage:
 *  - default snapshot is byte-for-byte unchanged
 *  - omitted/empty/invalid perspective query falls back to default
 *  - deterministic, repeatable output for non-default perspectives
 *  - section ordering changes per perspective
 *  - lightweight perspective relevance block is keyword-based and stable
 *  - emphasized fields surface matching items first with the star marker
 *  - archived/stale/invalidated filtering preserved across perspectives
 *  - no raw chat or provider output leaks into perspective snapshots
 *  - perspective labels stable
 *  - room/context behavior preserved
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use Domain\Memory\MemorySnapshotGenerator;
use Domain\Memory\MemorySnapshotPerspectiveRegistry;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Infrastructure\Persistence\DecisionRoomRepository;

function uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function insertDecisionMemory(PDO $pdo, array $row): void
{
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

echo "memory.md perspective-snapshot checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$contexts = new StrategicContextRepository();
$rooms = new DecisionRoomRepository();
$sessions = new SessionRepository();
$gen = new MemorySnapshotGenerator();

$now = '2026-05-07T15:07:00+02:00';

$ctx = $contexts->create('Perspective Context', 'desc', 'active');
$cid = (string)($ctx['context_id'] ?? '');
if ($cid === '') {
    echo "FAIL: create context\n";
    exit(1);
}

$room = $rooms->create($cid, 'Room P', '', null, 'active');
$rid = (string)($room['room_id'] ?? '');
if ($rid === '') {
    echo "FAIL: create room\n";
    exit(1);
}

$sFresh = uuid();
$sUnassigned = uuid();
$sStale = uuid();
$sInv = uuid();
foreach ([$sFresh, $sUnassigned, $sStale, $sInv] as $sid) {
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

// Memory 1: cross-cutting tech + cost risks, mixed hypotheses
insertDecisionMemory($pdo, [
    ':memory_id' => $mFresh,
    ':session_id' => $sFresh,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'proceed',
    ':confidence' => 'moderate',
    ':decision_summary' => 'fresh summary',
    ':validated_hypotheses' => json_encode(['Architecture supports horizontal scale', 'Users adopt the onboarding flow']),
    ':failed_assumptions' => json_encode(['Cost of vendor X stays flat']),
    ':unresolved_risks' => json_encode(['Database migration window risk', 'Channel acquisition cost spike', 'GDPR compliance unclear']),
    ':recommended_next_steps' => json_encode(['Run migration rehearsal', 'Negotiate vendor cap']),
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

// Memory 2: unassigned (linked at context level only), product-flavored
insertDecisionMemory($pdo, [
    ':memory_id' => $mUnassigned,
    ':session_id' => $sUnassigned,
    ':playbook_id' => 'founder-sprint',
    ':decision_status' => 'pivot',
    ':confidence' => 'weak',
    ':decision_summary' => 'unassigned summary',
    ':validated_hypotheses' => json_encode([]),
    ':failed_assumptions' => json_encode(['User feedback strongly negative']),
    ':unresolved_risks' => json_encode(['UX friction in checkout']),
    ':recommended_next_steps' => json_encode([]),
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
    ':validated_hypotheses' => json_encode([]),
    ':failed_assumptions' => json_encode([]),
    ':unresolved_risks' => json_encode(['Stale risk should be filtered']),
    ':recommended_next_steps' => json_encode(['Stale next step']),
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
    ':validated_hypotheses' => json_encode([]),
    ':failed_assumptions' => json_encode([]),
    ':unresolved_risks' => json_encode(['Invalidated risk should be filtered']),
    ':recommended_next_steps' => json_encode([]),
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

$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mFresh, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mUnassigned, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mStale, $now]);
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')->execute([$cid, $mInv, $now]);
$pdo->prepare('INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at) VALUES (?,?,?)')->execute([$rid, $mFresh, $now]);

// 1) default snapshot byte-for-byte equality vs legacy generator (no perspective vs explicit "default")
$mdNoOpt = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20]);
$mdDefault = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'default']);
if ($mdNoOpt !== $mdDefault) {
    echo "FAIL: default perspective changed legacy output\n";
    echo "DIFF (first 800 chars):\n";
    echo "--- without perspective ---\n" . substr($mdNoOpt, 0, 800) . "\n";
    echo "--- with default perspective ---\n" . substr($mdDefault, 0, 800) . "\n";
    exit(1);
}
echo "PASS: default perspective preserves legacy snapshot byte-for-byte\n";

// 2) invalid perspective falls back to default (still byte-for-byte equal to legacy)
$mdBogus = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'NOT_A_REAL_PERSPECTIVE']);
if ($mdBogus !== $mdNoOpt) {
    echo "FAIL: invalid perspective did not fall back to default output\n";
    exit(1);
}
echo "PASS: invalid perspective falls back to default\n";

// 3) deterministic output for cto twice on same data + clock
$mdCto1 = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'cto']);
$mdCto2 = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'cto']);
if ($mdCto1 !== $mdCto2) {
    echo "FAIL: cto perspective is not deterministic\n";
    exit(1);
}
echo "PASS: deterministic perspective output\n";

// 4) section ordering visibly differs across perspectives
function sectionOrder(string $md): array
{
    $order = [];
    foreach (preg_split("/\r?\n/", $md) as $line) {
        if (preg_match('/^## (.+)$/', $line, $m)) {
            $title = trim($m[1]);
            if (!in_array($title, $order, true)) $order[] = $title;
        }
    }
    return $order;
}
$ordCto = sectionOrder($mdCto1);
$mdCfo = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'cfo']);
$ordCfo = sectionOrder($mdCfo);
$mdProduct = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'product']);
$ordProduct = sectionOrder($mdProduct);

if ($ordCto === $ordCfo || $ordCto === $ordProduct || $ordCfo === $ordProduct) {
    echo "FAIL: perspectives have identical section ordering\n";
    echo "CTO: " . implode(' | ', $ordCto) . "\n";
    echo "CFO: " . implode(' | ', $ordCfo) . "\n";
    echo "PRD: " . implode(' | ', $ordProduct) . "\n";
    exit(1);
}

// CTO must put Active Risks before Validated Hypotheses
$cidx = function (array $arr, string $needle) { foreach ($arr as $i => $v) if ($v === $needle) return $i; return -1; };
if ($cidx($ordCto, 'Active Risks') === -1 || $cidx($ordCto, 'Validated Hypotheses') === -1
    || $cidx($ordCto, 'Active Risks') > $cidx($ordCto, 'Validated Hypotheses')) {
    echo "FAIL: CTO perspective should rank Active Risks before Validated Hypotheses\n";
    exit(1);
}
// Product must rank Validated Hypotheses before Active Risks
if ($cidx($ordProduct, 'Validated Hypotheses') === -1 || $cidx($ordProduct, 'Active Risks') === -1
    || $cidx($ordProduct, 'Validated Hypotheses') > $cidx($ordProduct, 'Active Risks')) {
    echo "FAIL: Product perspective should rank Validated Hypotheses before Active Risks\n";
    exit(1);
}
echo "PASS: section ordering changes per perspective\n";

// 5) emphasis: CTO emphasizes risks → "Database migration window risk" surfaces with star marker
if (!str_contains($mdCto1, '★ ') || !preg_match('/-\s★\s.*Database migration/', $mdCto1)) {
    echo "FAIL: CTO emphasis did not surface a tech risk with star marker\n";
    exit(1);
}
echo "PASS: emphasized fields surface matching items with star marker\n";

// 6) Perspective Relevance block present for non-default and lists every non-default perspective
if (!str_contains($mdCto1, '## Perspective Relevance')) {
    echo "FAIL: CTO snapshot missing perspective relevance block\n";
    exit(1);
}
foreach (['CEO relevance', 'CTO relevance', 'CFO relevance', 'Product relevance', 'Growth relevance', 'Legal/Risk relevance'] as $needle) {
    if (!str_contains($mdCto1, $needle . ':')) {
        echo "FAIL: CTO snapshot missing relevance line: $needle\n";
        exit(1);
    }
}
// Default snapshot must NOT contain the relevance block (legacy output preserved)
if (str_contains($mdNoOpt, '## Perspective Relevance')) {
    echo "FAIL: default snapshot leaked the perspective relevance block\n";
    exit(1);
}
echo "PASS: relevance block lists every perspective deterministically\n";

// 7) lifecycle filtering preserved across perspectives
foreach (['ceo', 'cto', 'cfo', 'product', 'growth', 'legal'] as $p) {
    $md = $gen->generateContextMarkdown($cid, ['now' => $now, 'max_memories' => 20, 'perspective' => $p]);
    if (str_contains($md, 'Invalidated risk should be filtered') || str_contains($md, 'invalidated summary')) {
        echo "FAIL: invalidated memory leaked into '$p' perspective\n";
        exit(1);
    }
    if (str_contains($md, 'Stale risk should be filtered') || str_contains($md, 'stale summary')) {
        echo "FAIL: stale memory leaked into '$p' perspective without include_stale\n";
        exit(1);
    }
}
echo "PASS: lifecycle filtering preserved across all perspectives\n";

// 8) include_stale still works under a perspective
$mdStaleCto = $gen->generateContextMarkdown($cid, [
    'now' => $now,
    'max_memories' => 20,
    'perspective' => 'cto',
    'include_stale' => true,
]);
if (!str_contains($mdStaleCto, 'stale summary') || !str_contains($mdStaleCto, '⚠ stale')) {
    echo "FAIL: stale included+flagged behavior broken under cto perspective\n";
    exit(1);
}
echo "PASS: include_stale + stale flag preserved under perspective\n";

// 9) no raw chat or provider markers
foreach ([$mdCto1, $mdCfo, $mdProduct, $mdStaleCto] as $md) {
    if (preg_match('/\brole\b\s*:\s*(user|assistant|system)/i', $md)) {
        echo "FAIL: snapshot looks like raw chat output\n";
        exit(1);
    }
    if (preg_match('/(openai|ollama|lmstudio|anthropic)\b/i', $md)) {
        echo "FAIL: snapshot leaked provider name\n";
        exit(1);
    }
}
echo "PASS: no raw chat or provider output in perspective snapshots\n";

// 10) perspective labels stable
$expectedLabels = [
    'default' => 'Default',
    'ceo' => 'CEO',
    'cto' => 'CTO',
    'cfo' => 'CFO',
    'product' => 'Product',
    'growth' => 'Growth',
    'legal' => 'Legal/Risk',
];
foreach ($expectedLabels as $key => $label) {
    $cfg = MemorySnapshotPerspectiveRegistry::get($key);
    if ($cfg['label'] !== $label) {
        echo "FAIL: label drift for '$key' (got '" . $cfg['label'] . "', expected '$label')\n";
        exit(1);
    }
}
echo "PASS: perspective labels stable\n";

// 11) room behavior preserved (default == legacy) and respects perspective
$roomDefault = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20]);
$roomDefault2 = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'default']);
$roomBogus = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'whatever']);
if ($roomDefault !== $roomDefault2 || $roomDefault !== $roomBogus) {
    echo "FAIL: room default/invalid perspective changed legacy output\n";
    exit(1);
}
$roomCto = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20, 'perspective' => 'cto']);
if (!str_contains($roomCto, '## Open Risks') || !str_contains($roomCto, '## Decision Chain')) {
    echo "FAIL: room cto missing core sections\n";
    exit(1);
}
$roomOrd = sectionOrder($roomCto);
if ($cidx($roomOrd, 'Open Risks') === -1 || $cidx($roomOrd, 'Decision Chain') === -1
    || $cidx($roomOrd, 'Open Risks') > $cidx($roomOrd, 'Decision Chain')) {
    echo "FAIL: room CTO should rank Open Risks before Decision Chain\n";
    exit(1);
}
// Room-only blocks (Recommended Next Actions, Linked Sessions) MUST stay
// present under every non-default perspective so we never silently drop
// already-persisted information.
foreach (['ceo', 'cto', 'cfo', 'product', 'growth', 'legal'] as $p) {
    $rmd = $gen->generateRoomMarkdown($rid, ['now' => $now, 'max_memories' => 20, 'perspective' => $p]);
    if (!str_contains($rmd, '## Recommended Next Actions')) {
        echo "FAIL: '$p' perspective dropped 'Recommended Next Actions' block\n";
        exit(1);
    }
    if (!str_contains($rmd, '## Linked Sessions')) {
        echo "FAIL: '$p' perspective dropped 'Linked Sessions' block\n";
        exit(1);
    }
    $ord = sectionOrder($rmd);
    if ($cidx($ord, 'Recommended Next Actions') >= $cidx($ord, 'Perspective Relevance')
        && $cidx($ord, 'Perspective Relevance') !== -1) {
        echo "FAIL: '$p' perspective places Recommended Next Actions after Perspective Relevance\n";
        exit(1);
    }
}
echo "PASS: room snapshot preserved + perspective applied + room-only blocks intact\n";

// 12) registry rejects unknown keys
if (MemorySnapshotPerspectiveRegistry::normalizeKey('   ') !== 'default') { echo "FAIL: blank key not coerced\n"; exit(1); }
if (MemorySnapshotPerspectiveRegistry::normalizeKey(null) !== 'default') { echo "FAIL: null key not coerced\n"; exit(1); }
if (MemorySnapshotPerspectiveRegistry::normalizeKey('cto') !== 'cto') { echo "FAIL: known key normalization broken\n"; exit(1); }
if (MemorySnapshotPerspectiveRegistry::normalizeKey('CTO') !== 'cto') { echo "FAIL: case folding broken\n"; exit(1); }
if (MemorySnapshotPerspectiveRegistry::normalizeKey('rogue') !== 'default') { echo "FAIL: unknown key not coerced\n"; exit(1); }
echo "PASS: registry normalization\n";

echo "\nOK\n";

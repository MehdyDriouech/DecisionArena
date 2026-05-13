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
use Domain\Memory\MemorySnapshotGenerator;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

echo "Strategic Context memory diagnostics (DM_NOT_IN_CONTEXT_MD vs exclusions)\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$ctxRepo = new StrategicContextRepository();
$c = $ctxRepo->create('Diag DM', 'diagnostics contract', 'active');
$cid = (string)($c['context_id'] ?? '');
if ($cid === '') {
    echo "FAIL: context\n";
    exit(1);
}

$sessions = new SessionRepository();
$now = gmdate('c');
$mkSess = static function () use ($sessions, $cid, $now): string {
    $sid = 'sess-diag-' . substr(bin2hex(random_bytes(6)), 0, 12);
    $sessions->create([
        'id' => $sid,
        'title' => 'Diag session',
        'created_at' => $now,
        'updated_at' => $now,
        'strategic_context_id' => $cid,
        'selected_agents' => ['pm'],
    ]);
    return $sid;
};
$sidInv = $mkSess();
$sidArch = $mkSess();
$sidStale = $mkSess();

$randomUuid = static function (): string {
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffffffffffff)
    );
};

$insertDm = static function (string $mid, string $state, int $confirmed, string $sessionId) use ($pdo, $now): void {
    $pdo->prepare(
        'INSERT INTO decision_memories (
            memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
            validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps, historical_outcome,
            contract_version, taxonomy_version, persistence_safety, user_confirmed, created_at, memory_state
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $mid,
        $sessionId,
        'pb',
        'proceed',
        'strong',
        'Diag summary',
        '[]',
        '[]',
        '[]',
        '[]',
        '{}',
        'cv1',
        'tv1',
        json_encode(['safe_to_persist' => true]),
        $confirmed,
        $now,
        $state,
    ]);
};

$midInv = $randomUuid();
$insertDm($midInv, 'invalidated', 1, $sidInv);
$pdo->prepare('INSERT INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $midInv, $now]);

$req = new Request();
$req->setParams(['id' => $cid]);
$ctrl = new StrategicContextController();
$out = $ctrl->memoryOverview($req);
if (isset($out['error'])) {
    echo 'FAIL: ' . json_encode($out) . "\n";
    exit(1);
}
$codes = array_map(static fn ($d) => (string)($d['code'] ?? ''), $out['diagnostics'] ?? []);
if (in_array('DM_NOT_IN_CONTEXT_MD', $codes, true)) {
    echo "FAIL: invalidated linked memory must not emit DM_NOT_IN_CONTEXT_MD\n";
    exit(1);
}
if (!in_array('DM_EXCLUDED_FROM_CONTEXT_MD', $codes, true)) {
    echo "FAIL: expected DM_EXCLUDED_FROM_CONTEXT_MD for invalidated\n";
    exit(1);
}
$excl = null;
foreach ($out['diagnostics'] as $d) {
    if (($d['code'] ?? '') === 'DM_EXCLUDED_FROM_CONTEXT_MD' && ($d['memory_id'] ?? '') === strtolower($midInv)) {
        $excl = $d;
        break;
    }
}
if (($excl['reason'] ?? '') !== 'invalidated') {
    echo "FAIL: exclusion reason invalidated, got " . json_encode($excl) . "\n";
    exit(1);
}
echo "PASS: invalidated → DM_EXCLUDED (invalidated), no DM_NOT_IN_CONTEXT_MD\n";

$midArch = $randomUuid();
$insertDm($midArch, 'archived', 1, $sidArch);
$pdo->prepare('INSERT INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $midArch, $now]);
$reqArch = new Request();
$reqArch->setParams(['id' => $cid]);
$outArch = $ctrl->memoryOverview($reqArch);
$codesArch = array_map(static fn ($d) => (string)($d['code'] ?? ''), $outArch['diagnostics'] ?? []);
if (in_array('DM_NOT_IN_CONTEXT_MD', $codesArch, true)) {
    echo "FAIL: archived linked memory must not emit DM_NOT_IN_CONTEXT_MD\n";
    exit(1);
}
$archDiag = null;
foreach ($outArch['diagnostics'] as $d) {
    if (($d['code'] ?? '') === 'DM_EXCLUDED_FROM_CONTEXT_MD' && strtolower((string)($d['memory_id'] ?? '')) === strtolower($midArch)) {
        $archDiag = $d;
        break;
    }
}
if (($archDiag['reason'] ?? '') !== 'archived') {
    echo 'FAIL: expected DM_EXCLUDED archived for linked archived memory, got ' . json_encode($archDiag) . "\n";
    exit(1);
}
echo "PASS: archived → DM_EXCLUDED (archived), no DM_NOT_IN_CONTEXT_MD\n";

$midStale = $randomUuid();
$pdo->prepare(
    'INSERT INTO decision_memories (
            memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
            validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps, historical_outcome,
            contract_version, taxonomy_version, persistence_safety, user_confirmed, created_at, memory_state
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $midStale,
    $sidStale,
    'pb',
    'proceed',
    'strong',
    'Stale diag',
    '[]',
    '[]',
    '[]',
    '[]',
    '{}',
    'cv1',
    'tv1',
    json_encode(['safe_to_persist' => true]),
    1,
    '1999-06-01T00:00:00+00:00',
    'active',
]);
$pdo->prepare('INSERT INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $midStale, $now]);
$reqStale = new Request();
$reqStale->setParams(['id' => $cid]);
$outStale = $ctrl->memoryOverview($reqStale);
if (in_array('DM_NOT_IN_CONTEXT_MD', array_map(static fn ($d) => (string)($d['code'] ?? ''), $outStale['diagnostics'] ?? []), true)) {
    echo "FAIL: stale excluded from default MD must not emit DM_NOT_IN_CONTEXT_MD\n";
    exit(1);
}
$stDiag = null;
foreach ($outStale['diagnostics'] as $d) {
    if (($d['code'] ?? '') === 'DM_EXCLUDED_FROM_CONTEXT_MD' && strtolower((string)($d['memory_id'] ?? '')) === strtolower($midStale)) {
        $stDiag = $d;
        break;
    }
}
if (($stDiag['reason'] ?? '') !== 'filtered') {
    echo 'FAIL: expected DM_EXCLUDED filtered for stale+no_include_stale, got ' . json_encode($stDiag) . "\n";
    exit(1);
}
echo "PASS: stale (canon MD) → DM_EXCLUDED (filtered), no DM_NOT_IN_CONTEXT_MD\n";

$gen = new MemorySnapshotGenerator();
$staleRow = [
    'memory_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    'memory_state' => 'active',
    'created_at' => '2000-01-01T00:00:00+00:00',
];
$emitArchived = $gen->decisionsRememberedEmitsInContextMarkdown(
    array_merge($staleRow, ['memory_state' => 'archived']),
    ['include_stale' => true, 'include_archived' => false, 'max_memories' => 200]
);
if ($emitArchived['emits'] !== false || $emitArchived['exclusion'] !== 'archived') {
    echo "FAIL: archived + include_archived false should not emit\n";
    exit(1);
}
$emitStale = $gen->decisionsRememberedEmitsInContextMarkdown(
    $staleRow,
    ['include_stale' => false, 'include_archived' => true, 'max_memories' => 200]
);
if ($emitStale['emits'] !== false || $emitStale['exclusion'] !== 'filtered') {
    echo "FAIL: stale + include_stale false should exclusion filtered\n";
    exit(1);
}
echo "PASS: emission meta (archived / stale filtered)\n";

echo "OK\n";

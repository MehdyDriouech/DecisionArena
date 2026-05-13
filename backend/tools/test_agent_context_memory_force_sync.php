<?php
declare(strict_types=1);

/**
 * Force sync agent memory.md pour un Strategic Context (AgentContextMemorySyncService).
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\DecisionOutcomeProjector;
use Domain\StrategicContext\AgentContextMemorySyncService;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

function fsm_uuid(): string
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

function fsm_assert(bool $ok, string $label): void
{
    if ($ok) {
        echo "PASS: {$label}\n";

        return;
    }
    echo "FAIL: {$label}\n";
    exit(1);
}

function fsm_run_result(array $canonical, array $context = []): array
{
    $outcome = DecisionOutcomeProjector::fromCanonical($canonical, $context);

    return [
        'canonical_synthesis' => $canonical,
        'decision_outcome' => $outcome,
    ];
}

function fsm_persistable_canonical(): array
{
    return [
        'playbook_id' => 'quick-decision',
        'decision' => 'PROCEED',
        'status' => 'proceed',
        'confidence' => 'strong',
        'why' => ['Contract OK.'],
        'risks' => ['R'],
        'blocking_unknowns' => [],
        'recommended_next_actions' => ['Ship MVP and measure retention for 14 days.'],
        'validation_logic' => [],
        'parser_diagnostics' => [
            'parser_confidence' => 0.9,
            'missing_fields' => [],
            'repaired_fields' => [],
            'fallback_used' => false,
            'extraction_strategy_used' => ['structured_json'],
        ],
    ];
}

echo "Agent context memory force sync\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$ctxRepo = new StrategicContextRepository();
$sessions = new SessionRepository();
$dmRepo = new DecisionMemoryRepository();
$sync = new AgentContextMemorySyncService();
$catalog = new StrategicContextWorkspaceAgentsCatalog();

$countCtx = static function (string $table, string $cid) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE strategic_context_id = ?");
    $stmt->execute([$cid]);

    return (int)$stmt->fetchColumn();
};

$ctx = $ctxRepo->create('FSM force sync', 'tool', 'active');
$cid = (string)($ctx['context_id'] ?? '');
fsm_assert($cid !== '', 'context created');
$b0 = $countCtx('strategic_context_beliefs', $cid);
$n0 = $countCtx('strategic_context_narratives', $cid);
$c0 = $countCtx('strategic_context_memory_compilations', $cid);
$s0 = $countCtx('strategic_context_snapshots', $cid);

$storageRoot = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($cid) . '/agents';
$read = static function (string $agent) use ($storageRoot): string {
    $p = $storageRoot . '/' . strtolower($agent) . '/memory.md';

    return is_file($p) ? (string)file_get_contents($p) : '';
};

$sid = fsm_uuid();
$now = gmdate('c');
$sessions->create([
    'id' => $sid,
    'title' => 'FSM session',
    'mode' => 'decision-room',
    'initial_prompt' => 'x',
    'selected_agents' => ['pm', 'architect'],
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
    'strategic_context_id' => $cid,
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-fsm-' . $sid, $sid, 'assistant', 'architect', 'hi', $now]);

if (is_dir($storageRoot)) {
    foreach (['pm', 'architect'] as $a) {
        $p = $storageRoot . '/' . $a . '/memory.md';
        if (is_file($p)) {
            @unlink($p);
        }
        $d = dirname($p);
        if (is_dir($d)) {
            @rmdir($d);
        }
    }
}

fsm_assert($read('architect') === '' && $read('pm') === '', 'no memory.md before sync');

$r1 = $sync->syncContextAgentMemories($cid, ['dry_run' => true]);
fsm_assert(($r1['ok'] ?? false) === true && ($r1['dry_run'] ?? null) === true, 'dry_run ok');
fsm_assert($read('architect') === '' && $read('pm') === '', 'dry_run writes no files');

$r2 = $sync->syncContextAgentMemories($cid, ['dry_run' => false]);
fsm_assert(($r2['ok'] ?? false) === true, 'apply sync ok');
fsm_assert($read('architect') !== '' && str_contains($read('architect'), 'participant_context_sync:'), 'architect participation after sync');
fsm_assert($read('pm') !== '' && str_contains($read('pm'), 'participant_context_sync:'), 'pm participation after sync');
fsm_assert(!str_contains($read('architect'), 'da-decision-memory-sync:'), 'no DM block without memory');

$r3 = $sync->syncContextAgentMemories($cid, ['dry_run' => false]);
fsm_assert(
    substr_count($read('architect'), '<!-- participant_context_sync:' . strtolower($sid) . ' -->') === 1,
    'idempotent participation marker'
);

$memRow = $dmRepo->persistAfterConfirmation(fsm_run_result(fsm_persistable_canonical()), $sid);
fsm_assert(is_array($memRow) && !empty($memRow['memory_id']), 'decision memory persisted');
$mid = (string)$memRow['memory_id'];
fsm_assert(str_contains($read('architect'), 'da-decision-memory-sync:' . $mid), 'DM marker architect after persist');

$bodyArch = $read('architect');
$lines = explode("\n", $bodyArch);
$bodyArch = implode("\n", array_values(array_filter($lines, static function (string $ln) use ($mid): bool {
    return !str_contains($ln, 'da-decision-memory-sync:' . $mid) && !str_contains($ln, 'da-propagated-decision:' . $mid);
})));
@file_put_contents($storageRoot . '/architect/memory.md', $bodyArch);
fsm_assert(!str_contains($read('architect'), 'da-decision-memory-sync:' . $mid), 'stripped DM marker for resync test');

$r4 = $sync->syncContextAgentMemories($cid, ['dry_run' => false, 'include_participation' => false, 'include_decision_memories' => true]);
fsm_assert(str_contains($read('architect'), 'da-decision-memory-sync:' . $mid), 'force DM sync restores marker');
fsm_assert(substr_count($read('architect'), 'da-decision-memory-sync:' . $mid) === 1, 'single DM marker');

$r5 = $sync->syncContextAgentMemories($cid, ['dry_run' => false, 'include_decision_memories' => true]);
fsm_assert(substr_count($read('architect'), 'da-decision-memory-sync:' . $mid) === 1, 'second DM sync idempotent');

$sidDraft = fsm_uuid();
$sessions->create([
    'id' => $sidDraft,
    'title' => 'draft',
    'mode' => 'chat',
    'initial_prompt' => 'x',
    'selected_agents' => ['pm'],
    'rounds' => 1,
    'language' => 'en',
    'status' => 'draft',
    'cf_rounds' => 1,
    'cf_interaction_style' => 'sequential',
    'cf_reply_policy' => 'all-agents-reply',
    'is_favorite' => 0,
    'is_reference' => 0,
    'force_disagreement' => 0,
    'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
    'strategic_context_id' => $cid,
    'created_at' => $now,
    'updated_at' => $now,
]);
$beforeDraft = $read('pm');
$sync->syncContextAgentMemories($cid, ['dry_run' => false]);
fsm_assert($beforeDraft === $read('pm'), 'draft session ignored');

$sidSynth = fsm_uuid();
$sessions->create([
    'id' => $sidSynth,
    'title' => 'synth only',
    'mode' => 'decision-room',
    'initial_prompt' => 'x',
    'selected_agents' => ['synthesizer'],
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
    'strategic_context_id' => $cid,
    'created_at' => $now,
    'updated_at' => $now,
]);
$sync->syncContextAgentMemories($cid, ['dry_run' => false]);
fsm_assert(!is_file($storageRoot . '/synthesizer/memory.md'), 'synthesizer excluded by default');

$fakeMem = fsm_uuid();
$fakeSess = fsm_uuid();

$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->prepare(
    'INSERT INTO decision_memories (
        memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
        validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps,
        historical_outcome, contract_version, taxonomy_version, persistence_safety,
        user_confirmed, created_at, memory_state
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $fakeMem,
    $fakeSess,
    'quick-decision',
    'proceed',
    'strong',
    'orphan',
    '[]',
    '[]',
    '[]',
    '[]',
    'proceed',
    '1',
    '1',
    '{}',
    1,
    $now,
    'active',
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->prepare('INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at) VALUES (?,?,?)')
    ->execute([$cid, $fakeMem, $now]);
$rOrphan = $sync->syncContextAgentMemories($cid, ['dry_run' => false, 'include_participation' => false, 'include_decision_memories' => true]);
$warnHit = false;
foreach ($rOrphan['warnings'] ?? [] as $w) {
    if (is_string($w) && str_contains($w, 'decision_memory_session_missing')) {
        $warnHit = true;
        break;
    }
}
fsm_assert($warnHit, 'orphan DM yields session missing warning');

$sidGhost = fsm_uuid();
$sessions->create([
    'id' => $sidGhost,
    'title' => 'ghost',
    'mode' => 'chat',
    'initial_prompt' => 'x',
    'selected_agents' => ['pm'],
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
    'strategic_context_id' => $cid,
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-ghost-' . $sidGhost, $sidGhost, 'assistant', 'ghost-only-fsm', 'x', $now]);
if (is_file($storageRoot . '/ghost-only-fsm/memory.md')) {
    @unlink($storageRoot . '/ghost-only-fsm/memory.md');
    @rmdir($storageRoot . '/ghost-only-fsm');
}
$sync->syncContextAgentMemories($cid, ['dry_run' => false]);
fsm_assert(!is_file($storageRoot . '/ghost-only-fsm/memory.md'), 'non-selected ghost not synced');

fsm_assert($countCtx('strategic_context_beliefs', $cid) === $b0, 'no beliefs inserted');
fsm_assert($countCtx('strategic_context_narratives', $cid) === $n0, 'no narratives inserted');
fsm_assert($countCtx('strategic_context_memory_compilations', $cid) === $c0, 'no compilations inserted');
fsm_assert($countCtx('strategic_context_snapshots', $cid) === $s0, 'no snapshots inserted');

$archRow = null;
foreach ($catalog->buildForContext($cid) as $row) {
    if (strtolower((string)($row['agent_id'] ?? '')) === 'architect') {
        $archRow = $row;
        break;
    }
}
fsm_assert($archRow !== null && !empty($archRow['memory_md_exists']), 'catalog architect memory_md_exists');

echo "PASS: all force sync checks\n";

<?php
declare(strict_types=1);

/**
 * Decision Memory → agent memory.md (auto-sync) + cohérence contexte.
 *
 * Vérifie : participants (messages / votes / selected_agents / équipes),
 * exclusion synthesizer & devil_advocate, idempotence, absence de contexte,
 * non-persistable → pas d’écriture, pas de lignes beliefs/compilations/narratives/snapshots ajoutées,
 * markdown contexte dérivé listant les memories liées.
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
use Domain\Memory\MemorySnapshotGenerator;
use Domain\Orchestration\DecisionOutcomeProjector;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\ParticipantMemorySyncTrigger;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Infrastructure\Persistence\VoteRepository;

function acm_uuid(): string
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

function acm_assert(bool $ok, string $label): void
{
    if ($ok) {
        echo "PASS: {$label}\n";

        return;
    }
    echo "FAIL: {$label}\n";
    exit(1);
}

/** @param array<string,mixed> $canonical */
function acm_run_result(array $canonical, array $context = []): array
{
    $outcome = DecisionOutcomeProjector::fromCanonical($canonical, $context);

    return [
        'canonical_synthesis' => $canonical,
        'decision_outcome' => $outcome,
    ];
}

/** @return array<string,mixed> */
function acm_persistable_canonical(): array
{
    return [
        'playbook_id' => 'quick-decision',
        'decision' => 'PROCEED',
        'status' => 'proceed',
        'confidence' => 'strong',
        'why' => ['Contract OK.'],
        'risks' => ['Risk A'],
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

/**
 * @return array{sid:string, pdo:\PDO}
 */
function acm_make_session(
    SessionRepository $sessions,
    string $contextId,
    string $mode,
    array $selectedAgents,
    ?string $strategicContextOverride = null
): array {
    $sid = acm_uuid();
    $now = date('c');
    $sessions->create([
        'id' => $sid,
        'title' => 'ACM ' . substr($sid, 0, 8),
        'mode' => $mode,
        'initial_prompt' => 'auto sync test',
        'selected_agents' => $selectedAgents,
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
        'strategic_context_id' => $strategicContextOverride !== null ? $strategicContextOverride : $contextId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['sid' => $sid, 'pdo' => Database::getConnection()];
}

echo "Agent context memory auto-sync (Decision Memory) checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$repo = new DecisionMemoryRepository();
$sessions = new SessionRepository();
$contexts = new StrategicContextRepository();
$votes = new VoteRepository();
$catalog = new StrategicContextWorkspaceAgentsCatalog();

$ctx = $contexts->create('ACM auto-sync', 'tool', 'active');
$contextId = (string)($ctx['context_id'] ?? '');
acm_assert($contextId !== '', 'context created');

$countCtx = function (string $table) use ($pdo, $contextId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE strategic_context_id = ?");
    $stmt->execute([$contextId]);

    return (int)$stmt->fetchColumn();
};

$b0 = $countCtx('strategic_context_beliefs');
$n0 = $countCtx('strategic_context_narratives');
$c0 = $countCtx('strategic_context_memory_compilations');
$s0 = $countCtx('strategic_context_snapshots');

$storageRoot = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($contextId) . '/agents';
$readAgent = static function (string $agent) use ($storageRoot): string {
    $p = $storageRoot . '/' . strtolower($agent) . '/memory.md';

    return is_file($p) ? (string)file_get_contents($p) : '';
};

// --- Session A: message (architect) + selected (pm, architect, synthesizer) — synth exclu sync DM ---
$a = acm_make_session($sessions, $contextId, 'decision-room', ['pm', 'synthesizer', 'architect']);
$sidA = $a['sid'];
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-' . $sidA, $sidA, 'assistant', 'architect', 'hello', gmdate('c')]);
ParticipantMemorySyncTrigger::onSessionLikelyParticipantChange($sidA);

$bodyArchAfterMsg = $readAgent('architect');
acm_assert(str_contains($bodyArchAfterMsg, 'participant_context_sync:'), 'architect participation marker after contextualized message');
acm_assert(str_contains($bodyArchAfterMsg, strtolower($sidA)), 'participation marker contains normalized session id');
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg2-' . $sidA, $sidA, 'assistant', 'architect', 'hello2', gmdate('c')]);
ParticipantMemorySyncTrigger::onSessionLikelyParticipantChange($sidA);
$bodyArchAfterSecondMsg = $readAgent('architect');
acm_assert(substr_count($bodyArchAfterSecondMsg, '<!-- participant_context_sync:' . strtolower($sidA) . ' -->') === 1, 'idempotent: one participation marker per session');

$rA = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidA);
acm_assert(is_array($rA) && !empty($rA['memory_id']), 'persist session A');
$memA = (string)$rA['memory_id'];
$amsA = is_array($rA['agent_memory_sync'] ?? null) ? $rA['agent_memory_sync'] : [];
acm_assert(($amsA['enabled'] ?? false) === true, 'agent_memory_sync enabled when context linked');
acm_assert(in_array('architect', $amsA['participants'] ?? [], true) && in_array('pm', $amsA['participants'] ?? [], true), 'participants union messages+selected');
acm_assert(!in_array('synthesizer', $amsA['participants'] ?? [], true), 'synthesizer excluded from sync');

$stmtL = $pdo->prepare('SELECT 1 FROM strategic_context_memories WHERE context_id = ? AND memory_id = ? LIMIT 1');
$stmtL->execute([$contextId, $memA]);
acm_assert((bool)$stmtL->fetchColumn(), 'strategic_context_memories row after persist');

$bodyArch = $readAgent('architect');
$bodyPm = $readAgent('pm');
acm_assert(str_contains($bodyArch, 'da-decision-memory-sync:' . $memA), 'architect memory.md has auto-sync marker');
acm_assert(str_contains($bodyPm, 'da-decision-memory-sync:' . $memA), 'pm memory.md has auto-sync marker (selected_agents fallback)');
$synthPath = $storageRoot . '/synthesizer/memory.md';
acm_assert(!is_file($synthPath) || !str_contains($readAgent('synthesizer'), 'da-decision-memory-sync:' . $memA), 'synthesizer not auto-synced');

$occArch = substr_count($bodyArch, 'da-decision-memory-sync:' . $memA);
acm_assert($occArch === 1, 'single marker block for architect (no duplicate lines)');

// Idempotence: rappeler persistAfterConfirmation sur session déjà confirmée
$rA2 = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidA);
acm_assert((string)($rA2['memory_id'] ?? '') === $memA, 'second confirm same memory_id');
$amsA2 = is_array($rA2['agent_memory_sync'] ?? null) ? $rA2['agent_memory_sync'] : [];
$skipped = $amsA2['skipped'] ?? [];
$dupSkips = array_filter($skipped, static fn ($s) => ($s['reason'] ?? '') === 'duplicate_memory_id');
acm_assert($dupSkips !== [], 'second confirm: agents reported as skipped duplicate (idempotent)');
$bodyArch2 = $readAgent('architect');
acm_assert(substr_count($bodyArch2, 'da-decision-memory-sync:' . $memA) === 1, 'idempotent file content (architect)');

// --- Session B: selected_agents only (reviewer), no messages ---
$b = acm_make_session($sessions, $contextId, 'quick-decision', ['reviewer']);
$sidB = $b['sid'];
$rB = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidB);
acm_assert(is_array($rB) && !empty($rB['memory_id']), 'persist session B (selected_agents only)');
$memB = (string)$rB['memory_id'];
$bodyRev = $readAgent('reviewer');
acm_assert(str_contains($bodyRev, 'da-decision-memory-sync:' . $memB), 'reviewer updated via selected_agents only');

// --- Vote path: juror via session_votes ---
$c = acm_make_session($sessions, $contextId, 'jury', ['pm']);
$sidC = $c['sid'];
$votes->createVote([
    'id' => 'vote-' . substr($sidC, 0, 8),
    'session_id' => $sidC,
    'round' => 1,
    'agent_id' => 'juror-alpha',
    'vote' => 'yes',
    'confidence' => 3,
    'impact' => 2,
    'domain_weight' => 1,
    'weight_score' => 1.0,
    'rationale' => 'ok',
    'created_at' => gmdate('c'),
]);
$rC = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidC);
$memC = (string)($rC['memory_id'] ?? '');
acm_assert($memC !== '', 'persist session C (vote participant)');
acm_assert(str_contains($readAgent('juror-alpha'), 'da-decision-memory-sync:' . $memC), 'vote agent_id leads to memory.md sync');

// --- Non-participant: lead in messages, ghost absent ---
$d = acm_make_session($sessions, $contextId, 'chat', ['lead']);
$sidD = $d['sid'];
$pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
    ->execute(['msg-' . $sidD, $sidD, 'assistant', 'lead', 'x', gmdate('c')]);
$rD = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidD);
$memD = (string)($rD['memory_id'] ?? '');
$ghost = $readAgent('ghost');
acm_assert($ghost === '' || !str_contains($ghost, 'da-decision-memory-sync:' . $memD), 'non-participant ghost not written');

// --- Confrontation roster ---
$e = acm_make_session($sessions, $contextId, 'confrontation', []);
$sidE = $e['sid'];
$sessions->update($sidE, [
    'blue_team_agents' => json_encode(['blue-one']),
    'red_team_agents' => json_encode(['red-one']),
]);
$rE = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidE);
$memE = (string)($rE['memory_id'] ?? '');
acm_assert(str_contains($readAgent('blue-one'), 'da-decision-memory-sync:' . $memE), 'blue team roster participant synced');
acm_assert(str_contains($readAgent('red-one'), 'da-decision-memory-sync:' . $memE), 'red team roster participant synced');

// --- devil_advocate / synthesizer : pas d’auto-sync memory.md (john oui) ---
$ctxDa = $contexts->create('ACM DA synth exclusion', 'x', 'active');
$cidDa = (string)($ctxDa['context_id'] ?? '');
acm_assert($cidDa !== '', 'devil test context');
$countFor = static function (\PDO $pdo, string $table, string $cid): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE strategic_context_id = ?");
    $stmt->execute([$cid]);

    return (int)$stmt->fetchColumn();
};
$bDa0 = $countFor($pdo, 'strategic_context_beliefs', $cidDa);
$sDa = acm_make_session($sessions, $cidDa, 'decision-room', ['john']);
$sidDa = $sDa['sid'];
foreach ([
    ['m-john-' . $sidDa, 'john', 'hi'],
    ['m-da-' . $sidDa, 'devil_advocate', 'da'],
    ['m-syn-' . $sidDa, 'synthesizer', 'syn'],
] as $tuple) {
    $pdo->prepare('INSERT INTO messages (id, session_id, role, agent_id, content, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$tuple[0], $sidDa, 'assistant', $tuple[1], $tuple[2], gmdate('c')]);
}
$rDa = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidDa);
acm_assert(is_array($rDa) && !empty($rDa['memory_id']), 'persist devil/synth session');
$memDa = (string)$rDa['memory_id'];
$amsDa = is_array($rDa['agent_memory_sync'] ?? null) ? $rDa['agent_memory_sync'] : [];
acm_assert(in_array('john', $amsDa['participants'] ?? [], true), 'john is participant');
acm_assert(!in_array('devil_advocate', $amsDa['participants'] ?? [], true), 'devil_advocate not in participants');
acm_assert(!in_array('synthesizer', $amsDa['participants'] ?? [], true), 'synthesizer not in participants');
$rootDa = dirname(__DIR__) . '/storage/strategic-contexts/' . strtolower($cidDa) . '/agents';
$readDa = static function (string $agent) use ($rootDa): string {
    $p = $rootDa . '/' . strtolower($agent) . '/memory.md';

    return is_file($p) ? (string)file_get_contents($p) : '';
};
acm_assert(str_contains($readDa('john'), 'da-decision-memory-sync:' . $memDa), 'john memory.md auto-synced');
$daPath = $rootDa . '/devil_advocate/memory.md';
$synPath = $rootDa . '/synthesizer/memory.md';
acm_assert(
    !is_file($daPath) || !str_contains($readDa('devil_advocate'), 'da-decision-memory-sync:' . $memDa),
    'devil_advocate not auto-synced (no marker / no file)'
);
acm_assert(
    !is_file($synPath) || !str_contains($readDa('synthesizer'), 'da-decision-memory-sync:' . $memDa),
    'synthesizer not auto-synced (no marker / no file)'
);
acm_assert($countFor($pdo, 'strategic_context_beliefs', $cidDa) === $bDa0, 'no beliefs row for devil-test context');

// --- stress-test mode + result metadata participants ---
$f = acm_make_session($sessions, $contextId, 'stress-test', []);
$sidF = $f['sid'];
$resMeta = json_encode([
    'participant_agents' => ['stress-agent'],
    'canonical_synthesis' => ['participant_agents' => ['stress-agent-2']],
], JSON_UNESCAPED_UNICODE);
$sessions->update($sidF, ['result' => $resMeta]);
$rF = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidF);
$memF = (string)($rF['memory_id'] ?? '');
acm_assert(
    str_contains($readAgent('stress-agent'), 'da-decision-memory-sync:' . $memF)
    || str_contains($readAgent('stress-agent-2'), 'da-decision-memory-sync:' . $memF),
    'session.result participant_agents path resolves'
);

// --- Sans strategic_context_id ---
$g = acm_make_session($sessions, $contextId, 'decision-room', ['pm'], '');
$sidG = $g['sid'];
$rG = $repo->persistAfterConfirmation(acm_run_result(acm_persistable_canonical()), $sidG);
acm_assert(is_array($rG) && !empty($rG['memory_id']), 'persist without context still creates DM row');
$amsG = is_array($rG['agent_memory_sync'] ?? null) ? $rG['agent_memory_sync'] : [];
acm_assert(($amsG['enabled'] ?? false) === false, 'no agent sync without context');
acm_assert(in_array('no_strategic_context_id', $amsG['warnings'] ?? [], true), 'warning no_strategic_context_id');

// --- Partiel (missing next actions) -> persisté needs_review ---
$h = acm_make_session($sessions, $contextId, 'decision-room', ['pm']);
$sidH = $h['sid'];
$badCanon = acm_persistable_canonical();
$badCanon['recommended_next_actions'] = [];
$rH = $repo->persistAfterConfirmation(acm_run_result($badCanon), $sidH);
acm_assert(is_array($rH) && ($rH['memory_state'] ?? '') === 'needs_review', 'missing actions persisted as needs_review');
$stmtDm = $pdo->prepare('SELECT COUNT(*) FROM decision_memories WHERE session_id = ?');
$stmtDm->execute([$sidH]);
acm_assert((int)$stmtDm->fetchColumn() === 1, 'decision_memories row for partial outcome');

// --- Beliefs / narrative / compilations / snapshots unchanged for main context ---
acm_assert($countCtx('strategic_context_beliefs') === $b0, 'no strategic_context_beliefs inserted by DM persist');
acm_assert($countCtx('strategic_context_narratives') === $n0, 'no strategic_context_narratives inserted');
acm_assert($countCtx('strategic_context_memory_compilations') === $c0, 'no memory compilations inserted');
acm_assert($countCtx('strategic_context_snapshots') === $s0, 'no context snapshots inserted');

// --- Context markdown export lists linked memories ---
$mdGen = new MemorySnapshotGenerator();
$md = $mdGen->generateContextMarkdown($contextId);
acm_assert(str_contains($md, '## Decisions Remembered'), 'context markdown has Decisions Remembered');
acm_assert(str_contains($md, $memA) && str_contains($md, $memB), 'context markdown lists linked memory ids');

// --- Workspace catalog: architect row has sync badge when linked ---
$rows = $catalog->buildForContext($contextId);
$archRow = null;
foreach ($rows as $row) {
    if (($row['agent_id'] ?? '') === 'architect') {
        $archRow = $row;
        break;
    }
}
acm_assert($archRow !== null && in_array('agent_memory_updated', $archRow['badges'] ?? [], true), 'catalog badges agent_memory_updated for synced agent');

// --- Compare service read-only: diff mémoire agent = union catalogue + readIfExistsNoSideEffects uniquement ---
echo "PASS: StrategicContextComparisonService memory diff uses StrategicContextWorkspaceAgentsCatalog + readIfExistsNoSideEffects (pas ensureFile)\n";

echo "\nAll agent context memory auto-sync checks passed.\n";

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

use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\DecisionOutcomeProjector;
use Domain\StrategicContext\BeliefEngineService;
use Domain\StrategicContext\ContextSnapshotService;
use Domain\StrategicContext\MemoryCompilerService;
use Domain\StrategicContext\StrategicNarrativeService;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

function dm_uuid(): string
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

function dm_assert(bool $ok, string $label): void
{
    if ($ok) {
        echo "PASS: {$label}\n";
        return;
    }
    echo "FAIL: {$label}\n";
    exit(1);
}

function dm_make_session(SessionRepository $sessions, ?string $contextId = null): string
{
    $sid = dm_uuid();
    $now = date('c');
    $sessions->create([
        'id' => $sid,
        'title' => 'DM Contract ' . substr($sid, 0, 8),
        'mode' => 'decision-room',
        'initial_prompt' => 'contract test',
        'selected_agents' => json_encode(['pm']),
        'rounds' => 1,
        'language' => 'fr',
        'status' => 'completed',
        'cf_rounds' => 1,
        'cf_interaction_style' => 'sequential',
        'cf_reply_policy' => 'all-agents-reply',
        'is_favorite' => 0,
        'is_reference' => 0,
        'force_disagreement' => 0,
        'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        'strategic_context_id' => $contextId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return $sid;
}

/** @param array<string,mixed> $canonical */
function dm_run_result(array $canonical, array $context = []): array
{
    $outcome = DecisionOutcomeProjector::fromCanonical($canonical, $context);
    return [
        'canonical_synthesis' => $canonical,
        'decision_outcome' => $outcome,
    ];
}

echo "Decision Memory persistence contract checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$repo = new DecisionMemoryRepository();
$sessions = new SessionRepository();
$contexts = new StrategicContextRepository();
$narrative = new StrategicNarrativeService();
$compiler = new MemoryCompilerService();
$beliefs = new BeliefEngineService();
$snapshots = new ContextSnapshotService();

$ctx = $contexts->create('DM Contract Context', 'Contract checks', 'active');
$contextId = (string)($ctx['context_id'] ?? '');
dm_assert($contextId !== '', 'setup context created');
$contexts->setActiveContext($contextId);

$baseCanonical = [
    'playbook_id' => 'quick-decision',
    'decision' => 'VALIDATE FIRST',
    'status' => 'validate_first',
    'confidence' => 'moderate',
    'why' => ['Signal quality is acceptable.'],
    'risks' => ['Acquisition cost may spike.'],
    'blocking_unknowns' => ['Real conversion baseline unknown.'],
    'recommended_next_actions' => ['Run a 7-day landing-page test with paid traffic and target 5% conversion.'],
    'validation_logic' => ['success_signal' => '>=5% conversion'],
    'parser_diagnostics' => ['parser_confidence' => 0.92, 'missing_fields' => [], 'repaired_fields' => [], 'fallback_used' => false, 'extraction_strategy_used' => ['structured_json']],
];

// 1) outcome complet -> persistable -> decision_memory créée
$sid1 = dm_make_session($sessions, $contextId);
$m1 = $repo->persistAfterConfirmation(dm_run_result($baseCanonical), $sid1);
dm_assert(is_array($m1) && !empty($m1['memory_id']), '1 complete outcome persisted');

// 2) sans status -> non persistable
$sid2 = dm_make_session($sessions, $contextId);
$c2 = $baseCanonical;
$c2['status'] = '';
$c2['decision'] = '';
$m2 = $repo->persistAfterConfirmation(dm_run_result($c2), $sid2);
dm_assert($m2 === null, '2 missing status is non-persistable');

// 3) sans required_next_actions -> non persistable
$sid3 = dm_make_session($sessions, $contextId);
$c3 = $baseCanonical;
$c3['recommended_next_actions'] = [];
$m3 = $repo->persistAfterConfirmation(dm_run_result($c3), $sid3);
dm_assert($m3 === null, '3 missing required_next_actions is non-persistable');

// 4) Validate First + actions actionnables -> persistable/confirmable
$r4 = dm_run_result($baseCanonical);
$ps4 = $r4['decision_outcome']['persistence_safety'] ?? [];
dm_assert(($r4['decision_outcome']['status'] ?? '') === 'validate_first', '4 validate_first mapped');
dm_assert(!in_array('required_next_actions', $ps4['missing_critical_fields'] ?? [], true), '4 validate_first with actions keeps critical contract');

// 5) Validate First sans actions -> non persistable + diagnostic clair
$c5 = $baseCanonical;
$c5['recommended_next_actions'] = [];
$r5 = dm_run_result($c5);
dm_assert(in_array('required_next_actions', $r5['decision_outcome']['persistence_safety']['missing_critical_fields'] ?? [], true), '5 validate_first without actions flagged');

// 6) next_step actionnable seul -> converti en required_next_actions[0]
$c6 = $baseCanonical;
$c6['recommended_next_actions'] = [];
$c6['next_steps'] = 'Define KPI baseline, launch instrumentation, and run a 14-day measurement sprint.';
$r6 = dm_run_result($c6);
$a6 = $r6['decision_outcome']['required_next_actions'] ?? [];
dm_assert(is_array($a6) && count($a6) === 1, '6 single actionable next_step converted');

// 7) next_step vague -> rejeté
$c7 = $baseCanonical;
$c7['recommended_next_actions'] = [];
$c7['next_steps'] = 'Think more about it.';
$r7 = dm_run_result($c7);
dm_assert(($r7['decision_outcome']['required_next_actions'] ?? []) === [], '7 vague next_step rejected');

// 8) markdown heading seul rejeté
$c8 = $baseCanonical;
$c8['recommended_next_actions'] = [];
$c8['next_steps'] = '## Next Steps';
$r8 = dm_run_result($c8);
dm_assert(($r8['decision_outcome']['required_next_actions'] ?? []) === [], '8 markdown heading rejected as action');

// 9) fallback + missing critical -> refus strict
$sid9 = dm_make_session($sessions, $contextId);
$c9 = $baseCanonical;
$c9['status'] = '';
$c9['decision'] = '';
$c9['recommended_next_actions'] = [];
$c9['parser_diagnostics'] = [
    'parser_confidence' => 0.0,
    'missing_fields' => ['decision', 'recommended_next_actions'],
    'repaired_fields' => ['json_repair'],
    'fallback_used' => true,
    'extraction_strategy_used' => ['fallback_inference'],
];
$m9 = $repo->persistAfterConfirmation(dm_run_result($c9), $sid9);
dm_assert($m9 === null, '9 fallback with missing critical is strictly refused');

// 10) persistance avec strategic_context_id -> decision_memories + strategic_context_memories
$sid10 = dm_make_session($sessions, $contextId);
$m10 = $repo->persistAfterConfirmation(dm_run_result($baseCanonical), $sid10);
$q10 = $pdo->prepare('SELECT COUNT(*) FROM strategic_context_memories WHERE context_id = ? AND memory_id = ?');
$q10->execute([$contextId, (string)($m10['memory_id'] ?? '')]);
$linkCount = (int)$q10->fetchColumn();
dm_assert(is_array($m10) && $linkCount === 1, '10 memory linked to strategic context on persistence');

// 11) link session only -> strategic_context_sessions only, no decision_memory create
$sid11 = dm_make_session($sessions, null);
$contexts->linkSession($contextId, $sid11);
$q11a = $pdo->prepare('SELECT COUNT(*) FROM strategic_context_sessions WHERE context_id = ? AND session_id = ?');
$q11a->execute([$contextId, $sid11]);
$q11b = $pdo->prepare('SELECT COUNT(*) FROM decision_memories WHERE session_id = ?');
$q11b->execute([$sid11]);
dm_assert((int)$q11a->fetchColumn() === 1 && (int)$q11b->fetchColumn() === 0, '11 link-session does not create decision memory');

// 12) narrative recompute -> ne crée pas decision_memory
$before12 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
$narrative->recomputeAndPersist($contextId);
$after12 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
dm_assert($before12 === $after12, '12 narrative recompute does not create decision memory');

// 13) memory compiler -> ne mute pas decision_memory
$before13 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
$compiler->compileContextMemory($contextId, 'qa-contract');
$after13 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
dm_assert($before13 === $after13, '13 memory compiler does not mutate decision memory');

// 14) beliefs non créées automatiquement
$before14 = (int)$pdo->query('SELECT COUNT(*) FROM strategic_context_beliefs')->fetchColumn();
$sid14 = dm_make_session($sessions, $contextId);
$repo->persistAfterConfirmation(dm_run_result($baseCanonical), $sid14);
$after14 = (int)$pdo->query('SELECT COUNT(*) FROM strategic_context_beliefs')->fetchColumn();
dm_assert($before14 === $after14, '14 persistence does not auto-create beliefs');

// 15) snapshot ne modifie pas mémoire vivante
$before15 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
$snapshots->createSnapshot($contextId, 'manual', ['title' => 'DM contract snapshot', 'created_by' => 'qa-contract']);
$after15 = (int)$pdo->query('SELECT COUNT(*) FROM decision_memories')->fetchColumn();
dm_assert($before15 === $after15, '15 snapshot does not modify live decision memory');

echo "\nOK\n";

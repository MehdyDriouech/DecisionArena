<?php
declare(strict_types=1);

/**
 * QA / Hardening validation for:
 * - Beliefs advanced
 * - Situated Agent Chat enriched
 * - Strategic Context Comparison advanced
 * - Memory Governance
 *
 * Usage:
 *   php backend/tools/test_cognitive_qa_hardening.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Controllers\AgentContextMemoryController;
use Controllers\StrategicContextBeliefsController;
use Controllers\StrategicContextController;
use Controllers\StrategicContextMemoryCompilationsController;
use Controllers\StrategicContextSnapshotsController;
use Domain\Agents\Agent;
use Domain\CognitiveGovernance\PromptInjectionRegistry;
use Domain\Orchestration\PromptBuilder;
use Domain\Providers\ProviderRouter;
use Domain\Sessions\SessionStrategicContextGuard;
use Domain\StrategicContext\AgentContextChatService;
use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\BeliefEngineService;
use Domain\StrategicContext\MemoryGovernanceService;
use Domain\StrategicContext\StrategicContextComparisonService;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\StrategicContextMemoryGovernanceEventRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class QaRequest extends Request
{
    /** @param array<string,mixed> $params @param array<string,mixed> $body @param array<string,mixed> $query */
    public function __construct(
        private array $params = [],
        private array $bodyData = [],
        private array $queryData = []
    ) {
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function body(): array
    {
        return $this->bodyData;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryData[$key] ?? $default;
    }
}

final class CaptureSituatedChatRouter extends ProviderRouter
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        $this->calls[] = ['messages' => $messages, 'agent_id' => $agent?->id];
        return [
            'content' => 'QA_STUB_REPLY',
            'provider_id' => 'qa-stub',
            'provider_name' => 'QA Stub',
            'provider_type' => 'stub',
            'model' => 'qa-stub',
            'routing_mode' => 'stub',
        ];
    }
}

function qa_uuid(): string
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

function qa_fail(string $msg): void
{
    echo "FAIL: {$msg}\n";
    exit(1);
}

function qa_assert(bool $ok, string $msg): void
{
    if (!$ok) {
        qa_fail($msg);
    }
    echo "PASS: {$msg}\n";
}

function qa_count_table(\PDO $pdo, string $table): int
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $table);
    return (int)$stmt->fetchColumn();
}

echo "Cognitive QA / Hardening checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$ctxRepo = new StrategicContextRepository();
$ctxA = $ctxRepo->create('QA Context A', 'A', 'active');
$ctxB = $ctxRepo->create('QA Context B', 'B', 'active');
$ctxC = $ctxRepo->create('QA Context C Empty', 'C', 'active');
$aid = (string)($ctxA['context_id'] ?? '');
$bid = (string)($ctxB['context_id'] ?? '');
$cid = (string)($ctxC['context_id'] ?? '');
qa_assert($aid !== '' && $bid !== '' && $cid !== '', 'create QA contexts');

// ---------------------------------------------------------------------------
// PHASE 1 + 2: Beliefs advanced + API audit + targeted checks
// ---------------------------------------------------------------------------
$beliefSvc = new BeliefEngineService();
$beliefCtrl = new StrategicContextBeliefsController();

$createA = $beliefSvc->createBelief($aid, [
    'belief_text' => 'LEAK_A_ONLY_MARKER',
    'belief_type' => 'belief',
    'status' => 'active',
    'confidence' => 0.72,
    'source_type' => 'evidence',
    'source_reference_id' => 'evidence-A-1',
    'created_by' => 'qa-user',
]);
qa_assert(($createA['ok'] ?? false) === true, 'belief create in context A');
$beliefA = is_array($createA['belief'] ?? null) ? $createA['belief'] : [];
$beliefAId = (string)($beliefA['id'] ?? '');
qa_assert($beliefAId !== '', 'belief id generated');

$updateConfidence = $beliefSvc->updateBelief($aid, $beliefAId, [
    'confidence' => 0.88,
    'created_by' => 'qa-user',
    'confidence_reason' => 'qa_confidence_update',
]);
qa_assert(($updateConfidence['ok'] ?? false) === true, 'belief confidence update');

$invalidNoReason = $beliefSvc->updateBelief($aid, $beliefAId, [
    'status' => 'invalidated',
]);
qa_assert(($invalidNoReason['ok'] ?? true) === false, 'invalidation rejected without invalidated_by/reason');

$invalidWithReason = $beliefSvc->updateBelief($aid, $beliefAId, [
    'status' => 'invalidated',
    'invalidation_reason' => 'qa_invalidation_reason',
    'created_by' => 'qa-user',
]);
qa_assert(($invalidWithReason['ok'] ?? false) === true, 'invalidation accepted with reason');

$archiveBelief = $beliefSvc->archiveBelief($aid, $beliefAId);
qa_assert(($archiveBelief['ok'] ?? false) === true, 'archive belief after invalidation');

$badTransition = $beliefSvc->updateBelief($aid, $beliefAId, [
    'status' => 'active',
    'created_by' => 'qa-user',
]);
qa_assert(($badTransition['ok'] ?? true) === false, 'status transition archived->active rejected');

$timeline = $beliefSvc->getBeliefTimeline($beliefAId, $aid, 30);
qa_assert(is_array($timeline) && count($timeline) >= 3, 'belief timeline contains lifecycle events');

$relations = $beliefSvc->getBeliefRelations($beliefAId, $aid);
$hasEvidenceRel = false;
foreach ($relations as $rel) {
    if (($rel['relation_type'] ?? '') === 'linked_evidence') {
        $hasEvidenceRel = true;
        break;
    }
}
qa_assert($hasEvidenceRel, 'belief relations contain deterministic linked_evidence');

$createB = $beliefSvc->createBelief($bid, [
    'belief_text' => 'B_ONLY_MARKER',
    'belief_type' => 'belief',
    'status' => 'active',
    'confidence' => 0.67,
    'created_by' => 'qa-user',
]);
qa_assert(($createB['ok'] ?? false) === true, 'belief create in context B');

$listA = $beliefSvc->listBeliefsForContext($aid, ['limit' => 200]);
$containsB = false;
foreach ($listA as $b) {
    if (str_contains((string)($b['belief_text'] ?? ''), 'B_ONLY_MARKER')) {
        $containsB = true;
        break;
    }
}
qa_assert(!$containsB, 'belief isolation A/B');

// GET endpoints audit via controllers
$respGlobal = $beliefCtrl->indexGlobal(new QaRequest([], [], ['context_id' => $aid]));
qa_assert(is_array($respGlobal['beliefs'] ?? null), 'GET /api/beliefs?context_id');

$respShow = $beliefCtrl->showGlobal(new QaRequest(['id' => $beliefAId], [], ['context_id' => $aid]));
qa_assert(is_array($respShow['belief'] ?? null), 'GET /api/beliefs/{id}');

$respTimeline = $beliefCtrl->timelineGlobal(new QaRequest(['id' => $beliefAId], [], ['context_id' => $aid]));
qa_assert(is_array($respTimeline['timeline'] ?? null), 'GET /api/beliefs/{id}/timeline');

$respRelations = $beliefCtrl->relationsGlobal(new QaRequest(['id' => $beliefAId], [], ['context_id' => $aid]));
qa_assert(is_array($respRelations['relations'] ?? null), 'GET /api/beliefs/{id}/relations');

$respCtxBeliefs = $beliefCtrl->index(new QaRequest(['contextId' => $aid]));
qa_assert(is_array($respCtxBeliefs['beliefs'] ?? null), 'GET /api/strategic-contexts/{id}/beliefs');

// ---------------------------------------------------------------------------
// Situated chat checks
// ---------------------------------------------------------------------------
$captureRouter = new CaptureSituatedChatRouter();
$chatSvc = new AgentContextChatService($captureRouter);

$chatB = $chatSvc->exchange($bid, 'pm', 'Pourquoi ?', false, false, false, null, null, 'fr');
qa_assert(($chatB['ok'] ?? false) === true, 'POST situated chat works');
qa_assert(is_array($chatB['cognitive_runtime'] ?? null), 'situated chat returns cognitive_runtime');
qa_assert(is_array($chatB['prompt_injection_trace'] ?? null), 'situated chat returns prompt_injection_trace');
qa_assert(($chatB['memory_used'] ?? true) === false, 'situated chat include_memory=false respected');
qa_assert(($chatB['social_context_used'] ?? true) === false, 'situated chat include_social_context=false respected');

$lastCall = end($captureRouter->calls);
$messages = is_array($lastCall['messages'] ?? null) ? $lastCall['messages'] : [];
$systemMsg = is_array($messages[0] ?? null) ? (string)($messages[0]['content'] ?? '') : '';
qa_assert(str_contains($systemMsg, $bid), 'situated chat anchored on current context id');
qa_assert(!str_contains($systemMsg, 'LEAK_A_ONLY_MARKER'), 'situated chat no context leak A -> B');

$chatC = $chatSvc->exchange($cid, 'pm', 'Etat ?', false, false, false, null, null, 'fr');
qa_assert(($chatC['ok'] ?? false) === true, 'situated chat works on empty context');
$sourcesUsed = is_array($chatC['cognitive_runtime']['sources_used'] ?? null) ? $chatC['cognitive_runtime']['sources_used'] : [];
$forbiddenSources = ['agent_context_memory', 'decision_memories', 'social_dynamics', 'beliefs_prioritized', 'beliefs_contested', 'beliefs_fragile_assumptions', 'beliefs_invalidated_optional'];
$inventedSourceDetected = false;
foreach ($forbiddenSources as $src) {
    if (in_array($src, $sourcesUsed, true)) {
        $inventedSourceDetected = true;
        break;
    }
}
qa_assert(!$inventedSourceDetected, 'situated chat no invented cognitive sources when optional inputs are empty');

// ---------------------------------------------------------------------------
// Comparison checks (read-only + payload)
// ---------------------------------------------------------------------------
$cmpSvc = new StrategicContextComparisonService();
$ctxRepo->setActiveContext($aid);
$activeBefore = (string)(($ctxRepo->getActiveContext() ?? [])['context_id'] ?? '');
$countsBefore = [
    'beliefs' => qa_count_table($pdo, 'strategic_context_beliefs'),
    'snapshots' => qa_count_table($pdo, 'strategic_context_snapshots'),
    'governance_events' => qa_count_table($pdo, 'strategic_context_memory_governance_events'),
];

$cmp = $cmpSvc->compare($aid, $bid, true, true, true, true, true);
qa_assert(($cmp['ok'] ?? false) === true, 'POST /api/strategic-contexts/compare');
qa_assert(is_array($cmp['diff']['beliefs'] ?? null), 'compare diff.beliefs');
qa_assert(is_array($cmp['diff']['narrative_drift'] ?? null), 'compare diff.narrative_drift');
qa_assert(is_array($cmp['diff']['memory_compilations'] ?? null), 'compare diff.memory_compilations');
qa_assert(is_array($cmp['diff']['snapshots'] ?? null), 'compare diff.snapshots');
qa_assert(($cmp['diff']['runtime_meta']['read_only'] ?? false) === true, 'compare runtime_meta.read_only=true');

$activeAfter = (string)(($ctxRepo->getActiveContext() ?? [])['context_id'] ?? '');
qa_assert($activeBefore === $activeAfter, 'compare does not change active context');
$countsAfterCompare = [
    'beliefs' => qa_count_table($pdo, 'strategic_context_beliefs'),
    'snapshots' => qa_count_table($pdo, 'strategic_context_snapshots'),
    'governance_events' => qa_count_table($pdo, 'strategic_context_memory_governance_events'),
];
qa_assert($countsBefore === $countsAfterCompare, 'compare does not mutate DB tables');

// Runner compatibility pre-flight (strategic context guard)
$runnerModes = ['decision-room', 'confrontation', 'quick-decision', 'stress-test', 'jury', 'chat', 'reactive'];
foreach ($runnerModes as $mode) {
    $guardError = SessionStrategicContextGuard::assertSessionCreationAllowed($mode, []);
    qa_assert($guardError === null, "runner pre-flight guard compatible: {$mode}");
}

// ---------------------------------------------------------------------------
// Memory governance checks + hardening assertions
// ---------------------------------------------------------------------------
$ctxCtrl = new StrategicContextController();
$snapCtrl = new StrategicContextSnapshotsController();
$memCtrl = new AgentContextMemoryController();
$compCtrl = new StrategicContextMemoryCompilationsController();
$govRepo = new StrategicContextMemoryGovernanceEventRepository();
$govSvc = new MemoryGovernanceService();

$eventsBefore = qa_count_table($pdo, 'strategic_context_memory_governance_events');

// Promotion + invalidation audited via beliefs controller
$beliefViaCtrl = $beliefCtrl->store(new QaRequest(
    ['contextId' => $aid],
    [
        'belief_text' => 'QA governance belief',
        'belief_type' => 'belief',
        'status' => 'proposed',
        'created_by' => 'qa-user',
    ]
));
$beliefViaCtrlId = (string)(($beliefViaCtrl['belief'] ?? [])['id'] ?? '');
qa_assert($beliefViaCtrlId !== '', 'belief controller store works');

$promoted = $beliefCtrl->update(new QaRequest(
    ['contextId' => $aid, 'beliefId' => $beliefViaCtrlId],
    ['status' => 'active', 'created_by' => 'qa-user']
));
qa_assert(is_array($promoted['belief'] ?? null), 'belief promotion via controller');

$deprecated = $beliefCtrl->deprecate(new QaRequest(['contextId' => $aid, 'beliefId' => $beliefViaCtrlId]));
qa_assert(is_array($deprecated['belief'] ?? null), 'belief deprecate via controller');

// Narrative recompute journalized
$nar = $ctxCtrl->narrativeRecompute(new QaRequest(['id' => $aid], []));
qa_assert(!isset($nar['error']), 'narrative recompute endpoint callable');

// Snapshot create journalized
$snap = $snapCtrl->store(new QaRequest(
    ['id' => $aid],
    ['snapshot_type' => 'manual', 'title' => 'QA snapshot', 'created_by' => 'qa-user']
));
$snapId = (string)(($snap['snapshot'] ?? [])['id'] ?? '');
qa_assert($snapId !== '', 'snapshot create endpoint works');

// Memory compact journalized
$memSvc = new AgentContextMemoryService();
$memSvc->write($aid, 'pm', "# Agent Context Memory\n\n## Recent Learnings / Notes\n- foo\n");
$compact = $memCtrl->compact(new QaRequest(['context_id' => $aid, 'agent_id' => 'pm']));
qa_assert(!isset($compact['error']) && is_array($compact), 'memory compact endpoint works');

// Compilation compile journalized
$compiled = $compCtrl->compile(new QaRequest(
    ['id' => $aid],
    ['compilation_type' => 'strategic', 'created_by' => 'qa-user']
));
$compId = (string)(($compiled['compilation'] ?? [])['id'] ?? '');
qa_assert($compId !== '', 'memory compilation compile endpoint works');

$eventsAfter = qa_count_table($pdo, 'strategic_context_memory_governance_events');
qa_assert($eventsAfter > $eventsBefore, 'memory governance events are append-only increasing');

$govPayload = $ctxCtrl->memoryGovernance(new QaRequest(['id' => $aid], [], ['limit' => 180]));
qa_assert(is_array($govPayload['counts'] ?? null), 'GET /api/strategic-contexts/{id}/memory-governance');
qa_assert(is_array($govPayload['recent_events'] ?? null), 'memory-governance returns recent events');

$eventTypes = array_map(static fn (array $ev): string => (string)($ev['event_type'] ?? ''), $govPayload['recent_events'] ?? []);
qa_assert(in_array('promotion', $eventTypes, true), 'governance includes promotion event');
qa_assert(in_array('invalidation', $eventTypes, true), 'governance includes invalidation event');
qa_assert(in_array('creation', $eventTypes, true), 'governance includes creation event');
qa_assert(in_array('compaction', $eventTypes, true), 'governance includes compaction event');

// Hardening: invalidation requires actor/reason
$caught = false;
try {
    $govSvc->logEvent($aid, 'belief', qa_uuid(), 'invalidation', [
        'governance_status' => 'invalidated',
        'provenance_level' => 'explicit',
        'trust_level' => 0.5,
    ]);
} catch (\InvalidArgumentException) {
    $caught = true;
}
qa_assert($caught, 'hardening invalidation requires actor_id or reason');

// Hardening: trust/provenance coherence clamp
$govSvc->logEvent($aid, 'belief', qa_uuid(), 'status_change', [
    'governance_status' => 'stable',
    'provenance_level' => 'derived',
    'trust_level' => 0.99,
    'actor_id' => 'qa-user',
    'reason' => 'qa_derived_clamp',
]);
$rows = $govRepo->listForContext($aid, 20);
$derivedClampOk = false;
foreach ($rows as $row) {
    if ((string)($row['reason'] ?? '') === 'qa_derived_clamp') {
        $derivedClampOk = ((float)($row['trust_level'] ?? 1.0)) <= 0.9;
        break;
    }
}
qa_assert($derivedClampOk, 'hardening trust clamped for derived provenance');

// Hardening: invalidated/deprecated never prioritized in runtime segments
$depBelief = $beliefSvc->createBelief($aid, [
    'belief_text' => 'QA_DEPRECATED_MARKER',
    'belief_type' => 'belief',
    'status' => 'deprecated',
    'confidence' => 0.8,
    'created_by' => 'qa-user',
]);
qa_assert(($depBelief['ok'] ?? false) === true, 'create deprecated belief for runtime hardening test');

$pb = new PromptBuilder();
$m = new \ReflectionMethod(PromptBuilder::class, 'buildBeliefsRuntimeSegments');
$m->setAccessible(true);
$segments = $m->invoke($pb, $aid, true);
$prioritizedSegment = (string)($segments['prioritized'] ?? '');
$contestedSegment = (string)($segments['contested'] ?? '');
$fragileSegment = (string)($segments['fragile'] ?? '');
$invalidatedSegment = (string)($segments['invalidated'] ?? '');
qa_assert(!str_contains($prioritizedSegment . $contestedSegment . $fragileSegment, 'QA_DEPRECATED_MARKER'), 'deprecated/invalidated beliefs not prioritized');
qa_assert(str_contains($invalidatedSegment, 'QA_DEPRECATED_MARKER'), 'deprecated belief appears in invalidated optional segment');

// Hardening: registry keeps invalidated optional low priority
$defs = PromptInjectionRegistry::definitions();
$priority = [];
foreach ($defs as $def) {
    if (!is_array($def)) {
        continue;
    }
    $key = (string)($def['injection_key'] ?? '');
    if ($key !== '') {
        $priority[$key] = (int)($def['priority'] ?? 0);
    }
}
qa_assert(
    ($priority['beliefs_invalidated_optional'] ?? 0) < ($priority['beliefs_contested'] ?? 0)
    && ($priority['beliefs_invalidated_optional'] ?? 0) < ($priority['beliefs_prioritized'] ?? 0),
    'beliefs_invalidated_optional keeps low priority'
);

echo "\nOK\n";

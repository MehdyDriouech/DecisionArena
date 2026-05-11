<?php
declare(strict_types=1);

/**
 * Cognitive runtime QA campaign.
 *
 * Usage:
 *   php backend/tools/test_cognitive_runtime_qa.php
 *
 * Optional QA trace log:
 *   QA_RUNTIME_GUARD=1 php backend/tools/test_cognitive_runtime_qa.php
 * writes observations to backend/storage/logs/qa-runtime.log.
 *
 * This script intentionally does not fix runtime bugs. It reports invariant
 * violations and exits non-zero when at least one invariant is broken.
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Agents\Agent;
use Domain\Agents\Persona;
use Domain\CognitiveGovernance\CanonicalLayerMutationGuard;
use Domain\CognitiveGovernance\PromptInjectionRegistry;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\CognitiveBudgetEngine;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\PromptInjectionTraceCollector;
use Domain\Providers\ProviderRouter;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\StrategicContext\AgentContextChatService;
use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\BeliefEngineService;
use Domain\StrategicContext\ContextSnapshotService;
use Domain\StrategicContext\MemoryCompilerService;
use Domain\StrategicContext\StrategicContextComparisonService;
use Domain\StrategicContext\StrategicNarrativeService;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Controllers\SocialDynamicsController;
use Http\Request;

final class QaRuntimeStubRouter extends ProviderRouter
{
    /** @var list<array{messages:array<int,array<string,string>>,agent_id:?string}> */
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
            'content' => 'QA_RUNTIME_STUB_REPLY',
            'provider_id' => 'qa-runtime-stub',
            'provider_name' => 'QA Runtime Stub',
            'provider_type' => 'stub',
            'model' => 'qa-runtime-stub',
            'routing_mode' => 'stub',
        ];
    }
}

/** @var list<array{status:string,name:string,details:string}> $QA_RESULTS */
$QA_RESULTS = [];

function qa_log(string $event, array $data = []): void
{
    if ((string)getenv('QA_RUNTIME_GUARD') !== '1') {
        return;
    }
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $row = [
        'at' => date('c'),
        'event' => $event,
        'data' => $data,
    ];
    @file_put_contents(
        $dir . '/qa-runtime.log',
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function qa_result(string $status, string $name, string $details = ''): void
{
    global $QA_RESULTS;
    $QA_RESULTS[] = ['status' => $status, 'name' => $name, 'details' => $details];
    $suffix = $details !== '' ? ' - ' . $details : '';
    echo strtoupper($status) . ': ' . $name . $suffix . PHP_EOL;
    qa_log('assertion', ['status' => $status, 'name' => $name, 'details' => $details]);
}

function qa_check(bool $condition, string $name, string $details = ''): void
{
    qa_result($condition ? 'pass' : 'fail', $name, $details);
}

function qa_warn(string $name, string $details = ''): void
{
    qa_result('warn', $name, $details);
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

function qa_scalar(\PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function qa_hash_rows(\PDO $pdo, string $sql, array $params = []): string
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return hash('sha256', is_string($json) ? $json : '');
}

function qa_snapshot_row_hash(\PDO $pdo, string $contextId, string $snapshotId): string
{
    return qa_hash_rows(
        $pdo,
        'SELECT * FROM strategic_context_snapshots WHERE strategic_context_id = ? AND id = ?',
        [$contextId, $snapshotId]
    );
}

function qa_memory_path(string $contextId, string $agentId): string
{
    return __DIR__ . '/../storage/strategic-contexts/' . $contextId . '/agents/' . $agentId . '/memory.md';
}

/** @return array<string,string> relative path => sha256 */
function qa_file_hashes_for_context(string $contextId): array
{
    $root = __DIR__ . '/../storage/strategic-contexts/' . $contextId;
    if (!is_dir($root)) {
        return [];
    }
    $out = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '.tmp.')) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $out[$rel] = hash_file('sha256', $path) ?: '';
    }
    ksort($out);
    return $out;
}

/** @return array<string,int> */
function qa_context_counts(\PDO $pdo, string $contextId): array
{
    return [
        'beliefs' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_beliefs WHERE strategic_context_id = ?', [$contextId]),
        'belief_events' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_belief_events WHERE strategic_context_id = ?', [$contextId]),
        'belief_relations' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_belief_relations WHERE strategic_context_id = ?', [$contextId]),
        'belief_agent_positions' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_belief_agent_positions WHERE strategic_context_id = ?', [$contextId]),
        'narratives' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_narratives WHERE strategic_context_id = ?', [$contextId]),
        'memory_compilations' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_memory_compilations WHERE strategic_context_id = ?', [$contextId]),
        'snapshots' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_snapshots WHERE strategic_context_id = ?', [$contextId]),
        'governance_events' => qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_memory_governance_events WHERE strategic_context_id = ?', [$contextId]),
        'relationships' => qa_scalar($pdo, 'SELECT COUNT(*) FROM agent_relationships WHERE strategic_context_id = ?', [$contextId]),
        'relationship_events' => qa_scalar($pdo, 'SELECT COUNT(*) FROM relationship_events WHERE strategic_context_id = ?', [$contextId]),
        'chat_conversations' => qa_scalar($pdo, 'SELECT COUNT(*) FROM agent_context_conversations WHERE strategic_context_id = ?', [$contextId]),
        'chat_messages' => qa_scalar(
            $pdo,
            'SELECT COUNT(*) FROM agent_context_chat_messages m INNER JOIN agent_context_conversations c ON c.id = m.conversation_id WHERE c.strategic_context_id = ?',
            [$contextId]
        ),
    ];
}

function qa_json_contains(mixed $value, string $needle): bool
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) && str_contains($json, $needle);
}

function qa_file_source(string $relativePath): string
{
    $path = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $relativePath), '/');
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

/**
 * @param list<string> $forbiddenPatterns
 * @return list<string>
 */
function qa_forbidden_pattern_hits(string $source, array $forbiddenPatterns): array
{
    $hits = [];
    foreach ($forbiddenPatterns as $pattern) {
        if ($pattern === '') {
            continue;
        }
        if (@preg_match($pattern, '') === false) {
            if (str_contains($source, $pattern)) {
                $hits[] = $pattern;
            }
            continue;
        }
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $pattern;
        }
    }
    return $hits;
}

function qa_create_session(string $contextId, string $title, string $mode = 'decision-room'): string
{
    $repo = new SessionRepository();
    $id = qa_uuid();
    $now = date('c');
    $repo->create([
        'id' => $id,
        'title' => $title,
        'mode' => $mode,
        'initial_prompt' => 'QA runtime prompt',
        'selected_agents' => json_encode(['pm', 'architect', 'critic'], JSON_UNESCAPED_UNICODE),
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
        'strategic_context_id' => $contextId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function qa_agent(string $id = 'pm'): Agent
{
    $persona = new Persona($id, strtoupper($id), 'QA Agent', '', 'You are a QA runtime test agent.', []);
    return new Agent($id, $persona, null, null, null);
}

function qa_trace_registry_ok(?array $trace): bool
{
    if (!is_array($trace)) {
        return false;
    }
    $defs = PromptInjectionRegistry::definitionsByKey();
    $steps = is_array($trace['steps'] ?? null) ? $trace['steps'] : [];
    if ($steps === []) {
        return false;
    }
    foreach ($steps as $step) {
        if (!is_array($step)) {
            return false;
        }
        $key = (string)($step['injection_key'] ?? '');
        $block = (string)($step['block_id'] ?? '');
        if ($key === '' || $block === '') {
            return false;
        }
        if (!isset($defs[$key])) {
            return false;
        }
    }
    return true;
}

function qa_build_decision_room_prompt(PromptBuilder $builder, Agent $agent, string $contextId, string $objective): array
{
    PromptInjectionTraceCollector::begin([
        'mode' => 'decision-room',
        'session_id' => 'qa-runtime-prompt',
        'strategic_context_id' => $contextId,
        'round' => 1,
        'agent_id' => $agent->id,
    ]);
    try {
        $messages = $builder->buildDecisionRoomMessages(
            $agent,
            $objective,
            [],
            1,
            2,
            'en',
            false,
            null,
            null,
            null,
            null,
            false,
            null,
            $objective,
            '',
            $contextId
        );
        $trace = PromptInjectionTraceCollector::finish();
    } catch (\Throwable $e) {
        PromptInjectionTraceCollector::cancel();
        throw $e;
    }

    return ['messages' => $messages, 'trace' => $trace];
}

echo "Cognitive Runtime QA campaign" . PHP_EOL . PHP_EOL;

$db = Database::getInstance();
(new Migration($db))->run();
$pdo = Database::getConnection();

$runId = 'QA_RT_' . gmdate('Ymd_His') . '_' . substr(hash('sha256', random_bytes(8)), 0, 8);
$markerA = $runId . '_A_ONLY';
$markerB = $runId . '_B_ONLY';
$markerChaos = $runId . '_CHAOS_ONLY';

$contexts = new StrategicContextRepository();
$beliefs = new BeliefEngineService();
$memory = new AgentContextMemoryService();
$narrative = new StrategicNarrativeService();
$compiler = new MemoryCompilerService();
$snapshots = new ContextSnapshotService();
$comparison = new StrategicContextComparisonService();
$social = new SocialDynamicsService();

$ctxA = $contexts->create('QA Runtime Context A ' . $runId, 'A ' . $markerA, 'active');
$ctxB = $contexts->create('QA Runtime Context B ' . $runId, 'B ' . $markerB, 'active');
$a = (string)($ctxA['context_id'] ?? '');
$b = (string)($ctxB['context_id'] ?? '');
qa_check($a !== '' && $b !== '', 'setup creates contexts A and B', $runId);

// TEST 1 - Cross-context isolation.
$beliefA = $beliefs->createBelief($a, [
    'belief_text' => 'Belief marker ' . $markerA,
    'belief_type' => 'belief',
    'status' => 'active',
    'confidence' => 0.81,
    'supporting_agents' => ['pm'],
    'created_by' => 'qa-runtime',
    'source_type' => 'manual',
]);
$beliefB = $beliefs->createBelief($b, [
    'belief_text' => 'Belief marker ' . $markerB,
    'belief_type' => 'belief',
    'status' => 'active',
    'confidence' => 0.77,
    'supporting_agents' => ['architect'],
    'created_by' => 'qa-runtime',
    'source_type' => 'manual',
]);
$memory->write($a, 'pm', "# Agent Context Memory\n\n## Recent Notes\n- memory {$markerA}\n");
$memory->write($b, 'pm', "# Agent Context Memory\n\n## Recent Notes\n- memory {$markerB}\n");
$sidA = qa_create_session($a, 'QA runtime session A');
$sidB = qa_create_session($b, 'QA runtime session B');
$social->ingestAgentResponse($sidA, 1, 'pm', "## Challenge\n@architect strongly disagree {$markerA}", 'architect', ['pm', 'architect'], [], [], $a);
$social->ingestAgentResponse($sidB, 1, 'pm', "## Challenge\n@architect strongly disagree {$markerB}", 'architect', ['pm', 'architect'], [], [], $b);
$narA = $narrative->recomputeAndPersist($a);
$narB = $narrative->recomputeAndPersist($b);
$snapA = $snapshots->createSnapshot($a, 'manual', ['title' => 'QA A snapshot', 'created_by' => 'qa-runtime']);
$snapB = $snapshots->createSnapshot($b, 'manual', ['title' => 'QA B snapshot', 'created_by' => 'qa-runtime']);

$bundleA = [
    'beliefs' => $beliefs->listBeliefsForContext($a, ['limit' => 200]),
    'memory' => $memory->read($a, 'pm'),
    'narrative' => $narA,
    'snapshot' => $snapA,
];
$bundleB = [
    'beliefs' => $beliefs->listBeliefsForContext($b, ['limit' => 200]),
    'memory' => $memory->read($b, 'pm'),
    'narrative' => $narB,
    'snapshot' => $snapB,
];
qa_check(($beliefA['ok'] ?? false) === true && ($beliefB['ok'] ?? false) === true, 'TEST 1 setup created beliefs in A/B');
qa_check(!qa_json_contains($bundleA, $markerB), 'TEST 1 no B marker visible in context A bundle');
qa_check(!qa_json_contains($bundleB, $markerA), 'TEST 1 no A marker visible in context B bundle');
qa_check(qa_context_counts($pdo, $a)['relationships'] > 0 && qa_context_counts($pdo, $b)['relationships'] > 0, 'TEST 1 social dynamics scoped rows exist');

// TEST 1B - Social dynamics strict context by default; legacy only on explicit opt-in.
$socialRepo = new AgentRelationshipRepository();
$socialPromptBuilder = new SocialPromptContextBuilder($socialRepo);
$socialController = new SocialDynamicsController();
$sidStrictA = qa_create_session($a, 'QA strict social A', 'decision-room');
$sidStrictB = qa_create_session($b, 'QA strict social B', 'decision-room');
$socialRepo->upsertRelationship([
    'session_id' => $sidStrictA,
    'source_agent_id' => 'pm',
    'target_agent_id' => 'ctxa_agent',
    'strategic_context_id' => $a,
    'affinity' => 0.72,
    'trust' => 0.70,
    'conflict' => 0.08,
    'support_count' => 3,
    'challenge_count' => 0,
    'alliance_count' => 1,
    'attack_count' => 0,
    'last_interaction_type' => 'support',
]);
$socialRepo->upsertRelationship([
    'session_id' => $sidStrictB,
    'source_agent_id' => 'pm',
    'target_agent_id' => 'ctxb_agent',
    'strategic_context_id' => $b,
    'affinity' => 0.65,
    'trust' => 0.64,
    'conflict' => 0.12,
    'support_count' => 2,
    'challenge_count' => 0,
    'alliance_count' => 0,
    'attack_count' => 0,
    'last_interaction_type' => 'support',
]);
$socialRepo->upsertRelationship([
    'session_id' => $sidStrictA,
    'source_agent_id' => 'pm',
    'target_agent_id' => 'legacy_agent',
    'strategic_context_id' => null,
    'affinity' => 0.91,
    'trust' => 0.88,
    'conflict' => 0.03,
    'support_count' => 5,
    'challenge_count' => 0,
    'alliance_count' => 2,
    'attack_count' => 0,
    'last_interaction_type' => 'alliance',
]);

$strictA = $socialRepo->findBySession($sidStrictA, $a);
$strictB = $socialRepo->findBySession($sidStrictB, $b);
$optInA = $socialRepo->findBySession($sidStrictA, $a, true);
$strictABlock = $socialPromptBuilder->buildUserBlock($sidStrictA, 'pm', ['GO' => 1, 'NO-GO' => 0, 'ITERATE' => 0], $a, false);
$strictBBlock = $socialPromptBuilder->buildUserBlock($sidStrictB, 'pm', ['GO' => 1, 'NO-GO' => 0, 'ITERATE' => 0], $b, false);

qa_check(count($strictA) === 1, 'TEST 1B strict read A returns only scoped A rows');
qa_check(qa_json_contains($strictA, 'ctxa_agent') && !qa_json_contains($strictA, 'ctxb_agent') && !qa_json_contains($strictA, 'legacy_agent'), 'TEST 1B strict read A excludes B and legacy NULL');
qa_check(count($strictB) === 1, 'TEST 1B strict read B returns only scoped B rows');
qa_check(qa_json_contains($strictB, 'ctxb_agent') && !qa_json_contains($strictB, 'ctxa_agent') && !qa_json_contains($strictB, 'legacy_agent'), 'TEST 1B strict read B excludes A and legacy NULL');
qa_check(qa_json_contains($optInA, 'ctxa_agent') && qa_json_contains($optInA, 'legacy_agent') && !qa_json_contains($optInA, 'ctxb_agent'), 'TEST 1B opt-in legacy read returns A plus legacy only');
qa_check(str_contains($strictABlock, 'ctxa_agent') && !str_contains($strictABlock, 'ctxb_agent') && !str_contains($strictABlock, 'legacy_agent'), 'TEST 1B prompt social block A excludes legacy and context B');
qa_check(str_contains($strictBBlock, 'ctxb_agent') && !str_contains($strictBBlock, 'ctxa_agent') && !str_contains($strictBBlock, 'legacy_agent'), 'TEST 1B prompt social block B excludes legacy and context A');

$oldGet = $_GET ?? [];
$_GET = ['include_legacy' => '1'];
$reqLegacy = new Request();
$reqLegacy->setParams(['id' => $sidStrictA]);
$legacyPayload = $socialController->relationships($reqLegacy);
$_GET = $oldGet;
$legacyMeta = is_array($legacyPayload['meta'] ?? null) ? $legacyPayload['meta'] : [];
qa_check(($legacyMeta['include_legacy'] ?? false) === true, 'TEST 1B social endpoint marks include_legacy=true on explicit opt-in');
qa_check(($legacyMeta['legacy_opt_in_active'] ?? false) === true, 'TEST 1B social endpoint marks legacy opt-in active');

// TEST 2 - Narrative must not mutate beliefs.
$beliefHashBefore = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_beliefs WHERE strategic_context_id = ? ORDER BY id', [$a]);
$beliefEventCountBefore = qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_belief_events WHERE strategic_context_id = ?', [$a]);
$narAgain = $narrative->recomputeAndPersist($a);
$beliefHashAfter = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_beliefs WHERE strategic_context_id = ? ORDER BY id', [$a]);
$beliefEventCountAfter = qa_scalar($pdo, 'SELECT COUNT(*) FROM strategic_context_belief_events WHERE strategic_context_id = ?', [$a]);
qa_check(($narAgain['context_id'] ?? '') === $a, 'TEST 2 narrative recompute returns context A');
qa_check($beliefHashBefore === $beliefHashAfter, 'TEST 2 narrative recompute does not update belief rows');
qa_check($beliefEventCountBefore === $beliefEventCountAfter, 'TEST 2 narrative recompute does not append belief events');

// TEST 3 - Memory Compiler is read-derived for memory.md, beliefs, narrative.
$memoryHashesBefore = qa_file_hashes_for_context($a);
$beliefHashBefore = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_beliefs WHERE strategic_context_id = ? ORDER BY id', [$a]);
$narrativeHashBefore = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_narratives WHERE strategic_context_id = ? ORDER BY strategic_context_id', [$a]);
$compOne = $compiler->compile($a, 'working', 'qa-runtime', ['supersede_previous' => true]);
$compTwo = $compiler->compile($a, 'working', 'qa-runtime', ['supersede_previous' => true]);
$memoryHashesAfter = qa_file_hashes_for_context($a);
$beliefHashAfter = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_beliefs WHERE strategic_context_id = ? ORDER BY id', [$a]);
$narrativeHashAfter = qa_hash_rows($pdo, 'SELECT * FROM strategic_context_narratives WHERE strategic_context_id = ? ORDER BY strategic_context_id', [$a]);
qa_check(($compOne['ok'] ?? false) === true && ($compTwo['ok'] ?? false) === true, 'TEST 3 memory compiler runs twice');
qa_check($memoryHashesBefore === $memoryHashesAfter, 'TEST 3 compiler does not mutate memory.md');
qa_check($beliefHashBefore === $beliefHashAfter, 'TEST 3 compiler does not mutate beliefs');
qa_check($narrativeHashBefore === $narrativeHashAfter, 'TEST 3 compiler does not mutate narrative');

// TEST 4 - Snapshots are immutable and compare is not restore.
$s1 = $snapshots->createSnapshot($a, 'manual', ['title' => 'QA immutable S1', 'created_by' => 'qa-runtime']);
$s1Id = (string)(($s1['snapshot'] ?? [])['id'] ?? '');
$s1HashBefore = qa_snapshot_row_hash($pdo, $a, $s1Id);
$beliefs->createBelief($a, [
    'belief_text' => 'Live state after snapshot ' . $runId,
    'belief_type' => 'hypothesis',
    'status' => 'proposed',
    'confidence' => 0.42,
    'created_by' => 'qa-runtime',
    'source_type' => 'manual',
]);
$s1HashAfterLiveMutation = qa_snapshot_row_hash($pdo, $a, $s1Id);
$s2 = $snapshots->createSnapshot($a, 'manual', ['title' => 'QA immutable S2', 'created_by' => 'qa-runtime']);
$s2Id = (string)(($s2['snapshot'] ?? [])['id'] ?? '');
$countsBeforeSnapshotCompare = qa_context_counts($pdo, $a);
$snapshotCompare = $snapshots->compareSnapshots($a, $s1Id, $s2Id);
$countsAfterSnapshotCompare = qa_context_counts($pdo, $a);
$s1HashAfterCompare = qa_snapshot_row_hash($pdo, $a, $s1Id);
qa_check($s1Id !== '' && $s2Id !== '', 'TEST 4 creates S1/S2 snapshots');
qa_check($s1HashBefore === $s1HashAfterLiveMutation, 'TEST 4 S1 payload stable after live mutation');
qa_check(($snapshotCompare['ok'] ?? false) === true, 'TEST 4 snapshot compare returns ok');
qa_check($countsBeforeSnapshotCompare === $countsAfterSnapshotCompare, 'TEST 4 snapshot compare does not mutate live state');
qa_check($s1HashBefore === $s1HashAfterCompare, 'TEST 4 snapshot compare does not rewrite S1');
qa_check(!method_exists(ContextSnapshotService::class, 'restore'), 'TEST 4 no silent restore method exposed on ContextSnapshotService');

// TEST 5 - Context comparison is read-only.
$contexts->setActiveContext($a);
$activeBefore = (string)(($contexts->getActiveContext() ?? [])['context_id'] ?? '');
$countsACompareBefore = qa_context_counts($pdo, $a);
$countsBCompareBefore = qa_context_counts($pdo, $b);
$filesACompareBefore = qa_file_hashes_for_context($a);
$filesBCompareBefore = qa_file_hashes_for_context($b);
$cmp = $comparison->compare($a, $b, true, true, true, true, true);
$activeAfter = (string)(($contexts->getActiveContext() ?? [])['context_id'] ?? '');
qa_check(($cmp['ok'] ?? false) === true, 'TEST 5 context comparison returns ok');
qa_check(($cmp['diff']['runtime_meta']['read_only'] ?? false) === true, 'TEST 5 comparison declares runtime_meta.read_only');
qa_check($activeBefore === $activeAfter, 'TEST 5 comparison does not change active context');
qa_check($countsACompareBefore === qa_context_counts($pdo, $a), 'TEST 5 comparison does not mutate context A tables');
qa_check($countsBCompareBefore === qa_context_counts($pdo, $b), 'TEST 5 comparison does not mutate context B tables');
qa_check($filesACompareBefore === qa_file_hashes_for_context($a), 'TEST 5 comparison does not mutate context A files');
qa_check($filesBCompareBefore === qa_file_hashes_for_context($b), 'TEST 5 comparison does not mutate context B files');

// TEST 6 - PromptBuilder must remain read-only/no-side-effect.
$ctxPrompt = $contexts->create('QA Runtime PromptBuilder ' . $runId, 'PromptBuilder no-persistence probe', 'active');
$p = (string)($ctxPrompt['context_id'] ?? '');
$appLogsBefore = qa_scalar($pdo, 'SELECT COUNT(*) FROM app_logs');
$promptFilesBefore = qa_file_hashes_for_context($p);
$promptMemoryPath = qa_memory_path($p, 'pm');
$promptMemoryExistedBefore = file_exists($promptMemoryPath);
$builder = new PromptBuilder();
$promptTrace = null;
$promptMessages = [];
try {
    $built = qa_build_decision_room_prompt($builder, qa_agent('pm'), $p, 'PromptBuilder QA objective ' . $runId);
    $promptMessages = is_array($built['messages'] ?? null) ? $built['messages'] : [];
    $promptTrace = is_array($built['trace'] ?? null) ? $built['trace'] : null;
    qa_check(true, 'TEST 6 PromptBuilder buildDecisionRoomMessages completes');
} catch (\Throwable $e) {
    qa_check(false, 'TEST 6 PromptBuilder buildDecisionRoomMessages completes', $e->getMessage());
}
$appLogsAfter = qa_scalar($pdo, 'SELECT COUNT(*) FROM app_logs');
$promptFilesAfter = qa_file_hashes_for_context($p);
qa_check($appLogsAfter === $appLogsBefore, 'TEST 6 PromptBuilder does not write SQLite', 'app_logs delta=' . ($appLogsAfter - $appLogsBefore));
qa_check($promptFilesAfter === $promptFilesBefore, 'TEST 6 PromptBuilder does not write filesystem', 'memory existed before=' . ($promptMemoryExistedBefore ? 'yes' : 'no') . ', after=' . (file_exists($promptMemoryPath) ? 'yes' : 'no'));
qa_check(!file_exists($promptMemoryPath), 'TEST 6 PromptBuilder with missing memory does not create memory.md');
qa_check(is_array($promptTrace) && is_array($promptTrace['cognitive_budget'] ?? null), 'TEST 6 prompt trace and cognitive budget present');
qa_check(qa_trace_registry_ok($promptTrace), 'TEST 6 all prompt trace steps map to registry definitions');
qa_check(is_array($promptMessages) && count($promptMessages) === 2, 'TEST 6 prompt message shape is stable');

$ctxPromptExisting = $contexts->create('QA Runtime PromptBuilder Existing Memory ' . $runId, 'PromptBuilder read existing memory', 'active');
$pExisting = (string)($ctxPromptExisting['context_id'] ?? '');
$memoryWrite = $memory->write($pExisting, 'pm', "# Agent Context Memory\n\n## Recent Notes\n- existing-memory {$runId}\n");
qa_check(($memoryWrite['ok'] ?? false) === true, 'TEST 6 setup writes existing memory file');
$existingMemoryPath = qa_memory_path($pExisting, 'pm');
$existingMemoryBefore = is_file($existingMemoryPath) ? (string)file_get_contents($existingMemoryPath) : '';
$existingHashBefore = is_file($existingMemoryPath) ? (string)(hash_file('sha256', $existingMemoryPath) ?: '') : '';
try {
    qa_build_decision_room_prompt(new PromptBuilder(), qa_agent('pm'), $pExisting, 'PromptBuilder existing memory QA objective ' . $runId);
    qa_check(true, 'TEST 6 PromptBuilder build with existing memory completes');
} catch (\Throwable $e) {
    qa_check(false, 'TEST 6 PromptBuilder build with existing memory completes', $e->getMessage());
}
$existingMemoryAfter = is_file($existingMemoryPath) ? (string)file_get_contents($existingMemoryPath) : '';
$existingHashAfter = is_file($existingMemoryPath) ? (string)(hash_file('sha256', $existingMemoryPath) ?: '') : '';
qa_check($existingHashBefore !== '' && $existingHashBefore === $existingHashAfter, 'TEST 6 PromptBuilder with existing memory does not modify memory.md');
qa_check($existingMemoryBefore === $existingMemoryAfter, 'TEST 6 PromptBuilder reads existing memory without content mutation');

$promptBuilderSource = (string)@file_get_contents(__DIR__ . '/../src/Domain/Orchestration/PromptBuilder.php');
qa_check(!str_contains($promptBuilderSource, 'Logger::logPromptBuild(') && !str_contains($promptBuilderSource, '->logPromptBuild('), 'TEST 6 PromptBuilder does not reference Logger::logPromptBuild');
qa_check(!str_contains($promptBuilderSource, 'ensureFile('), 'TEST 6 PromptBuilder does not reference ensureFile');

$socialRepoSource = (string)@file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/AgentRelationshipRepository.php');
qa_check(!str_contains($socialRepoSource, 'includeLegacyNullRows = true'), 'TEST 6 social repository defaults never enable legacy NULL rows');
$socialPromptSource = (string)@file_get_contents(__DIR__ . '/../src/Domain/SocialDynamics/SocialPromptContextBuilder.php');
qa_check(!str_contains($socialPromptSource, 'summarizeForPrompt($sessionId, $agentId);'), 'TEST 6 SocialPromptContextBuilder avoids global summary call when context can be provided');

// TEST 7 - Situated Agent Chat cognitive runtime.
$router = new QaRuntimeStubRouter();
$chat = new AgentContextChatService($router);
$chatOut = $chat->exchange($b, 'pm', 'What is known in this context?', true, false, false, null, null, 'en');
$lastCall = $router->calls[count($router->calls) - 1] ?? ['messages' => []];
$chatPrompt = json_encode($lastCall['messages'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
qa_check(($chatOut['ok'] ?? false) === true, 'TEST 7 situated chat returns ok');
qa_check(is_array($chatOut['cognitive_runtime'] ?? null), 'TEST 7 situated chat returns cognitive_runtime');
qa_check(is_array($chatOut['prompt_injection_trace'] ?? null), 'TEST 7 situated chat returns prompt_injection_trace');
qa_check(qa_trace_registry_ok(is_array($chatOut['prompt_injection_trace'] ?? null) ? $chatOut['prompt_injection_trace'] : null), 'TEST 7 situated chat trace steps map to registry definitions');
qa_check(!str_contains($chatPrompt, $markerA), 'TEST 7 situated chat does not leak context A marker into B prompt');
qa_check(str_contains($chatPrompt, $markerB), 'TEST 7 situated chat uses B scoped sources');

// TEST 8 - Static cognitive hardening: forbidden calls and call graph.
$promptBuilderStatic = qa_file_source('src/Domain/Orchestration/PromptBuilder.php');
$promptForbiddenPersistence = qa_forbidden_pattern_hits($promptBuilderStatic, [
    '/\bLogger\b/',
    '/->\s*logPromptBuild\s*\(/',
    '/\bPDO\b/',
    '/Database::getConnection\s*\(/',
    '/Database::getInstance\s*\(/',
    '/\bfile_put_contents\s*\(/',
    '/\bfopen\s*\(/',
    '/\bfwrite\s*\(/',
    '/\bensureFile\s*\(/',
    '/\bsqlite\b/i',
    '/->\s*exec\s*\(/',
    '/->\s*prepare\s*\(/',
    '/->\s*query\s*\(/',
    '/->\s*(save|insert|update|delete)\s*\(/i',
]);
qa_check($promptForbiddenPersistence === [], 'TEST 8 static PromptBuilder has no persistence calls', implode('; ', $promptForbiddenPersistence));

$promptForbiddenFs = qa_forbidden_pattern_hits($promptBuilderStatic, [
    '/\bfile_put_contents\s*\(/',
    '/\bfopen\s*\(/',
    '/\bfwrite\s*\(/',
    '/\bmkdir\s*\(/',
    '/\bunlink\s*\(/',
]);
qa_check($promptForbiddenFs === [], 'TEST 8 static PromptBuilder has no filesystem writes', implode('; ', $promptForbiddenFs));

$promptForbiddenRepoWrites = qa_forbidden_pattern_hits($promptBuilderStatic, [
    '/->\s*(insert|update|delete|upsert|save)\s*\(/i',
    '/->\s*createBelief\s*\(/',
    '/->\s*updateBelief\s*\(/',
    '/->\s*archiveBelief\s*\(/',
    '/->\s*write\s*\(/',
]);
qa_check($promptForbiddenRepoWrites === [], 'TEST 8 static PromptBuilder has no repository writes', implode('; ', $promptForbiddenRepoWrites));

$forbiddenGraph = [
    'src/Domain/StrategicContext/StrategicNarrativeService.php' => [
        '/->\s*(createBelief|updateBelief|archiveBelief)\s*\(/',
        '/->\s*(write|appendNote|ensureFile)\s*\(/',
    ],
    'src/Domain/StrategicContext/MemoryCompilerService.php' => [
        '/->\s*(createBelief|updateBelief|archiveBelief)\s*\(/',
        '/->\s*(write|appendNote|ensureFile)\s*\(/',
    ],
    'src/Domain/StrategicContext/ContextSnapshotService.php' => [
        '/\bfunction\s+restore\b/i',
    ],
    'src/Domain/SocialDynamics/SocialPromptContextBuilder.php' => [
        '/summarizeForPrompt\s*\(\s*\$sessionId\s*,\s*\$agentId\s*\)/',
    ],
];
foreach ($forbiddenGraph as $relPath => $patterns) {
    $src = qa_file_source($relPath);
    $hits = qa_forbidden_pattern_hits($src, $patterns);
    qa_check($hits === [], 'TEST 8 forbidden call graph ' . $relPath, implode('; ', $hits));
}

$socialStrictHits = [];
$srcRoot = realpath(__DIR__ . '/../src');
if (is_string($srcRoot) && is_dir($srcRoot)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $abs = $file->getPathname();
        $rel = str_replace('\\', '/', substr($abs, strlen($srcRoot) + 1));
        $src = (string)@file_get_contents($abs);
        if ($src === '' || !str_contains($src, 'includeLegacyNullRows')) {
            continue;
        }
        if (preg_match('/includeLegacyNullRows\s*=\s*true/', $src) === 1) {
            $socialStrictHits[] = $rel;
        }
    }
}
qa_check($socialStrictHits === [], 'TEST 8 static includeLegacyNullRows=true not used as default', implode(', ', $socialStrictHits));

$socialPromptBuilderSource = qa_file_source('src/Domain/SocialDynamics/SocialPromptContextBuilder.php');
qa_check(
    preg_match('/summarizeForPrompt\s*\(\s*\$sessionId\s*,\s*\$agentId\s*,\s*\$sessionStrategicContextId\s*,\s*\$includeLegacyNullRows\s*\)/', $socialPromptBuilderSource) === 1,
    'TEST 8 SocialPromptContextBuilder passes explicit strict-context arguments to summarizeForPrompt'
);

$runnerLegacyOptInHits = [];
foreach ([
    'src/Domain/Orchestration/DecisionRoomRunner.php',
    'src/Domain/Orchestration/QuickDecisionRunner.php',
    'src/Domain/Orchestration/ConfrontationRunner.php',
    'src/Domain/Orchestration/StressTestRunner.php',
    'src/Domain/Orchestration/JuryRunner.php',
] as $runnerPath) {
    $src = qa_file_source($runnerPath);
    if (preg_match('/buildUserBlock\s*\([^)]*true\s*\)/s', $src) === 1) {
        $runnerLegacyOptInHits[] = $runnerPath;
    }
}
qa_check($runnerLegacyOptInHits === [], 'TEST 8 runners do not opt-in legacy social reads by default', implode(', ', $runnerLegacyOptInHits));

// TEST 9 - Runtime mutation guard behavior (active only with QA_RUNTIME_GUARD=1).
if (CanonicalLayerMutationGuard::enabled()) {
    $forbiddenTriggered = false;
    try {
        CanonicalLayerMutationGuard::assertAllowed('prompt_builder', 'sqlite', 'write', ['probe' => 'qa']);
    } catch (\RuntimeException) {
        $forbiddenTriggered = true;
    }
    qa_check($forbiddenTriggered, 'TEST 9 mutation guard blocks forbidden PromptBuilder -> SQLite write');
} else {
    qa_check(true, 'TEST 9 mutation guard runtime checks skipped (QA_RUNTIME_GUARD!=1)');
}

// PHASE 6 - Cognitive chaos scenarios.
$ctxChaos = $contexts->create('QA Runtime Chaos ' . $runId, 'Chaos ' . $markerChaos, 'active');
$chaos = (string)($ctxChaos['context_id'] ?? '');
$longMemory = "# Agent Context Memory\n\n## Recent Notes\n- " . str_repeat($markerChaos . ' long memory ', 5000) . "\n";
$memory->write($chaos, 'pm', $longMemory);
for ($i = 0; $i < 30; $i++) {
    $status = $i % 7 === 0 ? 'disputed' : ($i % 11 === 0 ? 'deprecated' : 'active');
    $beliefs->createBelief($chaos, [
        'belief_text' => sprintf('%s contradictory belief %02d says direction %s', $markerChaos, $i, $i % 2 === 0 ? 'north' : 'south'),
        'belief_type' => $i % 3 === 0 ? 'hypothesis' : 'belief',
        'status' => $status,
        'confidence' => 0.25 + (($i % 10) / 12),
        'supporting_agents' => $i % 2 === 0 ? ['pm'] : ['architect'],
        'disagreeing_agents' => $i % 2 === 0 ? ['critic'] : ['pm'],
        'created_by' => 'qa-runtime',
        'source_type' => 'manual',
    ]);
}
$chaosSession = qa_create_session($chaos, 'QA chaos session');
$social->ingestAgentResponse($chaosSession, 1, 'pm', "## Challenge\n@architect strongly disagree unsupported {$markerChaos}", 'architect', ['pm', 'architect', 'critic'], [], [], $chaos);
$narrative->recomputeAndPersist($chaos);
for ($i = 0; $i < 3; $i++) {
    $snapshots->createSnapshot($chaos, 'manual', ['title' => 'QA chaos snapshot ' . $i, 'created_by' => 'qa-runtime']);
}
$compiler->compile($chaos, 'working', 'qa-runtime', ['supersede_previous' => true]);
$compiler->compile($chaos, 'working', 'qa-runtime', ['supersede_previous' => true]);
$chaosCompare = $comparison->compare($chaos, $a, true, true, true, true, true);
$hugeObjective = 'Huge context objective ' . $markerChaos . ' ' . str_repeat('budget pressure ', 10000);
$chaosBuilt = qa_build_decision_room_prompt(new PromptBuilder(), qa_agent('pm'), $chaos, $hugeObjective);
$chaosTrace = is_array($chaosBuilt['trace'] ?? null) ? $chaosBuilt['trace'] : null;
$chaosMessages = is_array($chaosBuilt['messages'] ?? null) ? $chaosBuilt['messages'] : [];
$chaosUser = (string)(($chaosMessages[1] ?? [])['content'] ?? '');
$chaosBudget = is_array($chaosTrace['cognitive_budget'] ?? null) ? $chaosTrace['cognitive_budget'] : [];
$pruningEvents = is_array($chaosBudget['pruning_events'] ?? null) ? $chaosBudget['pruning_events'] : [];
qa_check(($chaosCompare['ok'] ?? false) === true, 'CHAOS comparison asymmetric A/chaos does not crash');
qa_check(is_array($chaosTrace) && qa_trace_registry_ok($chaosTrace), 'CHAOS Decision Room trace complete and registry declared');
qa_check(count($pruningEvents) > 0, 'CHAOS CognitiveBudgetEngine prunes huge context traceably');
qa_check(mb_strlen($chaosUser, 'UTF-8') <= CognitiveBudgetEngine::GLOBAL_USER_MESSAGE_HARD_CAP + 2000, 'CHAOS user prompt remains bounded after pruning');
qa_check(!str_contains($chaosUser, $markerA), 'CHAOS Decision Room prompt does not leak context A marker');

echo PHP_EOL . 'Summary' . PHP_EOL;
$failures = array_values(array_filter($QA_RESULTS, static fn (array $r): bool => $r['status'] === 'fail'));
$warnings = array_values(array_filter($QA_RESULTS, static fn (array $r): bool => $r['status'] === 'warn'));
$passes = array_values(array_filter($QA_RESULTS, static fn (array $r): bool => $r['status'] === 'pass'));
echo 'PASS=' . count($passes) . ' WARN=' . count($warnings) . ' FAIL=' . count($failures) . PHP_EOL;

if ($failures !== []) {
    echo PHP_EOL . 'Failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure['name'];
        if ($failure['details'] !== '') {
            echo ' (' . $failure['details'] . ')';
        }
        echo PHP_EOL;
    }
}

exit($failures === [] ? 0 : 1);

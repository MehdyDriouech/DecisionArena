<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Cross-read comparison between two Strategic Contexts.
 *
 * Comparison is read-only by design: no activation, no session/memory/social/timeline mutations,
 * no LLM, no ProviderRouter.
 */
final class StrategicContextComparisonService
{
    private const AGENTS_FOR_MEMORY_COMPARE = ['pm', 'architect', 'critic', 'ux-expert', 'po'];

    private const MEMORY_FOCUS_SECTIONS = [
        'Current Strategic Direction',
        'Open Questions',
        'Stable Beliefs',
        'Persistent Beliefs',
        'Strategic Assumptions',
        'Decisions Remembered',
        'Failed Predictions',
        'Failed Predictions / Regrets',
        'Relationships',
        'Relationships in this Context',
        'User Preferences',
        'User Preferences in this Context',
        'Recent Notes',
        'Recent Learnings',
        'Contradictions To Review',
        'Pending Consolidation Notes',
    ];

    private StrategicContextRepository $contextRepo;
    private WorkspaceTimelineService $timelineSvc;
    private DecisionMemoryRepository $decisionRepo;
    private AgentRelationshipRepository $relRepo;
    private AgentContextMemoryService $agentMemSvc;
    private BeliefEngineService $beliefSvc;
    private StrategicNarrativeService $narrativeSvc;
    private MemoryCompilerService $memoryCompilerSvc;
    private ContextSnapshotService $snapshotSvc;
    private \PDO $pdo;

    /** @var array<string,mixed> */
    private array $requestCache = [];

    public function __construct()
    {
        $this->contextRepo = new StrategicContextRepository();
        $this->timelineSvc = new WorkspaceTimelineService();
        $this->decisionRepo = new DecisionMemoryRepository();
        $this->relRepo = new AgentRelationshipRepository();
        $this->agentMemSvc = new AgentContextMemoryService();
        $this->beliefSvc = new BeliefEngineService();
        $this->narrativeSvc = new StrategicNarrativeService();
        $this->memoryCompilerSvc = new MemoryCompilerService();
        $this->snapshotSvc = new ContextSnapshotService();
        $this->pdo = Database::getConnection();
    }

    /**
     * @return array{ok:true,left:array,right:array,diff:array,markdown:string}|array{ok:false,message:string,code:int}
     */
    public function compare(
        string $leftContextId,
        string $rightContextId,
        bool $includeSessions,
        bool $includeDecisions,
        bool $includeAgentMemories,
        bool $includeSocialDynamics,
        bool $includeTimeline
    ): array {
        $t0 = microtime(true);
        $leftContextId = strtolower(trim($leftContextId));
        $rightContextId = strtolower(trim($rightContextId));
        if (!$this->agentMemSvc->isValidContextUuid($leftContextId)
            || !$this->agentMemSvc->isValidContextUuid($rightContextId)) {
            return ['ok' => false, 'message' => 'Invalid context id', 'code' => 400];
        }
        if ($leftContextId === $rightContextId) {
            return ['ok' => false, 'message' => 'Cannot compare a context with itself', 'code' => 400];
        }

        $leftRow = $this->contextRepo->find($leftContextId);
        $rightRow = $this->contextRepo->find($rightContextId);
        if (!$leftRow) {
            return ['ok' => false, 'message' => 'Left context not found', 'code' => 404];
        }
        if (!$rightRow) {
            return ['ok' => false, 'message' => 'Right context not found', 'code' => 404];
        }

        $leftSessions = $includeSessions ? $this->loadSessionsForContext($leftContextId) : [];
        $rightSessions = $includeSessions ? $this->loadSessionsForContext($rightContextId) : [];
        $leftSessMap = $this->indexSessionsById($leftSessions);
        $rightSessMap = $this->indexSessionsById($rightSessions);
        $leftSessionSignals = $includeSessions ? $this->groupedSessionSignals(array_keys($leftSessMap)) : $this->emptySessionSignals();
        $rightSessionSignals = $includeSessions ? $this->groupedSessionSignals(array_keys($rightSessMap)) : $this->emptySessionSignals();

        $leftWarnings = [];
        $rightWarnings = [];
        if ($includeSessions && $leftSessions === []) {
            $leftWarnings[] = 'No sessions linked to this strategic context (column or join table).';
        }
        if ($includeSessions && $rightSessions === []) {
            $rightWarnings[] = 'No sessions linked to this strategic context (column or join table).';
        }

        $leftSummary = $this->buildSideSummary(
            $leftContextId,
            $leftRow,
            $leftSessions,
            $leftSessionSignals,
            $includeSessions,
            $includeDecisions,
            $includeSocialDynamics,
            $includeTimeline
        );
        $rightSummary = $this->buildSideSummary(
            $rightContextId,
            $rightRow,
            $rightSessions,
            $rightSessionSignals,
            $includeSessions,
            $includeDecisions,
            $includeSocialDynamics,
            $includeTimeline
        );

        $diffObjectives = $this->diffObjectives($leftRow, $rightRow);
        $diffSessions = $includeSessions
            ? $this->diffSessionsBlock($leftSessMap, $rightSessMap)
            : [];
        $diffDecisions = $includeDecisions
            ? $this->diffDecisionsBlock($leftContextId, $rightContextId)
            : [];
        $diffRisks = ($includeSessions && $includeDecisions)
            ? $this->diffRisksEvidenceBlock($leftSessMap, $rightSessMap, $leftSessionSignals, $rightSessionSignals)
            : [];

        $diffAgentMemory = $includeAgentMemories
            ? $this->diffAgentMemories($leftContextId, $rightContextId, $leftWarnings, $rightWarnings)
            : [];

        $diffSocial = $includeSocialDynamics
            ? $this->diffSocialDynamicsBlock($leftContextId, $rightContextId)
            : [];

        $diffTimeline = $includeTimeline
            ? $this->diffTimelinesBlock($leftContextId, $rightContextId)
            : [];
        $beliefDiff = $this->diffBeliefsBlock($leftContextId, $rightContextId);
        $narrativeDrift = $this->diffNarrativeBlock($leftContextId, $rightContextId);
        $memoryCompilationDiff = $this->diffMemoryCompilationsBlock($leftContextId, $rightContextId);
        $snapshotDiff = $this->diffSnapshotsBlock($leftContextId, $rightContextId);

        $leftPayload = [
            'context_id' => $leftContextId,
            'title' => (string)($leftRow['title'] ?? ''),
            'summary' => $leftSummary,
            'warnings' => $leftWarnings,
        ];
        $rightPayload = [
            'context_id' => $rightContextId,
            'title' => (string)($rightRow['title'] ?? ''),
            'summary' => $rightSummary,
            'warnings' => $rightWarnings,
        ];

        $diff = [
            'objectives' => $diffObjectives,
            'sessions' => $diffSessions,
            'decisions' => $diffDecisions,
            'risks' => $diffRisks,
            'beliefs' => $beliefDiff,
            'narrative_drift' => $narrativeDrift,
            'memory_compilations' => $memoryCompilationDiff,
            'snapshots' => $snapshotDiff,
            'agent_memory_differences' => $diffAgentMemory,
            'social_dynamics_differences' => $diffSocial,
            'timeline_differences' => $diffTimeline,
        ];
        $diff['runtime_meta'] = [
            'service' => 'StrategicContextComparisonService',
            'runtime_ms' => (int)round((microtime(true) - $t0) * 1000),
            'cache_entries' => count($this->requestCache),
            'read_only' => true,
        ];

        $markdown = $this->buildMarkdown($leftPayload, $rightPayload, $diff);

        return [
            'ok' => true,
            'left' => $leftPayload,
            'right' => $rightPayload,
            'diff' => $diff,
            'markdown' => $markdown,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loadSessionsForContext(string $contextId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT s.id, s.title, s.mode, s.status, s.created_at, s.strategic_context_id,
                   s.decision_brief, s.result
            FROM sessions s
            LEFT JOIN strategic_context_sessions scs
              ON scs.session_id = s.id AND scs.context_id = :c_join
            WHERE s.strategic_context_id = :c_col OR scs.session_id IS NOT NULL
            ORDER BY s.created_at DESC
            LIMIT 200
        ');
        $stmt->execute([':c_join' => $contextId, ':c_col' => $contextId]);
        return array_map(static fn ($r) => is_array($r) ? $r : [], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<string> $sessionIds
     * @return array{verdict:list<string>,risk:list<string>,evidence:list<string>}
     */
    private function groupedSessionSignals(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return $this->emptySessionSignals();
        }
        $clean = array_values(array_filter(array_map('strval', $sessionIds), static fn (string $s): bool => trim($s) !== ''));
        if ($clean === []) {
            return $this->emptySessionSignals();
        }
        sort($clean);
        $cacheKey = 'session-signals:' . hash('sha256', implode('|', $clean));

        return $this->cached($cacheKey, function () use ($clean): array {
            $signals = $this->emptySessionSignals();
            $chunked = array_chunk($clean, 180);
            foreach ($chunked as $ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $queries = [
                    'verdict' => "SELECT DISTINCT session_id FROM session_verdicts WHERE session_id IN ($ph)",
                    'risk' => "SELECT DISTINCT session_id FROM session_risk_profiles WHERE session_id IN ($ph)",
                    'evidence' => "SELECT DISTINCT session_id FROM evidence_reports WHERE session_id IN ($ph)",
                ];
                foreach ($queries as $k => $sql) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($ids);
                    $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];
                    foreach ($rows as $sid) {
                        $v = trim((string)$sid);
                        if ($v !== '' && !in_array($v, $signals[$k], true)) {
                            $signals[$k][] = $v;
                        }
                    }
                }
            }
            sort($signals['verdict']);
            sort($signals['risk']);
            sort($signals['evidence']);
            return $signals;
        });
    }

    /** @return array{verdict:list<string>,risk:list<string>,evidence:list<string>} */
    private function emptySessionSignals(): array
    {
        return ['verdict' => [], 'risk' => [], 'evidence' => []];
    }

    /** @param list<array<string,mixed>> $rows */
    /** @return array<string,array<string,mixed>> */
    private function indexSessionsById(array $rows): array
    {
        $m = [];
        foreach ($rows as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id !== '') {
                $m[$id] = $r;
            }
        }
        return $m;
    }

    /** @param array<string,mixed> $row */
    /** @return array<string,mixed> */
    private function buildSideSummary(
        string $contextId,
        array $row,
        array $sessions,
        array $sessionSignals,
        bool $includeSessions,
        bool $includeDecisions,
        bool $includeSocialDynamics,
        bool $includeTimeline
    ): array {
        $sessionCount = count($sessions);
        $completed = 0;
        foreach ($sessions as $s) {
            if (($s['status'] ?? '') === 'completed') {
                $completed++;
            }
        }
        $linkedMemories = $this->contextRepo->linkedMemoryIds($contextId);
        $decisionRows = $includeDecisions
            ? $this->decisionRepo->findLinkedMemoriesForStrategicContext($contextId, 30)
            : [];
        $relCount = null;
        $eventCount = null;
        if ($includeSocialDynamics) {
            $relCount = count($this->relRepo->findByStrategicContext($contextId));
            $eventCount = count($this->relRepo->findEventsByStrategicContext($contextId));
        }

        $verdictSessions = $includeSessions ? count($sessionSignals['verdict']) : 0;
        $riskSessions = $includeSessions ? count($sessionSignals['risk']) : 0;
        $evidenceSessions = $includeSessions ? count($sessionSignals['evidence']) : 0;

        $timelineItemCount = null;
        if ($includeTimeline) {
            $timeline = $this->cached('timeline:' . $contextId, fn () => $this->timelineSvc->build($contextId, false));
            $timelineItemCount = count($timeline['items'] ?? []);
        }
        $beliefRows = $this->cached('beliefs:' . $contextId, fn () => $this->beliefSvc->listBeliefsForContext($contextId, ['limit' => 500]));
        $narrative = $this->cached('narrative:' . $contextId, fn () => $this->narrativeSvc->getApiResponse($contextId));
        $compilations = $this->cached(
            'compilations:' . $contextId,
            fn () => $this->memoryCompilerSvc->listCompilations($contextId, ['status' => 'active', 'limit' => 24])
        );
        $snapshots = $this->cached(
            'snapshots:' . $contextId,
            fn () => $this->snapshotSvc->listSnapshots($contextId, ['limit' => 24])
        );
        $contestedBeliefs = 0;
        $invalidatedBeliefs = 0;
        foreach ($beliefRows as $b) {
            if (!is_array($b)) {
                continue;
            }
            $state = (string)($b['contestation_state'] ?? 'weak');
            if (in_array($state, ['contested', 'unstable'], true)) {
                $contestedBeliefs++;
            }
            if ($state === 'invalidated' || (string)($b['status'] ?? '') === 'invalidated') {
                $invalidatedBeliefs++;
            }
        }
        $narrativeObj = is_array($narrative['narrative'] ?? null) ? $narrative['narrative'] : [];
        $narrativeAssumptions = is_array($narrativeObj['key_assumptions'] ?? null) ? $narrativeObj['key_assumptions'] : [];
        $narrativeConflicts = is_array($narrativeObj['unresolved_conflicts'] ?? null) ? $narrativeObj['unresolved_conflicts'] : [];

        return [
            'status' => (string)($row['status'] ?? ''),
            'description_preview' => $this->snippet((string)($row['description'] ?? ''), 220),
            'session_count' => $includeSessions ? $sessionCount : null,
            'completed_session_count' => $includeSessions ? $completed : null,
            'linked_strategic_memory_ids_count' => count($linkedMemories),
            'linked_decision_memories_sample_count' => $includeDecisions ? count($decisionRows) : null,
            'sessions_with_verdict' => $includeSessions ? $verdictSessions : null,
            'sessions_with_risk_profile' => $includeSessions ? $riskSessions : null,
            'sessions_with_evidence_report' => $includeSessions ? $evidenceSessions : null,
            'agent_relationship_rows' => $relCount,
            'relationship_event_rows' => $eventCount,
            'timeline_item_count' => $timelineItemCount,
            'belief_count' => count($beliefRows),
            'belief_contested_count' => $contestedBeliefs,
            'belief_invalidated_count' => $invalidatedBeliefs,
            'narrative_key_assumptions_count' => count($narrativeAssumptions),
            'narrative_unresolved_conflicts_count' => count($narrativeConflicts),
            'memory_compilation_active_count' => count($compilations),
            'snapshot_count' => count($snapshots),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function diffObjectives(array $leftRow, array $rightRow): array
    {
        $out = [];
        $lt = trim((string)($leftRow['title'] ?? ''));
        $rt = trim((string)($rightRow['title'] ?? ''));
        if ($lt !== $rt) {
            $out[] = ['axis' => 'title', 'left' => $lt, 'right' => $rt];
        }
        $ls = trim((string)($leftRow['status'] ?? ''));
        $rs = trim((string)($rightRow['status'] ?? ''));
        if ($ls !== $rs) {
            $out[] = ['axis' => 'status', 'left' => $ls, 'right' => $rs];
        }
        $ld = preg_replace('/\s+/', ' ', trim((string)($leftRow['description'] ?? '')));
        $rd = preg_replace('/\s+/', ' ', trim((string)($rightRow['description'] ?? '')));
        if ($ld !== $rd) {
            $out[] = [
                'axis' => 'description',
                'left_preview' => $this->snippet($ld, 160),
                'right_preview' => $this->snippet($rd, 160),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $leftMap
     * @param array<string,array<string,mixed>> $rightMap
     * @return array<string,mixed>
     */
    private function diffSessionsBlock(array $leftMap, array $rightMap): array
    {
        $lids = array_keys($leftMap);
        $rids = array_keys($rightMap);
        $onlyLeft = array_values(array_diff($lids, $rids));
        $onlyRight = array_values(array_diff($rids, $lids));
        $shared = array_values(array_intersect($lids, $rids));

        $modeMismatches = [];
        foreach ($shared as $sid) {
            $lm = (string)($leftMap[$sid]['mode'] ?? '');
            $rm = (string)($rightMap[$sid]['mode'] ?? '');
            $ls = (string)($leftMap[$sid]['status'] ?? '');
            $rs = (string)($rightMap[$sid]['status'] ?? '');
            if ($lm !== $rm || $ls !== $rs) {
                $modeMismatches[] = [
                    'session_id' => $sid,
                    'left_mode' => $lm,
                    'right_mode' => $rm,
                    'left_status' => $ls,
                    'right_status' => $rs,
                ];
            }
        }

        return [
            'only_in_left' => array_slice(array_map(fn ($id) => $this->sessionBrief($leftMap[$id] ?? []), $onlyLeft), 0, 40),
            'only_in_right' => array_slice(array_map(fn ($id) => $this->sessionBrief($rightMap[$id] ?? []), $onlyRight), 0, 40),
            'shared_session_count' => count($shared),
            'shared_mode_or_status_mismatch' => $modeMismatches,
        ];
    }

    /** @param array<string,mixed> $s */
    /** @return array<string,string> */
    private function sessionBrief(array $s): array
    {
        return [
            'id' => (string)($s['id'] ?? ''),
            'title' => $this->snippet((string)($s['title'] ?? ''), 120),
            'mode' => (string)($s['mode'] ?? ''),
            'status' => (string)($s['status'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function diffDecisionsBlock(string $leftId, string $rightId): array
    {
        $lrows = $this->decisionRepo->findLinkedMemoriesForStrategicContext($leftId, 30);
        $rrows = $this->decisionRepo->findLinkedMemoriesForStrategicContext($rightId, 30);
        $lids = [];
        foreach ($lrows as $r) {
            $mid = (string)($r['memory_id'] ?? '');
            if ($mid !== '') {
                $lids[$mid] = $r;
            }
        }
        $rids = [];
        foreach ($rrows as $r) {
            $mid = (string)($r['memory_id'] ?? '');
            if ($mid !== '') {
                $rids[$mid] = $r;
            }
        }
        $onlyL = array_values(array_diff(array_keys($lids), array_keys($rids)));
        $onlyR = array_values(array_diff(array_keys($rids), array_keys($lids)));

        $brief = static function (array $row): array {
            return [
                'memory_id' => (string)($row['memory_id'] ?? ''),
                'decision_status' => (string)($row['decision_status'] ?? ''),
                'playbook_id' => (string)($row['playbook_id'] ?? ''),
                'summary_snippet' => mb_substr(trim((string)($row['decision_summary'] ?? '')), 0, 140, 'UTF-8'),
            ];
        };

        return [
            'only_left' => array_map(fn ($id) => $brief($lids[$id]), array_slice($onlyL, 0, 20)),
            'only_right' => array_map(fn ($id) => $brief($rids[$id]), array_slice($onlyR, 0, 20)),
            'shared_count' => count(array_intersect(array_keys($lids), array_keys($rids))),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $leftMap
     * @param array<string,array<string,mixed>> $rightMap
     * @return array<string,mixed>
     */
    private function diffRisksEvidenceBlock(
        array $leftMap,
        array $rightMap,
        array $leftSignals,
        array $rightSignals
    ): array
    {
        $leftKeys = array_keys($leftMap);
        $rightKeys = array_keys($rightMap);
        $L = [
            'risk' => array_values(array_intersect($leftKeys, is_array($leftSignals['risk'] ?? null) ? $leftSignals['risk'] : [])),
            'evidence' => array_values(array_intersect($leftKeys, is_array($leftSignals['evidence'] ?? null) ? $leftSignals['evidence'] : [])),
            'verdict' => array_values(array_intersect($leftKeys, is_array($leftSignals['verdict'] ?? null) ? $leftSignals['verdict'] : [])),
        ];
        $R = [
            'risk' => array_values(array_intersect($rightKeys, is_array($rightSignals['risk'] ?? null) ? $rightSignals['risk'] : [])),
            'evidence' => array_values(array_intersect($rightKeys, is_array($rightSignals['evidence'] ?? null) ? $rightSignals['evidence'] : [])),
            'verdict' => array_values(array_intersect($rightKeys, is_array($rightSignals['verdict'] ?? null) ? $rightSignals['verdict'] : [])),
        ];
        return [
            'sessions_with_risk_only_left' => array_values(array_diff($L['risk'], $R['risk'])),
            'sessions_with_risk_only_right' => array_values(array_diff($R['risk'], $L['risk'])),
            'sessions_with_evidence_only_left' => array_values(array_diff($L['evidence'], $R['evidence'])),
            'sessions_with_evidence_only_right' => array_values(array_diff($R['evidence'], $L['evidence'])),
            'sessions_with_verdict_only_left' => array_values(array_diff($L['verdict'], $R['verdict'])),
            'sessions_with_verdict_only_right' => array_values(array_diff($R['verdict'], $L['verdict'])),
        ];
    }

    /**
     * @param list<string> $leftWarn
     * @param list<string> $rightWarn
     * @return list<array<string,mixed>>
     */
    private function diffAgentMemories(
        string $leftId,
        string $rightId,
        array &$leftWarn,
        array &$rightWarn
    ): array {
        $out = [];
        foreach (self::AGENTS_FOR_MEMORY_COMPARE as $agentId) {
            $l = $this->agentMemSvc->readIfExistsNoSideEffects($leftId, $agentId);
            $r = $this->agentMemSvc->readIfExistsNoSideEffects($rightId, $agentId);
            if (!$l['exists']) {
                $leftWarn[] = "Agent memory file missing for agent `{$agentId}` (left context).";
            }
            if (!$r['exists']) {
                $rightWarn[] = "Agent memory file missing for agent `{$agentId}` (right context).";
            }
            $lSec = $this->parseMarkdownSections((string)$l['content']);
            $rSec = $this->parseMarkdownSections((string)$r['content']);
            $presentOnlyLeft = array_values(array_diff(array_keys($lSec), array_keys($rSec)));
            $presentOnlyRight = array_values(array_diff(array_keys($rSec), array_keys($lSec)));
            if ($presentOnlyLeft !== [] || $presentOnlyRight !== []) {
                $out[] = [
                    'agent_id' => $agentId,
                    'kind' => 'section_presence',
                    'only_left_sections' => array_slice($presentOnlyLeft, 0, 12),
                    'only_right_sections' => array_slice($presentOnlyRight, 0, 12),
                ];
            }
            foreach (self::MEMORY_FOCUS_SECTIONS as $title) {
                $lt = trim((string)($lSec[$title] ?? ''));
                $rt = trim((string)($rSec[$title] ?? ''));
                if ($lt === '' && $rt === '') {
                    continue;
                }
                if ($lt === $rt) {
                    continue;
                }
                $out[] = [
                    'agent_id' => $agentId,
                    'kind' => 'section_body',
                    'section' => $title,
                    'left_snippet' => $this->snippet($lt, 160),
                    'right_snippet' => $this->snippet($rt, 160),
                ];
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private function parseMarkdownSections(string $md): array
    {
        $md = str_replace("\r\n", "\n", $md);
        $md = trim($md);
        if ($md === '') {
            return [];
        }
        $parts = preg_split('/\n(?=## )/', $md) ?: [];
        $map = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !preg_match('/^##\s+(.+)$/m', $part, $m)) {
                continue;
            }
            $title = trim($m[1]);
            $body = trim(substr($part, strlen($m[0])));
            $map[$title] = $body;
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function diffSocialDynamicsBlock(string $leftId, string $rightId): array
    {
        $lRels = $this->relRepo->findByStrategicContext($leftId);
        $rRels = $this->relRepo->findByStrategicContext($rightId);
        $lMap = $this->normalizeRelationshipPairs($lRels);
        $rMap = $this->normalizeRelationshipPairs($rRels);

        $onlyL = array_diff_key($lMap, $rMap);
        $onlyR = array_diff_key($rMap, $lMap);
        $shared = array_intersect_key($lMap, $rMap);

        $tensionNotes = [];
        foreach ($shared as $pk => $lv) {
            $rv = $rMap[$pk];
            $lc = (float)($lv['conflict'] ?? 0);
            $rc = (float)($rv['conflict'] ?? 0);
            if (abs($lc - $rc) < 0.08 && (string)($lv['last_interaction_type'] ?? '') === (string)($rv['last_interaction_type'] ?? '')) {
                continue;
            }
            $tensionNotes[] = [
                'pair' => $pk,
                'left_conflict' => $lc,
                'right_conflict' => $rc,
                'left_last' => (string)($lv['last_interaction_type'] ?? ''),
                'right_last' => (string)($rv['last_interaction_type'] ?? ''),
            ];
        }

        $lEvents = $this->relRepo->findEventsByStrategicContext($leftId);
        $rEvents = $this->relRepo->findEventsByStrategicContext($rightId);
        $countTypes = static function (array $events): array {
            $c = [];
            foreach ($events as $e) {
                $t = (string)($e['event_type'] ?? 'unknown');
                $c[$t] = ($c[$t] ?? 0) + 1;
            }
            ksort($c);
            return $c;
        };
        $lc = $countTypes($lEvents);
        $rc = $countTypes($rEvents);
        $eventDiff = [];
        $keys = array_unique(array_merge(array_keys($lc), array_keys($rc)));
        sort($keys);
        foreach ($keys as $k) {
            $a = $lc[$k] ?? 0;
            $b = $rc[$k] ?? 0;
            if ($a !== $b) {
                $eventDiff[] = ['event_type' => $k, 'left_count' => $a, 'right_count' => $b];
            }
        }

        $serializePairs = static function (array $pairs, int $max): array {
            $out = [];
            $i = 0;
            foreach ($pairs as $pk => $meta) {
                if ($i++ >= $max) {
                    break;
                }
                $out[] = array_merge(['pair' => $pk], $meta);
            }
            return $out;
        };

        return [
            'relationship_pairs_only_left' => $serializePairs($onlyL, 24),
            'relationship_pairs_only_right' => $serializePairs($onlyR, 24),
            'shared_pair_metric_deltas' => array_slice($tensionNotes, 0, 24),
            'relationship_event_type_counts_diff' => $eventDiff,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function normalizeRelationshipPairs(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $src = strtolower(trim((string)($r['source_agent_id'] ?? '')));
            $tgt = strtolower(trim((string)($r['target_agent_id'] ?? '')));
            if ($src === '' || $tgt === '') {
                continue;
            }
            $pk = strcmp($src, $tgt) <= 0 ? $src . '↔' . $tgt : $tgt . '↔' . $src;
            $out[$pk] = [
                'affinity' => (float)($r['affinity'] ?? 0),
                'trust' => (float)($r['trust'] ?? 0),
                'conflict' => (float)($r['conflict'] ?? 0),
                'last_interaction_type' => (string)($r['last_interaction_type'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function diffTimelinesBlock(string $leftId, string $rightId): array
    {
        $l = $this->timelineSvc->build($leftId, false);
        $r = $this->timelineSvc->build($rightId, false);
        $lKeys = $this->timelineItemKeys($l['items'] ?? []);
        $rKeys = $this->timelineItemKeys($r['items'] ?? []);
        $onlyL = array_values(array_diff($lKeys, $rKeys));
        $onlyR = array_values(array_diff($rKeys, $lKeys));

        $countByType = static function (array $items): array {
            $c = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $t = (string)($it['type'] ?? '');
                $c[$t] = ($c[$t] ?? 0) + 1;
            }
            ksort($c);
            return $c;
        };
        $lc = $countByType($l['items'] ?? []);
        $rc = $countByType($r['items'] ?? []);
        $typeDiff = [];
        $types = array_unique(array_merge(array_keys($lc), array_keys($rc)));
        sort($types);
        foreach ($types as $t) {
            $a = $lc[$t] ?? 0;
            $b = $rc[$t] ?? 0;
            if ($a !== $b) {
                $typeDiff[] = ['type' => $t, 'left' => $a, 'right' => $b];
            }
        }

        return [
            'timeline_item_keys_only_left' => array_slice($onlyL, 0, 40),
            'timeline_item_keys_only_right' => array_slice($onlyR, 0, 40),
            'counts_by_type_diff' => $typeDiff,
            'left_legacy_count' => (int)($l['legacy_count'] ?? 0),
            'right_legacy_count' => (int)($r['legacy_count'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function diffBeliefsBlock(string $leftId, string $rightId): array
    {
        $leftRows = $this->cached('beliefs:' . $leftId, fn () => $this->beliefSvc->listBeliefsForContext($leftId, ['limit' => 500]));
        $rightRows = $this->cached('beliefs:' . $rightId, fn () => $this->beliefSvc->listBeliefsForContext($rightId, ['limit' => 500]));
        $leftMap = [];
        foreach ($leftRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sig = $this->beliefSignature($row);
            if ($sig !== '') {
                $leftMap[$sig] = $row;
            }
        }
        $rightMap = [];
        foreach ($rightRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sig = $this->beliefSignature($row);
            if ($sig !== '') {
                $rightMap[$sig] = $row;
            }
        }
        $onlyLeftKeys = array_values(array_diff(array_keys($leftMap), array_keys($rightMap)));
        $onlyRightKeys = array_values(array_diff(array_keys($rightMap), array_keys($leftMap)));
        $shared = array_values(array_intersect(array_keys($leftMap), array_keys($rightMap)));

        $contradictory = [];
        $confidenceDivergence = [];
        $invalidationDifferences = [];
        foreach ($shared as $sig) {
            $l = $leftMap[$sig];
            $r = $rightMap[$sig];
            $lState = (string)($l['contestation_state'] ?? 'weak');
            $rState = (string)($r['contestation_state'] ?? 'weak');
            $lStatus = (string)($l['status'] ?? '');
            $rStatus = (string)($r['status'] ?? '');
            $lConf = (float)($l['confidence'] ?? 0.5);
            $rConf = (float)($r['confidence'] ?? 0.5);
            $delta = round(abs($lConf - $rConf), 3);
            if ($lState !== $rState || $lStatus !== $rStatus) {
                $contradictory[] = [
                    'signature' => $sig,
                    'belief_text' => (string)($l['belief_text'] ?? $r['belief_text'] ?? ''),
                    'left_state' => $lState,
                    'right_state' => $rState,
                    'left_status' => $lStatus,
                    'right_status' => $rStatus,
                ];
            }
            if ($delta >= 0.15) {
                $confidenceDivergence[] = [
                    'signature' => $sig,
                    'belief_text' => (string)($l['belief_text'] ?? $r['belief_text'] ?? ''),
                    'left_confidence' => $lConf,
                    'right_confidence' => $rConf,
                    'delta' => $delta,
                ];
            }
            $linv = (string)($l['invalidated_by'] ?? '');
            $rinv = (string)($r['invalidated_by'] ?? '');
            $lreason = (string)($l['invalidation_reason'] ?? '');
            $rreason = (string)($r['invalidation_reason'] ?? '');
            if ($linv !== $rinv || $lreason !== $rreason) {
                $invalidationDifferences[] = [
                    'signature' => $sig,
                    'belief_text' => (string)($l['belief_text'] ?? $r['belief_text'] ?? ''),
                    'left_invalidated_by' => $linv,
                    'right_invalidated_by' => $rinv,
                    'left_reason' => $this->snippet($lreason, 140),
                    'right_reason' => $this->snippet($rreason, 140),
                ];
            }
        }
        usort($confidenceDivergence, static fn (array $a, array $b): int => ($b['delta'] <=> $a['delta']));

        $leftContested = 0;
        $rightContested = 0;
        $leftInvalidated = 0;
        $rightInvalidated = 0;
        $leftConsensus = 0.0;
        $rightConsensus = 0.0;
        $leftDrift = 0.0;
        $rightDrift = 0.0;
        foreach ($leftRows as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (in_array((string)($b['contestation_state'] ?? ''), ['contested', 'unstable'], true)) {
                $leftContested++;
            }
            if ((string)($b['contestation_state'] ?? '') === 'invalidated' || (string)($b['status'] ?? '') === 'invalidated') {
                $leftInvalidated++;
            }
            $leftConsensus += (float)($b['consensus_score'] ?? 0.0);
            $leftDrift += (float)($b['drift_score'] ?? 0.0);
        }
        foreach ($rightRows as $b) {
            if (!is_array($b)) {
                continue;
            }
            if (in_array((string)($b['contestation_state'] ?? ''), ['contested', 'unstable'], true)) {
                $rightContested++;
            }
            if ((string)($b['contestation_state'] ?? '') === 'invalidated' || (string)($b['status'] ?? '') === 'invalidated') {
                $rightInvalidated++;
            }
            $rightConsensus += (float)($b['consensus_score'] ?? 0.0);
            $rightDrift += (float)($b['drift_score'] ?? 0.0);
        }
        $leftN = max(1, count($leftRows));
        $rightN = max(1, count($rightRows));

        return [
            'only_left' => array_slice(array_map(fn (string $k): array => $this->beliefBrief($leftMap[$k] ?? []), $onlyLeftKeys), 0, 40),
            'only_right' => array_slice(array_map(fn (string $k): array => $this->beliefBrief($rightMap[$k] ?? []), $onlyRightKeys), 0, 40),
            'shared_count' => count($shared),
            'contradictory' => array_slice($contradictory, 0, 40),
            'invalidated_differences' => array_slice($invalidationDifferences, 0, 30),
            'confidence_divergence' => array_slice($confidenceDivergence, 0, 40),
            'contested_divergence' => [
                'left_contested_count' => $leftContested,
                'right_contested_count' => $rightContested,
                'delta' => $leftContested - $rightContested,
            ],
            'belief_drift' => [
                'left_avg_drift_score' => round($leftDrift / $leftN, 3),
                'right_avg_drift_score' => round($rightDrift / $rightN, 3),
                'delta' => round(($leftDrift / $leftN) - ($rightDrift / $rightN), 3),
            ],
            'consensus_divergence' => [
                'left_avg_consensus' => round($leftConsensus / $leftN, 3),
                'right_avg_consensus' => round($rightConsensus / $rightN, 3),
                'delta' => round(($leftConsensus / $leftN) - ($rightConsensus / $rightN), 3),
            ],
            'invalidation_differences' => [
                'left_invalidated_count' => $leftInvalidated,
                'right_invalidated_count' => $rightInvalidated,
                'delta' => $leftInvalidated - $rightInvalidated,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function diffNarrativeBlock(string $leftId, string $rightId): array
    {
        $left = $this->cached('narrative:' . $leftId, fn () => $this->narrativeSvc->getApiResponse($leftId));
        $right = $this->cached('narrative:' . $rightId, fn () => $this->narrativeSvc->getApiResponse($rightId));
        $ln = is_array($left['narrative'] ?? null) ? $left['narrative'] : [];
        $rn = is_array($right['narrative'] ?? null) ? $right['narrative'] : [];
        $lAss = is_array($ln['key_assumptions'] ?? null) ? array_map('strval', $ln['key_assumptions']) : [];
        $rAss = is_array($rn['key_assumptions'] ?? null) ? array_map('strval', $rn['key_assumptions']) : [];
        $lConf = is_array($ln['unresolved_conflicts'] ?? null) ? array_map('strval', $ln['unresolved_conflicts']) : [];
        $rConf = is_array($rn['unresolved_conflicts'] ?? null) ? array_map('strval', $rn['unresolved_conflicts']) : [];
        $lShifts = is_array($ln['recent_shifts'] ?? null) ? array_map('strval', $ln['recent_shifts']) : [];
        $rShifts = is_array($rn['recent_shifts'] ?? null) ? array_map('strval', $rn['recent_shifts']) : [];
        $driftAxes = [];
        if ((string)($ln['current_direction'] ?? '') !== (string)($rn['current_direction'] ?? '')) {
            $driftAxes[] = 'current_direction';
        }
        if ((string)($ln['confidence_trend'] ?? '') !== (string)($rn['confidence_trend'] ?? '')) {
            $driftAxes[] = 'confidence_trend';
        }
        if ($lAss !== $rAss) {
            $driftAxes[] = 'key_assumptions';
        }
        if ($lConf !== $rConf) {
            $driftAxes[] = 'unresolved_conflicts';
        }
        if ($lShifts !== $rShifts) {
            $driftAxes[] = 'recent_shifts';
        }
        $summary = 'Narratives proches.';
        if ($driftAxes !== []) {
            $summary = 'Narrative drift détecté sur: ' . implode(', ', $driftAxes) . '.';
        }
        return [
            'current_direction' => [
                'left' => $this->snippet((string)($ln['current_direction'] ?? ''), 240),
                'right' => $this->snippet((string)($rn['current_direction'] ?? ''), 240),
            ],
            'key_assumptions_only_left' => array_slice(array_values(array_diff($lAss, $rAss)), 0, 24),
            'key_assumptions_only_right' => array_slice(array_values(array_diff($rAss, $lAss)), 0, 24),
            'unresolved_conflicts_only_left' => array_slice(array_values(array_diff($lConf, $rConf)), 0, 24),
            'unresolved_conflicts_only_right' => array_slice(array_values(array_diff($rConf, $lConf)), 0, 24),
            'confidence_trend' => [
                'left' => (string)($ln['confidence_trend'] ?? ''),
                'right' => (string)($rn['confidence_trend'] ?? ''),
            ],
            'recent_shifts_only_left' => array_slice(array_values(array_diff($lShifts, $rShifts)), 0, 24),
            'recent_shifts_only_right' => array_slice(array_values(array_diff($rShifts, $lShifts)), 0, 24),
            'narrative_drift_summary' => $summary,
        ];
    }

    /** @return array<string,mixed> */
    private function diffMemoryCompilationsBlock(string $leftId, string $rightId): array
    {
        $left = $this->cached(
            'compilations:' . $leftId,
            fn () => $this->memoryCompilerSvc->listCompilations($leftId, ['status' => 'active', 'limit' => 40])
        );
        $right = $this->cached(
            'compilations:' . $rightId,
            fn () => $this->memoryCompilerSvc->listCompilations($rightId, ['status' => 'active', 'limit' => 40])
        );
        $mapTypes = static function (array $rows): array {
            $types = [];
            $sumConf = 0.0;
            $sumStability = 0.0;
            $n = 0;
            $latestByType = [];
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $t = (string)($r['compilation_type'] ?? '');
                if ($t === '') {
                    continue;
                }
                $types[$t] = true;
                $sumConf += (float)($r['confidence'] ?? 0);
                $sumStability += (float)($r['stability_score'] ?? 0);
                $n++;
                if (!isset($latestByType[$t])) {
                    $latestByType[$t] = [
                        'id' => (string)($r['id'] ?? ''),
                        'summary' => mb_substr(trim((string)($r['summary'] ?? '')), 0, 180, 'UTF-8'),
                        'created_at' => (string)($r['created_at'] ?? ''),
                    ];
                }
            }
            return [
                'types' => array_keys($types),
                'avg_confidence' => $n > 0 ? round($sumConf / $n, 3) : 0.0,
                'avg_stability' => $n > 0 ? round($sumStability / $n, 3) : 0.0,
                'latest_by_type' => $latestByType,
            ];
        };
        $l = $mapTypes($left);
        $r = $mapTypes($right);
        sort($l['types']);
        sort($r['types']);

        return [
            'active_count_left' => count($left),
            'active_count_right' => count($right),
            'types_only_left' => array_values(array_diff($l['types'], $r['types'])),
            'types_only_right' => array_values(array_diff($r['types'], $l['types'])),
            'avg_confidence' => ['left' => $l['avg_confidence'], 'right' => $r['avg_confidence']],
            'avg_stability' => ['left' => $l['avg_stability'], 'right' => $r['avg_stability']],
            'latest_by_type_left' => $l['latest_by_type'],
            'latest_by_type_right' => $r['latest_by_type'],
        ];
    }

    /** @return array<string,mixed> */
    private function diffSnapshotsBlock(string $leftId, string $rightId): array
    {
        $leftList = $this->cached(
            'snapshots:' . $leftId,
            fn () => $this->snapshotSvc->listSnapshots($leftId, ['limit' => 12])
        );
        $rightList = $this->cached(
            'snapshots:' . $rightId,
            fn () => $this->snapshotSvc->listSnapshots($rightId, ['limit' => 12])
        );
        $latestLeft = is_array($leftList[0] ?? null) ? $leftList[0] : null;
        $latestRight = is_array($rightList[0] ?? null) ? $rightList[0] : null;
        $leftFull = null;
        $rightFull = null;
        if (is_array($latestLeft) && trim((string)($latestLeft['id'] ?? '')) !== '') {
            $sid = (string)$latestLeft['id'];
            $leftFull = $this->cached('snapshot-full:' . $leftId . ':' . $sid, fn () => $this->snapshotSvc->getSnapshot($leftId, $sid));
        }
        if (is_array($latestRight) && trim((string)($latestRight['id'] ?? '')) !== '') {
            $sid = (string)$latestRight['id'];
            $rightFull = $this->cached('snapshot-full:' . $rightId . ':' . $sid, fn () => $this->snapshotSvc->getSnapshot($rightId, $sid));
        }
        $extract = static function (?array $snap): array {
            if (!is_array($snap)) {
                return [
                    'beliefs' => 0,
                    'narrative_assumptions' => 0,
                    'memory_active' => 0,
                    'social_relationship_rows' => 0,
                    'timeline_items' => 0,
                ];
            }
            $beliefs = is_array($snap['beliefs_snapshot'] ?? null) ? $snap['beliefs_snapshot'] : [];
            $narr = is_array($snap['strategic_narrative'] ?? null) ? $snap['strategic_narrative'] : [];
            $memory = is_array($snap['memory_compilations_snapshot'] ?? null) ? $snap['memory_compilations_snapshot'] : [];
            $social = is_array($snap['social_snapshot'] ?? null) ? $snap['social_snapshot'] : [];
            $timeline = is_array($snap['timeline_snapshot'] ?? null) ? $snap['timeline_snapshot'] : [];
            $assEcho = is_array($narr['key_assumptions_echo'] ?? null) ? $narr['key_assumptions_echo'] : [];
            return [
                'beliefs' => (int)($beliefs['counts']['total'] ?? 0),
                'narrative_assumptions' => count($assEcho),
                'memory_active' => (int)($memory['active_count'] ?? 0),
                'social_relationship_rows' => (int)($social['relationship_rows'] ?? 0),
                'timeline_items' => is_array($timeline['recent_items'] ?? null) ? count($timeline['recent_items']) : 0,
            ];
        };
        $l = $extract(is_array($leftFull) ? $leftFull : null);
        $r = $extract(is_array($rightFull) ? $rightFull : null);
        return [
            'snapshot_count_left' => count($leftList),
            'snapshot_count_right' => count($rightList),
            'latest_left' => is_array($latestLeft) ? [
                'id' => (string)($latestLeft['id'] ?? ''),
                'created_at' => (string)($latestLeft['created_at'] ?? ''),
                'type' => (string)($latestLeft['snapshot_type'] ?? ''),
            ] : null,
            'latest_right' => is_array($latestRight) ? [
                'id' => (string)($latestRight['id'] ?? ''),
                'created_at' => (string)($latestRight['created_at'] ?? ''),
                'type' => (string)($latestRight['snapshot_type'] ?? ''),
            ] : null,
            'latest_snapshot_counts' => [
                'beliefs' => ['left' => $l['beliefs'], 'right' => $r['beliefs']],
                'narrative_assumptions' => ['left' => $l['narrative_assumptions'], 'right' => $r['narrative_assumptions']],
                'active_memory' => ['left' => $l['memory_active'], 'right' => $r['memory_active']],
                'social_state_rows' => ['left' => $l['social_relationship_rows'], 'right' => $r['social_relationship_rows']],
                'cognitive_timeline_items' => ['left' => $l['timeline_items'], 'right' => $r['timeline_items']],
            ],
            'restore_policy' => 'read_only_no_restore_no_mutation',
        ];
    }

    /** @param list<array<string,mixed>> $items */
    /** @return list<string> */
    private function timelineItemKeys(array $items): array
    {
        $keys = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $t = (string)($it['type'] ?? '');
            $id = (string)($it['id'] ?? '');
            if ($t !== '' && $id !== '') {
                $keys[] = $t . ':' . $id;
            }
        }
        sort($keys);
        return $keys;
    }

    /** @param array<string,mixed> $row */
    private function beliefSignature(array $row): string
    {
        $text = trim((string)($row['belief_text'] ?? ''));
        if ($text === '') {
            return '';
        }
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text, 'UTF-8');
        $type = mb_strtolower(trim((string)($row['belief_type'] ?? 'belief')), 'UTF-8');
        $agent = mb_strtolower(trim((string)($row['agent_id'] ?? 'group')), 'UTF-8');
        return $type . '|' . $agent . '|' . $normalized;
    }

    /** @param array<string,mixed> $row */
    private function beliefBrief(array $row): array
    {
        return [
            'id' => (string)($row['id'] ?? ''),
            'belief_type' => (string)($row['belief_type'] ?? ''),
            'agent_id' => (string)($row['agent_id'] ?? ''),
            'belief_text' => $this->snippet((string)($row['belief_text'] ?? ''), 180),
            'status' => (string)($row['status'] ?? ''),
            'contestation_state' => (string)($row['contestation_state'] ?? 'weak'),
            'confidence' => (float)($row['confidence'] ?? 0.5),
            'drift_score' => (float)($row['drift_score'] ?? 0.0),
            'consensus_score' => (float)($row['consensus_score'] ?? 0.0),
        ];
    }

    /**
     * @template T
     * @param callable():T $resolver
     * @return T
     */
    private function cached(string $key, callable $resolver): mixed
    {
        if (array_key_exists($key, $this->requestCache)) {
            return $this->requestCache[$key];
        }
        $v = $resolver();
        $this->requestCache[$key] = $v;
        return $v;
    }

    private function snippet(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<string,mixed> $diff
     */
    private function buildMarkdown(array $left, array $right, array $diff): string
    {
        $lines = [];
        $lines[] = '# Strategic Context Comparison';
        $lines[] = '';
        $lines[] = '## Compared Contexts';
        $lines[] = '- **Left:** `' . ($left['context_id'] ?? '') . '` — ' . $this->snippet((string)($left['title'] ?? ''), 120);
        $lines[] = '- **Right:** `' . ($right['context_id'] ?? '') . '` — ' . $this->snippet((string)($right['title'] ?? ''), 120);
        $lines[] = '';
        $lines[] = '## Executive Summary';
        $lines[] = '| Metric | Left | Right |';
        $lines[] = '|--------|------|-------|';
        $ls = $left['summary'] ?? [];
        $rs = $right['summary'] ?? [];
        foreach (['status', 'session_count', 'timeline_item_count', 'agent_relationship_rows'] as $k) {
            $lv = $ls[$k] ?? '—';
            $rv = $rs[$k] ?? '—';
            $lines[] = '| ' . $k . ' | ' . $lv . ' | ' . $rv . ' |';
        }
        $lines[] = '';
        $lines[] = '## Objectives';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['objectives'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Sessions';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['sessions'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Decisions';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['decisions'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Beliefs Diff';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['beliefs'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Narrative Drift';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['narrative_drift'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Memory Compilations Diff';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['memory_compilations'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Snapshot Diff';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['snapshots'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Risks / Evidence';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['risks'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Agent Memory Differences';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['agent_memory_differences'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Social Dynamics Differences';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['social_dynamics_differences'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Timeline Differences';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['timeline_differences'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Runtime Meta';
        $lines[] = '```json';
        $lines[] = $this->jsonSnippet($diff['runtime_meta'] ?? []);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Warnings';
        $lw = $left['warnings'] ?? [];
        $rw = $right['warnings'] ?? [];
        if (!is_array($lw)) {
            $lw = [];
        }
        if (!is_array($rw)) {
            $rw = [];
        }
        if ($lw === [] && $rw === []) {
            $lines[] = '_None._';
        } else {
            $leftBullet = '- **Left:** ';
            $rightBullet = '- **Right:** ';
            foreach ($lw as $warnLine) {
                $lines[] = $leftBullet . (is_string($warnLine) ? $warnLine : json_encode($warnLine, JSON_UNESCAPED_UNICODE));
            }
            foreach ($rw as $warnLine) {
                $lines[] = $rightBullet . (is_string($warnLine) ? $warnLine : json_encode($warnLine, JSON_UNESCAPED_UNICODE));
            }
        }
        return implode("\n", $lines);
    }

    /** @param mixed $data */
    private function jsonSnippet($data): string
    {
        $j = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($j)) {
            return '{}';
        }
        if (mb_strlen($j, 'UTF-8') > 9000) {
            return mb_substr($j, 0, 8990, 'UTF-8') . "\n…";
        }
        return $j;
    }
}

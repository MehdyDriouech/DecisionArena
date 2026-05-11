<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\CognitiveGovernance\CanonicalLayerMutationGuard;
use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Domain\CognitiveGovernance\DeterministicHash;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\StrategicContextMemoryCompilationRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Memory Compiler MVP : consolidation déterministe, interprétative, non canonique.
 * Ne modifie jamais beliefs, narrative persistée ni memory.md ; lecture seule + nouvelles lignes compilations.
 */
final class MemoryCompilerService
{
    public const COMPILATION_TYPES = [
        'working', 'strategic', 'social', 'risk', 'belief', 'postmortem', 'longitudinal',
    ];

    public const COMPILATION_STATUSES = ['active', 'archived', 'superseded', 'deprecated'];

    public function __construct(
        private ?StrategicContextRepository $contexts = null,
        private ?BeliefEngineService $beliefs = null,
        private ?StrategicNarrativeService $narrative = null,
        private ?WorkspaceTimelineService $timeline = null,
        private ?AgentContextMemoryService $agentMemory = null,
        private ?DecisionMemoryRepository $decisionMemories = null,
        private ?StrategicContextMemoryCompilationRepository $compilations = null,
        private ?\PDO $pdo = null,
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->beliefs = $beliefs ?? new BeliefEngineService();
        $this->narrative = $narrative ?? new StrategicNarrativeService();
        $this->timeline = $timeline ?? new WorkspaceTimelineService();
        $this->agentMemory = $agentMemory ?? new AgentContextMemoryService();
        $this->decisionMemories = $decisionMemories ?? new DecisionMemoryRepository();
        $this->compilations = $compilations ?? new StrategicContextMemoryCompilationRepository();
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * @param array{supersede_previous?:bool} $options
     * @return array{ok:true,compilation:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function compileContextMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'strategic', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compileBeliefMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'belief', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compileSocialMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'social', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compileRiskMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'risk', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compileLongitudinalMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'longitudinal', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compileWorkingMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'working', $createdBy, $options);
    }

    /** @param array{supersede_previous?:bool} $options */
    public function compilePostmortemMemory(string $contextId, ?string $createdBy = null, array $options = []): array
    {
        return $this->compile($contextId, 'postmortem', $createdBy, $options);
    }

    /**
     * @param array{supersede_previous?:bool} $options
     * @return array{ok:true,compilation:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function compile(string $contextId, string $compilationType, ?string $createdBy = null, array $options = []): array
    {
        $cid = trim($contextId);
        if ($this->contexts->find($cid) === null) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        $type = strtolower(trim($compilationType));
        if (!in_array($type, self::COMPILATION_TYPES, true)) {
            return ['ok' => false, 'message' => 'invalid compilation_type', 'code' => 400];
        }
        $supersedePrevious = array_key_exists('supersede_previous', $options)
            ? (bool)$options['supersede_previous']
            : true;

        $memoryHashBefore = null;
        $beliefHashBefore = null;
        if (CanonicalLayerMutationGuard::enabled()) {
            $memoryHashBefore = $this->memoryFilesHashForContext($cid);
            $beliefHashBefore = $this->beliefRowsHash($cid);
        }

        $snapshot = $this->gatherSnapshot($cid);
        $classified = $this->classifyBeliefs($snapshot['beliefs']);
        $patterns = $this->extractPatterns($cid, $snapshot, $classified);
        $built = $this->buildMarkdownAndMeta($cid, $type, $snapshot, $classified, $patterns);
        $scores = $this->computeScores($snapshot, $classified, $built['metadata']);

        $beliefCount = is_array($snapshot['beliefs'] ?? null) ? count($snapshot['beliefs']) : 0;
        $timelineCount = is_array($snapshot['timeline'] ?? null) ? count($snapshot['timeline']) : 0;
        $now = date('c');
        $id = $this->uuid();
        $persistSnapshot = $this->redactSnapshotForStorage($snapshot);
        $persistSnapshot['pipeline_version'] = 'memory-compiler-mvp-1';
        $snapshotJson = json_encode($persistSnapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($snapshotJson === false) {
            $snapshotJson = '{}';
        }
        $metaJson = json_encode($built['metadata'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($metaJson === false) {
            $metaJson = '{}';
        }
        $sourceHash = DeterministicHash::sha256(is_string($snapshotJson) ? $snapshotJson : '{}');
        $compilationHash = DeterministicHash::sha256([
            'context_id' => $cid,
            'compilation_type' => $type,
            'title' => $built['title'],
            'summary' => $built['summary'],
            'markdown' => $built['markdown'],
            'source_hash' => $sourceHash,
            'confidence' => $scores['confidence'],
            'stability_score' => $scores['stability_score'],
        ]);
        $inputHash = DeterministicHash::sha256([
            'belief_count' => $beliefCount,
            'timeline_count' => $timelineCount,
            'source_snapshot' => $persistSnapshot,
        ]);
        $built['metadata']['provenance_integrity'] = [
            'input_hash' => $inputHash,
            'source_hash' => $sourceHash,
            'compilation_hash' => $compilationHash,
            'runtime_hash' => DeterministicHash::sha256([
                'source_hash' => $sourceHash,
                'compilation_hash' => $compilationHash,
                'compilation_type' => $type,
            ]),
        ];
        $built['metadata']['cognitive_provenance'] = CognitiveProvenanceEnvelope::forMemoryCompilation($cid, $type, [
            'pipeline_version' => 'memory-compiler-mvp-1',
            'generated_at' => date('c'),
            'source_count' => $beliefCount + $timelineCount,
            'source_hash' => $sourceHash,
            'compilation_hash' => $compilationHash,
            'input_hashes' => [$inputHash],
            'pruned_sources' => [
                'belief_full_text_redacted_in_source_snapshot_json',
                'agent_memory_samples_truncated',
            ],
            'excluded_sources' => [
                'memory_md_files_not_mutated',
                'beliefs_not_modified_by_compiler',
            ],
        ]);
        $metaJson = json_encode($built['metadata'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($metaJson === false) {
            $metaJson = '{}';
        }

        if ($supersedePrevious) {
            $this->compilations->supersedeActiveByType($cid, $type);
        }

        $row = [
            'id' => $id,
            'strategic_context_id' => $cid,
            'compilation_type' => $type,
            'title' => $built['title'],
            'summary' => $built['summary'],
            'compiled_memory_markdown' => $built['markdown'],
            'source_snapshot_json' => $snapshotJson,
            'compilation_metadata_json' => $metaJson,
            'confidence' => $scores['confidence'],
            'stability_score' => $scores['stability_score'],
            'status' => 'active',
            'source_hash' => $sourceHash,
            'created_by' => $createdBy !== null && trim($createdBy) !== '' ? trim($createdBy) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->compilations->insert($row);

        $full = $this->compilations->findByIdInContext($id, $cid);
        if ($full === null) {
            return ['ok' => false, 'message' => 'persist_failed', 'code' => 500];
        }

        if (CanonicalLayerMutationGuard::enabled()) {
            $memoryHashAfter = $this->memoryFilesHashForContext($cid);
            if ($memoryHashBefore !== $memoryHashAfter) {
                CanonicalLayerMutationGuard::assertAllowed(
                    'memory_compiler',
                    'memory',
                    'overwrite',
                    ['strategic_context_id' => $cid]
                );
            }
            $beliefHashAfter = $this->beliefRowsHash($cid);
            if ($beliefHashBefore !== $beliefHashAfter) {
                CanonicalLayerMutationGuard::assertAllowed(
                    'memory_compiler',
                    'beliefs',
                    'mutate',
                    ['strategic_context_id' => $cid]
                );
            }
        }

        return ['ok' => true, 'compilation' => $this->rowToApi($full, true)];
    }

    private function memoryFilesHashForContext(string $contextId): string
    {
        $root = dirname(__DIR__, 3) . '/storage/strategic-contexts/' . strtolower(trim($contextId));
        if (!is_dir($root)) {
            return 'no-memory-dir';
        }
        $files = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!str_ends_with(str_replace('\\', '/', $path), '/memory.md')) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
                $files[$rel] = hash_file('sha256', $path) ?: '';
            }
        } catch (\Throwable) {
            return 'memory-hash-unavailable';
        }
        ksort($files);
        $json = json_encode($files, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return hash('sha256', is_string($json) ? $json : '');
    }

    private function beliefRowsHash(string $contextId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, belief_text, status, confidence, contestation_state
                 FROM strategic_context_beliefs
                 WHERE strategic_context_id = ?
                 ORDER BY id'
            );
            $stmt->execute([$contextId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return hash('sha256', is_string($json) ? $json : '');
        } catch (\Throwable) {
            return 'belief-hash-unavailable';
        }
    }

    /**
     * Réduit le snapshot persisté (pas de corps beliefs complet — audit par IDs + comptages).
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function redactSnapshotForStorage(array $snapshot): array
    {
        $out = $snapshot;
        $beliefs = $out['beliefs'] ?? [];
        unset($out['beliefs']);
        $ids = [];
        if (is_array($beliefs)) {
            foreach ($beliefs as $b) {
                if (is_array($b) && isset($b['id'])) {
                    $ids[] = (string)$b['id'];
                }
            }
        }
        sort($ids);
        $out['belief_row_ids'] = $ids;
        $ams = $out['agent_memory_samples'] ?? [];
        if (is_array($ams)) {
            foreach ($ams as $aid => $info) {
                if (!is_array($info)) {
                    continue;
                }
                unset($out['agent_memory_samples'][$aid]['excerpt']);
            }
        }

        return $out;
    }

    /**
     * @param array{compilation_type?:string,status?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listCompilations(string $contextId, array $filters = []): array
    {
        $cid = trim($contextId);
        if ($this->contexts->find($cid) === null) {
            return [];
        }
        $rows = $this->compilations->listForContext($cid, $filters);

        return array_map(fn (array $r) => $this->rowToApi($r, false), $rows);
    }

    /** @return ?array<string,mixed> */
    public function getCompilation(string $contextId, string $compilationId): ?array
    {
        $cid = trim($contextId);
        $pid = trim($compilationId);
        if ($this->contexts->find($cid) === null) {
            return null;
        }
        $row = $this->compilations->findByIdInContext($pid, $cid);
        if ($row === null) {
            return null;
        }

        return $this->rowToApi($row, true);
    }

    /** @return array{ok:true}|array{ok:false,message:string,code:int} */
    public function archiveCompilation(string $contextId, string $compilationId): array
    {
        $cid = trim($contextId);
        $pid = trim($compilationId);
        if ($this->contexts->find($cid) === null) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        if ($this->compilations->findByIdInContext($pid, $cid) === null) {
            return ['ok' => false, 'message' => 'Compilation not found', 'code' => 404];
        }
        if (!$this->compilations->updateStatus($pid, $cid, 'archived')) {
            return ['ok' => false, 'message' => 'update_failed', 'code' => 500];
        }

        return ['ok' => true];
    }

    /** @return array{ok:true}|array{ok:false,message:string,code:int} */
    public function supersedeCompilation(string $contextId, string $compilationId): array
    {
        $cid = trim($contextId);
        $pid = trim($compilationId);
        if ($this->contexts->find($cid) === null) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        if ($this->compilations->findByIdInContext($pid, $cid) === null) {
            return ['ok' => false, 'message' => 'Compilation not found', 'code' => 404];
        }
        if (!$this->compilations->updateStatus($pid, $cid, 'superseded')) {
            return ['ok' => false, 'message' => 'update_failed', 'code' => 500];
        }

        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    private function gatherSnapshot(string $contextId): array
    {
        $beliefRows = $this->beliefs->listBeliefsForContext($contextId, ['limit' => 500]);
        usort($beliefRows, static fn (array $a, array $b): int => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

        $nar = $this->narrative->getApiResponse($contextId);
        $tl = $this->timeline->build($contextId, false);
        $sessionIds = $this->fetchContextSessionIds($contextId);
        $linkedMemories = $this->decisionMemories->findLinkedMemoriesForStrategicContext($contextId, 40);

        $postmortems = $this->countForSessions('session_postmortems', $sessionIds);
        $reruns = $this->countRerunsInSessions($sessionIds);
        $evidence = $this->countForSessions('evidence_reports', $sessionIds);
        $risks = $this->countForSessions('session_risk_profiles', $sessionIds);

        $items = is_array($tl['items'] ?? null) ? $tl['items'] : [];
        $relEvents = 0;
        foreach ($items as $it) {
            if (is_array($it) && ($it['type'] ?? '') === 'relationship_event') {
                $relEvents++;
            }
        }

        $agentSamples = [];
        foreach (['pm', 'synthesizer', 'risk_analyst'] as $aid) {
            $r = $this->agentMemory->readIfExistsNoSideEffects($contextId, $aid);
            if ($r['exists'] === true) {
                $agentSamples[$aid] = [
                    'chars' => strlen($r['content']),
                    'excerpt' => mb_substr($r['content'], 0, 600, 'UTF-8'),
                ];
            }
        }

        $ctx = $this->contexts->find($contextId);
        $tw = $tl['warnings'] ?? [];
        $twList = is_array($tw) ? array_values(array_filter(array_map('strval', $tw))) : [];

        return [
            'strategic_context_id' => $contextId,
            'context_title' => (string)($ctx['title'] ?? ''),
            'beliefs' => $beliefRows,
            'beliefs_count' => count($beliefRows),
            'narrative' => $nar,
            'timeline_items_count' => count($items),
            'timeline_warnings' => $twList,
            'session_ids' => $sessionIds,
            'sessions_count' => count($sessionIds),
            'linked_decision_memories_count' => count($linkedMemories),
            'relationship_events_in_timeline' => $relEvents,
            'postmortems_count' => $postmortems,
            'reruns_count' => $reruns,
            'evidence_reports_count' => $evidence,
            'risk_profiles_count' => $risks,
            'agent_memory_samples' => $agentSamples,
            'timeline_item_types' => $this->countTimelineTypes($items),
            'postmortem_outcomes' => $this->fetchPostmortemOutcomes($sessionIds),
        ];
    }

    /** @param list<string> $sessionIds @return list<array{session_id:string,outcome:string}> */
    private function fetchPostmortemOutcomes(array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds)));
        if ($sessionIds === []) {
            return [];
        }
        $sessionIds = array_slice($sessionIds, 0, 120);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT session_id, outcome FROM session_postmortems WHERE session_id IN ($ph) ORDER BY datetime(created_at) DESC LIMIT 24"
            );
            $stmt->execute($sessionIds);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $sid = trim((string)($r['session_id'] ?? ''));
                $oc = trim((string)($r['outcome'] ?? ''));
                if ($sid !== '') {
                    $out[] = ['session_id' => $sid, 'outcome' => $oc];
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<string> $sessionIds */
    private function countForSessions(string $table, array $sessionIds): int
    {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds)));
        if ($sessionIds === []) {
            return 0;
        }
        $sessionIds = array_slice($sessionIds, 0, 200);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id IN ($ph)");
            $stmt->execute($sessionIds);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<string> $sessionIds */
    private function countRerunsInSessions(array $sessionIds): int
    {
        $sessionIds = array_values(array_filter(array_map('strval', $sessionIds)));
        if ($sessionIds === []) {
            return 0;
        }
        $sessionIds = array_slice($sessionIds, 0, 200);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sessions WHERE id IN ($ph) AND parent_session_id IS NOT NULL AND TRIM(parent_session_id) <> ''"
            );
            $stmt->execute($sessionIds);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    private function fetchContextSessionIds(string $contextId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT s.id
            FROM sessions s
            LEFT JOIN strategic_context_sessions scs
              ON scs.session_id = s.id AND scs.context_id = :c_join
            WHERE s.strategic_context_id = :c_col OR scs.session_id IS NOT NULL
            LIMIT 220
        ');
        $stmt->execute([':c_join' => $contextId, ':c_col' => $contextId]);
        $col = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];
        $out = [];
        foreach ($col as $v) {
            $s = trim((string)$v);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $items */
    private function countTimelineTypes(array $items): array
    {
        $c = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $t = (string)($it['type'] ?? 'unknown');
            $c[$t] = ($c[$t] ?? 0) + 1;
        }
        ksort($c);

        return $c;
    }

    /**
     * @param list<array<string,mixed>> $beliefs
     * @return array{stable:list<array<string,mixed>>,disputed:list<array<string,mixed>>,emerging:list<array<string,mixed>>,obsolete:list<array<string,mixed>>,unresolved:list<array<string,mixed>>}
     */
    private function classifyBeliefs(array $beliefs): array
    {
        $out = [
            'stable' => [],
            'disputed' => [],
            'emerging' => [],
            'obsolete' => [],
            'unresolved' => [],
        ];
        foreach ($beliefs as $b) {
            if (!is_array($b)) {
                continue;
            }
            $st = strtolower((string)($b['status'] ?? ''));
            $dis = $b['disagreeing_agents'] ?? [];
            $hasDis = is_array($dis) && $dis !== [];
            if ($st === 'deprecated' || $st === 'archived') {
                $out['obsolete'][] = $b;
            } elseif ($st === 'disputed' || $hasDis) {
                $out['disputed'][] = $b;
            } elseif ($st === 'proposed') {
                $out['emerging'][] = $b;
            } elseif ($st === 'active') {
                $out['stable'][] = $b;
            } else {
                $out['unresolved'][] = $b;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,list<array<string,mixed>>> $classified
     * @return array{recurring_risk_summaries:list<string>,recurring_event_types:list<string>,frequent_disagreeing_agents:list<string>}
     */
    private function extractPatterns(string $contextId, array $snapshot, array $classified): array
    {
        unset($snapshot);
        $tl = $this->timeline->build($contextId, false);
        $items = is_array($tl['items'] ?? null) ? $tl['items'] : [];
        $riskNorm = [];
        foreach ($items as $it) {
            if (!is_array($it) || ($it['type'] ?? '') !== 'risk') {
                continue;
            }
            $sum = trim((string)($it['summary'] ?? ''));
            if ($sum === '') {
                continue;
            }
            $k = mb_strtolower(preg_replace('/\s+/u', ' ', $sum) ?? $sum, 'UTF-8');
            $riskNorm[$k] = ($riskNorm[$k] ?? 0) + 1;
        }
        $recRisk = [];
        foreach ($riskNorm as $k => $n) {
            if ($n >= 3) {
                $recRisk[] = (string)$k;
            }
        }
        sort($recRisk);

        $evCount = [];
        foreach ($items as $it) {
            if (!is_array($it) || ($it['type'] ?? '') !== 'relationship_event') {
                continue;
            }
            $meta = $it['metadata'] ?? [];
            $et = is_array($meta) ? trim((string)($meta['event_type'] ?? '')) : '';
            if ($et === '') {
                continue;
            }
            $evCount[$et] = ($evCount[$et] ?? 0) + 1;
        }
        $recEv = [];
        foreach ($evCount as $et => $n) {
            if ($n >= 2) {
                $recEv[] = $et . ' (×' . $n . ')';
            }
        }
        sort($recEv);

        $opp = [];
        foreach ($classified['disputed'] as $b) {
            $da = $b['disagreeing_agents'] ?? [];
            if (!is_array($da)) {
                continue;
            }
            foreach ($da as $ag) {
                $a = strtolower(trim((string)$ag));
                if ($a !== '') {
                    $opp[$a] = ($opp[$a] ?? 0) + 1;
                }
            }
        }
        $freqOpp = [];
        foreach ($opp as $a => $n) {
            if ($n >= 2) {
                $freqOpp[] = $a . ' (×' . $n . ')';
            }
        }
        sort($freqOpp);

        return [
            'recurring_risk_summaries' => $recRisk,
            'recurring_event_types' => $recEv,
            'frequent_disagreeing_agents' => $freqOpp,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,list<array<string,mixed>>> $classified
     * @param array{recurring_risk_summaries:list<string>,recurring_event_types:list<string>,frequent_disagreeing_agents:list<string>} $patterns
     * @return array{title:string,summary:string,markdown:string,metadata:array<string,mixed>}
     */
    private function buildMarkdownAndMeta(
        string $contextId,
        string $type,
        array $snapshot,
        array $classified,
        array $patterns,
    ): array {
        $longitudinalPriorCount = 0;
        $warnings = [
            'compiler_mvp_deterministic',
            'compiled_memory_is_non_canonical',
            'no_automatic_belief_or_narrative_mutation',
        ];
        $nar = $snapshot['narrative'] ?? [];
        $nw = $nar['warnings'] ?? [];
        if (is_array($nw)) {
            foreach ($nw as $w) {
                $s = trim((string)$w);
                if ($s !== '') {
                    $warnings[] = 'narrative_signal:' . $s;
                }
            }
        }
        foreach ($snapshot['timeline_warnings'] ?? [] as $w) {
            $s = trim((string)$w);
            if ($s !== '') {
                $warnings[] = 'timeline_signal:' . $s;
            }
        }

        $classifyCounts = [
            'stable' => count($classified['stable']),
            'disputed' => count($classified['disputed']),
            'emerging' => count($classified['emerging']),
            'obsolete' => count($classified['obsolete']),
            'unresolved' => count($classified['unresolved']),
        ];

        $unresolvedLines = [];
        foreach ($classified['disputed'] as $b) {
            $t = trim((string)($b['belief_text'] ?? ''));
            if ($t !== '') {
                $unresolvedLines[] = mb_substr($t, 0, 220, 'UTF-8');
            }
        }
        $unresolvedLines = array_slice(array_values(array_unique($unresolvedLines)), 0, 12);

        $keyShifts = [];
        foreach ($patterns['recurring_risk_summaries'] as $r) {
            $keyShifts[] = 'Risque récurrent (≥3 signaux timeline) : ' . mb_substr($r, 0, 160, 'UTF-8');
        }
        foreach ($patterns['recurring_event_types'] as $e) {
            $keyShifts[] = 'Dynamique sociale récurrente : ' . $e;
        }
        foreach ($patterns['frequent_disagreeing_agents'] as $o) {
            $keyShifts[] = 'Opposition récurrente (agents) : ' . $o;
        }
        $keyShifts = array_slice($keyShifts, 0, 14);

        $title = sprintf(
            'Compilation %s — %s — %s',
            $type,
            mb_substr((string)($snapshot['context_title'] ?? ''), 0, 48, 'UTF-8'),
            gmdate('Y-m-d H:i') . ' UTC'
        );

        $lines = [];
        $lines[] = '# Memory compilation';
        $lines[] = sprintf('- **Type** : `%s`', $type);
        $lines[] = sprintf('- **Strategic context** : `%s`', $contextId);
        $lines[] = '- **Nature** : mémoire consolidée **interprétative**, réversible, non canonique (≠ narrative officielle, ≠ beliefs).';
        $lines[] = '';
        $lines[] = '## Provenance (comptes)';
        $lines[] = sprintf('- Beliefs : **%d**', (int)($snapshot['beliefs_count'] ?? 0));
        $lines[] = sprintf('- Sessions liées (scope) : **%d**', (int)($snapshot['sessions_count'] ?? 0));
        $lines[] = sprintf('- Timeline (items) : **%d**', (int)($snapshot['timeline_items_count'] ?? 0));
        $lines[] = sprintf('- Événements relationnels (timeline) : **%d**', (int)($snapshot['relationship_events_in_timeline'] ?? 0));
        $lines[] = sprintf('- Decision memories liées : **%d**', (int)($snapshot['linked_decision_memories_count'] ?? 0));
        $lines[] = sprintf('- Evidence reports (sessions) : **%d**', (int)($snapshot['evidence_reports_count'] ?? 0));
        $lines[] = sprintf('- Risk profiles (sessions) : **%d**', (int)($snapshot['risk_profiles_count'] ?? 0));
        $lines[] = sprintf('- Postmortems (sessions) : **%d**', (int)($snapshot['postmortems_count'] ?? 0));
        $lines[] = sprintf('- Reruns (sessions avec parent) : **%d**', (int)($snapshot['reruns_count'] ?? 0));
        $ams = $snapshot['agent_memory_samples'] ?? [];
        if (is_array($ams) && $ams !== []) {
            $lines[] = '- Agent memory.md (échantillon lecture seule) : ' . implode(', ', array_keys($ams));
        }
        $lines[] = '';
        $lines[] = '## Classification MVP (beliefs)';
        foreach ($classifyCounts as $k => $n) {
            $lines[] = sprintf('- **%s** : %d', $k, $n);
        }
        $lines[] = '';

        if ($type === 'belief' || $type === 'strategic' || $type === 'working') {
            $lines[] = '## Beliefs — extraits dominants / tensions';
            $this->appendBeliefSection($lines, 'Dominants (active)', $classified['stable'], 8);
            $this->appendBeliefSection($lines, 'Disputés / tensions', $classified['disputed'], 10);
            $this->appendBeliefSection($lines, 'Émergents (proposed)', $classified['emerging'], 6);
            $this->appendBeliefSection($lines, 'Obsolètes (deprecated/archived)', $classified['obsolete'], 6);
            $lines[] = '';
        }

        if ($type === 'social' || $type === 'strategic' || $type === 'longitudinal') {
            $lines[] = '## Social dynamics (agrégat système)';
            $lines[] = '_Interactions agents uniquement — pas de profilage psychologique._';
            if ($patterns['recurring_event_types'] !== []) {
                foreach ($patterns['recurring_event_types'] as $x) {
                    $lines[] = '- ' . $x;
                }
            } else {
                $lines[] = '- Aucun motif social récurrent détecté (seuil MVP ≥2 événements du même type).';
            }
            if ($patterns['frequent_disagreeing_agents'] !== []) {
                $lines[] = '';
                $lines[] = '### Agents souvent en opposition (sur beliefs disputés)';
                foreach ($patterns['frequent_disagreeing_agents'] as $x) {
                    $lines[] = '- ' . $x;
                }
            }
            $lines[] = '';
        }

        if ($type === 'risk' || $type === 'strategic' || $type === 'longitudinal') {
            $lines[] = '## Risques';
            if ($patterns['recurring_risk_summaries'] !== []) {
                $lines[] = '### Motifs récurrents (timeline, ≥3)';
                foreach ($patterns['recurring_risk_summaries'] as $r) {
                    $lines[] = '- ' . $r;
                }
            } else {
                $lines[] = '- Aucune récurrence forte de libellé risque (seuil MVP).';
            }
            $lines[] = '';
        }

        if ($type === 'strategic' || $type === 'longitudinal') {
            $lines[] = '## Echo narrative (lecture seule, non autoritaire)';
            $n = $nar['narrative'] ?? [];
            if (is_array($n)) {
                $keys = ['computed_at', 'headline', 'summary', 'dominant_themes', 'open_questions'];
                foreach ($keys as $k) {
                    if (!array_key_exists($k, $n)) {
                        continue;
                    }
                    $v = $n[$k];
                    if (is_array($v)) {
                        $lines[] = sprintf('- **%s** : %s', $k, json_encode($v, JSON_UNESCAPED_UNICODE));
                    } else {
                        $lines[] = sprintf('- **%s** : %s', $k, trim((string)$v));
                    }
                }
            }
            $lines[] = '';
        }

        if ($type === 'postmortem' || $type === 'strategic') {
            $lines[] = '## Postmortems (extraits outcome)';
            $pm = $snapshot['postmortem_outcomes'] ?? [];
            if (is_array($pm) && $pm !== []) {
                foreach ($pm as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lines[] = sprintf('- session `%s` → **%s**', $row['session_id'] ?? '', $row['outcome'] ?? '');
                }
            } else {
                $lines[] = '- Aucun postmortem lié aux sessions du contexte.';
            }
            $lines[] = '';
        }

        if ($type === 'longitudinal') {
            $lines[] = '## Longitudinal / dérive (heuristique)';
            $prevRows = $this->compilations->listForContext($contextId, ['limit' => 18]);
            $longitudinalPriorCount = count($prevRows);
            $beliefCounts = [];
            foreach ($prevRows as $pr) {
                $sj = (string)($pr['source_snapshot_json'] ?? '');
                $dec = json_decode($sj, true);
                if (is_array($dec) && isset($dec['beliefs_count'])) {
                    $beliefCounts[] = (int)$dec['beliefs_count'];
                }
            }
            if (count($beliefCounts) >= 2) {
                $last = $beliefCounts[0];
                $avg = array_sum(array_slice($beliefCounts, 1, 5)) / max(1, min(5, count($beliefCounts) - 1));
                $lines[] = sprintf(
                    '- Comparaison MVP : dernier snapshot beliefs_count=%d vs moyenne des %d précédents ≈ %.1f.',
                    $last,
                    min(5, count($beliefCounts) - 1),
                    $avg
                );
            } else {
                $lines[] = '- Historique de compilations insuffisant pour une courbe (besoin de ≥2 snapshots antérieurs).';
            }
            $lines[] = '- Voir aussi métadonnées `longitudinal_notes` ci-dessous.';
            $lines[] = '';
        }

        if ($type === 'working') {
            $lines[] = '## Working memory (condensé)';
            $lines[] = '- Focus croyances actives / tensions ouvertes + signaux volume (pas de réécriture des stores).';
            $lines[] = '';
        }

        $lines[] = '## Avertissements anti-dérive';
        foreach (array_unique($warnings) as $w) {
            $lines[] = '- ' . $w;
        }
        $lines[] = '';
        $lines[] = '## Synthèse MVP (non factuelle)';
        $synth = $this->buildSyntheticLine($type, $snapshot, $classifyCounts, $patterns);
        $lines[] = $synth;

        $markdown = implode("\n", $lines);
        $summary = $this->summaryFromMarkdown($synth . "\n" . $markdown);

        $metadata = [
            'warnings' => array_values(array_unique($warnings)),
            'key_shifts' => $keyShifts,
            'unresolved_tensions' => $unresolvedLines,
            'classify_counts' => $classifyCounts,
            'longitudinal_notes' => $type === 'longitudinal' ? [
                'prior_compilation_rows_considered' => $longitudinalPriorCount,
            ] : [],
        ];

        return ['title' => $title, 'summary' => $summary, 'markdown' => $markdown, 'metadata' => $metadata];
    }

    /** @param list<array<string,mixed>> $slice */
    private function appendBeliefSection(array &$lines, string $heading, array $slice, int $max): void
    {
        $lines[] = '### ' . $heading;
        $n = 0;
        foreach ($slice as $b) {
            if ($n >= $max) {
                break;
            }
            if (!is_array($b)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s / %s] %s',
                (string)($b['status'] ?? ''),
                (string)($b['belief_type'] ?? ''),
                mb_substr(trim((string)($b['belief_text'] ?? '')), 0, 200, 'UTF-8')
            );
            $n++;
        }
        if ($n === 0) {
            $lines[] = '- _(vide)_';
        }
    }

    /** @param array<string,int> $cc */
    private function buildSyntheticLine(string $type, array $snapshot, array $cc, array $patterns): string
    {
        $parts = [];
        $parts[] = 'Vue compilée :';
        $parts[] = sprintf('%d beliefs', (int)($snapshot['beliefs_count'] ?? 0));
        $parts[] = sprintf('%d sessions', (int)($snapshot['sessions_count'] ?? 0));
        if (($cc['disputed'] ?? 0) > 0) {
            $parts[] = sprintf('tensions ouvertes (%d)', (int)$cc['disputed']);
        }
        if ($patterns['recurring_risk_summaries'] !== []) {
            $parts[] = 'risques récurrents détectés';
        }
        if ($patterns['recurring_event_types'] !== []) {
            $parts[] = 'motifs sociaux récurrents';
        }
        $parts[] = 'type=' . $type;

        return implode(' · ', $parts) . '.';
    }

    private function summaryFromMarkdown(string $md): string
    {
        $flat = preg_replace('/^#+\s.*/m', '', $md) ?? $md;
        $flat = preg_replace('/\s+/', ' ', trim($flat)) ?? trim($flat);
        if (mb_strlen($flat, 'UTF-8') > 380) {
            return mb_substr($flat, 0, 377, 'UTF-8') . '…';
        }

        return $flat;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,list<array<string,mixed>>> $classified
     * @param array<string,mixed> $metadata
     * @return array{confidence:float,stability_score:float}
     */
    private function computeScores(array $snapshot, array $classified, array $metadata): array
    {
        $total = max(1, (int)($snapshot['beliefs_count'] ?? 0));
        $disputed = count($classified['disputed']);
        $emerging = count($classified['emerging']);
        $active = count($classified['stable']);

        $confidence = 0.52 + min(0.22, $total * 0.012);
        $confidence -= min(0.18, $disputed * 0.035);
        if (!empty($metadata['warnings'])) {
            $confidence -= 0.03;
        }
        $confidence = max(0.35, min(0.82, $confidence));

        $stab = 0.5 + ($active / $total) * 0.22;
        $stab -= min(0.2, (($disputed + $emerging) / $total) * 0.28);
        if (($snapshot['sessions_count'] ?? 0) < 2) {
            $stab -= 0.06;
        }
        $stab = max(0.35, min(0.88, $stab));

        return ['confidence' => round($confidence, 3), 'stability_score' => round($stab, 3)];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowToApi(array $row, bool $includeMarkdown): array
    {
        $snap = [];
        $sj = (string)($row['source_snapshot_json'] ?? '');
        if ($sj !== '') {
            $d = json_decode($sj, true);
            if (is_array($d)) {
                $snap = $d;
            }
        }
        $meta = [];
        $mj = (string)($row['compilation_metadata_json'] ?? '');
        if ($mj !== '') {
            $d = json_decode($mj, true);
            if (is_array($d)) {
                $meta = $d;
            }
        }
        $sources = [
            'beliefs' => (int)($snap['beliefs_count'] ?? 0),
            'sessions' => (int)($snap['sessions_count'] ?? 0),
            'risks' => (int)($snap['risk_profiles_count'] ?? 0),
            'evidence_reports' => (int)($snap['evidence_reports_count'] ?? 0),
            'postmortems' => (int)($snap['postmortems_count'] ?? 0),
            'reruns' => (int)($snap['reruns_count'] ?? 0),
            'timeline_items' => (int)($snap['timeline_items_count'] ?? 0),
            'relationship_events' => (int)($snap['relationship_events_in_timeline'] ?? 0),
            'linked_decision_memories' => (int)($snap['linked_decision_memories_count'] ?? 0),
            'narrative_loaded' => isset($snap['narrative']) ? 1 : 0,
        ];

        $out = [
            'id' => (string)($row['id'] ?? ''),
            'strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            'compilation_type' => (string)($row['compilation_type'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
            'confidence' => isset($row['confidence']) ? (float)$row['confidence'] : 0.5,
            'stability_score' => isset($row['stability_score']) ? (float)$row['stability_score'] : 0.5,
            'status' => (string)($row['status'] ?? 'active'),
            'sources' => $sources,
            'source_hash' => $row['source_hash'] !== null && (string)$row['source_hash'] !== '' ? (string)$row['source_hash'] : null,
            'compilation_hash' => isset($meta['provenance_integrity']['compilation_hash'])
                ? (string)$meta['provenance_integrity']['compilation_hash']
                : (isset($meta['cognitive_provenance']['compilation_hash']) ? (string)$meta['cognitive_provenance']['compilation_hash'] : null),
            'created_by' => $row['created_by'] !== null && (string)$row['created_by'] !== '' ? (string)$row['created_by'] : null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'compilation_metadata' => $meta,
        ];
        if ($includeMarkdown) {
            $out['compiled_memory_markdown'] = (string)($row['compiled_memory_markdown'] ?? '');
            $out['source_snapshot'] = $snap;
        } else {
            $out['compiled_memory_markdown'] = null;
        }

        return $out;
    }

    private function uuid(): string
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
}

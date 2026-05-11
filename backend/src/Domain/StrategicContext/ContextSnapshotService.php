<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\CognitiveGovernance\CanonicalLayerMutationGuard;
use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Domain\CognitiveGovernance\DeterministicHash;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Infrastructure\Persistence\StrategicContextSnapshotRepository;

/**
 * Context Snapshots : capture immuable et déterministe de l’état stratégique à un instant T.
 * Aucune mutation des stores live (beliefs, narrative, compilations, sessions, memory.md).
 */
final class ContextSnapshotService
{
    public const SNAPSHOT_TYPES = [
        'manual',
        'scheduled',
        'milestone',
        'pre-rerun',
        'postmortem',
        'before-major-decision',
        'longitudinal-anchor',
    ];

    public function __construct(
        private ?StrategicContextRepository $contexts = null,
        private ?BeliefEngineService $beliefs = null,
        private ?StrategicNarrativeService $narrative = null,
        private ?WorkspaceTimelineService $timeline = null,
        private ?MemoryCompilerService $memoryCompiler = null,
        private ?DecisionMemoryRepository $decisionMemories = null,
        private ?AgentRelationshipRepository $relationships = null,
        private ?StrategicContextSnapshotRepository $snapshots = null,
        private ?ContextSnapshotDiffService $diff = null,
        private ?\PDO $pdo = null,
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->beliefs = $beliefs ?? new BeliefEngineService();
        $this->narrative = $narrative ?? new StrategicNarrativeService();
        $this->timeline = $timeline ?? new WorkspaceTimelineService();
        $this->memoryCompiler = $memoryCompiler ?? new MemoryCompilerService();
        $this->decisionMemories = $decisionMemories ?? new DecisionMemoryRepository();
        $this->relationships = $relationships ?? new AgentRelationshipRepository();
        $this->snapshots = $snapshots ?? new StrategicContextSnapshotRepository();
        $this->diff = $diff ?? new ContextSnapshotDiffService();
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * @param array{title?:string,description?:string,created_by?:string} $options
     * @return array{ok:true,snapshot:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function createSnapshot(string $contextId, string $snapshotType, array $options = []): array
    {
        if (CanonicalLayerMutationGuard::enabled()) {
            $restoreRequested = array_key_exists('restore_mode', $options)
                || ((bool)($options['apply_to_runtime'] ?? false) === true)
                || ((bool)($options['overwrite_runtime'] ?? false) === true);
            if ($restoreRequested) {
                CanonicalLayerMutationGuard::assertAllowed(
                    'snapshot_service',
                    'runtime_state',
                    'implicit_restore',
                    [
                        'strategic_context_id' => $contextId,
                        'snapshot_type' => $snapshotType,
                    ]
                );
            }
        }
        $cid = trim($contextId);
        $type = trim($snapshotType);
        if ($this->contexts->find($cid) === null) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        if (!in_array($type, self::SNAPSHOT_TYPES, true)) {
            return ['ok' => false, 'message' => 'invalid snapshot_type', 'code' => 400];
        }
        $title = trim((string)($options['title'] ?? ''));
        if ($title === '') {
            $title = sprintf('Snapshot %s — %s UTC', $type, gmdate('Y-m-d H:i'));
        }
        $description = array_key_exists('description', $options) ? trim((string)$options['description']) : null;
        $description = $description !== '' ? $description : null;
        $createdBy = trim((string)($options['created_by'] ?? ''));
        $createdBy = $createdBy !== '' ? $createdBy : null;

        $payload = $this->buildSnapshotPayload($cid);
        if (!empty($options['tags']) && is_array($options['tags'])) {
            $payload['metadata']['tags'] = array_values(array_filter(array_map('strval', $options['tags'])));
        }
        $markdown = $this->buildSnapshotMarkdown($cid, $payload);
        $snapshotHash = $this->computeSnapshotHash($markdown, $payload);
        $inputHash = DeterministicHash::sha256([
            'context_id' => $cid,
            'source_summary' => $payload['source_summary'] ?? [],
            'metadata_context' => $payload['metadata']['context'] ?? [],
            'current_state_echo' => $payload['metadata']['current_state_echo'] ?? [],
        ]);
        $sourceHash = DeterministicHash::sha256([
            'beliefs' => $payload['beliefs'] ?? [],
            'strategic_narrative' => $payload['strategic_narrative'] ?? [],
            'risks' => $payload['risks'] ?? [],
            'evidence' => $payload['evidence'] ?? [],
            'social' => $payload['social'] ?? [],
            'timeline' => $payload['timeline'] ?? [],
            'memory_compilations' => $payload['memory_compilations'] ?? [],
            'source_summary' => $payload['source_summary'] ?? [],
        ]);
        $payload['metadata']['provenance_integrity'] = [
            'input_hash' => $inputHash,
            'source_hash' => $sourceHash,
            'runtime_hash' => DeterministicHash::sha256([
                'snapshot_hash' => $snapshotHash,
                'source_hash' => $sourceHash,
                'context_id' => $cid,
            ]),
            'snapshot_hash' => $snapshotHash,
        ];
        $payload['metadata']['cognitive_provenance'] = CognitiveProvenanceEnvelope::forContextSnapshot($cid, 'live_capture', [
            'created_at' => date('c'),
            'total_sources' => (int)(($payload['source_summary']['beliefs'] ?? 0)
                + ($payload['source_summary']['sessions'] ?? 0)
                + ($payload['source_summary']['timeline_items'] ?? 0)
                + ($payload['source_summary']['relationship_events'] ?? 0)
                + ($payload['source_summary']['memory_compilations_active'] ?? 0)
                + ($payload['source_summary']['linked_decision_memories'] ?? 0)),
            'input_hashes' => [$inputHash],
            'source_hash' => $sourceHash,
            'runtime_hash' => (string)$payload['metadata']['provenance_integrity']['runtime_hash'],
            'snapshot_hash' => $snapshotHash,
            'selected_labels' => $payload['metadata']['cognitive_provenance']['selected_sources'] ?? ($payload['metadata']['cognitive_provenance']['selected_labels'] ?? []),
            'pruned_labels' => $payload['metadata']['cognitive_provenance']['pruned_sources'] ?? ($payload['metadata']['cognitive_provenance']['pruned_labels'] ?? []),
        ]);
        $now = date('c');
        $id = $this->uuid();

        $row = [
            'id' => $id,
            'strategic_context_id' => $cid,
            'snapshot_type' => $type,
            'title' => $title,
            'description' => $description,
            'snapshot_markdown' => $markdown,
            'strategic_narrative_json' => json_encode($payload['strategic_narrative'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'beliefs_snapshot_json' => json_encode($payload['beliefs'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'risks_snapshot_json' => json_encode($payload['risks'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'evidence_snapshot_json' => json_encode($payload['evidence'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'social_snapshot_json' => json_encode($payload['social'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'timeline_snapshot_json' => json_encode($payload['timeline'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'memory_compilations_json' => json_encode($payload['memory_compilations'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'source_summary_json' => json_encode($payload['source_summary'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'metadata_json' => json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'snapshot_hash' => $snapshotHash,
            'created_by' => $createdBy,
            'created_at' => $now,
        ];
        $this->snapshots->insert($row);
        $full = $this->snapshots->findByIdInContext($id, $cid);
        if ($full === null) {
            return ['ok' => false, 'message' => 'persist_failed', 'code' => 500];
        }

        return ['ok' => true, 'snapshot' => $this->rowToApi($full, true)];
    }

    /**
     * @param array{snapshot_type?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listSnapshots(string $contextId, array $filters = []): array
    {
        if ($this->contexts->find(trim($contextId)) === null) {
            return [];
        }
        $rows = $this->snapshots->listSummaryForContext(trim($contextId), $filters);

        return array_map(fn (array $r) => $this->rowToApi($r, false), $rows);
    }

    /** @return ?array<string,mixed> */
    public function getSnapshot(string $contextId, string $snapshotId): ?array
    {
        $cid = trim($contextId);
        $sid = trim($snapshotId);
        if ($this->contexts->find($cid) === null) {
            return null;
        }
        $row = $this->snapshots->findByIdInContext($sid, $cid);

        return $row === null ? null : $this->rowToApi($row, true);
    }

    /**
     * @return array{ok:true,diff:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function compareSnapshots(string $contextId, string $snapshotIdA, string $snapshotIdB): array
    {
        $cid = trim($contextId);
        if ($this->contexts->find($cid) === null) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        $a = $this->snapshots->findByIdInContext(trim($snapshotIdA), $cid);
        $b = $this->snapshots->findByIdInContext(trim($snapshotIdB), $cid);
        if ($a === null || $b === null) {
            return ['ok' => false, 'message' => 'Snapshot not found', 'code' => 404];
        }
        $cmp = $this->diff->compareSnapshots($a, $b);
        $integrityA = $this->verifySnapshotIntegrityRecord($a);
        $integrityB = $this->verifySnapshotIntegrityRecord($b);
        $cmp['integrity'] = [
            'snapshot_a' => $integrityA,
            'snapshot_b' => $integrityB,
            'restore_verification' => [
                'snapshot_a_restorable' => (bool)($integrityA['valid'] ?? false),
                'snapshot_b_restorable' => (bool)($integrityB['valid'] ?? false),
                'policy' => 'immutable_snapshot_requires_hash_match',
            ],
            'branch_verification' => [
                'same_context' => ((string)($a['strategic_context_id'] ?? '')) === ((string)($b['strategic_context_id'] ?? '')),
                'same_snapshot_hash' => ((string)($a['snapshot_hash'] ?? '')) !== '' && ((string)($a['snapshot_hash'] ?? '')) === ((string)($b['snapshot_hash'] ?? '')),
                'created_at_a' => (string)($a['created_at'] ?? ''),
                'created_at_b' => (string)($b['created_at'] ?? ''),
            ],
        ];

        return ['ok' => true, 'diff' => $cmp];
    }

    /**
     * @param array{limit?:int} $options
     * @return array{snapshots:list<array<string,mixed>>,view_markdown:string,metadata:array<string,mixed>}
     */
    public function buildLongitudinalView(string $contextId, array $options = []): array
    {
        $cid = trim($contextId);
        $limit = max(2, min(40, (int)($options['limit'] ?? 12)));
        if ($this->contexts->find($cid) === null) {
            return ['snapshots' => [], 'view_markdown' => '', 'metadata' => ['error' => 'context_not_found']];
        }
        $rows = $this->snapshots->listSummaryForContext($cid, ['limit' => $limit]);
        $meta = ['snapshot_count' => count($rows), 'ordered_desc' => true];
        $lines = [];
        $lines[] = '# Longitudinal Context Evolution';
        $lines[] = sprintf('- **Strategic context** : `%s`', $cid);
        $lines[] = '- Vue agrégée à partir des **snapshots** persistés (MVP déterministe).';
        $lines[] = '';

        $prev = null;
        foreach (array_reverse($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sum = $this->jsonDecode((string)($row['source_summary_json'] ?? '{}'));
            $lines[] = sprintf(
                '## %s — `%s`',
                (string)($row['created_at'] ?? ''),
                (string)($row['snapshot_type'] ?? '')
            );
            $lines[] = sprintf('- **Title** : %s', (string)($row['title'] ?? ''));
            $lines[] = sprintf(
                '- **Counts** : beliefs=%d, sessions=%d, risks=%d, timeline_items=%d, compilations_active=%d',
                (int)($sum['beliefs'] ?? 0),
                (int)($sum['sessions'] ?? 0),
                (int)($sum['risks'] ?? 0),
                (int)($sum['timeline_items'] ?? 0),
                (int)($sum['memory_compilations_active'] ?? 0)
            );
            if ($prev !== null) {
                $prevSum = $this->jsonDecode((string)($prev['source_summary_json'] ?? '{}'));
                $lines[] = sprintf(
                    '- **Δ vs snapshot précédent** : beliefs %+d, sessions %+d, risks %+d',
                    (int)($sum['beliefs'] ?? 0) - (int)($prevSum['beliefs'] ?? 0),
                    (int)($sum['sessions'] ?? 0) - (int)($prevSum['sessions'] ?? 0),
                    (int)($sum['risks'] ?? 0) - (int)($prevSum['risks'] ?? 0)
                );
            }
            $lines[] = '';
            $prev = $row;
        }

        return [
            'snapshots' => array_map(fn (array $r) => $this->rowToApi($r, false), $rows),
            'view_markdown' => implode("\n", $lines),
            'metadata' => $meta,
        ];
    }

    /** @return array<string,mixed> */
    public function buildSnapshotPayload(string $contextId): array
    {
        $cid = trim($contextId);
        $ctx = $this->contexts->find($cid);
        $current = $this->contexts->currentState($cid);
        $nar = $this->narrative->getApiResponse($cid);
        $tl = $this->timeline->build($cid, false);
        $items = is_array($tl['items'] ?? null) ? $tl['items'] : [];
        $sessionIds = $this->fetchContextSessionIds($cid);

        $beliefRows = $this->beliefs->listBeliefsForContext($cid, ['limit' => 120]);
        usort($beliefRows, static fn (array $x, array $y): int => strcmp((string)($x['id'] ?? ''), (string)($y['id'] ?? '')));
        $classified = $this->classifyBeliefs($beliefRows);
        $beliefsSnap = [
            'counts' => [
                'total' => count($beliefRows),
                'stable' => count($classified['stable']),
                'disputed' => count($classified['disputed']),
                'emerging' => count($classified['emerging']),
                'deprecated' => count($classified['obsolete']),
            ],
            'samples' => [
                'dominant' => $this->sliceBeliefs($classified['stable'], 6),
                'disputed' => $this->sliceBeliefs($classified['disputed'], 8),
                'emerging' => $this->sliceBeliefs($classified['emerging'], 5),
                'deprecated' => $this->sliceBeliefs($classified['obsolete'], 5),
            ],
        ];

        $nObj = $nar['narrative'] ?? [];
        if (!is_array($nObj)) {
            $nObj = [];
        }
        $ka = $nObj['key_assumptions'] ?? [];
        $kaEcho = [];
        if (is_array($ka)) {
            foreach (array_slice($ka, 0, 8) as $x) {
                $kaEcho[] = mb_substr(trim((string)$x), 0, 200, 'UTF-8');
            }
        }
        $narrativeSnap = [
            'warnings' => is_array($nar['warnings'] ?? null) ? array_values(array_map('strval', $nar['warnings'])) : [],
            'computed_at' => (string)($nObj['computed_at'] ?? ''),
            'headline_echo' => mb_substr(trim((string)($nObj['headline'] ?? $nObj['current_direction'] ?? '')), 0, 280, 'UTF-8'),
            'summary_echo' => mb_substr(trim((string)($nObj['summary'] ?? '')), 0, 400, 'UTF-8'),
            'key_assumptions_echo' => $kaEcho,
        ];

        $riskThemes = [];
        foreach ($items as $it) {
            if (!is_array($it) || ($it['type'] ?? '') !== 'risk') {
                continue;
            }
            $s = trim((string)($it['summary'] ?? ''));
            if ($s === '') {
                continue;
            }
            $riskThemes[] = mb_strtolower(preg_replace('/\s+/u', ' ', $s) ?? $s, 'UTF-8');
        }
        $riskThemes = array_values(array_unique($riskThemes));
        sort($riskThemes);
        $riskThemes = array_slice($riskThemes, 0, 24);
        $risksSnap = [
            'risk_profiles_count' => $this->countForSessions('session_risk_profiles', $sessionIds),
            'timeline_risk_themes' => $riskThemes,
        ];

        $evidenceSnap = [
            'evidence_reports_count' => $this->countForSessions('evidence_reports', $sessionIds),
            'recent_session_ids' => array_slice($sessionIds, 0, 12),
        ];

        $relRows = $this->relationships->findByStrategicContext($cid);
        $relRows = array_slice(is_array($relRows) ? $relRows : [], 0, 55);
        $events = $this->relationships->findEventsByStrategicContext($cid);
        $events = array_slice(is_array($events) ? $events : [], 0, 200);
        $evCount = [];
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $et = trim((string)($e['event_type'] ?? ''));
            if ($et === '') {
                continue;
            }
            $evCount[$et] = ($evCount[$et] ?? 0) + 1;
        }
        ksort($evCount);
        $socialSnap = [
            'relationship_rows' => count($relRows),
            'relationship_events_scanned' => count($events),
            'event_type_counts' => $evCount,
            'highlights' => SocialPromptContextBuilder::computeHighlights($relRows),
        ];

        $countsByType = [];
        $recentItems = [];
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $typ = (string)($it['type'] ?? 'unknown');
            $countsByType[$typ] = ($countsByType[$typ] ?? 0) + 1;
            if ($n < 36) {
                $recentItems[] = [
                    'type' => $typ,
                    'title' => mb_substr(trim((string)($it['title'] ?? '')), 0, 120, 'UTF-8'),
                    'created_at' => (string)($it['created_at'] ?? ''),
                ];
                $n++;
            }
        }
        ksort($countsByType);
        $timelineSnap = [
            'counts_by_type' => $countsByType,
            'recent_items' => $recentItems,
        ];

        $comps = $this->memoryCompiler->listCompilations($cid, ['status' => 'active', 'limit' => 18]);
        $activeN = 0;
        foreach ($comps as $c) {
            if (is_array($c) && (($c['status'] ?? '') === 'active')) {
                $activeN++;
            }
        }
        $memItems = [];
        foreach ($comps as $c) {
            if (!is_array($c)) {
                continue;
            }
            $memItems[] = [
                'id' => (string)($c['id'] ?? ''),
                'compilation_type' => (string)($c['compilation_type'] ?? ''),
                'stability_score' => isset($c['stability_score']) ? (float)$c['stability_score'] : 0.0,
                'confidence' => isset($c['confidence']) ? (float)$c['confidence'] : 0.0,
                'created_at' => (string)($c['created_at'] ?? ''),
                'summary' => mb_substr(trim((string)($c['summary'] ?? '')), 0, 160, 'UTF-8'),
            ];
        }
        $memorySnap = [
            'active_count' => $activeN,
            'items' => $memItems,
        ];

        $memories = $this->decisionMemories->findLinkedMemoriesForStrategicContext($cid, 10);
        $decisionBrief = [];
        foreach ($memories as $m) {
            if (!is_array($m)) {
                continue;
            }
            $decisionBrief[] = [
                'memory_id' => (string)($m['memory_id'] ?? ''),
                'decision_status' => (string)($m['decision_status'] ?? ''),
                'confidence' => (string)($m['confidence'] ?? ''),
                'summary' => mb_substr(trim((string)($m['decision_summary'] ?? '')), 0, 160, 'UTF-8'),
            ];
        }

        $sourceSummary = [
            'beliefs' => count($beliefRows),
            'sessions' => count($sessionIds),
            'risks' => (int)$risksSnap['risk_profiles_count'],
            'evidence_reports' => (int)$evidenceSnap['evidence_reports_count'],
            'postmortems' => $this->countForSessions('session_postmortems', $sessionIds),
            'reruns' => $this->countRerunsInSessions($sessionIds),
            'timeline_items' => count($items),
            'relationship_events' => count($events),
            'linked_decision_memories' => count($memories),
            'memory_compilations_active' => $activeN,
        ];

        $totalSources = (int)($sourceSummary['beliefs'] ?? 0)
            + (int)($sourceSummary['sessions'] ?? 0)
            + (int)($sourceSummary['timeline_items'] ?? 0)
            + (int)($sourceSummary['relationship_events'] ?? 0)
            + (int)($sourceSummary['memory_compilations_active'] ?? 0)
            + (int)($sourceSummary['linked_decision_memories'] ?? 0);

        $metadata = [
            'warnings' => [
                'snapshot_mvp_immutable_insert_only',
                'snapshot_no_auto_loop',
                'snapshot_compact_json_limits',
            ],
            'limits' => [
                'beliefs_max' => 120,
                'timeline_recent_items' => 36,
                'relationship_rows_max' => 55,
                'compilation_items_max' => 18,
            ],
            'context' => [
                'title' => (string)($ctx['title'] ?? ''),
                'status' => (string)($ctx['status'] ?? ''),
                'description' => (string)($ctx['description'] ?? ''),
            ],
            'current_state_echo' => [
                'current_decision_status' => (string)($current['current_decision_status'] ?? ''),
                'current_confidence' => (string)($current['current_confidence'] ?? ''),
                'latest_next_step' => (string)($current['latest_next_step'] ?? ''),
            ],
            'decision_memories_recent' => $decisionBrief,
            'snapshot_data_layers' => [
                'live_echo' => [
                    'current_state_echo',
                    'narrative_echo_fields',
                    'belief_samples_truncated',
                ],
                'derived_aggregates' => [
                    'belief_counts_by_stability',
                    'timeline_counts_by_type',
                    'social_event_type_counts',
                    'memory_compilation_summaries',
                ],
                'frozen_on_persist' => [
                    'full_payload_json_row_immutable_after_insert',
                ],
                'restored' => [
                    'not_applicable_at_capture',
                    'restore_operations_are_explicit_api_paths_with_audit',
                ],
            ],
            'cognitive_provenance' => CognitiveProvenanceEnvelope::forContextSnapshot($cid, 'live_capture', [
                'created_at' => date('c'),
                'total_sources' => $totalSources,
                'selected_labels' => [
                    'beliefs:classified_samples',
                    'narrative:echo_only',
                    'timeline:recent_36',
                    'social:relationships+events_capped',
                    'memory_compilations:active_list',
                    'decision_memories:linked_top10',
                ],
                'pruned_labels' => [
                    'belief_full_statement_text_omitted_use_belief_store',
                    'narrative_full_text_omitted_use_narrative_service',
                    'sessions_full_transcripts_omitted',
                ],
                'input_hashes' => [DeterministicHash::sha256([
                    'context_id' => $cid,
                    'beliefs_count' => count($beliefRows),
                    'timeline_items_count' => count($items),
                    'sessions_count' => count($sessionIds),
                    'context_description' => (string)($ctx['description'] ?? ''),
                ])],
            ]),
        ];

        return [
            'strategic_narrative' => $narrativeSnap,
            'beliefs' => $beliefsSnap,
            'risks' => $risksSnap,
            'evidence' => $evidenceSnap,
            'social' => $socialSnap,
            'timeline' => $timelineSnap,
            'memory_compilations' => $memorySnap,
            'source_summary' => $sourceSummary,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function buildSnapshotMarkdown(string $contextId, array $payload): string
    {
        $cid = trim($contextId);
        $ctx = $this->contexts->find($cid);
        $title = (string)($ctx['title'] ?? '');
        $lines = [];
        $lines[] = '# Context Snapshot';
        $lines[] = sprintf('- **Context** : `%s` — %s', $cid, $title);
        $lines[] = '- **Immutable** : photographie à l’instant de création ; pas de réécriture automatique.';
        $lines[] = '';

        $lines[] = '## Strategic Direction';
        $n = $payload['strategic_narrative'] ?? [];
        $lines[] = '- Headline / direction : ' . (string)($n['headline_echo'] ?? '—');
        if (($n['summary_echo'] ?? '') !== '') {
            $lines[] = '- Résumé narrative (echo) : ' . (string)$n['summary_echo'];
        }
        foreach (($n['warnings'] ?? []) as $w) {
            $lines[] = '- (narrative warning) ' . (string)$w;
        }
        $lines[] = '';

        $lines[] = '## Dominant Beliefs';
        $this->appendBeliefLines($lines, $payload['beliefs']['samples']['dominant'] ?? []);
        $lines[] = '';
        $lines[] = '## Disputed Beliefs';
        $this->appendBeliefLines($lines, $payload['beliefs']['samples']['disputed'] ?? []);
        $lines[] = '';
        $lines[] = '## Emerging Beliefs';
        $this->appendBeliefLines($lines, $payload['beliefs']['samples']['emerging'] ?? []);
        $lines[] = '';
        $lines[] = '## Deprecated Beliefs';
        $this->appendBeliefLines($lines, $payload['beliefs']['samples']['deprecated'] ?? []);
        $lines[] = '';

        $lines[] = '## Active Risks';
        $r = $payload['risks'] ?? [];
        $lines[] = sprintf('- Risk profiles (sessions du scope) : **%d**', (int)($r['risk_profiles_count'] ?? 0));
        foreach (array_slice($r['timeline_risk_themes'] ?? [], 0, 14) as $t) {
            $lines[] = '- (timeline) ' . (string)$t;
        }
        $lines[] = '';

        $lines[] = '## Social Dynamics';
        $s = $payload['social'] ?? [];
        $lines[] = sprintf('- Lignes relations (contexte) : **%d**', (int)($s['relationship_rows'] ?? 0));
        $h = $s['highlights'] ?? [];
        if (is_array($h)) {
            $lines[] = '- Highlights (agrégat système) : ' . json_encode($h, JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';

        $lines[] = '## Recent Strategic Events';
        foreach ($payload['metadata']['decision_memories_recent'] ?? [] as $dm) {
            if (!is_array($dm)) {
                continue;
            }
            $lines[] = sprintf(
                '- Memory `%s` · %s · %s',
                (string)($dm['memory_id'] ?? ''),
                (string)($dm['decision_status'] ?? ''),
                (string)($dm['summary'] ?? '')
            );
        }
        $lines[] = '';

        $lines[] = '## Narrative Drift';
        $lines[] = '_Signal : warnings narrative + echo `computed_at` ci-dessus (comparer à un snapshot ultérieur)._';
        $lines[] = '';

        $lines[] = '## Memory Compilations';
        foreach ($payload['memory_compilations']['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` [%s] stab=%.2f conf=%.2f — %s',
                (string)($it['id'] ?? ''),
                (string)($it['compilation_type'] ?? ''),
                (float)($it['stability_score'] ?? 0),
                (float)($it['confidence'] ?? 0),
                (string)($it['summary'] ?? '')
            );
        }
        $lines[] = '';

        $lines[] = '## Unresolved Tensions';
        $this->appendBeliefLines($lines, $payload['beliefs']['samples']['disputed'] ?? []);
        $lines[] = '';

        $lines[] = '## Stability Signals';
        $sum = $payload['source_summary'] ?? [];
        $lines[] = sprintf(
            '- Comptages : beliefs=%d, sessions=%d, reruns=%d, postmortems=%d, compilations actives=%d',
            (int)($sum['beliefs'] ?? 0),
            (int)($sum['sessions'] ?? 0),
            (int)($sum['reruns'] ?? 0),
            (int)($sum['postmortems'] ?? 0),
            (int)($sum['memory_compilations_active'] ?? 0)
        );
        $lines[] = '';

        $lines[] = '## Timeline Summary';
        foreach ($payload['timeline']['recent_items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $lines[] = sprintf('- **%s** · %s — %s', (string)($it['type'] ?? ''), (string)($it['created_at'] ?? ''), (string)($it['title'] ?? ''));
        }
        $lines[] = '';

        $lines[] = '## Snapshot Metadata';
        $lines[] = '- ' . implode("\n- ", $payload['metadata']['warnings'] ?? []);

        return implode("\n", $lines);
    }

    /** @param list<array<string,mixed>> $slice */
    private function appendBeliefLines(array &$lines, array $slice): void
    {
        if ($slice === []) {
            $lines[] = '- _(vide)_';

            return;
        }
        foreach ($slice as $b) {
            if (!is_array($b)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s] `%s` — %s',
                (string)($b['status'] ?? ''),
                (string)($b['id'] ?? ''),
                (string)($b['text'] ?? '')
            );
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function sliceBeliefs(array $rows, int $max): array
    {
        $out = [];
        $n = 0;
        foreach ($rows as $b) {
            if ($n >= $max) {
                break;
            }
            if (!is_array($b)) {
                continue;
            }
            $out[] = [
                'id' => (string)($b['id'] ?? ''),
                'belief_type' => (string)($b['belief_type'] ?? ''),
                'status' => (string)($b['status'] ?? ''),
                'confidence' => isset($b['confidence']) ? (float)$b['confidence'] : 0.5,
                'text' => mb_substr(trim((string)($b['belief_text'] ?? '')), 0, 240, 'UTF-8'),
            ];
            $n++;
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $beliefs
     * @return array{stable:list<array<string,mixed>>,disputed:list<array<string,mixed>>,emerging:list<array<string,mixed>>,obsolete:list<array<string,mixed>>}
     */
    private function classifyBeliefs(array $beliefs): array
    {
        $out = ['stable' => [], 'disputed' => [], 'emerging' => [], 'obsolete' => []];
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
            }
        }

        return $out;
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

    /** @param array<string,mixed> $payload */
    private function computeSnapshotHash(string $markdown, array $payload): string
    {
        return DeterministicHash::sha256($this->snapshotHashPayload($markdown, $payload));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{valid:?bool,current_hash:?string,expected_hash:?string,mismatch:string|null}
     */
    public function verifySnapshotIntegrityRecord(array $row): array
    {
        $hasFullPayload = isset(
            $row['snapshot_markdown'],
            $row['strategic_narrative_json'],
            $row['beliefs_snapshot_json'],
            $row['risks_snapshot_json'],
            $row['evidence_snapshot_json'],
            $row['social_snapshot_json'],
            $row['timeline_snapshot_json'],
            $row['memory_compilations_json'],
            $row['source_summary_json'],
            $row['metadata_json']
        );
        $current = isset($row['snapshot_hash']) && (string)$row['snapshot_hash'] !== '' ? (string)$row['snapshot_hash'] : null;
        if (!$hasFullPayload) {
            return [
                'valid' => null,
                'current_hash' => $current,
                'expected_hash' => null,
                'mismatch' => 'integrity_requires_full_snapshot_payload',
            ];
        }

        $payload = [
            'strategic_narrative' => $this->jsonDecode((string)($row['strategic_narrative_json'] ?? '{}')),
            'beliefs' => $this->jsonDecode((string)($row['beliefs_snapshot_json'] ?? '{}')),
            'risks' => $this->jsonDecode((string)($row['risks_snapshot_json'] ?? '{}')),
            'evidence' => $this->jsonDecode((string)($row['evidence_snapshot_json'] ?? '{}')),
            'social' => $this->jsonDecode((string)($row['social_snapshot_json'] ?? '{}')),
            'timeline' => $this->jsonDecode((string)($row['timeline_snapshot_json'] ?? '{}')),
            'memory_compilations' => $this->jsonDecode((string)($row['memory_compilations_json'] ?? '{}')),
            'source_summary' => $this->jsonDecode((string)($row['source_summary_json'] ?? '{}')),
            'metadata' => $this->jsonDecode((string)($row['metadata_json'] ?? '{}')),
        ];
        $expected = $this->computeSnapshotHash((string)($row['snapshot_markdown'] ?? ''), $payload);
        $valid = $current !== null && hash_equals($expected, $current);

        return [
            'valid' => $valid,
            'current_hash' => $current,
            'expected_hash' => $expected,
            'mismatch' => $valid ? null : 'snapshot_hash_mismatch',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function snapshotHashPayload(string $markdown, array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $contextMeta = is_array($metadata['context'] ?? null) ? $metadata['context'] : [];
        $stateEcho = is_array($metadata['current_state_echo'] ?? null) ? $metadata['current_state_echo'] : [];

        return [
            'markdown' => $markdown,
            'beliefs' => $payload['beliefs'] ?? [],
            'narrative' => $payload['strategic_narrative'] ?? [],
            'risks' => $payload['risks'] ?? [],
            'evidence' => $payload['evidence'] ?? [],
            'social' => $payload['social'] ?? [],
            'timeline' => $payload['timeline'] ?? [],
            'memory_compilations' => $payload['memory_compilations'] ?? [],
            'source_summary' => $payload['source_summary'] ?? [],
            'metadata_context' => [
                'title' => (string)($contextMeta['title'] ?? ''),
                'status' => (string)($contextMeta['status'] ?? ''),
                'description' => (string)($contextMeta['description'] ?? ''),
            ],
            'metadata_current_state_echo' => [
                'current_decision_status' => (string)($stateEcho['current_decision_status'] ?? ''),
                'current_confidence' => (string)($stateEcho['current_confidence'] ?? ''),
                'latest_next_step' => (string)($stateEcho['latest_next_step'] ?? ''),
            ],
            'metadata_tags' => is_array($metadata['tags'] ?? null) ? array_values(array_map('strval', $metadata['tags'])) : [],
        ];
    }

    private function jsonDecode(string $s): array
    {
        $d = json_decode($s, true);

        return is_array($d) ? $d : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowToApi(array $row, bool $includeBody): array
    {
        $sum = $this->jsonDecode((string)($row['source_summary_json'] ?? '{}'));
        $out = [
            'id' => (string)($row['id'] ?? ''),
            'strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            'snapshot_type' => (string)($row['snapshot_type'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => $row['description'] !== null && (string)$row['description'] !== '' ? (string)$row['description'] : null,
            'source_summary' => $sum,
            'snapshot_hash' => $row['snapshot_hash'] !== null && (string)$row['snapshot_hash'] !== '' ? (string)$row['snapshot_hash'] : null,
            'created_by' => $row['created_by'] !== null && (string)$row['created_by'] !== '' ? (string)$row['created_by'] : null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'integrity' => $this->verifySnapshotIntegrityRecord($row),
        ];
        if ($includeBody) {
            $out['snapshot_markdown'] = (string)($row['snapshot_markdown'] ?? '');
            $out['strategic_narrative'] = $this->jsonDecode((string)($row['strategic_narrative_json'] ?? '{}'));
            $out['beliefs_snapshot'] = $this->jsonDecode((string)($row['beliefs_snapshot_json'] ?? '{}'));
            $out['risks_snapshot'] = $this->jsonDecode((string)($row['risks_snapshot_json'] ?? '{}'));
            $out['evidence_snapshot'] = $this->jsonDecode((string)($row['evidence_snapshot_json'] ?? '{}'));
            $out['social_snapshot'] = $this->jsonDecode((string)($row['social_snapshot_json'] ?? '{}'));
            $out['timeline_snapshot'] = $this->jsonDecode((string)($row['timeline_snapshot_json'] ?? '{}'));
            $out['memory_compilations_snapshot'] = $this->jsonDecode((string)($row['memory_compilations_json'] ?? '{}'));
            $out['metadata'] = $this->jsonDecode((string)($row['metadata_json'] ?? '{}'));
        } else {
            $out['snapshot_markdown'] = null;
            $meta = $this->jsonDecode((string)($row['metadata_json'] ?? '{}'));
            $out['metadata'] = [
                'tags' => is_array($meta['tags'] ?? null) ? $meta['tags'] : [],
                'warnings' => is_array($meta['warnings'] ?? null) ? array_slice($meta['warnings'], 0, 6) : [],
            ];
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

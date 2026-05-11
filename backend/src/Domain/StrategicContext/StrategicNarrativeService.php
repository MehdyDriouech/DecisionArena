<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\CognitiveGovernance\CanonicalLayerMutationGuard;
use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Domain\CognitiveGovernance\DeterministicHash;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\StrategicContextNarrativeRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Infrastructure\Persistence\VerdictRepository;

/**
 * Strategic Narrative : agrégat déterministe, dérivé et auditable (≠ vérité canonique).
 * Aucun LLM · aucune écriture hors table strategic_context_narratives lors du recompute.
 */
final class StrategicNarrativeService
{
    public function __construct(
        private ?StrategicContextRepository $contexts = null,
        private ?WorkspaceTimelineService $timeline = null,
        private ?DecisionMemoryRepository $memories = null,
        private ?StrategicContextNarrativeRepository $store = null,
        private ?VerdictRepository $verdicts = null,
        private ?BeliefEngineService $beliefEngine = null,
        private ?\PDO $pdo = null,
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->timeline = $timeline ?? new WorkspaceTimelineService();
        $this->memories = $memories ?? new DecisionMemoryRepository();
        $this->store = $store ?? new StrategicContextNarrativeRepository();
        $this->verdicts = $verdicts ?? new VerdictRepository();
        $this->beliefEngine = $beliefEngine ?? new BeliefEngineService();
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Dernière narrative persistée, ou gabarit vide + avertissement si jamais calculée.
     *
     * @return array{context_id:string,narrative:array<string,mixed>,warnings:list<string>}
     */
    public function getApiResponse(string $contextId): array
    {
        $row = $this->store->findByContextId($contextId);
        if ($row === null) {
            return [
                'context_id' => $contextId,
                'narrative' => $this->emptyNarrativeTemplate(),
                'warnings' => ['narrative_not_computed'],
                'cognitive_provenance' => null,
            ];
        }
        $slice = $this->store->toApiSlice($row);
        $ss = $slice['narrative']['source_summary'] ?? null;
        $prov = is_array($ss) && isset($ss['cognitive_provenance']) && is_array($ss['cognitive_provenance'])
            ? $ss['cognitive_provenance']
            : null;

        return [
            'context_id' => $contextId,
            'narrative' => $slice['narrative'],
            'warnings' => $slice['warnings'],
            'cognitive_provenance' => $prov,
        ];
    }

    /**
     * @return array{context_id:string,narrative:array<string,mixed>,warnings:list<string>}
     */
    public function recomputeAndPersist(string $contextId): array
    {
        $beliefHashBefore = null;
        if (CanonicalLayerMutationGuard::enabled()) {
            $beliefHashBefore = $this->beliefRowsHash($contextId);
        }
        $built = $this->buildNarrative($contextId);
        $n = $built['narrative'];
        $warnings = $built['warnings'];
        $this->store->upsert($contextId, $n, $warnings);
        if (CanonicalLayerMutationGuard::enabled()) {
            $beliefHashAfter = $this->beliefRowsHash($contextId);
            if ($beliefHashBefore !== $beliefHashAfter) {
                CanonicalLayerMutationGuard::assertAllowed(
                    'narrative_service',
                    'beliefs',
                    'mutate',
                    ['strategic_context_id' => $contextId]
                );
            }
        }
        $ss = $n['source_summary'] ?? [];
        $prov = is_array($ss) && isset($ss['cognitive_provenance']) && is_array($ss['cognitive_provenance'])
            ? $ss['cognitive_provenance']
            : null;

        return [
            'context_id' => $contextId,
            'narrative' => $n,
            'warnings' => $warnings,
            'cognitive_provenance' => $prov,
        ];
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

    /** @return array{narrative:array<string,mixed>,warnings:list<string>} */
    private function buildNarrative(string $contextId): array
    {
        $warnings = [];
        $tl = $this->timeline->build($contextId, false);
        foreach ($tl['warnings'] ?? [] as $w) {
            $s = trim((string)$w);
            if ($s !== '') {
                $warnings[] = $s;
            }
        }

        $ctxRow = $this->contexts->find($contextId);
        $current = $this->contexts->currentState($contextId);
        $items = $tl['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $sessionIds = $this->fetchContextSessionIds($contextId);

        if (count($items) < 2) {
            $warnings[] = 'insufficient_timeline_signals';
        }
        if ($sessionIds === []) {
            $warnings[] = 'no_sessions_in_scope';
        }

        $countsByType = [];
        foreach ($items as $it) {
            $ty = (string)($it['type'] ?? '');
            $countsByType[$ty] = ($countsByType[$ty] ?? 0) + 1;
        }

        $majorRisks = [];
        foreach ($current['active_risks'] ?? [] as $r) {
            $s = trim((string)$r);
            if ($s !== '' && !in_array($s, $majorRisks, true)) {
                $majorRisks[] = $s;
            }
        }
        foreach ($items as $it) {
            if (($it['type'] ?? '') !== 'risk') {
                continue;
            }
            $line = trim((string)($it['summary'] ?? ''));
            if ($line === '') {
                $line = trim((string)($it['title'] ?? ''));
            }
            if ($line !== '' && !in_array($line, $majorRisks, true)) {
                $majorRisks[] = $line;
            }
            if (count($majorRisks) >= 14) {
                break;
            }
        }

        $completedBriefs = $this->fetchCompletedSessionBriefs($sessionIds);
        if ($completedBriefs === []) {
            $warnings[] = 'no_completed_sessions_with_brief';
        }
        foreach ($completedBriefs as $row) {
            foreach ($this->briefRisks((string)($row['decision_brief'] ?? '')) as $br) {
                if ($br !== '' && !in_array($br, $majorRisks, true)) {
                    $majorRisks[] = $br;
                }
                if (count($majorRisks) >= 16) {
                    break 2;
                }
            }
        }

        $conflicts = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') !== 'relationship_event') {
                continue;
            }
            if (!$this->isPotentialConflictItem($it)) {
                continue;
            }
            $line = trim((string)($it['title'] ?? '') . ' — ' . (string)($it['summary'] ?? ''));
            $line = trim($line, " —\t\n\r\0\x0B");
            if ($line !== '' && !in_array($line, $conflicts, true)) {
                $conflicts[] = $line;
            }
            if (count($conflicts) >= 10) {
                break;
            }
        }
        if ($conflicts === [] && ($countsByType['relationship_event'] ?? 0) > 0) {
            $warnings[] = 'relationship_events_present_but_no_high_signal_conflict_lines';
        }

        $confidenceTrend = $this->buildConfidenceTrend($completedBriefs, (string)($current['current_confidence'] ?? ''));

        $assumptions = [];
        $linkedMems = $this->memories->findLinkedMemoriesForStrategicContext($contextId, 1);
        if ($linkedMems !== []) {
            $vh = $linkedMems[0]['validated_hypotheses'] ?? [];
            if (is_array($vh)) {
                foreach ($vh as $h) {
                    $t = trim((string)$h);
                    if ($t !== '' && !in_array($t, $assumptions, true)) {
                        $assumptions[] = $t;
                    }
                    if (count($assumptions) >= 10) {
                        break;
                    }
                }
            }
        }
        if ($assumptions === []) {
            $warnings[] = 'no_validated_hypotheses_from_linked_memory';
        }

        $recentShifts = [];
        foreach ($items as $it) {
            if (count($recentShifts) >= 8) {
                break;
            }
            $ty = (string)($it['type'] ?? '');
            $ti = trim((string)($it['title'] ?? ''));
            if ($ti === '') {
                continue;
            }
            $recentShifts[] = $ty . ': ' . $ti . ' @ ' . (string)($it['created_at'] ?? '');
        }
        if ($recentShifts === []) {
            $warnings[] = 'no_recent_shifts_from_timeline';
        }

        $pmCount = $this->countPostmortems($sessionIds);
        $rerunCount = $this->countReruns($sessionIds);
        $verdictSessions = $this->countSessionsWithVerdict(array_slice($sessionIds, 0, 24));

        $currentDirection = $this->buildCurrentDirection($ctxRow ?? [], $current, $items);
        if (trim($currentDirection) === '') {
            $currentDirection = 'Direction stratégique non inférable à partir des sources disponibles (contexte vide ou non relié).';
            $warnings[] = 'insufficient_data_for_current_direction';
        }

        $computedAt = date('c');
        $sourceSummary = [
            'strategic_context_id' => $contextId,
            'timeline_item_count' => count($items),
            'counts_by_type' => $countsByType,
            'linked_session_links' => count($this->contexts->linkedSessionIds($contextId)),
            'linked_memory_links' => count($this->contexts->linkedMemoryIds($contextId)),
            'sessions_in_scope_count' => count($sessionIds),
            'postmortems_in_scope_count' => $pmCount,
            'reruns_in_scope_count' => $rerunCount,
            'sessions_with_verdict_count' => $verdictSessions,
            'computed_from' => [
                'WorkspaceTimelineService::build(include_legacy=false)',
                'StrategicContextRepository::currentState',
                'DecisionMemoryRepository::findLinkedMemoriesForStrategicContext',
                'sessions.decision_brief (completed)',
                'session_postmortems (count)',
                'sessions.parent_session_id (rerun count)',
                'session_verdicts (presence count)',
            ],
            'computed_at' => $computedAt,
        ];

        $beliefLayer = $this->mergeBeliefsIntoNarrativeSlices($contextId, $assumptions, $conflicts, $warnings);
        $sourceSummary['beliefs_layer_readonly'] = $beliefLayer['meta'];
        if (($beliefLayer['meta']['beliefs_rows_considered'] ?? 0) > 0) {
            $sourceSummary['computed_from'][] = 'BeliefEngineService::listForNarrativeEnrichment (read-only, no mutation)';
        }
        $narrativeInputHash = DeterministicHash::sha256([
            'strategic_context_id' => $contextId,
            'timeline_items_count' => count($items),
            'timeline_warnings' => $sourceSummary['counts_by_type'] ?? [],
            'session_ids' => $sessionIds,
            'current_state' => $current,
            'major_risks' => $majorRisks,
            'unresolved_conflicts' => $conflicts,
            'assumptions' => $assumptions,
            'recent_shifts' => $recentShifts,
            'belief_ids_sample' => $beliefLayer['meta']['belief_ids_sample'] ?? [],
        ]);
        $narrativeSourceHash = DeterministicHash::sha256([
            'computed_from' => $sourceSummary['computed_from'],
            'counts_by_type' => $sourceSummary['counts_by_type'],
            'sessions_in_scope_count' => $sourceSummary['sessions_in_scope_count'],
            'postmortems_in_scope_count' => $sourceSummary['postmortems_in_scope_count'],
            'reruns_in_scope_count' => $sourceSummary['reruns_in_scope_count'],
            'sessions_with_verdict_count' => $sourceSummary['sessions_with_verdict_count'],
            'beliefs_rows_considered' => $beliefLayer['meta']['beliefs_rows_considered'] ?? 0,
        ]);
        $narrativeRuntimeHash = DeterministicHash::sha256([
            'current_direction' => $currentDirection,
            'major_risks' => array_slice($majorRisks, 0, 16),
            'unresolved_conflicts' => $conflicts,
            'confidence_trend' => $confidenceTrend,
            'key_assumptions' => $assumptions,
            'recent_shifts' => $recentShifts,
            'warnings' => array_values(array_unique($warnings)),
        ]);
        $sourceSummary['provenance_integrity'] = [
            'input_hash' => $narrativeInputHash,
            'source_hash' => $narrativeSourceHash,
            'runtime_hash' => $narrativeRuntimeHash,
            'forensic_fingerprint' => DeterministicHash::sha256([
                'context_id' => $contextId,
                'input_hash' => $narrativeInputHash,
                'source_hash' => $narrativeSourceHash,
                'runtime_hash' => $narrativeRuntimeHash,
            ]),
        ];

        $sourceSummary['cognitive_provenance'] = CognitiveProvenanceEnvelope::forStrategicNarrative(
            $contextId,
            $sourceSummary['computed_from'],
            [
                'computed_at' => $computedAt,
                'source_count' => count($sessionIds) + count($items) + (int)($beliefLayer['meta']['beliefs_rows_considered'] ?? 0),
                'source_hash' => $narrativeSourceHash,
                'runtime_hash' => $narrativeRuntimeHash,
                'input_hashes' => [$narrativeInputHash],
                'pruned_sources' => [
                    'timeline_capped_to_service_limits',
                    'briefs_limited_to_last_10_completed_sessions',
                ],
                'excluded_sources' => array_values(array_filter([
                    count($items) < 2 ? 'insufficient_timeline_for_shifts' : null,
                    $sessionIds === [] ? 'no_sessions_in_scope' : null,
                ])),
            ]
        );

        $narrative = [
            'current_direction' => $currentDirection,
            'major_risks' => array_slice($majorRisks, 0, 16),
            'unresolved_conflicts' => $conflicts,
            'confidence_trend' => $confidenceTrend,
            'key_assumptions' => $assumptions,
            'recent_shifts' => $recentShifts,
            'source_summary' => $sourceSummary,
            'computed_at' => $computedAt,
        ];

        return ['narrative' => $narrative, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param list<string> $assumptions
     * @param list<string> $conflicts
     * @param list<string> $warnings
     * @return array{meta:array<string,mixed>}
     */
    private function mergeBeliefsIntoNarrativeSlices(string $contextId, array &$assumptions, array &$conflicts, array &$warnings): array
    {
        $meta = [
            'beliefs_rows_considered' => 0,
            'belief_ids_sample' => [],
            'note' => 'Beliefs are explicit user/system records; narrative lines prefixed [Beliefs MVP] are not factual truth.',
        ];
        try {
            $rows = $this->beliefEngine->listForNarrativeEnrichment($contextId);
        } catch (\Throwable) {
            return ['meta' => $meta];
        }
        $meta['beliefs_rows_considered'] = count($rows);
        if ($rows === []) {
            return ['meta' => $meta];
        }
        $warnings[] = 'narrative_includes_beliefs_mvp_readonly_layer';
        foreach ($rows as $b) {
            $bid = (string)($b['id'] ?? '');
            if ($bid !== '' && count($meta['belief_ids_sample']) < 12) {
                $meta['belief_ids_sample'][] = $bid;
            }
            $type = (string)($b['belief_type'] ?? '');
            $st = (string)($b['status'] ?? '');
            $agent = $b['agent_id'] !== null && (string)$b['agent_id'] !== '' ? (string)$b['agent_id'] : '—';
            $txt = trim((string)($b['belief_text'] ?? ''));
            if ($txt === '') {
                continue;
            }
            if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($txt, 'UTF-8') > 130) {
                $txt = mb_substr($txt, 0, 129, 'UTF-8') . '…';
            } elseif (strlen($txt) > 130) {
                $txt = substr($txt, 0, 129) . '…';
            }
            if ($st === 'active' && in_array($type, ['hypothesis', 'belief', 'interpretation', 'social_perception'], true)) {
                $line = '[Beliefs MVP] ' . $agent . ' · ' . $type . ' : ' . $txt;
                if (!in_array($line, $assumptions, true) && count($assumptions) < 14) {
                    $assumptions[] = $line;
                }
            }
            $dis = is_array($b['disagreeing_agents'] ?? null) ? $b['disagreeing_agents'] : [];
            if ($st === 'disputed' || (is_array($dis) && $dis !== [])) {
                $line2 = '[Beliefs MVP · tension] ' . $agent . ' · ' . $type . ' : ' . $txt;
                if (!in_array($line2, $conflicts, true) && count($conflicts) < 12) {
                    $conflicts[] = $line2;
                }
            }
        }

        return ['meta' => $meta];
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

    /**
     * @param list<string> $sessionIds
     * @return list<array<string,mixed>>
     */
    private function fetchCompletedSessionBriefs(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $sessionIds = array_slice($sessionIds, 0, 200);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id, decision_brief, updated_at, created_at
            FROM sessions
            WHERE status IN ('completed', 'archived') AND id IN ($ph)
            ORDER BY datetime(COALESCE(updated_at, created_at)) DESC
            LIMIT 10
        ");
        $stmt->execute($sessionIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_reverse($rows);
    }

    /** @param list<array<string,mixed>> $completedChronoOldestFirst */
    private function buildConfidenceTrend(array $completedChronoOldestFirst, string $memoryConfidence): string
    {
        $points = [];
        foreach ($completedChronoOldestFirst as $row) {
            $c = $this->briefConfidence((string)($row['decision_brief'] ?? ''));
            if ($c !== '') {
                $points[] = $c;
            }
        }
        if (count($points) >= 2) {
            return 'Évolution observée (sessions terminées, ancien → récent) : ' . implode(' → ', $points);
        }
        if (count($points) === 1) {
            return 'Dernière session terminée : ' . $points[0];
        }
        $mc = trim($memoryConfidence);
        if ($mc !== '') {
            return 'Mémoire décisionnelle confirmée (champ confidence) : ' . $mc;
        }
        return 'données_insuffisantes_pour_tendance';
    }

    private function briefConfidence(string $json): string
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return '';
        }
        $c = $d['confidence'] ?? '';
        if (is_array($c)) {
            return '';
        }
        return trim((string)$c);
    }

    /** @return list<string> */
    private function briefRisks(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return [];
        }
        $r = $d['risks'] ?? [];
        if (!is_array($r)) {
            return [];
        }
        $out = [];
        foreach ($r as $x) {
            $t = trim((string)$x);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $item timeline item */
    private function isPotentialConflictItem(array $item): bool
    {
        $meta = $item['metadata'] ?? [];
        if (!is_array($meta)) {
            return false;
        }
        $et = strtolower((string)($meta['event_type'] ?? ''));
        foreach (['conflict', 'disagree', 'friction', 'tension', 'dispute', 'challenge'] as $kw) {
            if (str_contains($et, $kw)) {
                return true;
            }
        }
        $intensity = isset($meta['intensity']) ? (float)$meta['intensity'] : 0.0;

        return $intensity >= 0.55;
    }

    /** @param list<string> $sessionIds */
    private function countPostmortems(array $sessionIds): int
    {
        if ($sessionIds === []) {
            return 0;
        }
        $sessionIds = array_slice($sessionIds, 0, 200);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM session_postmortems WHERE session_id IN ($ph)");
            $stmt->execute($sessionIds);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<string> $sessionIds */
    private function countReruns(array $sessionIds): int
    {
        if ($sessionIds === []) {
            return 0;
        }
        $sessionIds = array_slice($sessionIds, 0, 200);
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sessions WHERE parent_session_id IS NOT NULL AND id IN ($ph)");
            $stmt->execute($sessionIds);

            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<string> $sessionIds */
    private function countSessionsWithVerdict(array $sessionIds): int
    {
        $n = 0;
        foreach ($sessionIds as $sid) {
            if ($this->verdicts->findBySession($sid) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<string,mixed> $ctxRow
     * @param array<string,mixed> $current
     * @param list<array<string,mixed>> $items
     */
    private function buildCurrentDirection(array $ctxRow, array $current, array $items): string
    {
        $parts = [];
        $title = trim((string)($ctxRow['title'] ?? ''));
        if ($title !== '') {
            $parts[] = 'Objectif / périmètre : ' . $title;
        }
        $desc = trim((string)($ctxRow['description'] ?? ''));
        if ($desc !== '') {
            $d = $desc;
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($d, 'UTF-8') > 400) {
                    $d = mb_substr($d, 0, 399, 'UTF-8') . '…';
                }
            } elseif (strlen($d) > 400) {
                $d = substr($d, 0, 399) . '…';
            }
            $parts[] = $d;
        }
        $nx = trim((string)($current['latest_next_step'] ?? ''));
        if ($nx !== '') {
            $parts[] = 'Prochaine étape (mémoire confirmée) : ' . $nx;
        } else {
            $ds = trim((string)($current['decision_summary'] ?? ''));
            if ($ds !== '') {
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($ds, 'UTF-8') > 340) {
                        $ds = mb_substr($ds, 0, 339, 'UTF-8') . '…';
                    }
                } elseif (strlen($ds) > 340) {
                    $ds = substr($ds, 0, 339) . '…';
                }
                $parts[] = 'Synthèse décisionnelle récente : ' . $ds;
            }
        }
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'decision') {
                $sum = trim((string)($it['summary'] ?? ''));
                if ($sum !== '') {
                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                        if (mb_strlen($sum, 'UTF-8') > 240) {
                            $sum = mb_substr($sum, 0, 239, 'UTF-8') . '…';
                        }
                    } elseif (strlen($sum) > 240) {
                        $sum = substr($sum, 0, 239) . '…';
                    }
                    $parts[] = 'Dernière décision de session observée : ' . $sum;
                }
                break;
            }
        }

        return implode("\n\n", array_values(array_filter($parts, static fn ($p) => trim((string)$p) !== '')));
    }

    /** @return array<string,mixed> */
    private function emptyNarrativeTemplate(): array
    {
        return [
            'current_direction' => '',
            'major_risks' => [],
            'unresolved_conflicts' => [],
            'confidence_trend' => 'données_insuffisantes_pour_tendance',
            'key_assumptions' => [],
            'recent_shifts' => [],
            'source_summary' => [],
            'computed_at' => '',
        ];
    }
}

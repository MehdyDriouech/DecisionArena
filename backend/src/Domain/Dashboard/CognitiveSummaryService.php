<?php
declare(strict_types=1);

namespace Domain\Dashboard;

use Infrastructure\Persistence\Database;

final class CognitiveSummaryService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function buildSummary(?string $requestedContextId = null): array
    {
        $activeContext = $this->fetchActiveContext();
        $contexts = $this->fetchContexts();
        $activeContextId = is_array($activeContext) ? (string)($activeContext['context_id'] ?? '') : '';
        $scope = $this->resolveScope($requestedContextId, $activeContextId, $contexts);
        $scopeContextId = (string)($scope['selected_context_id'] ?? '');

        $sessions = $this->fetchScopedSessions($scopeContextId !== '' ? $scopeContextId : null);
        $sessionIds = array_values(array_filter(array_map(
            static fn(array $s): string => (string)($s['id'] ?? ''),
            $sessions
        )));

        $riskBySession = $this->fetchRiskProfilesBySessionIds($sessionIds);
        $social = $this->fetchSocialConflictSignals($sessionIds);
        $lastSnapshotByContext = $this->fetchLastSnapshotAtByContext();
        $lastCompilationByContext = $this->fetchLastCompilationAtByContext();
        $runtimeSignals = $this->fetchRuntimeSignals($sessionIds);
        $selectedContext = $this->findContextById($contexts, $scopeContextId);

        $activity = $this->computeActivitySection($sessions, $selectedContext ?? $activeContext, $contexts);
        $quality = $this->computeQualitySection($sessions);
        $risks = $this->computeRiskSection($sessions, $riskBySession, $social, $contexts, $sessionIds);
        $contextsSection = $this->computeContextsSection(
            $sessions,
            $contexts,
            $riskBySession,
            $lastSnapshotByContext,
            $lastCompilationByContext
        );

        return [
            'generated_at' => date('c'),
            'scope' => [
                'mode' => (string)($scope['mode'] ?? ($scopeContextId !== '' ? 'active_context_only' : 'all_contexts_no_active_workspace')),
                'active_context_id' => $activeContextId !== '' ? $activeContextId : null,
                'selected_context_id' => $scopeContextId !== '' ? $scopeContextId : null,
                'requested_context_id' => $scope['requested_context_id'] ?? null,
                'sessions_count' => count($sessions),
            ],
            'activity' => $activity,
            'decision_quality' => $quality,
            'risks' => $risks,
            'runtime_expert' => $runtimeSignals,
            'strategic_contexts' => [
                'items' => $contextsSection,
            ],
            'reliability' => [
                'notes' => [
                    'runtime_expert section is partial: some signals are not canonically persisted in sessions.result',
                    'confidence average is normalized from textual decision_brief confidence labels',
                ],
                'kpi_quality' => [
                    'activity' => 'high',
                    'decision_quality' => 'medium',
                    'risks' => 'medium',
                    'runtime_expert' => 'low_to_medium',
                    'strategic_contexts' => 'medium',
                ],
            ],
        ];
    }

    private function resolveScope(?string $requestedContextId, string $activeContextId, array $contexts): array
    {
        $requested = is_string($requestedContextId) ? trim($requestedContextId) : '';
        $contextIds = [];
        foreach ($contexts as $ctx) {
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid !== '') {
                $contextIds[$cid] = true;
            }
        }

        if ($requested === '' || $requested === null) {
            if ($activeContextId !== '') {
                return [
                    'mode' => 'active_context_only',
                    'requested_context_id' => null,
                    'selected_context_id' => $activeContextId,
                ];
            }
            return [
                'mode' => 'all_contexts_no_active_workspace',
                'requested_context_id' => null,
                'selected_context_id' => null,
            ];
        }

        if (strtolower($requested) === 'all') {
            return [
                'mode' => 'all_contexts_manual',
                'requested_context_id' => 'all',
                'selected_context_id' => null,
            ];
        }

        if (isset($contextIds[$requested])) {
            return [
                'mode' => 'specific_context_manual',
                'requested_context_id' => $requested,
                'selected_context_id' => $requested,
            ];
        }

        if ($activeContextId !== '') {
            return [
                'mode' => 'active_context_fallback_invalid_request',
                'requested_context_id' => $requested,
                'selected_context_id' => $activeContextId,
            ];
        }

        return [
            'mode' => 'all_contexts_fallback_invalid_request',
            'requested_context_id' => $requested,
            'selected_context_id' => null,
        ];
    }

    private function findContextById(array $contexts, string $contextId): ?array
    {
        $cid = trim($contextId);
        if ($cid === '') {
            return null;
        }
        foreach ($contexts as $ctx) {
            if ((string)($ctx['context_id'] ?? '') === $cid) {
                return $ctx;
            }
        }
        return null;
    }

    private function fetchActiveContext(): ?array
    {
        $stmt = $this->pdo->query('SELECT context_id, title, status, updated_at FROM strategic_contexts WHERE is_workspace_active = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        return is_array($row) ? $row : null;
    }

    private function fetchScopedSessions(?string $contextId): array
    {
        if ($contextId === null || trim($contextId) === '') {
            $stmt = $this->pdo->query('SELECT id, strategic_context_id, title, mode, status, rounds, parent_session_id, decision_brief, result, updated_at, created_at FROM sessions ORDER BY created_at DESC LIMIT 500');
            return $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        }

        $stmt = $this->pdo->prepare('SELECT id, strategic_context_id, title, mode, status, rounds, parent_session_id, decision_brief, result, updated_at, created_at FROM sessions WHERE strategic_context_id = ? ORDER BY created_at DESC LIMIT 500');
        $stmt->execute([$contextId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchRiskProfilesBySessionIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare("SELECT session_id, risk_level, updated_at, created_at FROM session_risk_profiles WHERE session_id IN ($ph)");
        $stmt->execute($sessionIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $sid = (string)($row['session_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $out[$sid] = $row;
        }
        return $out;
    }

    private function fetchSocialConflictSignals(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [
                'active_contradictions' => 0,
                'inter_agent_conflicts' => 0,
            ];
        }
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));

        $evt = $this->pdo->prepare("
            SELECT COUNT(*) AS c
            FROM relationship_events
            WHERE session_id IN ($ph)
              AND (
                LOWER(COALESCE(event_type, '')) IN ('challenge', 'attack')
                OR COALESCE(intensity, 0) >= 0.55
              )
        ");
        $evt->execute($sessionIds);
        $activeContradictions = (int)($evt->fetchColumn() ?: 0);

        $rel = $this->pdo->prepare("
            SELECT COUNT(*) AS c
            FROM agent_relationships
            WHERE session_id IN ($ph)
              AND COALESCE(conflict, 0) >= 0.60
        ");
        $rel->execute($sessionIds);
        $interAgentConflicts = (int)($rel->fetchColumn() ?: 0);

        return [
            'active_contradictions' => $activeContradictions,
            'inter_agent_conflicts' => $interAgentConflicts,
        ];
    }

    private function fetchContexts(): array
    {
        $stmt = $this->pdo->query('SELECT context_id, title, status, is_workspace_active, updated_at FROM strategic_contexts ORDER BY is_workspace_active DESC, updated_at DESC LIMIT 120');
        return $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function fetchLastSnapshotAtByContext(): array
    {
        $stmt = $this->pdo->query('SELECT strategic_context_id, MAX(created_at) AS last_snapshot_at FROM strategic_context_snapshots GROUP BY strategic_context_id');
        $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        $out = [];
        foreach ($rows as $row) {
            $cid = (string)($row['strategic_context_id'] ?? '');
            if ($cid !== '') {
                $out[$cid] = $row['last_snapshot_at'] ?? null;
            }
        }
        return $out;
    }

    private function fetchLastCompilationAtByContext(): array
    {
        $stmt = $this->pdo->query('SELECT strategic_context_id, MAX(created_at) AS last_compilation_at FROM strategic_context_memory_compilations GROUP BY strategic_context_id');
        $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        $out = [];
        foreach ($rows as $row) {
            $cid = (string)($row['strategic_context_id'] ?? '');
            if ($cid !== '') {
                $out[$cid] = $row['last_compilation_at'] ?? null;
            }
        }
        return $out;
    }

    private function fetchRuntimeSignals(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [
                'coverage_ratio' => 0,
                'runtime_warnings' => 0,
                'retries' => 0,
                'budget_pressure' => 0,
                'pruning_events' => 0,
                'large_traces' => 0,
                'truncated_payloads' => 0,
                'qa_mode_active' => 0,
            ];
        }

        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT meta_json
            FROM messages
            WHERE session_id IN ($ph)
              AND role = 'assistant'
              AND meta_json IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 1200
        ");
        $stmt->execute($sessionIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $warnings = 0;
        $retries = 0;
        $budgetPressure = 0;
        $pruningEvents = 0;
        $largeTraces = 0;
        $truncatedPayloads = 0;
        $qaModeActive = 0;
        $traceCount = 0;

        foreach ($rows as $row) {
            $metaRaw = (string)($row['meta_json'] ?? '');
            if ($metaRaw === '') {
                continue;
            }
            $meta = json_decode($metaRaw, true);
            if (!is_array($meta)) {
                continue;
            }
            $trace = $meta['prompt_injection_trace'] ?? null;
            if (!is_array($trace)) {
                continue;
            }
            $traceCount++;
            if (strlen(json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '') > 28000) {
                $largeTraces++;
            }

            $rw = $trace['runtime_warnings'] ?? [];
            if (is_array($rw)) {
                $warnings += count($rw);
                foreach ($rw as $w) {
                    $ws = strtolower((string)$w);
                    if (str_contains($ws, 'budget') || str_contains($ws, 'soft_budget') || str_contains($ws, 'hard_cap')) {
                        $budgetPressure++;
                    }
                    if (str_contains($ws, 'truncat')) {
                        $truncatedPayloads++;
                    }
                }
            }

            $qa = strtolower((string)($trace['qa_mode'] ?? 'off'));
            if ($qa !== '' && $qa !== 'off' && $qa !== 'dev') {
                $qaModeActive++;
            }

            $cb = $trace['cognitive_budget'] ?? [];
            if (is_array($cb)) {
                $remaining = isset($cb['global_remaining_chars']) ? (int)$cb['global_remaining_chars'] : null;
                if ($remaining !== null && $remaining < 1200) {
                    $budgetPressure++;
                }
                $pruned = $cb['pruned_sources'] ?? [];
                if (is_array($pruned)) {
                    $pruningEvents += count($pruned);
                }
            }

            $rt = $trace['cognitive_runtime'] ?? [];
            if (is_array($rt)) {
                $retryCount = isset($rt['retry_count']) ? (int)$rt['retry_count'] : 0;
                if ($retryCount > 0) {
                    $retries += $retryCount;
                }
                $runtimeMetrics = $rt['runtime_metrics'] ?? [];
                if (is_array($runtimeMetrics) && isset($runtimeMetrics['retry_count'])) {
                    $retries += max(0, (int)$runtimeMetrics['retry_count']);
                }
            }

            $steps = $trace['steps'] ?? [];
            if (is_array($steps)) {
                foreach ($steps as $step) {
                    if (!is_array($step)) {
                        continue;
                    }
                    $decision = strtolower((string)($step['pruning_decision'] ?? ''));
                    if ($decision !== '' && $decision !== 'kept') {
                        $pruningEvents++;
                    }
                    if (!empty($step['truncated']) || str_contains(strtolower((string)($step['exclusion_reason'] ?? '')), 'truncat')) {
                        $truncatedPayloads++;
                    }
                }
            }
        }

        $coverage = count($rows) > 0 ? round($traceCount / count($rows), 3) : 0;

        return [
            'coverage_ratio' => $coverage,
            'runtime_warnings' => $warnings,
            'retries' => $retries,
            'budget_pressure' => $budgetPressure,
            'pruning_events' => $pruningEvents,
            'large_traces' => $largeTraces,
            'truncated_payloads' => $truncatedPayloads,
            'qa_mode_active' => $qaModeActive,
        ];
    }

    private function computeActivitySection(array $sessions, ?array $activeContext, array $contexts): array
    {
        $active = 0;
        $completed = 0;
        $rerun = 0;
        $verdict = ['go' => 0, 'iterate' => 0, 'no_go' => 0, 'unknown' => 0];

        foreach ($sessions as $s) {
            $status = strtolower((string)($s['status'] ?? ''));
            if (in_array($status, ['active', 'running', 'draft'], true)) {
                $active++;
            }
            if ($status === 'completed') {
                $completed++;
            }
            if ((string)($s['parent_session_id'] ?? '') !== '') {
                $rerun++;
            }

            $key = $this->normalizeVerdictLabel($this->extractDecisionLabel($s));
            $verdict[$key] = ($verdict[$key] ?? 0) + 1;
        }

        $recentContexts = 0;
        $now = time();
        foreach ($contexts as $ctx) {
            $updatedAt = strtotime((string)($ctx['updated_at'] ?? ''));
            if ($updatedAt > 0 && (($now - $updatedAt) <= 30 * 24 * 3600)) {
                $recentContexts++;
            }
        }

        return [
            'active_analyses' => $active,
            'completed_analyses' => $completed,
            'verdict_breakdown' => $verdict,
            'rerun_analyses' => $rerun,
            'active_strategic_context' => $activeContext,
            'contexts_recent_activity' => $recentContexts,
        ];
    }

    private function computeQualitySection(array $sessions): array
    {
        $scoreCount = 0;
        $scoreSum = 0.0;
        $falseConsensusHigh = 0;
        $confidenceCount = 0;
        $confidenceSum = 0.0;
        $fragile = 0;
        $blocked = 0;
        $depthCount = 0;
        $depthSum = 0.0;

        foreach ($sessions as $s) {
            $result = $this->decodeJson($s['result'] ?? null);
            $decisionBrief = $this->decodeJson($s['decision_brief'] ?? null);

            $score = isset($result['decision_quality_score']) ? (float)$result['decision_quality_score'] : null;
            if ($score !== null && is_finite($score)) {
                $scoreSum += max(0, min(100, $score));
                $scoreCount++;
            }

            $fcRisk = strtolower((string)($result['false_consensus']['risk_level'] ?? ''));
            if (in_array($fcRisk, ['high', 'critical'], true)) {
                $falseConsensusHigh++;
                $fragile++;
            }

            $guardStatus = strtolower((string)($result['guardrails']['guardrail_status'] ?? ''));
            if (in_array($guardStatus, ['blocked', 'auto_retry_triggered'], true) || in_array(strtolower((string)($s['status'] ?? '')), ['blocked', 'error'], true)) {
                $blocked++;
            }

            $conf = strtolower((string)($decisionBrief['confidence'] ?? ''));
            $confNorm = match ($conf) {
                'high' => 0.85,
                'medium' => 0.60,
                'low' => 0.35,
                default => null,
            };
            if ($confNorm !== null) {
                $confidenceSum += $confNorm;
                $confidenceCount++;
            }

            $rounds = (int)($s['rounds'] ?? 0);
            $extra = (int)($result['auto_retry']['extra_rounds'] ?? 0);
            $depth = $rounds + max(0, $extra);
            if ($depth > 0) {
                $depthSum += $depth;
                $depthCount++;
            }
        }

        $base = max(1, count($sessions));

        return [
            'avg_quality_score' => $scoreCount > 0 ? round($scoreSum / $scoreCount, 2) : null,
            'false_consensus_rate' => round($falseConsensusHigh / $base, 3),
            'avg_confidence_score' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 3) : null,
            'fragile_rate' => round($fragile / $base, 3),
            'blocked_rate' => round($blocked / $base, 3),
            'avg_debate_depth' => $depthCount > 0 ? round($depthSum / $depthCount, 2) : null,
            'coverage' => [
                'quality_score_sessions' => $scoreCount,
                'confidence_sessions' => $confidenceCount,
                'depth_sessions' => $depthCount,
                'total_sessions' => count($sessions),
            ],
        ];
    }

    private function computeRiskSection(array $sessions, array $riskBySession, array $social, array $contexts, array $sessionIds): array
    {
        $criticalOpen = 0;
        $highRiskDetected = 0;
        $contextsHighRisk = [];
        $highRiskDetails = [];

        foreach ($sessions as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $status = strtolower((string)($s['status'] ?? ''));
            $riskLevel = strtolower((string)($riskBySession[$sid]['risk_level'] ?? ''));
            $isHigh = in_array($riskLevel, ['high', 'critical'], true);
            if ($isHigh) {
                $highRiskDetected++;
                $cid = (string)($s['strategic_context_id'] ?? '');
                if ($cid !== '') {
                    $contextsHighRisk[$cid] = true;
                }
                if (count($highRiskDetails) < 30) {
                    $highRiskDetails[] = [
                        'session_id' => $sid,
                        'title' => (string)($s['title'] ?? ''),
                        'risk_level' => $riskLevel,
                        'status' => $status,
                        'strategic_context_id' => $cid !== '' ? $cid : null,
                        'updated_at' => (string)($riskBySession[$sid]['updated_at'] ?? $riskBySession[$sid]['created_at'] ?? $s['updated_at'] ?? ''),
                    ];
                }
            }
            if (in_array($status, ['active', 'running', 'draft'], true) && $isHigh) {
                $criticalOpen++;
            }
        }

        foreach ($contexts as $ctx) {
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $title = strtolower((string)($ctx['title'] ?? ''));
            if (str_contains($title, 'risk') || str_contains($title, 'risque') || str_contains($title, 'critical')) {
                $contextsHighRisk[$cid] = true;
            }
        }

        return [
            'critical_open_analyses' => $criticalOpen,
            'high_risks_detected' => $highRiskDetected,
            'active_contradictions' => (int)($social['active_contradictions'] ?? 0),
            'inter_agent_conflicts' => (int)($social['inter_agent_conflicts'] ?? 0),
            'contexts_high_risk' => count($contextsHighRisk),
            'details' => [
                'active_contradictions' => $this->fetchContradictionDetails($sessionIds),
                'inter_agent_conflicts' => $this->fetchInterAgentConflictDetails($sessionIds),
                'high_risks' => $highRiskDetails,
            ],
        ];
    }

    private function fetchContradictionDetails(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id, session_id, round_index, source_agent_id, target_agent_id, event_type, intensity, evidence, created_at
            FROM relationship_events
            WHERE session_id IN ($ph)
              AND (
                LOWER(COALESCE(event_type, '')) IN ('challenge', 'attack')
                OR COALESCE(intensity, 0) >= 0.55
              )
            ORDER BY created_at DESC
            LIMIT 40
        ");
        $stmt->execute($sessionIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'session_id' => (string)($row['session_id'] ?? ''),
                'round_index' => isset($row['round_index']) ? (int)$row['round_index'] : null,
                'source_agent_id' => (string)($row['source_agent_id'] ?? ''),
                'target_agent_id' => ($row['target_agent_id'] ?? null) !== null ? (string)$row['target_agent_id'] : null,
                'event_type' => (string)($row['event_type'] ?? ''),
                'intensity' => isset($row['intensity']) ? (float)$row['intensity'] : null,
                'evidence' => (string)($row['evidence'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    private function fetchInterAgentConflictDetails(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id, session_id, source_agent_id, target_agent_id, conflict, trust, affinity, challenge_count, attack_count, updated_at
            FROM agent_relationships
            WHERE session_id IN ($ph)
              AND COALESCE(conflict, 0) >= 0.60
            ORDER BY conflict DESC, updated_at DESC
            LIMIT 40
        ");
        $stmt->execute($sessionIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'session_id' => (string)($row['session_id'] ?? ''),
                'source_agent_id' => (string)($row['source_agent_id'] ?? ''),
                'target_agent_id' => (string)($row['target_agent_id'] ?? ''),
                'conflict' => isset($row['conflict']) ? (float)$row['conflict'] : 0.0,
                'trust' => isset($row['trust']) ? (float)$row['trust'] : 0.0,
                'affinity' => isset($row['affinity']) ? (float)$row['affinity'] : 0.0,
                'challenge_count' => isset($row['challenge_count']) ? (int)$row['challenge_count'] : 0,
                'attack_count' => isset($row['attack_count']) ? (int)$row['attack_count'] : 0,
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }, $rows);
    }

    private function computeContextsSection(
        array $sessions,
        array $contexts,
        array $riskBySession,
        array $lastSnapshotByContext,
        array $lastCompilationByContext
    ): array {
        $byContext = [];
        foreach ($contexts as $ctx) {
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $byContext[$cid] = [
                'context_id' => $cid,
                'title' => (string)($ctx['title'] ?? ''),
                'status' => (string)($ctx['status'] ?? ''),
                'is_workspace_active' => (int)($ctx['is_workspace_active'] ?? 0),
                'analyses_count' => 0,
                'major_decisions_count' => 0,
                'open_risks_count' => 0,
                'narrative_stability' => 'unknown',
                'reruns_count' => 0,
                'last_snapshot_at' => $lastSnapshotByContext[$cid] ?? null,
                'last_memory_compilation_at' => $lastCompilationByContext[$cid] ?? null,
            ];
        }

        foreach ($sessions as $s) {
            $cid = (string)($s['strategic_context_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (!isset($byContext[$cid])) {
                $byContext[$cid] = [
                    'context_id' => $cid,
                    'title' => $cid,
                    'status' => 'unknown',
                    'is_workspace_active' => 0,
                    'analyses_count' => 0,
                    'major_decisions_count' => 0,
                    'open_risks_count' => 0,
                    'narrative_stability' => 'unknown',
                    'reruns_count' => 0,
                    'last_snapshot_at' => $lastSnapshotByContext[$cid] ?? null,
                    'last_memory_compilation_at' => $lastCompilationByContext[$cid] ?? null,
                ];
            }
            $byContext[$cid]['analyses_count']++;

            $decision = $this->normalizeVerdictLabel($this->extractDecisionLabel($s));
            if (in_array($decision, ['go', 'iterate', 'no_go'], true)) {
                $byContext[$cid]['major_decisions_count']++;
            }
            $sid = (string)($s['id'] ?? '');
            $riskLevel = strtolower((string)($riskBySession[$sid]['risk_level'] ?? ''));
            if (in_array($riskLevel, ['high', 'critical'], true)) {
                $byContext[$cid]['open_risks_count']++;
            }
            if ((string)($s['parent_session_id'] ?? '') !== '') {
                $byContext[$cid]['reruns_count']++;
            }
        }

        return array_values($byContext);
    }

    private function extractDecisionLabel(array $session): string
    {
        $decisionBrief = $this->decodeJson($session['decision_brief'] ?? null);
        $result = $this->decodeJson($session['result'] ?? null);

        $raw = (string)($decisionBrief['decision'] ?? '');
        if ($raw !== '') {
            return $raw;
        }
        $raw = (string)($result['adjusted_decision']['ui_decision_label'] ?? '');
        if ($raw !== '') {
            return $raw;
        }
        $raw = (string)($result['adjusted_decision']['decision_label'] ?? '');
        if ($raw !== '') {
            return $raw;
        }
        $raw = (string)($result['decision_outcome']['decision'] ?? '');
        if ($raw !== '') {
            return $raw;
        }
        return '';
    }

    private function normalizeVerdictLabel(string $label): string
    {
        $l = strtolower(trim($label));
        if ($l === '') {
            return 'unknown';
        }
        if (in_array($l, ['go', 'proceed', 'approve'], true)) {
            return 'go';
        }
        if (in_array($l, ['iterate', 'iterer', 'itérer', 'needs-more-info', 'needs_more_info', 'reduce-scope', 'reduce_scope', 'pivot'], true)) {
            return 'iterate';
        }
        if (in_array($l, ['no-go', 'no_go', 'reject', 'stop'], true)) {
            return 'no_go';
        }
        return 'unknown';
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $d = json_decode($value, true);
        return is_array($d) ? $d : [];
    }
}

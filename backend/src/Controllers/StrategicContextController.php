<?php
declare(strict_types=1);

namespace Controllers;

use Domain\Memory\MemorySnapshotGenerator;
use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\AgentContextMemorySyncService;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Http\Request;
use Http\Response;
use Domain\StrategicContext\StrategicContextComparisonService;
use Domain\StrategicContext\MemoryGovernanceService;
use Domain\StrategicContext\StrategicNarrativeService;
use Domain\StrategicContext\WorkspaceTimelineService;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class StrategicContextController
{
    private StrategicContextRepository $repo;
    private DecisionMemoryRepository $memories;
    private SessionRepository $sessions;
    private StrategicContextWorkspaceAgentsCatalog $workspaceAgents;

    public function __construct()
    {
        $this->repo = new StrategicContextRepository();
        $this->memories = new DecisionMemoryRepository();
        $this->sessions = new SessionRepository();
        $this->workspaceAgents = new StrategicContextWorkspaceAgentsCatalog();
    }

    /** @param array<string,mixed> $c */
    private function contextPayload(array $c): array
    {
        $cid = (string)($c['context_id'] ?? '');
        return [
            ...$c,
            'linked_memory_ids' => $this->repo->linkedMemoryIds($cid),
            'linked_session_ids' => $this->repo->linkedSessionIds($cid),
            'current_state' => $this->repo->currentState($cid),
            'workspace_agents' => $this->workspaceAgents->buildForContext($cid),
            'workspace_agents_expert_personas' => $this->workspaceAgents->buildExpertPersonaFallbackForContext($cid),
        ];
    }

    private function isValidContextId(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** GET /api/strategic-contexts */
    public function index(Request $req): array
    {
        $status = (string)($req->query('status') ?? '');
        $limit = (int)($req->query('limit') ?? 100);
        $items = $this->repo->list(['status' => $status], $limit);
        $out = [];
        foreach ($items as $c) {
            $out[] = $this->contextPayload($c);
        }
        $active = $this->repo->getActiveContext();
        return [
            'contexts' => $out,
            'active_context' => $active ? $this->contextPayload($active) : null,
        ];
    }

    /** GET /api/strategic-contexts/active */
    public function active(Request $_req): array
    {
        $c = $this->repo->getActiveContext();
        return ['active_context' => $c ? $this->contextPayload($c) : null];
    }

    /** POST /api/strategic-contexts/{id}/activate */
    public function activate(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $ok = $this->repo->setActiveContext($id);
        if (!$ok) {
            return Response::error('Context not found or cannot be activated', 404);
        }
        $c = $this->repo->find($id);
        return ['active_context' => $c ? $this->contextPayload($c) : null];
    }

    /** GET /api/strategic-contexts/{context_id}/timeline */
    public function timeline(Request $req): array
    {
        $raw = $req->param('context_id');
        if ($raw === null || trim((string)$raw) === '') {
            $raw = $req->param('id');
        }
        $id = trim((string)$raw);
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }
        $includeLegacy = trim((string)($req->query('include_legacy', ''))) === '1';
        $svc = new WorkspaceTimelineService();
        return $svc->build($id, $includeLegacy);
    }

    /**
     * GET /api/strategic-contexts/{id}/memory-overview
     * Agrégation read-only pour l’UI Strategic Context (aucune mutation, aucune table dédiée).
     *
     * @return array<string,mixed>
     */
    public function memoryOverview(Request $req): array
    {
        $raw = $req->param('context_id');
        if ($raw === null || trim((string)$raw) === '') {
            $raw = $req->param('id');
        }
        $id = trim((string)$raw);
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }

        $linkedMemIds = [];
        foreach ($this->repo->linkedMemoryIds($id) as $x) {
            $n = strtolower(trim((string)$x));
            if ($n !== '') {
                $linkedMemIds[$n] = true;
            }
        }
        $linkedMemIds = array_keys($linkedMemIds);
        $linkedSessIds = array_values(array_unique(array_filter(array_map('strval', $this->repo->linkedSessionIds($id)))));

        $sessionRows = $this->sessions->findAll($id);
        $fromSessions = [];
        foreach ($sessionRows as $sr) {
            $sid = strtolower(trim((string)($sr['id'] ?? '')));
            if ($sid !== '') {
                $fromSessions[$sid] = true;
            }
        }
        foreach ($linkedSessIds as $sid) {
            $fromSessions[strtolower(trim($sid))] = true;
        }
        $sessionsCount = count($fromSessions);

        $catalog = $this->workspaceAgents->buildForContext($id);
        $participantsCount = 0;
        $agentMemoriesCount = 0;
        foreach ($catalog as $row) {
            if (!empty($row['participated'])) {
                ++$participantsCount;
            }
            if (!empty($row['memory_md_exists'])) {
                ++$agentMemoriesCount;
            }
        }

        $exportRows = $this->memories->findLinkedMemoriesForStrategicContextMarkdownExport($id, 200);
        $lastDecisionAt = '';
        foreach ($exportRows as $m) {
            $ca = (string)($m['created_at'] ?? '');
            if ($ca !== '' && ($lastDecisionAt === '' || strcmp($ca, $lastDecisionAt) > 0)) {
                $lastDecisionAt = $ca;
            }
        }
        if ($lastDecisionAt === '') {
            $lastDecisionAt = null;
        }

        $decisionsPreview = [];
        foreach (array_slice($exportRows, 0, 5) as $m) {
            $mid = (string)($m['memory_id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $nextSteps = $m['recommended_next_steps'] ?? [];
            $nextStr = '';
            if (is_array($nextSteps)) {
                $flat = [];
                foreach ($nextSteps as $n) {
                    if (is_string($n) && trim($n) !== '') {
                        $flat[] = trim($n);
                    }
                }
                $nextStr = implode('; ', array_slice($flat, 0, 3));
            } elseif (is_string($nextSteps)) {
                $nextStr = trim($nextSteps);
            }
            $decisionsPreview[] = [
                'memory_id' => $mid,
                'session_id' => (string)($m['session_id'] ?? ''),
                'playbook_id' => (string)($m['playbook_id'] ?? ''),
                'decision_status' => (string)($m['decision_status'] ?? ''),
                'memory_state' => (string)($m['memory_state'] ?? 'active'),
                'summary' => (string)($m['decision_summary'] ?? ''),
                'next_action' => $nextStr,
                'created_at' => (string)($m['created_at'] ?? ''),
                'user_confirmed' => (int)($m['user_confirmed'] ?? 0) === 1,
            ];
        }

        $snapshotGen = new MemorySnapshotGenerator();
        // Aligner sur le rendu memory.md « canon » (même défauts que GET memory.md sans query) :
        // pas de stale ni d’archivées, pour ne pas traiter comme « manquantes » des mémoires volontairement exclues.
        $mdOptsForDiag = [
            'include_stale' => false,
            'include_archived' => false,
            'include_expert_metadata' => false,
            'max_memories' => 200,
        ];
        $ctxMd = $snapshotGen->generateContextMarkdown($id, $mdOptsForDiag);

        $warnings = [];
        if ($participantsCount > 0 && $linkedMemIds === []) {
            $warnings[] = 'Des agents ont participé mais aucune Decision Memory n’est liée à ce contexte.';
        }
        if ($sessionsCount > 0 && $linkedMemIds === []) {
            $warnings[] = 'Des analyses (sessions) sont associées mais aucune mémoire de décision n’est liée.';
        }

        $diagnostics = [];
        $linkedSet = array_fill_keys($linkedMemIds, true);

        $exportIdSet = [];
        foreach ($exportRows as $er) {
            $eid = strtolower(trim((string)($er['memory_id'] ?? '')));
            if ($eid !== '') {
                $exportIdSet[$eid] = true;
            }
        }

        $midsEligibleForContextSection = [];
        foreach ($exportRows as $er) {
            $eid = strtolower(trim((string)($er['memory_id'] ?? '')));
            if ($eid === '') {
                continue;
            }
            if ($snapshotGen->decisionsRememberedEmitsInContextMarkdown($er, $mdOptsForDiag)['emits']) {
                $midsEligibleForContextSection[$eid] = true;
            }
        }

        foreach ($linkedMemIds as $midRaw) {
            $mid = strtolower(trim((string)$midRaw));
            if ($mid === '') {
                continue;
            }
            $row = $this->memories->findById($mid);
            if ($row === null) {
                $diagnostics[] = [
                    'severity' => 'info',
                    'code' => 'LINKED_MEMORY_ROW_MISSING',
                    'message' => 'Lien strategic_context_memories vers une Decision Memory absente de la base.',
                    'memory_id' => $mid,
                ];
                continue;
            }
            $emit = $snapshotGen->decisionsRememberedEmitsInContextMarkdown($row, $mdOptsForDiag);
            if (!$emit['emits']) {
                $diagnostics[] = [
                    'severity' => 'info',
                    'code' => 'DM_EXCLUDED_FROM_CONTEXT_MD',
                    'reason' => (string)($emit['exclusion'] ?? 'filtered'),
                    'message' => 'Decision Memory volontairement exclue du rendu « Decisions Remembered » (même règle que l’export markdown).',
                    'memory_id' => $mid,
                ];
                continue;
            }
            if (!isset($exportIdSet[$mid])) {
                $diagnostics[] = [
                    'severity' => 'info',
                    'code' => 'DM_EXCLUDED_FROM_CONTEXT_MD',
                    'reason' => 'not_exportable',
                    'message' => 'Decision Memory hors fenêtre d’export (limite max_memories) : absence attendue du markdown.',
                    'memory_id' => $mid,
                ];
                continue;
            }
            if (!preg_match('/###\s+' . preg_quote($mid, '/') . '\b/mi', $ctxMd)) {
                $diagnostics[] = [
                    'severity' => 'warning',
                    'code' => 'DM_NOT_IN_CONTEXT_MD',
                    'message' => 'Decision Memory exportable absente du bloc « Decisions Remembered » du memory.md contexte.',
                    'memory_id' => $mid,
                ];
            }
        }

        $agentMemSvc = new AgentContextMemoryService();
        foreach ($catalog as $row) {
            if (empty($row['participated'])) {
                continue;
            }
            $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
            if ($aid === '' || in_array($aid, ['synthesizer', 'devil_advocate'], true)) {
                continue;
            }
            if (empty($row['memory_md_exists'])) {
                $diagnostics[] = [
                    'severity' => 'warning',
                    'code' => 'PARTICIPANT_AGENT_MEMORY_MISSING',
                    'message' => 'Agent participant sans fichier memory.md pour ce contexte.',
                    'agent_id' => $aid,
                    'recommended_action' => 'contexts.memoryDiagnostics.recommended.syncAgentMemories',
                ];
            }
        }

        if ($linkedMemIds !== []) {
            foreach ($catalog as $row) {
                if (empty($row['participated']) || empty($row['memory_md_exists'])) {
                    continue;
                }
                $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
                if ($aid === '' || in_array($aid, ['synthesizer', 'devil_advocate'], true)) {
                    continue;
                }
                $ex = $agentMemSvc->readIfExistsNoSideEffects($id, $aid);
                $content = ($ex['exists'] ?? false) ? (string)($ex['content'] ?? '') : '';
                if ($content === '') {
                    continue;
                }
                foreach (array_keys($midsEligibleForContextSection) as $mid) {
                    $mid = trim($mid);
                    if ($mid === '') {
                        continue;
                    }
                    $t1 = 'da-decision-memory-sync:' . $mid;
                    $t2 = 'da-propagated-decision:' . $mid;
                    if (!str_contains($content, $t1) && !str_contains($content, $t2)) {
                        $diagnostics[] = [
                            'severity' => 'info',
                            'code' => 'DM_NOT_SYNCED_TO_AGENT_MEMORY',
                            'message' => 'Decision Memory liée sans marqueur de sync dans le memory.md agent (reconstruction possible).',
                            'memory_id' => $mid,
                            'agent_id' => $aid,
                            'recommended_action' => 'contexts.memoryDiagnostics.recommended.syncAgentMemories',
                        ];
                    }
                    $c1 = substr_count($content, $t1);
                    $c2 = substr_count($content, $t2);
                    if ($c1 + $c2 > 1) {
                        $diagnostics[] = [
                            'severity' => 'warning',
                            'code' => 'DUPLICATE_SYNC_MARKERS',
                            'message' => 'Plusieurs marqueurs de sync pour la même Decision Memory dans le memory.md agent.',
                            'memory_id' => $mid,
                            'agent_id' => $aid,
                        ];
                    }
                }
                if (preg_match_all('/da-propagated-decision:([0-9a-f-]{36})/i', $content, $mm)) {
                    foreach ($mm[1] as $found) {
                        $fid = strtolower(trim($found));
                        if ($fid !== '' && !isset($linkedSet[$fid])) {
                            $diagnostics[] = [
                                'severity' => 'warning',
                                'code' => 'UNKNOWN_MEMORY_ID_IN_AGENT_MD',
                                'message' => 'Marqueur da-propagated-decision pour un memory_id non lié au contexte dans le fichier agent.',
                                'memory_id' => $fid,
                                'agent_id' => $aid,
                            ];
                        }
                    }
                }
            }
        }

        $expertAutomationNotes = [
            'Beliefs créées automatiquement par erreur : vérifier manuellement l’historique API / audit (non inféré ici).',
            'Narrative recomputée automatiquement par erreur : vérifier les appels POST /narrative/recompute (non inféré ici).',
            'Memory Compiler déclenché automatiquement par erreur : vérifier POST /memory-compilations/compile (non inféré ici).',
            'Snapshots mutés : comparer avec l’historique snapshots (non inféré ici).',
        ];

        $diagMaxSeverity = 'ok';
        foreach ($diagnostics as $d) {
            $sev = (string)($d['severity'] ?? 'info');
            if ($sev === 'error') {
                $diagMaxSeverity = 'error';
            } elseif ($sev === 'warning' && $diagMaxSeverity !== 'error') {
                $diagMaxSeverity = 'warning';
            } elseif ($sev === 'info' && $diagMaxSeverity === 'ok') {
                $diagMaxSeverity = 'info';
            }
        }

        $level = 'ok';
        if ($warnings !== []) {
            $level = ($participantsCount > 0 && $linkedMemIds === []) ? 'incomplete' : 'warning';
        }
        if ($diagMaxSeverity === 'error') {
            $level = 'warning';
        }

        return [
            'ok' => true,
            'overview' => [
                'context_id' => $id,
                'sessions_count' => $sessionsCount,
                'decision_memories_count' => count($linkedMemIds),
                'agent_memories_count' => $agentMemoriesCount,
                'participants_count' => $participantsCount,
                'last_decision_at' => $lastDecisionAt,
                'memory_health' => [
                    'level' => $level,
                    'warnings' => $warnings,
                ],
            ],
            'decisions_preview' => $decisionsPreview,
            'diagnostics' => $diagnostics,
            'expert_automation_notes' => $expertAutomationNotes,
        ];
    }

    /**
     * POST /api/strategic-contexts/{id}/agent-memories/sync
     * Reconstruction idempotente des memory.md agents (sessions completed + Decision Memories liées).
     *
     * @return array<string,mixed>
     */
    public function syncAgentMemories(Request $req): array
    {
        $raw = $req->param('context_id');
        if ($raw === null || trim((string)$raw) === '') {
            $raw = $req->param('id');
        }
        $id = trim((string)$raw);
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $bool = static function (array $body, string $key, bool $default): bool {
            if (!array_key_exists($key, $body)) {
                return $default;
            }
            $v = $body[$key];
            if (is_bool($v)) {
                return $v;
            }
            if ($v === 1 || $v === '1') {
                return true;
            }
            if ($v === 0 || $v === '0') {
                return false;
            }
            $s = strtolower(trim((string)$v));

            return $s === 'true' || $s === 'yes';
        };
        $options = [
            'dry_run' => $bool($body, 'dry_run', false),
            'include_participation' => $bool($body, 'include_participation', true),
            'include_decision_memories' => $bool($body, 'include_decision_memories', true),
            'include_synthesizer' => $bool($body, 'include_synthesizer', false),
            'include_devil_advocate' => $bool($body, 'include_devil_advocate', false),
        ];
        $out = (new AgentContextMemorySyncService())->syncContextAgentMemories($id, $options);
        if (($out['ok'] ?? false) !== true) {
            $err = (string)($out['error'] ?? 'sync_failed');
            $code = $err === 'context_not_found' ? 404 : 400;

            return Response::error($err, $code);
        }

        return $out;
    }

    /** GET /api/strategic-contexts/{id}/narrative */
    public function narrativeShow(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }
        $svc = new StrategicNarrativeService();

        return $svc->getApiResponse($id);
    }

    /** POST /api/strategic-contexts/{id}/narrative/recompute */
    public function narrativeRecompute(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }
        $svc = new StrategicNarrativeService();
        $out = $svc->recomputeAndPersist($id);
        if (($out['ok'] ?? false) === true) {
            try {
                (new MemoryGovernanceService())->logEvent($id, 'narrative', 'strategic_narrative', 'promotion', [
                    'governance_status' => 'stable',
                    'provenance_level' => 'derived',
                    'trust_level' => 0.72,
                    'metadata' => [
                        'warnings_count' => is_array($out['warnings'] ?? null) ? count($out['warnings']) : 0,
                        'key_assumptions_count' => is_array($out['key_assumptions'] ?? null) ? count($out['key_assumptions']) : 0,
                    ],
                ]);
            } catch (\Throwable) {
            }
        }
        return $out;
    }

    /** POST /api/strategic-contexts/compare — lecture seule croisée (aucun changement de contexte actif). */
    public function compare(Request $req): array
    {
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $left = strtolower(trim((string)($body['left_context_id'] ?? '')));
        $right = strtolower(trim((string)($body['right_context_id'] ?? '')));
        if (!$this->isValidContextId($left) || !$this->isValidContextId($right)) {
            return Response::error('Invalid context id', 400);
        }

        $incSess = $this->boolCompareFlag($body['include_sessions'] ?? true);
        $incDec = $this->boolCompareFlag($body['include_decisions'] ?? true);
        $incMem = $this->boolCompareFlag($body['include_agent_memories'] ?? false);
        $incSoc = $this->boolCompareFlag($body['include_social_dynamics'] ?? true);
        $incTl = $this->boolCompareFlag($body['include_timeline'] ?? true);

        $svc = new StrategicContextComparisonService();
        $out = $svc->compare($left, $right, $incSess, $incDec, $incMem, $incSoc, $incTl);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 500));
        }
        unset($out['ok']);
        return $out;
    }

    /** GET /api/strategic-contexts/{id}/memory-governance */
    public function memoryGovernance(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->repo->find($id)) {
            return Response::error('Context not found', 404);
        }
        $limit = max(20, min(400, (int)$req->query('limit', 180)));
        $svc = new MemoryGovernanceService();
        return $svc->getContextGovernance($id, $limit);
    }

    /** @param mixed $v */
    private function boolCompareFlag($v): bool
    {
        if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
            return false;
        }
        return true;
    }

    /** GET /api/strategic-contexts/{id} */
    public function show(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $c = $this->repo->find($id);
        if (!$c) return Response::error('Context not found', 404);
        return [
            'context' => $this->contextPayload($c),
        ];
    }

    /** POST /api/strategic-contexts */
    public function create(Request $req): array
    {
        $payload = $req->body();
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') return Response::error('Missing title', 400);
        $desc = (string)($payload['description'] ?? '');
        $status = (string)($payload['status'] ?? 'active');
        $c = $this->repo->create($title, $desc, $status);
        return ['context' => $this->contextPayload($c)];
    }

    /** PUT /api/strategic-contexts/{id} */
    public function update(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $payload = $req->body();
        $c = $this->repo->update($id, is_array($payload) ? $payload : []);
        if (!$c) return Response::error('Context not found', 404);
        return ['context' => $this->contextPayload($c)];
    }

    /** DELETE /api/strategic-contexts/{id} */
    public function destroy(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $ok = $this->repo->delete($id);
        if (!$ok) return Response::error('Context not found', 404);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/link-memory */
    public function linkMemory(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $payload = $req->body();
        $mid = trim((string)($payload['memory_id'] ?? ''));
        if ($mid === '' || !$this->memories->findById($mid)) {
            return Response::error('Memory not found', 404);
        }
        $ok = $this->repo->linkMemory($id, $mid);
        if (!$ok) return Response::error('Context not found', 404);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/unlink-memory */
    public function unlinkMemory(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $payload = $req->body();
        $mid = trim((string)($payload['memory_id'] ?? ''));
        if ($mid === '') return Response::error('Missing memory_id', 400);
        $this->repo->unlinkMemory($id, $mid);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/link-session */
    public function linkSession(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $payload = $req->body();
        $sid = trim((string)($payload['session_id'] ?? ''));
        if ($sid === '' || !$this->sessions->findById($sid)) {
            return Response::error('Session not found', 404);
        }
        $ok = $this->repo->linkSession($id, $sid);
        if (!$ok) {
            return Response::error('Context not found', 404);
        }
        $session = $this->sessions->findById($sid);
        if ($session !== null && strtolower(trim((string)($session['status'] ?? ''))) === 'completed') {
            $ctxRow = $this->repo->find($id);
            $label = is_array($ctxRow) ? trim((string)($ctxRow['title'] ?? '')) : '';
            (new AgentContextMemoryService())->syncParticipantMemoryOnSessionCompleted(
                $sid,
                $session,
                $id,
                $label !== '' ? $label : null
            );
        }
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/unlink-session */
    public function unlinkSession(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidContextId($id)) {
            return Response::error('Invalid context id', 400);
        }
        $payload = $req->body();
        $sid = trim((string)($payload['session_id'] ?? ''));
        if ($sid === '') return Response::error('Missing session_id', 400);
        $this->repo->unlinkSession($id, $sid);
        return ['success' => true];
    }
}


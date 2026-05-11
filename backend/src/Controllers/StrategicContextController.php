<?php
declare(strict_types=1);

namespace Controllers;

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

    public function __construct()
    {
        $this->repo = new StrategicContextRepository();
        $this->memories = new DecisionMemoryRepository();
        $this->sessions = new SessionRepository();
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
        if (!$ok) return Response::error('Context not found', 404);
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


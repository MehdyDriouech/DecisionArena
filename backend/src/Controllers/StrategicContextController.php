<?php
declare(strict_types=1);

namespace Controllers;

use Http\Request;
use Http\Response;
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

    /** GET /api/strategic-contexts */
    public function index(Request $req): array
    {
        $status = (string)($req->query('status') ?? '');
        $limit = (int)($req->query('limit') ?? 100);
        $items = $this->repo->list(['status' => $status], $limit);
        $out = [];
        foreach ($items as $c) {
            $cid = (string)($c['context_id'] ?? '');
            $out[] = [
                ...$c,
                'linked_memory_ids' => $this->repo->linkedMemoryIds($cid),
                'linked_session_ids' => $this->repo->linkedSessionIds($cid),
                'current_state' => $this->repo->currentState($cid),
            ];
        }
        return ['contexts' => $out];
    }

    /** GET /api/strategic-contexts/{id} */
    public function show(Request $req): array
    {
        $id = (string)$req->param('id');
        $c = $this->repo->find($id);
        if (!$c) return Response::error('Context not found', 404);
        return [
            'context' => [
                ...$c,
                'linked_memory_ids' => $this->repo->linkedMemoryIds($id),
                'linked_session_ids' => $this->repo->linkedSessionIds($id),
                'current_state' => $this->repo->currentState($id),
            ],
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
        return ['context' => [
            ...$c,
            'linked_memory_ids' => [],
            'linked_session_ids' => [],
            'current_state' => $this->repo->currentState((string)$c['context_id']),
        ]];
    }

    /** PUT /api/strategic-contexts/{id} */
    public function update(Request $req): array
    {
        $id = (string)$req->param('id');
        $payload = $req->body();
        $c = $this->repo->update($id, is_array($payload) ? $payload : []);
        if (!$c) return Response::error('Context not found', 404);
        return ['context' => [
            ...$c,
            'linked_memory_ids' => $this->repo->linkedMemoryIds($id),
            'linked_session_ids' => $this->repo->linkedSessionIds($id),
            'current_state' => $this->repo->currentState($id),
        ]];
    }

    /** DELETE /api/strategic-contexts/{id} */
    public function destroy(Request $req): array
    {
        $id = (string)$req->param('id');
        $ok = $this->repo->delete($id);
        if (!$ok) return Response::error('Context not found', 404);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/link-memory */
    public function linkMemory(Request $req): array
    {
        $id = (string)$req->param('id');
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
        $id = (string)$req->param('id');
        $payload = $req->body();
        $mid = trim((string)($payload['memory_id'] ?? ''));
        if ($mid === '') return Response::error('Missing memory_id', 400);
        $this->repo->unlinkMemory($id, $mid);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{id}/link-session */
    public function linkSession(Request $req): array
    {
        $id = (string)$req->param('id');
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
        $id = (string)$req->param('id');
        $payload = $req->body();
        $sid = trim((string)($payload['session_id'] ?? ''));
        if ($sid === '') return Response::error('Missing session_id', 400);
        $this->repo->unlinkSession($id, $sid);
        return ['success' => true];
    }
}


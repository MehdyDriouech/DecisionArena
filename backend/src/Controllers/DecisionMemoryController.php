<?php
declare(strict_types=1);

namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\DecisionMemoryEmbeddingsRepository;
use Infrastructure\Persistence\SessionRepository;
use Domain\DecisionMemory\DeterministicFakeEmbeddingProvider;

final class DecisionMemoryController
{
    private DecisionMemoryRepository $repo;
    private SessionRepository $sessions;
    private DecisionMemoryEmbeddingsRepository $embeddings;

    public function __construct()
    {
        $this->repo = new DecisionMemoryRepository();
        $this->sessions = new SessionRepository();
        $this->embeddings = new DecisionMemoryEmbeddingsRepository();
    }

    /** GET /api/decision-memories */
    public function index(Request $req): array
    {
        $limit  = (int)($req->query('limit') ?? 200);
        $offset = (int)($req->query('offset') ?? 0);
        $filters = [
            'playbook_id' => $req->query('playbook_id'),
            'decision_status' => $req->query('decision_status'),
            'confidence' => $req->query('confidence'),
            'from' => $req->query('from'),
            'to' => $req->query('to'),
            'link_type' => $req->query('link_type'),
            'q' => $req->query('q'),
            'include_archived' => $req->query('include_archived'),
        ];
        return [
            'memories' => $this->repo->findFiltered($filters, $limit, $offset),
            'links' => $this->repo->findAllLinks(1000),
        ];
    }

    /** GET /api/decision-memories/search */
    public function search(Request $req): array
    {
        $params = [
            'q' => (string)($req->query('q') ?? ''),
            'context_id' => (string)($req->query('context_id') ?? ''),
            'room_id' => (string)($req->query('room_id') ?? ''),
            'playbook_id' => (string)($req->query('playbook_id') ?? ''),
            'decision_status' => (string)($req->query('decision_status') ?? ''),
            'confidence' => (string)($req->query('confidence') ?? ''),
            'memory_state' => (string)($req->query('memory_state') ?? ''),
            'include_stale' => (string)($req->query('include_stale') ?? '0'),
            'expert_override' => (string)($req->query('expert_override') ?? '0'),
            'limit' => (string)($req->query('limit') ?? '50'),
            'offset' => (string)($req->query('offset') ?? '0'),
        ];
        // NOTE: search is secondary to navigation; no automatic reuse/injection here.
        return $this->repo->searchScoped($params);
    }

    /** GET /api/decision-memories/similar */
    public function similar(Request $req): array
    {
        // Feature-flagged; deterministic fake provider only (no live providers in this lot).
        $provider = new DeterministicFakeEmbeddingProvider();
        $params = [
            'memory_id' => (string)($req->query('memory_id') ?? ''),
            'q' => (string)($req->query('q') ?? ''),
            'context_id' => (string)($req->query('context_id') ?? ''),
            'room_id' => (string)($req->query('room_id') ?? ''),
            'playbook_id' => (string)($req->query('playbook_id') ?? ''),
            'limit' => (string)($req->query('limit') ?? '12'),
            'include_stale' => (string)($req->query('include_stale') ?? '0'),
            'expert_override' => (string)($req->query('expert_override') ?? '0'),
        ];
        return $this->embeddings->findSimilar($provider, $params);
    }

    /** GET /api/decision-memories/{id} */
    public function show(Request $req): array
    {
        $id = (string)$req->param('id');
        $mem = $this->repo->findById($id);
        if (!$mem) {
            return Response::error('Memory not found', 404);
        }
        $links = $this->repo->linksFor($id);
        return ['memory' => $mem, 'links' => $links];
    }

    /** GET /api/sessions/{id}/decision-memory */
    public function bySession(Request $req): array
    {
        $sessionId = (string)$req->param('id');
        $session = $this->sessions->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $mem = $this->repo->findBySession($sessionId);
        return ['memory' => $mem];
    }

    /**
     * POST /api/sessions/{id}/decision-memory/confirm
     * Explicit user confirmation gate (required when persistence_safety requires it).
     */
    public function confirm(Request $req): array
    {
        $sessionId = (string)$req->param('id');
        $session = $this->sessions->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $persisted = null;
        if (!empty($session['result'])) {
            $persisted = json_decode((string)$session['result'], true);
        }
        if (!is_array($persisted)) {
            return Response::error('Session has no persisted run result', 400);
        }

        $mem = $this->repo->persistAfterConfirmation($persisted, $sessionId);
        if (!$mem) {
            return Response::error('Memory could not be persisted (unsafe outcome)', 400);
        }
        return ['memory' => $mem];
    }

    /** POST /api/decision-memories/{id}/link */
    public function link(Request $req): array
    {
        $fromId = (string)$req->param('id');
        $payload = $req->body();
        $toId = (string)($payload['to_memory_id'] ?? '');
        $linkType = (string)($payload['link_type'] ?? 'related');
        if (!$this->repo->findById($fromId) || !$this->repo->findById($toId)) {
            return Response::error('Memory not found', 404);
        }
        $ok = $this->repo->createLink($fromId, $toId, $linkType);
        if (!$ok) {
            return Response::error('Invalid link payload', 400);
        }
        return ['success' => true];
    }

    /** GET /api/decision-memories/{id}/related */
    public function related(Request $req): array
    {
        $id = (string)$req->param('id');
        $mem = $this->repo->findById($id);
        if (!$mem) {
            return Response::error('Memory not found', 404);
        }
        return [
            'memory_id' => $id,
            'related' => $this->repo->relatedMemories($id, 80),
            'links' => $this->repo->linksFor($id),
        ];
    }

    /**
     * GET /api/decision-memories/compact?ids=...
     * Returns server-truth compact contexts + blocked reasons.
     */
    public function compact(Request $req): array
    {
        $raw = (string)($req->query('ids') ?? '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $allowStale = (string)($req->query('allow_stale') ?? '') === '1' || (string)($req->query('allow_stale') ?? '') === 'true';
        $expertOverride = (string)($req->query('expert_override') ?? '') === '1' || (string)($req->query('expert_override') ?? '') === 'true';
        $out = $this->repo->compactReusableForIdsWithOptions($ids, [
            'allow_stale' => $allowStale,
            'expert_override' => $expertOverride,
        ]);
        return [
            'allowed' => $out['allowed'],
            'blocked' => $out['blocked'],
        ];
    }

    /** GET /api/decision-memories/{id}/audit */
    public function audit(Request $req): array
    {
        $id = (string)$req->param('id');
        if (!$this->repo->findById($id)) {
            return Response::error('Memory not found', 404);
        }
        $limit = (int)($req->query('limit') ?? 200);
        return ['memory_id' => $id, 'events' => $this->repo->auditFor($id, $limit)];
    }

    /** POST /api/decision-memories/{id}/lifecycle */
    public function lifecycle(Request $req): array
    {
        $id = (string)$req->param('id');
        $payload = $req->body();
        $action = (string)($payload['action'] ?? '');
        $res = $this->repo->applyLifecycleAction($id, $action, is_array($payload) ? $payload : []);
        if (($res['ok'] ?? false) !== true) {
            return Response::error('Invalid lifecycle action', 400);
        }
        return ['success' => true, 'memory' => $res['memory'] ?? null];
    }

    /** DELETE /api/decision-memories/{id} */
    public function destroy(Request $req): array
    {
        $id = (string)$req->param('id');
        $ok = $this->repo->deleteHard($id);
        if (!$ok) {
            return Response::error('Memory not found', 404);
        }
        return ['success' => true];
    }
}


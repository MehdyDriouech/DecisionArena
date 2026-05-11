<?php
declare(strict_types=1);

namespace Controllers;

use Domain\StrategicContext\BeliefEngineService;
use Domain\StrategicContext\MemoryGovernanceService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\StrategicContextRepository;

final class StrategicContextBeliefsController
{
    private BeliefEngineService $engine;
    private StrategicContextRepository $contexts;
    private MemoryGovernanceService $governance;

    public function __construct()
    {
        $this->engine = new BeliefEngineService();
        $this->contexts = new StrategicContextRepository();
        $this->governance = new MemoryGovernanceService();
    }

    private function isValidContextId(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    private function isValidBeliefId(string $id): bool
    {
        return $this->isValidContextId($id);
    }

    private function isValidAgentId(string $id): bool
    {
        $id = strtolower(trim($id));

        return $id !== '' && (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id);
    }

    /** GET /api/strategic-contexts/{contextId}/beliefs */
    public function index(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        if (!$this->isValidContextId($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $q = [
            'agent_id' => $req->query('agent_id'),
            'belief_type' => $req->query('belief_type'),
            'status' => $req->query('status'),
            'disputed_only' => trim((string)$req->query('disputed_only', '')) === '1',
            'limit' => (int)$req->query('limit', 400),
        ];
        $items = $this->engine->listBeliefsForContext($cid, $q);

        return ['beliefs' => $items];
    }

    /** GET /api/strategic-contexts/{contextId}/agents/{agentId}/beliefs */
    public function indexByAgent(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        $aid = strtolower(trim((string)$req->param('agentId')));
        if (!$this->isValidContextId($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $items = $this->engine->listBeliefsForAgentInContext($cid, $aid);

        return ['beliefs' => $items, 'agent_id' => $aid];
    }

    /** POST /api/strategic-contexts/{contextId}/beliefs */
    public function store(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        if (!$this->isValidContextId($cid)) {
            return Response::error('Invalid context id', 400);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $out = $this->engine->createBelief($cid, $body);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 400));
        }
        $belief = is_array($out['belief'] ?? null) ? $out['belief'] : [];
        $this->safeGovernanceLog($cid, (string)($belief['id'] ?? ''), 'creation', $belief, $body);

        return ['belief' => $belief];
    }

    /** PUT /api/strategic-contexts/{contextId}/beliefs/{beliefId} */
    public function update(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        $bid = trim((string)$req->param('beliefId'));
        if (!$this->isValidContextId($cid) || !$this->isValidBeliefId($bid)) {
            return Response::error('Invalid id', 400);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $out = $this->engine->updateBelief($cid, $bid, $body);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 400));
        }
        $belief = is_array($out['belief'] ?? null) ? $out['belief'] : [];
        $eventType = $this->eventTypeFromBeliefPatch($body, $belief);
        $this->safeGovernanceLog($cid, (string)($belief['id'] ?? $bid), $eventType, $belief, $body);

        return ['belief' => $belief];
    }

    /** POST /api/strategic-contexts/{contextId}/beliefs/{beliefId}/archive */
    public function archive(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        $bid = trim((string)$req->param('beliefId'));
        if (!$this->isValidContextId($cid) || !$this->isValidBeliefId($bid)) {
            return Response::error('Invalid id', 400);
        }
        $out = $this->engine->archiveBelief($cid, $bid);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 400));
        }
        $belief = is_array($out['belief'] ?? null) ? $out['belief'] : [];
        $this->safeGovernanceLog($cid, (string)($belief['id'] ?? $bid), 'archiving', $belief, []);

        return ['belief' => $belief];
    }

    /** POST /api/strategic-contexts/{contextId}/beliefs/{beliefId}/deprecate */
    public function deprecate(Request $req): array
    {
        $cid = trim((string)$req->param('contextId'));
        $bid = trim((string)$req->param('beliefId'));
        if (!$this->isValidContextId($cid) || !$this->isValidBeliefId($bid)) {
            return Response::error('Invalid id', 400);
        }
        $out = $this->engine->deprecateBelief($cid, $bid);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 400));
        }
        $belief = is_array($out['belief'] ?? null) ? $out['belief'] : [];
        $this->safeGovernanceLog($cid, (string)($belief['id'] ?? $bid), 'invalidation', $belief, []);

        return ['belief' => $belief];
    }

    /** GET /api/beliefs */
    public function indexGlobal(Request $req): array
    {
        $contextId = trim((string)$req->query('context_id', ''));
        if ($contextId === '' || !$this->isValidContextId($contextId)) {
            return Response::error('context_id query param required (strict scoped access)', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $q = [
            'context_id' => $contextId,
            'belief_type' => $req->query('belief_type'),
            'status' => $req->query('status'),
            'contestation_state' => $req->query('contestation_state'),
            'agent_id' => $req->query('agent_id'),
            'q' => $req->query('q'),
            'limit' => (int)$req->query('limit', 200),
            'offset' => (int)$req->query('offset', 0),
        ];

        return ['beliefs' => $this->engine->listBeliefsGlobal($q)];
    }

    /** GET /api/beliefs/{id} */
    public function showGlobal(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidBeliefId($id)) {
            return Response::error('Invalid belief id', 400);
        }
        $contextId = trim((string)$req->query('context_id', ''));
        if ($contextId !== '' && !$this->isValidContextId($contextId)) {
            return Response::error('Invalid context id', 400);
        }
        $out = $this->engine->getBeliefById($id, $contextId !== '' ? $contextId : null);
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 400));
        }

        return ['belief' => $out['belief']];
    }

    /** GET /api/beliefs/{id}/timeline */
    public function timelineGlobal(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidBeliefId($id)) {
            return Response::error('Invalid belief id', 400);
        }
        $contextId = trim((string)$req->query('context_id', ''));
        if ($contextId !== '' && !$this->isValidContextId($contextId)) {
            return Response::error('Invalid context id', 400);
        }
        $limit = (int)$req->query('limit', 400);
        $timeline = $this->engine->getBeliefTimeline($id, $contextId !== '' ? $contextId : null, $limit);

        return ['belief_id' => $id, 'timeline' => $timeline];
    }

    /** GET /api/beliefs/{id}/relations */
    public function relationsGlobal(Request $req): array
    {
        $id = trim((string)$req->param('id'));
        if (!$this->isValidBeliefId($id)) {
            return Response::error('Invalid belief id', 400);
        }
        $contextId = trim((string)$req->query('context_id', ''));
        if ($contextId !== '' && !$this->isValidContextId($contextId)) {
            return Response::error('Invalid context id', 400);
        }
        $relations = $this->engine->getBeliefRelations($id, $contextId !== '' ? $contextId : null);

        return ['belief_id' => $id, 'relations' => $relations];
    }

    /** GET /api/beliefs/runtime?context_id=... */
    public function runtimeProjection(Request $req): array
    {
        $contextId = trim((string)$req->query('context_id', ''));
        if ($contextId === '' || !$this->isValidContextId($contextId)) {
            return Response::error('context_id query param required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }

        return $this->engine->getBeliefsRuntimeProjection($contextId);
    }

    /** @param array<string,mixed> $belief @param array<string,mixed> $patch */
    private function safeGovernanceLog(string $contextId, string $beliefId, string $eventType, array $belief, array $patch): void
    {
        if ($beliefId === '') {
            return;
        }
        try {
            $this->governance->logEvent($contextId, 'belief', $beliefId, $eventType, [
                'governance_status' => $this->governance->governanceStatusFromBelief($belief),
                'provenance_level' => 'explicit',
                'trust_level' => isset($belief['confidence']) ? (float)$belief['confidence'] : 0.5,
                'actor_id' => (string)($patch['created_by'] ?? $belief['created_by'] ?? 'system'),
                'reason' => isset($patch['reason']) ? (string)$patch['reason'] : null,
                'metadata' => [
                    'belief_status' => (string)($belief['status'] ?? ''),
                    'contestation_state' => (string)($belief['contestation_state'] ?? ''),
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $patch @param array<string,mixed> $belief */
    private function eventTypeFromBeliefPatch(array $patch, array $belief): string
    {
        $status = strtolower(trim((string)($patch['status'] ?? $belief['status'] ?? '')));
        if (in_array($status, ['active', 'reinforced'], true)) {
            return 'promotion';
        }
        if (in_array($status, ['invalidated', 'deprecated'], true)) {
            return 'invalidation';
        }
        if (in_array($status, ['disputed'], true) || (string)($belief['contestation_state'] ?? '') === 'contested') {
            return 'contradiction';
        }
        if ($status === 'archived') {
            return 'archiving';
        }
        return 'status_change';
    }
}

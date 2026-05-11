<?php
declare(strict_types=1);

namespace Controllers;

use Domain\StrategicContext\ContextSnapshotService;
use Domain\StrategicContext\MemoryGovernanceService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\StrategicContextRepository;

final class StrategicContextSnapshotsController
{
    private ContextSnapshotService $snapshots;
    private StrategicContextRepository $contexts;
    private MemoryGovernanceService $governance;

    public function __construct()
    {
        $this->snapshots = new ContextSnapshotService();
        $this->contexts = new StrategicContextRepository();
        $this->governance = new MemoryGovernanceService();
    }

    private function isValidUuid(string $id): bool
    {
        $id = trim($id);

        return $id !== '' && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** GET /api/strategic-contexts/{id}/snapshots/longitudinal */
    public function longitudinal(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $limit = (int)$req->query('limit', 12);
        $view = $this->snapshots->buildLongitudinalView($cid, ['limit' => $limit]);

        return [
            'strategic_context_id' => $cid,
            'snapshots' => $view['snapshots'],
            'view_markdown' => $view['view_markdown'],
            'metadata' => $view['metadata'],
        ];
    }

    /** POST /api/strategic-contexts/{id}/snapshots/compare */
    public function compare(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $a = trim((string)($body['snapshot_a_id'] ?? $body['snapshot_id_a'] ?? ''));
        $b = trim((string)($body['snapshot_b_id'] ?? $body['snapshot_id_b'] ?? ''));
        if (!$this->isValidUuid($a) || !$this->isValidUuid($b)) {
            return Response::error('snapshot_a_id and snapshot_b_id required (UUID)', 400);
        }
        $res = $this->snapshots->compareSnapshots($cid, $a, $b);
        if (($res['ok'] ?? false) !== true) {
            return Response::error((string)($res['message'] ?? 'compare_failed'), (int)($res['code'] ?? 400));
        }

        return ['diff' => $res['diff']];
    }

    /** GET /api/strategic-contexts/{id}/snapshots */
    public function index(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $filters = [
            'snapshot_type' => trim((string)$req->query('snapshot_type', '')),
            'limit' => (int)$req->query('limit', 60),
        ];
        if ($filters['snapshot_type'] === '') {
            unset($filters['snapshot_type']);
        }

        return ['snapshots' => $this->snapshots->listSnapshots($cid, $filters)];
    }

    /** POST /api/strategic-contexts/{id}/snapshots */
    public function store(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $type = trim((string)($body['snapshot_type'] ?? 'manual'));
        $opts = [
            'title' => trim((string)($body['title'] ?? '')),
            'description' => array_key_exists('description', $body) ? trim((string)$body['description']) : '',
            'created_by' => trim((string)($body['created_by'] ?? '')),
        ];
        if ($opts['description'] === '') {
            unset($opts['description']);
        } else {
            $opts['description'] = (string)$opts['description'];
        }
        if ($opts['title'] === '') {
            unset($opts['title']);
        }
        if ($opts['created_by'] === '') {
            unset($opts['created_by']);
        }
        if (!empty($body['tags']) && is_array($body['tags'])) {
            $opts['tags'] = $body['tags'];
        }
        $res = $this->snapshots->createSnapshot($cid, $type, $opts);
        if (($res['ok'] ?? false) !== true) {
            return Response::error((string)($res['message'] ?? 'create_failed'), (int)($res['code'] ?? 400));
        }
        $snap = is_array($res['snapshot'] ?? null) ? $res['snapshot'] : [];
        try {
            $this->governance->logEvent($cid, 'snapshot', (string)($snap['id'] ?? ''), 'creation', [
                'governance_status' => 'archived',
                'provenance_level' => 'derived',
                'trust_level' => 0.85,
                'actor_id' => isset($opts['created_by']) ? (string)$opts['created_by'] : null,
                'metadata' => [
                    'snapshot_type' => (string)($snap['snapshot_type'] ?? $type),
                    'snapshot_hash' => (string)($snap['snapshot_hash'] ?? ''),
                ],
            ]);
        } catch (\Throwable) {
        }

        return ['snapshot' => $snap];
    }

    /** GET /api/strategic-contexts/{id}/snapshots/{snapshotId} */
    public function show(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        $sid = trim((string)$req->param('snapshotId'));
        if (!$this->isValidUuid($cid) || !$this->isValidUuid($sid)) {
            return Response::error('Invalid id', 400);
        }
        $one = $this->snapshots->getSnapshot($cid, $sid);
        if ($one === null) {
            return Response::error('Snapshot not found', 404);
        }

        return ['snapshot' => $one];
    }
}

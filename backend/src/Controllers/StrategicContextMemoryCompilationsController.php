<?php
declare(strict_types=1);

namespace Controllers;

use Domain\StrategicContext\MemoryCompilerService;
use Domain\StrategicContext\MemoryGovernanceService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\StrategicContextRepository;

final class StrategicContextMemoryCompilationsController
{
    private MemoryCompilerService $compiler;
    private StrategicContextRepository $contexts;
    private MemoryGovernanceService $governance;

    public function __construct()
    {
        $this->compiler = new MemoryCompilerService();
        $this->contexts = new StrategicContextRepository();
        $this->governance = new MemoryGovernanceService();
    }

    private function isValidContextId(string $id): bool
    {
        $id = trim($id);

        return $id !== '' && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    private function isValidCompilationId(string $id): bool
    {
        return $this->isValidContextId($id);
    }

    /** GET /api/strategic-contexts/{id}/memory-compilations */
    public function index(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidContextId($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $filters = [
            'compilation_type' => trim((string)$req->query('compilation_type', '')),
            'status' => trim((string)$req->query('status', '')),
            'limit' => (int)$req->query('limit', 80),
        ];
        if ($filters['compilation_type'] === '') {
            unset($filters['compilation_type']);
        }
        if ($filters['status'] === '') {
            unset($filters['status']);
        }

        return ['compilations' => $this->compiler->listCompilations($cid, $filters)];
    }

    /** POST /api/strategic-contexts/{id}/memory-compilations/compile */
    public function compile(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidContextId($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if ($this->contexts->find($cid) === null) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        if (!is_array($body)) {
            $body = [];
        }
        $type = strtolower(trim((string)($body['compilation_type'] ?? 'strategic')));
        $createdBy = trim((string)($body['created_by'] ?? ''));
        $createdBy = $createdBy !== '' ? $createdBy : null;
        $supersede = !array_key_exists('supersede_previous', $body) ? true : (bool)$body['supersede_previous'];

        $res = $this->compiler->compile($cid, $type, $createdBy, ['supersede_previous' => $supersede]);
        if (($res['ok'] ?? false) !== true) {
            return Response::error((string)($res['message'] ?? 'compile_failed'), (int)($res['code'] ?? 400));
        }
        $comp = is_array($res['compilation'] ?? null) ? $res['compilation'] : [];
        try {
            $this->governance->logEvent($cid, 'memory_compilation', (string)($comp['id'] ?? ''), 'promotion', [
                'governance_status' => 'stable',
                'provenance_level' => 'derived',
                'trust_level' => isset($comp['confidence']) ? (float)$comp['confidence'] : 0.5,
                'actor_id' => $createdBy,
                'metadata' => [
                    'compilation_type' => (string)($comp['compilation_type'] ?? $type),
                    'status' => (string)($comp['status'] ?? 'active'),
                ],
            ]);
        } catch (\Throwable) {
        }

        return ['compilation' => $comp];
    }

    /** GET /api/strategic-contexts/{id}/memory-compilations/{compilationId} */
    public function show(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        $pid = trim((string)$req->param('compilationId'));
        if (!$this->isValidContextId($cid) || !$this->isValidCompilationId($pid)) {
            return Response::error('Invalid id', 400);
        }
        $one = $this->compiler->getCompilation($cid, $pid);
        if ($one === null) {
            return Response::error('Compilation not found', 404);
        }

        return ['compilation' => $one];
    }

    /** POST /api/strategic-contexts/{id}/memory-compilations/{compilationId}/archive */
    public function archive(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        $pid = trim((string)$req->param('compilationId'));
        if (!$this->isValidContextId($cid) || !$this->isValidCompilationId($pid)) {
            return Response::error('Invalid id', 400);
        }
        $res = $this->compiler->archiveCompilation($cid, $pid);
        if (($res['ok'] ?? false) !== true) {
            return Response::error((string)($res['message'] ?? 'failed'), (int)($res['code'] ?? 400));
        }
        try {
            $this->governance->logEvent($cid, 'memory_compilation', $pid, 'archiving', [
                'governance_status' => 'archived',
                'provenance_level' => 'derived',
                'trust_level' => 0.5,
                'actor_id' => 'system',
            ]);
        } catch (\Throwable) {
        }

        return ['ok' => true];
    }

    /** POST /api/strategic-contexts/{id}/memory-compilations/{compilationId}/supersede */
    public function supersede(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        $pid = trim((string)$req->param('compilationId'));
        if (!$this->isValidContextId($cid) || !$this->isValidCompilationId($pid)) {
            return Response::error('Invalid id', 400);
        }
        $res = $this->compiler->supersedeCompilation($cid, $pid);
        if (($res['ok'] ?? false) !== true) {
            return Response::error((string)($res['message'] ?? 'failed'), (int)($res['code'] ?? 400));
        }
        try {
            $this->governance->logEvent($cid, 'memory_compilation', $pid, 'invalidation', [
                'governance_status' => 'deprecated',
                'provenance_level' => 'derived',
                'trust_level' => 0.45,
                'actor_id' => 'system',
                'reason' => 'memory_compilation_superseded_or_deprecated',
            ]);
        } catch (\Throwable) {
        }

        return ['ok' => true];
    }
}

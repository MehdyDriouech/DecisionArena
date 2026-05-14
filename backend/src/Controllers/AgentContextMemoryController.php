<?php
declare(strict_types=1);

namespace Controllers;

use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\MemoryGovernanceService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\StrategicContextRepository;

final class AgentContextMemoryController
{
    private AgentContextMemoryService $memory;
    private StrategicContextRepository $contextRepo;
    private MemoryGovernanceService $governance;

    public function __construct()
    {
        $this->memory = new AgentContextMemoryService();
        $this->contextRepo = new StrategicContextRepository();
        $this->governance = new MemoryGovernanceService();
    }

    /** GET /api/strategic-contexts/{context_id}/agents/{agent_id}/memory */
    public function show(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        if (!$this->memory->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->memory->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $this->memory->ensureFile($cid, $aid);
        $content = $this->memory->read($cid, $aid);
        $linked = $this->contextRepo->linkedMemoryIds($cid);
        $summary = $this->memory->summarizeMemoryMdForAgentContext($cid, $aid, $linked);

        return [
            'context_id' => $cid,
            'agent_id' => $aid,
            'content' => $content,
            'path' => $this->memory->relativePath($cid, $aid),
            'memory_md_state' => $summary['state'],
            'memory_md_flags' => [
                'template_only' => $summary['template_only'],
                'participation_sync' => $summary['participation_sync'],
                'decision_memory_sync' => $summary['decision_memory_sync'],
                'file_exists' => $summary['file_exists'],
            ],
        ];
    }

    /** PUT /api/strategic-contexts/{context_id}/agents/{agent_id}/memory */
    public function update(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        if (!$this->memory->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->memory->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        $content = isset($body['content']) ? (string)$body['content'] : '';
        if ($content === '') {
            return Response::error('Missing content', 400);
        }
        if (strlen($content) > 512000) {
            return Response::error('Content too large', 400);
        }
        $r = $this->memory->write($cid, $aid, $content);
        if (!$r['ok']) {
            return Response::error($r['message'] ?? 'Write failed', 500);
        }
        $this->logGovernance($cid, $aid, 'status_change', 'stable', [
            'action' => 'update',
            'content_length' => strlen($content),
        ]);
        return ['success' => true, 'path' => $this->memory->relativePath($cid, $aid)];
    }

    /** POST /api/strategic-contexts/{context_id}/agents/{agent_id}/memory/append */
    public function append(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        if (!$this->memory->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->memory->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $body = $req->body();
        $note = trim((string)($body['note'] ?? ''));
        if ($note === '') {
            return Response::error('Missing note', 400);
        }
        $section = strtolower(trim((string)($body['section'] ?? 'recent')));
        if (!in_array($section, ['recent', 'pending'], true)) {
            return Response::error('Invalid section (use recent or pending)', 400);
        }
        $sid = isset($body['session_id']) ? trim((string)$body['session_id']) : null;
        if ($sid !== null && $sid !== '' && strlen($sid) > 80) {
            return Response::error('Invalid session_id', 400);
        }
        $r = $this->memory->appendNote($cid, $aid, $note, $section, $sid ?: null);
        if (!$r['ok']) {
            return Response::error($r['message'] ?? 'Append failed', 400);
        }
        $this->logGovernance($cid, $aid, 'creation', 'pending', [
            'action' => 'append_note',
            'section' => $section,
        ]);
        return ['success' => true];
    }

    /** POST /api/strategic-contexts/{context_id}/agents/{agent_id}/memory/consolidate */
    public function consolidate(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        if (!$this->memory->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->memory->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $r = $this->memory->consolidate($cid, $aid);
        if (!$r['ok']) {
            return Response::error($r['message'] ?? 'Consolidate failed', 500);
        }
        $this->logGovernance($cid, $aid, 'promotion', 'stable', [
            'action' => 'consolidate',
            'lines_removed' => (int)($r['lines_removed'] ?? 0),
        ]);
        return [
            'success' => true,
            'lines_removed' => (int)($r['lines_removed'] ?? 0),
        ];
    }

    /** POST .../memory/recent-note */
    public function recentNote(Request $req): array
    {
        [$cid, $aid, $err] = $this->validateContextAgent($req);
        if ($err !== null) {
            return $err;
        }
        $body = $req->body();
        $note = trim((string)($body['note'] ?? ''));
        if ($note === '') {
            return Response::error('Missing note', 400);
        }
        $sid = isset($body['session_id']) ? trim((string)$body['session_id']) : null;
        if ($sid !== null && $sid !== '' && strlen($sid) > 80) {
            return Response::error('Invalid session_id', 400);
        }
        $r = $this->memory->appendRecentNote($cid, $aid, $note, $sid !== '' ? $sid : null);
        if (($r['ok'] ?? false) === true) {
            $this->logGovernance($cid, $aid, 'creation', 'pending', ['action' => 'recent_note']);
        }
        return $this->memoryMaintenancePayload($cid, $aid, $r);
    }

    /** POST .../memory/contradiction */
    public function contradiction(Request $req): array
    {
        [$cid, $aid, $err] = $this->validateContextAgent($req);
        if ($err !== null) {
            return $err;
        }
        $body = $req->body();
        $text = trim((string)($body['contradiction'] ?? ''));
        if ($text === '') {
            return Response::error('Missing contradiction', 400);
        }
        $src = isset($body['source']) ? trim((string)$body['source']) : null;
        if ($src !== null && $src !== '' && strlen($src) > 200) {
            return Response::error('Invalid source', 400);
        }
        $r = $this->memory->addContradiction($cid, $aid, $text, $src !== '' ? $src : null);
        if (($r['ok'] ?? false) === true) {
            $this->logGovernance($cid, $aid, 'contradiction', 'contested', ['action' => 'contradiction']);
        }
        return $this->memoryMaintenancePayload($cid, $aid, $r);
    }

    /** POST .../memory/deprecate */
    public function deprecate(Request $req): array
    {
        [$cid, $aid, $err] = $this->validateContextAgent($req);
        if ($err !== null) {
            return $err;
        }
        $body = $req->body();
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') {
            return Response::error('Missing text', 400);
        }
        $reason = isset($body['reason']) ? trim((string)$body['reason']) : null;
        if ($reason !== null && $reason !== '' && strlen($reason) > 400) {
            return Response::error('Invalid reason', 400);
        }
        $r = $this->memory->markDeprecated($cid, $aid, $text, $reason !== '' ? $reason : null);
        if (($r['ok'] ?? false) === true) {
            $this->logGovernance($cid, $aid, 'invalidation', 'deprecated', [
                'action' => 'deprecate',
                'reason' => $reason !== '' ? $reason : null,
            ]);
        }
        return $this->memoryMaintenancePayload($cid, $aid, $r);
    }

    /** POST .../memory/compact */
    public function compact(Request $req): array
    {
        [$cid, $aid, $err] = $this->validateContextAgent($req);
        if ($err !== null) {
            return $err;
        }
        $r = $this->memory->compactMemory($cid, $aid);
        if (($r['ok'] ?? false) === true) {
            $this->logGovernance($cid, $aid, 'compaction', 'stable', ['action' => 'compact']);
        }
        return $this->memoryMaintenancePayload($cid, $aid, $r);
    }

    /**
     * @return array{0:string,1:string,2:array|null} Tuple : context_id, agent_id, erreur HTTP (Response::error) ou null si OK.
     */
    private function validateContextAgent(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        if (!$this->memory->isValidContextUuid($cid)) {
            return [$cid, $aid, Response::error('Invalid context id', 400)];
        }
        if (!$this->memory->isValidAgentId($aid)) {
            return [$cid, $aid, Response::error('Invalid agent id', 400)];
        }
        if (!$this->contextRepo->find($cid)) {
            return [$cid, $aid, Response::error('Context not found', 404)];
        }
        return [$cid, $aid, null];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function memoryMaintenancePayload(string $cid, string $aid, array $r): array
    {
        if (!($r['ok'] ?? false)) {
            $msg = (string)($r['message'] ?? 'operation_failed');
            $code = ($msg === 'write_failed' || $msg === 'mkdir_failed' || $msg === 'storage_unavailable') ? 500 : 400;
            return Response::error($msg, $code);
        }
        return [
            'context_id' => $cid,
            'agent_id' => $aid,
            'changed' => (bool)($r['changed'] ?? true),
            'sections_touched' => array_values($r['sections_touched'] ?? []),
            'warnings' => array_values($r['warnings'] ?? []),
            'memory' => $this->memory->read($cid, $aid),
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function logGovernance(string $contextId, string $agentId, string $eventType, string $status, array $metadata): void
    {
        try {
            $this->governance->logEvent($contextId, 'agent_memory', 'agent:' . $agentId, $eventType, [
                'agent_id' => $agentId,
                'governance_status' => $status,
                'provenance_level' => 'explicit',
                'trust_level' => $status === 'stable' ? 0.6 : 0.45,
                'actor_id' => 'system',
                'metadata' => $metadata,
                'reason' => isset($metadata['reason']) && is_string($metadata['reason']) ? trim($metadata['reason']) : null,
            ]);
        } catch (\Throwable) {
        }
    }
}

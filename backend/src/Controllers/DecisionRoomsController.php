<?php
declare(strict_types=1);

namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\DecisionRoomRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * REST CRUD for palace-lite decision rooms (organizational chains under a strategic context).
 * Separate from DecisionRoomController, which runs multi-agent orchestration (/api/decision-room/run).
 */
final class DecisionRoomsController
{
    private DecisionRoomRepository $rooms;
    private StrategicContextRepository $contexts;
    private DecisionMemoryRepository $memories;
    private SessionRepository $sessions;

    public function __construct()
    {
        $this->rooms = new DecisionRoomRepository();
        $this->contexts = new StrategicContextRepository();
        $this->memories = new DecisionMemoryRepository();
        $this->sessions = new SessionRepository();
    }

    /** GET /api/strategic-contexts/{context_id}/rooms */
    public function indexByContext(Request $req): array
    {
        $cid = (string)$req->param('context_id');
        if (!$this->contexts->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $items = $this->rooms->listByContext($cid);
        $out = [];
        foreach ($items as $r) {
            $rid = (string)($r['room_id'] ?? '');
            $out[] = [
                ...$r,
                'linked_memory_ids' => $this->rooms->linkedMemoryIds($rid),
                'linked_session_ids' => $this->rooms->linkedSessionIds($rid),
                'current_state' => $this->rooms->currentState($rid),
            ];
        }
        return ['rooms' => $out];
    }

    /** POST /api/strategic-contexts/{context_id}/rooms */
    public function createInContext(Request $req): array
    {
        $cid = (string)$req->param('context_id');
        if (!$this->contexts->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $payload = $req->body();
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            return Response::error('Missing title', 400);
        }
        $desc = (string)($payload['description'] ?? '');
        $status = (string)($payload['status'] ?? 'active');
        $pb = array_key_exists('playbook_id', $payload)
            ? (is_string($payload['playbook_id']) ? trim($payload['playbook_id']) : null)
            : null;
        $playbookId = ($pb !== null && $pb !== '') ? $pb : null;

        $room = $this->rooms->create($cid, $title, $desc, $playbookId, $status);
        if (!$room) {
            return Response::error('Context not found', 404);
        }
        $rid = (string)$room['room_id'];
        $active = $this->contexts->getActiveContext();
        return [
            'room' => [
                ...$room,
                'linked_memory_ids' => [],
                'linked_session_ids' => [],
                'current_state' => $this->rooms->currentState($rid),
            ],
            'active_strategic_context_id' => $active['context_id'] ?? null,
        ];
    }

    /** GET /api/decision-rooms/{room_id} */
    public function show(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $room = $this->rooms->find($rid);
        if (!$room) {
            return Response::error('Room not found', 404);
        }
        return ['room' => [
            ...$room,
            'linked_memory_ids' => $this->rooms->linkedMemoryIds($rid),
            'linked_session_ids' => $this->rooms->linkedSessionIds($rid),
            'current_state' => $this->rooms->currentState($rid),
        ]];
    }

    /** PUT /api/decision-rooms/{room_id} */
    public function update(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $payload = $req->body();
        $room = $this->rooms->update($rid, is_array($payload) ? $payload : []);
        if (!$room) {
            return Response::error('Room not found', 404);
        }
        return ['room' => [
            ...$room,
            'linked_memory_ids' => $this->rooms->linkedMemoryIds($rid),
            'linked_session_ids' => $this->rooms->linkedSessionIds($rid),
            'current_state' => $this->rooms->currentState($rid),
        ]];
    }

    /** POST /api/decision-rooms/{room_id}/archive */
    public function archive(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $room = $this->rooms->archive($rid);
        if (!$room) {
            return Response::error('Room not found', 404);
        }
        return ['room' => [
            ...$room,
            'linked_memory_ids' => $this->rooms->linkedMemoryIds($rid),
            'linked_session_ids' => $this->rooms->linkedSessionIds($rid),
            'current_state' => $this->rooms->currentState($rid),
        ]];
    }

    /**
     * DELETE /api/decision-rooms/{room_id}
     * Removes room metadata and links only; gate destructive use in UI (Expert) as needed.
     */
    public function destroy(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $ok = $this->rooms->delete($rid);
        if (!$ok) {
            return Response::error('Room not found', 404);
        }
        return ['success' => true];
    }

    /** POST /api/decision-rooms/{room_id}/memories */
    public function linkMemory(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $payload = $req->body();
        $mid = trim((string)($payload['memory_id'] ?? ''));
        if ($mid === '' || !$this->memories->findById($mid)) {
            return Response::error('Memory not found', 404);
        }
        $ok = $this->rooms->linkMemory($rid, $mid);
        if (!$ok) {
            return Response::error('Room not found', 404);
        }
        return ['success' => true];
    }

    /** DELETE /api/decision-rooms/{room_id}/memories/{memory_id} */
    public function unlinkMemory(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $mid = (string)$req->param('memory_id');
        if ($mid === '') {
            return Response::error('Missing memory_id', 400);
        }
        $this->rooms->unlinkMemory($rid, $mid);
        return ['success' => true];
    }

    /** POST /api/decision-rooms/{room_id}/sessions */
    public function linkSession(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $payload = $req->body();
        $sid = trim((string)($payload['session_id'] ?? ''));
        if ($sid === '' || !$this->sessions->findById($sid)) {
            return Response::error('Session not found', 404);
        }
        $ok = $this->rooms->linkSession($rid, $sid);
        if (!$ok) {
            return Response::error('Room not found', 404);
        }
        return ['success' => true];
    }

    /** DELETE /api/decision-rooms/{room_id}/sessions/{session_id} */
    public function unlinkSession(Request $req): array
    {
        $rid = (string)$req->param('room_id');
        $sid = (string)$req->param('session_id');
        if ($sid === '') {
            return Response::error('Missing session_id', 400);
        }
        $this->rooms->unlinkSession($rid, $sid);
        return ['success' => true];
    }
}

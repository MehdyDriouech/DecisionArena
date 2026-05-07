<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Palace-lite “decision room” metadata: a chain of decisions within one strategic context.
 * Links to decision_memories and sessions are join-table only (no chat or provider output copied).
 */
final class DecisionRoomRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return list<array<string,mixed>> */
    public function listByContext(string $contextId, int $limit = 200): array
    {
        $limit = max(1, min(300, $limit));
        $stmt = $this->pdo->prepare('
            SELECT * FROM decision_rooms
            WHERE context_id = :cid
            ORDER BY updated_at DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':cid', $contextId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRoom'], $rows);
    }

    public function find(string $roomId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM decision_rooms WHERE room_id = ?');
        $stmt->execute([$roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRoom($row) : null;
    }

    /**
     * @return array<string,mixed>|null Null if strategic context does not exist.
     */
    public function create(
        string $contextId,
        string $title,
        string $description = '',
        ?string $playbookId = null,
        string $status = 'active'
    ): ?array {
        if (!$this->contextExists($contextId)) {
            return null;
        }
        $title = trim($title);
        if ($title === '') {
            $title = 'Untitled room';
        }
        $now = date('c');
        $id = $this->uuid();
        $status = $this->normalizeStatus($status);
        $stmt = $this->pdo->prepare('
            INSERT INTO decision_rooms (room_id, context_id, title, description, playbook_id, status, created_at, updated_at)
            VALUES (:id, :ctx, :t, :d, :pb, :s, :ca, :ua)
        ');
        $stmt->execute([
            ':id' => $id,
            ':ctx' => $contextId,
            ':t' => $title,
            ':d' => (string)$description,
            ':pb' => $playbookId,
            ':s' => $status,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        return $this->find($id);
    }

    /** @return array<string,mixed>|null */
    public function update(string $roomId, array $patch): ?array
    {
        $cur = $this->find($roomId);
        if (!$cur) {
            return null;
        }
        $title = array_key_exists('title', $patch) ? trim((string)$patch['title']) : (string)$cur['title'];
        if ($title === '') {
            $title = 'Untitled room';
        }
        $desc = array_key_exists('description', $patch) ? (string)$patch['description'] : (string)($cur['description'] ?? '');
        $status = array_key_exists('status', $patch)
            ? $this->normalizeStatus((string)$patch['status'])
            : (string)$cur['status'];
        $playbookId = array_key_exists('playbook_id', $patch)
            ? $this->normalizeOptionalString($patch['playbook_id'])
            : $cur['playbook_id'];

        $now = date('c');
        $stmt = $this->pdo->prepare('
            UPDATE decision_rooms
            SET title = :t, description = :d, playbook_id = :pb, status = :s, updated_at = :ua
            WHERE room_id = :id
        ');
        $stmt->execute([
            ':t' => $title,
            ':d' => $desc,
            ':pb' => $playbookId,
            ':s' => $status,
            ':ua' => $now,
            ':id' => $roomId,
        ]);
        return $this->find($roomId);
    }

    /** @return array<string,mixed>|null */
    public function archive(string $roomId): ?array
    {
        return $this->update($roomId, ['status' => 'archived']);
    }

    /**
     * Hard delete room row and join links only (memories / sessions untouched).
     */
    public function delete(string $roomId): bool
    {
        if (!$this->find($roomId)) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM decision_room_memories WHERE room_id = ?')->execute([$roomId]);
        $this->pdo->prepare('DELETE FROM decision_room_sessions WHERE room_id = ?')->execute([$roomId]);
        $this->pdo->prepare('DELETE FROM decision_rooms WHERE room_id = ?')->execute([$roomId]);
        return true;
    }

    public function linkMemory(string $roomId, string $memoryId): bool
    {
        if (!$this->find($roomId)) {
            return false;
        }
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO decision_room_memories (room_id, memory_id, created_at)
            VALUES (:r, :m, :ca)
        ');
        $stmt->execute([':r' => $roomId, ':m' => $memoryId, ':ca' => $now]);
        $this->touchRoom($roomId);
        return true;
    }

    public function unlinkMemory(string $roomId, string $memoryId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM decision_room_memories WHERE room_id = ? AND memory_id = ?');
        $stmt->execute([$roomId, $memoryId]);
        $this->touchRoom($roomId);
        return true;
    }

    public function linkSession(string $roomId, string $sessionId): bool
    {
        if (!$this->find($roomId)) {
            return false;
        }
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO decision_room_sessions (room_id, session_id, created_at)
            VALUES (:r, :s, :ca)
        ');
        $stmt->execute([':r' => $roomId, ':s' => $sessionId, ':ca' => $now]);
        $this->touchRoom($roomId);
        return true;
    }

    public function unlinkSession(string $roomId, string $sessionId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM decision_room_sessions WHERE room_id = ? AND session_id = ?');
        $stmt->execute([$roomId, $sessionId]);
        $this->touchRoom($roomId);
        return true;
    }

    /** @return list<string> */
    public function linkedMemoryIds(string $roomId): array
    {
        $stmt = $this->pdo->prepare('SELECT memory_id FROM decision_room_memories WHERE room_id = ? ORDER BY created_at DESC');
        $stmt->execute([$roomId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<string> */
    public function linkedSessionIds(string $roomId): array
    {
        $stmt = $this->pdo->prepare('SELECT session_id FROM decision_room_sessions WHERE room_id = ? ORDER BY created_at DESC');
        $stmt->execute([$roomId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Deterministic current state from the latest linked memory with user_confirmed = 1.
     *
     * @return array<string,mixed>
     */
    public function currentState(string $roomId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT m.*
            FROM decision_memories m
            INNER JOIN decision_room_memories drm ON drm.memory_id = m.memory_id
            WHERE drm.room_id = :rid AND m.user_confirmed = 1
            ORDER BY m.created_at DESC, m.memory_id DESC
            LIMIT 1
        ');
        $stmt->execute([':rid' => $roomId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'current_decision_status' => '',
                'current_confidence' => '',
                'active_risks' => [],
                'latest_next_step' => '',
                'latest_memory_id' => '',
                'latest_memory_at' => '',
            ];
        }

        $decode = static function ($v) {
            if (!is_string($v) || trim($v) === '') {
                return [];
            }
            $x = json_decode($v, true);
            return is_array($x) ? $x : [];
        };
        $risks = array_values(array_filter(array_map('strval', $decode($row['unresolved_risks'] ?? '[]'))));
        $next = array_values(array_filter(array_map('strval', $decode($row['recommended_next_steps'] ?? '[]'))));

        return [
            'current_decision_status' => (string)($row['decision_status'] ?? ''),
            'current_confidence' => (string)($row['confidence'] ?? ''),
            'active_risks' => array_slice($risks, 0, 10),
            'latest_next_step' => (string)($next[0] ?? ''),
            'latest_memory_id' => (string)($row['memory_id'] ?? ''),
            'latest_memory_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function contextExists(string $contextId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM strategic_contexts WHERE context_id = ? LIMIT 1');
        $stmt->execute([$contextId]);
        return (bool)$stmt->fetchColumn();
    }

    private function touchRoom(string $roomId): void
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE decision_rooms SET updated_at = :u WHERE room_id = :id');
            $stmt->execute([':u' => date('c'), ':id' => $roomId]);
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $row */
    private function hydrateRoom(array $row): array
    {
        $pb = $row['playbook_id'] ?? null;
        return [
            'room_id' => (string)($row['room_id'] ?? ''),
            'context_id' => (string)($row['context_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'playbook_id' => $pb !== null && $pb !== '' ? (string)$pb : null,
            'status' => (string)($row['status'] ?? 'active'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $s = strtolower(trim($status));
        return in_array($s, ['active', 'paused', 'completed', 'archived'], true) ? $s : 'active';
    }

    private function normalizeOptionalString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

final class StrategicContextRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return list<array<string,mixed>> */
    public function list(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $where = [];
        $params = [];
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = :st';
            $params[':st'] = $status;
        }
        $sql = 'SELECT * FROM strategic_contexts';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT :lim';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateContext'], $rows);
    }

    public function find(string $contextId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM strategic_contexts WHERE context_id = ?');
        $stmt->execute([$contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateContext($row) : null;
    }

    /** @return array<string,mixed> */
    public function create(string $title, string $description = '', string $status = 'active'): array
    {
        $title = trim($title);
        $description = (string)$description;
        $status = $this->normalizeStatus($status);
        $now = date('c');
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_contexts (context_id, title, description, status, created_at, updated_at)
            VALUES (:id, :t, :d, :s, :ca, :ua)
        ');
        $stmt->execute([
            ':id' => $id,
            ':t' => $title,
            ':d' => $description,
            ':s' => $status,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        return $this->find($id) ?? ['context_id' => $id];
    }

    public function update(string $contextId, array $patch): ?array
    {
        $cur = $this->find($contextId);
        if (!$cur) return null;

        $title = array_key_exists('title', $patch) ? trim((string)$patch['title']) : (string)$cur['title'];
        $desc  = array_key_exists('description', $patch) ? (string)$patch['description'] : (string)($cur['description'] ?? '');
        $status= array_key_exists('status', $patch) ? $this->normalizeStatus((string)$patch['status']) : (string)$cur['status'];
        $now = date('c');

        $stmt = $this->pdo->prepare('
            UPDATE strategic_contexts
            SET title = :t, description = :d, status = :s, updated_at = :ua
            WHERE context_id = :id
        ');
        $stmt->execute([':t' => $title, ':d' => $desc, ':s' => $status, ':ua' => $now, ':id' => $contextId]);
        return $this->find($contextId);
    }

    public function linkMemory(string $contextId, string $memoryId): bool
    {
        if (!$this->find($contextId)) return false;
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO strategic_context_memories (context_id, memory_id, created_at)
            VALUES (:c, :m, :ca)
        ');
        $stmt->execute([':c' => $contextId, ':m' => $memoryId, ':ca' => $now]);
        $this->touch($contextId);
        return true;
    }

    public function unlinkMemory(string $contextId, string $memoryId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM strategic_context_memories WHERE context_id = ? AND memory_id = ?');
        $stmt->execute([$contextId, $memoryId]);
        $this->touch($contextId);
        return true;
    }

    public function linkSession(string $contextId, string $sessionId): bool
    {
        if (!$this->find($contextId)) return false;
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO strategic_context_sessions (context_id, session_id, created_at)
            VALUES (:c, :s, :ca)
        ');
        $stmt->execute([':c' => $contextId, ':s' => $sessionId, ':ca' => $now]);
        $this->touch($contextId);
        return true;
    }

    public function unlinkSession(string $contextId, string $sessionId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM strategic_context_sessions WHERE context_id = ? AND session_id = ?');
        $stmt->execute([$contextId, $sessionId]);
        $this->touch($contextId);
        return true;
    }

    /**
     * Hard delete a context and its links.
     * Use sparingly (Expert only in UI). Prefer archiving via status.
     */
    public function delete(string $contextId): bool
    {
        if (!$this->find($contextId)) return false;

        // Must delete dependents first (SQLite FK enforcement).
        // strategic_contexts <- decision_rooms <- (decision_room_memories, decision_room_sessions)
        $this->pdo->prepare("
            DELETE FROM decision_room_memories
            WHERE room_id IN (SELECT room_id FROM decision_rooms WHERE context_id = ?)
        ")->execute([$contextId]);
        $this->pdo->prepare("
            DELETE FROM decision_room_sessions
            WHERE room_id IN (SELECT room_id FROM decision_rooms WHERE context_id = ?)
        ")->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM decision_rooms WHERE context_id = ?')->execute([$contextId]);

        $this->pdo->prepare('DELETE FROM strategic_context_memories WHERE context_id = ?')->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM strategic_context_sessions WHERE context_id = ?')->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM strategic_contexts WHERE context_id = ?')->execute([$contextId]);
        return true;
    }

    /** @return list<string> */
    public function linkedMemoryIds(string $contextId): array
    {
        $stmt = $this->pdo->prepare('SELECT memory_id FROM strategic_context_memories WHERE context_id = ? ORDER BY created_at DESC');
        $stmt->execute([$contextId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<string> */
    public function linkedSessionIds(string $contextId): array
    {
        $stmt = $this->pdo->prepare('SELECT session_id FROM strategic_context_sessions WHERE context_id = ? ORDER BY created_at DESC');
        $stmt->execute([$contextId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Deterministic “Current State” (no LLM):
     * - latest linked memory drives decision_status/confidence/risks/next_step
     *
     * @return array<string,mixed>
     */
    public function currentState(string $contextId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT m.*
            FROM decision_memories m
            INNER JOIN strategic_context_memories scm ON scm.memory_id = m.memory_id
            WHERE scm.context_id = :cid AND m.user_confirmed = 1
            ORDER BY m.created_at DESC, m.memory_id DESC
            LIMIT 1
        ');
        $stmt->execute([':cid' => $contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'current_decision_status' => '',
                'current_confidence' => '',
                'active_risks' => [],
                'latest_next_step' => '',
                'latest_memory_id' => '',
                'latest_memory_at' => '',
                'decision_summary' => '',
                'contract_version' => '',
                'taxonomy_version' => '',
            ];
        }

        $decode = static function($v) {
            if (!is_string($v) || trim($v) === '') return [];
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
            'decision_summary' => trim((string)($row['decision_summary'] ?? '')),
            'contract_version' => (string)($row['contract_version'] ?? ''),
            'taxonomy_version' => (string)($row['taxonomy_version'] ?? ''),
        ];
    }

    private function touch(string $contextId): void
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE strategic_contexts SET updated_at = :u WHERE context_id = :id');
            $stmt->execute([':u' => date('c'), ':id' => $contextId]);
        } catch (\Throwable) {}
    }

    /** @param array<string,mixed> $row */
    private function hydrateContext(array $row): array
    {
        return [
            'context_id' => (string)($row['context_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'status' => (string)($row['status'] ?? 'active'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $s = strtolower(trim($status));
        return in_array($s, ['active', 'paused', 'completed', 'abandoned'], true) ? $s : 'active';
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}


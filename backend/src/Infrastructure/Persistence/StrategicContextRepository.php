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
        $sql .= ' ORDER BY is_workspace_active DESC, updated_at DESC LIMIT :lim';
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

    public function getActiveContext(): ?array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM strategic_contexts WHERE is_workspace_active = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        } catch (\Throwable) {
            return null;
        }
        return $row ? $this->hydrateContext($row) : null;
    }

    /**
     * Atomically set the global active workspace context. Idempotent if already active.
     * Only contexts in status active|paused may be activated.
     */
    public function setActiveContext(string $contextId): bool
    {
        $contextId = trim($contextId);
        if ($contextId === '') {
            return false;
        }
        $cur = $this->find($contextId);
        if (!$cur) {
            return false;
        }
        $st = (string)($cur['status'] ?? '');
        if (!in_array($st, ['active', 'paused'], true)) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE strategic_contexts SET is_workspace_active = 0');
            $stmt = $this->pdo->prepare(
                'UPDATE strategic_contexts SET is_workspace_active = 1, updated_at = :u WHERE context_id = :id'
            );
            $stmt->execute([':u' => date('c'), ':id' => $contextId]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
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
            INSERT INTO strategic_contexts (context_id, title, description, status, created_at, updated_at, is_workspace_active)
            VALUES (:id, :t, :d, :s, :ca, :ua, 0)
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
        // Un contexte « terminé » ou « abandonné » ne doit plus être la référence workspace globale
        // (sinon l’UI et les garde-fous session pourraient pointer vers un statut non exécutable).
        if (in_array($status, ['completed', 'abandoned'], true)) {
            try {
                $this->pdo->prepare('UPDATE strategic_contexts SET is_workspace_active = 0 WHERE context_id = ?')->execute([$contextId]);
            } catch (\Throwable) {
            }
        }
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

        $this->pdo->beginTransaction();
        try {
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

            // Beliefs graph is multi-table with FK chains:
            // beliefs <- (events, relations, agent_positions)
            $this->pdo->prepare('DELETE FROM strategic_context_belief_events WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_belief_relations WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_belief_agent_positions WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_beliefs WHERE strategic_context_id = ?')->execute([$contextId]);

            // Governance events scoped by context.
            $this->pdo->prepare('DELETE FROM strategic_context_memory_governance_events WHERE strategic_context_id = ?')->execute([$contextId]);

            $this->pdo->prepare('DELETE FROM strategic_context_memories WHERE context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_sessions WHERE context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_narratives WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_memory_compilations WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_context_snapshots WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare(
                'DELETE FROM agent_context_chat_messages WHERE conversation_id IN '
                . '(SELECT id FROM agent_context_conversations WHERE strategic_context_id = ?)'
            )->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM agent_context_conversations WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM open_space_task_events WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM open_space_task_messages WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM open_space_tasks WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM open_space_boards WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM open_space_orchestrator_proposals WHERE strategic_context_id = ?')->execute([$contextId]);
            $this->pdo->prepare('DELETE FROM strategic_contexts WHERE context_id = ?')->execute([$contextId]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
        $active = (int)($row['is_workspace_active'] ?? 0);

        return [
            'context_id' => (string)($row['context_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'status' => (string)($row['status'] ?? 'active'),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'is_workspace_active' => $active === 1 ? 1 : 0,
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


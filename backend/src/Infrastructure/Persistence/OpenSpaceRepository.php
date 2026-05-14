<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

final class OpenSpaceRepository
{
    private \PDO $pdo;

    public const TASK_STATUSES = ['backlog', 'todo', 'doing', 'testing', 'done'];

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return list<array<string,mixed>> */
    public function listBoards(string $contextId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM open_space_boards WHERE strategic_context_id = :cid ORDER BY datetime(updated_at) DESC, id DESC'
        );
        $stmt->execute([':cid' => strtolower(trim($contextId))]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findBoard(string $boardId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM open_space_boards WHERE id = ?');
        $stmt->execute([trim($boardId)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findContextBoard(string $contextId, string $boardId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM open_space_boards WHERE id = :id AND strategic_context_id = :cid');
        $acceptance = $payload['acceptance_criteria'] ?? null;
        if (is_array($acceptance)) {
            $acceptance = implode("\n", array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $acceptance), static fn ($x) => $x !== '')));
            if ($acceptance === '') {
                $acceptance = null;
            }
        }
        $stmt->execute([
            ':id' => trim($boardId),
            ':cid' => strtolower(trim($contextId)),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createBoard(string $contextId, string $title, ?string $description = null): array
    {
        $id = $this->uuid();
        $now = date('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO open_space_boards (id, strategic_context_id, title, description, created_at, updated_at)
             VALUES (:id, :cid, :title, :descr, :ca, :ua)'
        );
        $stmt->execute([
            ':id' => $id,
            ':cid' => strtolower(trim($contextId)),
            ':title' => trim($title),
            ':descr' => $description,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        return $this->findBoard($id) ?? [];
    }

    public function ensureContextBoard(string $contextId): array
    {
        $existing = $this->listBoards($contextId);
        if ($existing !== []) {
            return $existing[0];
        }
        return $this->createBoard($contextId, 'OpenSpace Board', 'Board MVP context-scoped');
    }

    /** @return list<array<string,mixed>> */
    public function listBoardTasks(string $contextId, string $boardId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM open_space_tasks
             WHERE strategic_context_id = :cid AND board_id = :bid
             ORDER BY datetime(updated_at) DESC, id DESC'
        );
        $stmt->execute([
            ':cid' => strtolower(trim($contextId)),
            ':bid' => trim($boardId),
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listTasks(string $contextId, array $filters = []): array
    {
        $sql = 'SELECT * FROM open_space_tasks WHERE strategic_context_id = :cid';
        $params = [':cid' => strtolower(trim($contextId))];
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        if ($status !== '' && in_array($status, self::TASK_STATUSES, true)) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        $agentId = strtolower(trim((string)($filters['agent_id'] ?? '')));
        if ($agentId !== '') {
            $sql .= ' AND assignee_agent_id = :aid';
            $params[':aid'] = $agentId;
        }
        $sql .= ' ORDER BY datetime(updated_at) DESC, id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findTask(string $taskId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM open_space_tasks WHERE id = ?');
        $stmt->execute([trim($taskId)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $payload */
    public function createTask(array $payload): array
    {
        $id = $this->uuid();
        $now = date('c');
        $acceptance = $payload['acceptance_criteria'] ?? null;
        if (is_array($acceptance)) {
            $acceptance = implode("\n", array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $acceptance), static fn ($x) => $x !== '')));
            if ($acceptance === '') {
                $acceptance = null;
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO open_space_tasks (
                id, board_id, strategic_context_id, title, description, status, priority,
                assignee_agent_id, source_type, source_id, linked_session_id, linked_decision_memory_id,
                acceptance_criteria, created_by, created_at, updated_at
             ) VALUES (
                :id, :board_id, :cid, :title, :descr, :status, :priority,
                :assignee, :source_type, :source_id, :linked_session_id, :linked_dm_id,
                :acceptance, :created_by, :ca, :ua
             )'
        );
        $stmt->execute([
            ':id' => $id,
            ':board_id' => (string)$payload['board_id'],
            ':cid' => strtolower(trim((string)$payload['strategic_context_id'])),
            ':title' => trim((string)$payload['title']),
            ':descr' => $payload['description'] ?? null,
            ':status' => strtolower(trim((string)$payload['status'])),
            ':priority' => $payload['priority'] ?? null,
            ':assignee' => $payload['assignee_agent_id'] ?? null,
            ':source_type' => $payload['source_type'] ?? null,
            ':source_id' => $payload['source_id'] ?? null,
            ':linked_session_id' => $payload['linked_session_id'] ?? null,
            ':linked_dm_id' => $payload['linked_decision_memory_id'] ?? null,
            ':acceptance' => $acceptance,
            ':created_by' => $payload['created_by'] ?? null,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        return $this->findTask($id) ?? [];
    }

    /** @param array<string,mixed> $patch */
    public function updateTask(string $taskId, string $contextId, array $patch): ?array
    {
        $allowed = [
            'title',
            'description',
            'status',
            'priority',
            'assignee_agent_id',
            'source_type',
            'source_id',
            'linked_session_id',
            'linked_decision_memory_id',
            'acceptance_criteria',
            'created_by',
        ];
        $sets = [];
        $params = [
            ':id' => trim($taskId),
            ':cid' => strtolower(trim($contextId)),
            ':ua' => date('c'),
        ];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $patch)) {
                continue;
            }
            $v = $patch[$col];
            if ($col === 'status') {
                $sv = strtolower(trim((string)$v));
                if (!in_array($sv, self::TASK_STATUSES, true)) {
                    continue;
                }
                $v = $sv;
            }
            if ($col === 'assignee_agent_id') {
                $v = $v === null ? null : strtolower(trim((string)$v));
                if ($v === '') {
                    $v = null;
                }
            }
            if ($col === 'acceptance_criteria' && is_array($v)) {
                $v = implode("\n", array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $v), static fn ($x) => $x !== '')));
                if ($v === '') {
                    $v = null;
                }
            }
            $sets[] = $col . ' = :' . $col;
            $params[':' . $col] = $v;
        }
        if ($sets === []) {
            return $this->findTask($taskId);
        }
        $sets[] = 'updated_at = :ua';
        $sql = 'UPDATE open_space_tasks SET ' . implode(', ', $sets) . ' WHERE id = :id AND strategic_context_id = :cid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->findTask($taskId);
    }

    public function insertTaskEvent(string $taskId, string $contextId, string $eventType, array $payload = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO open_space_task_events (id, task_id, strategic_context_id, event_type, payload_json, created_at)
             VALUES (:id, :task_id, :cid, :etype, :payload, :ca)'
        );
        $stmt->execute([
            ':id' => $this->uuid(),
            ':task_id' => trim($taskId),
            ':cid' => strtolower(trim($contextId)),
            ':etype' => trim($eventType),
            ':payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ':ca' => date('c'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listTaskMessages(string $contextId, ?string $taskId): array
    {
        if ($taskId !== null && trim($taskId) !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM open_space_task_messages
                 WHERE strategic_context_id = :cid AND task_id = :tid
                 ORDER BY datetime(created_at) ASC, id ASC'
            );
            $stmt->execute([
                ':cid' => strtolower(trim($contextId)),
                ':tid' => trim($taskId),
            ]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM open_space_task_messages
             WHERE strategic_context_id = :cid AND task_id IS NULL
             ORDER BY datetime(created_at) ASC, id ASC'
        );
        $stmt->execute([':cid' => strtolower(trim($contextId))]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $payload */
    public function createTaskMessage(array $payload): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO open_space_task_messages
             (id, task_id, strategic_context_id, agent_id, role, content, metadata_json, created_at)
             VALUES (:id, :task_id, :cid, :aid, :role, :content, :meta, :ca)'
        );
        $stmt->execute([
            ':id' => $id,
            ':task_id' => $payload['task_id'] ?? null,
            ':cid' => strtolower(trim((string)$payload['strategic_context_id'])),
            ':aid' => $payload['agent_id'] ?? null,
            ':role' => strtolower(trim((string)$payload['role'])),
            ':content' => (string)$payload['content'],
            ':meta' => array_key_exists('metadata_json', $payload) ? $payload['metadata_json'] : null,
            ':ca' => date('c'),
        ]);
        $stmt = $this->pdo->prepare('SELECT * FROM open_space_task_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $proposalJson */
    public function createProposal(
        string $contextId,
        string $objective,
        array $proposalJson,
        string $status = 'draft',
        ?array $proposalMeta = null,
        string $proposalSource = 'llm',
        bool $warning = false
    ): array
    {
        $id = $this->uuid();
        $now = date('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO open_space_orchestrator_proposals
             (id, strategic_context_id, objective, proposal_json, status, proposal_metadata_json, proposal_source, warning, created_at, updated_at)
             VALUES (:id, :cid, :objective, :proposal_json, :status, :proposal_metadata_json, :proposal_source, :warning, :ca, :ua)'
        );
        $stmt->execute([
            ':id' => $id,
            ':cid' => strtolower(trim($contextId)),
            ':objective' => trim($objective),
            ':proposal_json' => json_encode($proposalJson, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ':status' => trim($status) === '' ? 'draft' : trim($status),
            ':proposal_metadata_json' => $proposalMeta ? json_encode($proposalMeta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            ':proposal_source' => trim($proposalSource) === '' ? 'llm' : trim($proposalSource),
            ':warning' => $warning ? 1 : 0,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        return $this->findProposal($id) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function listProposals(string $contextId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM open_space_orchestrator_proposals
             WHERE strategic_context_id = :cid
             ORDER BY datetime(updated_at) DESC, id DESC'
        );
        $stmt->execute([':cid' => strtolower(trim($contextId))]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findProposal(string $proposalId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM open_space_orchestrator_proposals WHERE id = ?');
        $stmt->execute([trim($proposalId)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateProposalStatus(string $proposalId, string $nextStatus): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE open_space_orchestrator_proposals SET status = :st, updated_at = :ua WHERE id = :id'
        );
        $stmt->execute([
            ':st' => trim($nextStatus),
            ':ua' => date('c'),
            ':id' => trim($proposalId),
        ]);
        return $this->findProposal($proposalId);
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


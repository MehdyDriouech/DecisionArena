<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Beliefs explicites par Strategic Context (aucune génération automatique).
 */
final class StrategicContextBeliefRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return ?array<string,mixed> */
    public function findByIdInContext(string $beliefId, string $contextId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM strategic_context_beliefs WHERE id = ? AND strategic_context_id = ?'
        );
        $stmt->execute([$beliefId, $contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return ?array<string,mixed> */
    public function findById(string $beliefId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM strategic_context_beliefs WHERE id = ?');
        $stmt->execute([$beliefId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array{
     *   agent_id?:?string,
     *   belief_type?:?string,
     *   status?:?string,
     *   contestation_state?:?string,
     *   disputed_only?:bool,
     *   limit?:int
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function listForContext(string $contextId, array $filters = []): array
    {
        $limit = (int)($filters['limit'] ?? 400);
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM strategic_context_beliefs WHERE strategic_context_id = :cid';
        $params = [':cid' => $contextId];
        if (array_key_exists('agent_id', $filters) && $filters['agent_id'] !== null) {
            $aid = trim((string)$filters['agent_id']);
            if ($aid === '') {
                $sql .= ' AND (agent_id IS NULL OR TRIM(agent_id) = \'\')';
            } else {
                $sql .= ' AND agent_id = :aid';
                $params[':aid'] = $aid;
            }
        }
        $bt = isset($filters['belief_type']) ? trim((string)$filters['belief_type']) : '';
        if ($bt !== '') {
            $sql .= ' AND belief_type = :bt';
            $params[':bt'] = $bt;
        }
        $st = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($st !== '') {
            $sql .= ' AND status = :st';
            $params[':st'] = $st;
        }
        $cs = isset($filters['contestation_state']) ? trim((string)$filters['contestation_state']) : '';
        if ($cs !== '') {
            $sql .= ' AND contestation_state = :cs';
            $params[':cs'] = $cs;
        }
        $sql .= ' ORDER BY datetime(updated_at) DESC, id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    /**
     * @param array{
     *   strategic_context_id?:?string,
     *   belief_type?:?string,
     *   status?:?string,
     *   contestation_state?:?string,
     *   agent_id?:?string,
     *   q?:?string
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function listGlobal(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM strategic_context_beliefs WHERE 1=1';
        $params = [];
        $ctx = trim((string)($filters['strategic_context_id'] ?? ''));
        if ($ctx !== '') {
            $sql .= ' AND strategic_context_id = :ctx';
            $params[':ctx'] = $ctx;
        }
        $bt = trim((string)($filters['belief_type'] ?? ''));
        if ($bt !== '') {
            $sql .= ' AND belief_type = :bt';
            $params[':bt'] = $bt;
        }
        $st = trim((string)($filters['status'] ?? ''));
        if ($st !== '') {
            $sql .= ' AND status = :st';
            $params[':st'] = $st;
        }
        $cs = trim((string)($filters['contestation_state'] ?? ''));
        if ($cs !== '') {
            $sql .= ' AND contestation_state = :cs';
            $params[':cs'] = $cs;
        }
        $aid = trim((string)($filters['agent_id'] ?? ''));
        if ($aid !== '') {
            $sql .= ' AND agent_id = :aid';
            $params[':aid'] = $aid;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (belief_text LIKE :q OR id LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY datetime(updated_at) DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_beliefs (
                id, strategic_context_id, agent_id, belief_type, belief_text,
                confidence, status,
                evidence_sources_json, supporting_agents_json, disagreeing_agents_json, related_session_ids_json,
                source_type, source_reference_id,
                created_by, created_at, updated_at, last_reviewed_at,
                parent_belief_id, supersedes_belief_id, invalidated_by, invalidation_reason,
                confidence_history_json, drift_score, consensus_score, contested_by_agents_json, contestation_state
            ) VALUES (
                :id, :cid, :aid, :bt, :txt,
                :conf, :st,
                :es, :sa, :da, :rs,
                :srt, :srid,
                :cb, :ca, :ua, :lra,
                :parent_bid, :supersedes_bid, :invalidated_by, :invalidation_reason,
                :confidence_history, :drift_score, :consensus_score, :contested_by_agents, :contestation_state
            )
        ');
        $stmt->execute([
            ':id' => $row['id'],
            ':cid' => $row['strategic_context_id'],
            ':aid' => $row['agent_id'],
            ':bt' => $row['belief_type'],
            ':txt' => $row['belief_text'],
            ':conf' => $row['confidence'],
            ':st' => $row['status'],
            ':es' => $row['evidence_sources_json'],
            ':sa' => $row['supporting_agents_json'],
            ':da' => $row['disagreeing_agents_json'],
            ':rs' => $row['related_session_ids_json'],
            ':srt' => $row['source_type'],
            ':srid' => $row['source_reference_id'],
            ':cb' => $row['created_by'],
            ':ca' => $row['created_at'],
            ':ua' => $row['updated_at'],
            ':lra' => $row['last_reviewed_at'],
            ':parent_bid' => $row['parent_belief_id'] ?? null,
            ':supersedes_bid' => $row['supersedes_belief_id'] ?? null,
            ':invalidated_by' => $row['invalidated_by'] ?? null,
            ':invalidation_reason' => $row['invalidation_reason'] ?? null,
            ':confidence_history' => $row['confidence_history_json'] ?? '[]',
            ':drift_score' => $row['drift_score'] ?? 0.0,
            ':consensus_score' => $row['consensus_score'] ?? 0.0,
            ':contested_by_agents' => $row['contested_by_agents_json'] ?? '[]',
            ':contestation_state' => $row['contestation_state'] ?? 'weak',
        ]);
    }

    /**
     * @param array<string,mixed> $patch colonnes autorisées uniquement
     */
    public function update(string $beliefId, string $contextId, array $patch): bool
    {
        $allowed = [
            'agent_id', 'belief_type', 'belief_text', 'confidence', 'status',
            'evidence_sources_json', 'supporting_agents_json', 'disagreeing_agents_json', 'related_session_ids_json',
            'source_type', 'source_reference_id', 'created_by', 'last_reviewed_at',
            'parent_belief_id', 'supersedes_belief_id', 'invalidated_by', 'invalidation_reason',
            'confidence_history_json', 'drift_score', 'consensus_score', 'contested_by_agents_json', 'contestation_state',
        ];
        $sets = [];
        $params = [':id' => $beliefId, ':cid' => $contextId];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $patch)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[':' . $col] = $patch[$col];
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = :ua';
        $params[':ua'] = date('c');
        $sql = 'UPDATE strategic_context_beliefs SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND strategic_context_id = :cid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function deleteForContext(string $contextId): void
    {
        $this->pdo->prepare('DELETE FROM strategic_context_belief_events WHERE strategic_context_id = ?')->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM strategic_context_belief_relations WHERE strategic_context_id = ?')->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM strategic_context_belief_agent_positions WHERE strategic_context_id = ?')->execute([$contextId]);
        $this->pdo->prepare('DELETE FROM strategic_context_beliefs WHERE strategic_context_id = ?')->execute([$contextId]);
    }

    /** @param array<string,mixed> $event */
    public function insertEvent(array $event): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_belief_events (
                event_id, strategic_context_id, belief_id, event_type, actor_id, reason, payload_json, occurred_at, created_at
            ) VALUES (
                :id, :ctx, :bid, :typ, :actor, :reason, :payload, :occ, :crt
            )
        ');
        $stmt->execute([
            ':id' => $event['event_id'],
            ':ctx' => $event['strategic_context_id'],
            ':bid' => $event['belief_id'],
            ':typ' => $event['event_type'],
            ':actor' => $event['actor_id'] ?? null,
            ':reason' => $event['reason'] ?? null,
            ':payload' => $event['payload_json'] ?? '{}',
            ':occ' => $event['occurred_at'],
            ':crt' => $event['created_at'],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listEventsForBelief(string $beliefId, ?string $contextId = null, int $limit = 400): array
    {
        $limit = max(1, min(2000, $limit));
        $sql = 'SELECT * FROM strategic_context_belief_events WHERE belief_id = :bid';
        $params = [':bid' => $beliefId];
        if ($contextId !== null && trim($contextId) !== '') {
            $sql .= ' AND strategic_context_id = :ctx';
            $params[':ctx'] = trim($contextId);
        }
        $sql .= ' ORDER BY datetime(occurred_at) ASC, event_id ASC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    /**
     * @param list<array<string,mixed>> $relations
     */
    public function replaceRelationsForBelief(string $contextId, string $beliefId, array $relations): void
    {
        $this->pdo->prepare('DELETE FROM strategic_context_belief_relations WHERE strategic_context_id = ? AND from_belief_id = ?')
            ->execute([$contextId, $beliefId]);
        foreach ($relations as $r) {
            $this->upsertRelation([
                'strategic_context_id' => $contextId,
                'from_belief_id' => $beliefId,
                'relation_type' => (string)($r['relation_type'] ?? ''),
                'to_entity_type' => (string)($r['to_entity_type'] ?? ''),
                'to_entity_id' => (string)($r['to_entity_id'] ?? ''),
                'metadata_json' => json_encode($r['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
    }

    /** @param array<string,mixed> $row */
    public function upsertRelation(array $row): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_belief_relations (
                strategic_context_id, from_belief_id, relation_type, to_entity_type, to_entity_id, metadata_json, created_at, updated_at
            ) VALUES (
                :ctx, :from_bid, :typ, :to_typ, :to_id, :meta, :crt, :upd
            )
            ON CONFLICT(strategic_context_id, from_belief_id, relation_type, to_entity_type, to_entity_id)
            DO UPDATE SET metadata_json = excluded.metadata_json, updated_at = excluded.updated_at
        ');
        $stmt->execute([
            ':ctx' => $row['strategic_context_id'],
            ':from_bid' => $row['from_belief_id'],
            ':typ' => $row['relation_type'],
            ':to_typ' => $row['to_entity_type'],
            ':to_id' => $row['to_entity_id'],
            ':meta' => $row['metadata_json'] ?? '{}',
            ':crt' => $now,
            ':upd' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listRelationsForBelief(string $contextId, string $beliefId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM strategic_context_belief_relations
            WHERE strategic_context_id = :ctx AND from_belief_id = :bid
            ORDER BY relation_type ASC, to_entity_type ASC, to_entity_id ASC
        ');
        $stmt->execute([':ctx' => $contextId, ':bid' => $beliefId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    public function upsertAgentPosition(string $contextId, string $beliefId, string $agentId, string $position, ?string $reason = null): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_belief_agent_positions (
                strategic_context_id, belief_id, agent_id, position, reason, created_at, updated_at
            ) VALUES (
                :ctx, :bid, :aid, :pos, :reason, :crt, :upd
            )
            ON CONFLICT(strategic_context_id, belief_id, agent_id)
            DO UPDATE SET position = excluded.position, reason = excluded.reason, updated_at = excluded.updated_at
        ');
        $stmt->execute([
            ':ctx' => $contextId,
            ':bid' => $beliefId,
            ':aid' => $agentId,
            ':pos' => $position,
            ':reason' => $reason,
            ':crt' => $now,
            ':upd' => $now,
        ]);
    }
}

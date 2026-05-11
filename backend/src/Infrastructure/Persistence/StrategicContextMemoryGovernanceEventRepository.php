<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Journal append-only de gouvernance cognitive par strategic context.
 */
final class StrategicContextMemoryGovernanceEventRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_memory_governance_events (
                event_id, strategic_context_id, entity_type, entity_id, agent_id,
                event_type, governance_status, provenance_level, trust_level,
                actor_id, reason, metadata_json, occurred_at, created_at
            ) VALUES (
                :event_id, :strategic_context_id, :entity_type, :entity_id, :agent_id,
                :event_type, :governance_status, :provenance_level, :trust_level,
                :actor_id, :reason, :metadata_json, :occurred_at, :created_at
            )
        ');
        $stmt->execute([
            ':event_id' => (string)($row['event_id'] ?? ''),
            ':strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            ':entity_type' => (string)($row['entity_type'] ?? ''),
            ':entity_id' => (string)($row['entity_id'] ?? ''),
            ':agent_id' => $row['agent_id'] !== null && (string)$row['agent_id'] !== '' ? (string)$row['agent_id'] : null,
            ':event_type' => (string)($row['event_type'] ?? ''),
            ':governance_status' => (string)($row['governance_status'] ?? 'pending'),
            ':provenance_level' => (string)($row['provenance_level'] ?? 'explicit'),
            ':trust_level' => isset($row['trust_level']) ? (float)$row['trust_level'] : 0.5,
            ':actor_id' => $row['actor_id'] !== null && (string)$row['actor_id'] !== '' ? (string)$row['actor_id'] : null,
            ':reason' => $row['reason'] !== null && (string)$row['reason'] !== '' ? (string)$row['reason'] : null,
            ':metadata_json' => (string)($row['metadata_json'] ?? '{}'),
            ':occurred_at' => (string)($row['occurred_at'] ?? date('c')),
            ':created_at' => (string)($row['created_at'] ?? date('c')),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listForContext(string $contextId, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM strategic_context_memory_governance_events
             WHERE strategic_context_id = :cid
             ORDER BY datetime(occurred_at) DESC, event_id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':cid' => $contextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_values($rows);
    }
}

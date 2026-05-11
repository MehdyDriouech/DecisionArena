<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Snapshots stratégiques immuables par contexte (INSERT uniquement côté contenu).
 */
final class StrategicContextSnapshotRepository
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
            INSERT INTO strategic_context_snapshots (
                id, strategic_context_id, snapshot_type, title, description,
                snapshot_markdown,
                strategic_narrative_json, beliefs_snapshot_json, risks_snapshot_json,
                evidence_snapshot_json, social_snapshot_json, timeline_snapshot_json,
                memory_compilations_json, source_summary_json, metadata_json,
                snapshot_hash, created_by, created_at
            ) VALUES (
                :id, :strategic_context_id, :snapshot_type, :title, :description,
                :snapshot_markdown,
                :strategic_narrative_json, :beliefs_snapshot_json, :risks_snapshot_json,
                :evidence_snapshot_json, :social_snapshot_json, :timeline_snapshot_json,
                :memory_compilations_json, :source_summary_json, :metadata_json,
                :snapshot_hash, :created_by, :created_at
            )
        ');
        $stmt->execute([
            ':id' => (string)($row['id'] ?? ''),
            ':strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            ':snapshot_type' => (string)($row['snapshot_type'] ?? ''),
            ':title' => (string)($row['title'] ?? ''),
            ':description' => $row['description'] !== null && (string)$row['description'] !== '' ? (string)$row['description'] : null,
            ':snapshot_markdown' => (string)($row['snapshot_markdown'] ?? ''),
            ':strategic_narrative_json' => (string)($row['strategic_narrative_json'] ?? '{}'),
            ':beliefs_snapshot_json' => (string)($row['beliefs_snapshot_json'] ?? '{}'),
            ':risks_snapshot_json' => (string)($row['risks_snapshot_json'] ?? '{}'),
            ':evidence_snapshot_json' => (string)($row['evidence_snapshot_json'] ?? '{}'),
            ':social_snapshot_json' => (string)($row['social_snapshot_json'] ?? '{}'),
            ':timeline_snapshot_json' => (string)($row['timeline_snapshot_json'] ?? '{}'),
            ':memory_compilations_json' => (string)($row['memory_compilations_json'] ?? '{}'),
            ':source_summary_json' => (string)($row['source_summary_json'] ?? '{}'),
            ':metadata_json' => (string)($row['metadata_json'] ?? '{}'),
            ':snapshot_hash' => $row['snapshot_hash'] !== null && (string)$row['snapshot_hash'] !== '' ? (string)$row['snapshot_hash'] : null,
            ':created_by' => $row['created_by'] !== null && (string)$row['created_by'] !== '' ? (string)$row['created_by'] : null,
            ':created_at' => (string)($row['created_at'] ?? ''),
        ]);
    }

    /** @return ?array<string,mixed> */
    public function findByIdInContext(string $snapshotId, string $contextId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM strategic_context_snapshots WHERE id = ? AND strategic_context_id = ?'
        );
        $stmt->execute([$snapshotId, $contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Liste légère (sans colonnes JSON lourdes ni markdown).
     *
     * @param array{snapshot_type?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listSummaryForContext(string $contextId, array $filters = []): array
    {
        $limit = (int)($filters['limit'] ?? 60);
        $limit = max(1, min(120, $limit));
        $sql = 'SELECT id, strategic_context_id, snapshot_type, title, description,
            source_summary_json, metadata_json, snapshot_hash, created_by, created_at
            FROM strategic_context_snapshots WHERE strategic_context_id = :cid';
        $params = [':cid' => $contextId];
        $st = isset($filters['snapshot_type']) ? trim((string)$filters['snapshot_type']) : '';
        if ($st !== '') {
            $sql .= ' AND snapshot_type = :st';
            $params[':st'] = $st;
        }
        $sql .= ' ORDER BY datetime(created_at) DESC, id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Compilations de mémoire stratégique (agrégat dérivé, auditable, réversible).
 */
final class StrategicContextMemoryCompilationRepository
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
            INSERT INTO strategic_context_memory_compilations (
                id, strategic_context_id, compilation_type, title, summary,
                compiled_memory_markdown, source_snapshot_json, compilation_metadata_json,
                confidence, stability_score, status, source_hash, created_by, created_at, updated_at
            ) VALUES (
                :id, :strategic_context_id, :compilation_type, :title, :summary,
                :compiled_memory_markdown, :source_snapshot_json, :compilation_metadata_json,
                :confidence, :stability_score, :status, :source_hash, :created_by, :created_at, :updated_at
            )
        ');
        $stmt->execute([
            ':id' => (string)($row['id'] ?? ''),
            ':strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            ':compilation_type' => (string)($row['compilation_type'] ?? ''),
            ':title' => (string)($row['title'] ?? ''),
            ':summary' => (string)($row['summary'] ?? ''),
            ':compiled_memory_markdown' => (string)($row['compiled_memory_markdown'] ?? ''),
            ':source_snapshot_json' => (string)($row['source_snapshot_json'] ?? '{}'),
            ':compilation_metadata_json' => (string)($row['compilation_metadata_json'] ?? '{}'),
            ':confidence' => isset($row['confidence']) ? (float)$row['confidence'] : 0.5,
            ':stability_score' => isset($row['stability_score']) ? (float)$row['stability_score'] : 0.5,
            ':status' => (string)($row['status'] ?? 'active'),
            ':source_hash' => $row['source_hash'] !== null && (string)$row['source_hash'] !== '' ? (string)$row['source_hash'] : null,
            ':created_by' => $row['created_by'] !== null && (string)$row['created_by'] !== '' ? (string)$row['created_by'] : null,
            ':created_at' => (string)($row['created_at'] ?? ''),
            ':updated_at' => (string)($row['updated_at'] ?? ''),
        ]);
    }

    /** @return ?array<string,mixed> */
    public function findByIdInContext(string $compilationId, string $contextId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM strategic_context_memory_compilations WHERE id = ? AND strategic_context_id = ?'
        );
        $stmt->execute([$compilationId, $contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array{compilation_type?:string,status?:string,limit?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listForContext(string $contextId, array $filters = []): array
    {
        $limit = (int)($filters['limit'] ?? 80);
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, strategic_context_id, compilation_type, title, summary, confidence, stability_score,
            status, source_snapshot_json, compilation_metadata_json, source_hash, created_by, created_at, updated_at
            FROM strategic_context_memory_compilations WHERE strategic_context_id = :cid';
        $params = [':cid' => $contextId];
        $ct = isset($filters['compilation_type']) ? trim((string)$filters['compilation_type']) : '';
        if ($ct !== '') {
            $sql .= ' AND compilation_type = :ct';
            $params[':ct'] = $ct;
        }
        $st = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($st !== '') {
            $sql .= ' AND status = :st';
            $params[':st'] = $st;
        }
        $sql .= ' ORDER BY datetime(created_at) DESC, id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_values($rows);
    }

    public function updateStatus(string $compilationId, string $contextId, string $status): bool
    {
        $now = date('c');
        $stmt = $this->pdo->prepare(
            'UPDATE strategic_context_memory_compilations SET status = ?, updated_at = ?
             WHERE id = ? AND strategic_context_id = ?'
        );
        $stmt->execute([$status, $now, $compilationId, $contextId]);

        return $stmt->rowCount() > 0;
    }

    /** Marque les compilations actives du même type comme superseded (avant insertion d’une nouvelle). */
    public function supersedeActiveByType(string $contextId, string $compilationType): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare(
            'UPDATE strategic_context_memory_compilations SET status = ?, updated_at = ?
             WHERE strategic_context_id = ? AND compilation_type = ? AND status = ?'
        );
        $stmt->execute(['superseded', $now, $contextId, $compilationType, 'active']);
    }
}

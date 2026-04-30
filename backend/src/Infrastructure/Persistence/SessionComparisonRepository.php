<?php
namespace Infrastructure\Persistence;

class SessionComparisonRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findAll(): array {
        $stmt = $this->pdo->query('SELECT * FROM session_comparisons ORDER BY created_at DESC');
        return array_map([$this, 'decodeRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM session_comparisons WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodeRow($row) : null;
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO session_comparisons (id, title, session_ids, content_markdown, content_json, created_at)
            VALUES (:id, :title, :session_ids, :content_markdown, :content_json, :created_at)
        ');
        $stmt->execute([
            ':id'               => $data['id'],
            ':title'            => $data['title'] ?? null,
            ':session_ids'      => is_array($data['session_ids'])
                                    ? json_encode($data['session_ids'])
                                    : $data['session_ids'],
            ':content_markdown' => $data['content_markdown'],
            ':content_json'     => $data['content_json'] ?? null,
            ':created_at'       => $data['created_at'],
        ]);
        return $this->findById($data['id']);
    }

    public function delete(string $id): void {
        $this->pdo->prepare('DELETE FROM session_comparisons WHERE id = ?')->execute([$id]);
    }

    private function decodeRow(array $row): array {
        if (isset($row['session_ids']) && is_string($row['session_ids'])) {
            $row['session_ids'] = json_decode($row['session_ids'], true) ?? [];
        }
        return $row;
    }
}

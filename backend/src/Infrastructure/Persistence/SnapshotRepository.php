<?php
namespace Infrastructure\Persistence;

class SnapshotRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_snapshots WHERE session_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO session_snapshots
                (id, session_id, title, content_markdown, content_json, created_at)
            VALUES
                (:id, :session_id, :title, :content_markdown, :content_json, :created_at)
        ');
        $stmt->execute([
            ':id'               => $data['id'],
            ':session_id'       => $data['session_id'],
            ':title'            => $data['title'],
            ':content_markdown' => $data['content_markdown'],
            ':content_json'     => $data['content_json'],
            ':created_at'       => $data['created_at'],
        ]);
        $stmt2 = $this->pdo->prepare('SELECT * FROM session_snapshots WHERE id = ?');
        $stmt2->execute([$data['id']]);
        return $stmt2->fetch(\PDO::FETCH_ASSOC);
    }
}

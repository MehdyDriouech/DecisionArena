<?php
namespace Infrastructure\Persistence;

class ContextDocumentRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_context_documents WHERE session_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(array $data): array {
        try {
            (new ContextDocumentChunkRepository())->deleteBySession($data['session_id']);
        } catch (\Throwable) {
        }
        $this->pdo->prepare(
            'DELETE FROM session_context_documents WHERE session_id = ?'
        )->execute([$data['session_id']]);

        $stmt = $this->pdo->prepare("
            INSERT INTO session_context_documents
                (id, session_id, title, source_type, original_filename, mime_type,
                 content, character_count, created_at, updated_at)
            VALUES
                (:id, :session_id, :title, :source_type, :original_filename, :mime_type,
                 :content, :character_count, :created_at, :updated_at)
        ");
        $stmt->execute([
            ':id'                => $data['id'],
            ':session_id'        => $data['session_id'],
            ':title'             => $data['title'] ?? null,
            ':source_type'       => $data['source_type'],
            ':original_filename' => $data['original_filename'] ?? null,
            ':mime_type'         => $data['mime_type'] ?? null,
            ':content'           => $data['content'],
            ':character_count'   => $data['character_count'],
            ':created_at'        => $data['created_at'] ?? date('c'),
            ':updated_at'        => date('c'),
        ]);

        try {
            (new ContextDocumentChunkRepository())->reindexSession(
                $data['session_id'],
                (string)$data['content']
            );
        } catch (\Throwable) {
        }

        return $this->findBySession($data['session_id']);
    }

    public function delete(string $sessionId): void {
        try {
            (new ContextDocumentChunkRepository())->deleteBySession($sessionId);
        } catch (\Throwable) {
        }
        $this->pdo->prepare(
            'DELETE FROM session_context_documents WHERE session_id = ?'
        )->execute([$sessionId]);
    }
}

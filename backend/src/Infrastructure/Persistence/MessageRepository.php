<?php
namespace Infrastructure\Persistence;

class MessageRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM messages WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findBySessionAndRound(string $sessionId, int $round): array {
        $stmt = $this->pdo->prepare('SELECT * FROM messages WHERE session_id = ? AND round = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId, $round]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO messages
                (id, session_id, role, agent_id, provider_id, model, round, phase,
                 target_agent_id, mode_context, message_type, content, created_at)
            VALUES
                (:id, :session_id, :role, :agent_id, :provider_id, :model, :round, :phase,
                 :target_agent_id, :mode_context, :message_type, :content, :created_at)
        ');
        $stmt->execute([
            ':id'              => $data['id'],
            ':session_id'      => $data['session_id'],
            ':role'            => $data['role'],
            ':agent_id'        => $data['agent_id'] ?? null,
            ':provider_id'     => $data['provider_id'] ?? null,
            ':model'           => $data['model'] ?? null,
            ':round'           => $data['round'] ?? null,
            ':phase'           => $data['phase'] ?? null,
            ':target_agent_id' => $data['target_agent_id'] ?? null,
            ':mode_context'    => $data['mode_context'] ?? null,
            ':message_type'    => $data['message_type'] ?? null,
            ':content'         => $data['content'],
            ':created_at'      => $data['created_at'],
        ]);
        $stmt2 = $this->pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt2->execute([$data['id']]);
        return $stmt2->fetch(\PDO::FETCH_ASSOC);
    }
}

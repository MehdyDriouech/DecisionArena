<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Persistance du « Situated Agent Chat » (hors sessions messages).
 */
final class AgentContextChatRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function findConversation(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_context_conversations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array{id:string,role:string,content:string,created_at:string}> */
    public function listMessages(string $conversationId, int $limit = 120): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, role, content, created_at FROM agent_context_chat_messages
             WHERE conversation_id = ? ORDER BY created_at ASC, id ASC LIMIT ' . $limit
        );
        $stmt->execute([$conversationId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string)($r['id'] ?? ''),
                'role' => (string)($r['role'] ?? ''),
                'content' => (string)($r['content'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function createConversation(string $id, string $strategicContextId, string $agentId, ?string $linkSessionId): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_context_conversations (id, strategic_context_id, agent_id, link_session_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            strtolower(trim($strategicContextId)),
            strtolower(trim($agentId)),
            $linkSessionId !== null && $linkSessionId !== '' ? $linkSessionId : null,
            $now,
            $now,
        ]);
    }

    public function touchConversation(string $conversationId): void
    {
        $stmt = $this->pdo->prepare('UPDATE agent_context_conversations SET updated_at = ? WHERE id = ?');
        $stmt->execute([date('c'), $conversationId]);
    }

    public function insertMessage(string $id, string $conversationId, string $role, string $content): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO agent_context_chat_messages (id, conversation_id, role, content, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $conversationId, $role, $content, date('c')]);
    }

    /** Suppression ciblée (rollback après échec provider) — pas de cascade conversation. */
    public function deleteMessageById(string $messageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM agent_context_chat_messages WHERE id = ?');
        $stmt->execute([$messageId]);
    }
}

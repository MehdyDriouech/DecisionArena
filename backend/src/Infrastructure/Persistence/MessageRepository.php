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

    /**
     * Merge JSON metadata into an existing message row (Human-in-the-loop challenge trace).
     *
     * @param array<string,mixed> $patch
     */
    public function patchMetaJson(string $messageId, array $patch, ?string $appendChallengeRef = null): void {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $cur = [];
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string)$row['meta_json'], true);
            $cur = is_array($decoded) ? $decoded : [];
        }
        $merged = array_merge($cur, $patch);
        if ($appendChallengeRef !== null && $appendChallengeRef !== '') {
            $refs = $cur['challenge_refs'] ?? [];
            if (!is_array($refs)) {
                $refs = [];
            }
            $refs[] = $appendChallengeRef;
            $merged['challenge_refs'] = array_values(array_unique($refs));
        }
        $upd = $this->pdo->prepare('UPDATE messages SET meta_json = ? WHERE id = ?');
        $upd->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $messageId]);
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO messages
                (id, session_id, role, agent_id,
                 provider_id, provider_name, model,
                 requested_provider_id, requested_model,
                 provider_fallback_used, provider_fallback_reason,
                 round, phase, target_agent_id, mode_context, message_type,
                 thread_type, thread_turn, reaction_role, reactive_thread_id,
                 meta_json,
                 content, created_at)
            VALUES
                (:id, :session_id, :role, :agent_id,
                 :provider_id, :provider_name, :model,
                 :requested_provider_id, :requested_model,
                 :provider_fallback_used, :provider_fallback_reason,
                 :round, :phase, :target_agent_id, :mode_context, :message_type,
                 :thread_type, :thread_turn, :reaction_role, :reactive_thread_id,
                 :meta_json,
                 :content, :created_at)
        ');
        $stmt->execute([
            ':id'                      => $data['id'],
            ':session_id'              => $data['session_id'],
            ':role'                    => $data['role'],
            ':agent_id'                => $data['agent_id'] ?? null,
            ':provider_id'             => $data['provider_id'] ?? null,
            ':provider_name'           => $data['provider_name'] ?? null,
            ':model'                   => $data['model'] ?? null,
            ':requested_provider_id'   => $data['requested_provider_id'] ?? null,
            ':requested_model'         => $data['requested_model'] ?? null,
            ':provider_fallback_used'  => isset($data['provider_fallback_used']) ? (int)$data['provider_fallback_used'] : 0,
            ':provider_fallback_reason'=> $data['provider_fallback_reason'] ?? null,
            ':round'                   => $data['round'] ?? null,
            ':phase'                   => $data['phase'] ?? null,
            ':target_agent_id'         => $data['target_agent_id'] ?? null,
            ':mode_context'            => $data['mode_context'] ?? null,
            ':message_type'            => $data['message_type'] ?? null,
            ':thread_type'             => $data['thread_type'] ?? null,
            ':thread_turn'             => isset($data['thread_turn']) ? (int)$data['thread_turn'] : null,
            ':reaction_role'           => $data['reaction_role'] ?? null,
            ':reactive_thread_id'      => $data['reactive_thread_id'] ?? null,
            ':meta_json'               => $data['meta_json'] ?? null,
            ':content'                 => $data['content'],
            ':created_at'              => $data['created_at'],
        ]);
        $stmt2 = $this->pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt2->execute([$data['id']]);
        return $stmt2->fetch(\PDO::FETCH_ASSOC);
    }
}

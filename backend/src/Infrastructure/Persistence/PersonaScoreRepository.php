<?php
namespace Infrastructure\Persistence;

class PersonaScoreRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_persona_scores WHERE session_id = ? ORDER BY influence_score DESC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows ?: null;
    }

    public function upsert(string $sessionId, array $scores, string $computedAt): void {
        $delete = $this->pdo->prepare('DELETE FROM session_persona_scores WHERE session_id = ?');
        $delete->execute([$sessionId]);

        $stmt = $this->pdo->prepare('
            INSERT INTO session_persona_scores
                (id, session_id, agent_id, message_count, avg_message_length, citation_count, influence_score, dominance, computed_at)
            VALUES
                (:id, :session_id, :agent_id, :message_count, :avg_message_length, :citation_count, :influence_score, :dominance, :computed_at)
        ');

        foreach ($scores as $score) {
            $stmt->execute([
                ':id'                 => $this->uuid(),
                ':session_id'         => $sessionId,
                ':agent_id'           => $score['agent_id'],
                ':message_count'      => $score['message_count'],
                ':avg_message_length' => $score['avg_message_length'],
                ':citation_count'     => $score['citation_count'],
                ':influence_score'    => $score['influence_score'],
                ':dominance'          => $score['dominance'],
                ':computed_at'        => $computedAt,
            ]);
        }
    }

    public function getLastComputedAt(string $sessionId): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT computed_at FROM session_persona_scores WHERE session_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['computed_at'] : null;
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

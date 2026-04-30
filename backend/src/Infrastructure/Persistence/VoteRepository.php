<?php
namespace Infrastructure\Persistence;

class VoteRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findVotesBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM session_votes WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findDecisionBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM session_decisions WHERE session_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['vote_summary'])) {
            $decoded = json_decode($row['vote_summary'], true);
            $row['vote_summary'] = is_array($decoded) ? $decoded : $row['vote_summary'];
        }
        return $row;
    }

    public function createVote(array $vote): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO session_votes
                (id, session_id, round, agent_id, vote, confidence, impact, domain_weight, weight_score, rationale, created_at)
            VALUES
                (:id, :session_id, :round, :agent_id, :vote, :confidence, :impact, :domain_weight, :weight_score, :rationale, :created_at)
        ');
        $stmt->execute([
            ':id' => $vote['id'],
            ':session_id' => $vote['session_id'],
            ':round' => $vote['round'] ?? null,
            ':agent_id' => $vote['agent_id'],
            ':vote' => $vote['vote'],
            ':confidence' => (int)$vote['confidence'],
            ':impact' => (int)$vote['impact'],
            ':domain_weight' => (int)$vote['domain_weight'],
            ':weight_score' => (float)$vote['weight_score'],
            ':rationale' => $vote['rationale'] ?? '',
            ':created_at' => $vote['created_at'],
        ]);
        return $vote;
    }

    public function replaceDecision(string $sessionId, array $decision): array {
        $delete = $this->pdo->prepare('DELETE FROM session_decisions WHERE session_id = ?');
        $delete->execute([$sessionId]);

        $stmt = $this->pdo->prepare('
            INSERT INTO session_decisions
                (id, session_id, decision_label, decision_score, confidence_level, threshold_used, vote_summary, created_at)
            VALUES
                (:id, :session_id, :decision_label, :decision_score, :confidence_level, :threshold_used, :vote_summary, :created_at)
        ');
        $stmt->execute([
            ':id' => $decision['id'],
            ':session_id' => $sessionId,
            ':decision_label' => $decision['decision_label'],
            ':decision_score' => (float)$decision['decision_score'],
            ':confidence_level' => $decision['confidence_level'],
            ':threshold_used' => (float)$decision['threshold_used'],
            ':vote_summary' => json_encode($decision['vote_summary'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_at' => $decision['created_at'],
        ]);
        return $this->findDecisionBySession($sessionId) ?? $decision;
    }

    public function clearSession(string $sessionId): void {
        $stmt1 = $this->pdo->prepare('DELETE FROM session_votes WHERE session_id = ?');
        $stmt1->execute([$sessionId]);
        $stmt2 = $this->pdo->prepare('DELETE FROM session_decisions WHERE session_id = ?');
        $stmt2->execute([$sessionId]);
    }
}

<?php
namespace Infrastructure\Persistence;

class VerdictRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM session_verdicts WHERE session_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO session_verdicts
                (id, session_id, verdict_label, verdict_summary, feasibility_score,
                 product_value_score, ux_score, risk_score, confidence_score,
                 recommended_action, created_at)
            VALUES
                (:id, :session_id, :verdict_label, :verdict_summary, :feasibility_score,
                 :product_value_score, :ux_score, :risk_score, :confidence_score,
                 :recommended_action, :created_at)
        ');
        $stmt->execute([
            ':id'                  => $data['id'],
            ':session_id'          => $data['session_id'],
            ':verdict_label'       => $data['verdict_label'],
            ':verdict_summary'     => $data['verdict_summary'],
            ':feasibility_score'   => $data['feasibility_score'] ?? null,
            ':product_value_score' => $data['product_value_score'] ?? null,
            ':ux_score'            => $data['ux_score'] ?? null,
            ':risk_score'          => $data['risk_score'] ?? null,
            ':confidence_score'    => $data['confidence_score'] ?? null,
            ':recommended_action'  => $data['recommended_action'] ?? null,
            ':created_at'          => $data['created_at'] ?? date('c'),
        ]);
        return $this->findBySession($data['session_id']);
    }
}

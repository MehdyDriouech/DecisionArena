<?php
namespace Infrastructure\Persistence;

class DebateRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findArgumentsBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM arguments WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findPositionsBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_positions WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findEdgesBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM interaction_edges WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function createArgument(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO arguments
                (id, session_id, round, agent_id, argument_text, argument_type, target_argument_id, strength, created_at)
            VALUES
                (:id, :session_id, :round, :agent_id, :argument_text, :argument_type, :target_argument_id, :strength, :created_at)
        ');
        $stmt->execute([
            ':id'                 => $data['id'],
            ':session_id'         => $data['session_id'],
            ':round'              => $data['round'],
            ':agent_id'           => $data['agent_id'],
            ':argument_text'      => $data['argument_text'],
            ':argument_type'      => $data['argument_type'],
            ':target_argument_id' => $data['target_argument_id'] ?? null,
            ':strength'           => $data['strength'] ?? 1,
            ':created_at'         => $data['created_at'],
        ]);
        return $this->findArgumentById($data['id']) ?? $data;
    }

    public function createPosition(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO agent_positions
                (id, session_id, round, agent_id, stance, confidence, impact, domain_weight, weight_score,
                 main_argument, biggest_risk, change_since_last_round, created_at)
            VALUES
                (:id, :session_id, :round, :agent_id, :stance, :confidence, :impact, :domain_weight, :weight_score,
                 :main_argument, :biggest_risk, :change_since_last_round, :created_at)
        ');
        $stmt->execute([
            ':id'                      => $data['id'],
            ':session_id'              => $data['session_id'],
            ':round'                   => $data['round'],
            ':agent_id'                => $data['agent_id'],
            ':stance'                  => $data['stance'],
            ':confidence'              => $data['confidence'],
            ':impact'                  => $data['impact'],
            ':domain_weight'           => $data['domain_weight'],
            ':weight_score'            => $data['weight_score'],
            ':main_argument'           => $data['main_argument'] ?? '',
            ':biggest_risk'            => $data['biggest_risk'] ?? '',
            ':change_since_last_round' => $data['change_since_last_round'] ?? '',
            ':created_at'              => $data['created_at'],
        ]);
        return $this->findPositionById($data['id']) ?? $data;
    }

    public function createEdge(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO interaction_edges
                (id, session_id, round, source_agent_id, target_agent_id, edge_type, weight, argument_id, created_at)
            VALUES
                (:id, :session_id, :round, :source_agent_id, :target_agent_id, :edge_type, :weight, :argument_id, :created_at)
        ');
        $stmt->execute([
            ':id'              => $data['id'],
            ':session_id'      => $data['session_id'],
            ':round'           => $data['round'],
            ':source_agent_id' => $data['source_agent_id'],
            ':target_agent_id' => $data['target_agent_id'],
            ':edge_type'       => $data['edge_type'] ?? 'neutral',
            ':weight'          => $data['weight'] ?? 1,
            ':argument_id'     => $data['argument_id'] ?? null,
            ':created_at'      => $data['created_at'],
        ]);
        return $this->findEdgeById($data['id']) ?? $data;
    }

    private function findArgumentById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM arguments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findPositionById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM agent_positions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findEdgeById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM interaction_edges WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

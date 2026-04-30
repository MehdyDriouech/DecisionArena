<?php
namespace Infrastructure\Persistence;

class SessionAgentProvidersRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    /**
     * Returns an array keyed by agent_id: ['pm' => ['provider_id' => '...', 'model' => '...'], ...]
     */
    public function findBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare(
            'SELECT agent_id, provider_id, model FROM session_agent_providers WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['agent_id']] = [
                'provider_id' => $row['provider_id'],
                'model'       => $row['model'],
            ];
        }
        return $result;
    }

    /**
     * Delete all overrides for the session, then insert each entry from $agentProviders.
     * $agentProviders format: ['pm' => ['provider_id' => '...', 'model' => '...'], ...]
     */
    public function saveForSession(string $sessionId, array $agentProviders): void {
        $deleteStmt = $this->pdo->prepare(
            'DELETE FROM session_agent_providers WHERE session_id = ?'
        );
        $deleteStmt->execute([$sessionId]);

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO session_agent_providers (id, session_id, agent_id, provider_id, model)
             VALUES (:id, :session_id, :agent_id, :provider_id, :model)'
        );

        foreach ($agentProviders as $agentId => $overrides) {
            $insertStmt->execute([
                ':id'          => $this->uuid(),
                ':session_id'  => $sessionId,
                ':agent_id'    => (string)$agentId,
                ':provider_id' => $overrides['provider_id'] ?? null,
                ':model'       => $overrides['model'] ?? null,
            ]);
        }
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

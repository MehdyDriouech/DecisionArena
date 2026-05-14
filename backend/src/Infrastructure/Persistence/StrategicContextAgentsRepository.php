<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Présence explicite d'un agent dans un contexte stratégique (ex. ajout OpenSpace).
 */
final class StrategicContextAgentsRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function exists(string $contextId, string $agentId): bool
    {
        $contextId = strtolower(trim($contextId));
        $agentId = strtolower(trim($agentId));
        if ($contextId === '' || $agentId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM strategic_context_agents WHERE context_id = :c AND agent_id = :a LIMIT 1'
        );
        $stmt->execute([':c' => $contextId, ':a' => $agentId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string> ids agents en minuscules
     */
    public function listAgentIds(string $contextId): array
    {
        $contextId = strtolower(trim($contextId));
        if ($contextId === '') {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT agent_id FROM strategic_context_agents WHERE context_id = :c ORDER BY created_at ASC, agent_id ASC'
        );
        $stmt->execute([':c' => $contextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
            if ($aid !== '') {
                $out[] = $aid;
            }
        }

        return $out;
    }

    /**
     * @return bool true si insertion, false si déjà présent
     */
    public function insert(string $contextId, string $agentId, string $source = 'manual'): bool
    {
        $contextId = strtolower(trim($contextId));
        $agentId = strtolower(trim($agentId));
        $source = trim($source) !== '' ? trim($source) : 'manual';
        if ($contextId === '' || $agentId === '') {
            return false;
        }
        if ($this->exists($contextId, $agentId)) {
            return false;
        }
        $created = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO strategic_context_agents (context_id, agent_id, source, created_at) VALUES (:c,:a,:s,:t)'
        );
        $stmt->execute([':c' => $contextId, ':a' => $agentId, ':s' => $source, ':t' => $created]);

        return true;
    }
}

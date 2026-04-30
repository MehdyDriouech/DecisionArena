<?php
namespace Infrastructure\Persistence;

class TemplateRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findAll(): array {
        $stmt = $this->pdo->query('SELECT * FROM session_templates WHERE enabled = 1 ORDER BY source DESC, name ASC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRow'], $rows);
    }

    public function findAllAdmin(): array {
        $stmt = $this->pdo->query('SELECT * FROM session_templates ORDER BY source DESC, name ASC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRow'], $rows);
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM session_templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodeRow($row) : null;
    }

    public function save(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO session_templates
                (id, name, description, mode, selected_agents, rounds, force_disagreement,
                 interaction_style, reply_policy, final_synthesis, prompt_starter,
                 expected_output, notes, source, enabled, created_at, updated_at)
            VALUES
                (:id, :name, :description, :mode, :selected_agents, :rounds, :force_disagreement,
                 :interaction_style, :reply_policy, :final_synthesis, :prompt_starter,
                 :expected_output, :notes, :source, :enabled, :created_at, :updated_at)
        ');
        $existing = $this->findById($data['id']);
        $stmt->execute([
            ':id'                => $data['id'],
            ':name'              => $data['name'],
            ':description'       => $data['description'] ?? null,
            ':mode'              => $data['mode'],
            ':selected_agents'   => is_array($data['selected_agents']) ? json_encode($data['selected_agents']) : ($data['selected_agents'] ?? '[]'),
            ':rounds'            => $data['rounds'] ?? 2,
            ':force_disagreement'=> $data['force_disagreement'] ? 1 : 0,
            ':interaction_style' => $data['interaction_style'] ?? null,
            ':reply_policy'      => $data['reply_policy'] ?? null,
            ':final_synthesis'   => $data['final_synthesis'] ? 1 : 0,
            ':prompt_starter'    => $data['prompt_starter'] ?? null,
            ':expected_output'   => $data['expected_output'] ?? null,
            ':notes'             => $data['notes'] ?? null,
            ':source'            => $data['source'] ?? 'custom',
            ':enabled'           => isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
            ':created_at'        => $existing['created_at'] ?? ($data['created_at'] ?? date('c')),
            ':updated_at'        => date('c'),
        ]);
        return $this->findById($data['id']);
    }

    public function delete(string $id): bool {
        $template = $this->findById($id);
        if (!$template) return false;
        if ($template['source'] === 'system') return false;
        $stmt = $this->pdo->prepare('DELETE FROM session_templates WHERE id = ?');
        $stmt->execute([$id]);
        return true;
    }

    private function decodeRow(array $row): array {
        $row['selected_agents']   = json_decode($row['selected_agents'] ?? '[]', true) ?? [];
        $row['force_disagreement'] = (bool)$row['force_disagreement'];
        $row['final_synthesis']    = (bool)$row['final_synthesis'];
        $row['enabled']            = (bool)$row['enabled'];
        return $row;
    }
}

<?php
namespace Infrastructure\Persistence;

class SessionRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findAll(): array {
        $stmt = $this->pdo->query('SELECT * FROM sessions ORDER BY created_at DESC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRow'], $rows);
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodeRow($row) : null;
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions
                (id, title, mode, initial_prompt, selected_agents, rounds, language,
                 status, cf_rounds, cf_interaction_style, cf_reply_policy,
                 is_favorite, is_reference, force_disagreement,
                 parent_session_id, rerun_reason,
                 created_at, updated_at)
            VALUES
                (:id, :title, :mode, :initial_prompt, :selected_agents, :rounds, :language,
                 :status, :cf_rounds, :cf_interaction_style, :cf_reply_policy,
                 :is_favorite, :is_reference, :force_disagreement,
                 :parent_session_id, :rerun_reason,
                 :created_at, :updated_at)
        ');
        $stmt->execute([
            ':id'                   => $data['id'],
            ':title'                => $data['title'],
            ':mode'                 => $data['mode'] ?? 'chat',
            ':initial_prompt'       => $data['initial_prompt'] ?? '',
            ':selected_agents'      => is_array($data['selected_agents'])
                                        ? json_encode($data['selected_agents'])
                                        : ($data['selected_agents'] ?? '[]'),
            ':rounds'               => $data['rounds'] ?? 2,
            ':language'             => $data['language'] ?? 'en',
            ':status'               => $data['status'] ?? 'draft',
            ':cf_rounds'            => $data['cf_rounds'] ?? 3,
            ':cf_interaction_style' => $data['cf_interaction_style'] ?? 'sequential',
            ':cf_reply_policy'      => $data['cf_reply_policy'] ?? 'all-agents-reply',
            ':is_favorite'          => $data['is_favorite'] ?? 0,
            ':is_reference'         => $data['is_reference'] ?? 0,
            ':force_disagreement'   => $data['force_disagreement'] ?? 0,
            ':parent_session_id'    => $data['parent_session_id'] ?? null,
            ':rerun_reason'         => $data['rerun_reason'] ?? null,
            ':created_at'           => $data['created_at'],
            ':updated_at'           => $data['updated_at'],
        ]);
        return $this->findById($data['id']);
    }

    public function update(string $id, array $data): void {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = :$key";
            $params[":$key"] = $value;
        }
        $params[':id'] = $id;
        $params[':updated_at'] = date('c');
        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE sessions SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    private function decodeRow(array $row): array {
        $row['selected_agents'] = json_decode($row['selected_agents'] ?? '[]', true) ?? [];
        return $row;
    }
}

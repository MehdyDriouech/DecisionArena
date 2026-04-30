<?php
namespace Infrastructure\Persistence;

class ScenarioPackRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findAll(bool $enabledOnly = true): array {
        $sql  = $enabledOnly
            ? 'SELECT * FROM scenario_persona_packs WHERE enabled = 1 ORDER BY source DESC, name ASC'
            : 'SELECT * FROM scenario_persona_packs ORDER BY source DESC, name ASC';
        $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRow'], $rows);
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM scenario_persona_packs WHERE id = ?');
        $stmt->execute([$id]);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodeRow($row) : null;
    }

    public function save(array $data): array {
        $existing = $this->findById($data['id']);
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO scenario_persona_packs
                (id, name, description, target_profile, scenario_type, recommended_mode,
                 persona_ids, rounds, force_disagreement, decision_threshold,
                 prompt_starter, max_personas, enabled, source, created_at, updated_at)
            VALUES
                (:id, :name, :description, :target_profile, :scenario_type, :recommended_mode,
                 :persona_ids, :rounds, :force_disagreement, :decision_threshold,
                 :prompt_starter, :max_personas, :enabled, :source, :created_at, :updated_at)
        ");
        $stmt->execute([
            ':id'                 => $data['id'],
            ':name'               => $data['name'],
            ':description'        => $data['description'] ?? null,
            ':target_profile'     => $data['target_profile'] ?? null,
            ':scenario_type'      => $data['scenario_type'] ?? null,
            ':recommended_mode'   => $data['recommended_mode'],
            ':persona_ids'        => is_array($data['persona_ids']) ? json_encode($data['persona_ids']) : ($data['persona_ids'] ?? '[]'),
            ':rounds'             => (int)($data['rounds'] ?? 2),
            ':force_disagreement' => $data['force_disagreement'] ? 1 : 0,
            ':decision_threshold' => (float)($data['decision_threshold'] ?? 0.55),
            ':prompt_starter'     => $data['prompt_starter'] ?? null,
            ':max_personas'       => isset($data['max_personas']) ? (int)$data['max_personas'] : null,
            ':enabled'            => isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
            ':source'             => $data['source'] ?? 'custom',
            ':created_at'         => $existing['created_at'] ?? ($data['created_at'] ?? date('c')),
            ':updated_at'         => date('c'),
        ]);
        return $this->findById($data['id']);
    }

    public function delete(string $id): bool {
        $pack = $this->findById($id);
        if (!$pack) return false;
        if ($pack['source'] === 'system') return false;
        $stmt = $this->pdo->prepare('DELETE FROM scenario_persona_packs WHERE id = ?');
        $stmt->execute([$id]);
        return true;
    }

    private function decodeRow(array $row): array {
        $row['persona_ids']        = json_decode($row['persona_ids'] ?? '[]', true) ?? [];
        $row['force_disagreement'] = (bool)$row['force_disagreement'];
        $row['enabled']            = (bool)$row['enabled'];
        $row['rounds']             = (int)$row['rounds'];
        $row['decision_threshold'] = (float)$row['decision_threshold'];
        if (isset($row['max_personas'])) {
            $row['max_personas'] = $row['max_personas'] !== null ? (int)$row['max_personas'] : null;
        }
        return $row;
    }
}

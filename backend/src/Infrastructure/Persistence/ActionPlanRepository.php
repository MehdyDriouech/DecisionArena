<?php
namespace Infrastructure\Persistence;

class ActionPlanRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_action_plans WHERE session_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->decodeRow($row) : null;
    }

    public function create(array $data): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO session_action_plans
                (id, session_id, source_message_id, summary, immediate_actions,
                 short_term_actions, experiments, risks_to_monitor, owner_notes,
                 created_at, updated_at)
            VALUES
                (:id, :session_id, :source_message_id, :summary, :immediate_actions,
                 :short_term_actions, :experiments, :risks_to_monitor, :owner_notes,
                 :created_at, :updated_at)
        ');
        $stmt->execute([
            ':id'                => $data['id'],
            ':session_id'        => $data['session_id'],
            ':source_message_id' => $data['source_message_id'] ?? null,
            ':summary'           => $data['summary'] ?? null,
            ':immediate_actions' => is_array($data['immediate_actions'] ?? null)
                                        ? json_encode($data['immediate_actions'])
                                        : ($data['immediate_actions'] ?? null),
            ':short_term_actions'=> is_array($data['short_term_actions'] ?? null)
                                        ? json_encode($data['short_term_actions'])
                                        : ($data['short_term_actions'] ?? null),
            ':experiments'       => is_array($data['experiments'] ?? null)
                                        ? json_encode($data['experiments'])
                                        : ($data['experiments'] ?? null),
            ':risks_to_monitor'  => is_array($data['risks_to_monitor'] ?? null)
                                        ? json_encode($data['risks_to_monitor'])
                                        : ($data['risks_to_monitor'] ?? null),
            ':owner_notes'       => $data['owner_notes'] ?? null,
            ':created_at'        => $data['created_at'],
            ':updated_at'        => $data['updated_at'],
        ]);
        return $this->findBySession($data['session_id']);
    }

    public function update(string $id, array $data): void {
        $sets   = [];
        $params = [];
        $allowed = ['summary', 'immediate_actions', 'short_term_actions', 'experiments', 'risks_to_monitor', 'owner_notes'];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;
            $sets[]       = "$field = :$field";
            $val          = $data[$field];
            $params[":$field"] = is_array($val) ? json_encode($val) : $val;
        }
        if (empty($sets)) return;
        $params[':id']         = $id;
        $params[':updated_at'] = date('c');
        $sets[] = 'updated_at = :updated_at';
        $this->pdo->prepare('UPDATE session_action_plans SET ' . implode(', ', $sets) . ' WHERE id = :id')
                  ->execute($params);
    }

    private function decodeRow(array $row): array {
        foreach (['immediate_actions', 'short_term_actions', 'experiments', 'risks_to_monitor'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            } else {
                $row[$field] = [];
            }
        }
        return $row;
    }
}

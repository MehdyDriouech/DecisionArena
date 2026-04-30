<?php
namespace Infrastructure\Persistence;

class ProviderRoutingSettingsRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function get(): array {
        $stmt = $this->pdo->prepare('SELECT * FROM provider_routing_settings WHERE id = ?');
        $stmt->execute(['default']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $now = date('c');
            $this->pdo->prepare("
                INSERT INTO provider_routing_settings
                    (id, routing_mode, primary_provider_id, preferred_provider_id, fallback_provider_ids, load_balance_strategy, created_at, updated_at)
                VALUES
                    (:id, :routing_mode, :primary_provider_id, :preferred_provider_id, :fallback_provider_ids, :load_balance_strategy, :created_at, :updated_at)
            ")->execute([
                ':id' => 'default',
                ':routing_mode' => 'single-primary',
                ':primary_provider_id' => null,
                ':preferred_provider_id' => null,
                ':fallback_provider_ids' => json_encode([]),
                ':load_balance_strategy' => 'round-robin',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            return $this->get();
        }
        return $this->hydrate($row);
    }

    public function update(array $settings): array {
        $current = $this->get();
        $now = date('c');

        $merged = array_merge($current, $settings);
        $fallbackJson = json_encode(array_values($merged['fallback_provider_ids'] ?? []));

        $stmt = $this->pdo->prepare("
            UPDATE provider_routing_settings
            SET routing_mode = :routing_mode,
                primary_provider_id = :primary_provider_id,
                preferred_provider_id = :preferred_provider_id,
                fallback_provider_ids = :fallback_provider_ids,
                load_balance_strategy = :load_balance_strategy,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => 'default',
            ':routing_mode' => $merged['routing_mode'] ?? 'single-primary',
            ':primary_provider_id' => $merged['primary_provider_id'] ?? null,
            ':preferred_provider_id' => $merged['preferred_provider_id'] ?? null,
            ':fallback_provider_ids' => $fallbackJson,
            ':load_balance_strategy' => $merged['load_balance_strategy'] ?? 'round-robin',
            ':updated_at' => $now,
        ]);

        return $this->get();
    }

    private function hydrate(array $row): array {
        $fallback = [];
        if (!empty($row['fallback_provider_ids'])) {
            $decoded = json_decode((string)$row['fallback_provider_ids'], true);
            if (is_array($decoded)) $fallback = array_values($decoded);
        }

        return [
            'routing_mode' => (string)($row['routing_mode'] ?? 'single-primary'),
            'primary_provider_id' => $row['primary_provider_id'] !== null ? (string)$row['primary_provider_id'] : null,
            'preferred_provider_id' => $row['preferred_provider_id'] !== null ? (string)$row['preferred_provider_id'] : null,
            'fallback_provider_ids' => $fallback,
            'load_balance_strategy' => (string)($row['load_balance_strategy'] ?? 'round-robin'),
        ];
    }
}


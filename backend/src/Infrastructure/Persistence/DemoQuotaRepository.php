<?php
namespace Infrastructure\Persistence;

class DemoQuotaRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function getCount(string $userId, string $usageDate): int {
        $stmt = $this->pdo->prepare(
            'SELECT count FROM demo_llm_usage WHERE user_id = ? AND usage_date = ?'
        );
        $stmt->execute([$userId, $usageDate]);
        $n = $stmt->fetchColumn();
        return $n === false ? 0 : (int)$n;
    }

    public function increment(string $userId, string $usageDate): int {
        $now = date('c');
        $this->pdo->prepare('
            INSERT INTO demo_llm_usage (user_id, usage_date, count, updated_at)
            VALUES (?, ?, 1, ?)
            ON CONFLICT(user_id, usage_date) DO UPDATE SET
                count = count + 1,
                updated_at = excluded.updated_at
        ')->execute([$userId, $usageDate, $now]);
        return $this->getCount($userId, $usageDate);
    }
}

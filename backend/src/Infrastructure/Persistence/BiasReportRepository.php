<?php
namespace Infrastructure\Persistence;

class BiasReportRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_bias_reports WHERE session_id = ? ORDER BY computed_at DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        $decoded = json_decode($row['bias_report_json'] ?? '{}', true);
        return is_array($decoded) ? $decoded : null;
    }

    public function upsert(string $sessionId, array $report, string $computedAt): void {
        $delete = $this->pdo->prepare('DELETE FROM session_bias_reports WHERE session_id = ?');
        $delete->execute([$sessionId]);

        $stmt = $this->pdo->prepare('
            INSERT INTO session_bias_reports (id, session_id, bias_report_json, computed_at)
            VALUES (:id, :session_id, :bias_report_json, :computed_at)
        ');
        $stmt->execute([
            ':id'               => $this->uuid(),
            ':session_id'       => $sessionId,
            ':bias_report_json' => json_encode($report, JSON_UNESCAPED_UNICODE),
            ':computed_at'      => $computedAt,
        ]);
    }

    public function getLastComputedAt(string $sessionId): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT computed_at FROM session_bias_reports WHERE session_id = ? ORDER BY computed_at DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['computed_at'] : null;
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

<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

class RiskProfileRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function save(string $sessionId, array $report): void
    {
        $now  = date('c');
        $json = json_encode($report, JSON_UNESCAPED_UNICODE);

        $cats = json_encode($report['risk_categories'] ?? [], JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare("
            INSERT INTO session_risk_profiles
              (session_id, risk_level, reversibility, risk_categories_json,
               estimated_error_cost, recommended_threshold, required_process,
               report_json, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(session_id) DO UPDATE
              SET risk_level            = excluded.risk_level,
                  reversibility         = excluded.reversibility,
                  risk_categories_json  = excluded.risk_categories_json,
                  estimated_error_cost  = excluded.estimated_error_cost,
                  recommended_threshold = excluded.recommended_threshold,
                  required_process      = excluded.required_process,
                  report_json           = excluded.report_json,
                  updated_at            = excluded.updated_at
        ");
        $stmt->execute([
            $sessionId,
            (string)($report['risk_level']           ?? 'medium'),
            (string)($report['reversibility']         ?? 'moderate'),
            $cats,
            (string)($report['estimated_error_cost']  ?? 'unknown'),
            isset($report['recommended_threshold']) ? (float)$report['recommended_threshold'] : null,
            (string)($report['required_process']      ?? 'standard'),
            $json,
            $now,
            $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findBySession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT report_json FROM session_risk_profiles WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)$row['report_json'], true);
        return is_array($decoded) ? $decoded : null;
    }
}

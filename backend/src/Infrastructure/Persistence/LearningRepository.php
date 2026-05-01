<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

class LearningRepository
{
    private \PDO $pdo;

    /** Minimum sessions with postmortems before showing analytics. */
    public const MIN_SESSIONS_FOR_INSIGHTS = 5;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->pdo();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS learning_insights_cache (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              scope TEXT NOT NULL,
              scope_id TEXT,
              report_json TEXT NOT NULL,
              computed_at TEXT NOT NULL,
              UNIQUE(scope, scope_id)
            )
        ");
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    public function saveCache(string $scope, ?string $scopeId, array $report): void
    {
        $now  = date('c');
        $json = json_encode($report, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("
            INSERT INTO learning_insights_cache (scope, scope_id, report_json, computed_at)
            VALUES (?,?,?,?)
            ON CONFLICT(scope, scope_id) DO UPDATE
              SET report_json = excluded.report_json,
                  computed_at = excluded.computed_at
        ");
        $stmt->execute([$scope, $scopeId, $json, $now]);
    }

    public function findCache(string $scope, ?string $scopeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT report_json FROM learning_insights_cache WHERE scope = ? AND scope_id IS ?'
        );
        $stmt->execute([$scope, $scopeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)$row['report_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function invalidateAll(): void
    {
        $this->pdo->exec('DELETE FROM learning_insights_cache');
    }

    // ── Raw data queries ──────────────────────────────────────────────────────

    /**
     * Returns all sessions that have a postmortem, enriched with session fields.
     * @return list<array<string,mixed>>
     */
    public function findSessionsWithPostmortems(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT
                    s.id               AS session_id,
                    s.mode,
                    s.selected_agents,
                    s.decision_threshold,
                    s.context_quality_level,
                    s.context_quality_score,
                    s.reliability_cap,
                    s.created_at       AS session_created_at,
                    p.outcome,
                    p.confidence_in_retrospect,
                    p.notes
                FROM sessions s
                INNER JOIN session_postmortems p ON p.session_id = s.id
                ORDER BY s.created_at DESC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Returns final decision rows (from session_decisions) joined with postmortems.
     * @return list<array<string,mixed>>
     */
    public function findDecisionsWithPostmortems(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT
                    sd.session_id,
                    sd.decision_label,
                    sd.decision_score,
                    sd.confidence_level,
                    p.outcome
                FROM session_decisions sd
                INNER JOIN session_postmortems p ON p.session_id = sd.session_id
                ORDER BY sd.session_id DESC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function countPostmortems(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM session_postmortems');
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

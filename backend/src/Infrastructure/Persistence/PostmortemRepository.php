<?php
namespace Infrastructure\Persistence;

class PostmortemRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_postmortems (
                id                       TEXT PRIMARY KEY,
                session_id               TEXT NOT NULL UNIQUE,
                outcome                  TEXT NOT NULL,
                confidence_in_retrospect REAL NOT NULL DEFAULT 0,
                notes                    TEXT,
                created_at               TEXT NOT NULL
            )
        ");
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_postmortems WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): array {
        // Replace if already exists
        $existing = $this->findBySession($data['session_id']);
        if ($existing) {
            $del = $this->pdo->prepare('DELETE FROM session_postmortems WHERE session_id = ?');
            $del->execute([$data['session_id']]);
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO session_postmortems
                (id, session_id, outcome, confidence_in_retrospect, notes, created_at)
            VALUES
                (:id, :session_id, :outcome, :confidence_in_retrospect, :notes, :created_at)
        ');
        $stmt->execute([
            ':id'                       => $data['id'],
            ':session_id'               => $data['session_id'],
            ':outcome'                  => $data['outcome'],
            ':confidence_in_retrospect' => (float)($data['confidence_in_retrospect'] ?? 0),
            ':notes'                    => $data['notes'] ?? null,
            ':created_at'               => $data['created_at'],
        ]);

        return $this->findBySession($data['session_id']);
    }

    public function getStats(): array {
        // Total counts by outcome
        $stmt = $this->pdo->query(
            "SELECT outcome, COUNT(*) as cnt FROM session_postmortems GROUP BY outcome"
        );
        $outcomeCounts = [];
        $total = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $outcomeCounts[$row['outcome']] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
        }

        $correct   = $outcomeCounts['correct']   ?? 0;
        $incorrect = $outcomeCounts['incorrect'] ?? 0;
        $partial   = $outcomeCounts['partial']   ?? 0;

        // By mode — join postmortems → sessions
        $byMode = [];
        try {
            $stmt2 = $this->pdo->query("
                SELECT s.mode, p.outcome, COUNT(*) as cnt
                FROM session_postmortems p
                JOIN sessions s ON s.id = p.session_id
                GROUP BY s.mode, p.outcome
            ");
            foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $key = str_replace('-', '_', $row['mode'] ?? 'unknown');
                if (!isset($byMode[$key])) {
                    $byMode[$key] = ['correct' => 0, 'total' => 0];
                }
                $byMode[$key]['total'] += (int)$row['cnt'];
                if ($row['outcome'] === 'correct') {
                    $byMode[$key]['correct'] += (int)$row['cnt'];
                }
            }
        } catch (\Throwable $e) {
            $byMode = [];
        }

        // By agent — parse selected_agents from sessions
        $byAgent = [];
        try {
            $stmt3 = $this->pdo->query("
                SELECT s.selected_agents, p.outcome
                FROM session_postmortems p
                JOIN sessions s ON s.id = p.session_id
            ");
            foreach ($stmt3->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $agents = json_decode($row['selected_agents'] ?? '[]', true) ?? [];
                foreach ($agents as $agentId) {
                    $agentKey = is_string($agentId) ? $agentId : (string)$agentId;
                    if (!isset($byAgent[$agentKey])) {
                        $byAgent[$agentKey] = ['sessions_rated' => 0, 'correct_sessions' => 0];
                    }
                    $byAgent[$agentKey]['sessions_rated']++;
                    if ($row['outcome'] === 'correct') {
                        $byAgent[$agentKey]['correct_sessions']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            $byAgent = [];
        }

        return [
            'total'     => $total,
            'correct'   => $correct,
            'incorrect' => $incorrect,
            'partial'   => $partial,
            'by_mode'   => $byMode,
            'by_agent'  => $byAgent,
        ];
    }
}

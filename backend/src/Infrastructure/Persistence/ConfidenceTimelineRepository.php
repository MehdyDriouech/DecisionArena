<?php
namespace Infrastructure\Persistence;

class ConfidenceTimelineRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_confidence_timeline (
                id                        TEXT PRIMARY KEY,
                session_id                TEXT NOT NULL,
                round                     INTEGER NOT NULL,
                confidence                REAL NOT NULL,
                dominant_position         TEXT NOT NULL,
                consensus_forming         INTEGER NOT NULL DEFAULT 0,
                computed_at               TEXT NOT NULL
            )
        ");
    }

    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM session_confidence_timeline WHERE session_id = ? ORDER BY round ASC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows ?: null;
    }

    public function upsertRounds(string $sessionId, array $rounds, string $computedAt): void {
        $delete = $this->pdo->prepare(
            'DELETE FROM session_confidence_timeline WHERE session_id = ?'
        );
        $delete->execute([$sessionId]);

        $insert = $this->pdo->prepare('
            INSERT INTO session_confidence_timeline
                (id, session_id, round, confidence, dominant_position, consensus_forming, computed_at)
            VALUES
                (:id, :session_id, :round, :confidence, :dominant_position, :consensus_forming, :computed_at)
        ');

        foreach ($rounds as $r) {
            $insert->execute([
                ':id'               => bin2hex(random_bytes(8)),
                ':session_id'       => $sessionId,
                ':round'            => $r['round'],
                ':confidence'       => $r['confidence'],
                ':dominant_position'=> $r['dominant_position'],
                ':consensus_forming'=> $r['consensus_forming'] ? 1 : 0,
                ':computed_at'      => $computedAt,
            ]);
        }
    }

    public function getLastComputedAt(string $sessionId): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT computed_at FROM session_confidence_timeline WHERE session_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['computed_at'] : null;
    }

    public function clearSession(string $sessionId): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM session_confidence_timeline WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
    }
}

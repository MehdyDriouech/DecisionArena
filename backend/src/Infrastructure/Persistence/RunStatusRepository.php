<?php
namespace Infrastructure\Persistence;

class RunStatusRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->ensureColumn();
    }

    public function load(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT run_status FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)($row['run_status'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function save(string $sessionId, array $payload): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET run_status = :run_status WHERE id = :id');
        $stmt->execute([
            ':id' => $sessionId,
            ':run_status' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    public function initialize(string $sessionId, string $mode, int $totalRounds): void
    {
        $now = date('c');
        $this->save($sessionId, [
            'session_id' => $sessionId,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'elapsed_seconds' => 0,
            'progress' => [
                'percent' => 1,
                'current_round' => 0,
                'total_rounds' => max(1, $totalRounds),
                'current_phase' => 'session_started',
                'current_phase_label' => 'Session demarree',
                'current_team' => null,
                'current_agent_id' => null,
                'current_agent_name' => null,
                'current_step' => 'startup',
                'estimated' => true,
            ],
            'events' => [],
            'last_message_at' => null,
            'last_error' => null,
        ]);
    }

    public function appendEvent(
        string $sessionId,
        array $event,
        array $progressPatch = [],
        ?string $status = null,
        ?string $lastError = null
    ): void {
        $current = $this->load($sessionId) ?? [];
        $startedAt = (string)($current['started_at'] ?? date('c'));
        $events = isset($current['events']) && is_array($current['events']) ? $current['events'] : [];
        $eventTs = (string)($event['ts'] ?? date('c'));

        $events[] = array_merge([
            'ts' => $eventTs,
            'level' => 'info',
            'phase' => null,
            'round' => null,
            'team' => null,
            'agent_id' => null,
            'label' => '',
            'metadata' => [],
        ], $event);

        if (count($events) > 120) {
            $events = array_slice($events, -120);
        }

        $progress = isset($current['progress']) && is_array($current['progress']) ? $current['progress'] : [];
        $progress = array_merge($progress, $progressPatch);
        if (isset($progress['percent'])) {
            $pct = (int)$progress['percent'];
            $progress['percent'] = max(0, min(99, $pct));
        }
        if (!array_key_exists('estimated', $progress)) {
            $progress['estimated'] = true;
        }

        $now = date('c');
        $payload = array_merge($current, [
            'session_id' => $sessionId,
            'status' => $status ?? (string)($current['status'] ?? 'running'),
            'started_at' => $startedAt,
            'updated_at' => $now,
            'completed_at' => ($status === 'completed' || $status === 'failed' || $status === 'blocked')
                ? ($current['completed_at'] ?? $now)
                : ($current['completed_at'] ?? null),
            'elapsed_seconds' => $this->elapsedSeconds($startedAt, $now),
            'events' => $events,
            'progress' => $progress,
            'last_error' => $lastError,
        ]);

        if ($status === 'completed' && isset($payload['progress']) && is_array($payload['progress'])) {
            $payload['progress']['percent'] = 100;
        }
        $this->save($sessionId, $payload);
    }

    private function ensureColumn(): void
    {
        try {
            $stmt = $this->pdo->query("PRAGMA table_info('sessions')");
            $columns = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            foreach ($columns as $col) {
                if (($col['name'] ?? '') === 'run_status') {
                    return;
                }
            }
            $this->pdo->exec('ALTER TABLE sessions ADD COLUMN run_status TEXT DEFAULT NULL');
        } catch (\Throwable $e) {
            // Best effort only.
        }
    }

    private function elapsedSeconds(string $startedAt, string $updatedAt): int
    {
        $startTs = strtotime($startedAt);
        $endTs = strtotime($updatedAt);
        if ($startTs === false || $endTs === false || $endTs < $startTs) {
            return 0;
        }
        return (int)($endTs - $startTs);
    }
}

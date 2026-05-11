<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Persistance de la Strategic Narrative (une ligne courante par strategic_context_id).
 */
final class StrategicContextNarrativeRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return ?array<string,mixed> ligne brute SQLite */
    public function findByContextId(string $contextId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM strategic_context_narratives WHERE strategic_context_id = ?');
        $stmt->execute([$contextId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param array{
     *   current_direction:string,
     *   major_risks:array,
     *   unresolved_conflicts:array,
     *   confidence_trend:string,
     *   key_assumptions:array,
     *   recent_shifts:array,
     *   source_summary:array,
     *   computed_at:string
     * } $narrative
     * @param list<string> $warnings
     */
    public function upsert(string $contextId, array $narrative, array $warnings): void
    {
        $now = date('c');
        $id = 'nar-' . strtolower(trim($contextId));
        $sum = $narrative['source_summary'] ?? [];
        if (!is_array($sum)) {
            $sum = [];
        }
        $sum['warnings_snapshot'] = array_values(array_filter(array_map('strval', $warnings)));

        $stmt = $this->pdo->prepare('
            INSERT INTO strategic_context_narratives (
                id, strategic_context_id,
                current_direction, major_risks_json, unresolved_conflicts_json,
                confidence_trend, key_assumptions_json, recent_shifts_json,
                source_summary_json, computed_at, created_at, updated_at
            ) VALUES (
                :id, :cid,
                :cd, :mr, :uc,
                :ct, :ka, :rs,
                :ss, :comp, :ca, :ua
            )
            ON CONFLICT(strategic_context_id) DO UPDATE SET
                current_direction = excluded.current_direction,
                major_risks_json = excluded.major_risks_json,
                unresolved_conflicts_json = excluded.unresolved_conflicts_json,
                confidence_trend = excluded.confidence_trend,
                key_assumptions_json = excluded.key_assumptions_json,
                recent_shifts_json = excluded.recent_shifts_json,
                source_summary_json = excluded.source_summary_json,
                computed_at = excluded.computed_at,
                updated_at = excluded.updated_at
        ');
        $stmt->execute([
            ':id' => $id,
            ':cid' => $contextId,
            ':cd' => (string)($narrative['current_direction'] ?? ''),
            ':mr' => json_encode($narrative['major_risks'] ?? [], JSON_UNESCAPED_UNICODE),
            ':uc' => json_encode($narrative['unresolved_conflicts'] ?? [], JSON_UNESCAPED_UNICODE),
            ':ct' => (string)($narrative['confidence_trend'] ?? ''),
            ':ka' => json_encode($narrative['key_assumptions'] ?? [], JSON_UNESCAPED_UNICODE),
            ':rs' => json_encode($narrative['recent_shifts'] ?? [], JSON_UNESCAPED_UNICODE),
            ':ss' => json_encode($sum, JSON_UNESCAPED_UNICODE),
            ':comp' => (string)($narrative['computed_at'] ?? $now),
            ':ca' => $now,
            ':ua' => $now,
        ]);
    }

    /** @return array{narrative:array<string,mixed>,warnings:list<string>} */
    public function toApiSlice(array $row): array
    {
        $decode = static function ($v): array {
            if (!is_string($v) || $v === '') {
                return [];
            }
            $x = json_decode($v, true);
            return is_array($x) ? $x : [];
        };
        $sum = $decode($row['source_summary_json'] ?? '[]');
        $warnings = [];
        if (isset($sum['warnings_snapshot']) && is_array($sum['warnings_snapshot'])) {
            $warnings = array_values(array_filter(array_map('strval', $sum['warnings_snapshot'])));
        }
        $sumOut = $sum;
        unset($sumOut['warnings_snapshot']);

        $narrative = [
            'current_direction' => (string)($row['current_direction'] ?? ''),
            'major_risks' => $decode($row['major_risks_json'] ?? '[]'),
            'unresolved_conflicts' => $decode($row['unresolved_conflicts_json'] ?? '[]'),
            'confidence_trend' => (string)($row['confidence_trend'] ?? ''),
            'key_assumptions' => $decode($row['key_assumptions_json'] ?? '[]'),
            'recent_shifts' => $decode($row['recent_shifts_json'] ?? '[]'),
            'source_summary' => $sumOut,
            'computed_at' => (string)($row['computed_at'] ?? ''),
        ];

        return ['narrative' => $narrative, 'warnings' => $warnings];
    }
}

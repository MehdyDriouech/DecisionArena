<?php
namespace Infrastructure\Persistence;

class JuryAdversarialReportRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    /**
     * Persist (insert or replace) a jury_adversarial report for a session.
     * Scalar columns are stored individually for query convenience;
     * the full payload is also stored in report_json for future-proofing.
     */
    public function saveForSession(string $sessionId, array $report): void {
        $now = date('c');

        $existing = $this->findBySession($sessionId);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE jury_adversarial_reports SET
                    enabled = :enabled,
                    debate_quality_score = :debate_quality_score,
                    challenge_count = :challenge_count,
                    challenge_ratio = :challenge_ratio,
                    position_changes = :position_changes,
                    position_changers_json = :position_changers_json,
                    minority_report_present = :minority_report_present,
                    interaction_density = :interaction_density,
                    most_challenged_agent = :most_challenged_agent,
                    warnings_json = :warnings_json,
                    compliance_retries = :compliance_retries,
                    planned_rounds = :planned_rounds,
                    executed_rounds = :executed_rounds,
                    report_json = :report_json,
                    updated_at = :updated_at
                WHERE session_id = :session_id
            ');
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO jury_adversarial_reports
                    (session_id, enabled, debate_quality_score, challenge_count,
                     challenge_ratio, position_changes, position_changers_json,
                     minority_report_present, interaction_density, most_challenged_agent,
                     warnings_json, compliance_retries, planned_rounds, executed_rounds,
                     report_json, created_at, updated_at)
                VALUES
                    (:session_id, :enabled, :debate_quality_score, :challenge_count,
                     :challenge_ratio, :position_changes, :position_changers_json,
                     :minority_report_present, :interaction_density, :most_challenged_agent,
                     :warnings_json, :compliance_retries, :planned_rounds, :executed_rounds,
                     :report_json, :created_at, :updated_at)
            ');
        }

        $positionChangers = $report['position_changers'] ?? [];
        $warnings         = $report['warnings'] ?? [];

        $params = [
            ':session_id'              => $sessionId,
            ':enabled'                 => (int)($report['enabled'] ?? false),
            ':debate_quality_score'    => isset($report['debate_quality_score'])
                                            ? (float)$report['debate_quality_score'] : null,
            ':challenge_count'         => (int)($report['challenge_count'] ?? 0),
            ':challenge_ratio'         => isset($report['challenge_ratio'])
                                            ? (float)$report['challenge_ratio'] : null,
            ':position_changes'        => (int)($report['position_changes'] ?? 0),
            ':position_changers_json'  => is_array($positionChangers)
                                            ? json_encode($positionChangers, JSON_UNESCAPED_UNICODE)
                                            : (string)$positionChangers,
            ':minority_report_present' => (int)($report['minority_report_present'] ?? false),
            ':interaction_density'     => isset($report['interaction_density'])
                                            ? (float)$report['interaction_density'] : null,
            ':most_challenged_agent'   => $report['most_challenged_agent'] ?? null,
            ':warnings_json'           => is_array($warnings)
                                            ? json_encode($warnings, JSON_UNESCAPED_UNICODE)
                                            : (string)$warnings,
            ':compliance_retries'      => (int)($report['compliance_retries'] ?? 0),
            ':planned_rounds'          => isset($report['planned_rounds'])
                                            ? (int)$report['planned_rounds'] : null,
            ':executed_rounds'         => isset($report['executed_rounds'])
                                            ? (int)$report['executed_rounds'] : null,
            ':report_json'             => json_encode($report, JSON_UNESCAPED_UNICODE),
            ':updated_at'              => $now,
        ];

        if ($existing === null) {
            $params[':created_at'] = $now;
        }

        $stmt->execute($params);
    }

    /**
     * Find a report by session ID and return it as the original array shape.
     * Returns null if not found.
     */
    public function findBySession(string $sessionId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM jury_adversarial_reports WHERE session_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Reconstruct from report_json (source of truth for full payload)
        if (!empty($row['report_json'])) {
            $decoded = json_decode($row['report_json'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: rebuild from scalar columns if report_json is corrupted/missing
        return [
            'enabled'                 => (bool)($row['enabled'] ?? false),
            'debate_quality_score'    => $row['debate_quality_score'] !== null
                                            ? (float)$row['debate_quality_score'] : null,
            'challenge_count'         => (int)($row['challenge_count'] ?? 0),
            'challenge_ratio'         => $row['challenge_ratio'] !== null
                                            ? (float)$row['challenge_ratio'] : null,
            'position_changes'        => (int)($row['position_changes'] ?? 0),
            'position_changers'       => !empty($row['position_changers_json'])
                                            ? (json_decode($row['position_changers_json'], true) ?? [])
                                            : [],
            'minority_report_present' => (bool)($row['minority_report_present'] ?? false),
            'interaction_density'     => $row['interaction_density'] !== null
                                            ? (float)$row['interaction_density'] : null,
            'most_challenged_agent'   => $row['most_challenged_agent'] ?? null,
            'warnings'                => !empty($row['warnings_json'])
                                            ? (json_decode($row['warnings_json'], true) ?? [])
                                            : [],
            'compliance_retries'      => (int)($row['compliance_retries'] ?? 0),
            'planned_rounds'          => $row['planned_rounds'] !== null
                                            ? (int)$row['planned_rounds'] : null,
            'executed_rounds'         => $row['executed_rounds'] !== null
                                            ? (int)$row['executed_rounds'] : null,
        ];
    }

    public function deleteBySession(string $sessionId): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM jury_adversarial_reports WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
    }
}

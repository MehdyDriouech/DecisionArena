<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

class EvidenceRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function saveClaim(
        string $sessionId,
        ?string $messageId,
        ?string $agentId,
        string $claimText,
        string $claimType,
        string $status,
        float $confidence,
        ?string $evidenceText,
        ?string $sourceReference
    ): int {
        $now = date('c');
        $stmt = $this->pdo->prepare("
            INSERT INTO evidence_claims
              (session_id, message_id, agent_id, claim_text, claim_type, status,
               confidence, evidence_text, source_reference, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $sessionId, $messageId, $agentId, $claimText, $claimType,
            $status, $confidence, $evidenceText, $sourceReference, $now, $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function findClaimsBySession(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM evidence_claims WHERE session_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteClaimsBySession(string $sessionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM evidence_claims WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    }

    public function saveReport(string $sessionId, array $report): void
    {
        $now  = date('c');
        $json = json_encode($report, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("
            INSERT INTO evidence_reports (session_id, report_json, created_at, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(session_id) DO UPDATE
              SET report_json = excluded.report_json,
                  updated_at  = excluded.updated_at
        ");
        $stmt->execute([$sessionId, $json, $now, $now]);
    }

    /** @return array<string,mixed>|null */
    public function findReportBySession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT report_json FROM evidence_reports WHERE session_id = ?'
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

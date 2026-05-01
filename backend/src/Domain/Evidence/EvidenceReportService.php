<?php

declare(strict_types=1);

namespace Domain\Evidence;

use Infrastructure\Persistence\EvidenceRepository;

/**
 * Orchestrates claim extraction → assessment → report persistence.
 */
class EvidenceReportService
{
    private EvidenceClaimExtractor  $extractor;
    private EvidenceAssessmentService $assessor;
    private EvidenceRepository      $repo;

    public function __construct()
    {
        $this->extractor = new EvidenceClaimExtractor();
        $this->assessor  = new EvidenceAssessmentService();
        $this->repo      = new EvidenceRepository();
    }

    /**
     * Full pipeline: extract → assess → persist claims & report → return report.
     *
     * @param array<int,array<string,mixed>> $messages   all session messages
     * @param array<string,mixed>|null       $contextDoc context document row
     * @return array<string,mixed>                       evidence_report
     */
    public function generateAndPersist(
        string $sessionId,
        array $messages,
        ?array $contextDoc
    ): array {
        $contextText = $contextDoc !== null ? (string)($contextDoc['content'] ?? '') : null;

        // 1. Extract raw claims
        $claims = $this->extractor->extract($messages);

        // 2. Assess each claim against context
        $claims = $this->assessor->assess($claims, $contextText);

        // 3. Persist claims (delete old ones first so recompute is idempotent)
        $this->repo->deleteClaimsBySession($sessionId);
        foreach ($claims as $c) {
            $this->repo->saveClaim(
                $sessionId,
                $c['message_id'] ?? null,
                $c['agent_id']   ?? null,
                (string)($c['claim_text'] ?? ''),
                (string)($c['claim_type'] ?? 'strategic_assumption'),
                (string)($c['status']     ?? 'unsupported'),
                (float) ($c['confidence'] ?? 0.5),
                $c['evidence_text']    ?? null,
                $c['source_reference'] ?? null
            );
        }

        // 4. Build report
        $report = $this->buildReport($claims);

        // 5. Persist report
        $this->repo->saveReport($sessionId, $report);

        return $report;
    }

    /**
     * Re-run the pipeline from persisted messages (used by /recompute endpoint).
     */
    public function recompute(string $sessionId, array $messages, ?array $contextDoc): array
    {
        return $this->generateAndPersist($sessionId, $messages, $contextDoc);
    }

    /**
     * Load a cached report; returns null if none exists.
     */
    public function loadCachedReport(string $sessionId): ?array
    {
        return $this->repo->findReportBySession($sessionId);
    }

    // ── Internal report builder ───────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $claims assessed claims
     * @return array<string,mixed>
     */
    public function buildReport(array $claims): array
    {
        $total        = count($claims);
        $verified     = 0;
        $plausible    = 0;
        $unsupported  = 0;
        $contradicted = 0;
        $needsSource  = 0;
        $criticals    = [];

        foreach ($claims as $c) {
            switch ($c['status'] ?? 'unsupported') {
                case 'verified':       $verified++;                 break;
                case 'plausible':      $plausible++;                break;
                case 'unsupported':    $unsupported++;              break;
                case 'contradicted':   $contradicted++;             break;
                case 'needs_source':   $needsSource++;              break;
            }
            // A claim is critical if it is contradicted, or is factual and unsupported
            if (($c['status'] ?? '') === 'contradicted'
                || (($c['status'] ?? '') === 'needs_source' && ($c['claim_type'] ?? '') === 'factual')
            ) {
                $criticals[] = mb_substr((string)($c['claim_text'] ?? ''), 0, 160, 'UTF-8');
            }
        }

        // evidence_score: ratio of strong vs weak evidence (0–1)
        $evidenceScore = $total > 0
            ? round(($verified * 1.0 + $plausible * 0.7) / ($total * 1.0), 3)
            : 1.0; // No claims extracted → no evidence issues

        // decision_impact: how risky is the evidence gap?
        $impact = $this->computeImpact($total, $unsupported, $contradicted, $needsSource);

        // recommendation text
        $recommendation = $this->buildRecommendation(
            $evidenceScore, $contradicted, $unsupported + $needsSource, $impact
        );

        return [
            'evidence_score'           => $evidenceScore,
            'total_claims'             => $total,
            'verified_count'           => $verified,
            'plausible_count'          => $plausible,
            'unsupported_claims_count' => $unsupported + $needsSource,
            'contradicted_claims_count'=> $contradicted,
            'needs_source_count'       => $needsSource,
            'critical_unknowns'        => array_slice($criticals, 0, 5),
            'decision_impact'          => $impact,
            'recommendation'           => $recommendation,
        ];
    }

    private function computeImpact(int $total, int $unsupported, int $contradicted, int $needsSource): string
    {
        if ($total === 0) {
            return 'low';
        }
        $badRatio = ($unsupported + $contradicted + $needsSource) / $total;

        if ($contradicted > 0 || $badRatio >= 0.7) {
            return 'high';
        }
        if ($badRatio >= 0.4) {
            return 'medium';
        }
        return 'low';
    }

    private function buildRecommendation(
        float $score,
        int $contradicted,
        int $unsupported,
        string $impact
    ): string {
        if ($contradicted > 0) {
            return "Review the {$contradicted} contradicted claim(s) before finalising this decision. "
                . 'These claims conflict with information in the context document.';
        }
        if ($unsupported >= 5) {
            return "High number of unsupported claims ({$unsupported}). "
                . 'Enrich the context document with market data, cost estimates and technical specs.';
        }
        if ($impact === 'medium') {
            return "Several claims lack supporting evidence. "
                . 'Consider adding more context before committing to this decision.';
        }
        if ($score >= 0.8) {
            return 'Evidence coverage is good. Proceed with standard review.';
        }
        return 'Evidence quality is acceptable. Verify key assumptions before execution.';
    }
}

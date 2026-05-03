<?php

declare(strict_types=1);

namespace Domain\Evidence;

use Infrastructure\Persistence\ContextDocumentChunkRepository;
use Infrastructure\Persistence\EvidenceRepository;
use Infrastructure\Persistence\SessionRepository;

/**
 * Orchestrates claim extraction → assessment → report persistence (Phase 3).
 */
class EvidenceReportService
{
    private EvidenceClaimExtractor   $extractor;
    private EvidenceAssessmentService $assessor;
    private EvidenceRepository       $repo;

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
     * @param array<string,mixed>|null       $contextDoc context document row (may include Phase 1 prompt fields)
     * @return array<string,mixed>                       evidence_report
     */
    public function generateAndPersist(
        string $sessionId,
        array $messages,
        ?array $contextDoc
    ): array {
        $contextText = $contextDoc !== null ? (string)($contextDoc['content'] ?? '') : null;

        $objective = '';
        try {
            $sess = (new SessionRepository())->findById($sessionId);
            $objective = (string)($sess['initial_prompt'] ?? '');
        } catch (\Throwable) {
        }

        $chunkBundle = $this->buildChunkMeta($sessionId, $objective);
        $retrievalQuery = $chunkBundle['retrieval_query'];
        unset($chunkBundle['retrieval_query']);

        $claims = $this->extractor->extract($messages);
        $claims = $this->addFallbackClaimsForChallengedMessages($claims, $messages);
        $claims = $this->assessor->assess($claims, $contextText, $chunkBundle);
        $claims = $this->applyUserChallengeFlags($claims, $messages);
        $claims = $this->tuneConfidenceForUserChallenges($claims);

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
                $c['source_reference'] ?? null,
                (string)($c['support_class']    ?? 'unsupported'),
                (string)($c['importance']      ?? 'medium'),
                isset($c['linked_chunk_ids']) && is_string($c['linked_chunk_ids']) ? $c['linked_chunk_ids'] : null,
                (string)($c['source_layer'] ?? 'none'),
                !empty($c['challenge_flag']) ? 1 : 0
            );
        }

        $report = $this->buildReport(
            $claims,
            $contextDoc,
            $retrievalQuery
        );

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

    /** @return array<string,mixed>|null */
    public function loadCachedReport(string $sessionId): ?array
    {
        return $this->repo->findReportBySession($sessionId);
    }

    /**
     * @return array{chunks:list,priority_chunk_ids:list,retrieval_query:?string}
     */
    private function buildChunkMeta(string $sessionId, string $objective): array
    {
        $chunkRepo = new ContextDocumentChunkRepository();
        $chunks    = $chunkRepo->findChunksWithOffsetsForSession($sessionId);
        $priority  = [];
        $ftsQ      = ContextDocumentChunkRepository::buildFtsMatchQuery($objective, null);
        if ($ftsQ !== '' && $chunks !== []) {
            try {
                $raw = $chunkRepo->searchTopChunks($sessionId, $ftsQ, 8);
                foreach (ContextDocumentChunkRepository::dedupeByChunkIndex($raw, 5) as $r) {
                    $priority[] = (int)$r['id'];
                }
            } catch (\Throwable) {
            }
        }

        return [
            'chunks'             => $chunks,
            'priority_chunk_ids' => $priority,
            'retrieval_query'    => $ftsQ !== '' ? $ftsQ : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $claims assessed claims
     * @return array<string,mixed>
     */
    public function buildReport(
        array $claims,
        ?array $contextDoc = null,
        ?string $retrievalQuery = null
    ): array {
        $total             = count($claims);
        $supported         = 0;
        $unsupported       = 0;
        $contradicted      = 0;
        $notApplicable     = 0;
        $verified          = 0;
        $plausible         = 0;
        $needsSource       = 0;
        $applicableImportant = 0;
        $supportedImportant  = 0;
        $highUnsupported     = 0;
        $highContradicted    = 0;
        $criticals           = [];
        $claimSummaries      = [];

        $challengedCount      = 0;
        $highChallenged       = 0;
        $granularityFallback  = 0;

        foreach ($claims as $c) {
            $sc  = (string)($c['support_class'] ?? 'unsupported');
            $imp = (string)($c['importance'] ?? 'medium');
            $st  = (string)($c['status'] ?? 'unsupported');
            $chF = !empty($c['challenge_flag']);
            if ($chF) {
                $challengedCount++;
                if ($imp === 'high') {
                    $highChallenged++;
                }
                if (($c['claim_granularity'] ?? '') === 'message_fallback') {
                    $granularityFallback++;
                }
            }

            match ($sc) {
                'supported'       => $supported++,
                'unsupported'     => $unsupported++,
                'contradicted'    => $contradicted++,
                'not_applicable'  => $notApplicable++,
                default           => $unsupported++,
            };

            if ($st === 'verified') {
                $verified++;
            }
            if ($st === 'plausible') {
                $plausible++;
            }
            if ($st === 'needs_source') {
                $needsSource++;
            }

            if ($sc !== 'not_applicable' && in_array($imp, ['medium', 'high'], true)) {
                $applicableImportant++;
                if ($sc === 'supported') {
                    $supportedImportant++;
                }
            }
            if ($imp === 'high' && $sc === 'unsupported') {
                $highUnsupported++;
            }
            if ($imp === 'high' && $sc === 'contradicted') {
                $highContradicted++;
            }

            if (($sc === 'contradicted' || ($sc === 'unsupported' && $imp === 'high'))
                && count($criticals) < 8
            ) {
                $criticals[] = mb_substr((string)($c['claim_text'] ?? ''), 0, 160, 'UTF-8');
            }

            if (count($claimSummaries) < 50) {
                $claimSummaries[] = [
                    'claim_text'         => mb_substr((string)($c['claim_text'] ?? ''), 0, 220, 'UTF-8'),
                    'support_class'      => $sc,
                    'importance'         => $imp,
                    'linked_chunk_ids'   => $c['linked_chunk_ids'] ?? null,
                    'source_layer'       => (string)($c['source_layer'] ?? 'none'),
                    'challenge_flag'     => $chF,
                    'confidence_weight'  => $chF ? 0.72 : 1.0,
                    'claim_granularity'  => (string)($c['claim_granularity'] ?? 'extracted'),
                ];
            }
        }

        $evidenceDensity = ($applicableImportant === 0)
            ? 1.0
            : round($supportedImportant / $applicableImportant, 4);

        $unsupportedClaimsCount = $unsupported + $needsSource;

        $evidenceBadge = $this->computeEvidenceBadge(
            $evidenceDensity,
            $contradicted,
            $highContradicted,
            $highUnsupported
        );

        $score100 = $this->computeEvidenceScore100(
            $evidenceDensity,
            $contradicted,
            $highUnsupported,
            $highContradicted,
            $total
        );

        $impact = $this->computeImpactPhase3(
            $total,
            $unsupportedClaimsCount,
            $contradicted,
            $needsSource,
            $evidenceDensity,
            $highUnsupported,
            $highContradicted
        );

        $recommendation = $this->buildRecommendationPhase3(
            $evidenceDensity,
            $contradicted,
            $unsupportedClaimsCount,
            $impact,
            $highContradicted,
            $highUnsupported
        );

        $ctxHash = $contextDoc['context_hash'] ?? null;
        $ctxTrunc = !empty($contextDoc['context_truncated']);

        $granularityNote = $granularityFallback > 0
            ? 'Some user challenges used whole-message claim granularity because no atomic claims were extracted; boundaries are approximate.'
            : null;

        return [
            'evidence_score'                  => round($score100 / 100, 4),
            'score'                           => $score100,
            'evidence_density'                => $evidenceDensity,
            'evidence_badge'                  => $evidenceBadge,
            'total_claims'                    => $total,
            'supported_claims_count'          => $supported,
            'verified_count'                  => $verified,
            'plausible_count'                 => $plausible,
            'unsupported_claims_count'        => $unsupportedClaimsCount,
            'contradicted_claims_count'       => $contradicted,
            'not_applicable_claims_count'     => $notApplicable,
            'needs_source_count'              => $needsSource,
            'high_importance_unsupported_count'=> $highUnsupported,
            'high_importance_contradicted_count'=> $highContradicted,
            'challenged_claims_count'         => $challengedCount,
            'high_importance_challenged_count'=> $highChallenged,
            'user_challenge_claim_granularity_note' => $granularityNote,
            'uncertainty_penalty_units'       => round(min(20, $challengedCount * 2.0 + $highChallenged * 3.5), 2),
            'applicable_important_claims'     => $applicableImportant,
            'supported_important_claims'      => $supportedImportant,
            'critical_unknowns'               => array_slice($criticals, 0, 5),
            'decision_impact'                 => $impact,
            'recommendation'                  => $recommendation,
            'claims'                          => $claimSummaries,
            'context_hash'                    => $ctxHash,
            'context_truncated'               => $ctxTrunc,
            'retrieval_query'                 => $retrievalQuery,
        ];
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @param array<int,array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function addFallbackClaimsForChallengedMessages(array $claims, array $messages): array
    {
        $claimedMsgIds = [];
        foreach ($claims as $c) {
            $mid = $c['message_id'] ?? null;
            if ($mid !== null && $mid !== '') {
                $claimedMsgIds[(string)$mid] = true;
            }
        }
        $out   = $claims;
        $limit = 60;
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = $this->decodeMessageMetaRow($msg);
            if (($meta['challenge_status'] ?? '') !== 'challenged') {
                continue;
            }
            $mid = (string)($msg['id'] ?? '');
            if ($mid === '' || isset($claimedMsgIds[$mid])) {
                continue;
            }
            $content = trim((string)($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $snippet = mb_substr($content, 0, 400, 'UTF-8');
            $out[] = [
                'claim_text'       => '[Whole-message proxy — user challenged; no atomic claims extracted from this reply] ' . $snippet,
                'claim_type'       => 'strategic_assumption',
                'agent_id'         => $msg['agent_id'] ?? null,
                'message_id'       => $mid,
                'status'           => 'unsupported',
                'confidence'       => 0.5,
                'evidence_text'    => null,
                'source_reference' => null,
                'claim_granularity'=> 'message_fallback',
            ];
            $claimedMsgIds[$mid] = true;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @param array<int,array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function applyUserChallengeFlags(array $claims, array $messages): array
    {
        $challengedMsgs = [];
        foreach ($messages as $msg) {
            $mid = (string)($msg['id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $meta = $this->decodeMessageMetaRow($msg);
            if (($meta['challenge_status'] ?? '') === 'challenged') {
                $challengedMsgs[$mid] = true;
            }
        }
        foreach ($claims as &$c) {
            $mid = (string)($c['message_id'] ?? '');
            if (($c['claim_granularity'] ?? '') === 'message_fallback') {
                $c['challenge_flag'] = true;
            } elseif ($mid !== '' && isset($challengedMsgs[$mid])) {
                $c['challenge_flag'] = true;
            } else {
                $c['challenge_flag'] = false;
            }
        }
        unset($c);
        return $claims;
    }

    /**
     * User challenge contests evidence without changing support_class; down-weight confidence only.
     *
     * @param list<array<string,mixed>> $claims
     * @return list<array<string,mixed>>
     */
    private function tuneConfidenceForUserChallenges(array $claims): array
    {
        foreach ($claims as &$c) {
            if (empty($c['challenge_flag'])) {
                continue;
            }
            $base = (float)($c['confidence'] ?? 0.5);
            $c['confidence'] = round(max(0.08, $base * 0.72), 4);
        }
        unset($c);
        return $claims;
    }

    /** @param array<string,mixed> $msg */
    private function decodeMessageMetaRow(array $msg): array
    {
        $raw = $msg['meta_json'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }

    private function computeEvidenceBadge(
        float $density,
        int $contradicted,
        int $highContradicted,
        int $highUnsupported
    ): string {
        if ($contradicted > 0 || $highContradicted > 0) {
            return 'Risky';
        }
        if ($highUnsupported > 0 || $density < 0.4) {
            return 'Weak';
        }
        if ($density < 0.7) {
            return 'Medium';
        }
        return 'Strong';
    }

    private function computeEvidenceScore100(
        float $density,
        int $contradicted,
        int $highUnsupported,
        int $highContradicted,
        int $total
    ): int {
        if ($total === 0) {
            return 100;
        }
        $raw = $density * 100
            - min(35, $contradicted * 14)
            - min(25, $highUnsupported * 12)
            - min(30, $highContradicted * 18);
        return (int) max(0, min(100, round($raw)));
    }

    private function computeImpactPhase3(
        int $total,
        int $unsupported,
        int $contradicted,
        int $needsSource,
        float $density,
        int $highUnsupported,
        int $highContradicted
    ): string {
        if ($total === 0) {
            return 'low';
        }
        if ($highContradicted > 0 || $contradicted >= 2) {
            return 'high';
        }
        if ($contradicted > 0 || $highUnsupported >= 2 || $density < 0.35) {
            return 'high';
        }
        $badRatio = ($unsupported + $contradicted + $needsSource) / $total;
        if ($badRatio >= 0.55 || $density < 0.5) {
            return 'medium';
        }
        if ($badRatio >= 0.35) {
            return 'medium';
        }
        return 'low';
    }

    private function buildRecommendationPhase3(
        float $density,
        int $contradicted,
        int $unsupported,
        string $impact,
        int $highContradicted,
        int $highUnsupported
    ): string {
        if ($highContradicted > 0) {
            return "High-importance claim(s) contradict the shared context ({$highContradicted}); reconcile before committing.";
        }
        if ($contradicted > 0) {
            return "Review the {$contradicted} contradicted claim(s): they conflict with passages in the context document.";
        }
        if ($highUnsupported > 0) {
            return "Some high-importance claims ({$highUnsupported}) are not supported by the context document — add sourcing or narrow the decision.";
        }
        if ($density < 0.35) {
            return 'Evidence density is low for important claims; enrich the context or downgrade confidence.';
        }
        if ($unsupported >= 5) {
            return "Many claims lack support ({$unsupported}). Enrich the context with concrete facts.";
        }
        if ($impact === 'medium') {
            return 'Several important claims are weakly evidenced — verify before execution.';
        }
        if ($density >= 0.75) {
            return 'Important claims are largely supported by context. Proceed with standard review.';
        }
        return 'Evidence is acceptable; verify key assumptions before execution.';
    }
}

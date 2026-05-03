<?php
namespace Domain\DecisionReliability;

use Infrastructure\Logging\Logger;

class DecisionReliabilityService {
    private ContextQualityAnalyzer $contextAnalyzer;
    private FalseConsensusDetector $falseConsensusDetector;
    private ContextClarificationService $clarificationService;
    private ?Logger $logger;

    public function __construct(
        ?ContextQualityAnalyzer $contextAnalyzer = null,
        ?FalseConsensusDetector $falseConsensusDetector = null,
        ?ContextClarificationService $clarificationService = null,
        ?Logger $logger = null
    ) {
        $this->contextAnalyzer = $contextAnalyzer ?? new ContextQualityAnalyzer();
        $this->falseConsensusDetector = $falseConsensusDetector ?? new FalseConsensusDetector();
        $this->clarificationService = $clarificationService ?? new ContextClarificationService();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * @param ?array<string,mixed> $rawDecision
     * @param array<int,array> $votes
     * @param array<int,array> $positions
     * @param array<int,array> $edges
     * @param ?array<string,mixed> $timeline
     * @param ?array<int,array> $personaScores
     * @param ?array<string,mixed> $biasReport
     * @return array<string,mixed>
     */
    public function buildEnvelope(
        string $objective,
        ?array $contextDoc,
        ?array $rawDecision,
        array $votes,
        array $positions,
        array $edges,
        float $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        ?array $timeline = null,
        ?array $personaScores = null,
        ?array $biasReport = null,
        ?array $evidenceReport = null,
        ?array $riskProfile = null
    ): array {
        $threshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $contextQuality = $this->contextAnalyzer->analyze($objective, $contextDoc);
        $falseConsensus = $this->falseConsensusDetector->detect(
            $contextQuality,
            $positions,
            $edges,
            $votes,
            $rawDecision,
            $timeline,
            $personaScores,
            $biasReport
        );

        $adjustedDecision = $this->buildAdjustedDecision($rawDecision, $contextQuality, $falseConsensus, $threshold, $evidenceReport, $riskProfile);
        $decisionSummary = $this->buildDecisionSummary($adjustedDecision, $contextQuality, $falseConsensus, $evidenceReport, $riskProfile);

        $contextClarification = null;
        if (($adjustedDecision['final_outcome'] ?? '') === 'INSUFFICIENT_CONTEXT') {
            $contextClarification = $this->clarificationService->generateClarificationQuestions($contextQuality);
        }

        $warnings = $this->collectWarnings($contextQuality, $falseConsensus, $adjustedDecision, $decisionSummary);

        $this->logReliabilitySnapshot($contextQuality, $falseConsensus);

        // Build risk threshold info for consumers
        $riskThresholdInfo = null;
        if ($riskProfile !== null) {
            $riskThresholdInfo = [
                'configured_threshold'    => round($threshold, 4),
                'risk_adjusted_threshold' => (float)($riskProfile['recommended_threshold'] ?? $threshold),
                'threshold_reason'        => $riskProfile['recommendations'][0] ?? '',
                'was_adjusted'            => ($riskProfile['recommended_threshold'] ?? $threshold) > $threshold,
            ];
        }

        return [
            'raw_decision' => $rawDecision,
            'adjusted_decision' => $adjustedDecision,
            'context_quality' => $contextQuality,
            'reliability_cap' => (float)$contextQuality['reliability_cap'],
            'false_consensus_risk' => $falseConsensus['false_consensus_risk'],
            'false_consensus' => $falseConsensus,
            'reliability_warnings' => $warnings,
            'decision_threshold' => $threshold,
            'decision_reliability_summary' => $decisionSummary,
            'context_clarification' => $contextClarification,
            'evidence_score' => $evidenceReport !== null ? (float)($evidenceReport['evidence_score'] ?? 1.0) : null,
            'risk_threshold_info' => $riskThresholdInfo,
        ];
    }

    /**
     * @param array<string,mixed> $adjustedDecision
     * @param array<string,mixed> $contextQuality
     * @param array<string,mixed> $falseConsensus
     * @return array{decision_possible:bool, reliability_level:string, top_issues: array<int,array{key:string}>, recommended_action:string}
     */
    public function buildDecisionSummary(array $adjustedDecision, array $contextQuality, array $falseConsensus, ?array $evidenceReport = null, ?array $riskProfile = null): array {
        $level = (string)($contextQuality['level'] ?? 'medium');
        $final = (string)($adjustedDecision['final_outcome'] ?? '');
        $decisionPossible = $final !== 'INSUFFICIENT_CONTEXT';

        $issues = [];
        $seen = [];

        $push = function (string $key) use (&$issues, &$seen): void {
            if (isset($seen[$key]) || count($issues) >= 3) {
                return;
            }
            $issues[] = ['key' => $key];
            $seen[$key] = true;
        };

        if ($final === 'INSUFFICIENT_CONTEXT') {
            $push('reliability.issue.insufficient_context');
        }

        $fc = (string)($falseConsensus['false_consensus_risk'] ?? 'low');
        if ($fc === 'high') {
            $push('reliability.issue.false_consensus_high');
        } elseif ($fc === 'medium' && $final !== 'INSUFFICIENT_CONTEXT' && count($issues) < 3) {
            $push('reliability.issue.false_consensus_medium');
        }

        foreach (($falseConsensus['signals'] ?? []) as $signal) {
            $type = (string)($signal['type'] ?? '');
            if ($type === 'low_contradiction' || $type === 'no_explicit_disagreement' || $type === 'low_argument_diversity') {
                if (!isset($seen['reliability.issue.low_contradiction'])) {
                    $push('reliability.issue.low_contradiction');
                }
                break;
            }
        }

        if ($final !== 'INSUFFICIENT_CONTEXT'
            && ($contextQuality['level'] ?? '') === 'weak'
            && !isset($seen['reliability.issue.insufficient_context'])) {
            $push('reliability.issue.weak_context');
        }

        if (($adjustedDecision['capped'] ?? false) && count($issues) < 3 && !isset($seen['reliability.issue.cap'])) {
            $push('reliability.issue.cap_applied');
        }

        if (!empty($contextQuality['context_truncated']) && !isset($seen['reliability.issue.context_truncated'])) {
            $push('reliability.issue.context_truncated');
        }

        $rec = (string)($adjustedDecision['reason'] ?? '');
        if ($rec === '' && !empty($falseConsensus['recommendations'][0])) {
            $rec = (string)$falseConsensus['recommendations'][0];
        }
        if ($rec === '' && $final === 'INSUFFICIENT_CONTEXT') {
            $rec = 'complete_context_rerun';
        }

        // Surface evidence quality in the summary
        $evidenceScore  = null;
        $evidenceImpact = null;
        if ($evidenceReport !== null) {
            $evidenceScore  = (float)($evidenceReport['evidence_score']  ?? 1.0);
            $evidenceImpact = (string)($evidenceReport['decision_impact'] ?? 'low');
            $density        = (float)($evidenceReport['evidence_density'] ?? 1.0);
            $hiUnsup        = (int)($evidenceReport['high_importance_unsupported_count'] ?? 0);
            $hiContra       = (int)($evidenceReport['high_importance_contradicted_count'] ?? 0);
            $contraN        = (int)($evidenceReport['contradicted_claims_count'] ?? 0);

            if ($hiContra > 0 && !isset($seen['reliability.issue.evidence_contradicted_hi'])) {
                $push('reliability.issue.evidence_contradicted_hi');
            } elseif ($contraN > 0 && !isset($seen['reliability.issue.evidence_contradicted'])) {
                $push('reliability.issue.evidence_contradicted');
            }
            if ($hiUnsup > 0 && !isset($seen['reliability.issue.evidence_unsupported_hi'])) {
                $push('reliability.issue.evidence_unsupported_hi');
            }
            if ($density < 0.35 && ($evidenceReport['total_claims'] ?? 0) >= 3 && !isset($seen['reliability.issue.evidence_low_density'])) {
                $push('reliability.issue.evidence_low_density');
            }

            if ($evidenceImpact === 'high' && !isset($seen['reliability.issue.evidence_gap'])) {
                $push('reliability.issue.evidence_gap');
            } elseif ($evidenceImpact === 'medium' && count($issues) < 3 && !isset($seen['reliability.issue.evidence_partial'])) {
                $push('reliability.issue.evidence_partial');
            }
        }

        // Surface risk profile in summary
        $riskLevel     = null;
        $riskAdjThr    = null;
        if ($riskProfile !== null) {
            $riskLevel  = (string)($riskProfile['risk_level'] ?? 'medium');
            $riskAdjThr = (float)($riskProfile['recommended_threshold'] ?? 0.0);
            if (in_array($riskLevel, ['high', 'critical'], true) && !isset($seen['reliability.issue.high_risk'])) {
                $push('reliability.issue.high_risk');
            }
        }

        return [
            'decision_possible'       => $decisionPossible,
            'reliability_level'       => $level,
            'top_issues'              => $issues,
            'recommended_action'      => $rec,
            'evidence_score'          => $evidenceScore,
            'evidence_impact'         => $evidenceImpact,
            'risk_level'              => $riskLevel,
            'risk_adjusted_threshold' => $riskAdjThr,
        ];
    }

    private function logReliabilitySnapshot(array $contextQuality, array $falseConsensus): void {
        try {
            $this->logger->info('decision_reliability_envelope', [
                'metadata' => json_encode([
                    'context_quality_score' => $contextQuality['score'] ?? null,
                    'semantic_density' => $contextQuality['semantic_density'] ?? null,
                    'reliability_cap' => $contextQuality['reliability_cap'] ?? null,
                    'false_consensus_risk' => $falseConsensus['false_consensus_risk'] ?? null,
                    'diversity_score' => $falseConsensus['diversity_score'] ?? null,
                    'lexical_uniformity' => $falseConsensus['lexical_uniformity'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param ?array<string,mixed> $rawDecision
     * @param array<string,mixed> $contextQuality
     * @param array<string,mixed> $falseConsensus
     * @return array<string,mixed>
     */
    private function buildAdjustedDecision(
        ?array $rawDecision,
        array $contextQuality,
        array $falseConsensus,
        float $threshold,
        ?array $evidenceReport = null,
        ?array $riskProfile = null
    ): array {
        if (empty($rawDecision)) {
            return $this->adjustedInsufficientShell(
                $contextQuality,
                $threshold,
                'No raw decision was available to adjust.',
                'NO_CONSENSUS'
            );
        }

        $rawLabelLower = strtolower((string)($rawDecision['decision_label'] ?? 'no-consensus'));
        $rawScore = max(0.0, min(1.0, (float)($rawDecision['decision_score'] ?? 0.0)));
        $cap = (float)($contextQuality['reliability_cap'] ?? 1.0);
        $adjustedScore = min($rawScore, $cap);
        $capped = $adjustedScore < $rawScore;

        $voteLabel = $this->normalizeVoteLabel($rawLabelLower, $rawScore, $threshold);
        $contextLevel = (string)($contextQuality['level'] ?? 'strong');
        $criticalMissing = $contextQuality['critical_missing'] ?? [];
        $hasCriticalGaps = is_array($criticalMissing) && count($criticalMissing) >= 2;

        $decisionStatus = 'CONFIDENT';
        $reason = null;

        if ($rawScore < $threshold) {
            $voteLabel = 'NO_CONSENSUS';
            $decisionStatus = 'FRAGILE';
            $reason = 'Winning score did not reach configured threshold.';
        }

        if ($contextLevel === 'weak') {
            if ($hasCriticalGaps) {
                $decisionStatus = 'INSUFFICIENT_CONTEXT';
                $reason = 'Weak context with multiple critical missing information blocks.';
            } elseif (in_array($voteLabel, ['GO', 'NO_GO'], true)) {
                $decisionStatus = 'FRAGILE';
                $reason = 'Consensus on direction but context quality is weak.';
            } else {
                $decisionStatus = 'INSUFFICIENT_CONTEXT';
                $reason = 'Decision cannot be considered reliable with weak context.';
            }
        }

        $fcRisk = (string)($falseConsensus['false_consensus_risk'] ?? 'low');
        if ($decisionStatus === 'CONFIDENT' && $fcRisk === 'high' && in_array($voteLabel, ['GO', 'NO_GO'], true)) {
            $decisionStatus = 'FRAGILE';
            $reason = 'High false-consensus risk downgrades confidence in the vote outcome.';
        }

        if ($decisionStatus !== 'INSUFFICIENT_CONTEXT' && $fcRisk === 'high' && $voteLabel === 'ITERATE') {
            $decisionStatus = 'FRAGILE';
            $reason = $reason ?? 'High false-consensus risk on an iterative / unclear vote outcome.';
        }

        // Evidence downgrade rules (Phase 3: density + high-importance taxonomy)
        if ($evidenceReport !== null) {
            $contradicted  = (int)($evidenceReport['contradicted_claims_count'] ?? 0);
            $unsupported   = (int)($evidenceReport['unsupported_claims_count']  ?? 0);
            $total         = (int)($evidenceReport['total_claims']              ?? 0);
            $impact        = (string)($evidenceReport['decision_impact']         ?? 'low');
            $criticals     = (array)($evidenceReport['critical_unknowns']        ?? []);
            $density       = (float)($evidenceReport['evidence_density']         ?? 1.0);
            $hiUnsup       = (int)($evidenceReport['high_importance_unsupported_count'] ?? 0);
            $hiContra      = (int)($evidenceReport['high_importance_contradicted_count'] ?? 0);

            if ($hiContra > 0 && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? 'Evidence: at least one high-importance claim contradicts the shared context.';
            } elseif ($contradicted > 0 && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? "Evidence: {$contradicted} claim(s) directly contradicted by context document.";
            }
            if ($hiUnsup >= 2 && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? 'Evidence: multiple high-importance claims lack context support.';
            }
            if ($total > 0 && ($unsupported / max(1, $total)) >= 0.7 && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? "Evidence: over 70% of identified claims are unsupported.";
            }
            if ($total >= 4 && $density < 0.3 && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? 'Evidence: low support density for important claims relative to context.';
            }
            if (!empty($criticals) && $decisionStatus === 'CONFIDENT') {
                $decisionStatus = 'FRAGILE';
                $reason = $reason ?? 'Evidence: critical unknowns detected — key claims cannot be verified.';
            }
            if ($impact === 'high' && $decisionStatus === 'INSUFFICIENT_CONTEXT') {
                // Already insufficient — no extra downgrade needed
            }
        }

        // Risk-adjusted threshold downgrade
        // Only applies when the decision would be CONFIDENT but raw score
        // passes the user threshold while failing the risk-adjusted one.
        if ($riskProfile !== null && $decisionStatus === 'CONFIDENT') {
            $riskAdjusted = (float)($riskProfile['recommended_threshold'] ?? $threshold);
            if ($riskAdjusted > $threshold && $rawScore < $riskAdjusted) {
                $decisionStatus = 'FRAGILE';
                $adjPct = round($riskAdjusted * 100);
                $rl     = (string)($riskProfile['risk_level'] ?? 'medium');
                $reason = $reason ?? "Risk-adjusted threshold not met: {$rl}-risk decision requires ≥{$adjPct}% consensus.";
            }
        }

        $finalOutcome = $this->composeFinalOutcome($voteLabel, $decisionStatus);
        $legacyDecisionLabel = $this->legacyDisplayLabel($finalOutcome, $rawLabelLower);
        $legacyStatus = $this->legacyExecutionStatus($voteLabel);

        return [
            'decision_label' => $voteLabel,
            'vote_label' => $voteLabel,
            'decision_status' => $decisionStatus,
            'final_outcome' => $finalOutcome,
            'legacy_decision_label' => $legacyDecisionLabel,
            'legacy_decision_status' => $legacyStatus,
            'decision_score' => round($adjustedScore, 4),
            'confidence_level' => $this->confidenceFromScore($adjustedScore, $threshold),
            'threshold_used' => $threshold,
            'capped' => $capped,
            'raw_score' => round($rawScore, 4),
            'reliability_cap' => $cap,
            'reason' => $reason,
            'ui_decision_label' => $legacyDecisionLabel,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function adjustedInsufficientShell(array $contextQuality, float $threshold, string $reason, string $voteFallback): array {
        $finalOutcome = 'INSUFFICIENT_CONTEXT';
        $legacy = 'insufficient_context';
        return [
            'decision_label' => $voteFallback,
            'vote_label' => $voteFallback,
            'decision_status' => 'INSUFFICIENT_CONTEXT',
            'final_outcome' => $finalOutcome,
            'legacy_decision_label' => $legacy,
            'legacy_decision_status' => 'ITERATE',
            'decision_score' => 0.0,
            'confidence_level' => 'low',
            'threshold_used' => $threshold,
            'capped' => false,
            'raw_score' => 0.0,
            'reliability_cap' => (float)($contextQuality['reliability_cap'] ?? 1.0),
            'reason' => $reason,
            'ui_decision_label' => $legacy,
        ];
    }

    private function normalizeVoteLabel(string $rawLower, float $rawScore, float $threshold): string {
        if ($rawScore < $threshold) {
            return 'NO_CONSENSUS';
        }
        return match (true) {
            $rawLower === 'go' || $rawLower === 'go_fragile' => 'GO',
            $rawLower === 'no-go' || $rawLower === 'no_go' || $rawLower === 'no_go_fragile' => 'NO_GO',
            $rawLower === 'no-consensus' => 'NO_CONSENSUS',
            $rawLower === 'insufficient_context' => 'ITERATE',
            default => 'ITERATE',
        };
    }

    private function composeFinalOutcome(string $voteLabel, string $decisionStatus): string {
        if ($decisionStatus === 'INSUFFICIENT_CONTEXT') {
            return 'INSUFFICIENT_CONTEXT';
        }
        if ($voteLabel === 'NO_CONSENSUS') {
            return $decisionStatus === 'FRAGILE' ? 'NO_CONSENSUS_FRAGILE' : 'NO_CONSENSUS';
        }
        $suffix = $decisionStatus === 'CONFIDENT' ? 'CONFIDENT' : 'FRAGILE';
        return match ($voteLabel) {
            'GO' => 'GO_' . $suffix,
            'NO_GO' => 'NO_GO_' . $suffix,
            'ITERATE' => 'ITERATE_' . $suffix,
            default => 'ITERATE_' . $suffix,
        };
    }

    private function legacyDisplayLabel(string $finalOutcome, string $rawLabelLower): string {
        if ($finalOutcome === 'INSUFFICIENT_CONTEXT') {
            return 'insufficient_context';
        }
        return match ($finalOutcome) {
            'GO_CONFIDENT' => 'go',
            'GO_FRAGILE' => 'go_fragile',
            'NO_GO_CONFIDENT' => 'no-go',
            'NO_GO_FRAGILE' => 'no_go_fragile',
            'ITERATE_CONFIDENT', 'ITERATE_FRAGILE' => str_contains($rawLabelLower, 'reduce') ? 'reduce-scope'
                : (str_contains($rawLabelLower, 'pivot') ? 'pivot' : 'needs-more-info'),
            'NO_CONSENSUS', 'NO_CONSENSUS_FRAGILE' => 'no-consensus',
            default => 'needs-more-info',
        };
    }

    private function legacyExecutionStatus(string $voteLabel): string {
        return match ($voteLabel) {
            'GO' => 'GO',
            'NO_GO' => 'NO-GO',
            'NO_CONSENSUS' => 'ITERATE',
            default => 'ITERATE',
        };
    }

    private function confidenceFromScore(float $score, float $threshold): string {
        if ($score >= max(0.70, $threshold + 0.10)) {
            return 'high';
        }
        if ($score >= $threshold) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * @param array<string,mixed> $contextQuality
     * @param array<string,mixed> $falseConsensus
     * @param array<string,mixed> $adjustedDecision
     * @param array<string,mixed> $decisionSummary
     * @return array<int,string>
     */
    private function collectWarnings(
        array $contextQuality,
        array $falseConsensus,
        array $adjustedDecision,
        array $decisionSummary
    ): array {
        $issueKeys = [];
        foreach (($decisionSummary['top_issues'] ?? []) as $row) {
            if (is_array($row) && !empty($row['key'])) {
                $issueKeys[(string)$row['key']] = true;
            }
        }

        $warnings = [];
        foreach (($contextQuality['warnings'] ?? []) as $w) {
            $s = (string)$w;
            if ($this->warningRedundantWithIssues($s, $issueKeys, (string)($adjustedDecision['final_outcome'] ?? ''))) {
                continue;
            }
            $warnings[] = $s;
        }
        foreach (($falseConsensus['signals'] ?? []) as $signal) {
            if (!empty($signal['message'])) {
                $msg = (string)$signal['message'];
                if ($this->warningRedundantWithIssues($msg, $issueKeys, (string)($adjustedDecision['final_outcome'] ?? ''))) {
                    continue;
                }
                $warnings[] = $msg;
            }
        }
        if (!empty($adjustedDecision['capped']) && !isset($issueKeys['reliability.issue.cap_applied'])) {
            $warnings[] = 'Decision confidence was capped by context reliability policy.';
        }
        if (($adjustedDecision['final_outcome'] ?? '') === 'INSUFFICIENT_CONTEXT' && !isset($issueKeys['reliability.issue.insufficient_context'])) {
            $warnings[] = 'Decision is marked as insufficient context and should not be operationalized yet.';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param array<string,true> $issueKeys
     */
    private function warningRedundantWithIssues(string $warning, array $issueKeys, string $finalOutcome): bool {
        if ($finalOutcome === 'INSUFFICIENT_CONTEXT' && str_contains(strtolower($warning), 'too short')) {
            return isset($issueKeys['reliability.issue.insufficient_context']);
        }
        if (isset($issueKeys['reliability.issue.low_contradiction']) && str_contains(strtolower($warning), 'contradiction')) {
            return true;
        }
        return false;
    }
}

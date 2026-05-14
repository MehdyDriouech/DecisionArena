<?php
namespace Domain\Orchestration;

/**
 * Executable projection derived from CanonicalSynthesisExtractor output.
 *
 * This is not a second verdict system and not a playbook SSOT. It converts the
 * canonical runtime synthesis into a user-facing decision outcome: what to do,
 * what blocks execution, and which playbook outcomes are actionable.
 */
class DecisionOutcomeProjector {
    public const CONTRACT_VERSION = RuntimeContracts::DECISION_OUTCOME_CONTRACT_VERSION;

    /** @return array<string,mixed> */
    public static function fromCanonical(array $canonical, array $context = []): array {
        $playbookRuntime = is_array($context['playbook_runtime'] ?? null) ? $context['playbook_runtime'] : [];
        $riskProfile = is_array($context['risk_profile'] ?? null) ? $context['risk_profile'] : [];
        $guardrails = is_array($context['guardrails'] ?? null) ? $context['guardrails'] : [];

        $statusResolution = self::resolveStatus($canonical, $context, $guardrails);
        $status = $statusResolution['status'];

        $actionsResolution = self::resolveRequiredNextActions($canonical, $context);
        $actions = $actionsResolution['actions'];
        $unknowns = self::list($canonical['blocking_unknowns'] ?? []);
        $risks = self::list($canonical['risks'] ?? []);
        $why = self::list($canonical['why'] ?? []);
        if ($unknowns === []) {
            $unknowns = self::inferUnknowns($canonical, $playbookRuntime);
        }

        $playbookOutcomes = self::normalizePlaybookOutcomes(
            (string)($canonical['playbook_id'] ?? $playbookRuntime['playbook_id'] ?? ''),
            is_array($canonical['outcomes'] ?? null) ? $canonical['outcomes'] : []
        );

        $validation = is_array($canonical['validation_logic'] ?? null) ? $canonical['validation_logic'] : [];
        $validationLogic = [
            'success_signal' => self::text($validation['success_signal'] ?? ''),
            'validation_threshold' => self::text($validation['validation_threshold'] ?? ''),
            'failure_signal' => self::text($validation['failure_signal'] ?? ''),
            'kill_criteria' => self::text($validation['kill_criteria'] ?? ''),
        ];
        $evidenceClaims = self::normalizeEvidenceClaims($canonical['evidence_claims'] ?? []);
        $confidenceInfo = ConfidenceNormalizer::normalize($canonical, $context, [
            'status' => $status,
            'actions' => $actions,
            'unknowns' => $unknowns,
            'playbook_outcomes' => $playbookOutcomes,
            'validation_logic' => $validationLogic,
            'evidence_claims' => $evidenceClaims,
        ]);
        $confidence = (string)($confidenceInfo['level'] ?? 'weak');
        $decisionSummary = self::summary($status, $why, $actions, $unknowns);
        $warnings = self::validateOutcome($status, $actions, $playbookOutcomes, (string)($canonical['playbook_id'] ?? $playbookRuntime['playbook_id'] ?? ''));

        $parserWarnings = $canonical['parser_diagnostics']['warnings'] ?? [];
        $runtimeWarnings = $playbookRuntime['warnings'] ?? [];
        $warnings = array_values(array_unique(array_merge(
            $warnings,
            is_array($parserWarnings) ? $parserWarnings : [],
            is_array($runtimeWarnings) ? $runtimeWarnings : []
        )));

        $persistenceSafety = self::buildPersistenceSafety($canonical, $status, $actions, $unknowns, $warnings);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'taxonomy_version' => RuntimeContracts::TAXONOMY_VERSION,
            'status' => $status,
            'confidence' => $confidence,
            'confidence_explanation' => is_array($confidenceInfo['reasons'] ?? null) ? $confidenceInfo['reasons'] : [],
            'uncertainty_signals' => is_array($confidenceInfo['signals'] ?? null) ? $confidenceInfo['signals'] : [],
            'blocking_unknowns' => $unknowns,
            'required_next_actions' => $actions,
            'decision_summary' => $decisionSummary,
            'execution_risk_level' => self::executionRiskLevel($riskProfile, $risks),
            'validation_logic' => $validationLogic,
            'playbook_specific_outcomes' => $playbookOutcomes,
            'evidence_claims' => $evidenceClaims,
            'evidence_summary' => is_array($canonical['evidence_summary'] ?? null) ? $canonical['evidence_summary'] : null,
            'persistence_safety' => $persistenceSafety,
            'diagnostics' => [
                'warnings' => $warnings,
                'source' => 'canonical_synthesis',
                'parser_confidence' => $canonical['parser_diagnostics']['parser_confidence'] ?? null,
                'missing_fields' => $canonical['parser_diagnostics']['missing_fields'] ?? [],
                'fallback_used' => $canonical['parser_diagnostics']['fallback_used'] ?? false,
                'extraction_strategy_used' => $canonical['parser_diagnostics']['extraction_strategy_used'] ?? [],
                'status_source' => $statusResolution['source'],
                'required_next_actions_source' => $actionsResolution['source'],
                'required_next_actions_rejected' => $actionsResolution['rejected'],
                'required_next_actions_rejection_reason' => $actionsResolution['rejection_reason'],
                'confidence_diagnostics' => $confidenceInfo,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $canonical
     * @param array<string,mixed> $context
     * @return array{status:string,source:string}
     */
    private static function resolveStatus(array $canonical, array $context, array $guardrails): array
    {
        $statusCandidates = [
            ['source' => 'canonical.status', 'value' => $canonical['status'] ?? null],
            ['source' => 'canonical.decision_status', 'value' => $canonical['decision_status'] ?? null],
            ['source' => 'canonical.recommended_status', 'value' => $canonical['recommended_status'] ?? null],
            ['source' => 'context.status', 'value' => $context['status'] ?? null],
            ['source' => 'context.decision_status', 'value' => $context['decision_status'] ?? null],
            ['source' => 'context.recommended_status', 'value' => $context['recommended_status'] ?? null],
        ];
        foreach ($statusCandidates as $candidate) {
            $raw = trim((string)($candidate['value'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $norm = RuntimeContracts::normalizeStatus($raw);
            if ($norm !== '') {
                return ['status' => $norm, 'source' => (string)$candidate['source']];
            }
        }

        $decisionCandidates = [
            ['source' => 'canonical.decision', 'value' => $canonical['decision'] ?? null],
            ['source' => 'canonical.decision_label', 'value' => $canonical['decision_label'] ?? null],
            ['source' => 'canonical.outcome', 'value' => $canonical['outcome'] ?? null],
            ['source' => 'canonical.final_verdict', 'value' => $canonical['final_verdict'] ?? null],
            ['source' => 'canonical.verdict', 'value' => $canonical['verdict'] ?? null],
            ['source' => 'context.decision', 'value' => $context['decision'] ?? null],
            ['source' => 'context.decision_label', 'value' => $context['decision_label'] ?? null],
            ['source' => 'context.outcome', 'value' => $context['outcome'] ?? null],
            ['source' => 'context.final_verdict', 'value' => $context['final_verdict'] ?? null],
            ['source' => 'context.verdict', 'value' => $context['verdict'] ?? null],
        ];
        foreach ($decisionCandidates as $candidate) {
            $mapped = self::normalizeStatus(
                (string)($candidate['value'] ?? ''),
                (string)($canonical['status'] ?? ''),
                $guardrails
            );
            if ($mapped !== '') {
                return ['status' => $mapped, 'source' => (string)$candidate['source']];
            }
        }

        $fallback = RuntimeContracts::normalizeStatus((string)($canonical['status'] ?? ''));
        return ['status' => $fallback, 'source' => $fallback !== '' ? 'canonical.status_fallback' : 'none'];
    }

    /**
     * @param array<string,mixed> $canonical
     * @param array<string,mixed> $context
     * @return array{
     *   actions:list<string>,
     *   source:string,
     *   rejected:list<string>,
     *   rejection_reason:string
     * }
     */
    private static function resolveRequiredNextActions(array $canonical, array $context): array
    {
        $candidates = [
            ['source' => 'canonical.required_next_actions', 'value' => $canonical['required_next_actions'] ?? null],
            ['source' => 'canonical.recommended_next_actions', 'value' => $canonical['recommended_next_actions'] ?? null],
            ['source' => 'canonical.next_steps', 'value' => $canonical['next_steps'] ?? null],
            ['source' => 'context.required_next_actions', 'value' => $context['required_next_actions'] ?? null],
            ['source' => 'context.next_steps', 'value' => $context['next_steps'] ?? null],
            ['source' => 'context.decision_brief.next_step', 'value' => is_array($context['decision_brief'] ?? null) ? (($context['decision_brief']['next_step'] ?? null)) : null],
            ['source' => 'context.decision_brief_next_step', 'value' => $context['decision_brief_next_step'] ?? null],
        ];

        $rejected = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalizeActionCandidates($candidate['value'] ?? null);
            if ($normalized['actions'] !== []) {
                return [
                    'actions' => $normalized['actions'],
                    'source' => (string)$candidate['source'],
                    'rejected' => $normalized['rejected'],
                    'rejection_reason' => '',
                ];
            }
            if ($normalized['rejected'] !== []) {
                $rejected = array_values(array_unique(array_merge($rejected, $normalized['rejected'])));
            }
        }

        return [
            'actions' => [],
            'source' => 'none',
            'rejected' => array_slice($rejected, 0, 8),
            'rejection_reason' => 'no_executable_action_found',
        ];
    }

    /**
     * Computes memory/persistence safety for future Decision Memory.
     * No persistence is performed here; this is a contract-only safety envelope.
     *
     * @param array<string,mixed> $canonical
     * @param list<string> $actions
     * @param list<string> $unknowns
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private static function buildPersistenceSafety(array $canonical, string $status, array $actions, array $unknowns, array $warnings): array
    {
        $diag = is_array($canonical['parser_diagnostics'] ?? null) ? $canonical['parser_diagnostics'] : [];
        $parserConfidence = is_numeric($diag['parser_confidence'] ?? null) ? (float)$diag['parser_confidence'] : 0.0;
        $missing = is_array($diag['missing_fields'] ?? null) ? array_values($diag['missing_fields']) : [];
        $fallbackUsed = (bool)($diag['fallback_used'] ?? false);
        $strategies = is_array($diag['extraction_strategy_used'] ?? null) ? array_values($diag['extraction_strategy_used']) : [];
        $repaired = is_array($diag['repaired_fields'] ?? null) ? array_values($diag['repaired_fields']) : [];

        $missingCritical = [];
        if (trim($status) === '') {
            $missingCritical[] = 'status';
        }
        if ($actions === []) {
            $missingCritical[] = 'required_next_actions';
        }

        $severeWarnings = [];
        foreach ($warnings as $w) {
            $lw = strtolower((string)$w);
            if (str_contains($lw, 'empty_synthesis_output')
                || str_contains($lw, 'json_candidate_unreadable')
                || str_contains($lw, 'partial_synthesis_contract')
                || str_contains($lw, 'low_completeness')
            ) {
                $severeWarnings[] = (string)$w;
            }
        }

        // Keep "derived_from_fallback" observable, but distinguish "fallback-heavy" vs benign recovery.
        $fallbackStrategies = array_intersect($strategies, ['heuristic_recovery', 'fallback_inference', 'graceful_degradation']);
        $derivedFromFallback = ($repaired !== []) || ($fallbackStrategies !== []) || ($parserConfidence < 0.45);
        $fallbackHeavy = ($repaired !== [])
            || in_array('graceful_degradation', $strategies, true)
            || ($parserConfidence < 0.70)
            || (count($missing) >= 3);

        $requiresConfirmation = false;
        $reason = '';

        if ($missingCritical !== []) {
            $requiresConfirmation = true;
            $reason = 'Missing critical decision fields for persistence.';
        } elseif ($fallbackHeavy) {
            $requiresConfirmation = true;
            $reason = 'Derived from parser fallback/repair; requires user confirmation before persistence.';
        } elseif ($severeWarnings !== []) {
            $requiresConfirmation = true;
            $reason = 'Severe parser/runtime warnings present; requires confirmation.';
        } elseif ((string)($canonical['confidence'] ?? '') === 'weak' && count($unknowns) >= 2) {
            $requiresConfirmation = true;
            $reason = 'Weak confidence with unresolved unknowns; requires confirmation.';
        }

        $safeToPersist = !$requiresConfirmation;

        return [
            'safe_to_persist' => $safeToPersist,
            'reason' => $reason,
            'derived_from_fallback' => $derivedFromFallback,
            'parser_confidence' => round($parserConfidence, 2),
            'missing_critical_fields' => $missingCritical,
            'requires_user_confirmation' => $requiresConfirmation,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function normalizeEvidenceClaims(mixed $claims): array {
        if (!is_array($claims)) {
            return [];
        }
        $allowedTypes = ['fact', 'assumption', 'signal', 'intuition', 'unknown'];
        $allowedStatuses = ['verified', 'weak_evidence', 'assumption', 'unknown', 'contradicted'];
        $out = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $text = self::text($claim['claim'] ?? $claim['claim_text'] ?? '');
            if ($text === '') {
                continue;
            }
            $type = (string)($claim['claim_type'] ?? 'assumption');
            $status = (string)($claim['verification_status'] ?? 'weak_evidence');
            $out[] = [
                'claim' => $text,
                'claim_type' => in_array($type, $allowedTypes, true) ? $type : 'assumption',
                'confidence' => self::text($claim['confidence'] ?? 'moderate') ?: 'moderate',
                'supporting_evidence' => self::list($claim['supporting_evidence'] ?? []),
                'contradictions' => self::list($claim['contradictions'] ?? []),
                'verification_status' => in_array($status, $allowedStatuses, true) ? $status : 'weak_evidence',
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private static function normalizeStatus(string $decision, string $canonicalStatus, array $guardrails): string {
        $override = strtoupper((string)($guardrails['final_outcome_override'] ?? ''));
        if (in_array($override, ['INSUFFICIENT_CONTEXT', 'NO_CONSENSUS', 'NO_CONSENSUS_FRAGILE'], true)) {
            return 'validate_first';
        }

        $d = strtoupper(trim(str_replace(['_', '-'], ' ', $decision)));
        if (in_array($d, ['GO', 'PROCEED', 'PURSUE', 'APPROVE', 'BUILD', 'CONTINUE'], true)) {
            $s = strtoupper($canonicalStatus);
            return in_array($s, ['FRAGILE', 'INSUFFICIENT_CONTEXT'], true) ? 'proceed_with_constraints' : 'proceed';
        }
        if (in_array($d, ['NO GO', 'NO_GO', 'KILL', 'STOP', 'REJECT'], true)) {
            return 'kill';
        }
        if (in_array($d, ['ITERATE', 'PIVOT', 'NARROW', 'DEFER', 'REDUCE SCOPE', 'PAUSE', 'TEST FIRST'], true)) {
            return 'pivot';
        }
        if (in_array($d, ['INSUFFICIENT CONTEXT', 'NEEDS MORE INFO', 'NO CONSENSUS', 'VALIDATE FIRST', 'BLOCKED'], true)) {
            return 'validate_first';
        }
        // Fallback to canonical runtime taxonomy (prevents empty/un-normalized status in weak prose).
        $norm = RuntimeContracts::normalizeStatus($canonicalStatus);
        return $norm !== '' ? $norm : '';
    }

    /** @param array<string,mixed> $outcomes @return array<string,mixed> */
    private static function normalizePlaybookOutcomes(string $playbookId, array $outcomes): array {
        $map = [
            'founder-sprint' => [
                'validation_signal' => ['validation_signal'],
                'kill_criteria' => ['kill_criteria'],
                'next_experiment' => ['next_experiment'],
                'wedge_critique' => ['wedge_critique'],
                'icp_challenge' => ['icp_challenge'],
            ],
            'jury' => [
                'recommended_option' => ['final_recommendation'],
                'tradeoffs' => ['pros_cons_by_perspective'],
                'arbitration_confidence' => ['confidence_level'],
                'evaluation_criteria' => ['evaluation_criteria'],
            ],
            'stress-test' => [
                'failure_scenarios' => ['failure_scenarios'],
                'weakest_assumptions' => ['weakest_assumptions'],
                'evidence_gaps' => ['evidence_gaps'],
                'pivot_kill_signals' => ['pivot_kill_signals'],
            ],
            'quick-decision' => [
                'immediate_action' => ['immediate_next_action'],
                'main_constraint' => ['key_constraint'],
                'best_available_option' => ['best_available_option'],
                'main_risk' => ['main_risk'],
            ],
            'ceo-challenge' => [
                'strategic_assumptions' => ['strategic_assumptions'],
                'blind_spots' => ['blind_spots'],
                'execution_risks' => ['execution_risks'],
                'tradeoff_analysis' => ['tradeoff_analysis'],
                'leadership_decision_memo' => ['leadership_decision_memo'],
            ],
            'confrontation' => [
                'position_a' => ['position_a'],
                'position_b' => ['position_b'],
                'conflict_points' => ['conflict_points'],
                'strongest_arguments' => ['strongest_arguments'],
                'decision_path' => ['synthesis_or_decision_path'],
            ],
        ];

        $shape = $map[$playbookId] ?? [];
        if ($shape === []) {
            return array_filter($outcomes, fn($v) => self::text($v) !== '');
        }

        $out = [];
        foreach ($shape as $target => $sources) {
            foreach ($sources as $source) {
                $value = self::text($outcomes[$source] ?? '');
                if ($value !== '') {
                    $out[$target] = $value;
                    break;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function validateOutcome(string $status, array $actions, array $playbookOutcomes, string $playbookId): array {
        $warnings = [];
        if ($status === '') {
            $warnings[] = 'decision_outcome_missing_status';
        }
        if ($actions === []) {
            $warnings[] = 'decision_outcome_missing_next_action';
        }
        if ($playbookId === 'founder-sprint' && empty($playbookOutcomes['next_experiment'])) {
            $warnings[] = 'decision_outcome_founder_sprint_missing_next_experiment';
        }
        if ($playbookId === 'jury' && empty($playbookOutcomes['recommended_option'])) {
            $warnings[] = 'decision_outcome_jury_missing_recommended_option';
        }
        return $warnings;
    }

    /** @return list<string> */
    private static function inferUnknowns(array $canonical, array $playbookRuntime): array {
        $missing = $canonical['parser_diagnostics']['missing_fields'] ?? [];
        $pbMissing = $playbookRuntime['missing_sections'] ?? [];
        $out = [];
        foreach (array_slice(array_merge(is_array($missing) ? $missing : [], is_array($pbMissing) ? $pbMissing : []), 0, 4) as $field) {
            $out[] = 'Missing or weak signal: ' . str_replace('_', ' ', (string)$field);
        }
        return array_values(array_unique($out));
    }

    private static function executionRiskLevel(array $riskProfile, array $risks): string {
        $level = strtolower(trim((string)($riskProfile['risk_level'] ?? '')));
        if (in_array($level, ['low', 'medium', 'high', 'critical'], true)) {
            return $level;
        }
        if (count($risks) >= 3) return 'high';
        if (count($risks) >= 1) return 'medium';
        return 'unknown';
    }

    private static function summary(string $status, array $why, array $actions, array $unknowns): string {
        $label = $status !== '' ? str_replace('_', ' ', $status) : 'decision pending';
        $reason = $why[0] ?? '';
        $action = $actions[0] ?? '';
        if ($action !== '') {
            return trim("{$label}: {$action}" . ($reason !== '' ? " ({$reason})" : ''));
        }
        if ($reason !== '') {
            return trim("{$label}: {$reason}");
        }
        if (!empty($unknowns)) {
            return "{$label}: resolve blocking unknowns before execution.";
        }
        return $label;
    }

    /** @return list<string> */
    private static function list(mixed $value): array {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $txt = self::text($item);
            if ($txt !== '') {
                $out[] = $txt;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    /**
     * @return array{actions:list<string>,rejected:list<string>}
     */
    private static function normalizeActionCandidates(mixed $value): array
    {
        $rawItems = [];
        if (is_string($value)) {
            $rawItems = preg_split('/\r?\n|[;•]/', $value) ?: [];
        } elseif (is_array($value)) {
            $rawItems = $value;
        }

        $actions = [];
        $rejected = [];
        foreach ($rawItems as $item) {
            $text = self::text($item);
            if ($text === '') {
                continue;
            }
            $text = preg_replace('/^[-*]\s*/', '', $text) ?? $text;
            $text = preg_replace('/^\d+[.)]\s*/', '', $text) ?? $text;
            $text = preg_replace('/^#+\s*/', '', $text) ?? $text;
            $text = trim((string)$text);
            if ($text === '') {
                continue;
            }
            $lower = strtolower($text);
            if (preg_match('/\b(analy[sz]e the situation|analyser la situation|prepare validation|préparer la validation|réfléchir à la suite|think about next steps)\b/i', $lower)) {
                $rejected[] = $text;
                continue;
            }
            if (preg_match('/^next steps?$/i', $text) || preg_match('/^required next actions?$/i', $text) || str_starts_with($lower, '```')) {
                $rejected[] = $text;
                continue;
            }
            if (mb_strlen($text, 'UTF-8') < 12) {
                $rejected[] = $text;
                continue;
            }
            if (preg_match('/\b(tbd|n\/a|unknown|to be defined|à définir|à confirmer)\b/i', $text)) {
                $rejected[] = $text;
                continue;
            }
            if (!preg_match('/\b(launch|run|define|collect|validate|measure|build|draft|test|ship|send|simulate|create|document|plan|implement|review|align|prepare|verify|vérifier|valider|définir|lancer|mesurer|tester|simuler|envoyer|documenter|planifier|mettre en œuvre|prioriser|collecter|analyser|corriger|déployer)\b/i', $text)) {
                $rejected[] = $text;
                continue;
            }
            $actions[] = $text;
            if (count($actions) >= 6) {
                break;
            }
        }

        return [
            'actions' => array_values(array_unique($actions)),
            'rejected' => array_slice(array_values(array_unique($rejected)), 0, 10),
        ];
    }

    private static function text(mixed $value): string {
        if (is_array($value)) {
            $value = $value['text'] ?? $value['description'] ?? $value['title'] ?? json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return trim(preg_replace('/\s+/', ' ', (string)$value));
    }
}

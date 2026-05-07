<?php
namespace Domain\Orchestration;

/**
 * Coarse, explainable confidence normalization for executable outcomes.
 *
 * This is intentionally not a probability model. It turns runtime diagnostics,
 * evidence discipline, guardrails, and playbook completeness into a stable
 * weak/moderate/strong label with human-readable reasons.
 */
class ConfidenceNormalizer {
    /** @param array<string,mixed> $canonical @param array<string,mixed> $context @param array<string,mixed> $derived @return array<string,mixed> */
    public static function normalize(array $canonical, array $context = [], array $derived = []): array {
        $level = self::baseLevel((string)($canonical['confidence'] ?? ''), $canonical['parser_diagnostics']['parser_confidence'] ?? null);
        $reasons = [];
        $downgrades = [];

        $parserDiagnostics = is_array($canonical['parser_diagnostics'] ?? null) ? $canonical['parser_diagnostics'] : [];
        $parserConfidence = is_numeric($parserDiagnostics['parser_confidence'] ?? null) ? (float)$parserDiagnostics['parser_confidence'] : null;
        $missingFields = self::list($parserDiagnostics['missing_fields'] ?? []);
        $fallbackUsed = (bool)($parserDiagnostics['fallback_used'] ?? false);
        $parserWarnings = self::list($parserDiagnostics['warnings'] ?? []);
        $guardrails = is_array($context['guardrails'] ?? null) ? $context['guardrails'] : [];
        $guardrailWarnings = array_merge(self::list($guardrails['warnings'] ?? []), self::list($guardrails['reliability_warnings'] ?? []));

        $unknowns = self::list($derived['unknowns'] ?? []);
        $actions = self::list($derived['actions'] ?? []);
        $playbookOutcomes = is_array($derived['playbook_outcomes'] ?? null) ? $derived['playbook_outcomes'] : [];
        $validation = is_array($derived['validation_logic'] ?? null) ? $derived['validation_logic'] : [];
        $claims = is_array($derived['evidence_claims'] ?? null) ? $derived['evidence_claims'] : [];
        $status = (string)($derived['status'] ?? '');
        $playbookId = (string)($canonical['playbook_id'] ?? ($context['playbook_runtime']['playbook_id'] ?? ''));

        if ($parserConfidence !== null && $parserConfidence < 0.45) {
            self::cap($level, 'weak');
            $downgrades[] = 'low_parser_reliability';
            $reasons[] = 'Extraction was partial or unstable.';
        } elseif ($parserConfidence !== null && $parserConfidence < 0.70) {
            self::cap($level, 'moderate');
            $downgrades[] = 'moderate_parser_reliability';
            $reasons[] = 'Parser reliability is only moderate.';
        }
        if ($fallbackUsed) {
            self::cap($level, 'moderate');
            $downgrades[] = 'fallback_used';
            $reasons[] = 'Fallback extraction was needed.';
        }
        if (count($missingFields) >= 3) {
            self::cap($level, 'weak');
            $downgrades[] = 'many_missing_fields';
            $reasons[] = 'Several expected synthesis fields were missing.';
        } elseif (count($missingFields) > 0) {
            self::cap($level, 'moderate');
            $downgrades[] = 'missing_fields';
            $reasons[] = 'Some expected synthesis fields were missing.';
        }

        $evidence = self::evidenceCounts($claims);
        if ($evidence['contradicted'] > 0) {
            self::cap($level, 'weak');
            $downgrades[] = 'contradictions_detected';
            $reasons[] = 'Contradictory signals were detected.';
        }
        if ($evidence['unknown'] >= 2 || count($unknowns) >= 2) {
            self::cap($level, 'weak');
            $downgrades[] = 'critical_unknowns';
            $reasons[] = 'Critical unknowns still block confidence.';
        } elseif ($evidence['unknown'] > 0 || count($unknowns) > 0) {
            self::cap($level, 'moderate');
            $downgrades[] = 'unknowns_present';
            $reasons[] = 'At least one important unknown remains.';
        }
        if (($evidence['weak_evidence'] + $evidence['assumption']) >= 3) {
            self::cap($level, 'moderate');
            $downgrades[] = 'weak_or_assumption_based_evidence';
            $reasons[] = 'Important claims rely on assumptions or weak evidence.';
        }

        if ($status === '') {
            self::cap($level, 'weak');
            $downgrades[] = 'missing_status';
            $reasons[] = 'No stable decision status was extracted.';
        }
        if ($actions === []) {
            self::cap($level, 'weak');
            $downgrades[] = 'missing_next_action';
            $reasons[] = 'No executable next action was extracted.';
        }
        if (self::validationEmpty($validation)) {
            self::cap($level, 'moderate');
            $downgrades[] = 'weak_validation_logic';
            $reasons[] = 'Validation logic is incomplete.';
        }

        $playbookGaps = self::playbookGaps($playbookId, $playbookOutcomes);
        if ($playbookGaps !== []) {
            self::cap($level, count($playbookGaps) >= 2 ? 'weak' : 'moderate');
            $downgrades[] = 'playbook_specific_gaps';
            $reasons[] = self::playbookReason($playbookId, $playbookGaps);
        }

        foreach (array_merge($parserWarnings, $guardrailWarnings) as $warning) {
            $w = strtolower($warning);
            if (str_contains($w, 'false') && str_contains($w, 'consensus')) {
                self::cap($level, 'weak');
                $downgrades[] = 'false_consensus_risk';
                $reasons[] = 'Consensus may be misleading.';
                continue;
            }
            if (str_contains($w, 'no consensus') || str_contains($w, 'low contradiction') || str_contains($w, 'disagreement')) {
                self::cap($level, 'moderate');
                $downgrades[] = 'consensus_fragility';
                $reasons[] = 'Agreement between agents is fragile or under-challenged.';
                continue;
            }
            if (str_contains($w, 'insufficient') || str_contains($w, 'weak context') || str_contains($w, 'truncated')) {
                self::cap($level, 'weak');
                $downgrades[] = 'weak_context';
                $reasons[] = 'Input context is too weak for strong confidence.';
            }
        }

        $reasons = array_values(array_unique(array_filter($reasons)));
        $downgrades = array_values(array_unique($downgrades));
        if ($reasons === []) {
            $reasons[] = $level === 'strong'
                ? 'Core decision fields were extracted cleanly.'
                : 'Confidence is limited by incomplete runtime signals.';
        }

        return [
            'level' => $level,
            'reasons' => array_slice($reasons, 0, 6),
            'signals' => [
                'model_confidence' => self::baseLevel((string)($canonical['confidence'] ?? ''), null),
                'parser_reliability' => self::parserLevel($parserConfidence),
                'critical_unknowns' => max(count($unknowns), $evidence['unknown']),
                'contradictions' => $evidence['contradicted'],
                'weak_evidence_claims' => $evidence['weak_evidence'],
                'assumption_claims' => $evidence['assumption'],
                'fallback_used' => $fallbackUsed,
                'missing_fields' => $missingFields,
                'playbook_gaps' => $playbookGaps,
                'guardrail_warnings' => array_slice($guardrailWarnings, 0, 5),
            ],
            'downgrades' => $downgrades,
        ];
    }

    private static function baseLevel(string $confidence, mixed $parserConfidence): string {
        $norm = RuntimeContracts::normalizeConfidence($confidence);
        if ($norm !== '') {
            return $norm;
        }
        if (is_numeric($parserConfidence)) {
            return self::parserLevel((float)$parserConfidence);
        }
        return 'weak';
    }

    private static function parserLevel(?float $parserConfidence): string {
        if ($parserConfidence === null) return 'unknown';
        if ($parserConfidence >= 0.78) return 'strong';
        if ($parserConfidence >= 0.45) return 'moderate';
        return 'weak';
    }

    private static function cap(string &$level, string $max): void {
        $rank = ['weak' => 0, 'moderate' => 1, 'strong' => 2, 'unknown' => 0];
        if (($rank[$level] ?? 0) > ($rank[$max] ?? 0)) {
            $level = $max;
        }
    }

    /** @return array{verified:int,weak_evidence:int,assumption:int,unknown:int,contradicted:int} */
    private static function evidenceCounts(array $claims): array {
        $counts = ['verified' => 0, 'weak_evidence' => 0, 'assumption' => 0, 'unknown' => 0, 'contradicted' => 0];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $status = (string)($claim['verification_status'] ?? 'weak_evidence');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if (!empty($claim['contradictions']) && is_array($claim['contradictions'])) {
                $counts['contradicted']++;
            }
        }
        return $counts;
    }

    /** @return list<string> */
    private static function playbookGaps(string $playbookId, array $outcomes): array {
        $required = [
            'founder-sprint' => ['validation_signal', 'kill_criteria', 'next_experiment'],
            'stress-test' => ['failure_scenarios', 'weakest_assumptions'],
            'jury' => ['recommended_option', 'evaluation_criteria', 'arbitration_confidence'],
            'quick-decision' => ['immediate_action', 'main_constraint'],
        ][$playbookId] ?? [];
        $missing = [];
        foreach ($required as $field) {
            if (self::text($outcomes[$field] ?? '') === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    private static function playbookReason(string $playbookId, array $gaps): string {
        $labels = implode(', ', array_map(fn($x) => str_replace('_', ' ', (string)$x), $gaps));
        return match ($playbookId) {
            'founder-sprint' => "Founder Sprint confidence is limited without {$labels}.",
            'stress-test' => "Stress Test confidence is limited while {$labels} is missing.",
            'jury' => "Jury confidence is limited without argumentative convergence on {$labels}.",
            'quick-decision' => "Quick Decision confidence is limited without {$labels}.",
            default => "Playbook-specific confidence is limited because {$labels} is missing.",
        };
    }

    private static function validationEmpty(array $validation): bool {
        foreach (['success_signal', 'validation_threshold', 'failure_signal', 'kill_criteria'] as $key) {
            if (self::text($validation[$key] ?? '') !== '') {
                return false;
            }
        }
        return true;
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
        return array_values(array_unique($out));
    }

    private static function text(mixed $value): string {
        if (is_array($value)) {
            $value = $value['text'] ?? $value['description'] ?? $value['title'] ?? json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return trim(preg_replace('/\s+/', ' ', (string)$value));
    }
}

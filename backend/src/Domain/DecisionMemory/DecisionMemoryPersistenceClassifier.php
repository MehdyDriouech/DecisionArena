<?php

declare(strict_types=1);

namespace Domain\DecisionMemory;

use Domain\Orchestration\RuntimeContracts;

/**
 * Contrat produit : une session completed avec outcome + canonical peut être persistée manuellement.
 * Qualité / contradictions reflétées dans persistence_safety (JSON) + memory_state (confirmed | needs_review).
 */
final class DecisionMemoryPersistenceClassifier
{
    /**
     * @param array<string,mixed> $runResult JSON session.result décodé
     * @param array<string,mixed> $session   Ligne sessions (status, …)
     *
     * @return array{
     *   persistable:bool,
     *   technical_reason?:string,
     *   patched_outcome:array<string,mixed>,
     *   enriched_persistence_safety:array<string,mixed>,
     *   memory_state:string,
     *   persistence_quality:string
     * }
     */
    public static function prepareForManualPersistence(array $runResult, array $session): array
    {
        $st = strtolower(trim((string)($session['status'] ?? '')));
        if ($st !== 'completed') {
            return self::deny('session_not_completed');
        }

        $outcome = $runResult['decision_outcome'] ?? null;
        $canonical = $runResult['canonical_synthesis'] ?? null;
        if (!is_array($outcome) || !is_array($canonical)) {
            return self::deny('missing_outcome_or_canonical');
        }

        $playbookId = trim((string)($canonical['playbook_id'] ?? ''));
        if ($playbookId === '') {
            return self::deny('missing_playbook_id');
        }

        /** @var array<string,mixed> $patched */
        $patched = self::deepCopyOutcome($outcome);

        $rawStatus = trim((string)($patched['status'] ?? ''));
        $originalOutcomeLabel = $rawStatus !== '' ? $rawStatus : '(empty)';
        $normStatus = RuntimeContracts::normalizeStatus($rawStatus);
        if ($normStatus === '') {
            $patched['status'] = 'validate_first';
            $normStatus = 'validate_first';
        }

        $rawConf = trim((string)($patched['confidence'] ?? ''));
        $normConf = RuntimeContracts::normalizeConfidence($rawConf);
        if ($normConf === '') {
            $patched['confidence'] = 'weak';
            $normConf = 'weak';
        }

        $summary = trim((string)($patched['decision_summary'] ?? ''));
        if ($summary === '') {
            $patched['decision_summary'] = 'No decision summary extracted; decision memory persisted for human review.';
        }

        $next = $patched['required_next_actions'] ?? null;
        if (!is_array($next)) {
            $patched['required_next_actions'] = [];
        }

        $ps = is_array($patched['persistence_safety'] ?? null) ? $patched['persistence_safety'] : [];
        $missingCritical = is_array($ps['missing_critical_fields'] ?? null)
            ? array_values(array_filter(array_map('strval', $ps['missing_critical_fields'])))
            : [];
        $safe = ($ps['safe_to_persist'] ?? false) === true;
        $derivedFallback = ($ps['derived_from_fallback'] ?? false) === true;

        $verdict = $runResult['verdict'] ?? null;
        $verdictLabel = is_array($verdict) ? strtolower(trim((string)($verdict['verdict_label'] ?? ''))) : '';

        $rel = self::extractReliabilityStatus($runResult);
        $weakReliability = self::isWeakReliability($rel);

        $contradictions = self::detectSignalContradictions($normStatus, $verdictLabel, $weakReliability, $derivedFallback);

        $persistenceQuality = 'full';
        if ($contradictions !== []) {
            $persistenceQuality = 'contradictory';
        } elseif ($derivedFallback) {
            $persistenceQuality = 'fallback';
        } elseif ($missingCritical !== [] || !$safe) {
            $persistenceQuality = 'partial';
        }

        $needsReview = $persistenceQuality !== 'full' || !$safe || $missingCritical !== [];

        $memoryState = ($persistenceQuality === 'full' && $safe && $missingCritical === []) ? 'confirmed' : 'needs_review';

        $ps['da_manual_persist'] = true;
        $ps['da_persistence_quality'] = $persistenceQuality;
        $ps['da_review_required'] = $needsReview;
        $ps['da_decision_signal_contradictions'] = $contradictions;
        $ps['da_verdict_label'] = $verdictLabel !== '' ? $verdictLabel : null;
        $ps['da_original_outcome_status_label'] = $originalOutcomeLabel;
        $ps['da_normalized_status'] = $normStatus;
        $ps['da_reliability_status'] = $rel !== '' ? $rel : null;

        $patched['persistence_safety'] = $ps;

        return [
            'persistable' => true,
            'patched_outcome' => $patched,
            'enriched_persistence_safety' => $ps,
            'memory_state' => $memoryState,
            'persistence_quality' => $persistenceQuality,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function deny(string $reason): array
    {
        return [
            'persistable' => false,
            'technical_reason' => $reason,
            'patched_outcome' => [],
            'enriched_persistence_safety' => [],
            'memory_state' => 'active',
            'persistence_quality' => 'none',
        ];
    }

    /**
     * @param array<string,mixed> $outcome
     *
     * @return array<string,mixed>
     */
    private static function deepCopyOutcome(array $outcome): array
    {
        $json = json_encode($outcome, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return $outcome;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : $outcome;
    }

    /**
     * Signaux factuels pour notes agent (participation) — aucune invention de beliefs.
     *
     * @param array<string,mixed> $runResult
     *
     * @return array{
     *   outcome_status_norm:string,
     *   verdict_label:string,
     *   reliability:string,
     *   blocking_unknowns:list<string>,
     *   contradictions:list<string>
     * }
     */
    public static function participationDiagnosticNotes(array $runResult): array
    {
        $outcome = is_array($runResult['decision_outcome'] ?? null) ? $runResult['decision_outcome'] : [];
        $rawStatus = trim((string)($outcome['status'] ?? ''));
        $norm = RuntimeContracts::normalizeStatus($rawStatus);
        if ($norm === '') {
            $norm = 'validate_first';
        }
        $verdict = is_array($runResult['verdict'] ?? null) ? $runResult['verdict'] : [];
        $vl = strtolower(trim((string)($verdict['verdict_label'] ?? '')));
        $rel = self::extractReliabilityStatus($runResult);
        $weak = self::isWeakReliability($rel);
        $ps = is_array($outcome['persistence_safety'] ?? null) ? $outcome['persistence_safety'] : [];
        $fb = ($ps['derived_from_fallback'] ?? false) === true;
        $cx = self::detectSignalContradictions($norm, $vl, $weak, $fb);
        $unknowns = is_array($outcome['blocking_unknowns'] ?? null)
            ? array_values(array_filter(array_map('strval', $outcome['blocking_unknowns'])))
            : [];

        return [
            'outcome_status_norm' => $norm,
            'verdict_label' => $vl,
            'reliability' => $rel,
            'blocking_unknowns' => $unknowns,
            'contradictions' => $cx,
        ];
    }

    /**
     * @param array<string,mixed> $runResult
     */
    public static function extractReliabilityStatus(array $runResult): string
    {
        $drs = $runResult['decision_reliability_summary'] ?? null;
        if (is_array($drs)) {
            $fo = strtolower(trim((string)($drs['final_outcome'] ?? '')));
            if ($fo !== '') {
                return $fo;
            }
        }
        $out = $runResult['decision_outcome'] ?? null;
        if (is_array($out)) {
            $c = strtolower(trim((string)($out['confidence'] ?? '')));
            if ($c !== '') {
                return $c;
            }
        }

        return '';
    }

    public static function isWeakReliability(string $rel): bool
    {
        $r = strtolower($rel);

        return $r === 'weak' || str_contains($r, 'weak');
    }

    /**
     * @return list<string>
     */
    private static function detectSignalContradictions(
        string $normOutcomeStatus,
        string $verdictLabel,
        bool $weakReliability,
        bool $derivedFallback
    ): array {
        $out = [];
        $affirmativeVerdict = in_array($verdictLabel, ['go'], true);
        $conservativeOutcome = in_array($normOutcomeStatus, ['validate_first', 'kill'], true);

        if ($affirmativeVerdict && $conservativeOutcome) {
            $out[] = 'Outcome extraction: ' . $normOutcomeStatus . ' vs verdict signal: ' . $verdictLabel;
        }
        if ($affirmativeVerdict && $weakReliability) {
            $out[] = 'Verdict signal: ' . $verdictLabel . ' with weak reliability / confidence';
        }
        if ($affirmativeVerdict && $derivedFallback) {
            $out[] = 'Affirmative verdict (' . $verdictLabel . ') with fallback parser extraction';
        }

        return $out;
    }
}

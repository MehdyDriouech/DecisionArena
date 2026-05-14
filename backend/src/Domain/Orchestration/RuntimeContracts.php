<?php
declare(strict_types=1);

namespace Domain\Orchestration;

/**
 * Persistence-ready contract hardening (no persistence yet).
 *
 * Single source of truth for:
 * - contract versions
 * - canonical runtime taxonomy (status + confidence)
 */
final class RuntimeContracts
{
    public const TAXONOMY_VERSION = 'taxonomy.v1';
    public const PLAYBOOK_RUNTIME_CONTRACT_VERSION = 'playbook_runtime.v1';
    public const CANONICAL_SYNTHESIS_CONTRACT_VERSION = 'canonical_synthesis.v1';
    public const DECISION_OUTCOME_CONTRACT_VERSION = 'decision_outcome.v1';

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return ['proceed', 'proceed_with_constraints', 'validate_first', 'pivot', 'kill'];
    }

    /** @return list<string> */
    public static function allowedConfidenceLevels(): array
    {
        return ['weak', 'moderate', 'strong'];
    }

    public static function normalizeConfidence(string $raw): string
    {
        $l = strtolower(trim($raw));
        if ($l === '') return '';

        // Numeric shorthands (accept legacy "7/10" patterns)
        if (preg_match('/(\d+(?:\.\d+)?)\s*\/\s*10/', $l, $m)) {
            $n = (float)$m[1];
            return $n >= 7 ? 'strong' : ($n >= 4.5 ? 'moderate' : 'weak');
        }
        if (preg_match('/\b(strong|moderate|weak)\b/', $l, $m)) {
            return $m[1];
        }

        // Legacy HIGH/MEDIUM/LOW variants
        if (preg_match('/\b(high|medium|mid|low)\b/', $l, $m)) {
            return $m[1] === 'high'
                ? 'strong'
                : ($m[1] === 'medium' || $m[1] === 'mid' ? 'moderate' : 'weak');
        }

        return '';
    }

    public static function normalizeStatus(string $raw): string
    {
        $l = strtolower(trim($raw));
        if ($l === '') return '';
        $l = str_replace(['-', ' '], '_', $l);
        if (in_array($l, self::allowedStatuses(), true)) {
            return $l;
        }

        // Minimal tolerant mapping for legacy phrasing
        if (str_contains($l, 'proceed') && str_contains($l, 'constraint')) return 'proceed_with_constraints';
        if (str_contains($l, 'proceed')) return 'proceed';
        if (str_contains($l, 'validate')) return 'validate_first';
        if (str_contains($l, 'pivot') || str_contains($l, 'iterate')) return 'pivot';
        if (str_contains($l, 'kill') || str_contains($l, 'stop')) return 'kill';

        return '';
    }
}


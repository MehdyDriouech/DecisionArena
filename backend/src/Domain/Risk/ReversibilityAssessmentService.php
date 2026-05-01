<?php

declare(strict_types=1);

namespace Domain\Risk;

/**
 * Heuristic reversibility assessor.
 *
 * Analyses the objective + context document to determine how easily the
 * decision can be undone if it turns out to be wrong.
 */
class ReversibilityAssessmentService
{
    private const EASY_SIGNALS = [
        'test', 'experiment', 'pilot', 'prototype', 'beta', 'poc', 'proof of concept',
        'rollback', 'roll back', 'undo', 'revert', 'temporary', 'trial', 'sandbox',
        'staging', 'feature flag', 'toggle', 'A/B', 'a/b test',
        'iterative', 'sprint', 'preview', 'draft',
    ];

    private const MODERATE_SIGNALS = [
        'refactor', 'migrate', 'migration', 'update', 'upgrade', 'rework',
        'change', 'modify', 'adjust', 'revise', 'rearchitect',
        'onboard', 'integrate', 'third-party', 'vendor',
    ];

    private const HARD_SIGNALS = [
        'deploy to production', 'launch', 'go live', 'release', 'publish',
        'announce', 'public announcement', 'press release',
        'production deploy', 'data migration', 'database migration',
        'architectural decision', 'rename', 'rebrand',
        'enterprise contract', 'strategic partnership',
    ];

    private const IRREVERSIBLE_SIGNALS = [
        'irreversible', 'cannot be undone', 'permanent', 'permanently',
        'delete', 'destroy', 'shutdown', 'terminate', 'close account',
        'legal binding', 'sign contract', 'signed agreement', 'binding commitment',
        'sell', 'acquisition', 'merger', 'ipo', 'bankruptcy', 'layoffs',
        'terminate employees', 'fire', 'resign',
        'data deletion', 'purge', 'wipe',
    ];

    public function assess(string $objective, ?string $contextText): string
    {
        $combined = mb_strtolower($objective . ' ' . ($contextText ?? ''), 'UTF-8');

        // Check from most severe to least — first match wins
        foreach (self::IRREVERSIBLE_SIGNALS as $sig) {
            if (str_contains($combined, $sig)) {
                return DecisionRiskProfile::REV_IRREVERSIBLE;
            }
        }
        foreach (self::HARD_SIGNALS as $sig) {
            if (str_contains($combined, $sig)) {
                return DecisionRiskProfile::REV_HARD;
            }
        }
        foreach (self::EASY_SIGNALS as $sig) {
            if (str_contains($combined, $sig)) {
                return DecisionRiskProfile::REV_EASY;
            }
        }
        foreach (self::MODERATE_SIGNALS as $sig) {
            if (str_contains($combined, $sig)) {
                return DecisionRiskProfile::REV_MODERATE;
            }
        }

        // Default: moderate (most real decisions have some friction to undo)
        return DecisionRiskProfile::REV_MODERATE;
    }
}

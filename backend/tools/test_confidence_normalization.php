<?php

/**
 * Confidence normalization smoke tests.
 *
 * Usage: php backend/tools/test_confidence_normalization.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Orchestration\DecisionOutcomeProjector;

$pass = 0;
$fail = 0;

function check_conf(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    $fail++;
}

function project_conf(array $patch = [], array $context = []): array
{
    $base = [
        'playbook_id' => 'quick-decision',
        'decision' => 'GO',
        'status' => 'RELIABLE',
        'confidence' => 'HIGH',
        'why' => ['The option is reversible.'],
        'risks' => ['Support load.'],
        'blocking_unknowns' => [],
        'recommended_next_actions' => ['Send the invite today.'],
        'validation_logic' => [
            'success_signal' => '20 signups',
            'kill_criteria' => 'Fewer than five signups',
        ],
        'outcomes' => [
            'immediate_next_action' => 'Send the invite today.',
            'key_constraint' => '48 hours.',
        ],
        'evidence_claims' => [
            [
                'claim' => 'The launch is reversible.',
                'claim_type' => 'fact',
                'confidence' => 'strong',
                'supporting_evidence' => ['Existing rollout switch.'],
                'contradictions' => [],
                'verification_status' => 'verified',
            ],
        ],
        'parser_diagnostics' => [
            'parser_confidence' => 0.91,
            'missing_fields' => [],
            'warnings' => [],
            'fallback_used' => false,
        ],
    ];
    return DecisionOutcomeProjector::fromCanonical(array_replace_recursive($base, $patch), $context);
}

echo "Confidence normalization smoke tests\n\n";

$strong = project_conf();
check_conf('clean outcome stays strong', $strong['confidence'] === 'strong');
check_conf('clean outcome explains confidence', count($strong['confidence_explanation'] ?? []) > 0);

$contradicted = project_conf([
    'evidence_claims' => [
        [
            'claim' => 'The market signal is already proven.',
            'claim_type' => 'signal',
            'confidence' => 'weak',
            'supporting_evidence' => [],
            'contradictions' => ['No market test has run.'],
            'verification_status' => 'contradicted',
        ],
    ],
]);
check_conf('contradiction downgrades to weak', $contradicted['confidence'] === 'weak');
check_conf('contradiction is observable', (int)($contradicted['uncertainty_signals']['contradictions'] ?? 0) > 0);

$founderGap = project_conf([
    'playbook_id' => 'founder-sprint',
    'outcomes' => [
        'kill_criteria' => 'Kill if no urgent pain appears.',
    ],
], ['playbook_runtime' => ['playbook_id' => 'founder-sprint']]);
check_conf('founder missing validation signals is not strong', $founderGap['confidence'] !== 'strong');
check_conf('founder playbook gaps are surfaced', in_array('validation_signal', $founderGap['uncertainty_signals']['playbook_gaps'] ?? [], true));

$fallback = project_conf([
    'parser_diagnostics' => [
        'parser_confidence' => 0.31,
        'missing_fields' => ['decision', 'why', 'validation_logic'],
        'warnings' => [],
        'fallback_used' => true,
    ],
]);
check_conf('low parser confidence downgrades to weak', $fallback['confidence'] === 'weak');
check_conf('fallback usage is observable', ($fallback['uncertainty_signals']['fallback_used'] ?? false) === true);

$juryConsensus = project_conf([
    'playbook_id' => 'jury',
    'outcomes' => [
        'final_recommendation' => 'Option B',
        'evaluation_criteria' => 'Cost, reversibility, value',
        'confidence_level' => 'medium',
    ],
], [
    'playbook_runtime' => ['playbook_id' => 'jury'],
    'guardrails' => ['warnings' => ['High false-consensus risk downgrades confidence in the vote outcome.']],
]);
check_conf('jury false consensus downgrades to weak', $juryConsensus['confidence'] === 'weak');
check_conf('jury confidence mentions consensus', str_contains(strtolower(implode(' ', $juryConsensus['confidence_explanation'] ?? [])), 'consensus'));

echo "\nPassed: {$pass}; Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

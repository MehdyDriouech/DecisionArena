<?php

/**
 * DecisionOutcome projection smoke tests.
 *
 * Usage: php backend/tools/test_decision_outcome_projection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Orchestration\CanonicalSynthesisExtractor;
use Domain\Orchestration\DecisionOutcomeProjector;

$pass = 0;
$fail = 0;

function check_outcome(string $label, bool $condition, string $detail = ''): void
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

function project_text(string $content, string $playbookId): array
{
    $canonical = CanonicalSynthesisExtractor::extract($content, $playbookId);
    return DecisionOutcomeProjector::fromCanonical($canonical);
}

echo "DecisionOutcome projection smoke tests\n\n";

$canonicalFounder = [
    'playbook_id' => 'founder-sprint',
    'decision' => 'GO',
    'status' => 'RELIABLE',
    'confidence' => 'HIGH',
    'why' => ['There is a narrow ICP and a testable wedge.'],
    'risks' => ['Acquisition signal may be too weak.'],
    'blocking_unknowns' => [],
    'recommended_next_actions' => ['Run five concierge interviews this week.'],
    'validation_logic' => [
        'success_signal' => 'Three qualified prospects ask for a follow-up.',
        'validation_threshold' => '3/5 qualified prospects',
        'failure_signal' => 'No prospect accepts a second conversation.',
        'kill_criteria' => 'Kill if the pain is not urgent after five interviews.',
    ],
    'outcomes' => [
        'validation_signal' => 'Prospects volunteer budget and urgency.',
        'kill_criteria' => 'No budget owner accepts the pain framing.',
        'next_experiment' => 'Concierge interview sprint with five ICP prospects.',
    ],
    'parser_diagnostics' => ['parser_confidence' => 0.92, 'missing_fields' => [], 'warnings' => []],
];
$out = DecisionOutcomeProjector::fromCanonical($canonicalFounder);
check_outcome('canonical synthesis projects to proceed', $out['status'] === 'proceed');
check_outcome('founder outcome keeps next_experiment', ($out['playbook_specific_outcomes']['next_experiment'] ?? '') !== '');

$markdownQuick = <<<TXT
## Decision
Proceed with the smaller launch.

## Confidence
High.

## Why
- It is reversible and uses existing assets.

## Main risks
- The deadline leaves little room for support.

## Immediate next action
- Ship the invite to the first cohort today.

## Validation logic
Success signal: 20 qualified signups.
Kill criteria: Stop if fewer than five qualified signups arrive.

## Key constraint
48-hour launch window.
TXT;
$out = project_text($markdownQuick, 'quick-decision');
check_outcome('markdown projects quick decision status', $out['status'] === 'proceed');
check_outcome('markdown extracts immediate_action', ($out['playbook_specific_outcomes']['immediate_action'] ?? '') !== '');

$proseStress = 'Recommendation: test first before committing. Confidence is medium. The biggest risk is that enterprise buyers will not change procurement behavior. Next step: simulate the rollout with three accounts. Failure scenario: legal review blocks the pilot. Weakest assumption: buyer urgency.';
$out = project_text($proseStress, 'stress-test');
check_outcome('free prose maps iterate/test first to pivot', $out['status'] === 'pivot');
check_outcome('free prose extracts a next action', count($out['required_next_actions']) > 0);
check_outcome('stress-test extracts failure scenario', ($out['playbook_specific_outcomes']['failure_scenarios'] ?? '') !== '');

$partial = project_text('This seems promising, but evidence is missing.', 'jury');
check_outcome('partial output degrades without crash', is_array($partial) && isset($partial['diagnostics']));
check_outcome('partial output reports warnings', count($partial['diagnostics']['warnings'] ?? []) > 0);

echo "\nPassed: {$pass}; Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

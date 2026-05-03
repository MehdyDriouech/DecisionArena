<?php
declare(strict_types=1);

require __DIR__ . '/../public/index.php';

use Domain\Orchestration\PromptBuilder;

$pb   = new PromptBuilder();
$pass = 0;
$fail = 0;

function assert_contains(string $label, string $needle, string $haystack, int &$pass, int &$fail): void {
    if (str_contains($haystack, $needle)) { echo "PASS: {$label}\n"; $pass++; }
    else { echo "FAIL: {$label} — '{$needle}' not found in output\n"; $fail++; }
}
function assert_not_contains(string $label, string $needle, string $haystack, int &$pass, int &$fail): void {
    if (!str_contains($haystack, $needle)) { echo "PASS: {$label}\n"; $pass++; }
    else { echo "FAIL: {$label} — '{$needle}' unexpectedly found in output\n"; $fail++; }
}

// Mock reliability data with NO_CONSENSUS_FRAGILE
$reliabilityData = [
    'raw_decision'      => ['winning_label' => 'go', 'winning_score' => 0.55, 'threshold' => 0.65],
    'adjusted_decision' => ['decision_label' => 'NO_CONSENSUS', 'decision_status' => 'FRAGILE', 'final_outcome' => 'NO_CONSENSUS_FRAGILE'],
    'context_quality'   => ['level' => 'weak', 'score' => 30],
    'false_consensus'   => ['false_consensus_risk' => 'high'],
    'debate_quality_score' => 35,
    'guardrails'        => ['warnings' => ['false_consensus_risk_high']],
    'evidence_report'   => null,
    'risk_profile'      => null,
];

$block  = $pb->buildSynthesizerConstraintBlock($reliabilityData);
$format = $pb->buildSynthesizerOutputFormatInstruction();
$full   = $block . $format;

// Assert constraint block contains required headings
assert_contains('Contains Aggregated Vote Result heading', '## Aggregated Vote Result', $block, $pass, $fail);
assert_contains('Contains Reliability Signals heading', '## Reliability Signals', $block, $pass, $fail);
assert_contains('Contains Hard Constraints heading', '## Hard Constraints', $block, $pass, $fail);

// Assert hard constraint lines
assert_contains('Contains NO_CONSENSUS constraint', 'MUST NOT claim there is a clear GO if final_outcome is NO_CONSENSUS', $block, $pass, $fail);
assert_contains('Contains FRAGILE constraint', 'MUST NOT describe the decision as reliable if decision_status is FRAGILE', $block, $pass, $fail);
assert_contains('Contains weak debate constraint', 'MUST explicitly state when the debate was weak', $block, $pass, $fail);
assert_contains('Contains alignment constraint', 'MUST align the final recommendation', $block, $pass, $fail);

// Assert format instruction contains required section headings
assert_contains('Format has ## Decision', '## Decision', $format, $pass, $fail);
assert_contains('Format has ## Confidence', '## Confidence', $format, $pass, $fail);
assert_contains('Format has ## Why', '## Why', $format, $pass, $fail);
assert_contains('Format has ## Main Risks', '## Main Risks', $format, $pass, $fail);
assert_contains('Format has ## Next Step', '## Next Step', $format, $pass, $fail);

// Assert NO_CONSENSUS is reflected in the block
assert_contains('Block reflects NO_CONSENSUS_FRAGILE outcome', 'NO_CONSENSUS_FRAGILE', $block, $pass, $fail);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

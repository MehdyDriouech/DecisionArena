<?php

/**
 * Argument discipline prompt smoke tests.
 *
 * Usage: php backend/tools/test_argument_discipline_prompts.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Agents\Agent;
use Domain\Agents\Persona;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\RoundPolicy;

$pass = 0;
$fail = 0;

function check_arg(string $label, bool $condition, string $detail = ''): void
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

$builder = new PromptBuilder();
$agent = new Agent(
    id: 'pm',
    persona: new Persona('pm', 'PM', 'Product Manager', 'PM', 'You prioritize product value and execution clarity.', []),
    soul: null,
    providerId: null,
    model: null
);

echo "Argument discipline prompt smoke tests\n\n";

$systemBlock = $builder->buildArgumentDisciplineSystemBlock();
check_arg('system block separates observation and assumption', str_contains($systemBlock, 'observation') && str_contains($systemBlock, 'assumption'));
check_arg('system block discourages artificial consensus', str_contains($systemBlock, 'do not manufacture consensus'));
check_arg('system block keeps decision implication', str_contains($systemBlock, 'decision implication'));

$stressBlock = $builder->buildPlaybookDebateDisciplineBlock('stress-test');
check_arg('stress-test block attacks failure modes', str_contains($stressBlock, 'failure modes') && str_contains($stressBlock, 'kill/pivot'));

$juryBlock = $builder->buildPlaybookDebateDisciplineBlock('jury');
check_arg('jury block preserves minority arguments', str_contains($juryBlock, 'minority') && str_contains($juryBlock, 'criteria'));

$founderBlock = $builder->buildPlaybookDebateDisciplineBlock('founder-sprint');
check_arg('founder block prioritizes market validation', str_contains($founderBlock, 'market validation') && str_contains($founderBlock, 'next experiment'));

$repeatBlock = $builder->buildRepetitionReductionBlock([
    ['agent_id' => 'critic', 'content' => 'This is too broad.'],
]);
check_arg('repetition block asks for a new contribution', str_contains($repeatBlock, 'new objection') && str_contains($repeatBlock, 'changed vote'));

$messages = $builder->buildDecisionRoomMessages(
    $agent,
    "## Runtime Playbook\nplaybook_id: founder-sprint\n\nValidate this B2B workflow idea.",
    [
        ['agent_id' => 'critic', 'content' => 'The ICP is vague and acquisition is unsupported.'],
    ],
    2,
    3,
    'en',
    false,
    null,
    null,
    'critic'
);
$combined = implode("\n\n", array_column($messages, 'content'));
check_arg('decision room messages include argument discipline', str_contains($combined, '## Argument discipline'));
check_arg('decision room messages include founder discipline', str_contains($combined, 'Push toward market validation'));
check_arg('decision room messages include repetition guard', str_contains($combined, '## Repetition guard'));
check_arg('interaction contract includes claim type', str_contains($combined, '## Claim Type') && str_contains($combined, '## Missing Support'));

$policy = new RoundPolicy();
$challenge = $policy->getRoundTypeDirective(RoundPolicy::ROUND_CHALLENGE);
check_arg('challenge directive requests missing support and test', str_contains($challenge, 'missing support') && str_contains($challenge, 'test'));

echo "\nPassed: {$pass}; Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

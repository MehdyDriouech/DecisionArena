<?php
declare(strict_types=1);

/**
 * Test: strategic context description is injected into LLM prompts.
 *
 * Usage:
 *   php backend/tools/test_strategic_context_prompt_injection.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Agents\AgentAssembler;
use Domain\Orchestration\PromptBuilder;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\StrategicContextRepository;

$db = Database::getInstance();
(new Migration($db))->run();

$repo = new StrategicContextRepository();
$assembler = new AgentAssembler();
$pb = new PromptBuilder();

$context = $repo->create(
    'Contexte test injection',
    "Ne jamais recommander une stratégie qui augmente le churn.\nPrioriser la rétention et les coûts maîtrisés.",
    'active'
);
$contextId = (string)($context['context_id'] ?? '');
if ($contextId === '') {
    fwrite(STDERR, "[FAIL] Unable to create strategic context\n");
    exit(1);
}

$agent = $assembler->assemble('pm');
if (!$agent) {
    fwrite(STDERR, "[FAIL] Could not assemble test agent 'pm'\n");
    exit(1);
}

$messages = $pb->buildDecisionRoomMessages(
    $agent,
    'Doit-on lancer une offre freemium ce trimestre ?',
    [],
    1,
    2,
    'fr',
    false,
    null,
    null,
    null,
    null,
    false,
    null,
    null,
    '',
    $contextId
);

$user = '';
foreach ($messages as $m) {
    if (($m['role'] ?? '') === 'user') {
        $user = (string)($m['content'] ?? '');
        break;
    }
}

$requiredNeedles = [
    '## Strategic Context Guidance',
    'Contexte test injection',
    'Ne jamais recommander une stratégie qui augmente le churn.',
];

$missing = [];
foreach ($requiredNeedles as $needle) {
    if (strpos($user, $needle) === false) {
        $missing[] = $needle;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "[FAIL] Prompt does not contain strategic context guidance block\n");
    foreach ($missing as $m) {
        fwrite(STDERR, " - missing: {$m}\n");
    }
    exit(1);
}

echo "[PASS] Strategic context description is injected into prompt\n";
exit(0);


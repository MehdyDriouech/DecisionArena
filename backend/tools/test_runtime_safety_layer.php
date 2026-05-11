<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\CognitiveGovernance\CognitiveRuntimeQAMode;
use Domain\CognitiveGovernance\RuntimePromptGuard;
use Domain\CognitiveGovernance\RuntimeWriteGuard;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\RuntimeAwarePdo;

function rt_check(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__rt_fail'] = ($GLOBALS['__rt_fail'] ?? 0) + 1;
    }
}

$GLOBALS['__rt_fail'] = 0;
putenv('COGNITIVE_RUNTIME_QA_MODE=expert');
putenv('COGNITIVE_RUNTIME_WRITE_GUARDS=1');

rt_check(CognitiveRuntimeQAMode::enabled(), 'QA mode can be enabled');
rt_check(CognitiveRuntimeQAMode::current() === CognitiveRuntimeQAMode::EXPERT, 'QA mode resolves expert');

$db = Database::getConnection();
rt_check($db instanceof RuntimeAwarePdo, 'Database connection uses RuntimeAwarePdo');

$snapshotOverwriteBlocked = false;
try {
    RuntimeWriteGuard::inspectSql('UPDATE strategic_context_snapshots SET title = ? WHERE id = ?', 'qa-test');
} catch (\RuntimeException) {
    $snapshotOverwriteBlocked = true;
}
rt_check($snapshotOverwriteBlocked, 'Snapshot overwrite is blocked by runtime write guard');

$registryBypassBlocked = false;
try {
    RuntimePromptGuard::inspectStep(
        'unknown_runtime_block',
        null,
        100,
        'qa_test_injection',
        ['budget_layer' => 'orchestration', 'content_hash' => hash('sha256', 'x')]
    );
} catch (\RuntimeException) {
    $registryBypassBlocked = true;
}
rt_check($registryBypassBlocked, 'Injection outside registry is blocked in expert mode');

$unbudgetedBlocked = false;
try {
    RuntimePromptGuard::inspectStep(
        'chat_user_payload',
        ['injection_key' => 'chat_user_payload'],
        200,
        'qa_test_injection',
        ['content_hash' => hash('sha256', 'x')]
    );
} catch (\RuntimeException) {
    $unbudgetedBlocked = true;
}
rt_check($unbudgetedBlocked, 'Unbudgeted prompt injection is blocked in QA/expert mode');

if (($GLOBALS['__rt_fail'] ?? 0) > 0) {
    echo 'Runtime safety layer checks failed: ' . (int)$GLOBALS['__rt_fail'] . PHP_EOL;
    exit(1);
}

echo 'Runtime safety layer checks passed.' . PHP_EOL;
exit(0);


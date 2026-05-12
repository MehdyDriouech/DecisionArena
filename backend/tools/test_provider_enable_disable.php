<?php
declare(strict_types=1);

/**
 * Provider enable/disable regression checks.
 * Run: php backend/tools/test_provider_enable_disable.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Providers\ProviderRouter;
use Infrastructure\Persistence\ProviderRepository;

function ok(string $name, bool $pass): void
{
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

$repo = new ProviderRepository();
$router = new ProviderRouter();
$suffix = bin2hex(random_bytes(4));
$enabledId = 'test-enabled-' . $suffix;
$disabledId = 'test-disabled-' . $suffix;
$enabledBaseUrl = 'http://localhost:' . (18000 + random_int(0, 499));
$disabledBaseUrl = 'http://localhost:' . (18500 + random_int(0, 499));
$now = date('c');

try {
    $repo->save([
        'id' => $enabledId,
        'name' => 'Test Enabled',
        'type' => 'ollama',
        'base_url' => $enabledBaseUrl,
        'api_key' => '',
        'default_model' => 'qwen2.5:7b',
        'enabled' => 1,
        'priority' => 10,
        'is_local' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $repo->save([
        'id' => $disabledId,
        'name' => 'Test Disabled',
        'type' => 'ollama',
        'base_url' => $disabledBaseUrl,
        'api_key' => '',
        'default_model' => 'qwen2.5:7b',
        'enabled' => 1,
        'priority' => 20,
        'is_local' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $repo->setEnabled($disabledId, false);

    $enabledRow = $repo->findById($enabledId);
    $disabledRow = $repo->findById($disabledId);
    ok('enabled provider stays enabled', (int)($enabledRow['enabled'] ?? 0) === 1);
    ok('disabled provider is persisted as disabled', (int)($disabledRow['enabled'] ?? 0) === 0);

    $enabledEligible = $repo->findRoutingEligibleOrdered();
    $eligibleIds = array_map(static fn(array $row): string => (string)($row['id'] ?? ''), $enabledEligible);
    ok('disabled provider excluded from routing-eligible list', !in_array($disabledId, $eligibleIds, true));
    ok('enabled provider still present in routing-eligible list', in_array($enabledId, $eligibleIds, true));

    $ref = new ReflectionObject($router);

    $isProviderDisabled = $ref->getMethod('isProviderDisabled');
    $isProviderDisabled->setAccessible(true);
    ok('isProviderDisabled() true for disabled provider', (bool)$isProviderDisabled->invoke($router, $disabledId) === true);
    ok('isProviderDisabled() false for enabled provider', (bool)$isProviderDisabled->invoke($router, $enabledId) === false);

    $buildCandidates = $ref->getMethod('buildCandidateProviders');
    $buildCandidates->setAccessible(true);
    $candidates = $buildCandidates->invoke(
        $router,
        'single-primary',
        [
            'routing_mode' => 'single-primary',
            'primary_provider_id' => $disabledId,
            'preferred_provider_id' => null,
            'fallback_provider_ids' => [$disabledId],
            'load_balance_strategy' => 'round-robin',
        ],
        null
    );
    $candidateIds = array_map(static fn(array $row): string => (string)($row['id'] ?? ''), is_array($candidates) ? $candidates : []);
    ok('runtime candidates ignore disabled configured primary', !in_array($disabledId, $candidateIds, true));
    ok('runtime candidates still include enabled provider', in_array($enabledId, $candidateIds, true));

    echo "All provider enable/disable checks passed." . PHP_EOL;
} finally {
    $repo->delete($enabledId);
    $repo->delete($disabledId);
}


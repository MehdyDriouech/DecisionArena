<?php
declare(strict_types=1);

/**
 * Tests BYOK Gemini + demo quota (no secrets printed).
 * Run: php backend/tools/test_gemini_byok_quota.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';
require_once __DIR__ . '/../config/DemoLocalConfig.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';

use Domain\Demo\DemoAuthService;
use Domain\Demo\DemoQuotaGuard;
use Domain\Demo\DemoQuotaService;
use Domain\Providers\CommercialRuntimeContext;
use Domain\Providers\ProviderFactory;
use Domain\Providers\ProviderRouter;
use Domain\Providers\RuntimeBillingContext;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;

function ok(string $name, bool $pass): void
{
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

// --- Config file example has no raw secrets
$example = file_get_contents(dirname(__DIR__) . '/config/demo.local.example.php');
ok('example config has no AIza key pattern', !preg_match('/AIza[0-9A-Za-z_-]{20,}/', $example));
ok('gitignore excludes demo.local.php', is_file(dirname(__DIR__, 2) . '/.gitignore')
    && str_contains(file_get_contents(dirname(__DIR__, 2) . '/.gitignore'), 'demo.local.php'));

// --- BYOK runtime in memory
CommercialRuntimeContext::clear();
RuntimeBillingContext::clear();
CommercialRuntimeContext::loadFromRequestBody([
    'provider_runtime' => [
        'gemini' => [
            'enabled' => true,
            'api_key' => 'test-byok-key-not-real',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'default_model' => 'gemini-2.5-flash',
        ],
    ],
]);
RuntimeBillingContext::loadFromRequestBody([
    'provider_runtime' => [
        'gemini' => ['enabled' => true, 'api_key' => 'test-byok-key-not-real'],
    ],
]);
ok('RuntimeBillingContext detects gemini BYOK', RuntimeBillingContext::usesGeminiByok());
ok('CommercialRuntimeContext has gemini row', count(CommercialRuntimeContext::getRows()) === 1);
$row = CommercialRuntimeContext::getRows()[0];
ok('BYOK row tagged billing_source=byok', ($row['billing_source'] ?? '') === 'byok');

try {
    ProviderFactory::create($row);
    ok('Factory accepts BYOK row without server env', true);
} catch (\Throwable $e) {
    ok('Factory accepts BYOK row', false);
}

// Router metadata (no HTTP)
$router = new ProviderRouter();
$ref = new ReflectionClass($router);
$m = $ref->getMethod('attachBillingMetadata');
$m->setAccessible(true);
$meta = $m->invoke($router, ['content' => 'x'], $row);
ok('Router billing_source byok', ($meta['billing_source'] ?? '') === 'byok');
ok('Router byok_used true', !empty($meta['byok_used']));

CommercialRuntimeContext::clear();
RuntimeBillingContext::clear();

// --- Quota: only when demo mode (skip if not configured)
if (!\DemoLocalConfig::isDemoMode()) {
    echo "[INFO] demo.enabled false — quota integration tests skipped (enable demo.local.php for full run)\n";
    exit(0);
}

$db = Database::getInstance();
(new Migration($db))->run();

DemoAuthService::startSessionIfNeeded();
$_SESSION['demo_user'] = 'demo';
$user = DemoAuthService::currentUserId();
$date = DemoQuotaService::usageDateUtc();
$repo = new \Infrastructure\Persistence\DemoQuotaRepository();
$pdo = Database::getInstance()->pdo();
$pdo->prepare('DELETE FROM demo_llm_usage WHERE user_id = ? AND usage_date = ?')->execute([$user, $date]);

// Exhaust quota
$limit = DemoAuthService::dailyQuotaForUser($user);
for ($i = 0; $i < $limit; $i++) {
    DemoQuotaService::consumeOne($user);
}
ok('quota exhausted', DemoQuotaService::getStatus($user)['remaining'] === 0);

$req = new Request();
RuntimeBillingContext::loadFromRequestBody([
    'provider_runtime' => [
        'gemini' => ['enabled' => true, 'api_key' => 'byok-test-key'],
    ],
]);

try {
    DemoQuotaGuard::beginRun($req);
    ok('BYOK bypasses quota when exhausted', true);
    DemoQuotaGuard::completeRun('byok', true);
    ok('BYOK success does not consume quota', DemoQuotaService::getStatus($user)['remaining'] === 0);
} catch (\Throwable $e) {
    ok('BYOK bypasses quota when exhausted', false);
}

RuntimeBillingContext::clear();
CommercialRuntimeContext::clear();

try {
    DemoQuotaGuard::beginRun($req);
    ok('server billing blocked when quota 0', false);
} catch (\Domain\Demo\DemoHttpException $e) {
    ok('server billing blocked when quota 0', $e->getErrorCode() === 'daily_quota_exceeded');
}

unset($_SESSION['demo_user']);
echo "Done.\n";

<?php
declare(strict_types=1);

/**
 * Gemini provider / routing checks (no key printed).
 * Run: php backend/tools/test_gemini_provider.php
 * With live call: GEMINI_API_KEY=... php backend/tools/test_gemini_provider.php --live
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Providers\OpenAiCompatibleUrl;
use Domain\Providers\ProviderFactory;
use Domain\Providers\ProviderRouter;
use Domain\Providers\ProviderSecretResolver;
use Infrastructure\Persistence\ProviderRepository;

function ok(string $name, bool $pass): void
{
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

function fail(string $name, string $detail): void
{
    echo '[FAIL] ' . $name . PHP_EOL;
    echo '       ' . $detail . PHP_EOL;
    exit(1);
}

function out_info(string $msg): void
{
    echo '[INFO] ' . $msg . PHP_EOL;
}

$live = in_array('--live', $argv ?? [], true);

// --- URL builder (Gemini OpenAI compatibility)
$geminiBase = 'https://generativelanguage.googleapis.com/v1beta/openai';
$chatUrl = OpenAiCompatibleUrl::chatCompletions($geminiBase);
ok(
    'Gemini chat URL uses /chat/completions (not double /v1)',
    $chatUrl === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
);

// --- Missing key: clear env for this process
putenv('GEMINI_API_KEY');
putenv('GOOGLE_API_KEY');
unset($_ENV['GEMINI_API_KEY'], $_ENV['GOOGLE_API_KEY']);

$row = [
    'id' => 'gemini',
    'type' => 'openai-compatible',
    'base_url' => $geminiBase,
    'api_key' => '',
    'default_model' => 'gemini-2.5-flash',
];
try {
    ProviderFactory::create($row);
    fail('Factory without key throws', 'Expected RuntimeException');
} catch (\RuntimeException $e) {
    ok('Factory without key throws clear error', str_contains($e->getMessage(), 'GEMINI_API_KEY'));
    ok('Error message does not leak key shape', !preg_match('/AIza[0-9A-Za-z_-]{20,}/', $e->getMessage()));
}

$repo = new ProviderRepository();
$eligibleWithout = $repo->findRoutingEligibleOrdered();
$geminiEligible = false;
foreach ($eligibleWithout as $p) {
    if ((string)($p['id'] ?? '') === 'gemini') {
        $geminiEligible = true;
        break;
    }
}
ok('Gemini excluded from routing when no env key and empty DB key', !$geminiEligible);

if (!$live) {
    out_info('Skip live Gemini HTTP (--live not set).');
    exit(0);
}

$key = ProviderSecretResolver::geminiEnvKey();
if ($key === '') {
    fail('Live test', 'Set GEMINI_API_KEY or GOOGLE_API_KEY before --live');
}

$provider = ProviderFactory::create($row);
$content = $provider->chat(
    [['role' => 'user', 'content' => 'Reply with exactly: OK']],
    'gemini-2.5-flash',
    ['http_timeout_seconds' => 90, 'connect_timeout_seconds' => 15]
);
ok('Live Gemini chat returns content', is_string($content) && trim($content) !== '');
echo '[OK] Live response length: ' . strlen($content) . ' chars (content not logged)' . PHP_EOL;

// Router smoke (explicit provider)
$router = new ProviderRouter();
try {
    $result = $router->chat(
        [['role' => 'user', 'content' => 'Say OK in one word']],
        null,
        'gemini',
        'gemini-2.5-flash',
        null,
        ['http_timeout_seconds' => 90]
    );
    ok('ProviderRouter explicit gemini', ($result['provider_id'] ?? '') === 'gemini');
} catch (\Throwable $e) {
    fail('ProviderRouter explicit gemini', $e->getMessage());
}

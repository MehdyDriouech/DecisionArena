<?php
declare(strict_types=1);

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

use Domain\Providers\OpenAiCompatibleUrl;
use Domain\Providers\ProviderFactory;
use Domain\Providers\ProviderSecretResolver;
use Infrastructure\Persistence\ProviderRepository;

$fromFile = DemoLocalConfig::geminiFileApiKey();
echo 'demo.local api_key length: ' . strlen($fromFile) . PHP_EOL;

$row = (new ProviderRepository())->findById('gemini');
if (!$row) {
    echo "No provider row id=gemini in SQLite. Run: php backend/tools/seed_demo_gemini_provider.php\n";
    exit(1);
}

$dbKey = trim((string)($row['api_key'] ?? ''));
echo 'DB api_key length: ' . strlen($dbKey) . PHP_EOL;
echo 'DB base_url: ' . ($row['base_url'] ?? '') . PHP_EOL;
echo 'DB default_model: ' . ($row['default_model'] ?? '') . PHP_EOL;
echo 'Uses DB key first (demo.local ignored if DB non-empty): ' . ($dbKey !== '' ? 'yes' : 'no') . PHP_EOL;

$enriched = ProviderSecretResolver::enrich($row);
echo 'Resolved key length: ' . strlen(trim((string)($enriched['api_key'] ?? ''))) . PHP_EOL;
echo 'Chat URL: ' . OpenAiCompatibleUrl::chatCompletions((string)$enriched['base_url']) . PHP_EOL;

try {
    $provider = ProviderFactory::create($enriched);
    $content = $provider->chat(
        [['role' => 'user', 'content' => 'Reply with exactly: OK']],
        (string)($enriched['default_model'] ?? 'gemini-2.5-flash'),
        ['http_timeout_seconds' => 60, 'connect_timeout_seconds' => 15]
    );
    echo "Chat: OK (response length " . strlen($content) . " chars, not logged)\n";
} catch (Throwable $e) {
    echo 'Chat FAILED: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

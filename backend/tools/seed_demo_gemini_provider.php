<?php
declare(strict_types=1);

/**
 * Idempotent seed: Google Gemini (OpenAI-compatible) for demo hosting.
 *
 * Usage:
 *   php backend/tools/seed_demo_gemini_provider.php
 *   php backend/tools/seed_demo_gemini_provider.php --demo-primary
 *   php backend/tools/seed_demo_gemini_provider.php --demo-primary --force-routing
 *
 * API key: set GEMINI_API_KEY or GOOGLE_API_KEY in the server environment (never commit).
 * This script never prints or stores the key in SQLite when env is used.
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';

use Domain\Providers\ProviderSecretResolver;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\ProviderRepository;
use Infrastructure\Persistence\ProviderRoutingSettingsRepository;

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function hasFlag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

require_once __DIR__ . '/../config/DemoLocalConfig.php';

$defaults = ProviderSecretResolver::geminiSeedDefaults();

$demoPrimary = hasFlag($argv, '--demo-primary')
    || filter_var(getenv('DECISION_ARENA_DEMO_PRIMARY') ?: '', FILTER_VALIDATE_BOOLEAN)
    || (!empty($defaults['demo_primary']));
$forceRouting = hasFlag($argv, '--force-routing');

$db = Database::getInstance();
(new Migration($db))->run();

$repo = new ProviderRepository();
$routingRepo = new ProviderRoutingSettingsRepository();
$now = date('c');
$id = ProviderSecretResolver::GEMINI_PROVIDER_ID;
$desired = [
    'id'            => (string)($defaults['id'] ?? $id),
    'name'          => (string)($defaults['name'] ?? 'Google Gemini'),
    'type'          => (string)($defaults['type'] ?? 'openai-compatible'),
    'base_url'      => (string)($defaults['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta/openai'),
    'default_model' => (string)($defaults['default_model'] ?? 'gemini-2.5-flash'),
    'enabled'       => (int)($defaults['enabled'] ?? 1),
    'priority'      => (int)($defaults['priority'] ?? 10),
    'is_local'      => (int)($defaults['is_local'] ?? 0),
];

$existing = $repo->findById($id);
if (!$existing) {
    $desired['api_key'] = '';
    $desired['created_at'] = $now;
    $desired['updated_at'] = $now;
    $repo->save($desired);
    out('[OK] Provider "gemini" created (api_key left empty — use server env).');
} else {
    $patch = [
        'id'            => $id,
        'name'          => $desired['name'],
        'type'          => $desired['type'],
        'base_url'      => $desired['base_url'],
        'default_model' => $desired['default_model'],
        'enabled'       => (int)($existing['enabled'] ?? 1) === 0 ? 0 : $desired['enabled'],
        'priority'      => $desired['priority'],
        'is_local'      => $desired['is_local'],
        'api_key'       => (string)($existing['api_key'] ?? ''),
        'created_at'    => (string)($existing['created_at'] ?? $now),
        'updated_at'    => $now,
    ];
    $repo->save($patch);
    out('[OK] Provider "gemini" updated (existing api_key in DB preserved if any).');
}

$envKey = ProviderSecretResolver::geminiEnvKey();
if ($envKey === '') {
    out('[WARN] No GEMINI_API_KEY / GOOGLE_API_KEY in environment — gemini is not routing-eligible until set.');
} else {
    out('[OK] Server env API key detected (value not shown).');
    $eligible = $repo->findRoutingEligibleOrdered();
    $ids = array_map(static fn(array $r): string => (string)($r['id'] ?? ''), $eligible);
    if (in_array($id, $ids, true)) {
        out('[OK] Provider "gemini" is routing-eligible.');
    } else {
        out('[WARN] Provider "gemini" exists but is not routing-eligible (check base_url / enabled).');
    }
}

if ($demoPrimary) {
    $settings = $routingRepo->get();
    $currentPrimary = trim((string)($settings['primary_provider_id'] ?? ''));
    if ($currentPrimary !== '' && $currentPrimary !== $id && !$forceRouting) {
        out('[SKIP] primary_provider_id already set to "' . $currentPrimary . '" (use --force-routing to override).');
    } else {
        $routingRepo->update([
            'routing_mode' => 'single-primary',
            'primary_provider_id' => $id,
        ]);
        out('[OK] Demo routing: primary_provider_id = gemini (mode single-primary).');
    }
} else {
    out('[INFO] Routing unchanged. Pass --demo-primary or set DECISION_ARENA_DEMO_PRIMARY=1 to set gemini as primary.');
}

out('Done.');

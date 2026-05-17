<?php
declare(strict_types=1);

/**
 * Seed idempotent des comptes démo (mots de passe hashés).
 * Run: php backend/tools/seed_demo_users.php
 * Rebuild passwords: php backend/tools/seed_demo_users.php --force-password
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

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DemoUserRepository;
use Infrastructure\Persistence\Migration;

$force = in_array('--force-password', $argv ?? [], true);
$now = date('c');

$db = Database::getInstance();
(new Migration($db))->run();

$repo = new DemoUserRepository();

$users = [
    ['id' => 'demo', 'login' => 'demo', 'password' => 'demo', 'role' => 'demo'],
    ['id' => 'admin', 'login' => 'admin', 'password' => 'demo123', 'role' => 'admin'],
];

foreach ($users as $u) {
    $existing = $repo->findByLogin($u['login']);
    if ($existing && !$force && trim((string)($existing['password_hash'] ?? '')) !== '') {
        echo '[SKIP] ' . $u['login'] . ' (password already set; use --force-password to rebuild)' . PHP_EOL;
        continue;
    }
    $hash = password_hash($u['password'], PASSWORD_DEFAULT);
    $repo->upsert([
        'id' => $u['id'],
        'login' => $u['login'],
        'password_hash' => $hash,
        'role' => $u['role'],
        'enabled' => 1,
        'created_at' => $existing['created_at'] ?? $now,
    ], $force);
    echo '[OK] User "' . $u['login'] . '" (' . $u['role'] . ') — password hash ' . ($force || !$existing ? 'written' : 'created') . PHP_EOL;
}

echo '[INFO] Quota compte démo public : 2 usages/jour via demo.local.php → demo.accounts.demo.daily_llm_quota' . PHP_EOL;
echo 'Done.' . PHP_EOL;

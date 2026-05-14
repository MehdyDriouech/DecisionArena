<?php
declare(strict_types=1);

/**
 * Shared CLI bootstrap for backend/tools tests.
 *
 * It mirrors the project class loading used by the front controller without
 * booting HTTP routing or running migrations.
 */

$root = dirname(__DIR__);
$src = $root . '/src';

$polyfill = $src . '/mbstring-polyfill.php';
if (is_file($polyfill)) {
    require_once $polyfill;
}

foreach ([
    $root . '/vendor/autoload.php',
    dirname($root) . '/vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

spl_autoload_register(static function (string $class) use ($src): void {
    $file = $src . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

foreach ([
    '/Infrastructure/Persistence/RuntimeAwarePdoStatement.php',
    '/Infrastructure/Persistence/RuntimeAwarePdo.php',
    '/Infrastructure/Persistence/Database.php',
    '/Infrastructure/Persistence/Migration.php',
] as $file) {
    $path = $src . $file;
    if (is_file($path)) {
        require_once $path;
    }
}


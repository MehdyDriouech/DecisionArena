<?php
declare(strict_types=1);

/**
 * Tests CLI auth démo (lot C1).
 * Run: php backend/tools/test_demo_auth.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

putenv('DECISION_ARENA_DEMO_AUTH=1');
$_ENV['DECISION_ARENA_DEMO_AUTH'] = '1';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';
require_once __DIR__ . '/../config/DemoLocalConfig.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
}

use Controllers\DemoAuthApiController;
use Domain\Demo\DemoAuthService;
use Http\Request;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;

$failures = 0;
function assertTrue(bool $cond, string $label): void {
    global $failures;
    if ($cond) {
        echo "[PASS] $label\n";
        return;
    }
    $failures++;
    echo "[FAIL] $label\n";
}

function requestWithBody(array $body): Request {
    $req = new Request();
    $ref = new ReflectionClass($req);
    $prop = $ref->getProperty('body');
    $prop->setAccessible(true);
    $prop->setValue($req, $body);
    return $req;
}

$db = Database::getInstance();
(new Migration($db))->run();
ob_start();
passthru('php ' . escapeshellarg(__DIR__ . '/seed_demo_users.php'), $seedCode);
ob_end_clean();
assertTrue($seedCode === 0, 'seed_demo_users.php exit 0');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
$_SESSION = [];

$ctrl = new DemoAuthApiController();
$getReq = requestWithBody([]);

DemoAuthService::logout();
$me = $ctrl->me($getReq);
assertTrue(($me['authenticated'] ?? true) === false, 'GET /me non connecté → authenticated=false');

$login = $ctrl->login(requestWithBody(['login' => 'demo', 'password' => 'demo']));
assertTrue(($login['success'] ?? false) === true, 'POST login demo/demo → OK');

$me2 = $ctrl->me($getReq);
assertTrue(
    ($me2['authenticated'] ?? false) === true && ($me2['user']['login'] ?? '') === 'demo',
    'GET /me connecté → login=demo'
);

$logout = $ctrl->logout($getReq);
assertTrue(($logout['success'] ?? false) === true, 'POST logout → OK');

$bad = $ctrl->login(requestWithBody(['login' => 'demo', 'password' => 'wrong']));
assertTrue(($bad['error'] ?? false) === true, 'POST login mauvais mot de passe → erreur');

ob_start();
passthru('php ' . escapeshellarg(__DIR__ . '/seed_demo_users.php'));
passthru('php ' . escapeshellarg(__DIR__ . '/seed_demo_users.php'));
ob_end_clean();
echo "[INFO] Seed relancé 2× (idempotent)\n";

if ($failures > 0) {
    echo "\n$failures test(s) en échec.\n";
    exit(1);
}
echo "\nTous les tests auth démo ont réussi.\n";
exit(0);

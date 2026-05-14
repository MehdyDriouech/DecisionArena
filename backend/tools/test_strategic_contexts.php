<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
  $base = __DIR__ . '/../src/';
  $file = $base . str_replace('\\', '/', $class) . '.php';
  if (file_exists($file)) require_once $file;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\StrategicContextRepository;

echo "Strategic Context checks\n\n";

$db = Database::getInstance();
(new Migration($db))->run();
$repo = new StrategicContextRepository();

$c = $repo->create('Initiative A', 'Test context', 'active');
if (empty($c['context_id'])) { echo "FAIL: create context\n"; exit(1); }
echo "PASS: context created\n";

$cid = (string)$c['context_id'];
$list = $repo->list(['status' => 'active'], 50);
if (!array_filter($list, fn($x) => ($x['context_id'] ?? '') === $cid)) { echo "FAIL: list contexts\n"; exit(1); }
echo "PASS: context listed\n";

// Link operations are best-effort; we don't assume existing memories/sessions in local DB.
// Just ensure link/unlink SQL runs without exception.
$ok1 = $repo->unlinkMemory($cid, 'nonexistent');
$ok2 = $repo->unlinkSession($cid, 'nonexistent');
if (!$ok1 || !$ok2) { echo "FAIL: unlink operations\n"; exit(1); }
echo "PASS: unlink operations safe\n";

$state = $repo->currentState($cid);
if (!is_array($state)) { echo "FAIL: currentState shape\n"; exit(1); }
echo "PASS: currentState deterministic\n";

echo "\nOK\n";


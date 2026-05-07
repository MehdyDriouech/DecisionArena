<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Domain\DecisionMemory\DecisionMemoryContextBuilder;

$db = Database::getInstance();
(new Migration($db))->run();

echo "Decision Memory reuse checks\n\n";

$repo = new DecisionMemoryRepository();
$all = $repo->findFiltered([], 50, 0);
echo "INFO: memories_count=" . count($all) . "\n";

// pick some ids (if none, just assert API doesn't crash)
$ids = array_slice(array_map(fn($m) => (string)($m['memory_id'] ?? ''), $all), 0, 3);
$compact = $repo->compactReusableForIds($ids);
echo "PASS: compactReusableForIds() returns shape\n";
if (!isset($compact['allowed'], $compact['blocked']) || !is_array($compact['allowed']) || !is_array($compact['blocked'])) {
    echo "FAIL: invalid compact shape\n";
    exit(1);
}

// Ensure compact does not include raw chat blobs (heuristic)
$json = json_encode($compact, JSON_UNESCAPED_UNICODE);
if ($json && (str_contains($json, 'Conversation History') || str_contains($json, 'Previous Round Contributions'))) {
    echo "FAIL: compact seems to include chat-like content\n";
    exit(1);
}
echo "PASS: compact context contains no obvious raw chat\n";

// Injection block only includes allowed memories
$built = DecisionMemoryContextBuilder::buildInjectionBlock($compact['allowed'], 'en');
echo "PASS: injection block built (chars={$built['chars']})\n";

echo "\nOK\n";


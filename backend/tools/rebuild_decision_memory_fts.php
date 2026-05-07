<?php
/**
 * Rebuild Decision Memory FTS index (best-effort; falls back cleanly if FTS5 unavailable).
 *
 * Usage:
 *   php backend/tools/rebuild_decision_memory_fts.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\DecisionMemoryRepository;

$db = Database::getInstance();
(new Migration($db))->run();

$repo = new DecisionMemoryRepository();
$mode = $repo->isDecisionMemoryFtsAvailable() ? 'fts5' : 'like';
$out = $repo->rebuildDecisionMemoryFts();

echo "Decision Memory FTS rebuild\n";
echo "search_mode=" . ($out['search_mode'] ?? $mode) . "\n";
echo "indexed_count=" . (int)($out['indexed_count'] ?? 0) . "\n";


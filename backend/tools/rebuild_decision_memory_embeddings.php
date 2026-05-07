<?php
/**
 * Rebuild Decision Memory embeddings (experimental; feature-flagged).
 *
 * Usage:
 *   php backend/tools/rebuild_decision_memory_embeddings.php [include_stale=1] [expert_override=1]
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\DecisionMemoryEmbeddingsRepository;
use Domain\DecisionMemory\DeterministicFakeEmbeddingProvider;

$db = Database::getInstance();
(new Migration($db))->run();

$includeStale = in_array('include_stale=1', $argv ?? [], true) || in_array('include_stale=true', $argv ?? [], true);
$expertOverride = in_array('expert_override=1', $argv ?? [], true) || in_array('expert_override=true', $argv ?? [], true);

if (!DecisionMemoryEmbeddingsRepository::isSemanticMemoryEnabled()) {
    echo "Decision Memory embeddings rebuild\n";
    echo "enabled=false\n";
    echo "mode=experimental_semantic_similarity\n";
    exit(0);
}

$provider = new DeterministicFakeEmbeddingProvider();
$repo = new DecisionMemoryEmbeddingsRepository();
$out = $repo->rebuildEmbeddings($provider, [
    'include_stale' => $includeStale,
    'expert_override' => $expertOverride,
]);

echo "Decision Memory embeddings rebuild\n";
echo "enabled=true\n";
echo "mode=experimental_semantic_similarity\n";
echo "provider=" . ($out['provider'] ?? $provider->providerName()) . "\n";
echo "model=" . ($out['model'] ?? $provider->modelName()) . "\n";
echo "version=" . ($out['version'] ?? $provider->embeddingVersion()) . "\n";
echo "indexed_count=" . (int)($out['indexed_count'] ?? 0) . "\n";
echo "skipped_unchanged=" . (int)($out['skipped_unchanged'] ?? 0) . "\n";


<?php
/**
 * Phase 2 — FTS5 context retrieval (chunks on save, prompt excerpts, logging meta).
 *
 * Usage: php backend/tools/test_context_fts_phase2.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) {
        require_once $f;
    }
});

use Domain\Orchestration\PromptBuilder;
use Infrastructure\Persistence\ContextDocumentChunkRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;

$passN = 0;
$failN = 0;
$pass = function (string $label) use (&$passN): void {
    echo "PASS: {$label}\n";
    $passN++;
};
$fail = function (string $label, string $detail = '') use (&$failN): void {
    echo "FAIL: {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    $failN++;
};

$db        = Database::getInstance();
$migration = new Migration($db);
$migration->run();

$sessionId = 'test-fts-phase2-' . bin2hex(random_bytes(4));
$now       = date('c');
$sessRepo  = new SessionRepository();
$sessRepo->create([
    'id'             => $sessionId,
    'title'          => 'FTS phase2 test',
    'selected_agents'=> ['pm'],
    'created_at'     => $now,
    'updated_at'     => $now,
]);

$unique    = 'UNIQUE_MARKER_XRAY7729';
$content   = "Introduction paragraph.\n\n"
    . "Section about widgets: the {$unique} requirement applies to all vendors.\n\n"
    . str_repeat('Padding line for length. ', 40)
    . "\nFooter with galaxy terminology for search.";

$docRepo = new ContextDocumentRepository();
$docRepo->upsert([
    'id'               => 'ctx-' . bin2hex(random_bytes(8)),
    'session_id'       => $sessionId,
    'title'            => 'FTS Test Doc',
    'source_type'      => 'manual',
    'original_filename'=> null,
    'mime_type'        => 'text/plain',
    'content'          => $content,
    'character_count'  => mb_strlen($content, 'UTF-8'),
]);

$chunkRepo = new ContextDocumentChunkRepository();
$nChunks   = $chunkRepo->countBySession($sessionId);
if ($nChunks > 0) {
    $pass('chunks created on upsert');
} else {
    $fail('chunks created on upsert', "count={$nChunks}");
}

$ftsQ = ContextDocumentChunkRepository::buildFtsMatchQuery('widgets vendors', $unique);
$hits = $chunkRepo->searchTopChunks($sessionId, $ftsQ, 8);
if (!empty($hits)) {
    $pass('FTS MATCH returns rows');
} else {
    $fail('FTS MATCH returns rows', 'ftsQ=' . $ftsQ);
}

$pb = new PromptBuilder();
$prepared = $pb->prepareContextDocumentForPrompt($docRepo->findBySession($sessionId));
if ($prepared === null) {
    $fail('prepareContextDocumentForPrompt', 'null');
} else {
    $block = $pb->buildContextDocumentContent(
        $prepared,
        $sessionId,
        'We need to evaluate widgets for vendors',
        $unique
    );
    if (str_contains($block, '## Retrieved excerpts (machine-ranked)')
        && str_contains($block, '| E1 |')
        && str_contains($block, $unique)
        && preg_match('/^## Retrieved excerpts/m', $block) === 1
    ) {
        $pass('prompt includes excerpts table after full document');
    } else {
        $fail('prompt includes excerpts', substr($block, 0, 600));
    }
    $bodyStart = strpos($block, '---');
    $bodyEnd   = strrpos($block, '[INSTRUCTIONS]');
    if ($bodyStart !== false && $bodyEnd !== false && $bodyStart < $bodyEnd
        && str_contains(substr($block, $bodyStart, $bodyEnd - $bodyStart), $unique)
    ) {
        $pass('full document body still present before instructions');
    } else {
        $fail('full document placement');
    }
}

// Fallback: no hits for nonsense query tokens (very short / empty after tokenization)
$prepared2 = $pb->prepareContextDocumentForPrompt($docRepo->findBySession($sessionId));
if ($prepared2 !== null) {
    $block2 = $pb->buildContextDocumentContent($prepared2, $sessionId, 'zz zy zx qw', null);
    if (!str_contains($block2, '## Retrieved excerpts (machine-ranked)')) {
        $pass('no excerpts block when FTS yields no usable query/results');
    } else {
        $fail('fallback empty retrieval', '');
    }
}

$docRepo->delete($sessionId);
$pdo = Database::getInstance()->pdo();
$pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);
$afterDel = $chunkRepo->countBySession($sessionId);
if ($afterDel === 0) {
    $pass('chunks deleted with context document');
} else {
    $fail('chunks deleted with context document', "count={$afterDel}");
}

printf("\nDone: %d passed, %d failed\n", $passN, $failN);
exit($failN > 0 ? 1 : 0);

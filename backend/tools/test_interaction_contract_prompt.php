<?php
/**
 * CLI test — Interaction Contract Prompt (unit tests, no LLM calls)
 *
 * Usage (from backend/ folder):
 *   php tools/test_interaction_contract_prompt.php
 *
 * Tests:
 *  A — required block contains ## Target Agent
 *  B — required block contains ## Claim Challenged
 *  C — required block contains ## Objection
 *  D — required block contains ## Concession
 *  E — required block contains ## Position Change
 *  F — required block contains MUST
 *  G — optional block does NOT contain MUST
 *  H — buildTargetAgentHint and buildInteractionContractBlock coexist
 *  I — php -l PromptBuilder.php returns no syntax errors
 */

$base = __DIR__ . '/../src';

require_once $base . '/Infrastructure/Logging/Logger.php';
require_once $base . '/Infrastructure/Markdown/FrontMatterParser.php';
require_once $base . '/Infrastructure/Markdown/MarkdownFileLoader.php';
require_once $base . '/Domain/Agents/DecisionDynamics.php';
require_once $base . '/Domain/Agents/Persona.php';
require_once $base . '/Domain/Agents/Agent.php';
require_once $base . '/Domain/Orchestration/RuntimeContracts.php';
require_once $base . '/Domain/Orchestration/PlaybookRuntime.php';
require_once $base . '/Domain/Orchestration/PromptBuilder.php';

// Stub DB-dependent class — not needed for prompt-only tests
if (!class_exists('Infrastructure\Persistence\ContextDocumentChunkRepository')) {
    eval('namespace Infrastructure\Persistence; class ContextDocumentChunkRepository {
        public function countBySession(string $s): int { return 0; }
        public static function buildFtsMatchQuery(string $o, ?string $m): string { return ""; }
        public static function dedupeByChunkIndex(array $r, int $n): array { return []; }
        public static function excerptCell(string $c): string { return $c; }
    }');
}

use Domain\Orchestration\PromptBuilder;

$pb   = new PromptBuilder();
$pass = 0;
$fail = 0;

function assert_contains(string $label, string $haystack, string $needle): void {
    global $pass, $fail;
    if (str_contains($haystack, $needle)) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label} — expected to find: \"{$needle}\"\n";
        $fail++;
    }
}

function assert_not_contains(string $label, string $haystack, string $needle): void {
    global $pass, $fail;
    if (!str_contains($haystack, $needle)) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label} — expected NOT to find: \"{$needle}\"\n";
        $fail++;
    }
}

// ── A–F : bloc obligatoire ────────────────────────────────────────────────────
$required = $pb->buildInteractionContractBlock(true);
assert_contains('A — required block contains ## Target Agent',     $required, '## Target Agent');
assert_contains('B — required block contains ## Claim Challenged', $required, '## Claim Challenged');
assert_contains('C — required block contains ## Objection',        $required, '## Objection');
assert_contains('D — required block contains ## Concession',       $required, '## Concession');
assert_contains('E — required block contains ## Position Change',  $required, '## Position Change');
assert_contains('F — required block contains MUST',                $required, 'MUST');

// ── G : bloc optionnel ────────────────────────────────────────────────────────
$optional = $pb->buildInteractionContractBlock(false);
assert_not_contains('G — optional block does not contain MUST', $optional, 'MUST');

// ── H : coexistence avec buildTargetAgentHint ─────────────────────────────────
$prevMessages = [['agent_id' => 'pm', 'content' => 'The market opportunity is clear.']];
$hint         = $pb->buildTargetAgentHint('analyst', $prevMessages, 'pm');
$contract     = $pb->buildInteractionContractBlock(true);

if ($hint !== '' && $contract !== '') {
    echo "[PASS] H — buildTargetAgentHint and buildInteractionContractBlock are both non-empty (coexist)\n";
    $pass++;
} else {
    echo "[FAIL] H — hint='".strlen($hint)." chars', contract='".strlen($contract)." chars'\n";
    $fail++;
}

// ── I : syntaxe PHP ──────────────────────────────────────────────────────────
$pbFile     = $base . '/Domain/Orchestration/PromptBuilder.php';
$lintResult = shell_exec('php -l ' . escapeshellarg($pbFile) . ' 2>&1');
if (str_contains((string)$lintResult, 'No syntax errors')) {
    echo "[PASS] I — PromptBuilder.php has no syntax errors\n";
    $pass++;
} else {
    echo "[FAIL] I — php -l output: {$lintResult}\n";
    $fail++;
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n--- Results: {$pass} passed, {$fail} failed ---\n";
exit($fail > 0 ? 1 : 0);

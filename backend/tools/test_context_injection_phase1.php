<?php
/**
 * Phase 1 context injection — regression checks (no HTTP).
 *
 * Usage: php backend/tools/test_context_injection_phase1.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) {
        require_once $f;
    }
});

use Domain\DecisionReliability\ContextQualityAnalyzer;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\Orchestration\DecisionSummaryService;
use Domain\Orchestration\PromptBuilder;

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

$pb = new PromptBuilder();

// 1) Short doc: hash, no truncation
$short = $pb->prepareContextDocumentForPrompt([
    'content' => 'Hello context',
    'character_count' => 13,
    'title' => 'T',
    'source_type' => 'manual',
]);
if (($short['context_hash'] ?? '') === md5('Hello context') && empty($short['context_truncated']) && !isset($short['prompt_content'])) {
    $pass('prepare: short doc has hash, not truncated, no prompt_content');
} else {
    $fail('prepare: short doc', json_encode($short));
}

// 2) Long doc: truncation
$longBody = str_repeat('D', PromptBuilder::MAX_CONTEXT_INJECT_CHARS + 500);
$long = $pb->prepareContextDocumentForPrompt([
    'content' => $longBody,
    'character_count' => mb_strlen($longBody, 'UTF-8'),
    'title' => 'Big',
    'source_type' => 'manual',
]);
if (!empty($long['context_truncated']) && !empty($long['prompt_content'])
    && mb_strlen((string)$long['prompt_content'], 'UTF-8') < mb_strlen($longBody, 'UTF-8')
    && str_contains((string)$long['prompt_content'], 'NOTICE: Context truncated')) {
    $pass('prepare: long doc truncated with notice');
} else {
    $fail('prepare: long doc', json_encode(['truncated' => $long['context_truncated'] ?? null, 'plen' => mb_strlen((string)($long['prompt_content'] ?? ''), 'UTF-8')]));
}

// 3) buildContextDocumentContent format
$block = $pb->buildContextDocumentContent($short);
if (str_contains($block, '# Hierarchy (non-negotiable)')
    && str_contains($block, '# Shared Context Document')
    && str_contains($block, '[INSTRUCTIONS]')
    && substr_count($block, '# Hierarchy (non-negotiable)') === 1) {
    $pass('buildContextDocumentContent: hierarchy + single block');
} else {
    $fail('buildContextDocumentContent: format');
}

// 4) System discipline present
$sysBlock = $pb->buildEvidenceDisciplineSystemBlock();
if (str_contains($sysBlock, 'unsupported') && str_contains($sysBlock, 'do NOT fabricate citations')) {
    $pass('buildEvidenceDisciplineSystemBlock');
} else {
    $fail('buildEvidenceDisciplineSystemBlock');
}

// 5) ContextQualityAnalyzer + truncation flag
$analyzer = new ContextQualityAnalyzer();
$base = $analyzer->analyze('We need to ship feature X with KPI 95% retention by Q4 and budget 50k EUR for B2B customers', [
    'content' => 'full',
    'context_truncated' => true,
    'character_count' => 4,
]);
if (!empty($base['context_truncated']) && $base['score'] < 1.0) {
    $pass('ContextQualityAnalyzer downgrades when context_truncated');
} else {
    $fail('ContextQualityAnalyzer truncation', json_encode($base));
}

// 6) DecisionReliabilityService summary issue key
$rel = new DecisionReliabilityService();
$summary = $rel->buildDecisionSummary(
    ['final_outcome' => 'GO_CONFIDENT', 'capped' => false, 'reason' => ''],
    ['level' => 'strong', 'context_truncated' => true],
    ['false_consensus_risk' => 'low', 'signals' => []],
    null,
    null
);
$keys = array_column($summary['top_issues'], 'key');
if (in_array('reliability.issue.context_truncated', $keys, true)) {
    $pass('buildDecisionSummary includes context_truncated issue');
} else {
    $fail('buildDecisionSummary issues', json_encode($keys));
}

// 7) DecisionBrief primary_warning when context_truncated
$briefSvc = new DecisionSummaryService();
$brief = $briefSvc->buildDecisionBrief([
    'adjusted_decision' => ['decision_label' => 'go', 'confidence_level' => 'high', 'decision_status' => 'CONFIDENT'],
    'guardrails' => ['warnings' => []],
    'decision_quality_score' => [],
    'synthesizer_output' => '',
    'reliability_warnings' => [],
    'context_quality' => ['context_truncated' => true],
]);
if (str_contains((string)$brief['primary_warning'], 'truncated')) {
    $pass('buildDecisionBrief surfaces truncation warning');
} else {
    $fail('buildDecisionBrief', (string)($brief['primary_warning'] ?? ''));
}

echo "\nDone: {$passN} passed, {$failN} failed.\n";
exit($failN > 0 ? 1 : 0);

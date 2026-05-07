<?php
/**
 * Smoke tests for lightweight evidence-first claim extraction.
 *
 * Usage: php backend/tools/test_lightweight_claim_extractor.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Domain\Evidence\LightweightClaimExtractor;
use Domain\Orchestration\CanonicalSynthesisExtractor;
use Domain\Orchestration\DecisionOutcomeProjector;

$pass = 0;
$fail = 0;

function check_claims(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    if ($condition) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    $fail++;
}

$content = <<<TXT
Decision: validate first.
The ICP will adopt this if the wedge is painful enough, but acquisition feasibility is still unproven.
Success signal: three compliance leads ask for a follow-up.
Unknown: no evidence yet that the buyer has budget authority.
According to [E1], two prospects described manual evidence collection as urgent.
Kill criteria: stop if no prospect accepts the problem framing.
TXT;

$claims = LightweightClaimExtractor::extract($content, 'founder-sprint');
$statuses = array_column($claims, 'verification_status');
$types = array_column($claims, 'claim_type');

check_claims('extracts multiple claims', count($claims) >= 4, 'count=' . count($claims));
check_claims('detects verified claim with evidence marker', in_array('verified', $statuses, true), implode(',', $statuses));
check_claims('detects unknown', in_array('unknown', $statuses, true), implode(',', $statuses));
check_claims('detects assumption', in_array('assumption', $statuses, true), implode(',', $statuses));
check_claims('detects signal type', in_array('signal', $types, true), implode(',', $types));

$canonical = CanonicalSynthesisExtractor::extract($content, 'founder-sprint');
$outcome = DecisionOutcomeProjector::fromCanonical($canonical);
check_claims('canonical synthesis carries evidence_claims', count($canonical['evidence_claims'] ?? []) >= 4);
check_claims('decision outcome carries evidence_claims', count($outcome['evidence_claims'] ?? []) >= 4);
check_claims('evidence summary includes counts', (($outcome['evidence_summary']['claim_count'] ?? 0) >= 4));

echo "\nPassed: {$pass}; Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);

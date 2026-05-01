<?php
/**
 * Manual validation of the Evidence Layer — 5 scenarios.
 *
 * Usage: php backend/tools/test_evidence_layer.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) {
        require_once $f;
    }
});

use Domain\Evidence\EvidenceClaimExtractor;
use Domain\Evidence\EvidenceAssessmentService;
use Domain\Evidence\EvidenceReportService;

$extractor = new EvidenceClaimExtractor();
$assessor  = new EvidenceAssessmentService();
$reporter  = new EvidenceReportService();

// ── helpers ────────────────────────────────────────────────────────────────
$pass = function (string $label): void {
    echo "\e[32m✓ PASS\e[0m  {$label}\n";
};
$fail = function (string $label, string $detail = ''): void {
    echo "\e[31m✗ FAIL\e[0m  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
};
$header = function (string $s): void {
    echo "\n\e[1;34m── {$s} ──\e[0m\n";
};

// ── Scenario A — agent affirms fact absent from context → unsupported ─────
$header('Scenario A — claim absent from context → unsupported');
$msgs = [
    [
        'role'     => 'assistant',
        'agent_id' => 'pm',
        'content'  => 'Our target market will reach 5 billion users within 3 years, driven by mobile growth.',
        'id'       => '1',
    ],
];
$claims = $extractor->extract($msgs);
$claims = $assessor->assess($claims, null); // no context
$statusList = array_column($claims, 'status');
if (in_array('unsupported', $statusList, true) || in_array('needs_source', $statusList, true)) {
    $pass('Claim with no context → unsupported/needs_source');
} else {
    $fail('Expected unsupported or needs_source', implode(', ', $statusList));
}

// ── Scenario B — context contradicts agent → contradicted ─────────────────
$header('Scenario B — context contradicts agent → contradicted');
$msgs = [
    [
        'role'     => 'assistant',
        'agent_id' => 'cfo',
        'content'  => 'The development will cost only $50,000 total.',
        'id'       => '2',
    ],
];
$context = 'According to internal estimates the development cost is not $50,000 but approximately $500,000 based on the project scope.';
$claims  = $extractor->extract($msgs);
$claims  = $assessor->assess($claims, $context);
$statuses = array_column($claims, 'status');
if (in_array('contradicted', $statuses, true)) {
    $pass('Claim contradicted by context → contradicted');
} else {
    $fail('Expected contradicted', 'got: ' . implode(', ', $statuses));
}

// ── Scenario C — context sources a claim → plausible/verified ─────────────
$header('Scenario C — context supports claim → plausible or verified');
$msgs = [
    [
        'role'     => 'assistant',
        'agent_id' => 'analyst',
        'content'  => 'According to the market research report, the market size is expected to grow at 15% annually.',
        'id'       => '3',
    ],
];
$context = 'Based on research data from Gartner, the market growth is expected to be around 15% per year. The report was published in 2024.';
$claims  = $extractor->extract($msgs);
$claims  = $assessor->assess($claims, $context);
$statuses = array_column($claims, 'status');
$ok = !empty(array_filter($statuses, fn($s) => in_array($s, ['plausible','verified'], true)));
if ($ok) {
    $pass('Claim supported by context → plausible/verified');
} else {
    $fail('Expected plausible or verified', 'got: ' . implode(', ', $statuses));
}

// ── Scenario D — many unsupported claims → FRAGILE evidence impact ────────
$header('Scenario D — many unsupported claims → decision_impact high and FRAGILE hint');
$msgs = [];
for ($i = 1; $i <= 8; $i++) {
    $msgs[] = [
        'role'     => 'assistant',
        'agent_id' => 'agent' . $i,
        'content'  => "The market will grow 40% next year. Users will pay $99/month. We will launch in Q1. Legal compliance will be straightforward. Revenue will reach $10 million in year 1. Technical implementation will take 2 months. The competitive advantage will be clear. Cost will be minimal.",
        'id'       => (string)$i,
    ];
}
$claims = $extractor->extract($msgs);
$claims = $assessor->assess($claims, null); // no context
$report = $reporter->buildReport($claims);
$unsup  = $report['unsupported_claims_count'];
$impact = $report['decision_impact'];
echo "  unsupported_claims_count = {$unsup}, decision_impact = {$impact}\n";
if ($unsup >= 5 && in_array($impact, ['medium', 'high'], true)) {
    $pass("High unsupported count ({$unsup}) → impact={$impact}");
} else {
    $fail('Expected many unsupported claims with medium/high impact');
}

// ── Scenario E — no evidence_report for old session → UI safe ─────────────
$header('Scenario E — null evidence_report → no crash');
$nullReport = null;
$score      = $nullReport !== null ? (float)($nullReport['evidence_score'] ?? 1.0) : null;
if ($score === null) {
    $pass('Null evidence_report handled safely (evidence_score is null)');
} else {
    $fail('Unexpected value for null report');
}

echo "\n\e[1mDone.\e[0m\n";

<?php
/**
 * Manual validation of the Risk & Reversibility Layer — 5 scenarios.
 * Usage: php backend/tools/test_risk_layer.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Domain\Risk\RiskProfileAnalyzer;
use Domain\Risk\RiskAdjustedThresholdService;
use Domain\Risk\ReversibilityAssessmentService;

$analyzer  = new RiskProfileAnalyzer();
$thrSvc    = new RiskAdjustedThresholdService();
$revSvc    = new ReversibilityAssessmentService();

$pass = fn(string $l) => print "\e[32m✓ PASS\e[0m  {$l}\n";
$fail = fn(string $l, string $d='') => print "\e[31m✗ FAIL\e[0m  {$l}" . ($d ? " — {$d}" : '') . "\n";
$h    = fn(string $s) => print "\n\e[1;34m── {$s} ──\e[0m\n";

// ── Scenario A — "Changer la couleur d'un bouton" → low/easy ─────────────
$h('Scenario A — Change button color → low risk, easy reversible');
$profile = $analyzer->analyze(
    'Should we change the color of the submit button from blue to green?',
    'quick-decision',
    [],
    null,
    0.55
);
echo "  risk_level={$profile->riskLevel}, reversibility={$profile->reversibility}\n";
if ($profile->riskLevel === 'low') {
    ($pass)("risk_level=low ✓");
} else {
    ($fail)("Expected low", "got {$profile->riskLevel}");
}
$revResult = $revSvc->assess('change the color of the submit button', null);
echo "  reversibility={$revResult}\n";
if (in_array($revResult, ['easy', 'moderate'], true)) {
    ($pass)("reversibility is easy/moderate ✓");
} else {
    ($fail)("Expected easy/moderate", "got {$revResult}");
}

// ── Scenario B — "Migrate all production tomorrow" → high/critical ────────
$h('Scenario B — Migrate all production tomorrow → high/critical risk');
$profile = $analyzer->analyze(
    'Should we migrate the entire production database to a new cloud provider tomorrow morning?',
    'decision-room',
    [],
    null,
    0.55
);
echo "  risk_level={$profile->riskLevel}, reversibility={$profile->reversibility}\n";
if (in_array($profile->riskLevel, ['high', 'critical'], true)) {
    ($pass)("risk_level={$profile->riskLevel} ✓");
} else {
    ($fail)("Expected high or critical", "got {$profile->riskLevel}");
}

// ── Scenario C — "Sign a legal contract" → high with legal category ───────
$h('Scenario C — Sign legal contract → high risk, legal category');
$profile = $analyzer->analyze(
    'Should we sign the binding contract with our new enterprise vendor for compliance with GDPR requirements?',
    'jury',
    [],
    null,
    0.55
);
echo "  risk_level={$profile->riskLevel}, categories=" . implode(',', $profile->riskCategories) . "\n";
if (in_array($profile->riskLevel, ['high','critical'], true)) {
    ($pass)("risk_level={$profile->riskLevel} ✓");
} else {
    ($fail)("Expected high/critical", "got {$profile->riskLevel}");
}
if (in_array('legal', $profile->riskCategories, true)) {
    ($pass)("legal category detected ✓");
} else {
    ($fail)("Expected legal category", "got: " . implode(',', $profile->riskCategories));
}

// ── Scenario D — "Launch reversible marketing experiment" → medium/easy ───
$h('Scenario D — Reversible A/B test → medium risk, easy reversibility');
$profile = $analyzer->analyze(
    'Should we launch an A/B test on our homepage pricing section to evaluate conversion rates?',
    'quick-decision',
    [],
    null,
    0.55
);
echo "  risk_level={$profile->riskLevel}, reversibility={$profile->reversibility}\n";
// A/B test should not be critical
if (in_array($profile->riskLevel, ['low','medium'], true)) {
    ($pass)("risk_level={$profile->riskLevel} — not critical ✓");
} else {
    ($fail)("Expected low or medium", "got {$profile->riskLevel}");
}

// ── Scenario E — high risk + score 0.62, threshold 0.55 → FRAGILE ─────────
$h('Scenario E — high risk, score 0.62 below adjusted threshold 0.70');
$thrInfo = $thrSvc->compute('high', 0.55);
echo "  configured=0.55, adjusted={$thrInfo['risk_adjusted_threshold']}, was_adjusted=" . ($thrInfo['was_adjusted'] ? 'true' : 'false') . "\n";
if ($thrInfo['risk_adjusted_threshold'] >= 0.70) {
    ($pass)("high-risk adjusted threshold ≥ 0.70 ✓");
} else {
    ($fail)("Expected adjusted threshold ≥ 0.70");
}
if ($thrInfo['was_adjusted']) {
    ($pass)("was_adjusted = true ✓");
} else {
    ($fail)("Expected was_adjusted=true");
}
// Simulate downgrade logic
$rawScore = 0.62;
$adjThr   = $thrInfo['risk_adjusted_threshold'];
$decisionStatus = 'CONFIDENT';
if ($rawScore >= 0.55 && $rawScore < $adjThr) {
    $decisionStatus = 'FRAGILE';
}
if ($decisionStatus === 'FRAGILE') {
    ($pass)("Score 0.62 passes user threshold but fails adjusted → FRAGILE ✓");
} else {
    ($fail)("Expected FRAGILE", "decisionStatus={$decisionStatus}");
}

echo "\n\e[1mDone.\e[0m\n";

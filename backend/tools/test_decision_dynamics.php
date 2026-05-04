<?php
declare(strict_types=1);
/**
 * Sanity checks for decision dynamics normalization and vote weighting math.
 */
require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

use Domain\Agents\DecisionDynamics;
use Domain\Vote\VoteAggregator;
use Infrastructure\Persistence\VoteRepository;

$d = DecisionDynamics::normalize(['reputation' => 2.0, 'consensus_resistance' => 'strong', 'evidence_sensitivity' => 'invalid', 'risk_tolerance' => 'cautious']);
if ($d['reputation'] !== 1.3) {
    echo "FAIL clamp reputation max\n";
    exit(1);
}
if ($d['consensus_resistance'] !== 'strong') {
    echo "FAIL consensus strong\n";
    exit(1);
}
if ($d['evidence_sensitivity'] !== 'normal') {
    echo "FAIL evidence fallback\n";
    exit(1);
}
echo "OK DecisionDynamics::normalize\n";

// Mock repository: no SQLite required for this arithmetic check
$repo = new class extends VoteRepository {
    public function __construct() {}
    public function findVotesBySession(string $sessionId): array {
        return [
            ['vote' => 'go', 'weight_score' => 10.0, 'agent_id' => 'a'],
            ['vote' => 'no-go', 'weight_score' => 10.0, 'agent_id' => 'b'],
        ];
    }
    public function replaceDecision(string $sessionId, array $decision): array {
        return $decision;
    }
};

class MockDynRepo extends \Infrastructure\Persistence\PersonaDecisionDynamicsRepository {
    public function __construct() {}

    public function resolveForPersona(string $personaId, ?array $frontMatterDynamics = null): array {        return DecisionDynamics::normalize([
            'reputation' => $personaId === 'a' ? 1.2 : 0.8,
        ]);
    }
}

$agg = new VoteAggregator($repo, new MockDynRepo());
$out = $agg->recompute('s1', 0.51);
if (!$out) {
    echo "FAIL recompute\n";
    exit(1);
}
$pv = $out['vote_summary']['per_vote_weighting'] ?? [];
if (count($pv) !== 2) {
    echo "FAIL per_vote count\n";
    exit(1);
}
$rowA = $pv[0];
if ((float)$rowA['applied_reputation'] !== 1.2 || (float)$rowA['weighted_contribution'] !== 12.0) {
    echo "FAIL weighted A: " . json_encode($rowA) . "\n";
    exit(1);
}

$tot = $out['vote_summary']['vote_totals'];
$expectedGoShare = 12.0 / (12.0 + 8.0);
if (abs(($tot['go'] ?? 0) / (($tot['go'] ?? 0) + ($tot['no-go'] ?? 0)) - $expectedGoShare) > 0.001) {
    echo "FAIL vote_totals ratio\n";
    exit(1);
}

echo "OK VoteAggregator reputation weighting\n";
echo "Example per_vote row: " . json_encode($pv[0], JSON_UNESCAPED_UNICODE) . "\n";
echo "All checks passed.\n";

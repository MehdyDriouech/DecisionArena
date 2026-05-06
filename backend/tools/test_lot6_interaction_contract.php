<?php
declare(strict_types=1);

/**
 * Lot 6 — interaction contract parsing, persistence fields, memory_summary, StructuredRunResult.
 *
 * Usage: php backend/tools/test_lot6_interaction_contract.php
 *
 * Quick Decision: hors scope ; pas de graphe d’edges structurées tant que le runner
 * n’appelle pas DebateMemoryService::processMessage avec contrat — non testé ici.
 */

$base = __DIR__ . '/../src';

require_once $base . '/mbstring-polyfill.php';
require_once $base . '/Infrastructure/Persistence/DebateRepository.php';
require_once $base . '/Domain/Orchestration/DebateMemoryService.php';
require_once $base . '/Domain/DecisionReliability/MemorySummaryBuilder.php';
require_once $base . '/Domain/Vote/VoteTimelineReducer.php';
require_once $base . '/Domain/Orchestration/StructuredRunResult.php';

use Domain\DecisionReliability\MemorySummaryBuilder;
use Domain\Orchestration\DebateMemoryService;
use Domain\Orchestration\StructuredRunResult;
use Infrastructure\Persistence\DebateRepository;

final class InMemoryDebateRepository extends DebateRepository {
    public array $arguments = [];
    public array $positions = [];
    public array $edges = [];

    public function __construct() {}

    public function findArgumentsBySession(string $sessionId): array {
        return array_values(array_filter($this->arguments, fn($row) => ($row['session_id'] ?? '') === $sessionId));
    }

    public function findPositionsBySession(string $sessionId): array {
        return array_values(array_filter($this->positions, fn($row) => ($row['session_id'] ?? '') === $sessionId));
    }

    public function findEdgesBySession(string $sessionId): array {
        return array_values(array_filter($this->edges, fn($row) => ($row['session_id'] ?? '') === $sessionId));
    }

    public function createArgument(array $data): array {
        $this->arguments[] = $data;
        return $data;
    }

    public function createPosition(array $data): array {
        $this->positions[] = $data;
        return $data;
    }

    public function createEdge(array $data): array {
        $data['edge_source'] = $data['edge_source'] ?? 'unknown';
        $data['edge_confidence'] = (float)($data['edge_confidence'] ?? 0.5);
        $data['claim_challenged'] = $data['claim_challenged'] ?? null;
        $data['objection'] = $data['objection'] ?? null;
        $data['concession'] = $data['concession'] ?? null;
        $data['position_change'] = $data['position_change'] ?? null;
        $data['verified_interaction'] = (int)($data['verified_interaction'] ?? 0);
        $data['target_mismatch'] = (int)($data['target_mismatch'] ?? 0);
        $this->edges[] = $data;
        return $data;
    }
}

$pass = 0;
$fail = 0;

function assert_same(string $label, mixed $expected, mixed $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}\n";
    echo '  expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo '  actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    $fail++;
}

function assert_true(string $label, bool $condition): void {
    assert_same($label, true, $condition);
}

// ── Reflection: parseInteractionContract ───────────────────────────────────

$memory = new DebateMemoryService(new InMemoryDebateRepository());
$parse = new ReflectionMethod(DebateMemoryService::class, 'parseInteractionContract');
$parse->setAccessible(true);

$fullInput = <<<'MD'
## Target Agent
architect

## Claim Challenged
"Scaling will be easy"

## Objection
This ignores database contention.

## Concession
The caching proposal is useful.

## Position Change
weakened

## Stance
oppose
## Confidence
5
## Impact
5
## Domain Weight
5
MD;

$p1 = $parse->invoke($memory, $fullInput);
assert_same('parse target_agent', 'architect', $p1['target_agent']);
assert_same('parse claim_challenged', 'Scaling will be easy', $p1['claim_challenged']);
assert_same('parse objection', 'This ignores database contention.', $p1['objection']);
assert_same('parse concession', 'The caching proposal is useful.', $p1['concession']);
assert_same('parse position_change', 'weakened', $p1['position_change']);

$p2 = $parse->invoke($memory, "Just some prose without headings.\n\n## Stance\nsupport");
assert_true('parse empty sections: all null', $p2['target_agent'] === null
    && $p2['claim_challenged'] === null && $p2['objection'] === null
    && $p2['concession'] === null && $p2['position_change'] === null);

$p3 = $parse->invoke($memory, "## Objection\nNone\n\n## Stance\n5");
assert_true('Objection None → null', $p3['objection'] === null);

$p4 = $parse->invoke($memory, "## Position Change\nmaybe\n## Stance\nx");
assert_true('invalid position_change → null', $p4['position_change'] === null);

// ── Verified interaction rules ──────────────────────────────────────────────

assert_true(
    'verified explicit_target + conf + claim',
    MemorySummaryBuilder::isVerifiedStructuredInteraction([
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'claim_challenged' => 'x',
        'objection' => '',
    ])
);

assert_true(
    'not verified: assigned_fallback even with claim',
    !MemorySummaryBuilder::isVerifiedStructuredInteraction([
        'edge_source' => 'assigned_fallback',
        'edge_confidence' => 0.45,
        'claim_challenged' => 'x',
        'objection' => '',
    ])
);

// ── processMessage integration + MemorySummaryBuilder ───────────────────────

$repo = new InMemoryDebateRepository();
$m2 = new DebateMemoryService($repo);
$sid = 'lot6-session';
$state = ['arguments' => [], 'positions' => [], 'edges' => []];

$m2->processMessage($sid, 1, 'critic', $fullInput, 'architect', $state, 'explicit_target');

$edge = $state['edges'][0] ?? null;
assert_true('edge persisted claim', ($edge['claim_challenged'] ?? null) === 'Scaling will be easy');
assert_true('edge verified flag', (int)($edge['verified_interaction'] ?? 0) === 1);
assert_true('no target mismatch', (int)($edge['target_mismatch'] ?? 0) === 0);

$m2->processMessage(
    $sid,
    1,
    'analyst',
    "## Target Agent\npm\n## Claim Challenged\nz\n## Stance\noppose\n## Confidence\n5\n## Impact\n5\n## Domain Weight\n5",
    'architect',
    $state,
    'explicit_target'
);
$edge2 = $state['edges'][1] ?? null;
assert_true('target_mismatch when parsed target differs', (int)($edge2['target_mismatch'] ?? 0) === 1);

$summary = MemorySummaryBuilder::buildMemorySummary($state['edges'], $state['positions']);
assert_same('verified_interaction_count', 2, $summary['verified_interaction_count']);
assert_same('challenged_claim_count', 2, $summary['challenged_claim_count']);
assert_true('concession_count >= 1', ($summary['concession_count'] ?? 0) >= 1);
assert_true('objection_count >= 1', ($summary['objection_count'] ?? 0) >= 1);
assert_same('position_change_count', 1, $summary['position_change_count']);
assert_same('target_mismatch_count', 1, $summary['target_mismatch_count']);

// ── StructuredRunResult.memory_summary ─────────────────────────────────────

$aug = StructuredRunResult::augment([
    'votes' => [],
    'interaction_edges' => $state['edges'],
    'positions' => $state['positions'],
]);
assert_true(
    'sessions.result slice memory_summary has new keys',
    isset($aug['memory_summary']['verified_interaction_count'])
    && isset($aug['memory_summary']['challenged_claim_count'])
);

$slice = StructuredRunResult::persistableResultSlice($aug);
assert_true(
    'persistable memory_summary includes Lot 6 counters',
    isset($slice['memory_summary']['verified_interaction_count'])
    && (int)($slice['memory_summary']['target_mismatch_count'] ?? -1) === 1
);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * Lot 5 — votes / memory_summary / envelope coherence.
 *
 * Usage: php backend/tools/test_lot5_sync.php
 */

$base = __DIR__ . '/../src';

require_once $base . '/Domain/DecisionReliability/MemorySummaryBuilder.php';
require_once $base . '/Domain/DecisionReliability/ReliabilityConfig.php';
require_once $base . '/Domain/DecisionReliability/FalseConsensusDetector.php';
require_once $base . '/Domain/Orchestration/StructuredRunResult.php';
require_once $base . '/Domain/Vote/VoteTimelineReducer.php';
require_once $base . '/Domain/Vote/VoteAggregator.php';
require_once $base . '/Infrastructure/Persistence/VoteRepository.php';
require_once $base . '/Infrastructure/Persistence/PersonaDecisionDynamicsRepository.php';

use Domain\DecisionReliability\FalseConsensusDetector;
use Domain\DecisionReliability\MemorySummaryBuilder;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\StructuredRunResult;
use Domain\Vote\VoteAggregator;
use Infrastructure\Persistence\PersonaDecisionDynamicsRepository;
use Infrastructure\Persistence\VoteRepository;

final class Lot5InMemoryVoteRepo extends VoteRepository {
    public function __construct(private array $votes) {}

    public ?array $decision = null;

    public function findVotesBySession(string $sessionId): array {
        return array_values(array_filter(
            $this->votes,
            fn($vote) => ($vote['session_id'] ?? '') === $sessionId
        ));
    }

    public function replaceDecision(string $sessionId, array $decision): array {
        $this->decision = $decision;
        return $decision;
    }
}

final class Lot5FlatDynamicsRepo extends PersonaDecisionDynamicsRepository {
    public function __construct() {}

    public function resolveEffectiveForPersona(string $personaId, ?array $frontMatterDynamics, ?string $sessionPresetId): array {
        return [
            'reputation' => 1.0,
            'consensus_resistance' => 'medium',
            'evidence_sensitivity' => 'medium',
            'risk_tolerance' => 'medium',
        ];
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
    echo "  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo "  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    $fail++;
}

function assert_true(string $label, bool $condition): void {
    assert_same($label, true, $condition);
}

function vote_row(
    string $id,
    string $sessionId,
    int $round,
    string $agentId,
    string $vote,
    float $weight,
    string $createdAt,
    string $rationale = ''
): array {
    $r = $rationale !== '' ? $rationale : "{$agentId} votes {$vote}";
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'round' => $round,
        'agent_id' => $agentId,
        'vote' => $vote,
        'confidence' => (int)$weight,
        'impact' => (int)$weight,
        'domain_weight' => (int)$weight,
        'weight_score' => $weight,
        'rationale' => $r,
        'created_at' => $createdAt,
    ];
}

// ── Test 1–2: timeline vs final + reliability on final rationales ─────────────

$sessionId = 'lot5-vote-sync';
$votes = [
    vote_row('v1', $sessionId, 1, 'agent-a', 'go', 10.0, '2026-05-06T10:00:00+00:00', 'I disagree with the risk assessment'),
    vote_row('v2', $sessionId, 2, 'agent-a', 'no-go', 10.0, '2026-05-06T10:01:00+00:00', 'Aligned after review — no dissent wording here'),
    vote_row('v3', $sessionId, 1, 'agent-b', 'go', 5.0, '2026-05-06T10:02:00+00:00', 'We are in full agreement on direction'),
];

$repo = new Lot5InMemoryVoteRepo($votes);
$aggregator = new VoteAggregator($repo, new Lot5FlatDynamicsRepo());
$decision = $aggregator->recompute($sessionId, ReliabilityConfig::DEFAULT_DECISION_THRESHOLD, null);

$timeline = $repo->findVotesBySession($sessionId);
$final = $aggregator->latestValidVotesByAgent($timeline);

assert_same('test1 vote_timeline count', 3, count($timeline));
assert_same('test1 final_votes count', 2, count($final));
assert_same('test1 ignored_timeline_vote_count', 1, (int)($decision['vote_summary']['ignored_timeline_vote_count'] ?? -1));
assert_same('test1 decision uses two final voters', 2, count($decision['vote_summary']['per_vote_weighting'] ?? []));

$detector = new FalseConsensusDetector();
$fc = $detector->detect(
    ['level' => 'medium'],
    [],
    [],
    $timeline,
    $decision
);
assert_true(
    'test2 explicit disagreement ignores superseded disagree-only rationale',
    ($fc['explicit_disagreement_observed'] ?? true) === false
);

// ── Test 3: memory_summary edge taxonomy ─────────────────────────────────────

$edges = [
    [
        'source_agent_id' => 'a',
        'target_agent_id' => 'b',
        'edge_source' => 'explicit_target',
        'edge_confidence' => 0.9,
        'edge_type' => 'challenge',
    ],
    [
        'source_agent_id' => 'b',
        'target_agent_id' => 'a',
        'edge_source' => 'assigned_fallback',
        'edge_confidence' => 0.45,
        'edge_type' => 'challenge',
    ],
    [
        'source_agent_id' => 'c',
        'target_agent_id' => 'a',
        'edge_source' => 'unknown',
        'edge_confidence' => 0.3,
        'edge_type' => 'reference',
    ],
];
$mem = MemorySummaryBuilder::buildMemorySummary($edges, [
    ['agent_id' => 'a', 'round' => 1, 'stance' => 'go'],
    ['agent_id' => 'b', 'round' => 1, 'stance' => 'hold'],
    ['agent_id' => 'c', 'round' => 1, 'stance' => 'no-go'],
]);

assert_same('test3 reliable_edge_count', 1, $mem['reliable_edge_count']);
assert_same('test3 fallback_edge_count', 1, $mem['fallback_edge_count']);
assert_same('test3 unknown_edge_count', 1, $mem['unknown_edge_count']);
assert_same('test3 explicit_edge_count', 1, $mem['explicit_edge_count']);
$possible = $mem['possible_edge_count'];
assert_true('test3 interaction_density matches reliable/possible', abs($mem['interaction_density'] - round(min(1.0, 1 / max(1, $possible)), 4)) < 0.0001);

// ── Test 4: envelope slice keys ───────────────────────────────────────────────

$slice = StructuredRunResult::persistableResultSlice([
    'votes' => $timeline,
    'final_votes' => $final,
    'memory_summary' => $mem,
    'raw_decision' => ['x' => 1],
    'adjusted_decision' => ['y' => 2],
    'false_consensus' => $fc,
    'guardrails' => ['guardrail_status' => 'pass'],
    'decision_quality_score' => ['decision_quality_score' => 70],
]);
foreach (['vote_timeline', 'final_votes', 'memory_summary', 'raw_decision', 'adjusted_decision', 'false_consensus', 'guardrails', 'decision_quality_score'] as $k) {
    assert_true("test4 persistable slice has {$k}", array_key_exists($k, $slice));
}

$augmented = StructuredRunResult::augment([
    'votes' => $timeline,
    'automatic_decision' => $decision,
    'positions' => [
        ['agent_id' => 'a', 'round' => 1, 'stance' => 'go'],
        ['agent_id' => 'b', 'round' => 1, 'stance' => 'hold'],
        ['agent_id' => 'c', 'round' => 1, 'stance' => 'no-go'],
    ],
    'interaction_edges' => $edges,
    'memory_summary' => $mem,
]);
assert_same('test4 augment vote_timeline count', 3, count($augmented['vote_timeline'] ?? []));
assert_same('test4 augment final_votes count', 2, count($augmented['final_votes'] ?? []));

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

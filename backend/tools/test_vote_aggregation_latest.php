<?php
declare(strict_types=1);

/**
 * CLI test - final vote aggregation uses only the latest valid vote per agent.
 *
 * Usage:
 *   php backend/tools/test_vote_aggregation_latest.php
 */

$base = __DIR__ . '/../src';

require_once $base . '/Domain/DecisionReliability/ReliabilityConfig.php';
require_once $base . '/Infrastructure/Persistence/VoteRepository.php';
require_once $base . '/Infrastructure/Persistence/PersonaDecisionDynamicsRepository.php';
require_once $base . '/Domain/Vote/VoteTimelineReducer.php';
require_once $base . '/Domain/Vote/VoteAggregator.php';

use Domain\Vote\VoteAggregator;
use Infrastructure\Persistence\PersonaDecisionDynamicsRepository;
use Infrastructure\Persistence\VoteRepository;

final class InMemoryVoteRepository extends VoteRepository {
    /** @param array<int,array<string,mixed>> $votes */
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

final class FlatDynamicsRepository extends PersonaDecisionDynamicsRepository {
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

function vote_row(
    string $id,
    string $sessionId,
    int $round,
    string $agentId,
    string $vote,
    float $weight,
    string $createdAt
): array {
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
        'rationale' => "{$agentId} votes {$vote}",
        'created_at' => $createdAt,
    ];
}

$sessionId = 'test-session-latest-votes';
$votes = [
    vote_row('v1', $sessionId, 1, 'agent-a', 'go', 10.0, '2026-05-06T10:00:00+00:00'),
    vote_row('v2', $sessionId, 2, 'agent-a', 'no-go', 10.0, '2026-05-06T10:01:00+00:00'),
    vote_row('v3', $sessionId, 1, 'agent-b', 'go', 5.0, '2026-05-06T10:02:00+00:00'),
];

$repo = new InMemoryVoteRepository($votes);
$aggregator = new VoteAggregator($repo, new FlatDynamicsRepository());

$timeline = $repo->findVotesBySession($sessionId);
$finalVotes = $aggregator->latestValidVotesByAgent($timeline);
$decision = $aggregator->recompute($sessionId, 0.55);

$finalByAgent = [];
foreach ($finalVotes as $vote) {
    $finalByAgent[$vote['agent_id']] = $vote['vote'];
}

assert_same('timeline keeps all 3 votes', 3, count($timeline));
assert_same('final votes contain one vote per agent', 2, count($finalVotes));
assert_same('agent A final vote is latest no-go', 'no-go', $finalByAgent['agent-a'] ?? null);
assert_same('agent B final vote remains go', 'go', $finalByAgent['agent-b'] ?? null);
assert_same('automatic decision uses latest votes only', 'no-go', $decision['decision_label'] ?? null);
assert_same('decision summary records 3 timeline votes', 3, $decision['vote_summary']['timeline_vote_count'] ?? null);
assert_same('decision summary records 2 final votes', 2, $decision['vote_summary']['final_vote_count'] ?? null);
assert_same('decision summary exposes 2 final vote rows', 2, count($decision['vote_summary']['final_votes'] ?? []));
assert_same('per-vote weighting only includes final votes', 2, count($decision['vote_summary']['per_vote_weighting'] ?? []));
assert_same('go total only includes agent B', 5.0, $decision['vote_summary']['vote_totals']['go'] ?? null);
assert_same('no-go total only includes latest agent A vote', 10.0, $decision['vote_summary']['vote_totals']['no-go'] ?? null);
assert_same('timeline still starts with agent A old go vote', 'go', $repo->findVotesBySession($sessionId)[0]['vote'] ?? null);

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

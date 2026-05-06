<?php
declare(strict_types=1);

/**
 * CLI test - honest edge provenance/confidence and reliable interaction density.
 *
 * Usage:
 *   php backend/tools/test_edge_provenance_density.php
 */

$base = __DIR__ . '/../src';

require_once $base . '/mbstring-polyfill.php';
require_once $base . '/Infrastructure/Persistence/DebateRepository.php';
require_once $base . '/Domain/Orchestration/DebateMemoryService.php';
require_once $base . '/Domain/DecisionReliability/MemorySummaryBuilder.php';
require_once $base . '/Domain/Vote/VoteTimelineReducer.php';
require_once $base . '/Domain/DecisionReliability/FalseConsensusDetector.php';
require_once $base . '/Domain/DecisionReliability/DecisionGuardrailService.php';

use Domain\DecisionReliability\DecisionGuardrailService;
use Domain\DecisionReliability\FalseConsensusDetector;
use Domain\Orchestration\DebateMemoryService;
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
    echo "  expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo "  actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    $fail++;
}

function assert_true(string $label, bool $condition): void {
    assert_same($label, true, $condition);
}

$repo = new InMemoryDebateRepository();
$memory = new DebateMemoryService($repo);
$sessionId = 'test-edge-provenance';
$state = ['arguments' => [], 'positions' => [], 'edges' => []];

$memory->processMessage(
    $sessionId,
    2,
    'critic',
    "## Target Agent\npm\n\n## Challenge\n- Claim challenged: PM said launch now without pricing evidence\n- Why it is weak: no willingness-to-pay data\n\n## Stance\noppose\n## Confidence\n8\n## Impact\n8\n## Domain Weight\n8",
    'pm',
    $state,
    'explicit_target'
);

$memory->processMessage(
    $sessionId,
    2,
    'architect',
    "I have concerns about the implementation plan.\n\n## Stance\nneeds-more-info\n## Confidence\n6\n## Impact\n6\n## Domain Weight\n6",
    'pm',
    $state,
    'assigned_fallback'
);

$memory->processMessage(
    $sessionId,
    2,
    'analyst',
    "This market sizing remains thin.\n\n## Stance\nneeds-more-info\n## Confidence\n5\n## Impact\n5\n## Domain Weight\n5",
    'pm',
    $state,
    'unknown'
);

$edges = $state['edges'];
$bySource = [];
foreach ($edges as $edge) {
    $bySource[$edge['edge_source']] = $edge;
}

assert_same('three edges are stored', 3, count($edges));
assert_same('explicit edge confidence with quoted claim is 0.9', 0.9, $bySource['explicit_target']['edge_confidence'] ?? null);
assert_same('assigned fallback edge confidence is 0.45', 0.45, $bySource['assigned_fallback']['edge_confidence'] ?? null);
assert_same('unknown edge confidence is 0.3', 0.3, $bySource['unknown']['edge_confidence'] ?? null);

$detector = new FalseConsensusDetector();
$fc = $detector->detect(
    ['level' => 'medium'],
    $state['positions'],
    $edges,
    []
);

assert_same('only the explicit edge is reliable', 1, $fc['reliable_edge_count'] ?? null);
assert_same('possible directed edges are based on 4 debate agents', 12, $fc['possible_edge_count'] ?? null);
assert_same('interaction density does not count fallback or unknown as strong', 0.0833, $fc['interaction_density'] ?? null);
assert_true('low reliable density is flagged', in_array(
    'low_reliable_interaction_density',
    array_map(fn($signal) => $signal['type'] ?? '', $fc['signals'] ?? []),
    true
));

$fcNoReliable = $detector->detect(
    ['level' => 'medium'],
    $state['positions'],
    [$bySource['assigned_fallback'], $bySource['unknown']],
    []
);

$guardrail = (new DecisionGuardrailService())->evaluate(
    ['decision_label' => 'go', 'decision_score' => 0.8],
    ['decision_label' => 'GO', 'final_outcome' => 'GO_CONFIDENT'],
    ['level' => 'medium', 'critical_missing' => []],
    $fcNoReliable,
    25.0,
    null,
    null,
    'jury',
    ['auto_retry_on_weak_debate' => false]
);

assert_same('no reliable edge means density is zero', 0.0, $fcNoReliable['interaction_density'] ?? null);
assert_same('weak debate guardrail recommends retry', 'retry_recommended', $guardrail['guardrail_status'] ?? null);
assert_true('weak_parallel_debate warning is emitted', in_array('weak_parallel_debate', $guardrail['warnings'] ?? [], true));

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

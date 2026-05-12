<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Controllers\DecisionRoomController;
use Domain\Orchestration\DecisionRoomRunner;
use Http\Request;
use Infrastructure\Persistence\SessionRepository;

final class FakeRequest extends Request
{
    /** @param array<string,mixed> $body */
    public function __construct(private array $body)
    {
    }

    public function body(): array
    {
        return $this->body;
    }
}

final class FakeSessionRepository extends SessionRepository
{
    /** @param array<string,mixed> $session */
    public function __construct(private array $session)
    {
    }

    public function findById(string $id): ?array
    {
        return $id === (string)($this->session['id'] ?? '')
            ? $this->session
            : null;
    }

    public function update(string $id, array $data): void
    {
        // No-op in test.
    }
}

final class FakeDecisionRoomRunner extends DecisionRoomRunner
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function run(
        string $sessionId,
        string $objective,
        array $selectedAgents,
        int $rounds = 2,
        string $language = 'en',
        bool $forceDisagreement = false,
        ?array $contextDoc = null,
        bool $devilAdvocateEnabled = false,
        float $devilAdvocateThreshold = 0.65,
        array $agentProviders = [],
        float $decisionThreshold = 0.55,
        array $sessionOptions = []
    ): array {
        $this->calls[] = [
            'session_id' => $sessionId,
            'objective' => $objective,
            'selected_agents' => $selectedAgents,
            'rounds' => $rounds,
        ];

        return [
            'rounds' => [],
            'arguments' => [],
            'positions' => [],
            'interaction_edges' => [],
            'weighted_analysis' => [],
            'dominance_indicator' => '',
            'votes' => [],
            'memory_summary' => null,
            'automatic_decision' => null,
            'raw_decision' => [],
            'adjusted_decision' => [],
            'context_quality' => ['score' => 0.7, 'level' => 'medium'],
            'reliability_cap' => 0.7,
            'false_consensus_risk' => 'low',
            'false_consensus' => [],
            'reliability_warnings' => [],
            'guardrails' => [],
            'decision_quality_score' => 70,
            'decision_brief' => [],
        ];
    }
}

function setPrivate(object $target, string $property, mixed $value): void
{
    $ref = new ReflectionObject($target);
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($target, $value);
}

function ok(string $name, bool $pass): void
{
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

/**
 * @param mixed $sessionSelectedAgents
 * @param array<string,mixed> $requestBody
 */
function runCase(string $label, $sessionSelectedAgents, array $requestBody, array $expectedAgents): void
{
    $controller = new DecisionRoomController();
    $runner = new FakeDecisionRoomRunner();
    $sessionRepo = new FakeSessionRepository([
        'id' => 'sess-norm',
        'selected_agents' => $sessionSelectedAgents,
        'selected_memory_ids' => '[]',
        'force_disagreement' => 0,
        'language' => 'fr',
        'decision_threshold' => 0.55,
        'status' => 'draft',
    ]);

    setPrivate($controller, 'runner', $runner);
    setPrivate($controller, 'sessionRepo', $sessionRepo);
    try {
        $result = $controller->run(new FakeRequest($requestBody));
    } catch (Throwable $e) {
        echo '[FAIL] ' . $label . ' threw: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }

    ok($label . ' returns payload', is_array($result));
    ok($label . ' runner called once', count($runner->calls) === 1);
    ok($label . ' selected_agents normalized', $runner->calls[0]['selected_agents'] === $expectedAgents);
}

runCase(
    'UI payload array',
    ['pm', 'john'],
    [
        'session_id' => 'sess-norm',
        'objective' => 'Obj',
        'selected_agents' => ['john'],
    ],
    ['john']
);

runCase(
    'Direct payload fallback to session array',
    ['pm', 'john'],
    [
        'session_id' => 'sess-norm',
        'objective' => 'Obj',
    ],
    ['pm', 'john']
);

runCase(
    'Legacy payload fallback to JSON string',
    '["pm","john"]',
    [
        'session_id' => 'sess-norm',
        'objective' => 'Obj',
    ],
    ['pm', 'john']
);

runCase(
    'Invalid fallback string returns default',
    '{bad-json',
    [
        'session_id' => 'sess-norm',
        'objective' => 'Obj',
    ],
    []
);

echo "All decision room payload normalization checks passed." . PHP_EOL;

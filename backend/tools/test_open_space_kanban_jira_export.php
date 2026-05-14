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

use Controllers\OpenSpaceController;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\OpenSpaceRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class TestRequestKanban extends Http\Request
{
    public function __construct(private array $bodyData = [], private array $queryData = [], private array $paramData = [])
    {
    }
    public function body(): array { return $this->bodyData; }
    public function query(string $key, mixed $default = null): mixed { return $this->queryData[$key] ?? $default; }
    public function param(string $key, mixed $default = null): mixed { return $this->paramData[$key] ?? $default; }
}

function kb_check(bool $ok, string $label): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $GLOBALS['__kb_fail'] = ($GLOBALS['__kb_fail'] ?? 0) + 1;
    }
}

function kb_is_error(array $res): bool
{
    return !empty($res['error']);
}

$GLOBALS['__kb_fail'] = 0;
$migration = new Migration(Database::getInstance());
$migration->run();

$contexts = new StrategicContextRepository();
$repo = new OpenSpaceRepository();
$controller = new OpenSpaceController();

$ctx = $contexts->create('OS Kanban Export Test ' . substr(bin2hex(random_bytes(4)), 0, 8), 'desc', 'active');
$contextId = (string)($ctx['context_id'] ?? '');
$board = $repo->ensureContextBoard($contextId);
$boardId = (string)($board['id'] ?? '');

$create = function (string $title, string $status) use ($controller, $contextId, $boardId): array {
    return $controller->createTask(new TestRequestKanban([
        'strategic_context_id' => $contextId,
        'context_id' => $contextId,
        'board_id' => $boardId,
        'title' => $title,
        'description' => 'desc',
        'status' => $status,
        'priority' => 'high',
        'assignee_agent_id' => 'pm',
    ]));
};

$legacy = $create('Legacy triage', 'triage');
$modern = $create('Modern doing', 'doing');
kb_check(!kb_is_error($legacy) && !kb_is_error($modern), '1) create task accepts legacy + new statuses');
kb_check(($legacy['task']['status'] ?? '') === 'backlog', '2) triage is mapped to backlog');

$taskId = (string)($modern['task']['id'] ?? '');
$taskExport = $controller->exportTaskJira(new TestRequestKanban([], ['context_id' => $contextId], ['id' => $taskId]));
kb_check(!kb_is_error($taskExport), '3) task jira export succeeds');
kb_check(is_array($taskExport['export']['issues'] ?? null) && count($taskExport['export']['issues']) === 1, '4) task jira export contains one issue');

$boardExport = $controller->exportBoardJira(new TestRequestKanban([], ['context_id' => $contextId], ['id' => $boardId]));
kb_check(!kb_is_error($boardExport), '5) board jira export succeeds');
kb_check(is_array($boardExport['export']['issues'] ?? null) && count($boardExport['export']['issues']) >= 2, '6) board jira export contains board issues');
kb_check(str_starts_with((string)($boardExport['filename'] ?? ''), 'openspace-jira-export-'), '7) jira export filename follows convention');

try { $contexts->delete($contextId); } catch (\Throwable) {}

if (($GLOBALS['__kb_fail'] ?? 0) > 0) {
    echo 'OpenSpace kanban/jira tests failed: ' . (int)$GLOBALS['__kb_fail'] . PHP_EOL;
    exit(1);
}
echo 'OpenSpace kanban/jira tests passed.' . PHP_EOL;
exit(0);


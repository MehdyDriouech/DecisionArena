<?php
/**
 * CLI checks: persona frontmatter LLM patch, session agent_providers filtering, team expansion merge.
 * Run: php backend/tools/test_session_llm_assignment.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Personas\PersonaFrontmatterLlmUpdater;

function ok(string $name, bool $pass): void {
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . "\n";
    if (!$pass) {
        exit(1);
    }
}

$md = <<<'MD'
---
id: test-agent
name: Test
default_soul: x.soul.md
default_provider: old-prov
default_model: old-model
tags:
  - a
---

# Body

Hello
MD;

$out = PersonaFrontmatterLlmUpdater::mergePatch($md, [
    'default_provider' => 'new-prov',
    'default_model' => 'new-model',
]);
ok('merge updates provider', str_contains($out, 'default_provider: new-prov'));
ok('merge updates model', str_contains($out, 'default_model: new-model'));
ok('merge preserves body', str_contains($out, '# Body'));

$out2 = PersonaFrontmatterLlmUpdater::mergePatch($out, [
    'default_provider' => '',
    'default_model' => '',
]);
ok('empty provider removes line', !preg_match('/^default_provider:/m', $out2));
ok('empty model removes line', !preg_match('/^default_model:/m', $out2));

// --- SessionController-style filter (mirrors Controllers\SessionController::filterEmptyAgentProviderMap logic)
$filter = static function (array $raw): array {
    $out = [];
    foreach ($raw as $agentId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $aid = strtolower(trim((string)$agentId));
        if ($aid === '') {
            continue;
        }
        $pid = trim((string)($row['provider_id'] ?? ''));
        if ($pid === '') {
            continue;
        }
        $model = trim((string)($row['model'] ?? ''));
        $out[$aid] = [
            'provider_id' => $pid,
            'model'       => $model !== '' ? $model : null,
        ];
    }
    return $out;
};

ok('filter drops empty provider', $filter(['a' => ['provider_id' => '  ', 'model' => 'x']]) === []);
ok('filter keeps valid', $filter(['pm' => ['provider_id' => 'p1', 'model' => '']]) === ['pm' => ['provider_id' => 'p1', 'model' => null]]);
ok('filter normalizes agent id', isset($filter([' John ' => ['provider_id' => 'p2', 'model' => 'm2']])['john']));

// Team merge simulation (explicit agent row wins)
$agentProviders = $filter(['Mary' => ['provider_id' => 'solo', 'model' => 'm1']]);
$blueAssign = ['provider_id' => 'blue-p', 'model' => 'bm'];
$blueAgents = [' mary ', 'PM'];
foreach ($blueAgents as $agentId) {
    $normalizedAgentId = strtolower(trim((string)$agentId));
    if ($normalizedAgentId === '') {
        continue;
    }
    if (!isset($agentProviders[$normalizedAgentId]) && trim((string)($blueAssign['provider_id'] ?? '')) !== '') {
        $agentProviders[$normalizedAgentId] = [
            'provider_id' => trim((string)$blueAssign['provider_id']),
            'model'       => trim((string)($blueAssign['model'] ?? '')) ?: null,
        ];
    }
}
ok('team merge does not overwrite explicit agent', ($agentProviders['mary']['provider_id'] ?? '') === 'solo');
ok('team merge fills other blue agent', ($agentProviders['pm']['provider_id'] ?? '') === 'blue-p');

echo "All session_llm_assignment checks passed.\n";

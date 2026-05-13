<?php
declare(strict_types=1);

/**
 * Guards RunnerProgressPercent::roundRunPercent (Decision Room / Jury / Stress Test bar).
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

/** Legacy stress-test shape: round(min(ratio) * 100) — one arg to round. */
function legacy_stress_llm_percent(int $round, int $rounds): int
{
    return (int)round(
        min(0.99, max(0.02, (($round - 1) / max(1, $rounds)) + 0.15 / max(1, $rounds))) * 100
    );
}

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$rp = \Domain\Orchestration\RunnerProgressPercent::class;
$rounds = 3;
$check($rp::roundRunPercent(1, $rounds, 0.0) === 2, 'round 1 start within=0.0 => 2%');
$check($rp::roundRunPercent(1, $rounds, 0.2) === 9, 'round 1 llm within=0.2 => 9%');
$check($rp::roundRunPercent(1, $rounds, 0.6) === 22, 'round 1 response within=0.6 => 22%');
$check($rp::roundRunPercent(3, $rounds, 0.95) === 98, 'final round synth within=0.95 => 98%');

$check(
    $rp::roundRunPercent(1, $rounds, 0.2) !== legacy_stress_llm_percent(1, $rounds),
    'round 1 llm: shared formula differs from legacy stress literal (5% vs 9%)'
);

exit($fail > 0 ? 1 : 0);

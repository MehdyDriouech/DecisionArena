<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/mbstring-polyfill.php';
spl_autoload_register(function(string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (is_file($f)) require_once $f;
});

$svc = new Domain\Prompts\PromptPolicyService();
$list = $svc->list();
echo 'list: ' . count($list) . " items\n";
foreach ($list as $item) {
    echo '  - ' . $item['id'] . ' (' . $item['filename'] . ")\n";
}
$detail = $svc->get('social-dynamics-policy');
echo 'social-dynamics-policy content length: ' . strlen($detail['content']) . "\n";

// Test invalid id
try {
    $svc->get('../../etc/passwd');
    echo "FAIL: should have thrown\n";
} catch (\InvalidArgumentException $e) {
    echo "OK: invalid id rejected: " . $e->getMessage() . "\n";
}

echo "All checks passed.\n";

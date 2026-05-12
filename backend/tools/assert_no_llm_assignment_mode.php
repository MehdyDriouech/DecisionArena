<?php
/**
 * Non-régression : interdit la réapparition de l'état zombie LLM dans le code runtime.
 *
 * Usage : php backend/tools/assert_no_llm_assignment_mode.php
 *
 * Portée : frontend/src et backend/src uniquement (pas docs, pas frontend/i18n.js).
 */
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$roots = [
    $repoRoot . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'src',
    $repoRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'src',
];

$needles = ['llmAssignmentMode', 'set-llm-assignment-mode'];
$allowedExt = ['js' => true, 'ts' => true, 'tsx' => true, 'jsx' => true, 'php' => true, 'vue' => true, 'svelte' => true];
$violations = [];

foreach ($roots as $root) {
    if (!is_dir($root)) {
        $violations[] = "Missing directory: {$root}";
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!isset($allowedExt[$ext])) {
            continue;
        }
        $path = $file->getPathname();
        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                $violations[] = "{$path}: contains '{$needle}'";
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "assert_no_llm_assignment_mode: FORBIDDEN\n" . implode("\n", $violations) . "\n");
    exit(1);
}

fwrite(STDOUT, "assert_no_llm_assignment_mode: OK (no needles in frontend/src or backend/src)\n");
exit(0);

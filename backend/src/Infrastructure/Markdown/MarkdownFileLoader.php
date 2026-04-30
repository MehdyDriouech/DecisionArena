<?php
namespace Infrastructure\Markdown;

class MarkdownFileLoader {
    private string $baseDir;
    private FrontMatterParser $parser;

    public function __construct(string $baseDir) {
        $this->baseDir = realpath($baseDir) ?: $baseDir;
        $this->parser = new FrontMatterParser();
    }

    public function loadAll(string $subDir): array {
        $dir = $this->baseDir . '/' . ltrim($subDir, '/');
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.md') ?: [];
        $result = [];
        foreach ($files as $file) {
            $item = $this->loadFile($file);
            if ($item) $result[] = $item;
        }
        return $result;
    }

    public function loadById(string $subDir, string $id): ?array {
        // Prevent path traversal
        $id = preg_replace('/[^a-z0-9\-_\.]/', '', strtolower($id));
        $file = $this->baseDir . '/' . ltrim($subDir, '/') . '/' . $id . '.md';
        return $this->loadFile($file);
    }

    private function loadFile(string $path): ?array {
        if (!file_exists($path)) return null;
        $content = file_get_contents($path);
        $parsed = $this->parser->parse($content);
        return array_merge(
            $parsed['meta'],
            [
                'content'  => $parsed['content'],
                'filename' => basename($path),
            ]
        );
    }
}

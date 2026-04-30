<?php
namespace Infrastructure\Markdown;

class FrontMatterParser {
    public function parse(string $content): array {
        $meta = [];
        $body = $content;
        if (str_starts_with($content, '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if (count($parts) >= 3) {
                $yaml = trim($parts[1]);
                $body = trim($parts[2]);
                $meta = $this->parseYaml($yaml);
            }
        }
        return ['meta' => $meta, 'content' => $body];
    }

    private function parseYaml(string $yaml): array {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $inList = false;
        foreach ($lines as $line) {
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $m)) {
                $currentKey = $m[1];
                $val = trim($m[2]);
                if ($val === '' || $val === null) {
                    $result[$currentKey] = [];
                    $inList = true;
                } else {
                    $result[$currentKey] = $this->castValue($val);
                    $inList = false;
                }
            } elseif (preg_match('/^\s+-\s+(.+)$/', $line, $m) && $currentKey && $inList) {
                if (!is_array($result[$currentKey])) {
                    $result[$currentKey] = [];
                }
                $result[$currentKey][] = trim($m[1]);
            }
        }
        return $result;
    }

    private function castValue(string $val): mixed {
        if ($val === 'true') return true;
        if ($val === 'false') return false;
        if (is_numeric($val)) return $val + 0;
        return $val;
    }
}

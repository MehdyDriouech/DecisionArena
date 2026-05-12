<?php
namespace Domain\Personas;

/**
 * Updates default_provider / default_model in YAML frontmatter of a persona .md file.
 * Only keys present in $patch are modified. Empty string value removes the key line.
 */
final class PersonaFrontmatterLlmUpdater {
    /**
     * @param array<string, string> $patch keys: default_provider, default_model (values trimmed; '' removes)
     */
    public static function mergePatch(string $markdown, array $patch): string {
        if (!str_starts_with($markdown, '---')) {
            throw new \InvalidArgumentException('Persona markdown must start with YAML frontmatter (---).');
        }
        $parts = preg_split('/^---\s*$/m', $markdown, 3);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid frontmatter: expected closing --- delimiter.');
        }
        $yamlBlock = $parts[1];
        $body = $parts[2];

        $touchP = array_key_exists('default_provider', $patch);
        $touchM = array_key_exists('default_model', $patch);
        $valP = $touchP ? trim((string)$patch['default_provider']) : null;
        $valM = $touchM ? trim((string)$patch['default_model']) : null;

        $lines = explode("\n", $yamlBlock);
        $out = [];
        $seenP = false;
        $seenM = false;
        foreach ($lines as $line) {
            if (preg_match('/^default_provider:\s*.*$/', $line)) {
                $seenP = true;
                if (!$touchP) {
                    $out[] = $line;
                    continue;
                }
                if ($valP !== '') {
                    $out[] = 'default_provider: ' . self::yamlScalar($valP);
                }
                continue;
            }
            if (preg_match('/^default_model:\s*.*$/', $line)) {
                $seenM = true;
                if (!$touchM) {
                    $out[] = $line;
                    continue;
                }
                if ($valM !== '') {
                    $out[] = 'default_model: ' . self::yamlScalar($valM);
                }
                continue;
            }
            $out[] = $line;
        }

        if ($touchP && !$seenP && $valP !== '') {
            $out = self::insertAfterKey($out, 'default_soul', 'default_provider: ' . self::yamlScalar($valP));
        }
        if ($touchM && !$seenM && $valM !== '') {
            $after = ($touchP && $valP !== '') ? 'default_provider' : 'default_soul';
            $out = self::insertAfterKey($out, $after, 'default_model: ' . self::yamlScalar($valM));
        }

        $newYaml = rtrim(implode("\n", $out)) . "\n";
        return '---' . "\n" . $newYaml . '---' . $body;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function insertAfterKey(array $lines, string $afterKey, string $newLine): array {
        $pattern = '/^' . preg_quote($afterKey, '/') . ':/';
        $out = [];
        $inserted = false;
        foreach ($lines as $line) {
            $out[] = $line;
            if (!$inserted && preg_match($pattern, $line)) {
                $out[] = $newLine;
                $inserted = true;
            }
        }
        if (!$inserted) {
            array_unshift($out, $newLine);
        }
        return $out;
    }

    private static function yamlScalar(string $v): string {
        if ($v === '') {
            return '""';
        }
        if (preg_match('/^[a-zA-Z0-9_.\-:]+$/', $v)) {
            return $v;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
}

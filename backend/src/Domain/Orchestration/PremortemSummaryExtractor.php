<?php
namespace Domain\Orchestration;

/**
 * Extract structured premortem_summary from synthesizer output (fenced JSON).
 */
class PremortemSummaryExtractor {
    /**
     * @return ?array<string,mixed>
     */
    public static function fromSynthesizerOutput(string $content): ?array {
        if ($content === '') {
            return null;
        }
        // Prefer ```json ... ``` blocks containing premortem_summary
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $content, $blocks)) {
            foreach ($blocks[1] as $raw) {
                $parsed = self::tryDecodePremortem(trim($raw));
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        // Inline JSON object starting with premortem_summary
        if (preg_match('/\{\s*"premortem_summary"\s*:/s', $content)) {
            if (preg_match('/(\{[\s\S]*"premortem_summary"[\s\S]*\})/s', $content, $m)) {
                $decoded = json_decode(self::balanceJson($m[1]), true);
                return self::normalize($decoded['premortem_summary'] ?? null);
            }
        }
        return null;
    }

    private static function tryDecodePremortem(string $jsonish): ?array {
        $d = json_decode($jsonish, true);
        if (!is_array($d)) {
            return null;
        }
        return self::normalize($d['premortem_summary'] ?? null);
    }

    /** Best-effort: trim to outermost braces if trailing garbage */
    private static function balanceJson(string $s): string {
        $s = trim($s);
        $start = strpos($s, '{');
        if ($start === false) {
            return $s;
        }
        $depth = 0;
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return $s;
    }

    /** @param mixed $raw @return ?array<string,mixed> */
    private static function normalize($raw): ?array {
        if (!is_array($raw)) {
            return null;
        }
        return [
            'failure_scenario'      => isset($raw['failure_scenario']) ? (string)$raw['failure_scenario'] : '',
            'root_causes'           => self::strList($raw['root_causes'] ?? []),
            'invalid_assumptions'   => self::strList($raw['invalid_assumptions'] ?? []),
            'ignored_signals'       => self::strList($raw['ignored_signals'] ?? []),
            'preventive_actions'    => self::strList($raw['preventive_actions'] ?? []),
            'residual_risk_level'   => isset($raw['residual_risk_level']) ? (string)$raw['residual_risk_level'] : '',
        ];
    }

    /** @param mixed $v @return list<string> */
    private static function strList($v): array {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_slice($out, 0, 20);
    }
}

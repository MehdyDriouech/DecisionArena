<?php
declare(strict_types=1);

namespace Domain\Orchestration;

/**
 * Extract `{ "tradeoffs": { ... } }` from synthesizer output (fenced ```json blocks).
 */
class TradeoffsJsonExtractor
{
    /**
     * Returns the inner tradeoffs array (enabled, criteria, summary, options) or null.
     *
     * @return ?array<string,mixed>
     */
    public static function fromSynthesizerOutput(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $content, $blocks)) {
            foreach ($blocks[1] as $raw) {
                $nested = self::decodeTradeoffsFromJsonString(trim($raw));
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        if (preg_match('/\{\s*"tradeoffs"\s*:/s', $content)) {
            if (preg_match('/(\{[\s\S]*"tradeoffs"[\s\S]*\})/s', $content, $m)) {
                $decoded = json_decode(self::truncateBalancedJson($m[1]), true);
                if (is_array($decoded) && !empty($decoded['tradeoffs']) && is_array($decoded['tradeoffs'])) {
                    return self::sanitizeShape($decoded['tradeoffs']);
                }
            }
        }
        return null;
    }

    private static function decodeTradeoffsFromJsonString(string $jsonish): ?array
    {
        $d = json_decode($jsonish, true);
        if (!is_array($d)) {
            return null;
        }
        $t = $d['tradeoffs'] ?? null;
        if ($t === null && isset($d['criteria']) && is_array($d['criteria'])) {
            $t = $d;
        }
        return is_array($t) ? self::sanitizeShape($t) : null;
    }

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private static function sanitizeShape(array $t): array
    {
        return [
            'enabled'   => $t['enabled'] ?? true,
            'criteria'  => $t['criteria'] ?? [],
            'summary'   => $t['summary'] ?? '',
            'options'   => $t['options'] ?? null,
        ];
    }

    private static function truncateBalancedJson(string $s): string
    {
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
}

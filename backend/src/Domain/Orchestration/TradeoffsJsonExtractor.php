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

    /**
     * Remove an outer `{ "tradeoffs": { ... } }` blob from prose (e.g. LLM used `json` / one backtick, or no closing ``` fence).
     * Uses the **last** occurrence so an appendix at the end of "Recommended Action" wins.
     *
     * @return array{0: string, 1: ?array<string,mixed>} [cleanedText, innerTradeoffsOrNull]
     */
    public static function stripTradeoffsEnvelopeFromProse(string $text): array
    {
        $lastStart = null;
        $p = 0;
        while (preg_match('/\{\s*"tradeoffs"\s*:/s', $text, $m, PREG_OFFSET_CAPTURE, $p)) {
            $lastStart = (int)$m[0][1];
            $p = $lastStart + 1;
        }
        if ($lastStart === null) {
            return [$text, null];
        }
        $fromBrace = substr($text, $lastStart);
        $jsonStr = self::truncateBalancedJson($fromBrace);
        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded) || empty($decoded['tradeoffs']) || !is_array($decoded['tradeoffs'])) {
            return [$text, null];
        }
        $inner = self::sanitizeShape($decoded['tradeoffs']);
        $before = substr($text, 0, $lastStart);
        $before = preg_replace('/[`]{1,3}\s*json\s*$/i', '', rtrim($before));
        $before = preg_replace('/\bjson\s*$/i', '', rtrim($before));
        $before = preg_replace('/```(?:json)?\s*$/i', '', rtrim($before));
        $after = substr($text, $lastStart + strlen($jsonStr));
        $cleaned = trim(preg_replace("/\n{3,}/", "\n\n", rtrim($before) . $after));
        return [$cleaned, $inner];
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

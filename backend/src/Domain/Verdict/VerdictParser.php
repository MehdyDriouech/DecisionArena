<?php
namespace Domain\Verdict;

class VerdictParser {
    private const ALLOWED_LABELS = ['go','no-go','risky','needs-more-info','reduce-scope'];

    public static function parse(string $content): ?array {
        if (stripos($content, 'Final Verdict') === false && stripos($content, 'Verdict Label') === false) {
            return null;
        }

        $label = null;
        if (preg_match('/##\s*Verdict Label\s*\n+\s*(go|no-go|risky|needs-more-info|reduce-scope)/im', $content, $m)) {
            $label = strtolower(trim($m[1]));
        } elseif (preg_match('/\b(go|no-go|risky|needs-more-info|reduce-scope)\b/i', $content, $m)) {
            $label = strtolower(trim($m[1]));
        }

        if (!$label || !in_array($label, self::ALLOWED_LABELS, true)) {
            return null;
        }

        $summary = '';
        if (preg_match('/##\s*Verdict Summary\s*\n+(.*?)(?=\n##|\z)/is', $content, $ms)) {
            $summary = trim($ms[1]);
        }
        if (!$summary) {
            $summary = 'No summary provided.';
        }

        $scores = [];
        foreach ([
            'feasibility_score'   => '/Feasibility[:\s]+(\d+)\s*\/\s*10/i',
            'product_value_score' => '/Product Value[:\s]+(\d+)\s*\/\s*10/i',
            'ux_score'            => '/UX[:\s]+(\d+)\s*\/\s*10/i',
            'risk_score'          => '/Risk[:\s]+(\d+)\s*\/\s*10/i',
            'confidence_score'    => '/Confidence[:\s]+(\d+)\s*\/\s*10/i',
        ] as $key => $pattern) {
            $scores[$key] = preg_match($pattern, $content, $sm) ? min(10, max(0, (int)$sm[1])) : null;
        }

        $action = '';
        if (preg_match('/##\s*Recommended Action\s*\n+(.*?)(?=\n##|\z)/is', $content, $mr)) {
            $action = trim($mr[1]);
        }

        return array_merge([
            'verdict_label'      => $label,
            'verdict_summary'    => $summary,
            'recommended_action' => $action,
        ], $scores);
    }
}

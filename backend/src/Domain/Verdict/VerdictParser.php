<?php
namespace Domain\Verdict;

use Domain\Orchestration\CanonicalSynthesisExtractor;
use Domain\Orchestration\TradeoffsJsonExtractor;

class VerdictParser {
    private const ALLOWED_LABELS = ['go','no-go','risky','needs-more-info','reduce-scope'];

    public static function parse(string $content, ?string $playbookId = null): ?array {
        $canonical = CanonicalSynthesisExtractor::extract($content, $playbookId);
        $label = null;
        if (($canonical['decision'] ?? '') !== '') {
            $label = self::legacyLabelFromCanonical((string)$canonical['decision']);
        }
        if (!$label) {
            if (preg_match('/##\s*Verdict Label\s*\n+\s*(go|no-go|risky|needs-more-info|reduce-scope)/im', $content, $m)) {
                $label = strtolower(trim($m[1]));
            } elseif (preg_match('/(?:verdict|decision|recommendation|judgment|conclusion)\s*[:\-]\s*(go|no-go|risky|needs-more-info|reduce-scope|iterate|pivot|defer|pursue|narrow|kill|stop|proceed|pause)/i', $content, $m)) {
                $label = self::normalizeLabel($m[1]);
            } elseif (preg_match('/\b(go|no-go|risky|needs-more-info|reduce-scope|iterate|pivot|defer|pursue|narrow|kill|stop|proceed|pause)\b/i', $content, $m)) {
                $label = self::normalizeLabel($m[1]);
            } elseif (preg_match('/\b(insufficient context|need(?:s)? more information|not enough information|no consensus)\b/i', $content, $m)) {
                $label = strtolower(trim($m[1]));
                $label = str_contains($label, 'consensus') || str_contains($label, 'information') || str_contains($label, 'context')
                    ? 'needs-more-info'
                    : $label;
            }
        }

        if (!$label || !in_array($label, self::ALLOWED_LABELS, true)) {
            if (($canonical['parser_diagnostics']['parser_confidence'] ?? 0) > 0.2) {
                $label = 'needs-more-info';
            } else {
                return null;
            }
        }

        $summary = '';
        if (preg_match('/##\s*Verdict Summary\s*\n+(.*?)(?=\n##|\z)/is', $content, $ms)) {
            $summary = trim($ms[1]);
        } elseif (preg_match('/##\s*(?:Why|Rationale|Conclusion|Decision)\s*\n+(.*?)(?=\n##|\z)/is', $content, $ms)) {
            $summary = trim($ms[1]);
        } elseif (preg_match('/(?:because|rationale|why)\s*[:\-]\s*(.+?)(?:\n\n|\z)/is', $content, $ms)) {
            $summary = trim($ms[1]);
        }
        if (!$summary) {
            $why = $canonical['why'] ?? [];
            $summary = is_array($why) && $why !== [] ? implode(' ', array_slice($why, 0, 2)) : 'No summary provided.';
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
        } elseif (preg_match('/##\s*(?:Next Step|Recommended Next Step|Immediate Next Action|Action)\s*\n+(.*?)(?=\n##|\z)/is', $content, $mr)) {
            $action = trim($mr[1]);
        } elseif (preg_match('/(?:recommended action|next step|immediate next action)\s*[:\-]\s*(.+?)(?:\n\n|\z)/is', $content, $mr)) {
            $action = trim($mr[1]);
        }
        // Ne pas persister les blocs ```json collés par le LLM (affichage + exports).
        $action = preg_replace('/```(?:json)?\s*[\s\S]*?```/', '', $action);
        [$action] = TradeoffsJsonExtractor::stripTradeoffsEnvelopeFromProse($action);
        $action = trim(preg_replace("/\n{3,}/", "\n\n", $action));
        if ($action === '' && !empty($canonical['recommended_next_actions']) && is_array($canonical['recommended_next_actions'])) {
            $action = implode("\n", array_slice($canonical['recommended_next_actions'], 0, 3));
        }

        return array_merge([
            'verdict_label'      => $label,
            'verdict_summary'    => $summary,
            'recommended_action' => $action,
            'canonical_synthesis' => $canonical,
            'parser_diagnostics' => $canonical['parser_diagnostics'] ?? [],
        ], $scores);
    }

    private static function normalizeLabel(string $raw): string {
        $label = strtolower(trim($raw));
        return match ($label) {
            'proceed', 'pursue' => 'go',
            'kill', 'stop' => 'no-go',
            'pivot', 'narrow', 'defer', 'iterate', 'pause' => 'reduce-scope',
            default => $label,
        };
    }

    private static function legacyLabelFromCanonical(string $decision): string {
        return match (strtoupper(trim($decision))) {
            'GO' => 'go',
            'NO-GO' => 'no-go',
            'ITERATE' => 'reduce-scope',
            'INSUFFICIENT_CONTEXT', 'NO_CONSENSUS' => 'needs-more-info',
            default => '',
        };
    }
}

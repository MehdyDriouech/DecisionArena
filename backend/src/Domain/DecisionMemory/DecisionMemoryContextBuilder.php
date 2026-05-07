<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

use Domain\Orchestration\RuntimeContracts;

final class DecisionMemoryContextBuilder
{
    public const MAX_SELECTED_MEMORIES = 5;
    public const MAX_INJECT_CHARS = 6000;

    /**
     * @param list<array<string,mixed>> $compactMemories
     * @return array{block:string, injected_ids:list<string>, truncated:bool, chars:int}
     */
    public static function buildInjectionBlock(array $compactMemories, string $language = 'en'): array
    {
        $picked = array_slice($compactMemories, 0, self::MAX_SELECTED_MEMORIES);
        $ids = [];

        $title = $language === 'fr'
            ? '## Decision Memory (historique compact — non vérifié)'
            : '## Decision Memory (compact historical records — unverified)';
        $warn = $language === 'fr'
            ? "Ces éléments sont des **enregistrements de décisions passées**, pas des faits vérifiés. Ne les traite pas comme vérité. Si une mémoire contredit le contexte actuel, privilégie le contexte actuel."
            : "These are **prior decision records**, not verified facts. Do not treat them as ground truth. If they conflict with current context, prefer the current context.";

        $lines = [];
        $lines[] = "---";
        $lines[] = $title;
        $lines[] = $warn;
        $lines[] = "";
        $lines[] = "Rules:";
        $lines[] = "- Use as context only; do not cite as evidence.";
        $lines[] = "- If uncertain or outdated, propose a validation step.";
        $lines[] = "";

        foreach ($picked as $m) {
            $mid = (string)($m['memory_id'] ?? '');
            $ids[] = $mid;
            $pb = (string)($m['playbook_id'] ?? '');
            $st = RuntimeContracts::normalizeStatus((string)($m['decision_status'] ?? ''));
            $cf = RuntimeContracts::normalizeConfidence((string)($m['confidence'] ?? ''));
            $summary = trim((string)($m['decision_summary'] ?? ''));
            $created = (string)($m['created_at'] ?? '');

            $lines[] = "### Memory {$mid}";
            $lines[] = "- playbook_id: {$pb}";
            $lines[] = "- decision_status: {$st}";
            $lines[] = "- confidence: {$cf}";
            $lines[] = "- created_at: {$created}";
            $lines[] = "- summary: " . ($summary !== '' ? $summary : '(missing)');

            $risks = is_array($m['unresolved_risks'] ?? null) ? $m['unresolved_risks'] : [];
            $next = is_array($m['recommended_next_steps'] ?? null) ? $m['recommended_next_steps'] : [];
            if (!empty($risks)) {
                $lines[] = "- unresolved_risks: " . implode(' | ', array_slice(array_map('strval', $risks), 0, 6));
            }
            if (!empty($next)) {
                $lines[] = "- recommended_next_steps: " . implode(' | ', array_slice(array_map('strval', $next), 0, 6));
            }
            $lines[] = "";
        }

        $block = implode("\n", $lines);
        $truncated = false;
        $charset = 'UTF-8';
        if (mb_strlen($block, $charset) > self::MAX_INJECT_CHARS) {
            $block = mb_substr($block, 0, self::MAX_INJECT_CHARS, $charset)
                . "\n\n[NOTICE: Decision Memory block truncated for prompt injection.]";
            $truncated = true;
        }

        return [
            'block' => $block,
            'injected_ids' => array_values(array_filter($ids)),
            'truncated' => $truncated,
            'chars' => mb_strlen($block, $charset),
        ];
    }
}


<?php

declare(strict_types=1);

namespace Domain\Evidence;

/**
 * Builds an optional LLM prompt for evidence-enriched claim extraction (V2 hook).
 *
 * In V1 the heuristic pipeline is used directly.
 * This class provides the prompt structure for when a provider IS available.
 */
class EvidencePromptBuilder
{
    /**
     * Build a system + user message pair for LLM-based claim extraction.
     *
     * @param array<int,array<string,mixed>> $messages   agent messages
     * @param string|null                    $contextText context document content
     * @return list<array{role:string, content:string}>
     */
    public function buildExtractionPrompt(array $messages, ?string $contextText): array
    {
        $messagesText = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'assistant') {
                continue;
            }
            $agent = $m['agent_id'] ?? 'agent';
            $messagesText .= "\n[{$agent}]: " . mb_substr((string)($m['content'] ?? ''), 0, 600, 'UTF-8') . "\n";
        }

        $contextSection = $contextText
            ? "## Context Document\n\n" . mb_substr($contextText, 0, 2000, 'UTF-8') . "\n\n"
            : "## Context Document\n\n(none provided)\n\n";

        $system = <<<SYS
You are an evidence analyst. Your task is to extract important factual claims and assumptions from a multi-agent debate and assess each one against the provided context document.

For each claim, output a JSON object with these fields:
- claim_text (string, max 200 chars)
- claim_type (one of: factual, market_assumption, technical_assumption, legal_risk, cost_assumption, user_behavior_assumption, strategic_assumption)
- status (one of: verified, plausible, unsupported, contradicted, needs_source)
- confidence (float 0–1)
- evidence_text (the relevant excerpt from the context, or null)

Rules:
- Only mark a claim "verified" if the context explicitly supports it with data or a named source.
- Mark "contradicted" if the context explicitly contradicts the claim.
- Mark "plausible" if the context is consistent with the claim but does not fully confirm it.
- Mark "unsupported" if the context has no relevant information.
- Mark "needs_source" for factual claims that require an external source to verify.
- Do not invent sources. Do not claim external verification unless the context provides it.
- Return a JSON array of claims, no extra text.
SYS;

        $user = $contextSection
            . "## Agent Contributions\n\n"
            . $messagesText
            . "\n\nExtract the most important claims (maximum 15) and return a JSON array.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    /**
     * Build a brief evidence summary suitable for injection into a decision prompt.
     *
     * @param array<string,mixed> $report evidence report
     */
    public function buildEvidenceSummaryBlock(array $report): string
    {
        if (empty($report)) {
            return '';
        }

        $score    = round((float)($report['evidence_score'] ?? 1.0) * 100, 1);
        $unsup    = (int)($report['unsupported_claims_count'] ?? 0);
        $contra   = (int)($report['contradicted_claims_count'] ?? 0);
        $impact   = (string)($report['decision_impact'] ?? 'low');
        $rec      = (string)($report['recommendation'] ?? '');
        $unknowns = (array)($report['critical_unknowns'] ?? []);

        $block  = "## Evidence Assessment\n\n";
        $block .= "- Evidence coverage score: {$score}%\n";
        $block .= "- Unsupported claims: {$unsup}\n";
        $block .= "- Contradicted claims: {$contra}\n";
        $block .= "- Decision impact of evidence gaps: {$impact}\n";
        if (!empty($unknowns)) {
            $block .= "- Critical unknowns:\n";
            foreach ($unknowns as $u) {
                $block .= "  - {$u}\n";
            }
        }
        if ($rec !== '') {
            $block .= "\nEvidence recommendation: {$rec}\n";
        }
        $block .= "\n";

        return $block;
    }
}

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

        $score    = isset($report['score']) ? round((float)$report['score'], 1) : round((float)($report['evidence_score'] ?? 1.0) * 100, 1);
        $density  = isset($report['evidence_density']) ? round((float)$report['evidence_density'] * 100, 1) : null;
        $badge    = (string)($report['evidence_badge'] ?? '');
        $unsup    = (int)($report['unsupported_claims_count'] ?? 0);
        $contra   = (int)($report['contradicted_claims_count'] ?? 0);
        $hiu      = (int)($report['high_importance_unsupported_count'] ?? 0);
        $hic      = (int)($report['high_importance_contradicted_count'] ?? 0);
        $impact   = (string)($report['decision_impact'] ?? 'low');
        $rec      = (string)($report['recommendation'] ?? '');
        $unknowns = (array)($report['critical_unknowns'] ?? []);

        $block  = "## Evidence Assessment\n\n";
        if ($badge !== '') {
            $block .= "- Evidence strength: **{$badge}**\n";
        }
        $block .= "- Evidence score (0–100): {$score}\n";
        if ($density !== null) {
            $block .= "- Important-claim support density: {$density}%\n";
        }
        $block .= "- Unsupported claims: {$unsup}\n";
        $block .= "- Contradicted claims: {$contra}\n";
        if ($hiu > 0) {
            $block .= "- High-importance unsupported: {$hiu}\n";
        }
        if ($hic > 0) {
            $block .= "- High-importance contradicted: {$hic}\n";
        }
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

    /**
     * Directive for Devil's Advocate — evidence-aware challenges (no fabricated sources).
     */
    public function buildDevilAdvocateUserMessage(string $debateBody, ?array $evidenceReport, ?array $contextDoc): string
    {
        $parts = [];

        if ($evidenceReport !== null) {
            $parts[] = $this->buildDevilAdvocateEvidenceDirective($evidenceReport, $contextDoc);
        }

        $parts[] = "## Debate excerpt\n\n" . trim($debateBody);

        return implode("\n\n", array_filter($parts));
    }

    /**
     * @param array<string,mixed> $evidenceReport
     * @param ?array<string,mixed> $contextDoc
     */
    public function buildDevilAdvocateEvidenceDirective(?array $evidenceReport, ?array $contextDoc): string
    {
        if ($evidenceReport === null || empty($evidenceReport)) {
            return "## Evidence signals\n\n(none computed yet — challenge unstated assumptions with falsifiable questions.)";
        }

        $density = round((float)($evidenceReport['evidence_density'] ?? 0) * 100, 1);
        $hic     = (int)($evidenceReport['high_importance_contradicted_count'] ?? 0);
        $hiu     = (int)($evidenceReport['high_importance_unsupported_count'] ?? 0);
        $contra  = (int)($evidenceReport['contradicted_claims_count'] ?? 0);
        $chClaims = (int)($evidenceReport['challenged_claims_count'] ?? 0);
        $badge   = (string)($evidenceReport['evidence_badge'] ?? '');
        $claims  = (array)($evidenceReport['claims'] ?? []);
        $trunc   = !empty($contextDoc['context_truncated'] ?? false);

        $targets = [];
        foreach ($claims as $c) {
            if (!empty($c['challenge_flag']) && count($targets) < 6) {
                $lab = (string)($c['support_class'] ?? '');
                $targets[] = '- User-challenged (' . $lab . '): ' . ($c['claim_text'] ?? '');
            }
        }
        foreach ($claims as $c) {
            if (($c['support_class'] ?? '') === 'contradicted' && ($c['importance'] ?? '') === 'high') {
                $targets[] = '- Contradicted (high): ' . ($c['claim_text'] ?? '');
            }
        }
        foreach ($claims as $c) {
            if (count($targets) >= 4) {
                break;
            }
            if (($c['support_class'] ?? '') === 'unsupported' && ($c['importance'] ?? '') === 'high') {
                $targets[] = '- Unsupported (high): ' . ($c['claim_text'] ?? '');
            }
        }

        $out = "## Evidence signals (machine-assessed)\n\n";
        $out .= "- Strength: {$badge}\n";
        $out .= "- Important-claim density: {$density}%\n";
        $out .= "- Contradicted: {$contra} (high-importance flagged: {$hic})\n";
        $out .= "- High-importance unsupported: {$hiu}\n";
        $out .= "- User-challenged claims (disagreement, not truth reversal): {$chClaims}\n";
        if ($trunc) {
            $out .= "- Context was truncated for prompts; treat agent citations as unverified unless they quote the context doc.\n";
        }
        if ($density < 40) {
            $out .= "- Low evidence density: prioritize asking which context passage supports each key claim.\n";
        }
        if ($targets !== []) {
            $out .= "\n**Prioritize challenging:**\n" . implode("\n", array_slice($targets, 0, 4)) . "\n";
        }
        $out .= "\nRules: ask falsifiable questions; never invent external facts; demand quotes from the Shared Context Document when agents assert specifics.\n";

        return $out;
    }
}

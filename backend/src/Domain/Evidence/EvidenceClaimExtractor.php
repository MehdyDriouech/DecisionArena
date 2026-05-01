<?php

declare(strict_types=1);

namespace Domain\Evidence;

/**
 * Heuristic extractor — no LLM required for V1.
 *
 * Scans agent messages for sentences that look like strong claims and assigns
 * a preliminary claim_type. The EvidenceAssessmentService then rates each
 * claim against the context document.
 */
class EvidenceClaimExtractor
{
    /** Minimum character length of a sentence to be considered a claim. */
    private const MIN_CLAIM_LENGTH = 20;

    /** Maximum claims extracted per message (avoids noise). */
    private const MAX_PER_MESSAGE = 6;

    /** Maximum total claims per session (performance guard). */
    private const MAX_TOTAL = 60;

    // ── Keyword sets per claim type ──────────────────────────────────────────

    private const TYPE_PATTERNS = [
        'legal_risk'   => [
            'legally', 'legally required', 'regulatory', 'regulation', 'compliance',
            'gdpr', 'patent', 'lawsuit', 'liability', 'illegal', 'legal risk',
            'terms of service', 'terms of use', 'law requires', 'prohibited',
        ],
        'cost_assumption' => [
            'will cost', 'cost estimate', 'budget', 'total cost', 'price point',
            'revenue', 'margin', 'roi', 'return on investment', 'cash flow',
            'monthly cost', 'annual cost', 'expenses', 'investment required',
        ],
        'market_assumption' => [
            'market size', 'market share', 'total addressable market', 'tam',
            'market growth', 'market will', 'customers will', 'demand for',
            'consumer', 'audience', 'users want', 'users will', 'adoption rate',
            'growth rate',
        ],
        'user_behavior_assumption' => [
            'users will', 'users are', 'users prefer', 'users expect', 'users want',
            'customers will', 'customers expect', 'behavior', 'behaviour',
            'engagement', 'retention', 'churn', 'conversion rate', 'user experience',
        ],
        'technical_assumption' => [
            'technically feasible', 'will scale', 'can handle', 'latency',
            'performance', 'throughput', 'architecture will', 'infrastructure',
            'api will', 'system will', 'database can', 'response time',
            'technically possible', 'implementable', 'integration',
        ],
        'strategic_assumption' => [
            'competitive advantage', 'first mover', 'moat', 'disrupt', 'pivot',
            'strategy', 'strategic', 'differentiation', 'value proposition',
            'positioning', 'business model', 'growth strategy',
        ],
        'factual' => [
            'research shows', 'studies show', 'according to', 'data shows',
            'statistics', 'proven', 'evidence shows', 'survey', 'report says',
            'published', 'peer-reviewed', 'confirmed by',
        ],
    ];

    /**
     * Strong assertion signals — sentences containing these are likely claims.
     */
    private const ASSERTION_PATTERNS = [
        '/\b(will|shall|must|should|can|cannot|is guaranteed|is proven|is confirmed|definitely|certainly|undoubtedly)\b/i',
        '/\b\d+(\.\d+)?\s*(%|percent|million|billion|users|customers|months?|weeks?|days?)\b/i',
        '/\$\s*\d+(\.\d+)?(\s*(k|m|b|million|billion))?\b/i',
        '/\b(cost|price|revenue|budget|margin|roi)\b/i',
        '/\b(market|demand|adoption|growth|share)\b/i',
        '/\b(risk|liability|legal|compliance|regulation)\b/i',
        '/\b(technically|feasible|scalable|reliable|secure|performant)\b/i',
        '/\b(advantage|disruption|moat|strategy|pivot)\b/i',
    ];

    /**
     * @param array<int,array<string,mixed>> $messages  rows from messages table (role=assistant)
     * @return list<array<string,mixed>>                raw (unevaluated) claims
     */
    public function extract(array $messages): array
    {
        $claims = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') {
                continue;
            }
            $content  = (string)($msg['content'] ?? '');
            $agentId  = $msg['agent_id'] ?? null;
            $msgId    = isset($msg['id']) ? (string)$msg['id'] : null;

            $sentences = $this->splitIntoSentences($content);
            $extracted = 0;

            foreach ($sentences as $sentence) {
                if ($extracted >= self::MAX_PER_MESSAGE) {
                    break;
                }
                if (mb_strlen($sentence, 'UTF-8') < self::MIN_CLAIM_LENGTH) {
                    continue;
                }
                if (!$this->looksLikeAssertion($sentence)) {
                    continue;
                }
                $type = $this->detectClaimType($sentence);
                if ($type === null) {
                    continue;
                }

                $claims[] = [
                    'claim_text' => $this->cleanSentence($sentence),
                    'claim_type' => $type,
                    'agent_id'   => $agentId,
                    'message_id' => $msgId,
                    // Status/confidence assigned by EvidenceAssessmentService
                    'status'          => 'unsupported',
                    'confidence'      => 0.5,
                    'evidence_text'   => null,
                    'source_reference'=> null,
                ];
                $extracted++;

                if (count($claims) >= self::MAX_TOTAL) {
                    return $claims;
                }
            }
        }

        return $claims;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function splitIntoSentences(string $text): array
    {
        // Strip Markdown headers/bullets before splitting
        $text = preg_replace('/^#{1,6}\s+.+$/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*•]\s+/m', '', $text) ?? $text;

        // Split on sentence-ending punctuation followed by space or newline
        $parts = preg_split('/(?<=[.!?])\s+/', $text) ?: [];

        $result = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $result[] = $p;
            }
        }
        return $result;
    }

    private function looksLikeAssertion(string $sentence): bool
    {
        foreach (self::ASSERTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }
        return false;
    }

    private function detectClaimType(string $sentence): ?string
    {
        $lower = mb_strtolower($sentence, 'UTF-8');

        foreach (self::TYPE_PATTERNS as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $type;
                }
            }
        }

        // Generic strong-assertion fallback — tag as strategic_assumption
        if (preg_match('/\b(will|must|should|is guaranteed|definitely)\b/i', $sentence)) {
            return 'strategic_assumption';
        }

        return null;
    }

    private function cleanSentence(string $sentence): string
    {
        // Remove Markdown bold/italic markers
        $s = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $sentence) ?? $sentence;
        $s = preg_replace('/_{1,2}([^_]+)_{1,2}/', '$1', $s) ?? $s;
        return trim($s);
    }
}

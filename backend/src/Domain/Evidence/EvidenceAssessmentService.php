<?php

declare(strict_types=1);

namespace Domain\Evidence;

/**
 * Heuristic claim assessor — Phase 3 taxonomy (support independent of FTS success).
 *
 * Matching uses the full Shared Context Document only. Retrieval / FTS is optional
 * metadata: source_layer=retrieval when the match span overlaps a Phase-2 chunk row
 * that was in the machine-ranked excerpt set (priority_chunk_ids).
 *
 * support_class: supported | unsupported | contradicted | not_applicable
 */
class EvidenceAssessmentService
{
    private const WINDOW_CHARS = 200;

    private const NEGATION_PATTERNS = [
        '/\b(not|no|never|false|incorrect|wrong|disproved|contradicts|contrary to|unlikely|impossible|invalid|inaccurate)\b/i',
        '/\b(fails to|cannot|will not|does not|has not|have not|is not|are not|were not|was not)\b/i',
    ];

    private const SOURCE_MARKERS = [
        'according to', 'source:', 'ref:', 'reference:', 'see:', 'as per',
        'study shows', 'research shows', 'report by', 'data from',
        'published by', 'cited in', 'based on',
    ];

    /**
     * @param list<array<string,mixed>> $claims
     * @param array{
     *   chunks?: list<array{id:int,start_offset:int,end_offset:int}>,
     *   priority_chunk_ids?: list<int>
     * } $chunkMeta
     * @return list<array<string,mixed>>
     */
    public function assess(array $claims, ?string $fullContextText, array $chunkMeta = []): array
    {
        $chunks      = $chunkMeta['chunks'] ?? [];
        $priorityIds = [];
        foreach (($chunkMeta['priority_chunk_ids'] ?? []) as $pid) {
            $priorityIds[(int)$pid] = true;
        }

        $ctxLow  = ($fullContextText !== null && $fullContextText !== '')
            ? mb_strtolower($fullContextText, 'UTF-8')
            : '';
        $hasCtx = $ctxLow !== '';

        foreach ($claims as &$claim) {
            $claimText = (string)($claim['claim_text'] ?? '');
            $claimType = (string)($claim['claim_type'] ?? 'strategic_assumption');
            $importance = $this->deriveImportance($claimType);
            $claim['importance'] = $importance;

            if ($this->isNotApplicableClaim($claimText, $claimType)) {
                $claim['support_class']    = 'not_applicable';
                $claim['source_layer']     = 'none';
                $claim['linked_chunk_ids'] = null;
                $claim['status']           = 'not_applicable';
                $claim['confidence']       = 0.5;
                continue;
            }

            if (!$hasCtx) {
                $claim['support_class']    = 'unsupported';
                $claim['source_layer']     = 'none';
                $claim['linked_chunk_ids'] = null;
                $claim['status']           = $claimType === 'factual' ? 'needs_source' : 'unsupported';
                $claim['confidence']       = 0.3;
                continue;
            }

            $claimLow = mb_strtolower($claimText, 'UTF-8');
            $keywords = $this->extractKeywords($claimLow);
            if (empty($keywords)) {
                $claim['support_class']    = 'unsupported';
                $claim['source_layer']     = 'none';
                $claim['linked_chunk_ids'] = null;
                $claim['status']           = $claimType === 'factual' ? 'needs_source' : 'unsupported';
                $claim['confidence']       = 0.3;
                continue;
            }

            $matchPos = $this->findFirstMatch($ctxLow, $keywords);
            if ($matchPos === -1) {
                $claim['support_class']    = 'unsupported';
                $claim['source_layer']     = 'none';
                $claim['linked_chunk_ids'] = null;
                $claim['status']           = $claimType === 'factual' ? 'needs_source' : 'unsupported';
                $claim['confidence']       = 0.3;
                continue;
            }

            $window = $this->extractWindow($fullContextText ?? '', $matchPos);
            $linked = $this->chunksContainingPosition($chunks, $matchPos);
            $claim['linked_chunk_ids'] = $linked === []
                ? null
                : json_encode(array_values(array_unique(array_column($linked, 'id'))), JSON_UNESCAPED_UNICODE);
            $claim['source_layer'] = $this->resolveSourceLayer($linked, $priorityIds);

            if ($this->hasNegation($window)) {
                $claim['support_class']  = 'contradicted';
                $claim['status']         = 'contradicted';
                $claim['confidence']     = 0.75;
                $claim['evidence_text']  = $window;
                continue;
            }

            $hasCitation = $this->hasCitation($claimLow, $ctxLow);
            $claim['support_class'] = 'supported';
            $claim['status']        = $hasCitation ? 'verified' : 'plausible';
            $claim['confidence']    = $hasCitation ? 0.85 : 0.65;
            $claim['evidence_text'] = $window;
        }
        unset($claim);

        return $claims;
    }

    /** @param list<array{id:int}> $linkedChunks */
    private function resolveSourceLayer(array $linkedChunks, array $priorityIdSet): string
    {
        foreach ($linkedChunks as $c) {
            if (isset($priorityIdSet[(int)($c['id'] ?? 0)])) {
                return 'retrieval';
            }
        }
        return 'user_doc';
    }

    /**
     * @param list<array{id:int,start_offset:int,end_offset:int}> $chunks
     * @return list<array{id:int}>
     */
    private function chunksContainingPosition(array $chunks, int $matchCharPos): array
    {
        $out = [];
        foreach ($chunks as $c) {
            $start = (int)($c['start_offset'] ?? 0);
            $end   = (int)($c['end_offset'] ?? 0);
            if ($matchCharPos >= $start && $matchCharPos < $end) {
                $out[] = ['id' => (int)$c['id']];
            }
        }
        return $out;
    }

    private function deriveImportance(string $claimType): string
    {
        return match ($claimType) {
            'factual', 'legal_risk' => 'high',
            'cost_assumption', 'market_assumption', 'technical_assumption', 'user_behavior_assumption' => 'medium',
            default => 'low',
        };
    }

    private function isNotApplicableClaim(string $claimText, string $claimType): bool
    {
        if ($claimType !== 'strategic_assumption') {
            return false;
        }
        $low = mb_strtolower($claimText, 'UTF-8');
        if (preg_match('/\b\d+(\.\d+)?\s*(%|million|billion|users?|months?)\b/', $low)) {
            return false;
        }
        return (bool) preg_match(
            '/\b(we (should|could|might|may)|i think|in my view|opinion|recommend|suggest|consider|trade-?off|alternatively|rather than|personally)\b/i',
            $low
        );
    }

    // ── Private helpers (keyword / window — same spirit as V1) ─────────────────

    private function extractKeywords(string $claimLow): array
    {
        static $stopWords = [
            'the','a','an','is','are','was','were','be','been','being','have','has',
            'had','do','does','did','will','would','could','should','may','might',
            'shall','can','must','of','to','in','for','on','with','at','by','from',
            'up','about','into','through','during','before','after','above','below',
            'this','that','these','those','it','its','and','or','but','so','yet',
            'both','either','not','also','then','than','very','just','we','our',
            'their','they','he','she','i','you','our','its',
        ];

        $tokens = preg_split('/[\s,;:.!?()\[\]\/]+/', $claimLow, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kws    = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok, 'UTF-8') < 4) {
                continue;
            }
            if (in_array($tok, $stopWords, true)) {
                continue;
            }
            $kws[] = $tok;
            if (count($kws) >= 4) {
                break;
            }
        }
        return $kws;
    }

    private function findFirstMatch(string $ctxLow, array $keywords): int
    {
        foreach ($keywords as $kw) {
            $pos = mb_strpos($ctxLow, $kw, 0, 'UTF-8');
            if ($pos !== false) {
                return (int)$pos;
            }
        }
        return -1;
    }

    private function extractWindow(string $originalCtx, int $pos): string
    {
        $len     = mb_strlen($originalCtx, 'UTF-8');
        $start   = max(0, $pos - self::WINDOW_CHARS);
        $length  = min($len - $start, self::WINDOW_CHARS * 2);
        $excerpt = mb_substr($originalCtx, $start, $length, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt);
    }

    private function hasNegation(string $windowText): bool
    {
        foreach (self::NEGATION_PATTERNS as $pat) {
            if (preg_match($pat, $windowText)) {
                return true;
            }
        }
        return false;
    }

    private function hasCitation(string $claimLow, string $ctxLow): bool
    {
        foreach (self::SOURCE_MARKERS as $marker) {
            if (str_contains($claimLow, $marker) || str_contains($ctxLow, $marker)) {
                return true;
            }
        }
        return false;
    }
}

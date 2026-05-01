<?php

declare(strict_types=1);

namespace Domain\Evidence;

/**
 * Heuristic claim assessor — V1, no external data source.
 *
 * Statuses assigned:
 *   verified    – claim keywords found in context + explicit citation marker present
 *   plausible   – claim keywords found in context, no negation nearby
 *   contradicted– claim keywords found but negation pattern present in the same window
 *   unsupported – no relevant signal found in context
 *   needs_source– claim type is 'factual' with no context match
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
     * @return list<array<string,mixed>>
     */
    public function assess(array $claims, ?string $contextText): array
    {
        $ctxLow = ($contextText !== null) ? mb_strtolower($contextText, 'UTF-8') : '';
        $hasCtx = $ctxLow !== '';

        foreach ($claims as &$claim) {
            $claimType = (string)($claim['claim_type'] ?? 'strategic_assumption');
            $claimLow  = mb_strtolower((string)($claim['claim_text'] ?? ''), 'UTF-8');

            if (!$hasCtx) {
                $claim['status']     = ($claimType === 'factual') ? 'needs_source' : 'unsupported';
                $claim['confidence'] = 0.3;
                continue;
            }

            $keywords = $this->extractKeywords($claimLow);
            if (empty($keywords)) {
                $claim['status']     = 'unsupported';
                $claim['confidence'] = 0.3;
                continue;
            }

            // Find the first keyword match in context
            $matchPos = $this->findFirstMatch($ctxLow, $keywords);

            if ($matchPos === -1) {
                $claim['status']     = ($claimType === 'factual') ? 'needs_source' : 'unsupported';
                $claim['confidence'] = 0.3;
                continue;
            }

            // Extract a window around the match for negation/citation checks
            $window = $this->extractWindow($contextText ?? '', $matchPos);

            if ($this->hasNegation($window)) {
                $claim['status']        = 'contradicted';
                $claim['confidence']    = 0.75;
                $claim['evidence_text'] = $window;
                continue;
            }

            $hasCitation = $this->hasCitation($claimLow, $ctxLow);
            if ($hasCitation) {
                $claim['status']        = 'verified';
                $claim['confidence']    = 0.85;
                $claim['evidence_text'] = $window;
            } else {
                $claim['status']        = 'plausible';
                $claim['confidence']    = 0.6;
                $claim['evidence_text'] = $window;
            }
        }
        unset($claim);

        return $claims;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Extract 2–4 distinctive keywords, skipping stop words and short tokens. */
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

    /** Returns byte/char position of first keyword hit, or -1. */
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
        $len    = mb_strlen($originalCtx, 'UTF-8');
        $start  = max(0, $pos - self::WINDOW_CHARS);
        $length = min($len - $start, self::WINDOW_CHARS * 2);
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

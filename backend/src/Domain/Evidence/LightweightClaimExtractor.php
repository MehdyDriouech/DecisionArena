<?php
declare(strict_types=1);

namespace Domain\Evidence;

use Domain\Orchestration\PlaybookRuntime;

/**
 * Lightweight evidence-first claim extraction for synthesis outputs.
 *
 * This is not a truth engine. It surfaces important claims, assumptions,
 * unknowns and visible contradictions with simple heuristics so the UI can make
 * decision fragility visible without adding a heavy QA/RAG pipeline.
 */
final class LightweightClaimExtractor
{
    private const MAX_CLAIMS = 8;
    private const MIN_LEN = 18;

    /** @return list<array<string,mixed>> */
    public static function extract(string $content, ?string $playbookId = null): array
    {
        $sentences = self::candidateSentences($content);
        $claims = [];
        foreach ($sentences as $sentence) {
            $claim = self::claimFromSentence($sentence, $playbookId);
            if ($claim === null) {
                continue;
            }
            $claims[] = $claim;
        }

        usort($claims, function (array $a, array $b): int {
            $pa = (int)($a['_priority'] ?? 0);
            $pb = (int)($b['_priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            return strlen((string)($b['claim'] ?? '')) <=> strlen((string)($a['claim'] ?? ''));
        });

        $deduped = [];
        $seen = [];
        foreach ($claims as $claim) {
            unset($claim['_priority']);
            $key = strtolower(substr(preg_replace('/[^a-z0-9]+/i', ' ', (string)$claim['claim']), 0, 90));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $claim;
            if (count($deduped) >= self::MAX_CLAIMS) {
                break;
            }
        }

        return $deduped;
    }

    /** @return array<string,mixed> */
    public static function summarize(array $claims): array
    {
        $counts = [
            'verified' => 0,
            'weak_evidence' => 0,
            'assumption' => 0,
            'unknown' => 0,
            'contradicted' => 0,
        ];
        foreach ($claims as $claim) {
            $status = (string)($claim['verification_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return [
            'claim_count' => count($claims),
            'status_counts' => $counts,
            'primary_fragility' => self::primaryFragility($counts),
        ];
    }

    /** @return list<string> */
    private static function candidateSentences(string $content): array
    {
        $content = preg_replace('/```(?:json|text)?\s*|\s*```/i', ' ', $content) ?? $content;
        $content = preg_replace('/^\s*#{1,6}\s+/m', '', $content) ?? $content;
        $content = preg_replace('/^\s*[-*]\s+/m', '', $content) ?? $content;
        $parts = preg_split('/(?<=[.!?])\s+|\n+/', $content) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $s = trim(preg_replace('/\s+/', ' ', (string)$part));
            $s = trim($s, " \t\n\r\0\x0B-:");
            if (mb_strlen($s, 'UTF-8') >= self::MIN_LEN && self::looksDecisionRelevant($s)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private static function looksDecisionRelevant(string $sentence): bool
    {
        return (bool)preg_match(
            '/\b(will|should|must|risk|unknown|assumption|hypothesis|evidence|signal|validated|proven|likely|may|could|because|depends|fails|failure|kill|pivot|customers?|users?|market|technical|execution|moat|timing|wedge|icp)\b/i',
            $sentence
        );
    }

    /** @return ?array<string,mixed> */
    private static function claimFromSentence(string $sentence, ?string $playbookId): ?array
    {
        $claimType = self::claimType($sentence);
        if ($claimType === null) {
            return null;
        }
        $supporting = self::supportingEvidence($sentence);
        $contradictions = self::contradictions($sentence);
        $status = self::verificationStatus($claimType, $supporting, $contradictions, $sentence);

        return [
            'claim' => self::clean($sentence),
            'claim_type' => $claimType,
            'confidence' => self::confidence($claimType, $supporting, $contradictions, $sentence),
            'supporting_evidence' => $supporting,
            'contradictions' => $contradictions,
            'verification_status' => $status,
            '_priority' => self::playbookPriority($sentence, $playbookId) + self::statusPriority($status),
        ];
    }

    private static function claimType(string $sentence): ?string
    {
        $s = strtolower($sentence);
        if (preg_match('/\b(unknown|unclear|missing|not yet proven|not proven|lack|lacks|evidence gap|open question|unvalidated)\b/', $s)) {
            return 'unknown';
        }
        if (preg_match('/\b(success signal|validation signal|failure signal|kill criteria|threshold|metric|observable signal|leading indicator)\b/', $s)) {
            return 'signal';
        }
        if (preg_match('/\b(according to|data shows|evidence shows|research shows|context shows|confirmed|validated|verified|\[e\d+\])\b/i', $sentence)) {
            return 'fact';
        }
        if (preg_match('/\b(seems|likely|probably|may|might|could|intuition|I think|appears)\b/i', $sentence)) {
            return 'intuition';
        }
        if (preg_match('/\b(assume|assumption|hypothesis|will|should|must|depends on|requires|buyers? will|users? will|customers? will)\b/i', $sentence)) {
            return 'assumption';
        }
        return null;
    }

    /** @return list<string> */
    private static function supportingEvidence(string $sentence): array
    {
        $out = [];
        if (preg_match_all('/\[E\d+\]/i', $sentence, $m)) {
            $out = array_merge($out, $m[0]);
        }
        if (preg_match('/\b(because|supported by|based on|validated by|evidence shows|data shows|according to)\b(.{0,140})/i', $sentence, $m)) {
            $evidence = trim((string)($m[0] ?? ''));
            if ($evidence !== '') {
                $out[] = self::clean($evidence);
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /** @return list<string> */
    private static function contradictions(string $sentence): array
    {
        $out = [];
        if (preg_match('/\b(however|but|contradicts?|conflicts?|unless|fails if|failure signal|kill criteria|risk is|risk:)\b(.{0,160})/i', $sentence, $m)) {
            $out[] = self::clean((string)$m[0]);
        }
        return $out;
    }

    /** @param list<string> $supporting @param list<string> $contradictions */
    private static function verificationStatus(string $claimType, array $supporting, array $contradictions, string $sentence): string
    {
        if ($contradictions !== [] && preg_match('/\b(contradict|conflict|fails|failure|kill)\b/i', $sentence)) {
            return 'contradicted';
        }
        if ($claimType === 'unknown') {
            return 'unknown';
        }
        if ($claimType === 'fact' && $supporting !== []) {
            return 'verified';
        }
        if ($claimType === 'assumption' || $claimType === 'intuition') {
            return 'assumption';
        }
        return 'weak_evidence';
    }

    /** @param list<string> $supporting @param list<string> $contradictions */
    private static function confidence(string $claimType, array $supporting, array $contradictions, string $sentence): string
    {
        if ($contradictions !== [] || $claimType === 'unknown') {
            return 'weak';
        }
        if ($supporting !== []) {
            return 'strong';
        }
        if (preg_match('/\b(high confidence|strong signal|validated|verified)\b/i', $sentence)) {
            return 'strong';
        }
        if (preg_match('/\b(low confidence|weak|unproven|uncertain)\b/i', $sentence)) {
            return 'weak';
        }
        return 'moderate';
    }

    private static function playbookPriority(string $sentence, ?string $playbookId): int
    {
        $contract = PlaybookRuntime::contractFor($playbookId);
        if (!$contract) {
            return 0;
        }
        $score = 0;
        $lower = strtolower($sentence);
        foreach (($contract['validation_expectations'] ?? []) as $aliases) {
            foreach ((array)$aliases as $alias) {
                if ($alias !== '' && str_contains($lower, strtolower((string)$alias))) {
                    $score += 2;
                    break;
                }
            }
        }
        foreach (self::playbookFocusAliases((string)$playbookId) as $alias) {
            if (str_contains($lower, $alias)) {
                $score += 2;
            }
        }
        return $score;
    }

    /** @return list<string> */
    private static function playbookFocusAliases(string $playbookId): array
    {
        return match ($playbookId) {
            'founder-sprint' => ['icp', 'acquisition', 'wedge', 'validation'],
            'ceo-challenge' => ['moat', 'timing', 'execution', 'capability', 'strategic'],
            'stress-test' => ['weakest assumption', 'systemic risk', 'dependency', 'failure'],
            default => [],
        };
    }

    private static function statusPriority(string $status): int
    {
        return match ($status) {
            'contradicted', 'unknown' => 4,
            'assumption', 'weak_evidence' => 3,
            'verified' => 1,
            default => 0,
        };
    }

    /** @param array<string,int> $counts */
    private static function primaryFragility(array $counts): string
    {
        if (($counts['contradicted'] ?? 0) > 0) {
            return 'contradicted';
        }
        if (($counts['unknown'] ?? 0) > 0) {
            return 'unknown';
        }
        if (($counts['weak_evidence'] ?? 0) > 0) {
            return 'weak_evidence';
        }
        if (($counts['assumption'] ?? 0) > 0) {
            return 'assumption';
        }
        return ($counts['verified'] ?? 0) > 0 ? 'verified' : 'none';
    }

    private static function clean(string $text): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text) ?? $text;
        $text = preg_replace('/_{1,2}([^_]+)_{1,2}/', '$1', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_strlen($text, 'UTF-8') > 320 ? mb_substr($text, 0, 317, 'UTF-8') . '...' : $text;
    }
}

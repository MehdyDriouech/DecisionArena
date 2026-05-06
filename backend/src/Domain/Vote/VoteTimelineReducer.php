<?php
namespace Domain\Vote;

/**
 * Pure vote-timeline reduction (no DB). Used by reliability / false-consensus without VoteRepository.
 */
final class VoteTimelineReducer {
    private const VALID_VOTES = ['go', 'no-go', 'reduce-scope', 'needs-more-info', 'pivot'];

    /**
     * @param array<int,array<string,mixed>> $votes
     * @return array<int,array<string,mixed>>
     */
    public static function latestValidVotesByAgent(array $votes): array {
        $valid = [];
        foreach ($votes as $index => $vote) {
            $agentId = trim((string)($vote['agent_id'] ?? ''));
            $label   = self::normalizeVoteLabel((string)($vote['vote'] ?? ''));
            if ($agentId === '' || !in_array($label, self::VALID_VOTES, true)) {
                continue;
            }
            $vote['agent_id'] = $agentId;
            $vote['vote'] = $label;
            $vote['_timeline_index'] = $index;
            $valid[] = $vote;
        }

        usort($valid, fn($a, $b) => self::compareVoteChronology($a, $b));

        $latestByAgent = [];
        foreach ($valid as $vote) {
            $latestByAgent[(string)$vote['agent_id']] = $vote;
        }

        $finalVotes = array_values($latestByAgent);
        usort($finalVotes, fn($a, $b) => self::compareVoteChronology($a, $b));

        foreach ($finalVotes as &$vote) {
            unset($vote['_timeline_index']);
        }
        unset($vote);

        return $finalVotes;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function compareVoteChronology(array $a, array $b): int {
        $timeCmp = self::voteTimestamp($a['created_at'] ?? null) <=> self::voteTimestamp($b['created_at'] ?? null);
        if ($timeCmp !== 0) {
            return $timeCmp;
        }

        $roundCmp = self::voteRound($a['round'] ?? null) <=> self::voteRound($b['round'] ?? null);
        if ($roundCmp !== 0) {
            return $roundCmp;
        }

        return (int)($a['_timeline_index'] ?? 0) <=> (int)($b['_timeline_index'] ?? 0);
    }

    private static function voteTimestamp(mixed $value): int {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function voteRound(mixed $value): int {
        return is_numeric($value) ? (int)$value : -1;
    }

    private static function normalizeVoteLabel(string $label): string {
        $value = strtolower(trim($label));
        $value = str_replace('_', '-', $value);
        $value = preg_replace('/\s+/', '-', $value) ?? $value;
        if (str_contains($value, 'no-go')) return 'no-go';
        if (str_contains($value, 'reduce')) return 'reduce-scope';
        if (str_contains($value, 'needs-more-info')) return 'needs-more-info';
        if (str_contains($value, 'need-more')) return 'needs-more-info';
        if (str_contains($value, 'pivot')) return 'pivot';
        if ($value === 'go') return 'go';
        return $value;
    }
}

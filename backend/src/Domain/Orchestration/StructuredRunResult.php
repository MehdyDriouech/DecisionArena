<?php
declare(strict_types=1);

namespace Domain\Orchestration;

use Domain\DecisionReliability\MemorySummaryBuilder;
use Domain\Vote\VoteTimelineReducer;

/**
 * Normalizes structured runner payloads: vote timeline, final votes, memory summary.
 */
final class StructuredRunResult {
    /**
     * @param array<string,mixed> $runnerReturn
     * @return array<string,mixed>
     */
    public static function augment(array $runnerReturn): array {
        $votes = $runnerReturn               ['votes'] ?? [];
        $votes = is_array($votes) ? $votes : [];
        $auto  = $runnerReturn['automatic_decision'] ?? null;

        $finalVotes = null;
        if (is_array($auto)
            && isset($auto['vote_summary']['final_votes'])
            && is_array($auto['vote_summary']['final_votes'])
        ) {
            $finalVotes = $auto['vote_summary']['final_votes'];
        }
        if (!is_array($finalVotes)) {
            $finalVotes = VoteTimelineReducer::latestValidVotesByAgent($votes);
        }

        $memory = $runnerReturn['memory_summary'] ?? null;
        if (!is_array($memory)) {
            $memory = MemorySummaryBuilder::buildMemorySummary(
                $runnerReturn['interaction_edges'] ?? [],
                $runnerReturn['positions'] ?? []
            );
        }

        $runnerReturn['vote_timeline']   = $votes;
        $runnerReturn['final_votes']     = $finalVotes;
        $runnerReturn['memory_summary']  = $memory;

        return $runnerReturn;
    }

    /**
     * Subset persisted in sessions.result JSON (no duplicate decision_brief — column stores it).
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function persistableResultSlice(array $result): array {
        return [
            'vote_timeline'          => $result['vote_timeline'] ?? ($result['votes'] ?? []),
            'final_votes'            => $result['final_votes'] ?? null,
            'memory_summary'         => $result['memory_summary'] ?? null,
            'raw_decision'           => $result['raw_decision'] ?? null,
            'adjusted_decision'      => $result['adjusted_decision'] ?? null,
            'false_consensus'        => $result['false_consensus'] ?? null,
            'guardrails'             => $result['guardrails'] ?? null,
            'decision_quality_score' => $result['decision_quality_score'] ?? null,
            'auto_retry'             => $result['auto_retry'] ?? null,
            'premortem_summary'      => $result['premortem_summary'] ?? null,
        ];
    }
}

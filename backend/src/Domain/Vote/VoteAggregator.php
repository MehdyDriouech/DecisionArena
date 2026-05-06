<?php
namespace Domain\Vote;

use Domain\DecisionReliability\ReliabilityConfig;
use Infrastructure\Persistence\PersonaDecisionDynamicsRepository;
use Infrastructure\Persistence\VoteRepository;

class VoteAggregator {
    private const VALID_VOTES = ['go', 'no-go', 'reduce-scope', 'needs-more-info', 'pivot'];

    private VoteRepository $voteRepo;
    private PersonaDecisionDynamicsRepository $dynamicsRepo;

    public function __construct(?VoteRepository $repo = null, ?PersonaDecisionDynamicsRepository $dynamicsRepo = null) {
        $this->voteRepo     = $repo ?? new VoteRepository();
        $this->dynamicsRepo = $dynamicsRepo ?? new PersonaDecisionDynamicsRepository();
    }

    public function recompute(string $sessionId, float $threshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD, ?string $presetId = null): ?array {
        $threshold = ReliabilityConfig::normalizeThreshold($threshold);
        $votes = $this->voteRepo->findVotesBySession($sessionId);
        if (empty($votes)) {
            return null;
        }

        $finalVotes = $this->latestValidVotesByAgent($votes);
        if (empty($finalVotes)) {
            return null;
        }

        $agg              = $this->aggregateReputationWeightedVotes($finalVotes, $presetId);
        $voteTotals       = $agg['vote_totals'];
        $totalWeight      = $agg['total_weight'];
        $perVoteWeighting = $agg['per_vote_weighting'];

        if ($totalWeight <= 0) {
            return null;
        }

        $scores = [];
        foreach ($voteTotals as $label => $weight) {
            $scores[$label] = $weight / $totalWeight;
        }
        arsort($scores);
        $winningLabel = array_key_first($scores) ?? 'no-consensus';
        $winningScore = (float)($scores[$winningLabel] ?? 0.0);

        $decisionLabel = $winningScore >= $threshold ? $winningLabel : 'no-consensus';
        $notes = [];

        if ($winningLabel === 'go' && $this->hasHighWeightNoGo($finalVotes)) {
            $decisionLabel = 'reduce-scope';
            $notes[] = 'Go was downgraded because a high-weight no-go objection exists.';
        }

        $needsMoreInfoScore = (float)($scores['needs-more-info'] ?? 0.0);
        if ($winningLabel === 'go' && $needsMoreInfoScore >= 0.35) {
            $decisionLabel = 'needs-more-info';
            $notes[] = 'Go was changed because needs-more-info weight is significant.';
        }

        $confidence = 'low';
        if ($winningScore >= 0.70) {
            $confidence = 'high';
        } elseif ($winningScore >= $threshold) {
            $confidence = 'medium';
        }

        $decision = [
            'id' => $this->uuid(),
            'session_id' => $sessionId,
            'decision_label' => $decisionLabel,
            'decision_score' => round($winningScore, 4),
            'confidence_level' => $confidence,
            'threshold_used' => $threshold,
            'vote_summary' => [
                'vote_totals' => $voteTotals,
                'decision_scores' => $scores,
                'total_weight' => round($totalWeight, 4),
                'winning_label' => $winningLabel,
                'notes' => $notes,
                'per_vote_weighting' => $perVoteWeighting,
                'aggregation_strategy' => 'latest_valid_vote_per_agent',
                'timeline_vote_count' => count($votes),
                'final_vote_count' => count($finalVotes),
                'ignored_timeline_vote_count' => max(0, count($votes) - count($finalVotes)),
                'final_votes' => $this->formatVotesForSummary($finalVotes),
            ],
            'created_at' => date('c'),
        ];

        return $this->voteRepo->replaceDecision($sessionId, $decision);
    }

    /**
     * @param array<int,array<string,mixed>> $votes
     * @return array{vote_totals:array<string,float>,total_weight:float,per_vote_weighting:list<array<string,mixed>>}
     */
    private function aggregateReputationWeightedVotes(array $votes, ?string $presetId = null): array {
        $voteTotals       = [];
        $totalWeight      = 0.0;
        $perVoteWeighting = [];

        foreach ($votes as $vote) {
            $label   = $vote['vote'] ?? '';
            $weight  = (float)($vote['weight_score'] ?? 0);
            $agentId = (string)($vote['agent_id'] ?? '');
            if ($label === '') {
                continue;
            }
            $rep   = $this->dynamicsRepo->resolveEffectiveForPersona($agentId, null, $presetId)['reputation'];
            $wVote = $weight * $rep;
            $voteTotals[$label] = ($voteTotals[$label] ?? 0.0) + $wVote;
            $totalWeight += $wVote;
            $perVoteWeighting[] = [
                'agent_id'               => $agentId,
                'vote'                   => $label,
                'weight_score'           => round($weight, 4),
                'applied_reputation'     => round($rep, 4),
                'weighted_contribution'  => round($wVote, 4),
            ];
        }

        return [
            'vote_totals'         => $voteTotals,
            'total_weight'        => $totalWeight,
            'per_vote_weighting' => $perVoteWeighting,
        ];
    }

    /**
     * Keep the full vote table as the timeline, but only use the latest valid vote
     * for each agent when computing the final decision.
     *
     * @param array<int,array<string,mixed>> $votes
     * @return array<int,array<string,mixed>>
     */
    public function latestValidVotesByAgent(array $votes): array {
        return VoteTimelineReducer::latestValidVotesByAgent($votes);
    }

    /**
     * Build a human-readable explanation of the automatic decision.
     */
    public function getDecisionExplanation(string $sessionId, float $threshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD, ?string $presetId = null): array {
        $threshold = ReliabilityConfig::normalizeThreshold($threshold);
        $votes    = $this->voteRepo->findVotesBySession($sessionId);
        $decision = $this->voteRepo->findDecisionBySession($sessionId);

        if (empty($votes)) {
            return [
                'decision'         => 'no-data',
                'score'            => 0.0,
                'confidence_level' => 'none',
                'threshold'        => $threshold,
                'votes'            => [],
                'overrides'        => [],
                'explanation'      => 'No votes found for this session.',
            ];
        }

        $finalVotes = $this->latestValidVotesByAgent($votes);
        if (empty($finalVotes)) {
            return [
                'decision'         => 'no-data',
                'score'            => 0.0,
                'confidence_level' => 'none',
                'threshold'        => $threshold,
                'votes'            => [],
                'effective_votes'  => [],
                'aggregation_strategy' => 'latest_valid_vote_per_agent',
                'overrides'        => [],
                'explanation'      => 'No valid agent votes found for this session.',
            ];
        }

        $agg         = $this->aggregateReputationWeightedVotes($finalVotes, $presetId);
        $voteTotals  = $agg['vote_totals'];
        $totalWeight = $agg['total_weight'];

        $scores = [];
        foreach ($voteTotals as $label => $w) {
            $scores[$label] = round($w / max(1.0, $totalWeight), 4);
        }
        arsort($scores);

        $winningLabel = array_key_first($scores) ?? 'no-consensus';
        $winningScore = (float)($scores[$winningLabel] ?? 0.0);
        $decisionLabel = $decision['decision_label']
            ?? ($winningScore >= $threshold ? $winningLabel : 'no-consensus');

        $overrides = [];
        if ($this->hasHighWeightNoGo($finalVotes)) {
            $overrides[] = 'high_weight_no_go';
        }
        $needsMoreInfoScore = (float)($scores['needs-more-info'] ?? 0.0);
        if ($winningLabel === 'go' && $needsMoreInfoScore >= 0.35) {
            $overrides[] = 'needs_more_info_significant';
        }

        $explanation = $this->buildExplanation(
            $winningLabel, $winningScore, $decisionLabel, $scores, $overrides, $finalVotes, $threshold, $agg['per_vote_weighting']
        );

        $formattedVotes = array_map(fn($v) => [
            'agent_id'           => $v['agent_id'] ?? '',
            'vote'               => $v['vote'] ?? '',
            'weight_score'       => round((float)($v['weight_score'] ?? 0), 2),
            'applied_reputation' => round(
                $this->dynamicsRepo->resolveEffectiveForPersona((string)($v['agent_id'] ?? ''), null, $presetId)['reputation'],
                2
            ),
            'rationale'          => $v['rationale'] ?? '',
        ], $votes);

        return [
            'decision'         => $decisionLabel,
            'score'            => $winningScore,
            'confidence_level' => $decision['confidence_level'] ?? 'low',
            'threshold'        => $threshold,
            'votes'            => $formattedVotes,
            'effective_votes'  => $this->formatVotesForSummary($finalVotes),
            'aggregation_strategy' => 'latest_valid_vote_per_agent',
            'overrides'        => $overrides,
            'explanation'      => $explanation,
        ];
    }

    private function buildExplanation(
        string $winning,
        float  $score,
        string $decision,
        array  $scores,
        array  $overrides,
        array  $votes,
        float  $threshold,
        array  $perVoteWeighting = []
    ): string {
        $pct          = (int) round($score * 100);
        $thresholdPct = (int) round($threshold * 100);

        $text  = "The most common vote was '{$winning}' with {$pct}% of weighted votes (weight × reputation). ";

        if ($winning !== $decision) {
            $text .= "The final decision was changed to '{$decision}'. ";
        }
        if (in_array('high_weight_no_go', $overrides, true)) {
            $text .= "A high-weight agent (score ≥ 8) voted 'no-go', downgrading the decision. ";
        }
        if (in_array('needs_more_info_significant', $overrides, true)) {
            $text .= "A significant portion of votes requested more information, overriding the 'go' outcome. ";
        }
        if ($score < $threshold) {
            $text .= "The winning score ({$pct}%) did not reach the consensus threshold ({$thresholdPct}%), so the result is 'no-consensus'. ";
        }

        if (!empty($perVoteWeighting)) {
            $ranked = $perVoteWeighting;
            usort($ranked, fn($a, $b) => (float)($b['weighted_contribution'] ?? 0) <=> (float)($a['weighted_contribution'] ?? 0));
            $topVoters = array_slice($ranked, 0, 3);
            $topNames  = array_map(
                fn($row) => sprintf(
                    '%s (%s, score×rep=%.2f)',
                    $row['agent_id'] ?? '',
                    $row['vote'] ?? '',
                    (float)($row['weighted_contribution'] ?? 0)
                ),
                $topVoters
            );
        } else {
            usort($votes, fn($a, $b) => (float)($b['weight_score'] ?? 0) <=> (float)($a['weight_score'] ?? 0));
            $topVoters = array_slice($votes, 0, 3);
            $topNames  = array_map(
                fn($v) => sprintf('%s (%s, w=%.1f)', $v['agent_id'], $v['vote'], (float)($v['weight_score'] ?? 0)),
                $topVoters
            );
        }
        $text .= 'Top contributors (reputation-weighted): ' . implode(', ', $topNames) . '.';

        return $text;
    }

    private function hasHighWeightNoGo(array $votes): bool {
        foreach ($votes as $vote) {
            if (($vote['vote'] ?? '') === 'no-go' && (float)($vote['weight_score'] ?? 0) >= 8.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $votes
     * @return array<int,array<string,mixed>>
     */
    private function formatVotesForSummary(array $votes): array {
        return array_map(fn($v) => [
            'id' => $v['id'] ?? null,
            'agent_id' => $v['agent_id'] ?? '',
            'round' => $v['round'] ?? null,
            'vote' => $v['vote'] ?? '',
            'confidence' => isset($v['confidence']) ? (int)$v['confidence'] : null,
            'impact' => isset($v['impact']) ? (int)$v['impact'] : null,
            'domain_weight' => isset($v['domain_weight']) ? (int)$v['domain_weight'] : null,
            'weight_score' => isset($v['weight_score']) ? round((float)$v['weight_score'], 4) : null,
            'rationale' => $v['rationale'] ?? '',
            'created_at' => $v['created_at'] ?? null,
        ], $votes);
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

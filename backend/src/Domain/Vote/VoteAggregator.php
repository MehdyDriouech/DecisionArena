<?php
namespace Domain\Vote;

use Infrastructure\Persistence\VoteRepository;

class VoteAggregator {
    private VoteRepository $repo;

    public function __construct(?VoteRepository $repo = null) {
        $this->repo = $repo ?? new VoteRepository();
    }

    public function recompute(string $sessionId, float $threshold = 0.55): ?array {
        $votes = $this->repo->findVotesBySession($sessionId);
        if (empty($votes)) {
            return null;
        }

        $voteTotals = [];
        $totalWeight = 0.0;
        foreach ($votes as $vote) {
            $label = $vote['vote'] ?? '';
            $weight = (float)($vote['weight_score'] ?? 0);
            if ($label === '') continue;
            $voteTotals[$label] = ($voteTotals[$label] ?? 0.0) + $weight;
            $totalWeight += $weight;
        }
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

        if ($winningLabel === 'go' && $this->hasHighWeightNoGo($votes)) {
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
            ],
            'created_at' => date('c'),
        ];

        return $this->repo->replaceDecision($sessionId, $decision);
    }

    /**
     * Build a human-readable explanation of the automatic decision.
     */
    public function getDecisionExplanation(string $sessionId, float $threshold = 0.55): array {
        $votes    = $this->repo->findVotesBySession($sessionId);
        $decision = $this->repo->findDecisionBySession($sessionId);

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

        $voteTotals  = [];
        $totalWeight = 0.0;
        foreach ($votes as $vote) {
            $label  = $vote['vote'] ?? '';
            $weight = (float)($vote['weight_score'] ?? 0);
            if ($label === '') continue;
            $voteTotals[$label] = ($voteTotals[$label] ?? 0.0) + $weight;
            $totalWeight += $weight;
        }

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
        if ($this->hasHighWeightNoGo($votes)) {
            $overrides[] = 'high_weight_no_go';
        }
        $needsMoreInfoScore = (float)($scores['needs-more-info'] ?? 0.0);
        if ($winningLabel === 'go' && $needsMoreInfoScore >= 0.35) {
            $overrides[] = 'needs_more_info_significant';
        }

        $explanation = $this->buildExplanation(
            $winningLabel, $winningScore, $decisionLabel, $scores, $overrides, $votes, $threshold
        );

        $formattedVotes = array_map(fn($v) => [
            'agent_id'     => $v['agent_id']     ?? '',
            'vote'         => $v['vote']          ?? '',
            'weight_score' => round((float)($v['weight_score'] ?? 0), 2),
            'rationale'    => $v['rationale']     ?? '',
        ], $votes);

        return [
            'decision'         => $decisionLabel,
            'score'            => $winningScore,
            'confidence_level' => $decision['confidence_level'] ?? 'low',
            'threshold'        => $threshold,
            'votes'            => $formattedVotes,
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
        float  $threshold
    ): string {
        $pct          = (int) round($score * 100);
        $thresholdPct = (int) round($threshold * 100);

        $text  = "The most common vote was '{$winning}' with {$pct}% of weighted votes. ";

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

        usort($votes, fn($a, $b) => (float)($b['weight_score'] ?? 0) <=> (float)($a['weight_score'] ?? 0));
        $topVoters = array_slice($votes, 0, 3);
        $topNames  = array_map(
            fn($v) => sprintf('%s (%s, w=%.1f)', $v['agent_id'], $v['vote'], (float)($v['weight_score'] ?? 0)),
            $topVoters
        );
        $text .= 'Top contributors: ' . implode(', ', $topNames) . '.';

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

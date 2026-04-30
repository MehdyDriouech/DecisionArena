<?php
namespace Domain\DecisionReliability;

class FalseConsensusDetector {
    /**
     * @param array<string,mixed> $contextQuality
     * @param array<int,array> $positions
     * @param array<int,array> $edges
     * @param array<int,array> $votes
     * @param ?array<string,mixed> $rawDecision
     * @param ?array<string,mixed> $timeline
     * @param ?array<int,array> $personaScores
     * @param ?array<string,mixed> $biasReport
     * @return array{false_consensus_risk:string, signals:array<int,array>, recommendations:array<int,string>, diversity_score:float, lexical_uniformity:float, explicit_disagreement_observed:bool}
     */
    public function detect(
        array $contextQuality,
        array $positions,
        array $edges,
        array $votes,
        ?array $rawDecision = null,
        ?array $timeline = null,
        ?array $personaScores = null,
        ?array $biasReport = null
    ): array {
        $signals = [];
        $recommendations = [];
        $riskScore = 0;

        $diversityScore = $this->computeDiversityScore($votes, $positions);
        $lexicalUniformity = $this->computeLexicalUniformity($votes, $positions);
        $explicitDisagreement = $this->detectExplicitDisagreement($votes, $positions);

        if ($diversityScore < 0.30 && count($votes) >= 3) {
            $signals[] = [
                'type' => 'low_argument_diversity',
                'severity' => 'high',
                'message' => 'Agent rationales show low distinctness; arguments may be superficially aligned.',
            ];
            $riskScore += 3;
            $recommendations[] = 'Require each agent to cite a distinct risk or counter-argument before closing.';
        }

        if ($lexicalUniformity >= 0.72 && count($votes) >= 3) {
            $signals[] = [
                'type' => 'lexical_uniformity',
                'severity' => 'medium',
                'message' => 'High lexical overlap across agent rationales — possible echo-chamber phrasing.',
            ];
            $riskScore += 2;
            $recommendations[] = 'Ask agents to rewrite positions without reusing the same opening phrases.';
        }

        if (!$explicitDisagreement && count($votes) >= 3 && count($edges) >= 2) {
            $signals[] = [
                'type' => 'no_explicit_disagreement',
                'severity' => 'medium',
                'message' => 'No explicit disagreement language detected in votes or latest stances.',
            ];
            $riskScore += 1;
            $recommendations[] = 'Prompt for explicit dissent or strongest counter-case before voting.';
        }

        $roundConsensus = $this->detectEarlyConsensusFromPositions($positions);
        if ($roundConsensus !== null) {
            $severity = ($contextQuality['level'] ?? 'strong') === 'weak' ? 'high' : 'medium';
            $signals[] = [
                'type' => 'early_consensus_weak_context',
                'severity' => $severity,
                'message' => "Consensus formed by round {$roundConsensus} while context quality is {$contextQuality['level']}.",
            ];
            $riskScore += $severity === 'high' ? 3 : 2;
            $recommendations[] = 'Extend debate rounds and require explicit contradictory evidence.';
        }

        $challengeRatio = $this->challengeRatio($edges);
        if ($challengeRatio < 0.20 && count($edges) >= 2) {
            $signals[] = [
                'type' => 'low_contradiction',
                'severity' => 'medium',
                'message' => 'Debate graph shows low contradiction density between agents.',
            ];
            $riskScore += 2;
            $recommendations[] = 'Increase forced disagreement and assign explicit challenge targets.';
        }

        $dominant = $this->detectDominantAgent($positions, $personaScores);
        if ($dominant !== null) {
            $signals[] = [
                'type' => 'dominant_agent',
                'severity' => $dominant['severity'],
                'message' => $dominant['message'],
            ];
            $riskScore += $dominant['severity'] === 'high' ? 3 : 1;
            $recommendations[] = 'Rebalance influence by adding critical personas or reducing dominant weighting.';
        }

        $passive = $this->detectPassiveAgents($personaScores);
        if ($passive !== null) {
            $signals[] = [
                'type' => 'passive_agents',
                'severity' => 'medium',
                'message' => $passive,
            ];
            $riskScore += 2;
            $recommendations[] = 'Revise passive persona prompts and enforce contribution constraints.';
        }

        $decisionScore = (float)($rawDecision['decision_score'] ?? 0.0);
        $label = strtolower((string)($rawDecision['decision_label'] ?? ''));
        if (($contextQuality['level'] ?? 'strong') === 'weak' && $decisionScore >= 0.65 && $label !== 'no-consensus') {
            $signals[] = [
                'type' => 'weak_context_strong_consensus',
                'severity' => 'high',
                'message' => 'Strong consensus reached despite weak context quality.',
            ];
            $riskScore += 3;
            $recommendations[] = 'Collect missing context before final commit.';
        }

        if ($this->detectLateFlip($positions)) {
            $signals[] = [
                'type' => 'late_flip',
                'severity' => 'medium',
                'message' => 'Late-round position flip detected, indicating unstable consensus.',
            ];
            $riskScore += 2;
            $recommendations[] = 'Run one additional consolidation round with explicit evidence checks.';
        }

        if (!empty($timeline['late_consensus'])) {
            $signals[] = [
                'type' => 'late_consensus',
                'severity' => 'medium',
                'message' => 'Consensus was reached late in the timeline.',
            ];
            $riskScore += 1;
        }

        if (!empty($biasReport['detected']) && is_array($biasReport['detected'])) {
            foreach ($biasReport['detected'] as $bias) {
                $severity = strtolower((string)($bias['severity'] ?? 'low'));
                if ($severity === 'high') {
                    $riskScore += 2;
                } elseif ($severity === 'medium') {
                    $riskScore += 1;
                }
            }
        }

        $risk = 'low';
        if ($riskScore >= 7) {
            $risk = 'high';
        } elseif ($riskScore >= 4) {
            $risk = 'medium';
        }

        return [
            'false_consensus_risk' => $risk,
            'signals' => $signals,
            'recommendations' => array_values(array_unique($recommendations)),
            'diversity_score' => $diversityScore,
            'lexical_uniformity' => $lexicalUniformity,
            'explicit_disagreement_observed' => $explicitDisagreement,
        ];
    }

    /**
     * @param array<int,array> $votes
     * @param array<int,array> $positions
     * @return float 0..1 higher = more diverse rationales
     */
    private function computeDiversityScore(array $votes, array $positions): float {
        $sets = [];
        foreach ($votes as $v) {
            $t = trim((string)($v['rationale'] ?? ''));
            if ($t !== '' && ($v['agent_id'] ?? '') !== 'devil_advocate') {
                $sets[] = $this->wordSet($t);
            }
        }
        if (count($sets) < 2) {
            return 0.55;
        }
        $n = count($sets);
        $simSum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $simSum += $this->jaccard($sets[$i], $sets[$j]);
                $pairs++;
            }
        }
        $avgSim = $pairs > 0 ? $simSum / $pairs : 0.5;
        return round(max(0.0, min(1.0, 1.0 - $avgSim)), 2);
    }

    /**
     * @param array<int,array> $votes
     * @param array<int,array> $positions
     * @return float 0..1 higher = more uniform (bad)
     */
    private function computeLexicalUniformity(array $votes, array $positions): float {
        $sets = [];
        foreach ($votes as $v) {
            $t = trim((string)($v['rationale'] ?? ''));
            if ($t !== '' && ($v['agent_id'] ?? '') !== 'devil_advocate') {
                $sets[] = $this->wordSet($t);
            }
        }
        if (count($sets) < 2) {
            $texts = [];
            foreach ($positions as $p) {
                $agent = (string)($p['agent_id'] ?? '');
                if ($agent === '' || $agent === 'devil_advocate') {
                    continue;
                }
                $st = trim((string)($p['stance'] ?? ''));
                if ($st !== '') {
                    $texts[] = $this->wordSet($st);
                }
            }
            $sets = $texts;
        }
        if (count($sets) < 2) {
            return 0.35;
        }
        $n = count($sets);
        $simSum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $simSum += $this->jaccard($sets[$i], $sets[$j]);
                $pairs++;
            }
        }
        return round($pairs > 0 ? $simSum / $pairs : 0.0, 2);
    }

    /**
     * @param array<int,array> $votes
     * @param array<int,array> $positions
     */
    private function detectExplicitDisagreement(array $votes, array $positions): bool {
        $patterns = [
            '/\bdisagree\b/i',
            '/\bdo not agree\b/i',
            '/\bdon\'t agree\b/i',
            '/\bnot agree\b/i',
            '/\bi disagree\b/i',
            '/\bstrongly oppose\b/i',
            '/\bje ne suis pas d\'accord\b/iu',
            '/\bpas d\'accord\b/iu',
            '/\bfaudrait nuancer\b/iu',
            '/\bcontre\b/iu',
            '/\ben désaccord\b/iu',
        ];
        foreach ($votes as $v) {
            $t = (string)($v['rationale'] ?? '');
            foreach ($patterns as $p) {
                if (preg_match($p, $t) === 1) {
                    return true;
                }
            }
        }
        $latestByAgent = [];
        foreach ($positions as $p) {
            $agent = (string)($p['agent_id'] ?? '');
            if ($agent === '' || $agent === 'devil_advocate') {
                continue;
            }
            $round = (int)($p['round'] ?? 0);
            if (!isset($latestByAgent[$agent]) || $round >= (int)($latestByAgent[$agent]['round'] ?? 0)) {
                $latestByAgent[$agent] = $p;
            }
        }
        foreach ($latestByAgent as $p) {
            $st = (string)($p['stance'] ?? '');
            foreach ($patterns as $patt) {
                if (preg_match($patt, $st) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,true> $a @param array<string,true> $b */
    private function jaccard(array $a, array $b): float {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        $inter = 0;
        foreach ($a as $w => $_) {
            if (isset($b[$w])) {
                $inter++;
            }
        }
        $union = count($a) + count($b) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /** @return array<string,true> */
    private function wordSet(string $text): array {
        $norm = mb_strtolower($text, 'UTF-8');
        $norm = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $norm) ?? '';
        $parts = preg_split('/\s+/u', trim($norm)) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'but', 'not', 'you', 'all', 'can', 'our', 'les', 'des', 'une', 'pour', 'dans', 'sur', 'est', 'pas', 'que', 'qui'];
        $set = [];
        foreach ($parts as $w) {
            if ($w === '' || mb_strlen($w, 'UTF-8') < 3) {
                continue;
            }
            if (in_array($w, $stop, true)) {
                continue;
            }
            $set[$w] = true;
        }
        return $set;
    }

    private function detectEarlyConsensusFromPositions(array $positions): ?int {
        if (empty($positions)) {
            return null;
        }
        $byRound = [];
        foreach ($positions as $p) {
            $round = (int)($p['round'] ?? 0);
            $agent = (string)($p['agent_id'] ?? '');
            $stance = strtolower((string)($p['stance'] ?? ''));
            if ($round <= 0 || $agent === '' || $agent === 'devil_advocate' || $stance === '') {
                continue;
            }
            $byRound[$round][$agent] = $stance;
        }
        if (empty($byRound)) {
            return null;
        }
        ksort($byRound);
        foreach ($byRound as $round => $agentStances) {
            if (count($agentStances) < 3) {
                continue;
            }
            if (count(array_unique(array_values($agentStances))) === 1 && $round <= 2) {
                return (int)$round;
            }
        }
        return null;
    }

    private function challengeRatio(array $edges): float {
        if (empty($edges)) {
            return 0.0;
        }
        $ch = 0;
        foreach ($edges as $e) {
            if (($e['edge_type'] ?? '') === 'challenge') {
                $ch++;
            }
        }
        return $ch / max(1, count($edges));
    }

    private function detectDominantAgent(array $positions, ?array $personaScores): ?array {
        if (is_array($personaScores) && !empty($personaScores)) {
            $top = $personaScores[0];
            $score = (float)($top['influence_score'] ?? 0.0);
            if ($score >= 0.70) {
                return [
                    'severity' => 'high',
                    'message' => "Agent {$top['agent_id']} dominates influence score ({$score}).",
                ];
            }
            if ($score >= 0.55) {
                return [
                    'severity' => 'medium',
                    'message' => "Agent {$top['agent_id']} has outsized influence ({$score}).",
                ];
            }
        }

        if (empty($positions)) {
            return null;
        }
        $latestByAgent = [];
        foreach ($positions as $p) {
            $agent = (string)($p['agent_id'] ?? '');
            if ($agent === '' || $agent === 'devil_advocate') {
                continue;
            }
            if (!isset($latestByAgent[$agent]) || (int)$p['round'] >= (int)$latestByAgent[$agent]['round']) {
                $latestByAgent[$agent] = $p;
            }
        }
        if (count($latestByAgent) < 2) {
            return null;
        }
        $weights = [];
        foreach ($latestByAgent as $agent => $p) {
            $weights[$agent] = (float)($p['weight_score'] ?? 0.0);
        }
        arsort($weights);
        $topAgent = array_key_first($weights);
        $top = (float)($weights[$topAgent] ?? 0.0);
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return null;
        }
        $share = $top / $sum;
        if ($share >= 0.50) {
            return ['severity' => 'high', 'message' => "Agent {$topAgent} contributes {$share} of weighted influence."];
        }
        if ($share >= 0.40) {
            return ['severity' => 'medium', 'message' => "Agent {$topAgent} concentrates {$share} of weighted influence."];
        }
        return null;
    }

    private function detectPassiveAgents(?array $personaScores): ?string {
        if (!is_array($personaScores) || empty($personaScores)) {
            return null;
        }
        $total = count($personaScores);
        $passive = count(array_filter($personaScores, fn($s) => ($s['dominance'] ?? '') === 'passive'));
        if ($total === 0) {
            return null;
        }
        $ratio = $passive / $total;
        if ($ratio >= 0.40) {
            return "{$passive}/{$total} agents are passive contributors.";
        }
        return null;
    }

    private function detectLateFlip(array $positions): bool {
        if (empty($positions)) {
            return false;
        }
        $latestRound = 0;
        foreach ($positions as $p) {
            $latestRound = max($latestRound, (int)($p['round'] ?? 0));
        }
        if ($latestRound < 2) {
            return false;
        }

        $penultimate = [];
        $final = [];
        foreach ($positions as $p) {
            $agent = (string)($p['agent_id'] ?? '');
            if ($agent === '' || $agent === 'devil_advocate') {
                continue;
            }
            $round = (int)($p['round'] ?? 0);
            if ($round === $latestRound - 1) {
                $penultimate[$agent] = strtolower((string)($p['stance'] ?? ''));
            } elseif ($round === $latestRound) {
                $final[$agent] = strtolower((string)($p['stance'] ?? ''));
            }
        }
        $flipCount = 0;
        foreach ($final as $agent => $stance) {
            if (!isset($penultimate[$agent])) {
                continue;
            }
            if ($stance !== '' && $penultimate[$agent] !== '' && $stance !== $penultimate[$agent]) {
                $flipCount++;
            }
        }
        return $flipCount >= 2;
    }
}

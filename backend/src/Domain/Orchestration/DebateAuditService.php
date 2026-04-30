<?php
namespace Domain\Orchestration;

/**
 * DebateAuditService — heuristic scoring of multi-agent debate quality.
 *
 * All metrics return a value in [0, 10].
 * The global score is in [0, 100].
 */
class DebateAuditService {

    public function audit(
        array $messages,
        array $edges     = [],
        array $positions = [],
        array $arguments = []
    ): array {
        $density     = $this->computeInteractionDensity($messages, $edges);
        $reuse       = $this->computeArgumentReuse($arguments);
        $disagreement = $this->computeDisagreementQuality($edges);
        $evolution   = $this->computePositionEvolution($positions);
        $redundancy  = $this->computeRedundancy($messages);

        // Score: average of positive metrics — redundancy penalises
        $positive = ($density + $reuse + $disagreement + $evolution) / 4;
        $penalty  = $redundancy / 10; // 0-1
        $score    = (int) round($positive * 10 * (1 - $penalty * 0.3));
        $score    = max(0, min(100, $score));

        $warnings = $this->buildWarnings($density, $reuse, $disagreement, $evolution, $redundancy);
        $summary  = $this->buildSummary($score);

        return [
            'score'   => $score,
            'metrics' => [
                'interaction_density'  => $density,
                'argument_reuse'       => $reuse,
                'disagreement_quality' => $disagreement,
                'position_evolution'   => $evolution,
                'redundancy'           => $redundancy,
            ],
            'summary'  => $summary,
            'warnings' => $warnings,
        ];
    }

    // ── Metric: ratio of agent messages that have an incoming/outgoing edge ──

    private function computeInteractionDensity(array $messages, array $edges): float {
        $agentMsgs = array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'user');
        $total = count($agentMsgs);
        if ($total === 0) return 0.0;

        // Each edge represents a cross-agent reference
        $edgeCount = count($edges);
        $ratio = $edgeCount / $total;
        return (float) round(min(10.0, $ratio * 10), 1);
    }

    // ── Metric: proportion of arguments that target another argument ──

    private function computeArgumentReuse(array $arguments): float {
        if (empty($arguments)) return 5.0; // neutral when no data

        $challenged = array_filter($arguments, fn($a) => !empty($a['target_argument_id']));
        $ratio = count($challenged) / count($arguments);
        // Base 2 + reuse bonus up to 8
        return (float) round(min(10.0, 2 + $ratio * 8), 1);
    }

    // ── Metric: count of "challenge" or "contradict" typed edges ──

    private function computeDisagreementQuality(array $edges): float {
        if (empty($edges)) return 0.0;

        $challengeTypes = ['challenge', 'contradict', 'counter'];
        $count = 0;
        foreach ($edges as $edge) {
            if (in_array($edge['edge_type'] ?? '', $challengeTypes, true)) {
                $count++;
            }
        }
        // 5+ challenge edges → score 10
        return (float) round(min(10.0, $count * 2), 1);
    }

    // ── Metric: detect change in stance or confidence across rounds ──

    private function computePositionEvolution(array $positions): float {
        if (empty($positions)) return 5.0;

        $byAgent = [];
        foreach ($positions as $pos) {
            $aid = $pos['agent_id'] ?? 'unknown';
            $byAgent[$aid][] = $pos;
        }

        $evolved = 0;
        $total   = count($byAgent);
        foreach ($byAgent as $agentPositions) {
            if (count($agentPositions) < 2) continue;
            usort($agentPositions, fn($a, $b) => (int)($a['round'] ?? 0) - (int)($b['round'] ?? 0));
            $first = $agentPositions[0];
            $last  = end($agentPositions);

            $stanceChanged     = ($first['stance'] ?? '') !== ($last['stance'] ?? '');
            $confidenceShifted = abs((float)($first['confidence'] ?? 0) - (float)($last['confidence'] ?? 0)) >= 1.5;
            $hasChangeNote     = !empty($last['change_since_last_round']);

            if ($stanceChanged || $confidenceShifted || $hasChangeNote) {
                $evolved++;
            }
        }

        if ($total === 0) return 0.0;
        return (float) round(($evolved / $total) * 10, 1);
    }

    // ── Metric: ratio of duplicate message content ──

    private function computeRedundancy(array $messages): float {
        $agentMsgs = array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'user');
        $fingerprints = array_map(
            fn($m) => strtolower(substr(trim($m['content'] ?? ''), 0, 150)),
            array_values($agentMsgs)
        );

        $total  = count($fingerprints);
        if ($total === 0) return 0.0;

        $unique = count(array_unique($fingerprints));
        $ratio  = 1 - ($unique / $total);
        return (float) round($ratio * 10, 1);
    }

    // ── Warnings ──

    private function buildWarnings(
        float $density,
        float $reuse,
        float $disagreement,
        float $evolution,
        float $redundancy
    ): array {
        $warnings = [];

        if ($density < 2.0) {
            $warnings[] = 'Low interaction density — agents produced mostly independent answers without referencing each other.';
        }
        if ($disagreement < 2.0) {
            $warnings[] = 'Low disagreement quality — few genuine challenges or counter-arguments were recorded.';
        }
        if ($evolution < 2.0) {
            $warnings[] = 'Positions rarely evolved — agents may not have updated their stance based on peer arguments.';
        }
        if ($redundancy > 6.0) {
            $warnings[] = 'High redundancy — many messages share similar content, suggesting repetitive reasoning.';
        }
        if ($reuse < 3.0) {
            $warnings[] = 'Low argument reuse — arguments were rarely challenged or built upon by other agents.';
        }

        return $warnings;
    }

    // ── Summary text ──

    private function buildSummary(int $score): string {
        if ($score >= 75) {
            return 'Strong debate quality. Agents actively engaged with each other, challenged positions, and evolved their reasoning.';
        }
        if ($score >= 50) {
            return 'Moderate debate quality. Some cross-agent interaction occurred, but deeper engagement is possible.';
        }
        if ($score >= 25) {
            return 'Weak debate quality. Agents produced mostly parallel answers with limited genuine interaction.';
        }
        return 'Very low debate quality. Agents did not meaningfully interact or challenge each other\'s positions.';
    }
}

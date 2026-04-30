<?php
namespace Domain\Orchestration;

/**
 * Heuristic decision synthesis from existing session data (no LLM).
 */
class DecisionSummaryService {

    /**
     * @param array<string,mixed> $session
     * @param ?array<string,mixed> $verdict
     * @param ?array<string,mixed> $decision
     * @param array<int,array> $votes
     * @param array<int,array> $arguments
     * @param array<int,array> $highlights from DebateHighlightService
     * @return array<string,mixed>
     */
    public function build(
        array $session,
        ?array $verdict,
        ?array $decision,
        array $votes,
        array $arguments,
        array $highlights = []
    ): array {
        $labelRaw = null;
        if ($verdict && !empty($verdict['verdict_label'])) {
            $labelRaw = (string)$verdict['verdict_label'];
        } elseif ($decision && !empty($decision['decision_label'])) {
            $labelRaw = (string)$decision['decision_label'];
        }

        $tri = $this->mapTriState($labelRaw);

        $confidence = $this->inferConfidence($verdict, $decision, $votes);
        $confLabel  = $this->confidenceLabel($confidence);

        $summary = $this->buildSummaryText($tri, $verdict, $decision, $session);

        $keyFactors = $this->keyFactors($arguments, $votes, $verdict);
        $risks      = $this->risks($arguments, $verdict);
        $disagree   = $this->disagreements($arguments, $votes);

        return [
            'decision'           => $tri,
            'confidence'         => $confidence,
            'confidence_label'   => $confLabel,
            'summary'            => $summary,
            'key_factors'        => $keyFactors,
            'risks'              => $risks,
            'disagreements'      => $disagree,
            'source_label'       => $labelRaw,
            'highlights'         => $highlights,
        ];
    }

    private function mapTriState(?string $label): string {
        if (!$label) {
            return 'ITERATE';
        }
        $l = strtolower(trim($label));
        if (in_array($l, ['go', 'ship', 'approve'], true)) {
            return 'GO';
        }
        if (in_array($l, ['no-go', 'no_go', 'reject', 'stop'], true) || str_contains($l, 'no-go')) {
            return 'NO-GO';
        }
        return 'ITERATE';
    }

    private function inferConfidence(?array $verdict, ?array $decision, array $votes): float {
        if ($verdict && isset($verdict['confidence_score'])) {
            $c = (float)$verdict['confidence_score'];
            if ($c > 1.5) {
                return max(0.0, min(1.0, $c / 10));
            }
            return max(0.0, min(1.0, $c));
        }
        if ($decision && isset($decision['decision_score'])) {
            return max(0.0, min(1.0, (float)$decision['decision_score']));
        }
        if ($votes !== [] && $decision) {
            $vs = (float)($decision['decision_score'] ?? 0.55);
            return $vs;
        }
        return 0.45;
    }

    private function confidenceLabel(float $c): string {
        if ($c >= 0.72) {
            return 'high';
        }
        if ($c >= 0.48) {
            return 'medium';
        }
        return 'low';
    }

    private function buildSummaryText(string $tri, ?array $verdict, ?array $decision, array $session): string {
        $parts = [];
        if ($verdict && !empty($verdict['verdict_summary'])) {
            $parts[] = trim((string)$verdict['verdict_summary']);
        }
        if ($decision && !empty($decision['confidence_level'])) {
            $parts[] = 'Consensus signal: ' . (string)$decision['confidence_level'] . '.';
        }
        if ($parts === []) {
            $title = $session['title'] ?? 'Session';
            $parts[] = $tri === 'GO'
                ? "Recommendation leans toward proceeding ({$title}). Review key factors below before committing."
                : ($tri === 'NO-GO'
                    ? "Recommendation leans toward not proceeding as framed. Review risks and disagreements."
                    : "The record supports further iteration—consolidate evidence and narrow the decision.");
        }
        $text = implode(' ', $parts);
        $lines = preg_split('/\n+/', $text) ?: [$text];
        $lines = array_values(array_filter(array_map('trim', $lines)));
        return implode("\n", array_slice($lines, 0, 5));
    }

    /** @return list<string> */
    private function keyFactors(array $arguments, array $votes, ?array $verdict): array {
        $out = [];
        if ($verdict && !empty($verdict['recommended_action'])) {
            $out[] = (string)$verdict['recommended_action'];
        }
        $claims = array_values(array_filter($arguments, fn($a) => ($a['argument_type'] ?? '') === 'claim'));
        foreach (array_slice($claims, 0, 4) as $a) {
            $t = trim((string)($a['argument_text'] ?? ''));
            if ($t !== '') {
                $out[] = strlen($t) > 120 ? substr($t, 0, 117) . '…' : $t;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        foreach (array_slice($votes, 0, 2) as $v) {
            $r = trim((string)($v['rationale'] ?? ''));
            if ($r !== '') {
                $out[] = strlen($r) > 100 ? substr($r, 0, 97) . '…' : $r;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    /** @return list<string> */
    private function risks(array $arguments, ?array $verdict): array {
        $out = [];
        foreach ($arguments as $a) {
            if (($a['argument_type'] ?? '') !== 'risk') {
                continue;
            }
            $t = trim((string)($a['argument_text'] ?? ''));
            if ($t !== '') {
                $out[] = strlen($t) > 110 ? substr($t, 0, 107) . '…' : $t;
            }
        }
        if (isset($verdict['risk_score']) && (float)$verdict['risk_score'] >= 6.0 &&
            !str_contains(strtolower((string)($verdict['verdict_label'] ?? '')), 'go')) {
            $out[] = 'Aggregate technical / delivery risk scored high in verdict.';
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    /** @return list<string> */
    private function disagreements(array $arguments, array $votes): array {
        $out = [];
        foreach ($arguments as $a) {
            if (($a['argument_type'] ?? '') === 'counter_argument' || !empty($a['target_argument_id'])) {
                $t = trim((string)($a['argument_text'] ?? ''));
                if ($t !== '') {
                    $out[] = strlen($t) > 100 ? substr($t, 0, 97) . '…' : $t;
                }
            }
        }
        $labels = [];
        foreach ($votes as $v) {
            $labels[] = strtolower((string)($v['vote'] ?? ''));
        }
        $uniq = array_unique($labels);
        if (count($uniq) >= 2) {
            $out[] = 'Split votes: ' . implode(' vs ', array_slice($uniq, 0, 3)) . '.';
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }
}

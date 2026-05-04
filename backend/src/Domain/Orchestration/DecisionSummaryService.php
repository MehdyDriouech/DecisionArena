<?php
namespace Domain\Orchestration;

use Domain\DecisionReliability\ReliabilityConfig;

/**
 * Decision brief / trade-offs: LLM appendix (see TradeoffsJsonExtractor) + heuristic fallbacks.
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
        } elseif ($decision) {
            $labelRaw = (string)(
                $decision['legacy_decision_label']
                ?? $decision['ui_decision_label']
                ?? $decision['decision_label']
                ?? ''
            );
            if ($labelRaw === '') {
                $labelRaw = null;
            }
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
        if (in_array($l, ['go', 'go_fragile', 'ship', 'approve'], true)) {
            return 'GO';
        }
        if (in_array($l, ['no-go', 'no_go', 'no_go_fragile', 'reject', 'stop'], true) || str_contains($l, 'no-go')) {
            return 'NO-GO';
        }
        if ($l === 'no_consensus') {
            return 'ITERATE';
        }
        if ($l === 'insufficient_context') {
            return 'ITERATE';
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
            $vs = (float)($decision['decision_score'] ?? ReliabilityConfig::DEFAULT_DECISION_THRESHOLD);
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

    public function buildDecisionBrief(array $runResult): array
    {
        $adj      = $runResult['adjusted_decision'] ?? [];
        $guardrails = $runResult['guardrails'] ?? [];
        $qScore   = $runResult['decision_quality_score'] ?? [];
        $synthOut = $runResult['synthesizer_output'] ?? '';
        $risk     = $runResult['risk_profile'] ?? null;
        $relSum   = $runResult['decision_reliability_summary'] ?? [];

        $decisionMap = [
            'go'                   => 'GO',
            'no-go'                => 'NO-GO',
            'no_go'                => 'NO-GO',
            'iterate'              => 'ITERATE',
            'no-consensus'         => 'NO_CONSENSUS',
            'no_consensus'         => 'NO_CONSENSUS',
            'insufficient_context' => 'INSUFFICIENT_CONTEXT',
        ];
        $rawLabel = strtolower($adj['decision_label'] ?? 'no_consensus');
        $decision = $decisionMap[$rawLabel] ?? 'NO_CONSENSUS';

        $confidence  = strtoupper($adj['confidence_level'] ?? 'LOW');
        $reliability = $adj['decision_status'] ?? 'FRAGILE';

        $why      = $this->parseSynthesizerSection($synthOut, 'Why');
        $risks    = $this->parseSynthesizerSection($synthOut, 'Main Risks');
        $nextStep = $this->parseSynthesizerNextStep($synthOut);

        if (empty($why)) {
            $why = array_slice(
                array_map(fn($w) => $w['text'] ?? '', $runResult['key_factors'] ?? []),
                0, 3
            );
        }
        if (empty($risks)) {
            $topRisks = $risk['top_risks'] ?? [];
            $risks    = array_slice(array_map(fn($r) => $r['description'] ?? $r, $topRisks), 0, 3);
        }
        if (empty($nextStep)) {
            $nextStep = $relSum['recommended_action'] ?? ($guardrails['recommended_action'] ?? '');
        }

        $primaryWarning = $guardrails['warnings'][0] ?? ($runResult['reliability_warnings'][0] ?? '');

        if (!empty(($runResult['context_quality'] ?? [])['context_truncated'])) {
            $t = 'Context document was truncated for model prompts; treat conclusions as lower confidence.';
            $primaryWarning = $primaryWarning !== '' ? ($t . ' ' . $primaryWarning) : $t;
        }

        $evidenceReport = $runResult['evidence_report'] ?? null;
        $evidenceWarnings = [];
        if (is_array($evidenceReport)) {
            $hiU = (int)($evidenceReport['high_importance_unsupported_count'] ?? 0);
            $hiC = (int)($evidenceReport['high_importance_contradicted_count'] ?? 0);
            $con = (int)($evidenceReport['contradicted_claims_count'] ?? 0);
            $dens = (float)($evidenceReport['evidence_density'] ?? 1.0);
            if ($hiC > 0 || $con > 0) {
                $evidenceWarnings[] = 'Some claims contradict the shared context or retrieved evidence.';
            }
            if ($hiU > 0) {
                $evidenceWarnings[] = 'Some important claims are unsupported by the context document.';
            }
            if ($dens < 0.35 && ($evidenceReport['total_claims'] ?? 0) >= 3) {
                $evidenceWarnings[] = 'Evidence density for important claims is low — conclusions should be cautious.';
            }
            $chN = (int)($evidenceReport['challenged_claims_count'] ?? 0);
            if ($chN > 0) {
                $evidenceWarnings[] = 'Some claims have been challenged by the user (subjective contest — machine support classification unchanged).';
                $evidenceWarnings[] = 'Confidence may be reduced due to contested evidence.';
            }
        }

        if ($evidenceWarnings !== []) {
            $ewText = implode(' ', $evidenceWarnings);
            $primaryWarning = $primaryWarning !== '' ? ($ewText . ' ' . $primaryWarning) : $ewText;
        }

        $brief = [
            'decision'        => $decision,
            'confidence'      => $confidence,
            'reliability'     => $reliability,
            'why'             => array_values(array_filter($why)),
            'risks'           => array_values(array_filter($risks)),
            'next_step'       => $nextStep,
            'primary_warning' => $primaryWarning,
            'quality_score'   => $qScore['decision_quality_score'] ?? 0,
            'quality_level'   => $qScore['level'] ?? 'poor',
            'evidence_badge'       => is_array($evidenceReport) ? ($evidenceReport['evidence_badge'] ?? null) : null,
            'evidence_density'     => is_array($evidenceReport) ? ($evidenceReport['evidence_density'] ?? null) : null,
            'evidence_metrics'     => is_array($evidenceReport) ? [
                'unsupported_claims_count'  => $evidenceReport['unsupported_claims_count'] ?? null,
                'contradicted_claims_count' => $evidenceReport['contradicted_claims_count'] ?? null,
                'high_importance_unsupported_count' => $evidenceReport['high_importance_unsupported_count'] ?? null,
                'challenged_claims_count' => $evidenceReport['challenged_claims_count'] ?? null,
                'high_importance_challenged_count' => $evidenceReport['high_importance_challenged_count'] ?? null,
            ] : null,
        ];

        $synthOutRaw = trim((string)($runResult['synthesizer_output'] ?? ''));

        $fromLlm = TradeoffsJsonExtractor::fromSynthesizerOutput($synthOutRaw);
        $llmNorm = ($fromLlm !== null && ($fromLlm['enabled'] ?? true) !== false)
            ? self::normalizeTradeoffsStructure(['tradeoffs' => $fromLlm])
            : null;

        if ($llmNorm !== null && !empty($llmNorm['tradeoffs']['criteria'])) {
            $brief['tradeoffs'] = $llmNorm['tradeoffs'];
        } else {
            $tradeoffs = $this->buildTradeoffsFromRunContext([
                'adjusted_decision'      => $runResult['adjusted_decision'] ?? [],
                'decision_quality_score'=> $runResult['decision_quality_score'] ?? [],
                'context_quality'       => $runResult['context_quality'] ?? [],
                'risk_profile'          => $runResult['risk_profile'] ?? null,
                'false_consensus'       => $runResult['false_consensus'] ?? null,
                'verdict'               => $runResult['verdict'] ?? null,
                'guardrails'            => $guardrails ?? [],
                'session_mode'          => $runResult['session_mode_hint'] ?? null,
            ]);

            $normalizedBriefTradeoffs = $tradeoffs !== null ? self::normalizeTradeoffsStructure(['tradeoffs' => $tradeoffs]) : null;
            if ($normalizedBriefTradeoffs !== null) {
                $brief['tradeoffs'] = $normalizedBriefTradeoffs['tradeoffs'];
            }
        }

        return $brief;
    }

    /**
     * Multi-criteria heuristic trade-offs for UI (scores 1–5, higher = more favourable on that axis).
     * Returns null only if normalization fails; callers may still ignore silently.
     *
     * @param array<string,mixed> $ctx
     */
    public function buildTradeoffsFromRunContext(array $ctx): ?array {
        $adj       = $ctx['adjusted_decision'] ?? [];
        $verdict   = $ctx['verdict'] ?? null;
        $verdict   = is_array($verdict) ? $verdict : [];
        $qScore    = $ctx['decision_quality_score'] ?? [];
        $qScore    = is_array($qScore) ? $qScore : [];
        $ctxQ      = $ctx['context_quality'] ?? [];
        $ctxQ      = is_array($ctxQ) ? $ctxQ : [];
        $risk      = $ctx['risk_profile'] ?? null;
        $risk      = is_array($risk) ? $risk : [];
        $fc        = $ctx['false_consensus'] ?? null;
        $fc        = is_array($fc) ? $fc : [];
        $decisionScore = isset($adj['decision_score']) ? (float)$adj['decision_score'] : null;
        $dqFloat       = isset($qScore['decision_quality_score']) ? (float)$qScore['decision_quality_score'] : null;
        $confLevel     = strtoupper((string)($adj['confidence_level'] ?? 'LOW'));

        $criteria = [];

        // 1 — User value / impact
        if (isset($verdict['product_value_score']) && $verdict['product_value_score'] !== null && $verdict['product_value_score'] !== '') {
            $s = self::clamp15((int)round(((int)$verdict['product_value_score']) / 2));
            $criteria[] = [
                'id' => 'user_value',
                'score' => $s,
                'justification' => 'Derived from persisted verdict Product Value (/10); coarse mapping — confirm with stakeholders.',
                'confidence' => 'medium',
            ];
        } elseif ($dqFloat !== null) {
            $s = self::clamp15((int)max(1, min(5, (int)ceil($dqFloat / 20))));
            $criteria[] = [
                'id' => 'user_value',
                'score' => $s,
                'justification' => 'Derived from automated decision-quality score (/100); indicative only — not user research.',
                'confidence' => 'low',
            ];
        } elseif ($decisionScore !== null) {
            $s = self::clamp15((int)round($decisionScore * 4 + 1));
            $criteria[] = [
                'id' => 'user_value',
                'score' => $s,
                'justification' => 'Rough proxy from aggregated decision signal — no dedicated customer-value metric available.',
                'confidence' => 'low',
            ];
        } else {
            $criteria[] = [
                'id' => 'user_value',
                'score' => 3,
                'justification' => 'Neutral placeholder — neither verdict value score nor quality aggregates were available for this session snapshot.',
                'confidence' => 'low',
            ];
        }

        // 2 — Cost / effort (high score = favourable = lower relative effort burden)
        if (isset($verdict['feasibility_score']) && $verdict['feasibility_score'] !== '') {
            $fs = self::clamp15((int)round(((int)$verdict['feasibility_score']) / 2));
            $criteria[] = [
                'id' => 'cost',
                'score' => $fs,
                'justification' => 'Interpreted via verdict Feasibility as inverse proxy for execution burden (mapping /10 → /5).',
                'confidence' => 'medium',
            ];
        } else {
            $proc = strtolower((string)($risk['required_process'] ?? ''));
            $s = match ($proc) {
                'quick' => 4,
                'strict' => 2,
                default => 3,
            };
            $criteria[] = [
                'id' => 'cost',
                'score' => $s,
                'justification' => 'No feasibility score; inferred from governance process heuristic (' . ($proc !== '' ? $proc : 'unknown') . ').',
                'confidence' => 'low',
            ];
        }

        // 3 — Risk (high score = low residual risk favourable to proceed)
        if (($risk['risk_level'] ?? '') !== '') {
            $rl = strtolower((string)$risk['risk_level']);
            $s = match ($rl) {
                'low' => 5,
                'medium' => 3,
                'high' => 2,
                'critical' => 1,
                default => 3,
            };
            $criteria[] = [
                'id' => 'risk',
                'score' => $s,
                'justification' => 'Mapped from heuristic risk profile level (' . $rl . ') — corroborate with SMEs.',
                'confidence' => $risk !== [] ? 'medium' : 'low',
            ];
        } elseif (isset($verdict['risk_score']) && $verdict['risk_score'] !== '') {
            $vr = max(1, min(10, (int)$verdict['risk_score']));
            $s = self::clamp15(6 - (int)max(1, round($vr / 2)));
            $criteria[] = [
                'id' => 'risk',
                'score' => $s,
                'justification' => 'Inverted verdict Risk score (/10 higher = more peril) mapped to favourable /5.',
                'confidence' => 'medium',
            ];
        } else {
            $criteria[] = [
                'id' => 'risk',
                'score' => 3,
                'justification' => 'Risk metadata missing — neutral placeholder; escalate if decision is consequential.',
                'confidence' => 'low',
            ];
        }

        // 4 — Time-to-market
        $proc2 = strtolower((string)($risk['required_process'] ?? ''));
        $ttm = match ($proc2) {
            'quick' => 5,
            'standard' => 3,
            'strict' => 2,
            default => 3,
        };
        $criteria[] = [
            'id' => 'ttm',
            'score' => $ttm,
            'justification' => 'Inferred from process rigour heuristic and lightweight debate cohesion signal.',
            'confidence' => 'low',
        ];

        // 5 — Complexity (high score = simpler / easier to steer)
        $lvl = strtolower((string)($ctxQ['level'] ?? ''));
        $cx = match ($lvl) {
            'strong' => 5,
            'medium' => 3,
            'weak' => 2,
            default => 3,
        };
        $criteria[] = [
            'id' => 'complexity',
            'score' => self::clamp15($cx),
            'justification' => 'Context clarity level (' . ($lvl !== '' ? $lvl : 'unknown') . ') used as readability / execution-clarity proxy.',
            'confidence' => 'medium',
        ];

        // 6 — Confidence / team conviction
        if (isset($verdict['confidence_score']) && $verdict['confidence_score'] !== '') {
            $cRaw = (float)$verdict['confidence_score'];
            if ($cRaw > 1.5) {
                $cv = self::clamp15((int)round($cRaw / 2));
            } else {
                $cv = self::clamp15((int)max(1, min(5, (int)round($cRaw * 4 + 1))));
            }
            $criteria[] = [
                'id' => 'confidence',
                'score' => $cv,
                'justification' => 'From persisted verdict confidence score coarse mapping (/5 scale).',
                'confidence' => 'medium',
            ];
        } else {
            $cv = match ($confLevel) {
                'HIGH' => 5,
                'MEDIUM', 'MID' => 3,
                default => ($decisionScore !== null ? self::clamp15((int)round($decisionScore * 4 + 1)) : 3),
            };
            $criteria[] = [
                'id' => 'confidence',
                'score' => self::clamp15($cv),
                'justification' => 'Consensus confidence `' . strtolower($confLevel) . '` and decision score heuristic.',
                'confidence' => ($confLevel !== 'LOW' ? 'medium' : 'low'),
            ];
        }
        $summary = 'Heuristic multi-criteria sketch from debate outputs — illustrative, not calibrated metrics.';
        if (count(array_filter(array_column($criteria, 'confidence'), fn($x) => $x === 'low')) >= 4) {
            $summary .= ' Several axes rely on placeholders because structured verdict or risk artefacts were incomplete.';
        }
        return [
            'enabled' => true,
            'criteria' => $criteria,
            'summary' => $summary,
            'options' => null,
        ];
    }

    /**
     * @param list<mixed> $criteriaRows
     * @return list<array<string,mixed>>
     */
    public static function normalizeTradeoffCriteriaRows(array $criteriaRows): array {
        $out = [];
        $seenId = [];
        foreach ($criteriaRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)($row['id'] ?? ''))) ?: '';
            if ($id === '' || isset($seenId[$id])) {
                continue;
            }
            $rawSc = isset($row['score']) ? (float)$row['score'] : null;
            $sc = $rawSc !== null ? (int)round($rawSc) : 3;
            $sc = max(1, min(5, $sc));

            $jz = trim((string)($row['justification'] ?? ''));
            if ($jz === '') {
                continue;
            }
            $item = ['id' => $id, 'score' => $sc, 'justification' => $jz];
            if (!empty($row['label'])) {
                $item['label'] = (string)$row['label'];
            }
            $confSrc = $row['confidence'] ?? $row['criterion_confidence'] ?? null;
            if (!empty($confSrc) && is_string($confSrc)) {
                $item['confidence'] = $confSrc;
            }
            $seenId[$id] = true;
            $out[] = $item;
            if (count($out) >= 16) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param mixed $options
     * @return ?list<array{label:string,criteria:list<array<string,mixed>>}>
     */
    private static function normalizeTradeoffsOptionsEnvelope($options): ?array {
        if ($options === null) {
            return null;
        }
        if (!is_array($options)) {
            return null;
        }
        $outOpts = [];
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $lbl = trim((string)($opt['label'] ?? ''));
            if ($lbl === '') {
                continue;
            }
            $crit = self::normalizeTradeoffCriteriaRows($opt['criteria'] ?? []);
            if ($crit === []) {
                continue;
            }
            $outOpts[] = ['label' => $lbl, 'criteria' => $crit];
            if (count($outOpts) >= 8) {
                break;
            }
        }
        return $outOpts === [] ? null : $outOpts;
    }

    /**
     * @param array<string,mixed> $payload May include top-level legacy shape { tradeoffs: {...} }
     * @return ?array<string,mixed> Normalized envelope { tradeoffs: { enabled, criteria, summary?, options? } }
     */
    public static function normalizeTradeoffsStructure(array $payload): ?array {
        $t = $payload['tradeoffs'] ?? $payload;
        if (!is_array($t)) {
            return null;
        }
        if (array_key_exists('enabled', $t) && $t['enabled'] === false) {
            return null;
        }
        if (empty($t['criteria']) || !is_array($t['criteria'])) {
            return null;
        }
        $outCriteria = self::normalizeTradeoffCriteriaRows($t['criteria']);
        if ($outCriteria === []) {
            return null;
        }

        $optionsNorm = self::normalizeTradeoffsOptionsEnvelope(array_key_exists('options', $t) ? $t['options'] : null);

        $summary = isset($t['summary']) ? trim((string)$t['summary']) : '';
        return [
            'tradeoffs' => [
                'enabled'   => true,
                'criteria'  => $outCriteria,
                'summary'   => $summary !== '' ? $summary : null,
                'options'   => $optionsNorm,
            ],
        ];
    }

    private static function clamp15(int $x): int {
        return max(1, min(5, $x));
    }

    private function parseSynthesizerSection(string $output, string $heading): array
    {
        if (empty($output)) return [];
        $pattern = '/##\s+' . preg_quote($heading, '/') . '\s*\n(.*?)(?=\n##|\z)/si';
        if (!preg_match($pattern, $output, $m)) return [];
        $lines = explode("\n", trim($m[1]));
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^-\s+(.+)/', $line, $lm)) {
                $items[] = trim($lm[1]);
            }
        }
        return array_slice($items, 0, 3);
    }

    private function parseSynthesizerNextStep(string $output): string
    {
        if (empty($output)) return '';
        if (!preg_match('/##\s+Next Step\s*\n(.+?)(?=\n##|\z)/si', $output, $m)) return '';
        return trim($m[1]);
    }
}

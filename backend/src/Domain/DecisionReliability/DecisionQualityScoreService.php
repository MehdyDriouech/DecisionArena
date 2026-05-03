<?php
declare(strict_types=1);

namespace Domain\DecisionReliability;

class DecisionQualityScoreService
{
    public function compute(
        array $contextQuality,
        float $debateQualityScore,
        ?array $evidenceReport,
        ?array $riskProfile,
        array $falseConsensus
    ): array {
        $explanation = [];

        // Context quality: 0–25 pts
        $ctxScore = (float)($contextQuality['score'] ?? 0);
        $ctxPts   = round($ctxScore / 100 * 25, 1);
        $explanation[] = "Context quality: {$ctxPts}/25 (raw score: {$ctxScore})";

        // Debate quality: 0–25 pts
        $debPts = round($debateQualityScore / 100 * 25, 1);
        $explanation[] = "Debate quality: {$debPts}/25 (score: {$debateQualityScore})";

        // Evidence: 0–20 pts (Phase 3: density + high-importance gaps, not citation count)
        $evPts   = 0.0;
        $evScore = 0.0;
        if ($evidenceReport !== null) {
            $evScore = (float)($evidenceReport['score'] ?? (($evidenceReport['evidence_score'] ?? 0.5) * 100));
            $density = (float)($evidenceReport['evidence_density'] ?? 1.0);
            $hic     = (int)($evidenceReport['high_importance_contradicted_count'] ?? 0);
            $hiu     = (int)($evidenceReport['high_importance_unsupported_count'] ?? 0);
            $contra  = (int)($evidenceReport['contradicted_claims_count'] ?? 0);

            $evPts = round($evScore / 100 * 20, 1);
            $evPts -= min(8, $hic * 2.5 + $hiu * 1.5);
            $evPts -= min(4, max(0.0, (0.55 - $density)) * 15);
            $evPts -= min(3, $contra * 1.0);
            $evPts = round(max(0, min(20, $evPts)), 1);

            $explanation[] = "Evidence: {$evPts}/20 (score: {$evScore}, density: " . round($density * 100, 1) . "%, contra: {$contra}, hi_unsup: {$hiu})";
        } else {
            $explanation[] = "Evidence: 0/20 (no evidence layer)";
        }

        // Risk alignment: 5–15 pts
        $riskLevel = $riskProfile['risk_level'] ?? null;
        $riskPts = match($riskLevel) {
            'low', 'medium' => 15,
            'high'          => 10,
            'critical'      => 5,
            default         => 10, // absent: neutral
        };
        $explanation[] = "Risk alignment: {$riskPts}/15 (risk_level: " . ($riskLevel ?? 'absent') . ")";

        // False consensus penalty: 0 to -20
        $fcRisk  = $falseConsensus['false_consensus_risk'] ?? 'low';
        $fcPen   = match($fcRisk) {
            'high'   => -20,
            'medium' => -10,
            default  => 0,
        };
        if ($fcPen < 0) {
            $explanation[] = "False consensus penalty: {$fcPen} ({$fcRisk} risk)";
        }

        // Missing critical info penalty: -5 per item, max -20
        $missing     = $contextQuality['critical_missing'] ?? [];
        $missingCount= count($missing);
        $missingPen  = max(-20, $missingCount * -5);
        if ($missingPen < 0) {
            $explanation[] = "{$missingCount} critical info missing: {$missingPen}";
        }

        $raw   = $ctxPts + $debPts + $evPts + $riskPts + $fcPen + $missingPen;

        $challengePen = 0.0;
        if ($evidenceReport !== null) {
            $nCh  = (int)($evidenceReport['challenged_claims_count'] ?? 0);
            $hiCh = (int)($evidenceReport['high_importance_challenged_count'] ?? 0);
            if ($nCh > 0 || $hiCh > 0) {
                // Bounded impact: max −20 on final 100. Scale by claim volume & importance.
                $challengePen = min(20.0, $nCh * 1.8 + $hiCh * 4.5);
                $challengePen = round($challengePen, 1);
                $explanation[] = "User challenge uncertainty: -{$challengePen} pts (contested claims; objective support labels unchanged)";
            }
        }

        $score = (int) max(0, min(100, round($raw - $challengePen)));

        $level = match(true) {
            $score >= 80 => 'strong',
            $score >= 65 => 'medium',
            $score >= 40 => 'fragile',
            default      => 'poor',
        };

        return [
            'decision_quality_score' => $score,
            'level'                  => $level,
            'breakdown' => [
                'context'                  => $ctxPts,
                'debate'                   => $debPts,
                'evidence'                 => $evPts,
                'risk_alignment'           => $riskPts,
                'false_consensus_penalty'  => $fcPen,
                'missing_info_penalty'     => $missingPen,
                'user_challenge_penalty'   => $challengePen > 0 ? -$challengePen : 0,
            ],
            'explanation' => $explanation,
        ];
    }
}

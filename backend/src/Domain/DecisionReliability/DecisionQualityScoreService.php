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

        // Evidence: 0–20 pts
        $evScore = $evidenceReport !== null ? (float)($evidenceReport['score'] ?? 0) : 0.0;
        $evPts   = round($evScore / 100 * 20, 1);
        if ($evidenceReport === null) {
            $explanation[] = "Evidence: 0/20 (no evidence layer)";
        } else {
            $explanation[] = "Evidence: {$evPts}/20 (score: {$evScore})";
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
        $score = (int) max(0, min(100, round($raw)));

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
            ],
            'explanation' => $explanation,
        ];
    }
}

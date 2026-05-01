<?php

declare(strict_types=1);

namespace Domain\Learning;

use Infrastructure\Persistence\LearningRepository;

/**
 * Orchestrates the entire Learning Layer.
 *
 * Produces a complete learning report:
 * {
 *   "overview": { ... },
 *   "agent_performance": [...],
 *   "mode_performance": [...],
 *   "calibration": { ... },
 *   "insights": [...],
 *   "recommendations": [...]
 * }
 */
class LearningInsightService
{
    private DecisionOutcomeAnalyzer    $analyzer;
    private AgentPerformanceService    $agentSvc;
    private ModePerformanceService     $modeSvc;
    private ReliabilityCalibrationService $calibSvc;
    private LearningRepository         $repo;

    public function __construct()
    {
        $this->analyzer  = new DecisionOutcomeAnalyzer();
        $this->agentSvc  = new AgentPerformanceService();
        $this->modeSvc   = new ModePerformanceService();
        $this->calibSvc  = new ReliabilityCalibrationService();
        $this->repo      = new LearningRepository();
    }

    /**
     * Returns the full report, using cache if available.
     * @return array<string,mixed>
     */
    public function getOverview(bool $forceRecompute = false): array
    {
        if (!$forceRecompute) {
            $cached = $this->repo->findCache('global', null);
            if ($cached !== null) {
                return $cached;
            }
        }

        $report = $this->compute();
        $this->repo->saveCache('global', null, $report);
        return $report;
    }

    /**
     * Invalidates all caches and recomputes.
     * @return array<string,mixed>
     */
    public function recompute(): array
    {
        $this->repo->invalidateAll();
        $report = $this->compute();
        $this->repo->saveCache('global', null, $report);
        return $report;
    }

    /**
     * Core computation — no cache involvement.
     * @return array<string,mixed>
     */
    private function compute(): array
    {
        $total = $this->analyzer->countPostmortems();
        $minRequired = LearningRepository::MIN_SESSIONS_FOR_INSIGHTS;

        if ($total === 0) {
            return $this->emptyState();
        }

        $outcomes   = $this->analyzer->loadEnrichedOutcomes();
        $decisions  = $this->analyzer->loadDecisionConfidenceOutcomes();

        $agentPerf  = $this->agentSvc->compute($outcomes);
        $modePerf   = $this->modeSvc->compute($outcomes);
        $calibration= $this->calibSvc->compute($outcomes, $decisions);

        $overview   = $this->buildOverview($outcomes, $total, $minRequired);
        $insights   = $this->buildInsights($agentPerf, $modePerf, $calibration, $outcomes);
        $recs       = $this->buildTopRecommendations($agentPerf, $modePerf, $calibration);

        return [
            'overview'          => $overview,
            'agent_performance' => $agentPerf,
            'mode_performance'  => $modePerf,
            'calibration'       => $calibration,
            'insights'          => $insights,
            'recommendations'   => $recs,
            'computed_at'       => date('c'),
            'sufficient_data'   => $total >= $minRequired,
            'postmortems_count' => $total,
        ];
    }

    /** @param list<array<string,mixed>> $outcomes */
    private function buildOverview(array $outcomes, int $total, int $minRequired): array
    {
        $correct = $partial = $incorrect = 0;
        foreach ($outcomes as $o) {
            if ($o['outcome'] === 'correct')       { $correct++; }
            elseif ($o['outcome'] === 'partial')   { $partial++; }
            elseif ($o['outcome'] === 'incorrect') { $incorrect++; }
        }

        $correctRate  = $total > 0 ? round($correct / $total, 3) : 0.0;
        $incorrectRate= $total > 0 ? round($incorrect / $total, 3) : 0.0;

        return [
            'total_postmortems'  => $total,
            'correct_count'      => $correct,
            'partial_count'      => $partial,
            'incorrect_count'    => $incorrect,
            'correct_rate'       => $correctRate,
            'incorrect_rate'     => $incorrectRate,
            'data_confidence'    => $total >= $minRequired ? 'sufficient' : 'low',
            'min_required'       => $minRequired,
        ];
    }

    /**
     * Produces cross-cutting insights from all analytics.
     * @param list<array<string,mixed>> $agentPerf
     * @param list<array<string,mixed>> $modePerf
     * @param array<string,mixed>       $calibration
     * @param list<array<string,mixed>> $outcomes
     * @return list<array<string,mixed>>
     */
    private function buildInsights(
        array $agentPerf,
        array $modePerf,
        array $calibration,
        array $outcomes
    ): array {
        $insights = [];

        // Overconfidence global insight
        if (($calibration['overconfidence_rate'] ?? 0) > 0.2) {
            $rate = round(($calibration['overconfidence_rate'] ?? 0) * 100);
            $insights[] = [
                'type'    => 'overconfidence',
                'level'   => 'warning',
                'message' => "System is overconfident in {$rate}% of high-confidence sessions that turned out incorrect.",
            ];
        }

        // Weak context signal
        $wcsr = $calibration['weak_context_success_rate'] ?? null;
        if ($wcsr !== null && $wcsr < 0.35 && ($calibration['weak_context_session_count'] ?? 0) >= 2) {
            $pct = round($wcsr * 100);
            $insights[] = [
                'type'    => 'weak_context',
                'level'   => 'warning',
                'message' => "Decisions made with weak context quality succeed only {$pct}% of the time. Clarify context before debate.",
            ];
        }

        // False consensus signal
        if (($calibration['false_consensus_failure_rate'] ?? 0) > 0.35) {
            $pct = round(($calibration['false_consensus_failure_rate'] ?? 0) * 100);
            $insights[] = [
                'type'    => 'false_consensus',
                'level'   => 'warning',
                'message' => "Low-reliability-cap (false consensus risk) sessions fail {$pct}% of the time. Enable Devil's Advocate.",
            ];
        }

        // Agent calibration warnings
        foreach ($agentPerf as $ap) {
            if ($ap['calibration_warning'] === 'overconfident_when_wrong' && !$ap['insufficient_data']) {
                $insights[] = [
                    'type'    => 'agent_calibration',
                    'level'   => 'info',
                    'message' => "Agent '{$ap['agent_id']}' tends to be overconfident when wrong (avg confidence: {$ap['avg_confidence_when_wrong']}).",
                ];
            }
        }

        // Mode-specific risky signal
        foreach ($modePerf as $mp) {
            if (!$mp['insufficient_data'] && $mp['incorrect_rate'] > 0.3) {
                $pct = round($mp['incorrect_rate'] * 100);
                $insights[] = [
                    'type'    => 'mode_reliability',
                    'level'   => 'info',
                    'message' => "Mode '{$mp['mode_label']}' has {$pct}% incorrect rate. Review session configurations.",
                ];
            }
        }

        return $insights;
    }

    /**
     * Aggregates top-level recommendations across all sub-services.
     * @param list<array<string,mixed>> $agentPerf
     * @param list<array<string,mixed>> $modePerf
     * @param array<string,mixed>       $calibration
     * @return list<string>
     */
    private function buildTopRecommendations(
        array $agentPerf,
        array $modePerf,
        array $calibration
    ): array {
        $recs = [];

        // From calibration
        foreach ($calibration['recommendations'] ?? [] as $r) {
            if (!in_array($r, $recs, true)) {
                $recs[] = $r;
            }
        }

        // From modes (first risky mode)
        foreach ($modePerf as $mp) {
            if (!$mp['insufficient_data'] && !empty($mp['recommendation'])) {
                if ($mp['incorrect_rate'] > 0.3) {
                    $recs[] = "[Mode: {$mp['mode_label']}] {$mp['recommendation']}";
                }
            }
        }

        // From agents with warnings
        foreach ($agentPerf as $ap) {
            if (!$ap['insufficient_data'] && $ap['calibration_warning'] && !empty($ap['recommendation'])) {
                $recs[] = "[Agent: {$ap['agent_id']}] {$ap['recommendation']}";
            }
        }

        return array_values(array_unique($recs));
    }

    private function emptyState(): array
    {
        return [
            'overview' => [
                'total_postmortems'  => 0,
                'correct_count'      => 0,
                'partial_count'      => 0,
                'incorrect_count'    => 0,
                'correct_rate'       => 0.0,
                'incorrect_rate'     => 0.0,
                'data_confidence'    => 'none',
                'min_required'       => LearningRepository::MIN_SESSIONS_FOR_INSIGHTS,
            ],
            'agent_performance' => [],
            'mode_performance'  => [],
            'calibration'       => [
                'total_sessions_analyzed'     => 0,
                'high_confidence_count'       => 0,
                'high_confidence_wrong_count' => 0,
                'overconfidence_rate'         => 0.0,
                'go_decision_count'           => 0,
                'go_failure_rate'             => 0.0,
                'weak_context_session_count'  => 0,
                'weak_context_success_rate'   => null,
                'low_reliability_cap_count'   => 0,
                'false_consensus_failure_rate'=> 0.0,
                'recommendations'             => [],
            ],
            'insights'          => [],
            'recommendations'   => [],
            'computed_at'       => date('c'),
            'sufficient_data'   => false,
            'postmortems_count' => 0,
        ];
    }
}

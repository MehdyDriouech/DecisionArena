<?php

declare(strict_types=1);

namespace Controllers;

use Domain\Learning\LearningInsightService;
use Http\Request;

class LearningController
{
    private LearningInsightService $service;

    public function __construct()
    {
        $this->service = new LearningInsightService();
    }

    /**
     * GET /api/learning/overview
     * Full report (overview + all sub-sections).
     */
    public function overview(Request $request): array
    {
        return $this->service->getOverview();
    }

    /**
     * GET /api/learning/agents
     * Agent performance list only.
     */
    public function agents(Request $request): array
    {
        $report = $this->service->getOverview();
        return [
            'agent_performance' => $report['agent_performance'] ?? [],
            'sufficient_data'   => $report['sufficient_data']   ?? false,
            'postmortems_count' => $report['postmortems_count'] ?? 0,
            'computed_at'       => $report['computed_at']       ?? null,
        ];
    }

    /**
     * GET /api/learning/modes
     * Mode performance list only.
     */
    public function modes(Request $request): array
    {
        $report = $this->service->getOverview();
        return [
            'mode_performance' => $report['mode_performance'] ?? [],
            'sufficient_data'  => $report['sufficient_data']  ?? false,
            'postmortems_count'=> $report['postmortems_count']?? 0,
            'computed_at'      => $report['computed_at']      ?? null,
        ];
    }

    /**
     * GET /api/learning/calibration
     * Reliability calibration only.
     */
    public function calibration(Request $request): array
    {
        $report = $this->service->getOverview();
        return [
            'calibration'       => $report['calibration']      ?? [],
            'sufficient_data'   => $report['sufficient_data']  ?? false,
            'postmortems_count' => $report['postmortems_count']?? 0,
            'computed_at'       => $report['computed_at']      ?? null,
        ];
    }

    /**
     * POST /api/learning/recompute
     * Invalidates all caches and recomputes the full report.
     */
    public function recompute(Request $request): array
    {
        try {
            $report = $this->service->recompute();
            return ['status' => 'recomputed', 'report' => $report];
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => 'Recompute failed: ' . $e->getMessage()];
        }
    }

    /**
     * GET /api/learning/export
     * Returns Markdown or JSON report as downloadable content.
     * Query params: format=markdown|json
     */
    public function export(Request $request): array
    {
        try {
            $format = $request->query('format', 'markdown');
            $report = $this->service->getOverview();

            if ($format === 'json') {
                return [
                    'format'   => 'json',
                    'content'  => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'filename' => 'learning-report.json',
                ];
            }

            $md = $this->buildMarkdown($report);
            return [
                'format'   => 'markdown',
                'content'  => $md,
                'filename' => 'learning-report.md',
            ];
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => 'Export failed: ' . $e->getMessage()];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildMarkdown(array $report): string
    {
        $lines = [];
        $lines[] = '# Learning Report';
        $lines[] = '';
        $lines[] = '> Generated: ' . ($report['computed_at'] ?? date('c'));
        $lines[] = '';

        // Overview
        $ov = $report['overview'] ?? [];
        $lines[] = '## Overview';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|--------|-------|';
        $lines[] = '| Post-mortems | ' . ($ov['total_postmortems'] ?? 0) . ' |';
        $lines[] = '| Correct rate | ' . round(($ov['correct_rate'] ?? 0) * 100) . '% |';
        $lines[] = '| Incorrect rate | ' . round(($ov['incorrect_rate'] ?? 0) * 100) . '% |';
        $lines[] = '| Data confidence | ' . ($ov['data_confidence'] ?? 'none') . ' |';
        $lines[] = '';

        // Mode performance
        $modes = $report['mode_performance'] ?? [];
        if (!empty($modes)) {
            $lines[] = '## Mode Performance';
            $lines[] = '';
            $lines[] = '| Mode | Sessions | Correct | Incorrect | Recommendation |';
            $lines[] = '|------|----------|---------|-----------|----------------|';
            foreach ($modes as $m) {
                $correct   = round(($m['correct_rate'] ?? 0) * 100) . '%';
                $incorrect = round(($m['incorrect_rate'] ?? 0) * 100) . '%';
                $rec = str_replace('|', '/', $m['recommendation'] ?? '');
                $lines[] = "| {$m['mode_label']} | {$m['sessions_count']} | $correct | $incorrect | $rec |";
            }
            $lines[] = '';
        }

        // Agent performance
        $agents = $report['agent_performance'] ?? [];
        if (!empty($agents)) {
            $lines[] = '## Agent Performance';
            $lines[] = '';
            $lines[] = '| Agent | Sessions | Correct | Incorrect | Warning | Recommendation |';
            $lines[] = '|-------|----------|---------|-----------|---------|----------------|';
            foreach ($agents as $a) {
                $correct   = $a['insufficient_data'] ? 'N/A' : round(($a['correct_rate'] ?? 0) * 100) . '%';
                $incorrect = $a['insufficient_data'] ? 'N/A' : round(($a['incorrect_rate'] ?? 0) * 100) . '%';
                $warn      = $a['calibration_warning'] ?? '-';
                $rec       = str_replace('|', '/', $a['recommendation'] ?? '');
                $lines[] = "| {$a['agent_id']} | {$a['sessions_count']} | $correct | $incorrect | $warn | $rec |";
            }
            $lines[] = '';
        }

        // Calibration
        $cal = $report['calibration'] ?? [];
        if (!empty($cal) && ($cal['total_sessions_analyzed'] ?? 0) > 0) {
            $lines[] = '## Reliability Calibration';
            $lines[] = '';
            $lines[] = '| Metric | Value |';
            $lines[] = '|--------|-------|';
            $lines[] = '| Overconfidence rate | ' . round(($cal['overconfidence_rate'] ?? 0) * 100) . '% |';
            $lines[] = '| High-confidence wrong count | ' . ($cal['high_confidence_wrong_count'] ?? 0) . ' |';
            $lines[] = '| GO failure rate | ' . round(($cal['go_failure_rate'] ?? 0) * 100) . '% |';
            $wcsr = $cal['weak_context_success_rate'];
            $lines[] = '| Weak context success rate | ' . ($wcsr !== null ? round($wcsr * 100) . '%' : 'N/A') . ' |';
            $lines[] = '| False consensus failure rate | ' . round(($cal['false_consensus_failure_rate'] ?? 0) * 100) . '% |';
            $lines[] = '';
            if (!empty($cal['recommendations'])) {
                $lines[] = '### Calibration Recommendations';
                $lines[] = '';
                foreach ($cal['recommendations'] as $r) {
                    $lines[] = '- ' . $r;
                }
                $lines[] = '';
            }
        }

        // Top recommendations
        $recs = $report['recommendations'] ?? [];
        if (!empty($recs)) {
            $lines[] = '## Top Recommendations';
            $lines[] = '';
            foreach ($recs as $r) {
                $lines[] = '- ' . $r;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace Domain\Learning;

use Infrastructure\Persistence\LearningRepository;

/**
 * Loads and enriches raw session+postmortem data for downstream analytics.
 *
 * Does NOT produce final statistics — it prepares normalised records.
 */
class DecisionOutcomeAnalyzer
{
    private LearningRepository $repo;

    public function __construct()
    {
        $this->repo = new LearningRepository();
    }

    /**
     * Returns an array of enriched outcome records.
     * Each record carries the session fields + postmortem outcome + parsed agents.
     *
     * @return list<array<string,mixed>>
     */
    public function loadEnrichedOutcomes(): array
    {
        $rows   = $this->repo->findSessionsWithPostmortems();
        $result = [];

        foreach ($rows as $row) {
            $agents = [];
            if (isset($row['selected_agents'])) {
                $decoded = json_decode((string)$row['selected_agents'], true);
                if (is_array($decoded)) {
                    $agents = array_values(array_filter(
                        $decoded,
                        fn($v) => is_string($v) && $v !== ''
                    ));
                }
            }

            $result[] = [
                'session_id'              => (string)$row['session_id'],
                'mode'                    => (string)($row['mode'] ?? 'unknown'),
                'agents'                  => $agents,
                'decision_threshold'      => (float)($row['decision_threshold'] ?? 0.55),
                'context_quality_level'   => (string)($row['context_quality_level'] ?? 'unknown'),
                'context_quality_score'   => isset($row['context_quality_score'])
                    ? (float)$row['context_quality_score']
                    : null,
                'reliability_cap'         => isset($row['reliability_cap'])
                    ? (float)$row['reliability_cap']
                    : null,
                'outcome'                 => (string)($row['outcome'] ?? 'unknown'),
                'confidence_in_retrospect'=> (float)($row['confidence_in_retrospect'] ?? 0.5),
            ];
        }

        return $result;
    }

    /**
     * Returns decision rows with system confidence + actual outcome.
     * Used by ReliabilityCalibrationService.
     *
     * @return list<array<string,mixed>>
     */
    public function loadDecisionConfidenceOutcomes(): array
    {
        $rows = $this->repo->findDecisionsWithPostmortems();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'session_id'      => (string)($row['session_id'] ?? ''),
                'decision_label'  => (string)($row['decision_label'] ?? ''),
                'decision_score'  => isset($row['decision_score']) ? (float)$row['decision_score'] : null,
                'confidence_level'=> (string)($row['confidence_level'] ?? 'low'),
                'outcome'         => (string)($row['outcome'] ?? 'unknown'),
            ];
        }

        return $result;
    }

    public function countPostmortems(): int
    {
        return $this->repo->countPostmortems();
    }
}

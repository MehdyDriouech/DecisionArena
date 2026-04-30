<?php
namespace Domain\DecisionReliability;

class ContextQualityAnalyzer {
    /** @var array<int,string> */
    private const BUZZWORDS = [
        'innovant', 'innovative', 'disruptif', 'disruptive', 'scalable', 'synergy', 'paradigm',
        'holistic', 'groundbreaking', 'best-in-class', 'best in class', 'state of the art',
        'game-changer', 'game changer', 'bleeding edge', 'leverage', 'mission-critical',
        'world-class', 'next-gen', 'next gen', 'ai-powered', 'revolutionary', 'cutting-edge',
        'cutting edge', 'impactful',
    ];

    /**
     * Higher = more concrete / information-dense (0..1).
     */
    public function detectWeakSemanticDensity(string $text): float {
        $trim = trim($text);
        if ($trim === '') {
            return 0.0;
        }
        $normalized = mb_strtolower($trim, 'UTF-8');
        $wordCount = $this->wordCount($trim);
        if ($wordCount === 0) {
            return 0.0;
        }

        $digitHits = preg_match_all('/\d/', $trim) ?: 0;
        $hasMoney = preg_match('/[$€£]|€\s?\d|\beur\b|\busd\b|\d+\s?(k\b|m\b|md\b)/u', $normalized) === 1;
        $hasPercent = str_contains($normalized, '%');

        $concreteNeedles = [
            'kpi', 'metric', 'metrics', 'deadline', 'timeline', 'sla', 'budget', 'cost',
            'latency', 'availability', 'conversion', 'retention', 'revenue', 'roi', 'churn',
            'adoption', 'q1', 'q2', 'q3', 'q4', 'week', 'month', 'year', 'jour', 'mois', 'semaine',
        ];
        $concreteHits = 0;
        foreach ($concreteNeedles as $n) {
            if (str_contains($normalized, $n)) {
                $concreteHits++;
            }
        }

        $buzz = 0;
        foreach (self::BUZZWORDS as $b) {
            if (str_contains($normalized, $b)) {
                $buzz++;
            }
        }

        $numericSignal = min(1.0, $digitHits * 0.08 + ($hasPercent ? 0.18 : 0.0) + ($hasMoney ? 0.15 : 0.0));
        $concreteRatio = min(1.0, $concreteHits / 6.0);
        $lengthFactor = min(1.0, $wordCount / 90.0);
        $buzzPenalty = min(0.45, $buzz * 0.11 + max(0.0, $buzz / max(1.0, $wordCount / 40.0)) * 0.15);

        $fluffFromLength = ($wordCount > 35 && $digitHits === 0 && !$hasPercent) ? 0.12 : 0.0;

        $density = 0.28 + 0.34 * $numericSignal + 0.28 * $concreteRatio + 0.10 * $lengthFactor;
        $density -= $buzzPenalty;
        $density -= $fluffFromLength;

        return round(max(0.0, min(1.0, $density)), 2);
    }

    /**
     * @return array{
     *   score:float,
     *   level:string,
     *   missing_information:array<int,string>,
     *   warnings:array<int,string>,
     *   reliability_cap:float,
     *   critical_missing:array<int,string>,
     *   semantic_density:float
     * }
     */
    public function analyze(string $objective, ?array $contextDoc = null): array {
        $text = trim($objective);
        $normalized = mb_strtolower($text, 'UTF-8');

        $score = 1.0;
        $missing = [];
        $warnings = [];
        $criticalMissing = [];

        $wordCount = $this->wordCount($text);
        if ($wordCount < 6) {
            $score -= 0.25;
            $missing[] = 'objective_specificity';
            $criticalMissing[] = 'objective_specificity';
            $warnings[] = 'Objective is too short to support a reliable decision.';
        } elseif ($wordCount < 12) {
            $score -= 0.12;
            $missing[] = 'objective_depth';
            $warnings[] = 'Objective remains brief and may hide ambiguity.';
        }

        if ($this->isGenericFormulation($normalized)) {
            $score -= 0.15;
            $missing[] = 'problem_framing';
            $warnings[] = 'Formulation is too generic and not operationally scoped.';
        }

        if (!$this->hasSuccessCriteria($normalized)) {
            $score -= 0.16;
            $missing[] = 'success_criteria';
            $criticalMissing[] = 'success_criteria';
            $warnings[] = 'No explicit success criteria were detected.';
        } elseif (!$this->hasMeasurableObjective($normalized) && $wordCount > 18) {
            $score -= 0.10;
            $missing[] = 'success_criteria';
            $warnings[] = 'Goals appear stated but not measurable (no numbers, dates, or %).';
        }

        $constraintKeywords = $this->hasConstraints($normalized);
        if (!$constraintKeywords) {
            $score -= 0.14;
            $missing[] = 'constraints';
            $criticalMissing[] = 'constraints';
            $warnings[] = 'No constraints (time, budget, legal, technical) were detected.';
        } elseif ($wordCount > 20 && !$this->hasMeasurableConstraintSignal($text)) {
            $score -= 0.08;
            $missing[] = 'measurable_constraints';
            $criticalMissing[] = 'measurable_constraints';
            $warnings[] = 'Constraints are mentioned but not quantified (no amounts, dates, or SLAs).';
        }

        if (!$this->hasTargetContext($normalized)) {
            $score -= 0.14;
            $missing[] = 'target_or_market';
            $criticalMissing[] = 'target_or_market';
            $warnings[] = 'No explicit user, customer, or market target was detected.';
        }

        $hasContextDoc = !empty($contextDoc['content']);
        if (!$hasContextDoc) {
            $score -= 0.10;
            $missing[] = 'context_document';
            $criticalMissing[] = 'context_document';
            $warnings[] = 'No shared context document was provided.';
        }

        if ($this->hasImplicitAssumptions($normalized) && !$this->hasExplicitAssumptions($normalized)) {
            $score -= 0.08;
            $missing[] = 'explicit_assumptions';
            $warnings[] = 'Implicit assumptions detected without explicit hypothesis framing.';
        }

        $buzzCount = $this->countBuzzwords($normalized);
        if ($buzzCount >= 2 && $wordCount > 25) {
            $score -= min(0.14, 0.05 * $buzzCount);
            $warnings[] = 'Multiple marketing-style buzzwords detected with little operational detail.';
        }

        $semanticDensity = $this->detectWeakSemanticDensity($text);
        if ($semanticDensity < 0.38 && $wordCount > 30) {
            $score -= 0.12;
            $missing[] = 'semantic_density';
            $warnings[] = 'Long text but low semantic density (few numbers/concrete signals).';
        }

        $score = round(max(0.0, min(1.0, $score)), 2);
        $level = ReliabilityConfig::contextLevelFromScore($score);
        $levelBeforeDowngrade = $level;

        if ($semanticDensity < 0.35 && $level === 'strong') {
            $level = 'medium';
        }
        if ($semanticDensity < 0.28 && ($level === 'strong' || $level === 'medium')) {
            $level = 'weak';
        }

        $cap = ReliabilityConfig::reliabilityCapForLevel($level);
        if ($level !== $levelBeforeDowngrade && !in_array('semantic_density', $missing, true)) {
            $missing[] = 'semantic_density';
        }

        return [
            'score' => $score,
            'level' => $level,
            'missing_information' => array_values(array_unique($missing)),
            'warnings' => array_values(array_unique($warnings)),
            'reliability_cap' => $cap,
            'critical_missing' => array_values(array_unique($criticalMissing)),
            'semantic_density' => $semanticDensity,
        ];
    }

    private function wordCount(string $text): int {
        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        return count($parts);
    }

    private function isGenericFormulation(string $text): bool {
        $genericPatterns = [
            '/\bshould we launch this\b/u',
            '/\bshould we do this\b/u',
            '/\bis this a good idea\b/u',
            '/\bwhat do you think\b/u',
            '/\blaunch this\b/u',
            '/\bimprove this\b/u',
        ];
        foreach ($genericPatterns as $p) {
            if (preg_match($p, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function hasSuccessCriteria(string $text): bool {
        return $this->containsAny($text, [
            'kpi', 'metric', 'metrics', 'success', 'objectif', 'goal', 'target',
            '%', 'conversion', 'retention', 'revenue', 'roi', 'deadline', 'timeline',
            'adoption', 'churn', 'performance', 'latency', 'availability',
        ]);
    }

    /** Vague success language without numbers */
    private function hasMeasurableObjective(string $text): bool {
        if (preg_match('/\d/', $text) === 1) {
            return true;
        }
        if (str_contains($text, '%')) {
            return true;
        }
        return $this->containsAny($text, [
            'by q1', 'by q2', 'by q3', 'by q4', 'by 202', 'by 203',
            'within 30', 'within 60', 'within 90', '6 months', '12 months',
        ]);
    }

    private function hasConstraints(string $text): bool {
        return $this->containsAny($text, [
            'budget', 'cost', 'deadline', 'time', 'resource', 'team', 'scope',
            'legal', 'compliance', 'regulation', 'security', 'privacy', 'gdpr',
            'technical debt', 'capacity', 'infra', 'architecture', 'risk',
        ]);
    }

    private function hasMeasurableConstraintSignal(string $text): bool {
        if (preg_match('/\d/', $text) === 1) {
            return true;
        }
        $l = mb_strtolower($text, 'UTF-8');
        return preg_match('/[$€£]|€\s?\d|\beur\b|\busd\b|\d+\s?(k\b|m\b|md\b|days?|weeks?|months?|years?)\b/u', $l) === 1;
    }

    private function hasTargetContext(string $text): bool {
        return $this->containsAny($text, [
            'user', 'users', 'customer', 'customers', 'persona', 'audience', 'segment',
            'market', 'industry', 'b2b', 'b2c', 'tenant', 'client', 'buyer',
        ]);
    }

    private function hasExplicitAssumptions(string $text): bool {
        return $this->containsAny($text, ['assumption', 'assumptions', 'hypothesis', 'hypotheses']);
    }

    private function hasImplicitAssumptions(string $text): bool {
        return $this->containsAny($text, [
            'probably', 'likely', 'should', 'might', 'could', 'expected', 'assume',
            'we think', 'on suppose', 'suppose that',
        ]);
    }

    private function countBuzzwords(string $text): int {
        $n = 0;
        foreach (self::BUZZWORDS as $b) {
            if (str_contains($text, $b)) {
                $n++;
            }
        }
        return $n;
    }

    private function containsAny(string $text, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }
}

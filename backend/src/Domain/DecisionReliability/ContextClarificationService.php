<?php
namespace Domain\DecisionReliability;

/**
 * Produces actionable clarification prompts from context quality gaps (i18n keys + fallback EN).
 */
class ContextClarificationService {
    /** @var array<string,array{key:string,fallback:string}> */
    private const GAP_PROMPTS = [
        'objective_specificity' => [
            'key' => 'clarify.q.objective_specificity',
            'fallback' => 'What exact decision or scope are you trying to resolve (one sentence)?',
        ],
        'objective_depth' => [
            'key' => 'clarify.q.objective_depth',
            'fallback' => 'Can you add a few concrete details (actors, timeline hint, current state)? / Pouvez-vous ajouter des détails concrets (acteurs, échéance, état actuel) ?',
        ],
        'problem_framing' => [
            'key' => 'clarify.q.problem_framing',
            'fallback' => 'What is the problem, for whom, and what would \"done\" look like? / Quel est le problème, pour qui, et à quoi ressemble le \"terminé\" ?',
        ],
        'success_criteria' => [
            'key' => 'clarify.q.success_criteria',
            'fallback' => 'What is the main measurable success criterion (KPI, %, date, revenue, latency, etc.)? / Quel est le critère de succès principal (KPI, %, date, etc.) ?',
        ],
        'constraints' => [
            'key' => 'clarify.q.constraints',
            'fallback' => 'What major constraint must be respected (budget, legal, security, capacity)? / Quelle contrainte majeure (budget, légal, sécurité, capacité) ?',
        ],
        'measurable_constraints' => [
            'key' => 'clarify.q.measurable_constraints',
            'fallback' => 'Can you quantify at least one constraint (amount, deadline, SLA)? / Pouvez-vous quantifier au moins une contrainte (montant, deadline, SLA) ?',
        ],
        'target_or_market' => [
            'key' => 'clarify.q.target_or_market',
            'fallback' => 'Who is the primary user or customer segment? / Qui est la cible utilisateur ou le segment client principal ?',
        ],
        'context_document' => [
            'key' => 'clarify.q.context_document',
            'fallback' => 'Is there a short spec, brief, or data (even bullet points) you can attach as context? / Avez-vous un brief ou des données à joindre ?',
        ],
        'explicit_assumptions' => [
            'key' => 'clarify.q.explicit_assumptions',
            'fallback' => 'What are the 1–3 explicit assumptions or hypotheses underlying this decision? / Quelles sont 1–3 hypothèses explicites ?',
        ],
        'semantic_density' => [
            'key' => 'clarify.q.semantic_density',
            'fallback' => 'Replace vague goals with one concrete metric and one dated milestone. / Remplacez les objectifs vagues par une métrique et une échéance.',
        ],
    ];

    /**
     * @param array<string,mixed> $analysis context_quality analyzer output
     * @return array{questions: array<int,array{key:string,fallback:string}>}
     */
    public function generateClarificationQuestions(array $analysis): array {
        $priority = [];
        $critical = $analysis['critical_missing'] ?? [];
        if (is_array($critical)) {
            foreach ($critical as $gap) {
                $priority[] = (string)$gap;
            }
        }
        $missing = $analysis['missing_information'] ?? [];
        if (is_array($missing)) {
            foreach ($missing as $gap) {
                $g = (string)$gap;
                if (!in_array($g, $priority, true)) {
                    $priority[] = $g;
                }
            }
        }
        if (($analysis['semantic_density'] ?? 1.0) < 0.35 && !in_array('semantic_density', $priority, true)) {
            $priority[] = 'semantic_density';
        }

        $out = [];
        $seen = [];
        foreach ($priority as $gap) {
            if (count($out) >= 5) {
                break;
            }
            if (isset($seen[$gap])) {
                continue;
            }
            $prompt = self::GAP_PROMPTS[$gap] ?? null;
            if ($prompt === null) {
                continue;
            }
            $out[] = $prompt;
            $seen[$gap] = true;
        }

        return ['questions' => $out];
    }
}

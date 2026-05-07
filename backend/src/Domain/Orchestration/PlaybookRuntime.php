<?php
namespace Domain\Orchestration;

/**
 * Operational adapter for Decision Playbooks.
 *
 * UX/business truth stays in frontend/src/core/playbooks.js. This class keeps
 * only runtime-facing slugs and soft extraction hints derived from the canonical
 * output_contract, so prompts/runners can operationalize the canon without
 * becoming a competing product copy source.
 */
class PlaybookRuntime {
    public const CONTRACT_VERSION = RuntimeContracts::PLAYBOOK_RUNTIME_CONTRACT_VERSION;

    /** @var array<string, array<string, mixed>> */
    private const CONTRACTS = [
        'founder-sprint' => [
            'playbook_id' => 'founder-sprint',
            'expected_sections' => ['wedge_critique', 'icp_challenge', 'validation_signal', 'kill_criteria', 'next_experiment'],
            'required_outcomes' => ['wedge_critique', 'icp_challenge', 'validation_signal', 'kill_criteria', 'next_experiment'],
            'required_signals' => ['validation_signal', 'kill_criteria', 'next_experiment'],
            'validation_expectations' => [
                'wedge_critique' => ['wedge', 'segment', 'narrow', 'critique', 'beachhead'],
                'icp_challenge' => ['icp', 'initial customer', 'customer profile', 'persona', 'segment'],
                'validation_signal' => ['validation signal', 'success signal', 'observable signal', 'learning signal'],
                'kill_criteria' => ['kill criteria', 'stop', 'pivot', 'failure signal', 'threshold'],
                'next_experiment' => ['next experiment', 'smallest test', 'experiment', '7-day', 'next step'],
            ],
            'warning_prefix' => 'playbook_founder_sprint',
        ],
        'ceo-challenge' => [
            'playbook_id' => 'ceo-challenge',
            'expected_sections' => ['strategic_assumptions', 'blind_spots', 'execution_risks', 'tradeoff_analysis', 'leadership_decision_memo'],
            'required_outcomes' => ['strategic_assumptions', 'blind_spots', 'execution_risks', 'tradeoff_analysis', 'leadership_decision_memo'],
            'required_signals' => ['blind_spots', 'execution_risks', 'tradeoff_analysis'],
            'validation_expectations' => [
                'strategic_assumptions' => ['strategic assumption', 'assumption', 'bet', 'hypothesis'],
                'blind_spots' => ['blind spot', 'unknown', 'missing', 'overlooked'],
                'execution_risks' => ['execution risk', 'delivery risk', 'operational risk', 'distribution risk'],
                'tradeoff_analysis' => ['trade-off', 'tradeoff', 'compromise', 'cost of choosing'],
                'leadership_decision_memo' => ['decision memo', 'leadership', 'recommendation', 'next strategic step'],
            ],
            'warning_prefix' => 'playbook_ceo_challenge',
        ],
        'stress-test' => [
            'playbook_id' => 'stress-test',
            'expected_sections' => ['core_hypothesis', 'failure_scenarios', 'weakest_assumptions', 'evidence_gaps', 'pivot_kill_signals'],
            'required_outcomes' => ['core_hypothesis', 'failure_scenarios', 'weakest_assumptions', 'evidence_gaps', 'pivot_kill_signals'],
            'required_signals' => ['failure_scenarios', 'evidence_gaps', 'pivot_kill_signals'],
            'validation_expectations' => [
                'core_hypothesis' => ['core hypothesis', 'central hypothesis', 'main assumption'],
                'failure_scenarios' => ['failure scenario', 'failure mode', 'how this fails', 'breaks'],
                'weakest_assumptions' => ['weakest assumption', 'fragile assumption', 'riskiest assumption'],
                'evidence_gaps' => ['evidence gap', 'missing evidence', 'unknown', 'proof gap'],
                'pivot_kill_signals' => ['pivot', 'kill signal', 'kill criteria', 'stop', 'failure signal'],
            ],
            'warning_prefix' => 'playbook_stress_test',
        ],
        'jury' => [
            'playbook_id' => 'jury',
            'expected_sections' => ['decision_options', 'evaluation_criteria', 'pros_cons_by_perspective', 'final_recommendation', 'confidence_level'],
            'required_outcomes' => ['decision_options', 'evaluation_criteria', 'pros_cons_by_perspective', 'final_recommendation', 'confidence_level'],
            'required_signals' => ['evaluation_criteria', 'final_recommendation', 'confidence_level'],
            'validation_expectations' => [
                'decision_options' => ['option', 'alternative', 'choice'],
                'evaluation_criteria' => ['criteria', 'criterion', 'evaluation', 'scoring'],
                'pros_cons_by_perspective' => ['pros', 'cons', 'perspective', 'for', 'against'],
                'final_recommendation' => ['recommendation', 'recommended', 'verdict', 'judgment'],
                'confidence_level' => ['confidence', 'certainty', 'reliability'],
            ],
            'warning_prefix' => 'playbook_jury',
        ],
        'confrontation' => [
            'playbook_id' => 'confrontation',
            'expected_sections' => ['position_a', 'position_b', 'conflict_points', 'strongest_arguments', 'synthesis_or_decision_path'],
            'required_outcomes' => ['position_a', 'position_b', 'conflict_points', 'strongest_arguments', 'synthesis_or_decision_path'],
            'required_signals' => ['conflict_points', 'strongest_arguments', 'synthesis_or_decision_path'],
            'validation_expectations' => [
                'position_a' => ['position a', 'blue team', 'first position', 'one side'],
                'position_b' => ['position b', 'red team', 'second position', 'other side'],
                'conflict_points' => ['conflict point', 'disagreement', 'tension', 'where they differ'],
                'strongest_arguments' => ['strongest argument', 'strongest case', 'best argument'],
                'synthesis_or_decision_path' => ['synthesis', 'decision path', 'combine', 'test', 'recommended decision'],
            ],
            'warning_prefix' => 'playbook_confrontation',
        ],
        'quick-decision' => [
            'playbook_id' => 'quick-decision',
            'expected_sections' => ['decision_framing', 'key_constraint', 'best_available_option', 'main_risk', 'immediate_next_action'],
            'required_outcomes' => ['decision_framing', 'key_constraint', 'best_available_option', 'main_risk', 'immediate_next_action'],
            'required_signals' => ['best_available_option', 'main_risk', 'immediate_next_action'],
            'validation_expectations' => [
                'decision_framing' => ['decision framing', 'frame', 'decision to make'],
                'key_constraint' => ['key constraint', 'constraint', 'limitation', 'deadline'],
                'best_available_option' => ['best available option', 'best option', 'recommended option', 'choose'],
                'main_risk' => ['main risk', 'biggest risk', 'primary risk'],
                'immediate_next_action' => ['immediate next action', 'next action', 'next step', 'do now'],
            ],
            'warning_prefix' => 'playbook_quick_decision',
        ],
    ];

    /** @var array<string, string> */
    private const MODE_TO_PLAYBOOK = [
        'stress-test' => 'stress-test',
        'jury' => 'jury',
        'confrontation' => 'confrontation',
        'quick-decision' => 'quick-decision',
    ];

    /** @var array<string, string> */
    private const MARKERS = [
        'playbook_id: founder-sprint' => 'founder-sprint',
        'playbook_id: ceo-challenge' => 'ceo-challenge',
        'founder interrogation context' => 'founder-sprint',
        'founder sprint' => 'founder-sprint',
        'ceo challenge' => 'ceo-challenge',
    ];

    private static bool $devValidated = false;

    public function __construct() {
        if (!self::$devValidated && !$this->isProduction()) {
            self::$devValidated = true;
            $diagnostics = self::validateRuntimeCatalog();
            if (!empty($diagnostics['warnings'])) {
                error_log('[PlaybookRuntime] ' . implode(' | ', $diagnostics['warnings']));
            }
        }
    }

    public static function contractFor(?string $playbookId): ?array {
        if ($playbookId === null || $playbookId === '') {
            return null;
        }
        return self::CONTRACTS[$playbookId] ?? null;
    }

    public static function allContracts(): array {
        return self::CONTRACTS;
    }

    public function resolvePlaybookId(?string $mode, array $sessionOptions = [], string $objective = ''): ?string {
        foreach (['playbook_id', 'selected_playbook_id', 'product_preset'] as $key) {
            $candidate = (string)($sessionOptions[$key] ?? '');
            if (isset(self::CONTRACTS[$candidate])) {
                return $candidate;
            }
        }

        $mode = (string)$mode;
        if (isset(self::MODE_TO_PLAYBOOK[$mode])) {
            return self::MODE_TO_PLAYBOOK[$mode];
        }

        $haystack = strtolower($objective);
        foreach (self::MARKERS as $marker => $playbookId) {
            if (str_contains($haystack, $marker)) {
                return $playbookId;
            }
        }

        return null;
    }

    public function buildPromptBlock(?string $playbookId, string $language = 'en'): string {
        $contract = self::contractFor($playbookId);
        if (!$contract) {
            return '';
        }

        $sections = implode(', ', $contract['expected_sections']);
        $signals  = implode(', ', $contract['required_signals']);

        if ($language === 'fr') {
            return "\n\n---\n\n## Attentes runtime du playbook\n"
                . "Playbook: {$contract['playbook_id']}\n"
                . "Utilise ces attentes comme checklist souple, pas comme formulaire rigide.\n"
                . "La reponse doit rester naturelle et argumentative, mais elle doit generalement couvrir: {$sections}.\n"
                . "Signaux decisionnels a rendre observables: {$signals}.\n"
                . "Si une information manque, nomme l'incertitude et propose le plus petit test ou seuil qui la rendrait decidable.\n";
        }

        return "\n\n---\n\n## Playbook runtime expectations\n"
            . "Playbook: {$contract['playbook_id']}\n"
            . "Use these expectations as a flexible checklist, not a rigid form.\n"
            . "Keep the answer natural and argumentative, while generally covering: {$sections}.\n"
            . "Decision signals to make observable: {$signals}.\n"
            . "If information is missing, name the uncertainty and propose the smallest test or threshold that would make it decidable.\n";
    }

    public function extractDiagnostics(string $content, ?string $playbookId): array {
        $contract = self::contractFor($playbookId);
        if (!$contract) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'taxonomy_version' => RuntimeContracts::TAXONOMY_VERSION,
                'playbook_id' => null,
                'sections_found' => [],
                'missing_sections' => [],
                'signals_found' => [],
                'warnings' => [],
                'completeness_score' => null,
            ];
        }

        $found = [];
        $lower = strtolower($content);
        foreach ($contract['validation_expectations'] as $section => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($lower, strtolower($alias))) {
                    $found[] = $section;
                    break;
                }
            }
        }

        $found = array_values(array_unique($found));
        $expected = $contract['expected_sections'];
        $missing = array_values(array_diff($expected, $found));
        $signals = array_values(array_intersect($contract['required_signals'], $found));
        $score = count($expected) > 0 ? round(count($found) / count($expected), 2) : 1.0;

        $warnings = [];
        foreach (array_diff($contract['required_signals'], $found) as $section) {
            $warnings[] = $contract['warning_prefix'] . '_missing_' . $section;
        }
        if ($score < 0.6) {
            $warnings[] = $contract['warning_prefix'] . '_low_completeness';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'taxonomy_version' => RuntimeContracts::TAXONOMY_VERSION,
            'playbook_id' => $contract['playbook_id'],
            'sections_found' => $found,
            'missing_sections' => $missing,
            'signals_found' => $signals,
            'warnings' => array_values(array_unique($warnings)),
            'completeness_score' => $score,
        ];
    }

    public static function validateRuntimeCatalog(): array {
        $warnings = [];
        $requiredKeys = ['playbook_id', 'expected_sections', 'required_outcomes', 'required_signals', 'validation_expectations'];

        foreach (self::CONTRACTS as $id => $contract) {
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $contract)) {
                    $warnings[] = "{$id}: missing runtime key {$key}";
                }
            }
            foreach (($contract['expected_sections'] ?? []) as $section) {
                if (empty($contract['validation_expectations'][$section])) {
                    $warnings[] = "{$id}: no observable aliases for {$section}";
                }
            }
            foreach (($contract['required_signals'] ?? []) as $signal) {
                if (!in_array($signal, $contract['expected_sections'] ?? [], true)) {
                    $warnings[] = "{$id}: required signal {$signal} is not an expected section";
                }
            }
        }

        foreach (self::MODE_TO_PLAYBOOK as $mode => $id) {
            if (!isset(self::CONTRACTS[$id])) {
                $warnings[] = "mode {$mode}: maps to unknown playbook {$id}";
            }
        }

        foreach (array_unique(array_values(self::MARKERS)) as $id) {
            if (!isset(self::CONTRACTS[$id])) {
                $warnings[] = "runtime marker maps to unknown playbook {$id}";
            }
        }

        return [
            'ok' => $warnings === [],
            'warnings' => $warnings,
            'playbook_count' => count(self::CONTRACTS),
        ];
    }

    private function isProduction(): bool {
        $env = strtolower((string)(getenv('APP_ENV') ?: getenv('ENV') ?: 'development'));
        return in_array($env, ['prod', 'production'], true);
    }
}

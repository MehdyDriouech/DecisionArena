<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

/**
 * Politique runtime des injections : interdits, expert-only, dérivés, non persistants.
 * Ne bloque pas silencieusement les appels existants : produit des avertissements pour la trace / gouvernance.
 */
final class PromptRuntimePolicy
{
    /**
     * @return list<string>
     */
    public static function forbiddenInjections(): array
    {
        return [
            'mutation_directe_beliefs_depuis_promptbuilder',
            'mutation_narrative_depuis_promptbuilder',
            'persistance_sqlite_depuis_promptbuilder',
            'écriture_memory_md_depuis_promptbuilder',
            'snapshot_cross_context_sans_uuid',
            'retrieval_global_decision_memories_sans_session',
        ];
    }

    /**
     * @return list<string> injection_key réservés aux flux expert / futurs garde-fous UI
     */
    public static function expertOnlyInjectionKeys(): array
    {
        return [
            'strategic_narrative_echo',
            'beliefs_echo',
            'beliefs_invalidated_optional',
            'compiled_memory_echo',
            'context_snapshot_echo',
            'workspace_timeline_snippet',
            'replay_builder_context',
        ];
    }

    /**
     * @return list<string>
     */
    public static function derivedNonCanonicalKeys(): array
    {
        return [
            'strategic_narrative_echo',
            'beliefs_prioritized',
            'beliefs_contested',
            'beliefs_fragile_assumptions',
            'beliefs_invalidated_optional',
            'compiled_memory_echo',
            'context_snapshot_echo',
            'workspace_timeline_snippet',
            'debate_argument_memory',
        ];
    }

    /**
     * @return list<string>
     */
    public static function nonPersistentVolatileKeys(): array
    {
        return [
            'session_objective',
            'session_history_rounds',
            'synthesizer_reliability_envelope',
            'context_document',
            'context_document_system',
        ];
    }

    /**
     * @return list<string>
     */
    public static function crossContextRules(): array
    {
        return [
            'Les clés context_scope=strategic_context exigent strategic_context_id non vide au moment de l’injection.',
            'supports_cross_context=false pour toutes les entrées du registre MVP : aucune injection implicite multi-contexte.',
            'Situated chat : chemins memory.md confinés à {strategic_context_id}/agents/{agent_id}/.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function governanceExport(): array
    {
        return [
            'schema_version' => '1.0.0',
            'forbidden_injections' => self::forbiddenInjections(),
            'expert_only_injection_keys' => self::expertOnlyInjectionKeys(),
            'derived_non_canonical_keys' => self::derivedNonCanonicalKeys(),
            'non_persistent_volatile_keys' => self::nonPersistentVolatileKeys(),
            'cross_context_rules' => self::crossContextRules(),
            'auditability' => [
                'Toute injection tracée doit référencer injection_key + block_id + context_scope.',
                'Toute exclusion doit porter exclusion_reason non vide dans PromptInjectionTraceCollector.',
            ],
        ];
    }

    /**
     * Avertissements non bloquants pour enrichir prompt_injection_trace (MVP).
     *
     * @param array<string, mixed> $beginContext contexte passé à PromptInjectionTraceCollector::begin
     * @return list<string>
     */
    public static function evaluateRuntimeWarnings(array $beginContext): array
    {
        $warnings = [];
        $ctxId = $beginContext['strategic_context_id'] ?? null;
        $ctxId = is_string($ctxId) ? trim($ctxId) : '';
        if ($ctxId === '') {
            $warnings[] = 'strategic_context_id_absent: les injections agent_context_memory et social_dynamics_context sont vides ou non situées — pas de cross-context implicite.';
        }
        $mode = (string)($beginContext['mode'] ?? '');
        if ($mode !== 'decision-room') {
            $warnings[] = 'mode_non_decision_room: le registre complet est aligné MVP sur la trace Decision Room.';
        }
        if (!empty($beginContext['inject_strategic_narrative']) && !empty($beginContext['inject_beliefs_runtime'])) {
            $warnings[] = 'narrative_beliefs_duplication_guard: éviter la double injection narrative + beliefs dans le même prompt.';
        }

        return $warnings;
    }
}

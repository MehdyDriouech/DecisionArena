<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

use Domain\Orchestration\CognitiveBudgetEngine;

/**
 * Couche officielle « Cognitive Governance » — catalogue déterministe, lecture seule.
 * Aucune mutation runtime : formalise invariants, ownership et règles d’isolation.
 */
final class CognitiveGovernanceCatalog
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'schema_version' => '1.0.0',
            'runtime'        => 'local_sqlite',
            'provenance_model' => CognitiveProvenanceEnvelope::governanceBundle(),
            'prompt_injection_registry' => PromptInjectionRegistry::governanceExport(),
            'prompt_runtime_policy' => PromptRuntimePolicy::governanceExport(),
            'cognitive_budget_engine' => CognitiveBudgetEngine::governanceExport(),
            'layers'         => self::layers(),
            'ownership_matrix' => self::ownershipMatrix(),
            'mutation_forbidden' => self::mutationForbidden(),
            'isolation_rules' => self::isolationRules(),
            'dependencies'   => self::dependencies(),
            'systemic_risks' => self::systemicRisks(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function layers(): array
    {
        return [
            [
                'id' => 'raw_events',
                'label' => 'Raw events',
                'role' => 'Traces primaires (messages, votes, verdicts, logs) issues des exécutions de session.',
                'persistence' => 'SQLite (sessions, messages, votes, debriefs, etc.)',
                'mutability' => 'append-mostly; pas de réécriture rétroactive des faits bruts.',
                'provenance' => 'Runners / contrôleurs HTTP; horodatage explicite.',
                'lifetime' => 'Longue; archivage par politique produit.',
                'confidence' => 'Factuel (niveau événement).',
            ],
            [
                'id' => 'agent_memory',
                'label' => 'Agent context memory',
                'role' => 'Mémoire markdown partitionnée par strategic_context_id + agent_id (contexte situé).',
                'persistence' => 'Fichiers / chemins gérés par AgentContextMemoryService (hors cœur narrative canonique).',
                'mutability' => 'Écriture contrôlée via API dédiée; jamais comme effet de bord des runners.',
                'provenance' => 'Utilisateur / outils explicites; pas d’auto-boucle opaque.',
                'lifetime' => 'Liée au cycle de vie du contexte + maintenance manuelle.',
                'confidence' => 'Heuristique / éditoriale par agent.',
            ],
            [
                'id' => 'beliefs',
                'label' => 'Beliefs',
                'role' => 'Croyances explicites par contexte (BeliefEngineService + persistance dédiée).',
                'persistence' => 'SQLite (beliefs scopés strategic_context_id).',
                'mutability' => 'Atomique, auditée; statuts (proposed, active, disputed…).',
                'provenance' => 'Création / mise à jour via API; traçabilité requise.',
                'lifetime' => 'Jusqu’à archive / dépréciation explicite.',
                'confidence' => 'Déclaratif humain ou pipeline explicite — pas de vérité implicite globale.',
            ],
            [
                'id' => 'narrative',
                'label' => 'Strategic narrative',
                'role' => 'Echo synthétique dérivé (StrategicNarrativeService) pour lecture; non canonique vs beliefs/memory.md.',
                'persistence' => 'SQLite (JSON par contexte).',
                'mutability' => 'Recomputable; remplaçable; jamais source unique de vérité.',
                'provenance' => 'Agrégats déterministes + heuristiques documentées.',
                'lifetime' => 'Versionnée par recompute explicite.',
                'confidence' => 'Dérivée / indicative.',
            ],
            [
                'id' => 'compiled_memory',
                'label' => 'Compiled memory',
                'role' => 'Consolidations MemoryCompilerService (markdown + snapshot JSON).',
                'persistence' => 'SQLite (compilations + audit trail).',
                'mutability' => 'Supersedable / archivable; pas de mutation directe de memory.md ou beliefs.',
                'provenance' => 'Action explicite « compiler »; pas d’auto-run obligatoire.',
                'lifetime' => 'Historisée (active → superseded / archived).',
                'confidence' => 'Dérivée; non autonome.',
            ],
            [
                'id' => 'snapshots',
                'label' => 'Context snapshots',
                'role' => 'Photographie cognitive immuable d’un strategic context à un instant T (ContextSnapshotService).',
                'persistence' => 'SQLite (strategic_context_snapshots).',
                'mutability' => 'INSERT only pour le corps; pas d’UPDATE du contenu snapshot (MVP).',
                'provenance' => 'source_summary + metadata + hash; pas de cross-context.',
                'lifetime' => 'Immuable; suppression seulement si suppression du contexte parent.',
                'confidence' => 'Figée; diffable.',
            ],
            [
                'id' => 'prompt_runtime',
                'label' => 'Prompt runtime state',
                'role' => 'Assemblage PromptBuilder + retrievals multi-sources pour une exécution; état volatile.',
                'persistence' => 'Aucune persistance implicite depuis PromptBuilder.',
                'mutability' => 'Par requête uniquement; pas d’écriture beliefs/narrative/memory.',
                'provenance' => 'Inputs session + contexte + policies; ordre déterministe requis.',
                'lifetime' => 'Durée de la requête / run.',
                'confidence' => 'Opérationnel; non stocké comme vérité.',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function ownershipMatrix(): array
    {
        return [
            [
                'system' => 'DecisionMemoryRepository / decision_memories',
                'source_of_truth' => 'Décision confirmée + résumés liés à session/contexte.',
                'writable' => true,
                'derived' => false,
                'immutable' => false,
                'notes' => 'Canon métier pour l’historique de décision lié; scoping par session / strategic_context_id.',
            ],
            [
                'system' => 'BeliefEngineService',
                'source_of_truth' => 'Beliefs explicites par contexte.',
                'writable' => true,
                'derived' => false,
                'immutable' => false,
                'notes' => 'Atomic + audit; pas de belief « global » implicite.',
            ],
            [
                'system' => 'StrategicNarrativeService',
                'source_of_truth' => 'Aucun — dérivé uniquement.',
                'writable' => true,
                'derived' => true,
                'immutable' => false,
                'notes' => 'Recomputable; ne doit pas écraser beliefs ni agent memory.',
            ],
            [
                'system' => 'MemoryCompilerService',
                'source_of_truth' => 'Aucun — dérivé consolidé.',
                'writable' => true,
                'derived' => true,
                'immutable' => false,
                'notes' => 'Supersedable; interdit de muter memory.md ou beliefs en coulisse.',
            ],
            [
                'system' => 'ContextSnapshotService',
                'source_of_truth' => 'Instant figé (preuve d’état).',
                'writable' => true,
                'derived' => true,
                'immutable' => true,
                'notes' => 'INSERT contenu; pas de mutation post-capture (MVP).',
            ],
            [
                'system' => 'AgentContextMemoryService',
                'source_of_truth' => 'Mémoire agent située par contexte.',
                'writable' => true,
                'derived' => false,
                'immutable' => false,
                'notes' => 'Lecture pour PromptBuilder; écritures via chemins explicites.',
            ],
            [
                'system' => 'PromptBuilder',
                'source_of_truth' => 'Aucun — assembleur.',
                'writable' => false,
                'derived' => true,
                'immutable' => false,
                'notes' => 'Ne persiste pas; ne doit pas devenir canal d’écriture cachée.',
            ],
            [
                'system' => 'SocialDynamicsService',
                'source_of_truth' => 'Métriques / événements relationnels persistés (couche social dynamique).',
                'writable' => true,
                'derived' => false,
                'immutable' => false,
                'notes' => 'Scoping session ou contexte selon API; pas de « consensus global » implicite.',
            ],
            [
                'system' => 'WorkspaceTimelineService',
                'source_of_truth' => 'Aucun — agrégat lecture seule.',
                'writable' => false,
                'derived' => true,
                'immutable' => false,
                'notes' => 'Vue matérialisée à la volée; déterministe.',
            ],
        ];
    }

    /** @return list<array<string, string>> */
    private static function mutationForbidden(): array
    {
        return [
            ['from' => 'Strategic narrative', 'to' => 'Beliefs', 'rule' => 'Interdit : la narrative ne crée ni ne met à jour de beliefs.'],
            ['from' => 'Context snapshots', 'to' => 'Beliefs / narrative / compilations', 'rule' => 'Interdit : snapshot immuable, aucune rétro-écriture des couches vivantes.'],
            ['from' => 'PromptBuilder', 'to' => 'Persistance SQLite / fichiers mémoire', 'rule' => 'Interdit : pas d’enregistrement silencieux depuis l’assemblage prompt.'],
            ['from' => 'Memory compilations', 'to' => 'Decision memory canonique / beliefs', 'rule' => 'Interdit : pas de mutation directe; audit trail uniquement.'],
            ['from' => 'Runners (DecisionRoom, Confrontation, Jury, …)', 'to' => 'Couches cognitives hors contrat', 'rule' => 'Interdit : pas de persistance cachée beliefs/narrative/compiler hors services prévus.'],
            ['from' => 'Timeline / diff compare', 'to' => 'État vivant', 'rule' => 'Lecture seule sauf endpoints explicitement prévus ailleurs.'],
        ];
    }

    /** @return list<string> */
    private static function isolationRules(): array
    {
        return [
            'Aucun cross-context implicite : strategic_context_id obligatoire pour beliefs, narrative, snapshots, timeline, agent memory paths.',
            'Aucun fallback silencieux « premier contexte trouvé » pour les retrievals gouvernés.',
            'Aucun retrieval global par défaut pour mémoire décisionnelle ou beliefs.',
            'Aucun belief global hors table scopée; pas de snapshot cross-context.',
            'Situated Agent Chat : injection limitée au contexte courant + agents autorisés.',
            'Workspace unique (is_workspace_active) : un seul espace actif pour éviter collisions d’intention.',
        ];
    }

    /** @return list<array<string, string>> */
    private static function dependencies(): array
    {
        return [
            ['upstream' => 'Raw events', 'downstream' => 'WorkspaceTimelineService', 'kind' => 'read'],
            ['upstream' => 'Decision memories + sessions', 'downstream' => 'StrategicNarrativeService', 'kind' => 'read → derive'],
            ['upstream' => 'Beliefs + memories + timeline', 'downstream' => 'ContextSnapshotService', 'kind' => 'read → freeze'],
            ['upstream' => 'Beliefs + narrative slices', 'downstream' => 'MemoryCompilerService', 'kind' => 'read → derive'],
            ['upstream' => 'Multi-source retrievals', 'downstream' => 'PromptBuilder', 'kind' => 'assemble (volatile)'],
            ['upstream' => 'AgentContextMemoryService', 'downstream' => 'PromptBuilder / runners', 'kind' => 'inject text'],
            ['upstream' => 'SocialDynamicsService', 'downstream' => 'PromptBuilder / guards', 'kind' => 'inject metrics'],
        ];
    }

    /** @return list<string> */
    private static function systemicRisks(): array
    {
        return [
            'Dérive cognitive si la narrative est traitée comme canon par l’UI ou des prompts implicites.',
            'Duplication mémoire si agent memory et decision memory divergent sans garde-fou de provenance.',
            'Faux consensus si SocialDynamics ou heatmaps sont sur-interprétés sans session scope.',
            'Boucles narratives si recompute narrative / compiler déclenchés en chaîne sans action humaine claire.',
            'Contamination cross-context si un UUID de contexte est omis dans une jointure ou un chemin fichier.',
            'Mémoire autonome cachée si un runner écrit hors des repositories listés au contrat produit.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

/**
 * Enveloppe standard de provenance cognitive (MVP local, SQLite + JSON, sans vector DB).
 * Toute valeur absente est normalisée explicitement (null / [] / false) pour auditabilité.
 */
final class CognitiveProvenanceEnvelope
{
    public const SCHEMA_VERSION = '1.0.0';

    /** @return array<string, mixed> */
    public static function governanceBundle(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'field_definitions' => self::fieldDefinitions(),
            'defaults' => self::normalize([]),
            'cognitive_kinds' => [
                'raw_fact' => 'Événement ou enregistrement primaire (ex. message, vote).',
                'belief' => 'Croyance explicite ; jamais équivalente à un fait sans validation.',
                'hypothesis' => 'Hypothèse déclarée.',
                'interpretation' => 'Interprétation déclarée.',
                'strategic_narrative_echo' => 'Agrégat dérivé non canonique.',
                'compiled_memory' => 'Consolidation dérivée, supersedable.',
                'context_snapshot_frozen' => 'Figé immuable (corps snapshot).',
                'prompt_runtime_trace' => 'Assemblage volatile ; non source de vérité.',
                'retrieval_trace' => 'Sélection / excerpts (ex. FTS) — traçabilité lecture.',
                'budget_arbitration' => 'Réservé Retrieval Budget Engine MVP (null si non actif).',
            ],
            'retrieval_budget_mvp' => [
                'status' => 'reserved',
                'note' => 'Les champs budget_policy, selected_sources, pruned_sources liés au budget sont préremplis à null tant que le moteur budgétaire n’est pas branché.',
            ],
        ];
    }

    /** @return list<array{id:string,type:string,required:bool,description:string}> */
    private static function fieldDefinitions(): array
    {
        return [
            ['id' => 'cognitive_kind', 'type' => 'string', 'required' => true, 'description' => 'Nature sémantique de l’artefact pour l’audit.'],
            ['id' => 'source_type', 'type' => 'string|null', 'required' => false, 'description' => 'Type de source primaire quand applicable (ex. manual, session, memory).'],
            ['id' => 'source_reference_id', 'type' => 'string|null', 'required' => false, 'description' => 'Identifiant stable de la source primaire.'],
            ['id' => 'source_context_id', 'type' => 'string|null', 'required' => false, 'description' => 'strategic_context_id de rattachement obligatoire pour couches contextuelles.'],
            ['id' => 'derived_from', 'type' => 'list<string>', 'required' => false, 'description' => 'Services ou tables ayant alimenté la dérivation.'],
            ['id' => 'derivation_strategy', 'type' => 'string|null', 'required' => false, 'description' => 'Identifiant versionné de la stratégie déterministe.'],
            ['id' => 'derivation_version', 'type' => 'string|null', 'required' => false, 'description' => 'Version du pipeline.'],
            ['id' => 'computed_by', 'type' => 'string|null', 'required' => false, 'description' => 'Classe::méthode ou service auteur.'],
            ['id' => 'deterministic', 'type' => 'bool', 'required' => true, 'description' => 'true si aucune génératrice LLM obligatoire dans la chaîne.'],
            ['id' => 'generated_at', 'type' => 'string|null', 'required' => false, 'description' => 'Horodatage ISO8601.'],
            ['id' => 'confidence_method', 'type' => 'string|null', 'required' => false, 'description' => 'Heuristique / déclaratif / agrégat.'],
            ['id' => 'source_count', 'type' => 'int|null', 'required' => false, 'description' => 'Nombre de sources atomiques consommées.'],
            ['id' => 'retrieval_scope', 'type' => 'string|null', 'required' => false, 'description' => 'session|strategic_context|none.'],
            ['id' => 'transformation_steps', 'type' => 'list<string>', 'required' => false, 'description' => 'Étapes ordonnées de transformation.'],
            ['id' => 'input_hashes', 'type' => 'list<string>', 'required' => false, 'description' => 'Empreintes d’entrées stables (ex. md5).'],
            ['id' => 'input_hash', 'type' => 'string|null', 'required' => false, 'description' => 'Empreinte SHA-256 agrégée des entrées.'],
            ['id' => 'source_hash', 'type' => 'string|null', 'required' => false, 'description' => 'Empreinte SHA-256 des sources sélectionnées.'],
            ['id' => 'runtime_hash', 'type' => 'string|null', 'required' => false, 'description' => 'Empreinte SHA-256 de l’artefact runtime calculé.'],
            ['id' => 'snapshot_hash', 'type' => 'string|null', 'required' => false, 'description' => 'Empreinte SHA-256 snapshot persisté.'],
            ['id' => 'compilation_hash', 'type' => 'string|null', 'required' => false, 'description' => 'Empreinte SHA-256 de compilation dérivée.'],
            ['id' => 'provenance_fingerprint', 'type' => 'string|null', 'required' => false, 'description' => 'Fingerprint déterministe de l’enveloppe provenance.'],
            ['id' => 'budget_policy', 'type' => 'string|null', 'required' => false, 'description' => 'Politique budget cognitif (MVP réservé).'],
            ['id' => 'selected_sources', 'type' => 'list<string>', 'required' => false, 'description' => 'Identifiants ou labels des sources retenues.'],
            ['id' => 'pruned_sources', 'type' => 'list<string>', 'required' => false, 'description' => 'Sources compactées ou tronquées.'],
            ['id' => 'excluded_sources', 'type' => 'list<string>', 'required' => false, 'description' => 'Sources écartées avec motif.'],
            ['id' => 'recompute_reason', 'type' => 'string|null', 'required' => false, 'description' => 'Motif explicite de recalcul utilisateur / batch.'],
        ];
    }

    /**
     * @param array<string, mixed> $partial
     * @return array<string, mixed>
     */
    public static function normalize(array $partial): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'cognitive_kind' => null,
            'source_type' => null,
            'source_reference_id' => null,
            'source_context_id' => null,
            'derived_from' => [],
            'derivation_strategy' => null,
            'derivation_version' => null,
            'computed_by' => null,
            'deterministic' => false,
            'generated_at' => null,
            'confidence_method' => null,
            'source_count' => null,
            'retrieval_scope' => null,
            'transformation_steps' => [],
            'input_hashes' => [],
            'input_hash' => null,
            'source_hash' => null,
            'runtime_hash' => null,
            'snapshot_hash' => null,
            'compilation_hash' => null,
            'provenance_fingerprint' => null,
            'budget_policy' => null,
            'selected_sources' => [],
            'pruned_sources' => [],
            'excluded_sources' => [],
            'recompute_reason' => null,
        ];
        foreach ($partial as $k => $v) {
            if (!array_key_exists($k, $base)) {
                continue;
            }
            if ($k === 'derived_from' || $k === 'transformation_steps' || $k === 'input_hashes'
                || $k === 'selected_sources' || $k === 'pruned_sources' || $k === 'excluded_sources') {
                $base[$k] = is_array($v) ? array_values(array_filter(array_map('strval', $v))) : [];
                continue;
            }
            if ($k === 'deterministic') {
                $base[$k] = (bool)$v;
                continue;
            }
            if ($k === 'source_count') {
                $base[$k] = is_int($v) ? $v : (is_numeric($v) ? (int)$v : null);
                continue;
            }
            if ($v === null || $v === '') {
                $base[$k] = in_array($k, ['source_type', 'source_reference_id', 'source_context_id', 'derivation_strategy', 'derivation_version', 'computed_by', 'generated_at', 'confidence_method', 'retrieval_scope', 'recompute_reason', 'cognitive_kind', 'input_hash', 'source_hash', 'runtime_hash', 'snapshot_hash', 'compilation_hash', 'provenance_fingerprint'], true)
                    ? null
                    : $base[$k];
                continue;
            }
            $base[$k] = is_string($v) || is_int($v) || is_float($v) || is_bool($v) ? $v : $base[$k];
        }
        if ($base['input_hash'] === null && $base['input_hashes'] !== []) {
            $base['input_hash'] = DeterministicHash::sha256($base['input_hashes']);
        }
        if ($base['source_hash'] === null && ($base['selected_sources'] !== [] || $base['excluded_sources'] !== [] || $base['pruned_sources'] !== [])) {
            $base['source_hash'] = DeterministicHash::sha256([
                'selected_sources' => $base['selected_sources'],
                'pruned_sources' => $base['pruned_sources'],
                'excluded_sources' => $base['excluded_sources'],
                'source_count' => $base['source_count'],
            ]);
        }
        if ($base['runtime_hash'] === null) {
            $base['runtime_hash'] = DeterministicHash::sha256([
                'cognitive_kind' => $base['cognitive_kind'],
                'source_context_id' => $base['source_context_id'],
                'derived_from' => $base['derived_from'],
                'derivation_strategy' => $base['derivation_strategy'],
                'derivation_version' => $base['derivation_version'],
                'input_hash' => $base['input_hash'],
                'source_hash' => $base['source_hash'],
                'deterministic' => $base['deterministic'],
            ]);
        }
        $base['provenance_fingerprint'] = DeterministicHash::sha256([
            'schema_version' => self::SCHEMA_VERSION,
            'cognitive_kind' => $base['cognitive_kind'],
            'source_type' => $base['source_type'],
            'source_reference_id' => $base['source_reference_id'],
            'source_context_id' => $base['source_context_id'],
            'derived_from' => $base['derived_from'],
            'derivation_strategy' => $base['derivation_strategy'],
            'derivation_version' => $base['derivation_version'],
            'computed_by' => $base['computed_by'],
            'deterministic' => $base['deterministic'],
            'input_hash' => $base['input_hash'],
            'source_hash' => $base['source_hash'],
            'runtime_hash' => $base['runtime_hash'],
            'snapshot_hash' => $base['snapshot_hash'],
            'compilation_hash' => $base['compilation_hash'],
            'selected_sources' => $base['selected_sources'],
            'pruned_sources' => $base['pruned_sources'],
            'excluded_sources' => $base['excluded_sources'],
        ]);
        if (($base['schema_version'] ?? '') === '') {
            $base['schema_version'] = self::SCHEMA_VERSION;
        }

        return $base;
    }

    /** @param array<string, mixed> $row ligne belief SQLite/API interne */
    public static function forBeliefRecord(array $row): array
    {
        $ctx = (string)($row['strategic_context_id'] ?? '');
        $bt = strtolower(trim((string)($row['belief_type'] ?? 'belief')));
        $srcType = $row['source_type'] ?? null;
        $srcType = $srcType !== null && trim((string)$srcType) !== '' ? trim((string)$srcType) : null;
        $srcRef = $row['source_reference_id'] ?? null;
        $srcRef = $srcRef !== null && trim((string)$srcRef) !== '' ? trim((string)$srcRef) : null;

        $inputHash = DeterministicHash::sha256([
            'belief_text' => (string)($row['belief_text'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'confidence' => (float)($row['confidence'] ?? 0.5),
            'contestation_state' => (string)($row['contestation_state'] ?? ''),
            'source_type' => $srcType,
            'source_reference_id' => $srcRef,
        ]);

        return self::normalize([
            'cognitive_kind' => 'belief_engine:' . ($bt !== '' ? $bt : 'belief'),
            'source_type' => $srcType,
            'source_reference_id' => $srcRef,
            'source_context_id' => $ctx !== '' ? $ctx : null,
            'derived_from' => ['strategic_context_beliefs'],
            'derivation_strategy' => 'explicit_row',
            'derivation_version' => 'beliefs-mvp-1',
            'computed_by' => 'BeliefEngineService::toApiBelief',
            'deterministic' => true,
            'generated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'confidence_method' => 'declarative_row_confidence',
            'source_count' => 1,
            'retrieval_scope' => 'strategic_context',
            'transformation_steps' => ['sqlite_read', 'api_map'],
            'input_hash' => $inputHash,
        ]);
    }

    /**
     * @param list<string> $derivedFrom
     * @param array<string, mixed> $extra counts, samples, etc.
     */
    public static function forStrategicNarrative(string $contextId, array $derivedFrom, array $extra = []): array
    {
        $steps = [
            'WorkspaceTimelineService::build',
            'StrategicContextRepository::currentState',
            'DecisionMemoryRepository::findLinkedMemoriesForStrategicContext',
            'sessions.decision_brief_completed',
            'postmortem_rerun_verdict_counts',
            'BeliefEngineService::listForNarrativeEnrichment_readonly',
        ];
        $merged = array_values(array_unique(array_merge($steps, $derivedFrom)));

        $inputHashes = isset($extra['input_hashes']) && is_array($extra['input_hashes'])
            ? array_map('strval', $extra['input_hashes'])
            : [];
        if ($inputHashes === []) {
            $inputHashes[] = DeterministicHash::sha256([
                'context_id' => $contextId,
                'derived_from' => $merged,
                'selected_sources' => $extra['selected_sources'] ?? [],
                'pruned_sources' => $extra['pruned_sources'] ?? [],
                'excluded_sources' => $extra['excluded_sources'] ?? [],
            ]);
        }

        return self::normalize([
            'cognitive_kind' => 'strategic_narrative_echo',
            'source_context_id' => $contextId,
            'derived_from' => $merged,
            'derivation_strategy' => 'deterministic_workspace_aggregator',
            'derivation_version' => 'narrative-mvp-1',
            'computed_by' => 'StrategicNarrativeService::buildNarrative',
            'deterministic' => true,
            'generated_at' => (string)($extra['computed_at'] ?? ''),
            'confidence_method' => 'derived_heuristic_non_canonical',
            'source_count' => isset($extra['source_count']) ? (int)$extra['source_count'] : null,
            'retrieval_scope' => 'strategic_context',
            'recompute_reason' => isset($extra['recompute_reason']) ? (string)$extra['recompute_reason'] : null,
            'selected_sources' => isset($extra['selected_sources']) && is_array($extra['selected_sources'])
                ? array_map('strval', $extra['selected_sources']) : [],
            'pruned_sources' => isset($extra['pruned_sources']) && is_array($extra['pruned_sources'])
                ? array_map('strval', $extra['pruned_sources']) : [],
            'excluded_sources' => isset($extra['excluded_sources']) && is_array($extra['excluded_sources'])
                ? array_map('strval', $extra['excluded_sources']) : [],
            'input_hashes' => $inputHashes,
            'source_hash' => isset($extra['source_hash']) ? (string)$extra['source_hash'] : null,
            'runtime_hash' => isset($extra['runtime_hash']) ? (string)$extra['runtime_hash'] : null,
        ]);
    }

    /**
     * @param array<string, mixed> $compileMeta champs optionnels : pipeline_version, generated_at, source_count, pruned_sources, …
     */
    public static function forMemoryCompilation(string $contextId, string $compilationType, array $compileMeta = []): array
    {
        return self::normalize([
            'cognitive_kind' => 'compiled_memory',
            'source_context_id' => $contextId,
            'derived_from' => [
                'MemoryCompilerService::gatherSnapshot',
                'MemoryCompilerService::classifyBeliefs',
                'MemoryCompilerService::extractPatterns',
                'MemoryCompilerService::buildMarkdownAndMeta',
            ],
            'derivation_strategy' => 'memory_compiler_' . $compilationType,
            'derivation_version' => (string)($compileMeta['pipeline_version'] ?? 'memory-compiler-mvp-1'),
            'computed_by' => 'MemoryCompilerService::compile',
            'deterministic' => true,
            'generated_at' => (string)($compileMeta['generated_at'] ?? date('c')),
            'confidence_method' => 'deterministic_scoring',
            'retrieval_scope' => 'strategic_context',
            'transformation_steps' => ['gather', 'classify', 'extract', 'markdown', 'redact_snapshot_for_storage'],
            'source_count' => isset($compileMeta['source_count']) ? (int)$compileMeta['source_count'] : null,
            'selected_sources' => isset($compileMeta['selected_sources']) && is_array($compileMeta['selected_sources'])
                ? array_map('strval', $compileMeta['selected_sources']) : [],
            'pruned_sources' => isset($compileMeta['pruned_sources']) && is_array($compileMeta['pruned_sources'])
                ? array_map('strval', $compileMeta['pruned_sources']) : [],
            'excluded_sources' => isset($compileMeta['excluded_sources']) && is_array($compileMeta['excluded_sources'])
                ? array_map('strval', $compileMeta['excluded_sources']) : [],
            'input_hashes' => isset($compileMeta['input_hashes']) && is_array($compileMeta['input_hashes'])
                ? array_map('strval', $compileMeta['input_hashes']) : [],
            'source_hash' => isset($compileMeta['source_hash']) ? (string)$compileMeta['source_hash'] : null,
            'compilation_hash' => isset($compileMeta['compilation_hash']) ? (string)$compileMeta['compilation_hash'] : null,
        ]);
    }

    /** @param array<string, mixed> $summary résumé comptages snapshot */
    public static function forContextSnapshot(string $contextId, string $snapshotType, array $summary = []): array
    {
        return self::normalize([
            'cognitive_kind' => 'context_snapshot_frozen',
            'source_context_id' => $contextId,
            'source_type' => 'snapshot',
            'source_reference_id' => null,
            'derived_from' => [
                'ContextSnapshotService::buildSnapshotPayload',
                'BeliefEngineService',
                'StrategicNarrativeService',
                'WorkspaceTimelineService',
                'MemoryCompilerService::listCompilations',
                'DecisionMemoryRepository',
                'SocialPromptContextBuilder',
            ],
            'derivation_strategy' => 'context_snapshot_mvp',
            'derivation_version' => 'snapshots-mvp-1',
            'computed_by' => 'ContextSnapshotService::createSnapshot',
            'deterministic' => true,
            'generated_at' => (string)($summary['created_at'] ?? date('c')),
            'confidence_method' => 'frozen_aggregate',
            'retrieval_scope' => 'strategic_context',
            'source_count' => isset($summary['total_sources']) ? (int)$summary['total_sources'] : null,
            'selected_sources' => isset($summary['selected_labels']) && is_array($summary['selected_labels'])
                ? array_map('strval', $summary['selected_labels']) : [],
            'pruned_sources' => isset($summary['pruned_labels']) && is_array($summary['pruned_labels'])
                ? array_map('strval', $summary['pruned_labels']) : [],
            'input_hashes' => isset($summary['input_hashes']) && is_array($summary['input_hashes'])
                ? array_map('strval', $summary['input_hashes']) : [],
            'source_hash' => isset($summary['source_hash']) ? (string)$summary['source_hash'] : null,
            'runtime_hash' => isset($summary['runtime_hash']) ? (string)$summary['runtime_hash'] : null,
            'snapshot_hash' => isset($summary['snapshot_hash']) ? (string)$summary['snapshot_hash'] : null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Domain\Orchestration;

use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Domain\CognitiveGovernance\CognitiveRuntimeQAMode;
use Domain\CognitiveGovernance\DeterministicHash;
use Domain\CognitiveGovernance\PromptInjectionProvenance;
use Domain\CognitiveGovernance\PromptInjectionRegistry;
use Domain\CognitiveGovernance\RuntimePromptGuard;
use Domain\CognitiveGovernance\PromptRuntimePolicy;

/**
 * Collecte optionnelle des blocs injectés (Decision Room MVP+).
 * Compatible registre : métadonnées PromptInjectionRegistry fusionnées par étape.
 * Déduplication : jamais silencieuse — étape `skipped` + entrée dans deduplication_events.
 */
final class PromptInjectionTraceCollector
{
    /** @var null|array{context:array<string,mixed>,steps:list<array<string,mixed>>} */
    private static ?array $state = null;

    /** @var array<string, string> dedup_key => sha256 */
    private static array $dedupFingerprints = [];

    /** @var list<array<string, mixed>> */
    private static array $dedupEvents = [];

    /** @param array<string, mixed> $context session_id, strategic_context_id, round, agent_id, mode */
    public static function begin(array $context): void
    {
        self::$dedupFingerprints = [];
        self::$dedupEvents = [];
        CognitiveBudgetEngine::begin($context);
        self::$state = [
            'context' => $context,
            'steps' => [],
        ];
    }

    public static function active(): bool
    {
        return self::$state !== null;
    }

    /**
     * Même contenu et même dedup_key qu’une injection déjà enregistrée : exclusion tracée, ne pas réinjecter.
     */
    public static function isDuplicateSegment(?string $dedupKey, string $segment, string $blockId, string $layer): bool
    {
        if ($dedupKey === null || $dedupKey === '' || !self::active() || $segment === '') {
            return false;
        }
        $h = hash('sha256', $segment);
        if (isset(self::$dedupFingerprints[$dedupKey]) && self::$dedupFingerprints[$dedupKey] === $h) {
            self::addStep(
                $blockId . '_dedup_omitted',
                $layer,
                0,
                'skipped',
                'duplicate_identical_segment_same_dedup_key',
                [
                    'deduplication_key' => $dedupKey,
                    'content_hash_prefix' => substr($h, 0, 16),
                    'status' => 'deduplicated',
                ]
            );
            self::$dedupEvents[] = [
                'type' => 'duplicate_skipped',
                'deduplication_key' => $dedupKey,
                'block_id' => $blockId,
                'content_hash_prefix' => substr($h, 0, 16),
            ];

            return true;
        }

        return false;
    }

    public static function recordSegmentFingerprint(?string $dedupKey, string $segment): void
    {
        if ($dedupKey === null || $dedupKey === '' || !self::active() || $segment === '') {
            return;
        }
        self::$dedupFingerprints[$dedupKey] = hash('sha256', $segment);
    }

    /**
     * @param array<string, mixed> $extra priority, relevance_score, budget_consumed, refused_chars, pruning_decision, fallback_decision, cognitive_provenance_hint, etc.
     * @param string|null $injectedUtf8ForContentHash corps **après** dédup/budget, aligné sur injectedChars ; auto-remplit `content_hash` si absent
     */
    public static function addStep(
        string $blockId,
        string $cognitiveLayer,
        int $injectedChars,
        string $inclusionReason,
        ?string $exclusionReason = null,
        array $extra = [],
        ?string $injectedUtf8ForContentHash = null
    ): void {
        if (self::$state === null) {
            return;
        }
        $order = count(self::$state['steps']) + 1;
        $regDef = PromptInjectionRegistry::definitionForBlockId($blockId);
        $extraForGuard = $extra;
        $chars = max(0, $injectedChars);
        if ($chars > 0
            && !isset($extraForGuard['content_hash'])
            && $injectedUtf8ForContentHash !== null
        ) {
            $extraForGuard['content_hash'] = PromptInjectionProvenance::computeInjectedContentHash($injectedUtf8ForContentHash);
        }
        RuntimePromptGuard::inspectStep($blockId, $regDef, $chars, $inclusionReason, $extraForGuard);
        $registryFields = is_array($regDef) ? PromptInjectionRegistry::traceFieldsFromDefinition($regDef) : [
            'injection_key' => PromptInjectionRegistry::injectionKeyForBlockId($blockId),
        ];
        $refused = (int)($extraForGuard['refused_chars'] ?? 0);
        $budgetSoftRemaining = null;
        if (is_array($regDef) && isset($regDef['soft_budget']) && $regDef['soft_budget'] !== null) {
            $budgetSoftRemaining = max(0, (int)$regDef['soft_budget'] - max(0, $injectedChars));
        }
        $hint = isset($extraForGuard['cognitive_provenance_hint']) && is_array($extraForGuard['cognitive_provenance_hint'])
            ? $extraForGuard['cognitive_provenance_hint'] : null;
        $extraClean = $extraForGuard;
        unset(
            $extraClean['cognitive_provenance_hint'],
            $extraClean['pruning_decision'],
            $extraClean['fallback_decision'],
            $extraClean['score_priority'],
            $extraClean['refused_chars'],
            $extraClean['budget_soft_remaining']
        );
        $step = array_merge(
            [
                'order' => $order,
                'block_id' => $blockId,
                'cognitive_layer' => $cognitiveLayer,
                'injected_chars' => max(0, $injectedChars),
                'refused_chars' => $refused,
                'inclusion_reason' => $inclusionReason,
                'exclusion_reason' => $exclusionReason,
                'pruning_decision' => $extraForGuard['pruning_decision'] ?? null,
                'fallback_decision' => $extraForGuard['fallback_decision'] ?? null,
                'score_priority' => $extraForGuard['score_priority'] ?? ($registryFields['priority'] ?? null),
                'budget_soft_remaining' => $extraForGuard['budget_soft_remaining'] ?? $budgetSoftRemaining,
            ],
            $registryFields,
            $extraClean
        );
        if ($hint !== null) {
            $step['cognitive_provenance_hint'] = $hint;
        }
        self::$state['steps'][] = $step;
    }

    /** @return array<string, mixed>|null */
    public static function finish(): ?array
    {
        if (self::$state === null) {
            return null;
        }
        $ctx = self::$state['context'];
        $steps = self::$state['steps'];
        self::$state = null;
        $totalChars = 0;
        foreach ($steps as $st) {
            $totalChars += (int)($st['injected_chars'] ?? 0);
        }
        $dedupEvents = self::$dedupEvents;
        self::$dedupEvents = [];
        self::$dedupFingerprints = [];
        $cognitiveBudget = CognitiveBudgetEngine::finish();
        $sc = isset($ctx['strategic_context_id']) && is_string($ctx['strategic_context_id'])
            ? trim($ctx['strategic_context_id']) : null;
        $sc = $sc !== '' ? $sc : null;

        $mode = (string)($ctx['mode'] ?? 'decision-room');
        $runnerByMode = [
            'decision-room' => 'DecisionRoomRunner',
            'quick-decision' => 'QuickDecisionRunner',
            'stress-test' => 'StressTestRunner',
            'jury' => 'JuryRunner',
            'confrontation' => 'ConfrontationRunner',
            'chat' => 'ChatRunner',
            'reactive-chat' => 'ReactiveChatRunner',
        ];
        $runnerName = $runnerByMode[$mode] ?? 'UnknownRunner';
        $runtimeWarnings = PromptRuntimePolicy::evaluateRuntimeWarnings($ctx);
        RuntimePromptGuard::inspectPolicyWarnings($ctx, $runtimeWarnings);
        $traceInputHash = DeterministicHash::sha256([
            'mode' => $mode,
            'session_id' => $ctx['session_id'] ?? null,
            'strategic_context_id' => $sc,
            'round' => $ctx['round'] ?? null,
            'agent_id' => $ctx['agent_id'] ?? null,
            'steps' => array_map(static function (array $step): array {
                return [
                    'block_id' => $step['block_id'] ?? null,
                    'injection_key' => $step['injection_key'] ?? null,
                    'injected_chars' => $step['injected_chars'] ?? 0,
                    'refused_chars' => $step['refused_chars'] ?? 0,
                    'inclusion_reason' => $step['inclusion_reason'] ?? null,
                    'exclusion_reason' => $step['exclusion_reason'] ?? null,
                    'content_hash' => $step['content_hash'] ?? null,
                    'input_hash' => $step['input_hash'] ?? null,
                ];
            }, $steps),
        ]);
        $traceRuntimeHash = DeterministicHash::sha256([
            'schema_version' => '1.3.0',
            'mode' => $mode,
            'session_id' => $ctx['session_id'] ?? null,
            'strategic_context_id' => $sc,
            'steps' => $steps,
            'deduplication_events' => $dedupEvents,
            'cognitive_budget' => $cognitiveBudget,
            'runtime_warnings' => $runtimeWarnings,
        ]);
        $traceSourceHash = DeterministicHash::sha256([
            'mode' => $mode,
            'steps' => array_map(static function (array $step): array {
                return [
                    'injection_key' => $step['injection_key'] ?? null,
                    'block_id' => $step['block_id'] ?? null,
                    'content_hash' => $step['content_hash'] ?? null,
                    'injected_chars' => $step['injected_chars'] ?? 0,
                ];
            }, $steps),
        ]);

        return [
            'schema_version' => '1.3.0',
            'kind' => 'prompt_runtime_injection',
            'mode' => $mode,
            'session_id' => $ctx['session_id'] ?? null,
            'strategic_context_id' => $sc,
            'round' => $ctx['round'] ?? null,
            'agent_id' => $ctx['agent_id'] ?? null,
            'deterministic' => true,
            'computed_by' => 'PromptInjectionTraceCollector::finish',
            'qa_mode' => CognitiveRuntimeQAMode::current(),
            'prompt_injection_registry_version' => PromptInjectionRegistry::SCHEMA_VERSION,
            'official_order_decision_room_user' => PromptInjectionRegistry::OFFICIAL_ORDER_DECISION_ROOM_USER,
            'official_order_system' => PromptInjectionRegistry::OFFICIAL_ORDER_SYSTEM,
            'runtime_policy_warnings' => $runtimeWarnings,
            'runtime_warnings' => $runtimeWarnings,
            'input_hash' => $traceInputHash,
            'source_hash' => $traceSourceHash,
            'runtime_hash' => $traceRuntimeHash,
            'replay_fingerprint' => DeterministicHash::sha256([
                'mode' => $mode,
                'session_id' => $ctx['session_id'] ?? null,
                'round' => $ctx['round'] ?? null,
                'agent_id' => $ctx['agent_id'] ?? null,
                'runtime_hash' => $traceRuntimeHash,
            ]),
            'deduplication_events' => $dedupEvents,
            'cognitive_budget' => $cognitiveBudget,
            'cognitive_runtime' => [
                'mode' => $mode,
                'deterministic' => true,
                'policy_engine' => 'PromptRuntimePolicy',
                'budget_engine' => 'CognitiveBudgetEngine',
                'trace_collector' => 'PromptInjectionTraceCollector',
                'registry_version' => PromptInjectionRegistry::SCHEMA_VERSION,
                'pruning_events' => $cognitiveBudget['pruning_events'] ?? [],
                'cache_scope_sha256_prefix' => $cognitiveBudget['cache_scope_sha256_prefix'] ?? null,
            ],
            'cognitive_provenance' => CognitiveProvenanceEnvelope::normalize([
                'cognitive_kind' => 'prompt_runtime_trace',
                'source_context_id' => $sc,
                'derived_from' => ['PromptInjectionRegistry', 'PromptBuilder', $runnerName],
                'derivation_strategy' => 'prompt_injection_registry_trace_v1',
                'derivation_version' => PromptInjectionRegistry::SCHEMA_VERSION,
                'computed_by' => 'PromptInjectionTraceCollector::finish',
                'deterministic' => true,
                'retrieval_scope' => $sc !== null ? 'strategic_context' : 'session',
                'input_hash' => $traceInputHash,
                'source_hash' => $traceSourceHash,
                'runtime_hash' => $traceRuntimeHash,
            ]),
            'steps' => $steps,
            'total_injected_chars_user_message' => $totalChars,
            'notes' => [
                'Les étapes reflètent l’assemblage runtime du bloc utilisateur ; le system prompt peut rester hors trace fine selon le runner.',
                'Les exclusions FTS / context doc détaillées restent dans les métadonnées du logger prompt quand présentes.',
                'schema_version 1.3.0 ajoute input_hash/runtime_hash/replay_fingerprint pour audit/replay ; les clients antérieurs peuvent ignorer les clés inconnues.',
            ],
        ];
    }

    public static function cancel(): void
    {
        self::$state = null;
        self::$dedupFingerprints = [];
        self::$dedupEvents = [];
        CognitiveBudgetEngine::cancel();
    }
}

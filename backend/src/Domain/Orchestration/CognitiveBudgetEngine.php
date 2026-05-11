<?php

declare(strict_types=1);

namespace Domain\Orchestration;

use Domain\CognitiveGovernance\PromptInjectionRegistry;

/**
 * Moteur d’arbitrage cognitif runtime MVP — Decision Room / message utilisateur.
 * Déterministe, local, sans LLM, sans embeddings, sans vector DB.
 * Ne persiste rien, ne mute aucune donnée métier (beliefs, narrative, SQLite, memory.md).
 *
 * Applique soft_budget / hard_budget / max_chars issus de PromptInjectionRegistry + plafond global utilisateur.
 */
final class CognitiveBudgetEngine
{
    public const SCHEMA_VERSION = '1.0.0-mvp';

    /** Plafond dur total UTF-8 pour le message utilisateur Decision Room (tous segments budgétés). */
    public const GLOBAL_USER_MESSAGE_HARD_CAP = 72000;

    /** Seuil soft (signalement sans troncature obligatoire si sous le hard). */
    public const GLOBAL_USER_MESSAGE_SOFT_CAP = 48000;

    private static bool $active = false;

    /** @var array<string, mixed> */
    private static array $context = [];

    private static int $globalConsumed = 0;

    /** @var array<string, int> injection_key => chars injectés */
    private static array $consumedPerKey = [];

    /** @var list<array<string, mixed>> */
    private static array $pruningEvents = [];

    /** @var array<string, mixed>|null cache définitions registre */
    private static ?array $definitionsByKey = null;

    /** @var array<string, mixed> cache retrieval request-scoped (clé = scope + sous-clé) */
    private static array $retrievalCache = [];

    public static function begin(array $context): void
    {
        self::$active = true;
        self::$context = $context;
        self::$globalConsumed = 0;
        self::$consumedPerKey = [];
        self::$pruningEvents = [];
        self::$definitionsByKey = PromptInjectionRegistry::definitionsByKey();
        self::$retrievalCache = [];
    }

    public static function active(): bool
    {
        return self::$active;
    }

    public static function cancel(): void
    {
        self::$active = false;
        self::$context = [];
        self::$globalConsumed = 0;
        self::$consumedPerKey = [];
        self::$pruningEvents = [];
        self::$definitionsByKey = null;
        self::$retrievalCache = [];
    }

    /**
     * @template T
     * @param callable():T $producer
     * @return T
     */
    public static function cachedRetrieval(string $localKey, callable $producer): mixed
    {
        if (!self::$active) {
            return $producer();
        }
        $scope = self::cacheScopePrefix();
        $fullKey = $scope . '|' . $localKey;
        if (array_key_exists($fullKey, self::$retrievalCache)) {
            return self::$retrievalCache[$fullKey];
        }
        $v = $producer();
        self::$retrievalCache[$fullKey] = $v;

        return $v;
    }

    public static function cacheScopePrefix(): string
    {
        $sid = isset(self::$context['session_id']) ? (string)self::$context['session_id'] : '';
        $cid = isset(self::$context['strategic_context_id']) && is_string(self::$context['strategic_context_id'])
            ? trim(self::$context['strategic_context_id']) : '';

        return hash('sha256', $sid . "\n" . $cid);
    }

    /**
     * Applique caps registre + global ; troncature dure explicite si nécessaire.
     *
     * @return array{
     *   content:string,
     *   original_chars:int,
     *   injected_chars:int,
     *   refused_chars:int,
     *   pruning_decision:string,
     *   fallback_policy:string,
     *   score_breakdown:list<string>,
     *   budget_layer:string,
     *   chars_budget_allowed:int,
     *   soft_budget:int|null,
     *   hard_budget:int|null
     * }
     */
    public static function applySegment(string $blockId, string $segment): array
    {
        $origLen = mb_strlen($segment, 'UTF-8');
        if (!self::$active) {
            return self::passthrough($segment, $origLen, $blockId, 'engine_inactive');
        }

        $injKey = PromptInjectionRegistry::injectionKeyForBlockId($blockId);
        $def = self::$definitionsByKey !== null ? (self::$definitionsByKey[$injKey] ?? null) : null;
        $hardCap = self::resolveHardCap($def);
        $softCap = self::resolveSoftCap($def, $hardCap);

        $keyUsed = self::$consumedPerKey[$injKey] ?? 0;
        $remainKey = max(0, $hardCap - $keyUsed);
        $remainGlobal = max(0, self::GLOBAL_USER_MESSAGE_HARD_CAP - self::$globalConsumed);
        $allow = (int)min($origLen, $remainKey, $remainGlobal);

        $layer = self::budgetLayerForBlockId($blockId);
        $scoreBreakdown = [
            'registry_injection_key:' . $injKey,
            'budget_layer:' . $layer,
            'registry_priority:' . (is_array($def) ? (string)($def['priority'] ?? '0') : 'n/a'),
            'pruning_priority:' . (is_array($def) ? (string)($def['pruning_priority'] ?? '0') : 'n/a'),
            'global_remaining_before:' . (string)max(0, self::GLOBAL_USER_MESSAGE_HARD_CAP - self::$globalConsumed),
            'per_key_remaining_before:' . (string)$remainKey,
        ];

        if ($softCap !== null && $origLen > $softCap && $origLen <= $allow) {
            self::$pruningEvents[] = [
                'type' => 'soft_budget_exceeded',
                'block_id' => $blockId,
                'injection_key' => $injKey,
                'budget_layer' => $layer,
                'original_chars' => $origLen,
                'soft_cap' => $softCap,
                'hard_cap' => $hardCap,
                'chars_economized' => 0,
                'note' => 'Dépassement soft sans troncature (sous plafond dur effectif).',
            ];
        }

        if ($origLen <= $allow) {
            self::$consumedPerKey[$injKey] = $keyUsed + $origLen;
            self::$globalConsumed += $origLen;

            return [
                'content' => $segment,
                'original_chars' => $origLen,
                'injected_chars' => $origLen,
                'refused_chars' => 0,
                'pruning_decision' => 'none',
                'fallback_policy' => 'full_inject',
                'score_breakdown' => $scoreBreakdown,
                'budget_layer' => $layer,
                'chars_budget_allowed' => $allow,
                'soft_budget' => $softCap,
                'hard_budget' => $hardCap,
            ];
        }

        if ($allow <= 0) {
            self::$pruningEvents[] = [
                'type' => 'budget_exhausted_omit',
                'block_id' => $blockId,
                'injection_key' => $injKey,
                'budget_layer' => $layer,
                'original_chars' => $origLen,
                'refused_chars' => $origLen,
            ];

            return [
                'content' => '',
                'original_chars' => $origLen,
                'injected_chars' => 0,
                'refused_chars' => $origLen,
                'pruning_decision' => 'omitted_global_or_key_cap_exhausted',
                'fallback_policy' => 'empty_inject',
                'score_breakdown' => array_merge($scoreBreakdown, ['allowed_zero:true']),
                'budget_layer' => $layer,
                'chars_budget_allowed' => 0,
                'soft_budget' => $softCap,
                'hard_budget' => $hardCap,
            ];
        }

        $suffix = "\n\n[… CognitiveBudgetEngine: hard_truncate — injection_key={$injKey}, policy=hard_cap, deterministic_mvp=true …]\n";
        $suffixLen = mb_strlen($suffix, 'UTF-8');
        $keep = max(0, $allow - $suffixLen);
        $truncated = mb_substr($segment, 0, $keep, 'UTF-8') . $suffix;
        $outLen = mb_strlen($truncated, 'UTF-8');
        $refused = max(0, $origLen - $outLen);

        self::$pruningEvents[] = [
            'type' => 'hard_truncate',
            'block_id' => $blockId,
            'injection_key' => $injKey,
            'budget_layer' => $layer,
            'original_chars' => $origLen,
            'injected_chars' => $outLen,
            'refused_chars' => $refused,
            'chars_economized' => $refused,
            'hard_cap' => $hardCap,
            'global_cap' => self::GLOBAL_USER_MESSAGE_HARD_CAP,
        ];

        self::$consumedPerKey[$injKey] = $keyUsed + $outLen;
        self::$globalConsumed += $outLen;

        return [
            'content' => $truncated,
            'original_chars' => $origLen,
            'injected_chars' => $outLen,
            'refused_chars' => $refused,
            'pruning_decision' => 'hard_truncate_registry_or_global_cap',
            'fallback_policy' => 'hard_tail_trim_with_banner',
            'score_breakdown' => array_merge($scoreBreakdown, [
                'truncated_chars:' . (string)$refused,
            ]),
            'budget_layer' => $layer,
            'chars_budget_allowed' => $allow,
            'soft_budget' => $softCap,
            'hard_budget' => $hardCap,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function finish(): array
    {
        $softGlobalExceeded = self::$globalConsumed > self::GLOBAL_USER_MESSAGE_SOFT_CAP;
        $out = [
            'schema_version' => self::SCHEMA_VERSION,
            'global_user_message_hard_cap' => self::GLOBAL_USER_MESSAGE_HARD_CAP,
            'global_user_message_soft_cap' => self::GLOBAL_USER_MESSAGE_SOFT_CAP,
            'global_consumed_chars' => self::$globalConsumed,
            'soft_cap_exceeded_global' => $softGlobalExceeded,
            'consumed_per_injection_key' => self::$consumedPerKey,
            'pruning_events' => self::$pruningEvents,
            'retrieval_cache_entries' => count(self::$retrievalCache),
            'cache_scope_sha256_prefix' => substr(self::cacheScopePrefix(), 0, 12),
            'notes' => [
                'Le moteur ne modifie aucune table métier ; uniquement le texte injecté dans le prompt utilisateur DR.',
                'Les scores sont des chaînes déterministes MVP (pas de LLM).',
            ],
        ];
        self::$active = false;
        self::$context = [];
        self::$globalConsumed = 0;
        self::$consumedPerKey = [];
        self::$pruningEvents = [];
        self::$definitionsByKey = null;
        self::$retrievalCache = [];

        return $out;
    }

    /** Export statique pour GET /api/cognitive-governance (pas d’état runtime). */
    public static function governanceExport(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scope_mvp' => 'decision_room_user_message',
            'global_caps' => [
                'hard' => self::GLOBAL_USER_MESSAGE_HARD_CAP,
                'soft' => self::GLOBAL_USER_MESSAGE_SOFT_CAP,
            ],
            'policies' => [
                'fallback' => 'hard_tail_trim_with_banner',
                'deduplication' => 'PromptInjectionTraceCollector (clés registre) avant budget sur segments sensibles',
                'minimum_guarantee_mvp' => 'Les segments courts (objectif, tour) restent sous caps registre ; pas de réallocation dynamique inter-segments dans cette version.',
                'expert_override_mvp' => 'Réservé : clé begin context budget_expert_relax (non branchée par défaut).',
            ],
            'layer_map' => 'block_id → budget_layer via CognitiveBudgetEngine::budgetLayerForBlockId (context_docs, session_history, debate_memory, social, situated_memory, playbook, orchestration, synthesizer, session_core, governance)',
            'invariants' => [
                'Aucun retrieval global implicite.',
                'Aucun cache cross-context : préfixe scope = hash(session_id + strategic_context_id).',
                'Aucune mutation données métier.',
            ],
        ];
    }

    public static function budgetLayerForBlockId(string $blockId): string
    {
        return match (true) {
            $blockId === 'context_document' => 'context_docs',
            $blockId === 'objective' => 'session_core',
            in_array($blockId, ['playbook_block', 'debate_discipline'], true) => 'playbook',
            $blockId === 'previous_rounds' => 'session_history',
            $blockId === 'argument_memory' => 'debate_memory',
            str_starts_with($blockId, 'social') => 'social',
            $blockId === 'agent_context_memory' => 'situated_memory',
            str_contains($blockId, 'synthesizer') => 'synthesizer',
            default => 'orchestration',
        };
    }

    /** @param array<string, mixed>|null $def */
    private static function resolveHardCap(?array $def): int
    {
        if ($def === null) {
            return self::GLOBAL_USER_MESSAGE_HARD_CAP;
        }
        $candidates = [];
        if (isset($def['hard_budget']) && $def['hard_budget'] !== null) {
            $candidates[] = (int)$def['hard_budget'];
        }
        if (isset($def['max_chars']) && $def['max_chars'] !== null) {
            $candidates[] = (int)$def['max_chars'];
        }
        if ($candidates === []) {
            return self::GLOBAL_USER_MESSAGE_HARD_CAP;
        }

        return max(1, min($candidates));
    }

    /** @param array<string, mixed>|null $def */
    private static function resolveSoftCap(?array $def, int $hardCap): ?int
    {
        if ($def === null || !isset($def['soft_budget']) || $def['soft_budget'] === null) {
            return null;
        }
        $s = (int)$def['soft_budget'];

        return min($s, $hardCap);
    }

    /**
     * @return array{
     *   content:string,
     *   original_chars:int,
     *   injected_chars:int,
     *   refused_chars:int,
     *   pruning_decision:string,
     *   fallback_policy:string,
     *   score_breakdown:list<string>,
     *   budget_layer:string,
     *   chars_budget_allowed:int,
     *   soft_budget:int|null,
     *   hard_budget:int|null
     * }
     */
    private static function passthrough(string $segment, int $origLen, string $blockId, string $reason): array
    {
        return [
            'content' => $segment,
            'original_chars' => $origLen,
            'injected_chars' => $origLen,
            'refused_chars' => 0,
            'pruning_decision' => $reason,
            'fallback_policy' => 'full_inject',
            'score_breakdown' => ['passthrough:' . $reason],
            'budget_layer' => self::budgetLayerForBlockId($blockId),
            'chars_budget_allowed' => $origLen,
            'soft_budget' => null,
            'hard_budget' => null,
        ];
    }
}

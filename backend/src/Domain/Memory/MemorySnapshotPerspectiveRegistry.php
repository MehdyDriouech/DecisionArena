<?php
declare(strict_types=1);

namespace Domain\Memory;

/**
 * Deterministic registry of perspective projections used to reorder and
 * lightly emphasize sections of an existing memory.md snapshot.
 *
 * Hard constraints (Phase 1 — Perspective Snapshots):
 *  - No LLM. No embeddings. No semantic generation. No autonomous behavior.
 *  - SQLite remains the source of truth. The registry only describes
 *    deterministic projection rules over already-persisted decision records.
 *  - No new information is ever fabricated; perspectives only reorder,
 *    filter, and tag relevance over fields already exposed by the existing
 *    deterministic generator.
 */
final class MemorySnapshotPerspectiveRegistry
{
    public const DEFAULT_KEY = 'default';

    /**
     * Canonical section identifiers used by the generator.
     * Strategic context snapshot uses:
     *   header, current_state, active_risks, validated_hypotheses,
     *   failed_assumptions, decision_chains, unassigned_memories,
     *   perspective_relevance, safety_metadata
     * Room snapshot uses:
     *   header, current_state, open_risks, validated_hypotheses,
     *   failed_assumptions, decision_chain, recommended_next_actions,
     *   linked_sessions, perspective_relevance, safety_metadata
     */
    public const SECTION_HEADER = 'header';
    public const SECTION_CURRENT_STATE = 'current_state';
    public const SECTION_ACTIVE_RISKS = 'active_risks';
    public const SECTION_OPEN_RISKS = 'open_risks';
    public const SECTION_VALIDATED_HYPOTHESES = 'validated_hypotheses';
    public const SECTION_FAILED_ASSUMPTIONS = 'failed_assumptions';
    public const SECTION_DECISION_CHAINS = 'decision_chains';
    public const SECTION_DECISION_CHAIN = 'decision_chain';
    public const SECTION_UNASSIGNED_MEMORIES = 'unassigned_memories';
    public const SECTION_RECOMMENDED_NEXT_ACTIONS = 'recommended_next_actions';
    public const SECTION_LINKED_SESSIONS = 'linked_sessions';
    public const SECTION_PERSPECTIVE_RELEVANCE = 'perspective_relevance';
    public const SECTION_SAFETY_METADATA = 'safety_metadata';

    /**
     * Returns the list of all supported perspective keys (stable order, used
     * by the lightweight relevance tagging block).
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        return ['default', 'ceo', 'cto', 'cfo', 'product', 'growth', 'legal'];
    }

    /**
     * Coerce any user-supplied string into a known perspective key, falling
     * back to "default" for missing/invalid values.
     */
    public static function normalizeKey(?string $value): string
    {
        $v = strtolower(trim((string)$value));
        if ($v === '') return self::DEFAULT_KEY;
        $known = self::allKeys();
        return in_array($v, $known, true) ? $v : self::DEFAULT_KEY;
    }

    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   prioritized_sections:list<string>,
     *   hidden_sections:list<string>,
     *   emphasis_fields:list<string>,
     *   risk_keywords:list<string>,
     *   hypothesis_keywords:list<string>,
     *   ordering_rules:array<string,string>,
     * }
     */
    public static function get(string $key, string $scope = 'context'): array
    {
        $normalized = self::normalizeKey($key);
        $config = self::definitions()[$normalized] ?? self::definitions()[self::DEFAULT_KEY];

        // Adapt section names that differ between context vs room scope.
        // Only the prioritized_sections list gets the room-only "extras"
        // (recommended_next_actions, linked_sessions) appended; hidden_sections
        // keeps strictly the renamed entries that were declared by the
        // perspective so it never accidentally hides those room-only blocks.
        $sections = self::adaptSectionsForScope($config['prioritized_sections'], $scope, true);
        $hidden = self::adaptSectionsForScope($config['hidden_sections'], $scope, false);

        return [
            'key' => $normalized,
            'label' => $config['label'],
            'prioritized_sections' => $sections,
            'hidden_sections' => $hidden,
            'emphasis_fields' => $config['emphasis_fields'],
            'risk_keywords' => $config['risk_keywords'],
            'hypothesis_keywords' => $config['hypothesis_keywords'],
            'ordering_rules' => $config['ordering_rules'],
        ];
    }

    /**
     * Returns the static, deterministic definitions.
     *
     * @return array<string, array{
     *   label:string,
     *   prioritized_sections:list<string>,
     *   hidden_sections:list<string>,
     *   emphasis_fields:list<string>,
     *   risk_keywords:list<string>,
     *   hypothesis_keywords:list<string>,
     *   ordering_rules:array<string,string>,
     * }>
     */
    private static function definitions(): array
    {
        return [
            'default' => [
                'label' => 'Default',
                // Default keeps the historical section order so existing
                // snapshots remain unchanged byte-for-byte when no perspective
                // is requested. The generator itself short-circuits on this
                // key to guarantee identical output, but this list is also
                // used as a structural fallback.
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => [],
                'risk_keywords' => [],
                'hypothesis_keywords' => [],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'ceo' => [
                'label' => 'CEO',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['unresolved_risks', 'validated_hypotheses'],
                'risk_keywords' => [
                    'strategic', 'strategy', 'direction', 'vision',
                    'execution', 'priority', 'priorities',
                    'blocker', 'blockers', 'momentum', 'alignment',
                    'leadership', 'roadmap',
                ],
                'hypothesis_keywords' => [
                    'strategic', 'strategy', 'vision', 'direction',
                    'priority', 'execution', 'positioning', 'long term',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'cto' => [
                'label' => 'CTO',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['unresolved_risks', 'failed_assumptions'],
                'risk_keywords' => [
                    'technical', 'tech', 'architecture', 'architectural',
                    'scalability', 'scale', 'performance', 'latency',
                    'reliability', 'availability', 'downtime', 'outage',
                    'security', 'vulnerability', 'data loss',
                    'infrastructure', 'integration', 'api', 'database',
                    'migration', 'dependency', 'tech debt', 'technical debt',
                    'deprecation', 'compatibility',
                ],
                'hypothesis_keywords' => [
                    'technical', 'architecture', 'platform', 'system',
                    'engineering', 'feasibility', 'integration',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'cfo' => [
                'label' => 'CFO',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['unresolved_risks'],
                'risk_keywords' => [
                    'cost', 'costs', 'spend', 'spending', 'budget',
                    'cash', 'cashflow', 'cash flow', 'runway',
                    'revenue', 'margin', 'unit economics',
                    'capex', 'opex', 'burn', 'pricing',
                    'reversibility', 'reversible', 'irreversible',
                    'exposure', 'liability', 'commit', 'commitment',
                    'contract', 'vendor', 'compliance', 'audit',
                ],
                'hypothesis_keywords' => [
                    'cost', 'revenue', 'margin', 'pricing', 'roi',
                    'payback', 'unit economics', 'cash',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'product' => [
                'label' => 'Product',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['validated_hypotheses', 'failed_assumptions'],
                'risk_keywords' => [
                    'user', 'users', 'ux', 'usability', 'friction',
                    'adoption', 'onboarding', 'engagement', 'churn',
                    'feature', 'product', 'discovery',
                    'validation', 'experiment', 'hypothesis',
                    'feedback', 'support', 'persona',
                ],
                'hypothesis_keywords' => [
                    'user', 'users', 'adoption', 'feature',
                    'engagement', 'experiment', 'discovery',
                    'validation', 'persona', 'feedback',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'growth' => [
                'label' => 'Growth',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['validated_hypotheses'],
                'risk_keywords' => [
                    'acquisition', 'channel', 'channels', 'cac',
                    'retention', 'churn', 'activation',
                    'conversion', 'funnel', 'pipeline',
                    'marketing', 'campaign', 'seo', 'sem',
                    'virality', 'referral', 'ltv',
                    'segment', 'audience', 'targeting',
                ],
                'hypothesis_keywords' => [
                    'acquisition', 'channel', 'retention',
                    'conversion', 'funnel', 'campaign',
                    'segment', 'audience', 'growth',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
            'legal' => [
                'label' => 'Legal/Risk',
                'prioritized_sections' => [
                    self::SECTION_HEADER,
                    self::SECTION_CURRENT_STATE,
                    self::SECTION_ACTIVE_RISKS,
                    self::SECTION_FAILED_ASSUMPTIONS,
                    self::SECTION_DECISION_CHAINS,
                    self::SECTION_VALIDATED_HYPOTHESES,
                    self::SECTION_UNASSIGNED_MEMORIES,
                    self::SECTION_PERSPECTIVE_RELEVANCE,
                    self::SECTION_SAFETY_METADATA,
                ],
                'hidden_sections' => [],
                'emphasis_fields' => ['unresolved_risks', 'failed_assumptions'],
                'risk_keywords' => [
                    'legal', 'compliance', 'regulatory', 'regulation',
                    'gdpr', 'ccpa', 'hipaa', 'sox',
                    'privacy', 'data protection', 'consent',
                    'contract', 'liability', 'exposure',
                    'governance', 'audit', 'policy', 'breach',
                    'intellectual property', 'ip', 'license',
                    'risk',
                ],
                'hypothesis_keywords' => [
                    'compliance', 'legal', 'governance',
                    'policy', 'regulation', 'privacy',
                ],
                'ordering_rules' => [
                    'memories' => 'created_at_desc',
                    'risks' => 'alpha',
                    'hypotheses' => 'alpha',
                ],
            ],
        ];
    }

    /**
     * Translate scope-agnostic section names into the names actually used by
     * the room or context generator. For example, "active_risks" is called
     * "open_risks" in the room snapshot.
     *
     * @param list<string> $sections
     * @return list<string>
     */
    private static function adaptSectionsForScope(array $sections, string $scope, bool $addRoomExtras = true): array
    {
        $isRoom = ($scope === 'room');
        $out = [];
        foreach ($sections as $s) {
            if ($isRoom) {
                if ($s === self::SECTION_ACTIVE_RISKS) {
                    $out[] = self::SECTION_OPEN_RISKS;
                    continue;
                }
                if ($s === self::SECTION_DECISION_CHAINS) {
                    $out[] = self::SECTION_DECISION_CHAIN;
                    continue;
                }
                if ($s === self::SECTION_UNASSIGNED_MEMORIES) {
                    // Rooms have no "unassigned" group; the room scope adds
                    // recommended_next_actions + linked_sessions instead.
                    continue;
                }
            }
            $out[] = $s;
        }
        // Append room-only sections deterministically, just before
        // perspective_relevance / safety_metadata (or at the end if absent).
        // Only applied to prioritized_sections — hidden_sections must never
        // hide these room-only blocks unless a perspective explicitly declares
        // them as hidden in its definitions().
        if ($isRoom && $addRoomExtras) {
            $needles = [self::SECTION_RECOMMENDED_NEXT_ACTIONS, self::SECTION_LINKED_SESSIONS];
            foreach ($needles as $extra) {
                if (in_array($extra, $out, true)) continue;
                $insertAt = count($out);
                foreach ([self::SECTION_PERSPECTIVE_RELEVANCE, self::SECTION_SAFETY_METADATA] as $anchor) {
                    $idx = array_search($anchor, $out, true);
                    if ($idx !== false && $idx < $insertAt) $insertAt = (int)$idx;
                }
                array_splice($out, $insertAt, 0, [$extra]);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Deterministic case-insensitive keyword match against a single text.
     *
     * @param list<string> $keywords
     */
    public static function matchesAny(string $text, array $keywords): bool
    {
        if ($keywords === []) return false;
        $hay = mb_strtolower($text, 'UTF-8');
        foreach ($keywords as $kw) {
            $needle = mb_strtolower(trim((string)$kw), 'UTF-8');
            if ($needle === '') continue;
            if (mb_strpos($hay, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * Count distinct hits for a list of texts against the union of risk
     * + hypothesis keywords. Matches are bounded by item count for stability.
     *
     * @param list<string> $texts
     */
    public static function countMatches(array $texts, string $perspectiveKey): int
    {
        $cfg = self::get($perspectiveKey);
        $kw = array_values(array_unique(array_merge($cfg['risk_keywords'], $cfg['hypothesis_keywords'])));
        if ($kw === []) return 0;
        $hits = 0;
        foreach ($texts as $t) {
            if (self::matchesAny((string)$t, $kw)) $hits++;
        }
        return $hits;
    }

    /**
     * Map a deterministic hit count to a stable relevance bucket label.
     */
    public static function bucketForHits(int $hits, int $totalItems): string
    {
        if ($totalItems <= 0) return 'none';
        $ratio = $hits / $totalItems;
        if ($hits === 0) return 'none';
        if ($hits >= 4 || $ratio >= 0.5) return 'high';
        if ($hits >= 2 || $ratio >= 0.2) return 'medium';
        return 'low';
    }
}

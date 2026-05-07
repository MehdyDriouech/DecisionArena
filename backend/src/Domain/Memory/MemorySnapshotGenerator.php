<?php
declare(strict_types=1);

namespace Domain\Memory;

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionRoomRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Deterministic Markdown projections from existing decision memory records.
 * Source of truth remains SQLite.
 */
final class MemorySnapshotGenerator
{
    private \PDO $pdo;
    private StrategicContextRepository $contexts;
    private DecisionRoomRepository $rooms;

    private const DEFAULT_MAX_MEMORIES = 20;
    private const STALE_AFTER_DAYS = 90;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->contexts = new StrategicContextRepository();
        $this->rooms = new DecisionRoomRepository();
    }

    /** @param array{include_stale?:bool, include_archived?:bool, include_expert_metadata?:bool, max_memories?:int, now?:string, perspective?:string} $options */
    public function generateContextMarkdown(string $contextId, array $options = []): string
    {
        $ctx = $this->contexts->find($contextId);
        if (!$ctx) {
            return "# Context not found\n";
        }

        $opts = $this->normalizeOptions($options);
        $generatedAt = $opts['now'];

        // Phase 1 — Perspective Snapshots: when no perspective is requested
        // (or "default") the legacy code path runs unchanged so the existing
        // output is preserved byte-for-byte. Other perspectives are projected
        // by reordering and lightly emphasizing already-persisted fields.
        if ($opts['perspective'] !== MemorySnapshotPerspectiveRegistry::DEFAULT_KEY) {
            return $this->generateContextMarkdownWithPerspective($ctx, $contextId, $opts);
        }

        $state = $this->contexts->currentState($contextId);

        $rooms = $this->rooms->listByContext($contextId, 200);
        usort($rooms, function ($a, $b) {
            $ta = strtolower((string)($a['title'] ?? ''));
            $tb = strtolower((string)($b['title'] ?? ''));
            if ($ta === $tb) return strcmp((string)($a['room_id'] ?? ''), (string)($b['room_id'] ?? ''));
            return strcmp($ta, $tb);
        });

        $ctxMemIds = $this->fetchContextMemoryIds($contextId);

        $roomMemIdsByRoom = [];
        $roomMemIdsSet = [];
        foreach ($rooms as $r) {
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            $ids = $this->rooms->linkedMemoryIds($rid);
            $roomMemIdsByRoom[$rid] = $ids;
            foreach ($ids as $mid) $roomMemIdsSet[(string)$mid] = true;
        }

        $unassignedIds = [];
        foreach ($ctxMemIds as $mid) {
            if (!isset($roomMemIdsSet[(string)$mid])) {
                $unassignedIds[] = (string)$mid;
            }
        }

        $allIds = array_values(array_unique(array_merge($ctxMemIds, ...array_values($roomMemIdsByRoom))));
        $allMemRows = $this->fetchMemoriesByIds($allIds, $opts);

        $md = '';
        $md .= '# ' . $this->h((string)($ctx['title'] ?? 'Strategic Context')) . "\n\n";
        $md .= "> This snapshot is derived from prior decision records, not verified truth.\n\n";

        $md .= "## Current State\n";
        $md .= '- Context status: ' . $this->inline((string)($ctx['status'] ?? '')) . "\n";
        $md .= '- Current decision: ' . $this->inline((string)($state['current_decision_status'] ?? '')) . "\n";
        $md .= '- Confidence: ' . $this->inline((string)($state['current_confidence'] ?? '')) . "\n";
        $md .= '- Latest next step: ' . $this->inline((string)($state['latest_next_step'] ?? '')) . "\n";
        $md .= '- Latest memory: ' . $this->inline((string)($state['latest_memory_id'] ?? '')) . "\n";
        $md .= '- Last updated: ' . $this->inline((string)($ctx['updated_at'] ?? '')) . "\n\n";

        $md .= "## Active Risks\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($allMemRows, 'unresolved_risks')), 50) . "\n";

        $md .= "## Validated Hypotheses\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($allMemRows, 'validated_hypotheses')), 50) . "\n";

        $md .= "## Failed Assumptions\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($allMemRows, 'failed_assumptions')), 50) . "\n";

        $md .= "## Decision Chains\n\n";
        if ($rooms === []) {
            $md .= "_No decision chains for this context._\n\n";
        }

        foreach ($rooms as $r) {
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            $roomState = $this->rooms->currentState($rid);
            $linkedIds = $roomMemIdsByRoom[$rid] ?? [];
            $roomMems = $this->fetchMemoriesByIds($linkedIds, $opts);

            $md .= '### ' . $this->h((string)($r['title'] ?? $rid)) . "\n";
            $md .= '- Chain status: ' . $this->inline((string)($r['status'] ?? '')) . "\n";
            $md .= '- Playbook: ' . $this->inline((string)($r['playbook_id'] ?? '')) . "\n";
            $md .= '- Current decision: ' . $this->inline((string)($roomState['current_decision_status'] ?? '')) . "\n";
            $md .= '- Confidence: ' . $this->inline((string)($roomState['current_confidence'] ?? '')) . "\n";
            $md .= '- Latest next step: ' . $this->inline((string)($roomState['latest_next_step'] ?? '')) . "\n";
            $md .= '- Open risks: ' . $this->inline((string)count($this->uniqueSortedStrings($this->collectStrings($roomMems, 'unresolved_risks')))) . "\n";
            $md .= '- Linked memories: ' . $this->inline((string)count($roomMems)) . "\n\n";

            $md .= "#### Recent Memories\n";
            $recent = $this->formatRecentMemories($roomMems, $opts['max_memories'], false);
            $md .= $recent !== '' ? $recent : "- —\n";
            $md .= "\n";
        }

        if ($unassignedIds !== []) {
            $md .= "## Unassigned Decision Memories\n";
            $unassignedRows = $this->fetchMemoriesByIds($unassignedIds, $opts);
            $lines = $this->formatRecentMemories($unassignedRows, $opts['max_memories'], false);
            $md .= $lines !== '' ? $lines : "- —\n";
            $md .= "\n";
        }

        $meta = $this->collectVersions($allMemRows);
        $md .= "## Safety / Metadata\n";
        $md .= '- Generated at: ' . $this->inline($generatedAt) . "\n";
        $md .= '- Context ID: ' . $this->inline($contextId) . "\n";
        $md .= '- Contract versions: ' . $this->inline(implode(', ', $meta['contract_versions'])) . "\n";
        $md .= '- Taxonomy versions: ' . $this->inline(implode(', ', $meta['taxonomy_versions'])) . "\n";
        $md .= '- Stale memories included: ' . ($opts['include_stale'] ? 'yes' : 'no') . "\n";
        $md .= '- Invalidated memories included: no' . "\n";

        if ($opts['include_expert_metadata']) {
            $md .= "\n<!-- expert_metadata=1 -->\n";
        }

        return rtrim($md) . "\n";
    }

    /** @param array{include_stale?:bool, include_archived?:bool, include_expert_metadata?:bool, max_memories?:int, now?:string, perspective?:string} $options */
    public function generateRoomMarkdown(string $roomId, array $options = []): string
    {
        $room = $this->rooms->find($roomId);
        if (!$room) {
            return "# Decision chain not found\n";
        }

        $opts = $this->normalizeOptions($options);
        $generatedAt = $opts['now'];

        if ($opts['perspective'] !== MemorySnapshotPerspectiveRegistry::DEFAULT_KEY) {
            return $this->generateRoomMarkdownWithPerspective($room, $roomId, $opts);
        }

        $state = $this->rooms->currentState($roomId);
        $linkedMemIds = $this->rooms->linkedMemoryIds($roomId);
        $memRows = $this->fetchMemoriesByIds($linkedMemIds, $opts);

        $md = '';
        $md .= '# ' . $this->h((string)($room['title'] ?? $roomId)) . "\n\n";
        $md .= "> This snapshot is derived from prior decision records, not verified truth.\n\n";

        $md .= "## Current State\n";
        $md .= '- Chain status: ' . $this->inline((string)($room['status'] ?? '')) . "\n";
        $md .= '- Playbook: ' . $this->inline((string)($room['playbook_id'] ?? '')) . "\n";
        $md .= '- Current decision: ' . $this->inline((string)($state['current_decision_status'] ?? '')) . "\n";
        $md .= '- Confidence: ' . $this->inline((string)($state['current_confidence'] ?? '')) . "\n";
        $md .= '- Latest next step: ' . $this->inline((string)($state['latest_next_step'] ?? '')) . "\n";
        $md .= '- Latest memory: ' . $this->inline((string)($state['latest_memory_id'] ?? '')) . "\n";
        $md .= '- Last updated: ' . $this->inline((string)($room['updated_at'] ?? '')) . "\n\n";

        $md .= "## Open Risks\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($memRows, 'unresolved_risks')), 50) . "\n";

        $md .= "## Validated Hypotheses\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($memRows, 'validated_hypotheses')), 50) . "\n";

        $md .= "## Failed Assumptions\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($memRows, 'failed_assumptions')), 50) . "\n";

        $md .= "## Decision Chain\n";
        $chainLines = $this->formatRecentMemories($memRows, $opts['max_memories'], true);
        $md .= $chainLines !== '' ? $chainLines : "- —\n";
        $md .= "\n";

        $md .= "## Recommended Next Actions\n";
        $md .= $this->bullets($this->uniqueSortedStrings($this->collectStrings($memRows, 'recommended_next_steps')), 50) . "\n";

        $md .= "## Linked Sessions\n";
        $sessionIds = $this->rooms->linkedSessionIds($roomId);
        sort($sessionIds, SORT_STRING);
        $md .= $this->bullets($sessionIds, 200) . "\n";

        $meta = $this->collectVersions($memRows);
        $md .= "## Safety / Metadata\n";
        $md .= '- Generated at: ' . $this->inline($generatedAt) . "\n";
        $md .= '- Chain ID: ' . $this->inline($roomId) . "\n";
        $md .= '- Context ID: ' . $this->inline((string)($room['context_id'] ?? '')) . "\n";
        $md .= '- Contract versions: ' . $this->inline(implode(', ', $meta['contract_versions'])) . "\n";
        $md .= '- Taxonomy versions: ' . $this->inline(implode(', ', $meta['taxonomy_versions'])) . "\n";
        $md .= '- Stale memories included: ' . ($opts['include_stale'] ? 'yes' : 'no') . "\n";
        $md .= '- Invalidated memories included: no' . "\n";

        if ($opts['include_expert_metadata']) {
            $md .= "\n<!-- expert_metadata=1 -->\n";
        }

        return rtrim($md) . "\n";
    }

    /** @return array{include_stale:bool, include_archived:bool, include_expert_metadata:bool, max_memories:int, now:string, perspective:string} */
    private function normalizeOptions(array $options): array
    {
        $includeStale = ($options['include_stale'] ?? false) === true;
        $includeArchived = ($options['include_archived'] ?? false) === true;
        $includeExpert = ($options['include_expert_metadata'] ?? false) === true;
        $max = (int)($options['max_memories'] ?? self::DEFAULT_MAX_MEMORIES);
        $max = max(1, min(200, $max));
        $now = isset($options['now']) && is_string($options['now']) && $options['now'] !== '' ? $options['now'] : date('c');
        $perspective = MemorySnapshotPerspectiveRegistry::normalizeKey(
            isset($options['perspective']) ? (string)$options['perspective'] : null
        );
        return [
            'include_stale' => $includeStale,
            'include_archived' => $includeArchived,
            'include_expert_metadata' => $includeExpert,
            'max_memories' => $max,
            'now' => $now,
            'perspective' => $perspective,
        ];
    }

    /** @return list<string> */
    private function fetchContextMemoryIds(string $contextId): array
    {
        $stmt = $this->pdo->prepare('SELECT memory_id FROM strategic_context_memories WHERE context_id = ? ORDER BY created_at DESC');
        $stmt->execute([$contextId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function fetchMemoriesByIds(array $memoryIds, array $opts): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $memoryIds))));
        if ($ids === []) return [];

        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM decision_memories WHERE memory_id IN ($place)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        usort($rows, function ($a, $b) {
            $ca = (string)($a['created_at'] ?? '');
            $cb = (string)($b['created_at'] ?? '');
            if ($ca === $cb) return strcmp((string)($b['memory_id'] ?? ''), (string)($a['memory_id'] ?? ''));
            return strcmp($cb, $ca);
        });

        $out = [];
        foreach ($rows as $r) {
            $m = $this->hydrateMemory($r);
            if (($m['user_confirmed'] ?? 0) !== 1) continue;
            $state = (string)($m['memory_state'] ?? 'active');
            if ($state === 'invalidated') continue;
            if (!$opts['include_archived'] && $state === 'archived') continue;

            $isStale = $this->isStale($m, $opts['now']);
            if (!$opts['include_stale'] && $isStale) continue;
            $m['__stale'] = $isStale;
            $out[] = $m;
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private function hydrateMemory(array $row): array
    {
        foreach (['validated_hypotheses', 'failed_assumptions', 'unresolved_risks', 'recommended_next_steps'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
        }
        $row['user_confirmed'] = (int)($row['user_confirmed'] ?? 0);
        $row['memory_state'] = isset($row['memory_state']) ? (string)$row['memory_state'] : 'active';
        return $row;
    }

    /** @param array<string,mixed> $m */
    private function isStale(array $m, string $nowIso): bool
    {
        try {
            $created = new \DateTimeImmutable((string)($m['created_at'] ?? ''));
            $now = new \DateTimeImmutable($nowIso);
            $days = (int)floor(($now->getTimestamp() - $created->getTimestamp()) / 86400);
            return $days >= self::STALE_AFTER_DAYS;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<array<string,mixed>> $memRows */
    private function collectStrings(array $memRows, string $key): array
    {
        $out = [];
        foreach ($memRows as $m) {
            $vals = $m[$key] ?? [];
            if (!is_array($vals)) continue;
            foreach ($vals as $v) {
                $s = trim((string)$v);
                if ($s !== '') $out[] = $s;
            }
        }
        return $out;
    }

    /** @param list<string> $vals @return list<string> */
    private function uniqueSortedStrings(array $vals): array
    {
        $vals = array_values(array_unique(array_map('strval', $vals)));
        sort($vals, SORT_STRING);
        return $vals;
    }

    /** @param list<string> $items */
    private function bullets(array $items, int $limit): string
    {
        $items = array_values(array_filter(array_map('strval', $items)));
        if ($items === []) return "- —\n";
        $items = array_slice($items, 0, max(1, $limit));
        $out = '';
        foreach ($items as $x) {
            $out .= '- ' . $this->inline($x) . "\n";
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $memRows */
    private function collectVersions(array $memRows): array
    {
        $cv = [];
        $tv = [];
        foreach ($memRows as $m) {
            $c = trim((string)($m['contract_version'] ?? ''));
            $t = trim((string)($m['taxonomy_version'] ?? ''));
            if ($c !== '') $cv[$c] = true;
            if ($t !== '') $tv[$t] = true;
        }
        $cvs = array_keys($cv);
        $tvs = array_keys($tv);
        sort($cvs, SORT_STRING);
        sort($tvs, SORT_STRING);
        return ['contract_versions' => $cvs ?: ['—'], 'taxonomy_versions' => $tvs ?: ['—']];
    }

    /** @param list<array<string,mixed>> $memRows */
    private function formatRecentMemories(array $memRows, int $limit, bool $includeConfidence): string
    {
        $lines = [];
        $slice = array_slice($memRows, 0, $limit);
        foreach ($slice as $m) {
            $date = (string)($m['created_at'] ?? '');
            $status = (string)($m['decision_status'] ?? '');
            $conf = (string)($m['confidence'] ?? '');
            $sum = $this->oneLine((string)($m['decision_summary'] ?? ''), 160);
            $staleTag = !empty($m['__stale']) ? ' ⚠ stale' : '';
            if ($includeConfidence) {
                $lines[] = '- ' . $this->inline($date) . ' — ' . $this->inline($status) . ' — ' . $this->inline($conf) . ' — ' . $this->inline($sum) . $staleTag;
            } else {
                $lines[] = '- ' . $this->inline($date) . ' — ' . $this->inline($status) . ' — ' . $this->inline($sum) . $staleTag;
            }
        }
        return $lines ? implode("\n", $lines) . "\n" : '';
    }

    private function oneLine(string $s, int $max): string
    {
        $x = preg_replace('/\s+/', ' ', $s);
        $x = trim((string)$x);
        if ($x === '') return '—';
        if (mb_strlen($x) <= $max) return $x;
        return mb_substr($x, 0, max(0, $max - 1)) . '…';
    }

    private function inline(string $s): string
    {
        $x = $this->oneLine($s, 220);
        return str_replace(["\r", "\n"], ' ', $x);
    }

    private function h(string $s): string
    {
        return trim($this->inline($s));
    }

    // ===========================================================
    // Phase 1 — Perspective Snapshots
    //
    // The perspective code path is a pure projection. It only:
    //   - reorders sections
    //   - reorders bullet items inside emphasized fields (stable, alpha-sort
    //     for ties) so items matching the perspective's risk/hypothesis
    //     keywords surface first, prefixed with a star marker
    //   - appends a deterministic "## Perspective Relevance" block computed
    //     from keyword matches against persisted fields
    //
    // It NEVER:
    //   - calls an LLM
    //   - persists or mutates any record
    //   - fabricates new content
    //   - injects hidden prompts
    //   - alters lifecycle state (archived/invalidated filtering remains the
    //     same as the default snapshot)
    // ===========================================================

    /**
     * @param array<string,mixed> $ctx
     * @param array{include_stale:bool,include_archived:bool,include_expert_metadata:bool,max_memories:int,now:string,perspective:string} $opts
     */
    private function generateContextMarkdownWithPerspective(array $ctx, string $contextId, array $opts): string
    {
        $generatedAt = $opts['now'];
        $perspective = MemorySnapshotPerspectiveRegistry::get($opts['perspective'], 'context');
        $state = $this->contexts->currentState($contextId);

        $rooms = $this->rooms->listByContext($contextId, 200);
        usort($rooms, function ($a, $b) {
            $ta = strtolower((string)($a['title'] ?? ''));
            $tb = strtolower((string)($b['title'] ?? ''));
            if ($ta === $tb) return strcmp((string)($a['room_id'] ?? ''), (string)($b['room_id'] ?? ''));
            return strcmp($ta, $tb);
        });

        $ctxMemIds = $this->fetchContextMemoryIds($contextId);
        $roomMemIdsByRoom = [];
        $roomMemIdsSet = [];
        foreach ($rooms as $r) {
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            $ids = $this->rooms->linkedMemoryIds($rid);
            $roomMemIdsByRoom[$rid] = $ids;
            foreach ($ids as $mid) $roomMemIdsSet[(string)$mid] = true;
        }

        $unassignedIds = [];
        foreach ($ctxMemIds as $mid) {
            if (!isset($roomMemIdsSet[(string)$mid])) {
                $unassignedIds[] = (string)$mid;
            }
        }

        $allIds = array_values(array_unique(array_merge($ctxMemIds, ...array_values($roomMemIdsByRoom))));
        $allMemRows = $this->fetchMemoriesByIds($allIds, $opts);

        $sections = [];

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_HEADER] =
            '# ' . $this->h((string)($ctx['title'] ?? 'Strategic Context')) . "\n\n"
            . "> This snapshot is derived from prior decision records, not verified truth.\n"
            . '> Perspective: ' . $this->inline($perspective['label']) . " (deterministic projection — no AI inference, no new content).\n\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_CURRENT_STATE] =
            "## Current State\n"
            . '- Context status: ' . $this->inline((string)($ctx['status'] ?? '')) . "\n"
            . '- Current decision: ' . $this->inline((string)($state['current_decision_status'] ?? '')) . "\n"
            . '- Confidence: ' . $this->inline((string)($state['current_confidence'] ?? '')) . "\n"
            . '- Latest next step: ' . $this->inline((string)($state['latest_next_step'] ?? '')) . "\n"
            . '- Latest memory: ' . $this->inline((string)($state['latest_memory_id'] ?? '')) . "\n"
            . '- Last updated: ' . $this->inline((string)($ctx['updated_at'] ?? '')) . "\n\n";

        $risks = $this->uniqueSortedStrings($this->collectStrings($allMemRows, 'unresolved_risks'));
        $hypotheses = $this->uniqueSortedStrings($this->collectStrings($allMemRows, 'validated_hypotheses'));
        $failed = $this->uniqueSortedStrings($this->collectStrings($allMemRows, 'failed_assumptions'));

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_ACTIVE_RISKS] =
            "## Active Risks\n"
            . $this->bulletsEmphasized(
                $risks,
                $perspective['risk_keywords'],
                in_array('unresolved_risks', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_VALIDATED_HYPOTHESES] =
            "## Validated Hypotheses\n"
            . $this->bulletsEmphasized(
                $hypotheses,
                $perspective['hypothesis_keywords'],
                in_array('validated_hypotheses', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_FAILED_ASSUMPTIONS] =
            "## Failed Assumptions\n"
            . $this->bulletsEmphasized(
                $failed,
                array_values(array_unique(array_merge($perspective['risk_keywords'], $perspective['hypothesis_keywords']))),
                in_array('failed_assumptions', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $chains = "## Decision Chains\n\n";
        if ($rooms === []) {
            $chains .= "_No decision chains for this context._\n\n";
        }
        foreach ($rooms as $r) {
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            $roomState = $this->rooms->currentState($rid);
            $linkedIds = $roomMemIdsByRoom[$rid] ?? [];
            $roomMems = $this->fetchMemoriesByIds($linkedIds, $opts);

            $chains .= '### ' . $this->h((string)($r['title'] ?? $rid)) . "\n";
            $chains .= '- Chain status: ' . $this->inline((string)($r['status'] ?? '')) . "\n";
            $chains .= '- Playbook: ' . $this->inline((string)($r['playbook_id'] ?? '')) . "\n";
            $chains .= '- Current decision: ' . $this->inline((string)($roomState['current_decision_status'] ?? '')) . "\n";
            $chains .= '- Confidence: ' . $this->inline((string)($roomState['current_confidence'] ?? '')) . "\n";
            $chains .= '- Latest next step: ' . $this->inline((string)($roomState['latest_next_step'] ?? '')) . "\n";
            $chains .= '- Open risks: ' . $this->inline((string)count($this->uniqueSortedStrings($this->collectStrings($roomMems, 'unresolved_risks')))) . "\n";
            $chains .= '- Linked memories: ' . $this->inline((string)count($roomMems)) . "\n\n";

            $chains .= "#### Recent Memories\n";
            $recent = $this->formatRecentMemories($roomMems, $opts['max_memories'], false);
            $chains .= $recent !== '' ? $recent : "- —\n";
            $chains .= "\n";
        }
        $sections[MemorySnapshotPerspectiveRegistry::SECTION_DECISION_CHAINS] = $chains;

        if ($unassignedIds !== []) {
            $unassignedRows = $this->fetchMemoriesByIds($unassignedIds, $opts);
            $lines = $this->formatRecentMemories($unassignedRows, $opts['max_memories'], false);
            $sections[MemorySnapshotPerspectiveRegistry::SECTION_UNASSIGNED_MEMORIES] =
                "## Unassigned Decision Memories\n"
                . ($lines !== '' ? $lines : "- —\n")
                . "\n";
        }

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_PERSPECTIVE_RELEVANCE] =
            $this->buildPerspectiveRelevanceBlock($risks, $hypotheses, $failed, $opts['perspective']);

        $meta = $this->collectVersions($allMemRows);
        $safety = "## Safety / Metadata\n"
            . '- Generated at: ' . $this->inline($generatedAt) . "\n"
            . '- Context ID: ' . $this->inline($contextId) . "\n"
            . '- Perspective: ' . $this->inline($perspective['key']) . "\n"
            . '- Contract versions: ' . $this->inline(implode(', ', $meta['contract_versions'])) . "\n"
            . '- Taxonomy versions: ' . $this->inline(implode(', ', $meta['taxonomy_versions'])) . "\n"
            . '- Stale memories included: ' . ($opts['include_stale'] ? 'yes' : 'no') . "\n"
            . '- Invalidated memories included: no' . "\n"
            . '- Projection mode: deterministic (no AI inference; persisted fields only)' . "\n";
        $sections[MemorySnapshotPerspectiveRegistry::SECTION_SAFETY_METADATA] = $safety;

        $md = $this->assembleSections($sections, $perspective);
        if ($opts['include_expert_metadata']) {
            $md .= "\n<!-- expert_metadata=1 -->\n";
            $md .= '<!-- perspective=' . $perspective['key'] . " -->\n";
        }
        return rtrim($md) . "\n";
    }

    /**
     * @param array<string,mixed> $room
     * @param array{include_stale:bool,include_archived:bool,include_expert_metadata:bool,max_memories:int,now:string,perspective:string} $opts
     */
    private function generateRoomMarkdownWithPerspective(array $room, string $roomId, array $opts): string
    {
        $generatedAt = $opts['now'];
        $perspective = MemorySnapshotPerspectiveRegistry::get($opts['perspective'], 'room');

        $state = $this->rooms->currentState($roomId);
        $linkedMemIds = $this->rooms->linkedMemoryIds($roomId);
        $memRows = $this->fetchMemoriesByIds($linkedMemIds, $opts);

        $sections = [];

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_HEADER] =
            '# ' . $this->h((string)($room['title'] ?? $roomId)) . "\n\n"
            . "> This snapshot is derived from prior decision records, not verified truth.\n"
            . '> Perspective: ' . $this->inline($perspective['label']) . " (deterministic projection — no AI inference, no new content).\n\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_CURRENT_STATE] =
            "## Current State\n"
            . '- Chain status: ' . $this->inline((string)($room['status'] ?? '')) . "\n"
            . '- Playbook: ' . $this->inline((string)($room['playbook_id'] ?? '')) . "\n"
            . '- Current decision: ' . $this->inline((string)($state['current_decision_status'] ?? '')) . "\n"
            . '- Confidence: ' . $this->inline((string)($state['current_confidence'] ?? '')) . "\n"
            . '- Latest next step: ' . $this->inline((string)($state['latest_next_step'] ?? '')) . "\n"
            . '- Latest memory: ' . $this->inline((string)($state['latest_memory_id'] ?? '')) . "\n"
            . '- Last updated: ' . $this->inline((string)($room['updated_at'] ?? '')) . "\n\n";

        $risks = $this->uniqueSortedStrings($this->collectStrings($memRows, 'unresolved_risks'));
        $hypotheses = $this->uniqueSortedStrings($this->collectStrings($memRows, 'validated_hypotheses'));
        $failed = $this->uniqueSortedStrings($this->collectStrings($memRows, 'failed_assumptions'));
        $nextActions = $this->uniqueSortedStrings($this->collectStrings($memRows, 'recommended_next_steps'));

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_OPEN_RISKS] =
            "## Open Risks\n"
            . $this->bulletsEmphasized(
                $risks,
                $perspective['risk_keywords'],
                in_array('unresolved_risks', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_VALIDATED_HYPOTHESES] =
            "## Validated Hypotheses\n"
            . $this->bulletsEmphasized(
                $hypotheses,
                $perspective['hypothesis_keywords'],
                in_array('validated_hypotheses', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_FAILED_ASSUMPTIONS] =
            "## Failed Assumptions\n"
            . $this->bulletsEmphasized(
                $failed,
                array_values(array_unique(array_merge($perspective['risk_keywords'], $perspective['hypothesis_keywords']))),
                in_array('failed_assumptions', $perspective['emphasis_fields'], true),
                50
            ) . "\n";

        $chainLines = $this->formatRecentMemories($memRows, $opts['max_memories'], true);
        $sections[MemorySnapshotPerspectiveRegistry::SECTION_DECISION_CHAIN] =
            "## Decision Chain\n"
            . ($chainLines !== '' ? $chainLines : "- —\n")
            . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_RECOMMENDED_NEXT_ACTIONS] =
            "## Recommended Next Actions\n"
            . $this->bulletsEmphasized(
                $nextActions,
                array_values(array_unique(array_merge($perspective['risk_keywords'], $perspective['hypothesis_keywords']))),
                false,
                50
            ) . "\n";

        $sessionIds = $this->rooms->linkedSessionIds($roomId);
        sort($sessionIds, SORT_STRING);
        $sections[MemorySnapshotPerspectiveRegistry::SECTION_LINKED_SESSIONS] =
            "## Linked Sessions\n"
            . $this->bullets($sessionIds, 200) . "\n";

        $sections[MemorySnapshotPerspectiveRegistry::SECTION_PERSPECTIVE_RELEVANCE] =
            $this->buildPerspectiveRelevanceBlock($risks, $hypotheses, $failed, $opts['perspective']);

        $meta = $this->collectVersions($memRows);
        $safety = "## Safety / Metadata\n"
            . '- Generated at: ' . $this->inline($generatedAt) . "\n"
            . '- Chain ID: ' . $this->inline($roomId) . "\n"
            . '- Context ID: ' . $this->inline((string)($room['context_id'] ?? '')) . "\n"
            . '- Perspective: ' . $this->inline($perspective['key']) . "\n"
            . '- Contract versions: ' . $this->inline(implode(', ', $meta['contract_versions'])) . "\n"
            . '- Taxonomy versions: ' . $this->inline(implode(', ', $meta['taxonomy_versions'])) . "\n"
            . '- Stale memories included: ' . ($opts['include_stale'] ? 'yes' : 'no') . "\n"
            . '- Invalidated memories included: no' . "\n"
            . '- Projection mode: deterministic (no AI inference; persisted fields only)' . "\n";
        $sections[MemorySnapshotPerspectiveRegistry::SECTION_SAFETY_METADATA] = $safety;

        $md = $this->assembleSections($sections, $perspective);
        if ($opts['include_expert_metadata']) {
            $md .= "\n<!-- expert_metadata=1 -->\n";
            $md .= '<!-- perspective=' . $perspective['key'] . " -->\n";
        }
        return rtrim($md) . "\n";
    }

    /**
     * Assemble named sections in the order defined by the perspective. Any
     * built section that the perspective does not list is appended at the
     * end (just before safety/relevance) so we never drop persisted info
     * silently. Hidden sections are removed entirely.
     *
     * @param array<string,string> $sections section_id => markdown chunk
     * @param array{prioritized_sections:list<string>, hidden_sections:list<string>} $perspective
     */
    private function assembleSections(array $sections, array $perspective): string
    {
        $order = [];
        foreach ($perspective['prioritized_sections'] as $id) {
            if (in_array($id, $perspective['hidden_sections'], true)) continue;
            if (isset($sections[$id])) {
                $order[$id] = true;
            }
        }
        $tailAnchors = [
            MemorySnapshotPerspectiveRegistry::SECTION_PERSPECTIVE_RELEVANCE,
            MemorySnapshotPerspectiveRegistry::SECTION_SAFETY_METADATA,
        ];
        foreach (array_keys($sections) as $id) {
            if (in_array($id, $perspective['hidden_sections'], true)) continue;
            if (!isset($order[$id]) && !in_array($id, $tailAnchors, true)) {
                $order[$id] = true;
            }
        }
        foreach ($tailAnchors as $id) {
            if (!in_array($id, $perspective['hidden_sections'], true) && isset($sections[$id])) {
                unset($order[$id]);
                $order[$id] = true;
            }
        }

        $out = '';
        foreach (array_keys($order) as $id) {
            $chunk = $sections[$id] ?? '';
            if ($chunk === '') continue;
            $out .= $chunk;
            if (substr($chunk, -1) !== "\n") $out .= "\n";
        }
        return $out;
    }

    /**
     * Render bullet list with optional emphasis re-ordering. Items matching
     * any keyword bubble up to the top (in stable alpha order) and are
     * prefixed with a star marker so the reader can see why they are first.
     * When emphasis is disabled or no keywords match, the result is a normal
     * alpha-sorted bullet list (exactly like ::bullets()).
     *
     * @param list<string> $items
     * @param list<string> $keywords
     */
    private function bulletsEmphasized(array $items, array $keywords, bool $emphasize, int $limit): string
    {
        $items = array_values(array_filter(array_map('strval', $items)));
        if ($items === []) return "- —\n";

        if ($emphasize && $keywords !== []) {
            $matched = [];
            $rest = [];
            foreach ($items as $x) {
                if (MemorySnapshotPerspectiveRegistry::matchesAny($x, $keywords)) {
                    $matched[] = $x;
                } else {
                    $rest[] = $x;
                }
            }
            sort($matched, SORT_STRING);
            sort($rest, SORT_STRING);
            $ordered = array_merge($matched, $rest);
            $ordered = array_slice($ordered, 0, max(1, $limit));
            $matchedSet = array_flip($matched);
            $out = '';
            foreach ($ordered as $x) {
                $prefix = isset($matchedSet[$x]) ? '★ ' : '';
                $out .= '- ' . $prefix . $this->inline($x) . "\n";
            }
            return $out;
        }

        return $this->bullets($items, $limit);
    }

    /**
     * Build the deterministic "## Perspective Relevance" block. The block
     * always lists every supported perspective so readers can see why their
     * current view emphasizes some fields. Buckets (none/low/medium/high)
     * are computed from a fixed mapping over keyword hit counts; no ML, no
     * AI, no semantic inference — only string matching.
     *
     * @param list<string> $risks
     * @param list<string> $hypotheses
     * @param list<string> $failed
     */
    private function buildPerspectiveRelevanceBlock(array $risks, array $hypotheses, array $failed, string $current): string
    {
        $allTexts = array_values(array_merge($risks, $hypotheses, $failed));
        $total = count($allTexts);

        $lines = "## Perspective Relevance\n";
        $lines .= '> Lightweight deterministic relevance tagging — keyword based, no semantic inference.' . "\n";

        foreach (MemorySnapshotPerspectiveRegistry::allKeys() as $key) {
            if ($key === MemorySnapshotPerspectiveRegistry::DEFAULT_KEY) continue;
            $cfg = MemorySnapshotPerspectiveRegistry::get($key);
            $hits = MemorySnapshotPerspectiveRegistry::countMatches($allTexts, $key);
            $bucket = MemorySnapshotPerspectiveRegistry::bucketForHits($hits, $total);
            $marker = ($key === $current) ? ' (current)' : '';
            $lines .= '- ' . $cfg['label'] . ' relevance: ' . $bucket
                . ' (' . $hits . '/' . $total . ' field matches)' . $marker . "\n";
        }
        $lines .= "\n";
        return $lines;
    }
}


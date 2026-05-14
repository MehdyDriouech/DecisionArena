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

    /** @param array{include_stale?:bool, include_archived?:bool, include_expert_metadata?:bool, max_memories?:int, now?:string} $options */
    public function generateContextMarkdown(string $contextId, array $options = []): string
    {
        $ctx = $this->contexts->find($contextId);
        if (!$ctx) {
            return "# Context not found\n";
        }

        $opts = $this->normalizeOptions($options);
        $generatedAt = $opts['now'];

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

        $md .= "## Decision Rooms / Chains\n\n";
        if ($rooms === []) {
            $md .= "_No decision rooms for this context._\n\n";
        }

        foreach ($rooms as $r) {
            $rid = (string)($r['room_id'] ?? '');
            if ($rid === '') continue;
            $roomState = $this->rooms->currentState($rid);
            $linkedIds = $roomMemIdsByRoom[$rid] ?? [];
            $roomMems = $this->fetchMemoriesByIds($linkedIds, $opts);

            $md .= '### ' . $this->h((string)($r['title'] ?? $rid)) . "\n";
            $md .= '- Room status: ' . $this->inline((string)($r['status'] ?? '')) . "\n";
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

    /** @param array{include_stale?:bool, include_archived?:bool, include_expert_metadata?:bool, max_memories?:int, now?:string} $options */
    public function generateRoomMarkdown(string $roomId, array $options = []): string
    {
        $room = $this->rooms->find($roomId);
        if (!$room) {
            return "# Room not found\n";
        }

        $opts = $this->normalizeOptions($options);
        $generatedAt = $opts['now'];

        $state = $this->rooms->currentState($roomId);
        $linkedMemIds = $this->rooms->linkedMemoryIds($roomId);
        $memRows = $this->fetchMemoriesByIds($linkedMemIds, $opts);

        $md = '';
        $md .= '# ' . $this->h((string)($room['title'] ?? $roomId)) . "\n\n";
        $md .= "> This snapshot is derived from prior decision records, not verified truth.\n\n";

        $md .= "## Current State\n";
        $md .= '- Room status: ' . $this->inline((string)($room['status'] ?? '')) . "\n";
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
        $md .= '- Room ID: ' . $this->inline($roomId) . "\n";
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

    /** @return array{include_stale:bool, include_archived:bool, include_expert_metadata:bool, max_memories:int, now:string} */
    private function normalizeOptions(array $options): array
    {
        $includeStale = ($options['include_stale'] ?? false) === true;
        $includeArchived = ($options['include_archived'] ?? false) === true;
        $includeExpert = ($options['include_expert_metadata'] ?? false) === true;
        $max = (int)($options['max_memories'] ?? self::DEFAULT_MAX_MEMORIES);
        $max = max(1, min(200, $max));
        $now = isset($options['now']) && is_string($options['now']) && $options['now'] !== '' ? $options['now'] : date('c');
        return [
            'include_stale' => $includeStale,
            'include_archived' => $includeArchived,
            'include_expert_metadata' => $includeExpert,
            'max_memories' => $max,
            'now' => $now,
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
}


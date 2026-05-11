<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Infrastructure\Persistence\StrategicContextMemoryGovernanceEventRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Gouvernance officielle de cognition persistante (lecture + audit append-only).
 */
final class MemoryGovernanceService
{
    public const GOVERNANCE_STATUSES = ['pending', 'stable', 'contested', 'archived', 'deprecated', 'invalidated'];
    public const PROVENANCE_LEVELS = ['explicit', 'derived', 'system'];
    public const EVENT_TYPES = ['creation', 'promotion', 'invalidation', 'contradiction', 'compaction', 'archiving', 'status_change'];
    public const ENTITY_TYPES = ['belief', 'agent_memory', 'memory_compilation', 'snapshot', 'narrative'];

    public function __construct(
        private ?StrategicContextRepository $contexts = null,
        private ?BeliefEngineService $beliefs = null,
        private ?MemoryCompilerService $compilations = null,
        private ?ContextSnapshotService $snapshots = null,
        private ?StrategicNarrativeService $narrative = null,
        private ?AgentContextMemoryService $agentMemory = null,
        private ?StrategicContextMemoryGovernanceEventRepository $events = null,
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->beliefs = $beliefs ?? new BeliefEngineService();
        $this->compilations = $compilations ?? new MemoryCompilerService();
        $this->snapshots = $snapshots ?? new ContextSnapshotService();
        $this->narrative = $narrative ?? new StrategicNarrativeService();
        $this->agentMemory = $agentMemory ?? new AgentContextMemoryService();
        $this->events = $events ?? new StrategicContextMemoryGovernanceEventRepository();
    }

    /** @return array<string,mixed> */
    public function governanceModel(): array
    {
        return [
            'statuses' => self::GOVERNANCE_STATUSES,
            'provenance_levels' => self::PROVENANCE_LEVELS,
            'trust_range' => [0.0, 1.0],
            'promotion_rules' => [
                'pending -> stable requires explicit write + confidence >= 0.6',
                'contested -> stable requires contradiction resolution event',
                'deprecated/invalidated are never auto-promoted',
            ],
            'invalidation_rules' => [
                'Any invalidation requires actor_id or reason',
                'Invalidated/deprecated items stay visible in runtime as low-priority signals',
                'No silent physical delete; archive through status + append-only event',
            ],
            'hierarchy' => [
                ['level' => 1, 'entity_type' => 'belief', 'note' => 'Canonical contextual cognitive record.'],
                ['level' => 2, 'entity_type' => 'agent_memory', 'note' => 'Operational contextual notes (file-based).'],
                ['level' => 3, 'entity_type' => 'memory_compilation', 'note' => 'Derived synthesis, supersedable.'],
                ['level' => 4, 'entity_type' => 'snapshot', 'note' => 'Frozen immutable captures.'],
                ['level' => 5, 'entity_type' => 'narrative', 'note' => 'Derived narrative echo, never canonical fact.'],
            ],
        ];
    }

    /** @param array<string,mixed> $meta */
    public function logEvent(
        string $contextId,
        string $entityType,
        string $entityId,
        string $eventType,
        array $meta = []
    ): void {
        $ctx = trim($contextId);
        if ($ctx === '' || !$this->contexts->find($ctx)) {
            return;
        }
        $entityType = strtolower(trim($entityType));
        $entityId = trim($entityId);
        $eventType = strtolower(trim($eventType));
        if ($entityId === '' || !in_array($entityType, self::ENTITY_TYPES, true) || !in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException('invalid governance event envelope');
        }
        $status = strtolower(trim((string)($meta['governance_status'] ?? 'pending')));
        if (!in_array($status, self::GOVERNANCE_STATUSES, true)) {
            $status = 'pending';
        }
        $prov = strtolower(trim((string)($meta['provenance_level'] ?? 'explicit')));
        if (!in_array($prov, self::PROVENANCE_LEVELS, true)) {
            $prov = 'explicit';
        }
        $trust = isset($meta['trust_level']) ? (float)$meta['trust_level'] : 0.5;
        $trust = max(0.0, min(1.0, $trust));
        if ($prov === 'derived') {
            $trust = min(0.9, $trust);
        } elseif ($prov === 'system') {
            $trust = min(0.85, $trust);
        }
        $actorId = $this->nullableTrim($meta['actor_id'] ?? null);
        $reason = $this->nullableTrim($meta['reason'] ?? null);
        if ($eventType === 'invalidation' && $actorId === null && $reason === null) {
            throw new \InvalidArgumentException('invalidation requires actor_id or reason');
        }
        $now = date('c');
        $this->events->insert([
            'event_id' => $this->uuid(),
            'strategic_context_id' => $ctx,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'agent_id' => $this->nullableTrim($meta['agent_id'] ?? null),
            'event_type' => $eventType,
            'governance_status' => $status,
            'provenance_level' => $prov,
            'trust_level' => $trust,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata_json' => json_encode($meta['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'occurred_at' => (string)($meta['occurred_at'] ?? $now),
            'created_at' => $now,
        ]);
    }

    /** @return array<string,mixed> */
    public function getContextGovernance(string $contextId, int $limit = 180): array
    {
        $cid = trim($contextId);
        if ($cid === '' || !$this->contexts->find($cid)) {
            return [
                'context_id' => $cid,
                'governance_model' => $this->governanceModel(),
                'items' => [],
                'counts' => [],
                'recent_events' => [],
                'risks' => ['context_not_found'],
            ];
        }

        $items = [];
        $items = array_merge($items, $this->beliefGovernanceItems($cid));
        $items = array_merge($items, $this->compilationGovernanceItems($cid));
        $items = array_merge($items, $this->snapshotGovernanceItems($cid));
        $items = array_merge($items, $this->narrativeGovernanceItems($cid));
        $items = array_merge($items, $this->agentMemoryGovernanceItems($cid));

        $events = $this->events->listForContext($cid, max(80, $limit));
        $statsByEntity = $this->entityEventStats($events);
        foreach ($items as &$it) {
            $k = (string)($it['entity_type'] ?? '') . '|' . (string)($it['entity_id'] ?? '');
            $s = $statsByEntity[$k] ?? [
                'invalidation_count' => 0,
                'contradiction_count' => 0,
                'promotion_count' => 0,
                'last_event_at' => null,
            ];
            $it['invalidation_count'] = $s['invalidation_count'];
            $it['contradiction_count'] = $s['contradiction_count'];
            $it['promotion_count'] = $s['promotion_count'];
            $it['last_event_at'] = $s['last_event_at'];
        }
        unset($it);

        usort($items, static function (array $a, array $b): int {
            $pa = (int)($a['hierarchy_level'] ?? 99);
            $pb = (int)($b['hierarchy_level'] ?? 99);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        $counts = array_fill_keys(self::GOVERNANCE_STATUSES, 0);
        foreach ($items as $it) {
            $st = (string)($it['governance_status'] ?? 'pending');
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }
        $risks = [];
        if (($counts['contested'] ?? 0) > 0) {
            $risks[] = 'contested_items_present';
        }
        if (($counts['invalidated'] ?? 0) > 0) {
            $risks[] = 'invalidated_items_require_runtime_visibility';
        }
        if (($counts['pending'] ?? 0) > max(3, (int)floor(count($items) * 0.35))) {
            $risks[] = 'high_pending_ratio';
        }

        return [
            'context_id' => $cid,
            'governance_model' => $this->governanceModel(),
            'counts' => $counts,
            'items' => $items,
            'recent_events' => array_map(fn (array $ev): array => $this->eventToApi($ev), array_slice($events, 0, $limit)),
            'risks' => $risks,
        ];
    }

    /** @param array<string,mixed> $belief */
    public function governanceStatusFromBelief(array $belief): string
    {
        $status = strtolower(trim((string)($belief['status'] ?? '')));
        return match ($status) {
            'proposed' => 'pending',
            'active', 'reinforced' => 'stable',
            'disputed' => 'contested',
            'archived' => 'archived',
            'deprecated' => 'deprecated',
            'invalidated' => 'invalidated',
            default => 'pending',
        };
    }

    private function governanceStatusFromCompilationStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active' => 'stable',
            'archived' => 'archived',
            'deprecated', 'superseded' => 'deprecated',
            default => 'pending',
        };
    }

    /** @return list<array<string,mixed>> */
    private function beliefGovernanceItems(string $contextId): array
    {
        $rows = $this->beliefs->listBeliefsForContext($contextId, ['limit' => 320]);
        $out = [];
        foreach ($rows as $b) {
            $out[] = [
                'entity_type' => 'belief',
                'entity_id' => (string)($b['id'] ?? ''),
                'title' => $this->ellipsis((string)($b['belief_text'] ?? ''), 120),
                'governance_status' => $this->governanceStatusFromBelief($b),
                'provenance_level' => 'explicit',
                'trust_level' => max(0.0, min(1.0, (float)($b['confidence'] ?? 0.5))),
                'hierarchy_level' => 1,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function compilationGovernanceItems(string $contextId): array
    {
        $rows = $this->compilations->listCompilations($contextId, ['limit' => 100]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'entity_type' => 'memory_compilation',
                'entity_id' => (string)($r['id'] ?? ''),
                'title' => (string)($r['title'] ?? 'memory compilation'),
                'governance_status' => $this->governanceStatusFromCompilationStatus((string)($r['status'] ?? '')),
                'provenance_level' => 'derived',
                'trust_level' => max(0.0, min(1.0, (float)($r['confidence'] ?? 0.5))),
                'hierarchy_level' => 3,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function snapshotGovernanceItems(string $contextId): array
    {
        $rows = $this->snapshots->listSnapshots($contextId, ['limit' => 80]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'entity_type' => 'snapshot',
                'entity_id' => (string)($r['id'] ?? ''),
                'title' => (string)($r['title'] ?? 'snapshot'),
                'governance_status' => 'archived',
                'provenance_level' => 'derived',
                'trust_level' => 0.85,
                'hierarchy_level' => 4,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function narrativeGovernanceItems(string $contextId): array
    {
        $nar = $this->narrative->getApiResponse($contextId);
        $warnings = is_array($nar['warnings'] ?? null) ? $nar['warnings'] : [];
        $status = $warnings === [] ? 'stable' : 'contested';
        if (in_array('narrative_not_computed', array_map('strval', $warnings), true)) {
            $status = 'pending';
        }
        return [[
            'entity_type' => 'narrative',
            'entity_id' => 'strategic_narrative',
            'title' => 'Strategic narrative (derived)',
            'governance_status' => $status,
            'provenance_level' => 'derived',
            'trust_level' => $status === 'stable' ? 0.75 : 0.55,
            'hierarchy_level' => 5,
        ]];
    }

    /** @return list<array<string,mixed>> */
    private function agentMemoryGovernanceItems(string $contextId): array
    {
        $agents = ['pm', 'architect', 'critic', 'ux-expert', 'po', 'synthesizer', 'risk_analyst', 'devil_advocate'];
        $out = [];
        foreach ($agents as $aid) {
            $r = $this->agentMemory->readIfExistsNoSideEffects($contextId, $aid);
            if (!($r['exists'] ?? false)) {
                continue;
            }
            $content = (string)($r['content'] ?? '');
            $status = 'stable';
            if (preg_match('/##\s+Pending Consolidation Notes\s*\n\s*-/u', $content) === 1) {
                $status = 'pending';
            }
            if (preg_match('/##\s+Contradictions To Review\s*\n\s*-/u', $content) === 1) {
                $status = 'contested';
            }
            if (preg_match('/##\s+Deprecated \/ Forgotten\s*\n\s*-/u', $content) === 1 && $status !== 'contested') {
                $status = 'deprecated';
            }
            $out[] = [
                'entity_type' => 'agent_memory',
                'entity_id' => 'agent:' . $aid,
                'title' => 'Agent memory ' . $aid,
                'governance_status' => $status,
                'provenance_level' => 'explicit',
                'trust_level' => $status === 'stable' ? 0.6 : 0.45,
                'hierarchy_level' => 2,
            ];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $events @return array<string,array<string,mixed>> */
    private function entityEventStats(array $events): array
    {
        $out = [];
        foreach ($events as $ev) {
            $k = (string)($ev['entity_type'] ?? '') . '|' . (string)($ev['entity_id'] ?? '');
            if (!isset($out[$k])) {
                $out[$k] = [
                    'invalidation_count' => 0,
                    'contradiction_count' => 0,
                    'promotion_count' => 0,
                    'last_event_at' => null,
                ];
            }
            $typ = strtolower(trim((string)($ev['event_type'] ?? '')));
            if (in_array($typ, ['invalidation', 'deprecation'], true)) {
                $out[$k]['invalidation_count']++;
            }
            if ($typ === 'contradiction') {
                $out[$k]['contradiction_count']++;
            }
            if ($typ === 'promotion') {
                $out[$k]['promotion_count']++;
            }
            if ($out[$k]['last_event_at'] === null) {
                $out[$k]['last_event_at'] = (string)($ev['occurred_at'] ?? '');
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $ev @return array<string,mixed> */
    private function eventToApi(array $ev): array
    {
        $meta = json_decode((string)($ev['metadata_json'] ?? '{}'), true);
        return [
            'event_id' => (string)($ev['event_id'] ?? ''),
            'entity_type' => (string)($ev['entity_type'] ?? ''),
            'entity_id' => (string)($ev['entity_id'] ?? ''),
            'event_type' => (string)($ev['event_type'] ?? ''),
            'governance_status' => (string)($ev['governance_status'] ?? 'pending'),
            'provenance_level' => (string)($ev['provenance_level'] ?? 'explicit'),
            'trust_level' => isset($ev['trust_level']) ? (float)$ev['trust_level'] : 0.5,
            'actor_id' => $this->nullableTrim($ev['actor_id'] ?? null),
            'reason' => $this->nullableTrim($ev['reason'] ?? null),
            'metadata' => is_array($meta) ? $meta : [],
            'occurred_at' => (string)($ev['occurred_at'] ?? ''),
        ];
    }

    private function nullableTrim(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s !== '' ? $s : null;
    }

    private function ellipsis(string $s, int $max): string
    {
        $t = trim($s);
        if ($t === '') {
            return '—';
        }
        if (mb_strlen($t, 'UTF-8') <= $max) {
            return $t;
        }
        return mb_substr($t, 0, max(1, $max - 1), 'UTF-8') . '…';
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\CognitiveGovernance\CognitiveProvenanceEnvelope;
use Infrastructure\Persistence\StrategicContextBeliefRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Beliefs Engine MVP : persistance explicite, contextualisée, auditable.
 * Aucune invention automatique · pas de LLM · pas de fusion sémantique · pas de toucher à memory.md.
 */
final class BeliefEngineService
{
    public const BELIEF_TYPES = ['fact', 'belief', 'hypothesis', 'interpretation', 'social_perception'];

    public const STATUSES = ['proposed', 'active', 'disputed', 'deprecated', 'archived', 'invalidated', 'reinforced'];

    public const CONTESTATION_STATES = ['stable', 'contested', 'weak', 'reinforced', 'invalidated', 'archived', 'unstable', 'derived'];

    public const SOURCE_TYPES = ['session', 'evidence', 'relationship_event', 'memory', 'user', 'manual'];
    /** @var array<string,list<string>> */
    private const STATUS_TRANSITIONS = [
        'proposed' => ['active', 'disputed', 'deprecated', 'invalidated', 'archived'],
        'active' => ['reinforced', 'disputed', 'deprecated', 'invalidated', 'archived'],
        'reinforced' => ['active', 'disputed', 'deprecated', 'invalidated', 'archived'],
        'disputed' => ['active', 'reinforced', 'deprecated', 'invalidated', 'archived'],
        'deprecated' => ['archived', 'invalidated'],
        'invalidated' => ['archived'],
        'archived' => [],
    ];

    public function __construct(
        private ?StrategicContextBeliefRepository $beliefs = null,
        private ?StrategicContextRepository $contexts = null,
    ) {
        $this->beliefs = $beliefs ?? new StrategicContextBeliefRepository();
        $this->contexts = $contexts ?? new StrategicContextRepository();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:true,belief:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function createBelief(string $contextId, array $payload): array
    {
        $ctx = trim($contextId);
        if (!$this->contextExists($ctx)) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        $text = trim((string)($payload['belief_text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'message' => 'belief_text required', 'code' => 400];
        }
        $type = strtolower(trim((string)($payload['belief_type'] ?? '')));
        if (!in_array($type, self::BELIEF_TYPES, true)) {
            return ['ok' => false, 'message' => 'invalid belief_type', 'code' => 400];
        }
        $status = strtolower(trim((string)($payload['status'] ?? 'proposed')));
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'message' => 'invalid status', 'code' => 400];
        }
        $agentId = $this->normalizeAgentId($payload['agent_id'] ?? null);
        if ($agentId === false) {
            return ['ok' => false, 'message' => 'invalid agent_id', 'code' => 400];
        }
        $conf = $this->normalizeConfidence($payload['confidence'] ?? 0.5);
        $srcType = $this->normalizeSourceType($payload['source_type'] ?? null);
        if ($srcType === false) {
            return ['ok' => false, 'message' => 'invalid source_type', 'code' => 400];
        }
        $srcRef = trim((string)($payload['source_reference_id'] ?? ''));
        $srcRef = $srcRef !== '' ? $srcRef : null;
        $createdBy = trim((string)($payload['created_by'] ?? ''));
        $createdBy = $createdBy !== '' ? $createdBy : null;

        $evidence = $this->jsonStringList($payload['evidence_sources'] ?? []);
        $support = $this->jsonStringList($payload['supporting_agents'] ?? []);
        $disagree = $this->jsonStringList($payload['disagreeing_agents'] ?? []);
        $sessions = $this->jsonStringList($payload['related_session_ids'] ?? []);
        $contestedBy = $this->jsonStringList($payload['contested_by_agents'] ?? []);
        $parentBeliefId = $this->normalizeBeliefRef($payload['parent_belief_id'] ?? null);
        $supersedesBeliefId = $this->normalizeBeliefRef($payload['supersedes_belief_id'] ?? null);
        $invalidatedBy = $this->normalizeBeliefRef($payload['invalidated_by'] ?? null);
        $invalidationReason = $this->normalizeNullableString($payload['invalidation_reason'] ?? null);

        $now = date('c');
        $id = $this->uuid();
        $confidenceHistory = [[
            'at' => $now,
            'confidence' => $conf,
            'actor' => $createdBy ?? 'system',
            'reason' => 'created',
        ]];
        $row = [
            'id' => $id,
            'strategic_context_id' => $ctx,
            'agent_id' => $agentId,
            'belief_type' => $type,
            'belief_text' => $text,
            'confidence' => $conf,
            'status' => $status,
            'evidence_sources_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'supporting_agents_json' => json_encode($support, JSON_UNESCAPED_UNICODE),
            'disagreeing_agents_json' => json_encode($disagree, JSON_UNESCAPED_UNICODE),
            'related_session_ids_json' => json_encode($sessions, JSON_UNESCAPED_UNICODE),
            'source_type' => $srcType,
            'source_reference_id' => $srcRef,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
            'last_reviewed_at' => null,
            'parent_belief_id' => $parentBeliefId,
            'supersedes_belief_id' => $supersedesBeliefId,
            'invalidated_by' => $invalidatedBy,
            'invalidation_reason' => $invalidationReason,
            'confidence_history_json' => json_encode($confidenceHistory, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'drift_score' => 0.0,
            'consensus_score' => 0.0,
            'contested_by_agents_json' => json_encode($contestedBy, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'contestation_state' => 'weak',
        ];
        $derived = $this->computeDerivedScores($row);
        $row['drift_score'] = $derived['drift_score'];
        $row['consensus_score'] = $derived['consensus_score'];
        $row['contestation_state'] = $derived['contestation_state'];
        $this->beliefs->insert($row);
        $this->syncAgentPositions($ctx, $id, $support, $disagree);
        $this->syncDeterministicRelations($row);
        $this->appendBeliefEvent($ctx, $id, 'created', $createdBy, null, [
            'status' => $status,
            'confidence' => $conf,
            'contestation_state' => $row['contestation_state'],
        ]);

        return ['ok' => true, 'belief' => $this->toApiBelief($row)];
    }

    /**
     * @param array<string,mixed> $patch
     * @return array{ok:true,belief:array<string,mixed>}|array{ok:false,message:string,code:int}
     */
    public function updateBelief(string $contextId, string $beliefId, array $patch): array
    {
        $ctx = trim($contextId);
        $bid = trim($beliefId);
        if (!$this->contextExists($ctx)) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }
        $cur = $this->beliefs->findByIdInContext($bid, $ctx);
        if ($cur === null) {
            return ['ok' => false, 'message' => 'Belief not found', 'code' => 404];
        }
        $dbPatch = [];
        $metaPatch = [];
        if (array_key_exists('belief_text', $patch)) {
            $t = trim((string)$patch['belief_text']);
            if ($t === '') {
                return ['ok' => false, 'message' => 'belief_text cannot be empty', 'code' => 400];
            }
            $dbPatch['belief_text'] = $t;
        }
        if (array_key_exists('belief_type', $patch)) {
            $type = strtolower(trim((string)$patch['belief_type']));
            if (!in_array($type, self::BELIEF_TYPES, true)) {
                return ['ok' => false, 'message' => 'invalid belief_type', 'code' => 400];
            }
            $dbPatch['belief_type'] = $type;
        }
        if (array_key_exists('status', $patch)) {
            $st = strtolower(trim((string)$patch['status']));
            if (!in_array($st, self::STATUSES, true)) {
                return ['ok' => false, 'message' => 'invalid status', 'code' => 400];
            }
            $fromStatus = strtolower(trim((string)($cur['status'] ?? 'proposed')));
            if ($st !== $fromStatus && !$this->canTransitionStatus($fromStatus, $st)) {
                return ['ok' => false, 'message' => 'invalid status transition', 'code' => 400];
            }
            if ($st === 'invalidated') {
                $nextInvalidatedBy = array_key_exists('invalidated_by', $patch)
                    ? $this->normalizeBeliefRef($patch['invalidated_by'])
                    : $this->normalizeBeliefRef($cur['invalidated_by'] ?? null);
                $nextReason = array_key_exists('invalidation_reason', $patch)
                    ? $this->normalizeNullableString($patch['invalidation_reason'])
                    : $this->normalizeNullableString($cur['invalidation_reason'] ?? null);
                if ($nextInvalidatedBy === null && $nextReason === null) {
                    return ['ok' => false, 'message' => 'invalidated status requires invalidated_by or invalidation_reason', 'code' => 400];
                }
            }
            $dbPatch['status'] = $st;
        }
        if (array_key_exists('confidence', $patch)) {
            $dbPatch['confidence'] = $this->normalizeConfidence($patch['confidence']);
        }
        if (array_key_exists('agent_id', $patch)) {
            $aid = $this->normalizeAgentId($patch['agent_id']);
            if ($aid === false) {
                return ['ok' => false, 'message' => 'invalid agent_id', 'code' => 400];
            }
            $dbPatch['agent_id'] = $aid;
        }
        if (array_key_exists('evidence_sources', $patch)) {
            $dbPatch['evidence_sources_json'] = json_encode($this->jsonStringList($patch['evidence_sources']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('supporting_agents', $patch)) {
            $dbPatch['supporting_agents_json'] = json_encode($this->jsonStringList($patch['supporting_agents']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('disagreeing_agents', $patch)) {
            $dbPatch['disagreeing_agents_json'] = json_encode($this->jsonStringList($patch['disagreeing_agents']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('related_session_ids', $patch)) {
            $dbPatch['related_session_ids_json'] = json_encode($this->jsonStringList($patch['related_session_ids']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('parent_belief_id', $patch)) {
            $dbPatch['parent_belief_id'] = $this->normalizeBeliefRef($patch['parent_belief_id']);
        }
        if (array_key_exists('supersedes_belief_id', $patch)) {
            $dbPatch['supersedes_belief_id'] = $this->normalizeBeliefRef($patch['supersedes_belief_id']);
        }
        if (array_key_exists('invalidated_by', $patch)) {
            $dbPatch['invalidated_by'] = $this->normalizeBeliefRef($patch['invalidated_by']);
        }
        if (array_key_exists('invalidation_reason', $patch)) {
            $dbPatch['invalidation_reason'] = $this->normalizeNullableString($patch['invalidation_reason']);
        }
        if (array_key_exists('contested_by_agents', $patch)) {
            $dbPatch['contested_by_agents_json'] = json_encode($this->jsonStringList($patch['contested_by_agents']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (array_key_exists('contestation_state', $patch)) {
            $state = strtolower(trim((string)$patch['contestation_state']));
            if (!in_array($state, self::CONTESTATION_STATES, true)) {
                return ['ok' => false, 'message' => 'invalid contestation_state', 'code' => 400];
            }
            $dbPatch['contestation_state'] = $state;
            $metaPatch['manual_contestation_state'] = true;
        }
        if (array_key_exists('source_type', $patch)) {
            $raw = $patch['source_type'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $dbPatch['source_type'] = null;
            } else {
                $st = $this->normalizeSourceType($raw);
                if ($st === false) {
                    return ['ok' => false, 'message' => 'invalid source_type', 'code' => 400];
                }
                $dbPatch['source_type'] = $st;
            }
        }
        if (array_key_exists('source_reference_id', $patch)) {
            $r = trim((string)$patch['source_reference_id']);
            $dbPatch['source_reference_id'] = $r !== '' ? $r : null;
        }
        if (array_key_exists('created_by', $patch)) {
            $c = trim((string)$patch['created_by']);
            $dbPatch['created_by'] = $c !== '' ? $c : null;
        }
        if (array_key_exists('last_reviewed_at', $patch)) {
            $lr = trim((string)$patch['last_reviewed_at']);
            $dbPatch['last_reviewed_at'] = $lr !== '' ? $lr : null;
        }
        if (array_key_exists('confidence', $dbPatch)) {
            $dbPatch['confidence_history_json'] = $this->appendConfidenceHistory(
                (string)($cur['confidence_history_json'] ?? '[]'),
                (float)$dbPatch['confidence'],
                $this->normalizeNullableString($patch['created_by'] ?? null) ?? (string)($cur['created_by'] ?? 'system'),
                $this->normalizeNullableString($patch['confidence_reason'] ?? null) ?? 'confidence_adjusted'
            );
        }

        if ($dbPatch === []) {
            return ['ok' => true, 'belief' => $this->toApiBelief($cur)];
        }
        $next = array_merge($cur, $dbPatch);
        $derived = $this->computeDerivedScores($next);
        $dbPatch['drift_score'] = $derived['drift_score'];
        $dbPatch['consensus_score'] = $derived['consensus_score'];
        if (empty($metaPatch['manual_contestation_state'])) {
            $dbPatch['contestation_state'] = $derived['contestation_state'];
        }
        $ok = $this->beliefs->update($bid, $ctx, $dbPatch);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Nothing to update', 'code' => 400];
        }
        $fresh = $this->beliefs->findByIdInContext($bid, $ctx);
        if ($fresh !== null) {
            $this->syncAgentPositions(
                $ctx,
                $bid,
                $this->decodeList((string)($fresh['supporting_agents_json'] ?? '[]')),
                $this->decodeList((string)($fresh['disagreeing_agents_json'] ?? '[]'))
            );
            if (array_key_exists('relations', $patch)) {
                $relations = $this->normalizeRelations($patch['relations'] ?? [], $ctx);
                $this->beliefs->replaceRelationsForBelief($ctx, $bid, $relations);
            } elseif (
                array_key_exists('parent_belief_id', $patch)
                || array_key_exists('supersedes_belief_id', $patch)
                || array_key_exists('source_type', $patch)
                || array_key_exists('source_reference_id', $patch)
            ) {
                $this->syncDeterministicRelations($fresh);
            }
            $this->appendEventsFromDiff($ctx, $cur, $fresh, $patch);
        }

        return ['ok' => true, 'belief' => $this->toApiBelief($fresh ?? $cur)];
    }

    /**
     * @param array<string,mixed> $query agent_id?, belief_type?, status?, disputed_only? (bool as 1/0)
     * @return list<array<string,mixed>>
     */
    public function listBeliefsForContext(string $contextId, array $query = []): array
    {
        $ctx = trim($contextId);
        if (!$this->contextExists($ctx)) {
            return [];
        }
        $filters = ['limit' => (int)($query['limit'] ?? 400)];
        if (array_key_exists('agent_id', $query)) {
            $filters['agent_id'] = $query['agent_id'];
        }
        if (!empty($query['belief_type'])) {
            $filters['belief_type'] = (string)$query['belief_type'];
        }
        if (!empty($query['status'])) {
            $filters['status'] = (string)$query['status'];
        }
        if (!empty($query['contestation_state'])) {
            $filters['contestation_state'] = (string)$query['contestation_state'];
        }
        $rows = $this->beliefs->listForContext($ctx, $filters);
        if (!empty($query['disputed_only'])) {
            $rows = array_values(array_filter($rows, static function (array $r): bool {
                if (($r['status'] ?? '') === 'disputed') {
                    return true;
                }
                $d = $r['disagreeing_agents_json'] ?? '[]';
                $dec = is_string($d) ? json_decode($d, true) : [];
                return is_array($dec) && $dec !== [];
            }));
        }

        return array_map(fn (array $r) => $this->toApiBelief($r), $rows);
    }

    /**
     * GET /api/beliefs (query global, scoped explicitement via context_id).
     * @param array<string,mixed> $query
     * @return list<array<string,mixed>>
     */
    public function listBeliefsGlobal(array $query = []): array
    {
        $filters = [
            'strategic_context_id' => trim((string)($query['context_id'] ?? '')),
            'belief_type' => $query['belief_type'] ?? null,
            'status' => $query['status'] ?? null,
            'contestation_state' => $query['contestation_state'] ?? null,
            'agent_id' => $query['agent_id'] ?? null,
            'q' => $query['q'] ?? null,
        ];
        $limit = (int)($query['limit'] ?? 200);
        $offset = (int)($query['offset'] ?? 0);
        $rows = $this->beliefs->listGlobal($filters, $limit, $offset);

        return array_map(fn (array $r) => $this->toApiBelief($r), $rows);
    }

    /** @return array{ok:true,belief:array<string,mixed>}|array{ok:false,message:string,code:int} */
    public function getBeliefById(string $beliefId, ?string $contextId = null): array
    {
        $bid = trim($beliefId);
        if ($bid === '') {
            return ['ok' => false, 'message' => 'Invalid belief id', 'code' => 400];
        }
        $row = null;
        $ctx = $contextId !== null ? trim($contextId) : '';
        if ($ctx !== '') {
            $row = $this->beliefs->findByIdInContext($bid, $ctx);
        } else {
            $row = $this->beliefs->findById($bid);
        }
        if ($row === null) {
            return ['ok' => false, 'message' => 'Belief not found', 'code' => 404];
        }

        return ['ok' => true, 'belief' => $this->toApiBelief($row)];
    }

    /** @return list<array<string,mixed>> */
    public function getBeliefTimeline(string $beliefId, ?string $contextId = null, int $limit = 400): array
    {
        $events = $this->beliefs->listEventsForBelief(trim($beliefId), $contextId !== null ? trim($contextId) : null, $limit);

        return array_map(function (array $ev): array {
            $payload = json_decode((string)($ev['payload_json'] ?? '{}'), true);
            return [
                'event_id' => (string)($ev['event_id'] ?? ''),
                'belief_id' => (string)($ev['belief_id'] ?? ''),
                'strategic_context_id' => (string)($ev['strategic_context_id'] ?? ''),
                'event_type' => (string)($ev['event_type'] ?? ''),
                'actor_id' => $this->normalizeNullableString($ev['actor_id'] ?? null),
                'reason' => $this->normalizeNullableString($ev['reason'] ?? null),
                'payload' => is_array($payload) ? $payload : [],
                'occurred_at' => (string)($ev['occurred_at'] ?? ''),
            ];
        }, $events);
    }

    /** @return list<array<string,mixed>> */
    public function getBeliefRelations(string $beliefId, ?string $contextId = null): array
    {
        $ctx = '';
        if ($contextId !== null) {
            $ctx = trim($contextId);
        }
        if ($ctx === '') {
            $row = $this->beliefs->findById(trim($beliefId));
            if ($row === null) {
                return [];
            }
            $ctx = (string)($row['strategic_context_id'] ?? '');
        }
        if ($ctx === '') {
            return [];
        }
        $rows = $this->beliefs->listRelationsForBelief($ctx, trim($beliefId));

        return array_map(function (array $r): array {
            $meta = json_decode((string)($r['metadata_json'] ?? '{}'), true);
            return [
                'relation_type' => (string)($r['relation_type'] ?? ''),
                'to_entity_type' => (string)($r['to_entity_type'] ?? ''),
                'to_entity_id' => (string)($r['to_entity_id'] ?? ''),
                'metadata' => is_array($meta) ? $meta : [],
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    public function getBeliefsRuntimeProjection(string $contextId): array
    {
        $ctx = trim($contextId);
        if ($ctx === '' || !$this->contextExists($ctx)) {
            return [
                'context_id' => $ctx,
                'counts' => [],
                'beliefs' => [],
                'contested' => [],
                'invalidated' => [],
                'timeline' => [],
            ];
        }
        $rows = $this->beliefs->listForContext($ctx, ['limit' => 500]);
        $api = array_map(fn (array $r) => $this->toApiBelief($r), $rows);
        $counts = [
            'total' => count($api),
            'stable' => 0,
            'contested' => 0,
            'weak' => 0,
            'reinforced' => 0,
            'invalidated' => 0,
            'archived' => 0,
            'unstable' => 0,
            'derived' => 0,
        ];
        $contested = [];
        $invalidated = [];
        foreach ($api as $b) {
            $st = (string)($b['contestation_state'] ?? 'weak');
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
            if (in_array($st, ['contested', 'unstable'], true)) {
                $contested[] = $b;
            }
            if ($st === 'invalidated') {
                $invalidated[] = $b;
            }
        }
        $timeline = [];
        foreach ($api as $b) {
            $events = $this->beliefs->listEventsForBelief((string)$b['id'], $ctx, 20);
            $timeline[] = [
                'belief_id' => $b['id'],
                'belief_text' => $b['belief_text'],
                'events' => array_map(static fn (array $e): array => [
                    'event_type' => (string)($e['event_type'] ?? ''),
                    'occurred_at' => (string)($e['occurred_at'] ?? ''),
                ], $events),
            ];
        }

        return [
            'context_id' => $ctx,
            'counts' => $counts,
            'beliefs' => $api,
            'contested' => $contested,
            'invalidated' => $invalidated,
            'timeline' => $timeline,
            'graph' => $this->buildBeliefGraph($ctx, $api),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listBeliefsForAgentInContext(string $contextId, string $agentId): array
    {
        $aid = trim($agentId);
        if ($aid === '') {
            return [];
        }
        if (!$this->contextExists(trim($contextId))) {
            return [];
        }

        return $this->listBeliefsForContext($contextId, ['agent_id' => $aid]);
    }

    /** @return list<array<string,mixed>> */
    public function listDisputedBeliefs(string $contextId): array
    {
        $all = $this->listBeliefsForContext($contextId, ['limit' => 500]);
        $out = [];
        foreach ($all as $b) {
            if (($b['status'] ?? '') === 'disputed') {
                $out[] = $b;
                continue;
            }
            $dg = $b['disagreeing_agents'] ?? [];
            if (is_array($dg) && $dg !== []) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /** @return array{ok:true,belief:array<string,mixed>}|array{ok:false,message:string,code:int} */
    public function archiveBelief(string $contextId, string $beliefId): array
    {
        return $this->updateBelief($contextId, $beliefId, ['status' => 'archived']);
    }

    /** @return array{ok:true,belief:array<string,mixed>}|array{ok:false,message:string,code:int} */
    public function deprecateBelief(string $contextId, string $beliefId): array
    {
        return $this->updateBelief($contextId, $beliefId, ['status' => 'deprecated']);
    }

    /**
     * Lecture seule pour enrichissement narrative (aucune mutation des beliefs).
     *
     * @return list<array<string,mixed>> format API `belief` (même forme que les endpoints)
     */
    public function listForNarrativeEnrichment(string $contextId): array
    {
        if (!$this->contextExists(trim($contextId))) {
            return [];
        }
        $rows = $this->beliefs->listForContext(trim($contextId), ['limit' => 120]);
        $filtered = array_values(array_filter($rows, static function (array $r): bool {
            $st = (string)($r['status'] ?? '');

            return in_array($st, ['active', 'proposed', 'disputed'], true);
        }));

        return array_map(fn (array $r) => $this->toApiBelief($r), $filtered);
    }

    private function contextExists(string $contextId): bool
    {
        return $this->contexts->find($contextId) !== null;
    }

    /** @return float */
    private function normalizeConfidence(mixed $v): float
    {
        $f = is_numeric($v) ? (float)$v : 0.5;
        if ($f < 0.0) {
            $f = 0.0;
        }
        if ($f > 1.0) {
            $f = 1.0;
        }

        return $f;
    }

    /** @return string|false|null null = absent ; false = invalide */
    private function normalizeAgentId(mixed $v): string|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = strtolower(trim((string)$v));
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $s)) {
            return false;
        }

        return $s;
    }

    /** @return string|false|null */
    private function normalizeSourceType(mixed $v): string|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = strtolower(trim((string)$v));
        if (!in_array($s, self::SOURCE_TYPES, true)) {
            return false;
        }

        return $s;
    }

    private function normalizeBeliefRef(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);

        return $s !== '' ? $s : null;
    }

    private function canTransitionStatus(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if ($from === '' || $to === '') {
            return false;
        }
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    private function normalizeNullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);

        return $s !== '' ? $s : null;
    }

    /** @return list<string> */
    private function jsonStringList(mixed $v): array
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') {
                return [];
            }
            $parts = preg_split('/\s*,\s*/', $v) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $t = trim((string)$p);
                if ($t !== '' && !in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }

            return $out;
        }
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            $t = trim((string)$x);
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function decodeList(string $json): array
    {
        $x = json_decode($json, true);
        if (!is_array($x)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(static fn ($v) => trim((string)$v), $x))));
    }

    /** @return array{drift_score:float,consensus_score:float,contestation_state:string} */
    private function computeDerivedScores(array $row): array
    {
        $support = $this->decodeList((string)($row['supporting_agents_json'] ?? '[]'));
        $disagree = $this->decodeList((string)($row['disagreeing_agents_json'] ?? '[]'));
        $contested = $this->decodeList((string)($row['contested_by_agents_json'] ?? '[]'));
        $supportCount = count(array_unique($support));
        $disagreeCount = count(array_unique(array_merge($disagree, $contested)));
        $evidenceCount = count($this->decodeList((string)($row['evidence_sources_json'] ?? '[]')));
        $isInvalidated = trim((string)($row['invalidated_by'] ?? '')) !== '' || (string)($row['status'] ?? '') === 'invalidated';
        $isArchived = (string)($row['status'] ?? '') === 'archived';
        $isDerived = trim((string)($row['parent_belief_id'] ?? '')) !== '' || trim((string)($row['supersedes_belief_id'] ?? '')) !== '';
        $confidence = isset($row['confidence']) ? (float)$row['confidence'] : 0.5;
        $history = json_decode((string)($row['confidence_history_json'] ?? '[]'), true);
        $drift = 0.0;
        if (is_array($history) && count($history) >= 2) {
            $first = (float)($history[0]['confidence'] ?? $confidence);
            $last = (float)($history[count($history) - 1]['confidence'] ?? $confidence);
            $drift = min(1.0, abs($last - $first));
        }
        $drift = max($drift, min(1.0, ($disagreeCount * 0.15)));

        $consensus = ($supportCount * 1.0 + $evidenceCount * 0.4 + $confidence) - ($disagreeCount * 1.2) - ($isInvalidated ? 1.5 : 0.0);
        $consensusNorm = max(0.0, min(1.0, ($consensus + 2.0) / 6.0));

        $contestation = 'stable';
        if ($isArchived) {
            $contestation = 'archived';
        } elseif ($isInvalidated) {
            $contestation = 'invalidated';
        } elseif ($isDerived) {
            $contestation = 'derived';
        } elseif ($disagreeCount > 0 && $supportCount > 0) {
            $contestation = 'unstable';
        } elseif ($disagreeCount > 0) {
            $contestation = 'contested';
        } elseif ($supportCount >= 2 && $confidence >= 0.66) {
            $contestation = 'reinforced';
        } elseif ($consensusNorm < 0.35 || $confidence < 0.4) {
            $contestation = 'weak';
        }

        return [
            'drift_score' => round($drift, 4),
            'consensus_score' => round($consensusNorm, 4),
            'contestation_state' => $contestation,
        ];
    }

    private function appendBeliefEvent(
        string $contextId,
        string $beliefId,
        string $eventType,
        ?string $actorId,
        ?string $reason,
        array $payload = []
    ): void {
        $now = date('c');
        $this->beliefs->insertEvent([
            'event_id' => $this->uuid(),
            'strategic_context_id' => $contextId,
            'belief_id' => $beliefId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function appendEventsFromDiff(string $contextId, array $before, array $after, array $patch): void
    {
        $bid = (string)($after['id'] ?? '');
        $actor = $this->normalizeNullableString($patch['created_by'] ?? null) ?? $this->normalizeNullableString($after['created_by'] ?? null);
        $reason = $this->normalizeNullableString($patch['reason'] ?? null);
        $events = [];
        if ((string)($before['status'] ?? '') !== (string)($after['status'] ?? '')) {
            $events[] = (string)($after['status'] ?? 'updated');
        }
        if ((float)($before['confidence'] ?? 0.5) !== (float)($after['confidence'] ?? 0.5)) {
            $events[] = 'confidence_adjusted';
        }
        $beforeSupport = $this->decodeList((string)($before['supporting_agents_json'] ?? '[]'));
        $afterSupport = $this->decodeList((string)($after['supporting_agents_json'] ?? '[]'));
        if (count($afterSupport) > count($beforeSupport)) {
            $events[] = 'reinforced';
        }
        $beforeDis = $this->decodeList((string)($before['disagreeing_agents_json'] ?? '[]'));
        $afterDis = $this->decodeList((string)($after['disagreeing_agents_json'] ?? '[]'));
        if (count($afterDis) > count($beforeDis)) {
            $events[] = 'contradicted';
        }
        if ((string)($before['invalidated_by'] ?? '') !== (string)($after['invalidated_by'] ?? '') && (string)($after['invalidated_by'] ?? '') !== '') {
            $events[] = 'invalidated';
        }
        if ((string)($before['supersedes_belief_id'] ?? '') !== (string)($after['supersedes_belief_id'] ?? '') && (string)($after['supersedes_belief_id'] ?? '') !== '') {
            $events[] = 'superseded';
        }
        if ((string)($before['contestation_state'] ?? '') !== (string)($after['contestation_state'] ?? '')) {
            $events[] = 'consensus_recomputed';
        }
        if ((string)($before['parent_belief_id'] ?? '') !== (string)($after['parent_belief_id'] ?? '') && (string)($after['parent_belief_id'] ?? '') !== '') {
            $events[] = 'lineage';
        }
        $events = array_values(array_unique(array_filter($events)));
        if ($events === []) {
            $events = ['updated'];
        }
        foreach ($events as $ev) {
            $this->appendBeliefEvent($contextId, $bid, $ev, $actor, $reason, [
                'before_status' => (string)($before['status'] ?? ''),
                'after_status' => (string)($after['status'] ?? ''),
                'before_confidence' => (float)($before['confidence'] ?? 0.5),
                'after_confidence' => (float)($after['confidence'] ?? 0.5),
                'contestation_state' => (string)($after['contestation_state'] ?? ''),
            ]);
        }
    }

    private function appendConfidenceHistory(string $historyJson, float $confidence, string $actor, string $reason): string
    {
        $history = json_decode($historyJson, true);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'at' => date('c'),
            'confidence' => $confidence,
            'actor' => $actor,
            'reason' => $reason,
        ];
        if (count($history) > 120) {
            $history = array_slice($history, -120);
        }

        return (string)json_encode($history, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function syncAgentPositions(string $contextId, string $beliefId, array $supportingAgents, array $disagreeingAgents): void
    {
        foreach ($supportingAgents as $aid) {
            $aidN = $this->normalizeAgentId($aid);
            if (is_string($aidN)) {
                $this->beliefs->upsertAgentPosition($contextId, $beliefId, $aidN, 'support');
            }
        }
        foreach ($disagreeingAgents as $aid) {
            $aidN = $this->normalizeAgentId($aid);
            if (is_string($aidN)) {
                $this->beliefs->upsertAgentPosition($contextId, $beliefId, $aidN, 'disagree');
            }
        }
    }

    private function syncDeterministicRelations(array $row): void
    {
        $ctx = (string)($row['strategic_context_id'] ?? '');
        $bid = (string)($row['id'] ?? '');
        if ($ctx === '' || $bid === '') {
            return;
        }
        $rels = [];
        $parent = $this->normalizeBeliefRef($row['parent_belief_id'] ?? null);
        if ($parent !== null) {
            $rels[] = ['relation_type' => 'lineage_parent', 'to_entity_type' => 'belief', 'to_entity_id' => $parent];
        }
        $sup = $this->normalizeBeliefRef($row['supersedes_belief_id'] ?? null);
        if ($sup !== null) {
            $rels[] = ['relation_type' => 'lineage_supersedes', 'to_entity_type' => 'belief', 'to_entity_id' => $sup];
        }
        $srcType = $this->normalizeNullableString($row['source_type'] ?? null);
        $srcRef = $this->normalizeNullableString($row['source_reference_id'] ?? null);
        if ($srcType !== null && $srcRef !== null) {
            $relType = match ($srcType) {
                'session' => 'linked_decision',
                'evidence' => 'linked_evidence',
                'memory' => 'linked_narrative',
                'relationship_event' => 'linked_agent',
                default => 'supporting_link',
            };
            $targetType = match ($srcType) {
                'session' => 'decision',
                'evidence' => 'evidence',
                'memory' => 'narrative',
                'relationship_event' => 'agent',
                default => 'source',
            };
            $rels[] = ['relation_type' => $relType, 'to_entity_type' => $targetType, 'to_entity_id' => $srcRef];
        }
        $this->beliefs->replaceRelationsForBelief($ctx, $bid, array_map(
            static fn (array $r): array => [
                'relation_type' => $r['relation_type'],
                'to_entity_type' => $r['to_entity_type'],
                'to_entity_id' => $r['to_entity_id'],
                'metadata' => [],
            ],
            $rels
        ));
    }

    /** @return list<array<string,mixed>> */
    private function normalizeRelations(mixed $raw, string $contextId): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $allowed = [
            'supports', 'conflicts', 'derived_from', 'lineage_parent', 'lineage_supersedes',
            'linked_decision', 'linked_evidence', 'linked_narrative', 'linked_agent', 'linked_snapshot',
            'supporting_link', 'conflict_link', 'lineage_link',
        ];
        $out = [];
        foreach ($raw as $it) {
            if (!is_array($it)) {
                continue;
            }
            $typ = strtolower(trim((string)($it['relation_type'] ?? '')));
            $toType = strtolower(trim((string)($it['to_entity_type'] ?? '')));
            $toId = trim((string)($it['to_entity_id'] ?? ''));
            if ($typ === '' || $toType === '' || $toId === '' || !in_array($typ, $allowed, true)) {
                continue;
            }
            $out[] = [
                'strategic_context_id' => $contextId,
                'relation_type' => $typ,
                'to_entity_type' => $toType,
                'to_entity_id' => $toId,
                'metadata' => is_array($it['metadata'] ?? null) ? $it['metadata'] : [],
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $beliefs */
    private function buildBeliefGraph(string $contextId, array $beliefs): array
    {
        $nodes = array_map(static fn (array $b): array => [
            'id' => (string)$b['id'],
            'label' => (string)$b['belief_text'],
            'status' => (string)$b['status'],
            'contestation_state' => (string)($b['contestation_state'] ?? 'weak'),
        ], $beliefs);
        $edges = [];
        foreach ($beliefs as $b) {
            $rels = $this->beliefs->listRelationsForBelief($contextId, (string)$b['id']);
            foreach ($rels as $r) {
                $edges[] = [
                    'from' => (string)$b['id'],
                    'to' => (string)($r['to_entity_id'] ?? ''),
                    'relation_type' => (string)($r['relation_type'] ?? ''),
                    'to_entity_type' => (string)($r['to_entity_type'] ?? ''),
                ];
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** @param array<string,mixed> $row */
    private function toApiBelief(array $row): array
    {
        $decode = static function (?string $json): array {
            if ($json === null || $json === '') {
                return [];
            }
            $x = json_decode($json, true);

            return is_array($x) ? array_values(array_filter(array_map('strval', $x))) : [];
        };
        $history = json_decode((string)($row['confidence_history_json'] ?? '[]'), true);
        if (!is_array($history)) {
            $history = [];
        }

        $api = [
            'id' => (string)($row['id'] ?? ''),
            'strategic_context_id' => (string)($row['strategic_context_id'] ?? ''),
            'agent_id' => $row['agent_id'] !== null && (string)$row['agent_id'] !== '' ? (string)$row['agent_id'] : null,
            'belief_type' => (string)($row['belief_type'] ?? ''),
            'belief_text' => (string)($row['belief_text'] ?? ''),
            'confidence' => isset($row['confidence']) ? (float)$row['confidence'] : 0.5,
            'status' => (string)($row['status'] ?? ''),
            'supporting_agents' => $decode($row['supporting_agents_json'] ?? '[]'),
            'disagreeing_agents' => $decode($row['disagreeing_agents_json'] ?? '[]'),
            'evidence_sources' => $decode($row['evidence_sources_json'] ?? '[]'),
            'related_session_ids' => $decode($row['related_session_ids_json'] ?? '[]'),
            'source_type' => $row['source_type'] !== null && (string)$row['source_type'] !== '' ? (string)$row['source_type'] : null,
            'source_reference_id' => $row['source_reference_id'] !== null && (string)$row['source_reference_id'] !== '' ? (string)$row['source_reference_id'] : null,
            'created_by' => $row['created_by'] !== null && (string)$row['created_by'] !== '' ? (string)$row['created_by'] : null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'last_reviewed_at' => $row['last_reviewed_at'] !== null && (string)$row['last_reviewed_at'] !== '' ? (string)$row['last_reviewed_at'] : null,
            'parent_belief_id' => $row['parent_belief_id'] !== null && (string)$row['parent_belief_id'] !== '' ? (string)$row['parent_belief_id'] : null,
            'supersedes_belief_id' => $row['supersedes_belief_id'] !== null && (string)$row['supersedes_belief_id'] !== '' ? (string)$row['supersedes_belief_id'] : null,
            'invalidated_by' => $row['invalidated_by'] !== null && (string)$row['invalidated_by'] !== '' ? (string)$row['invalidated_by'] : null,
            'invalidation_reason' => $row['invalidation_reason'] !== null && (string)$row['invalidation_reason'] !== '' ? (string)$row['invalidation_reason'] : null,
            'confidence_history' => $history,
            'drift_score' => isset($row['drift_score']) ? (float)$row['drift_score'] : 0.0,
            'consensus_score' => isset($row['consensus_score']) ? (float)$row['consensus_score'] : 0.0,
            'contested_by_agents' => $decode($row['contested_by_agents_json'] ?? '[]'),
            'contestation_state' => (string)($row['contestation_state'] ?? 'weak'),
        ];
        $api['badges'] = array_values(array_filter([
            $api['contestation_state'],
            $api['invalidated_by'] ? 'invalidated' : null,
            $api['supersedes_belief_id'] || $api['parent_belief_id'] ? 'derived' : null,
        ]));
        $api['cognitive_provenance'] = CognitiveProvenanceEnvelope::forBeliefRecord($row);

        return $api;
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

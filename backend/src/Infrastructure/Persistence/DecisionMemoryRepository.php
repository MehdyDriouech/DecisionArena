<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Orchestration\RuntimeContracts;

final class DecisionMemoryRepository
{
    private \PDO $pdo;

    private const DEFAULT_AGING_DAYS = 30;
    private const DEFAULT_STALE_DAYS = 90;
    private const VALID_STATES = ['active', 'superseded', 'stale', 'invalidated', 'archived'];

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /** @return list<array<string,mixed>> */
    public function findAll(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM decision_memories ORDER BY created_at DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function findAllLinks(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM decision_memory_links ORDER BY created_at DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(string $memoryId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM decision_memories WHERE memory_id = ?');
        $stmt->execute([$memoryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRow($row) : null;
    }

    public function findBySession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM decision_memories WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRow($row) : null;
    }

    /**
     * Mémoires Decision Memory liées au contexte stratégique (join strategic_context_memories), les plus récentes d’abord.
     *
     * @return list<array<string,mixed>>
     */
    public function findLinkedMemoriesForStrategicContext(string $contextId, int $limit = 12): array
    {
        $contextId = trim($contextId);
        if ($contextId === '') {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM decision_memories m
             INNER JOIN strategic_context_memories scm ON scm.memory_id = m.memory_id AND scm.context_id = ?
             WHERE m.user_confirmed = 1
               AND (m.memory_state IS NULL OR m.memory_state NOT IN (\'invalidated\',\'archived\',\'stale\'))
             ORDER BY m.created_at DESC, m.memory_id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$contextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /**
     * Persist automatically only when outcome is memory-safe.
     * Returns created memory row when persisted; null otherwise.
     *
     * @param array<string,mixed> $runResult Must include decision_outcome + canonical_synthesis.
     */
    public function persistIfSafe(array $runResult, string $sessionId): ?array
    {
        $existing = $this->findBySession($sessionId);
        if ($existing) {
            return $existing;
        }

        $outcome = is_array($runResult['decision_outcome'] ?? null) ? $runResult['decision_outcome'] : null;
        $canonical = is_array($runResult['canonical_synthesis'] ?? null) ? $runResult['canonical_synthesis'] : null;
        if (!$outcome || !$canonical) {
            return null;
        }

        $ps = is_array($outcome['persistence_safety'] ?? null) ? $outcome['persistence_safety'] : [];
        $safe = ($ps['safe_to_persist'] ?? false) === true;
        $needs = ($ps['requires_user_confirmation'] ?? false) === true;
        if (!$safe || $needs) {
            return null;
        }

        return $this->createFromContracts($sessionId, $canonical, $outcome, $ps, true);
    }

    /**
     * Persist after explicit user confirmation when required.
     *
     * @param array<string,mixed> $runResult Must include decision_outcome + canonical_synthesis.
     */
    public function persistAfterConfirmation(array $runResult, string $sessionId): ?array
    {
        $existing = $this->findBySession($sessionId);
        if ($existing) {
            // If already persisted but not confirmed, promote confirmation.
            if (!empty($existing['user_confirmed'])) {
                return $existing;
            }
            $stmt = $this->pdo->prepare('UPDATE decision_memories SET user_confirmed = 1 WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $updated = $this->findBySession($sessionId);
            if ($updated && isset($updated['memory_id'])) {
                $this->refreshFtsForMemoryId((string)$updated['memory_id']);
            }
            return $updated;
        }

        $outcome = is_array($runResult['decision_outcome'] ?? null) ? $runResult['decision_outcome'] : null;
        $canonical = is_array($runResult['canonical_synthesis'] ?? null) ? $runResult['canonical_synthesis'] : null;
        if (!$outcome || !$canonical) {
            return null;
        }

        $ps = is_array($outcome['persistence_safety'] ?? null) ? $outcome['persistence_safety'] : [];
        $safe = ($ps['safe_to_persist'] ?? false) === true;
        $needs = ($ps['requires_user_confirmation'] ?? false) === true;
        $missingCritical = is_array($ps['missing_critical_fields'] ?? null) ? $ps['missing_critical_fields'] : [];

        // Never persist if missing critical fields, even with confirmation.
        if ($missingCritical !== []) {
            return null;
        }
        if (!$safe && !$needs) {
            return null;
        }

        return $this->createFromContracts($sessionId, $canonical, $outcome, $ps, true);
    }

    public function createLink(string $fromMemoryId, string $toMemoryId, string $linkType): bool
    {
        $linkType = strtolower(trim($linkType));
        if (!in_array($linkType, ['continuation', 'pivot', 'experiment_followup', 'related'], true)) {
            return false;
        }
        if ($fromMemoryId === '' || $toMemoryId === '' || $fromMemoryId === $toMemoryId) {
            return false;
        }
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT INTO decision_memory_links (from_memory_id, to_memory_id, link_type, created_at)
            VALUES (:f, :t, :lt, :ca)
        ');
        $stmt->execute([':f' => $fromMemoryId, ':t' => $toMemoryId, ':lt' => $linkType, ':ca' => $now]);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function auditFor(string $memoryId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare('
            SELECT * FROM decision_memory_audit_events
            WHERE memory_id = :id
            ORDER BY created_at DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':id', $memoryId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lifecycle actions (auditable).
     * $action: supersede | invalidate | archive | restore | review
     *
     * @return array{ok:bool, error?:string, memory?:array<string,mixed>}
     */
    public function applyLifecycleAction(string $memoryId, string $action, array $payload = []): array
    {
        $m = $this->findById($memoryId);
        if (!$m) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $action = strtolower(trim($action));
        $prevState = (string)($m['memory_state'] ?? 'active');
        if (!in_array($prevState, self::VALID_STATES, true)) {
            $prevState = 'active';
        }

        $now = date('c');
        $reason = trim((string)($payload['reason'] ?? ''));
        $newState = $prevState;

        try {
            if ($action === 'review') {
                $stmt = $this->pdo->prepare('UPDATE decision_memories SET last_reviewed_at = :t WHERE memory_id = :id');
                $stmt->execute([':t' => $now, ':id' => $memoryId]);
                $this->appendAudit($memoryId, 'review', $prevState, $prevState, $reason);
                return ['ok' => true, 'memory' => $this->findById($memoryId)];
            }

            if ($action === 'restore') {
                $newState = 'active';
                $stmt = $this->pdo->prepare('
                    UPDATE decision_memories
                    SET memory_state = :s, superseded_by = NULL
                    WHERE memory_id = :id
                ');
                $stmt->execute([':s' => $newState, ':id' => $memoryId]);
                $this->appendAudit($memoryId, 'restore', $prevState, $newState, $reason);
                return ['ok' => true, 'memory' => $this->findById($memoryId)];
            }

            if ($action === 'archive') {
                $newState = 'archived';
                $stmt = $this->pdo->prepare('UPDATE decision_memories SET memory_state = :s WHERE memory_id = :id');
                $stmt->execute([':s' => $newState, ':id' => $memoryId]);
                $this->appendAudit($memoryId, 'archive', $prevState, $newState, $reason);
                return ['ok' => true, 'memory' => $this->findById($memoryId)];
            }

            if ($action === 'invalidate') {
                $newState = 'invalidated';
                $stmt = $this->pdo->prepare('
                    UPDATE decision_memories
                    SET memory_state = :s, invalidated_reason = :r
                    WHERE memory_id = :id
                ');
                $stmt->execute([':s' => $newState, ':r' => $reason, ':id' => $memoryId]);
                $this->appendAudit($memoryId, 'invalidate', $prevState, $newState, $reason);
                return ['ok' => true, 'memory' => $this->findById($memoryId)];
            }

            if ($action === 'supersede') {
                $toId = trim((string)($payload['to_memory_id'] ?? ''));
                if ($toId === '' || !$this->findById($toId) || $toId === $memoryId) {
                    return ['ok' => false, 'error' => 'invalid_to_memory_id'];
                }
                $newState = 'superseded';
                $stmt = $this->pdo->prepare('
                    UPDATE decision_memories
                    SET memory_state = :s, superseded_by = :sb
                    WHERE memory_id = :id
                ');
                $stmt->execute([':s' => $newState, ':sb' => $toId, ':id' => $memoryId]);
                $this->appendAudit($memoryId, 'supersede', $prevState, $newState, $reason !== '' ? $reason : ('superseded_by:' . $toId));
                return ['ok' => true, 'memory' => $this->findById($memoryId)];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }

    /** @return list<array<string,mixed>> */
    public function linksFor(string $memoryId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM decision_memory_links
            WHERE from_memory_id = :id OR to_memory_id = :id
            ORDER BY created_at DESC
        ');
        $stmt->execute([':id' => $memoryId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Hard delete a decision memory and its dependent rows.
     * Used sparingly (expert tooling). Prefer lifecycle states for normal use.
     */
    public function deleteHard(string $memoryId): bool
    {
        $memoryId = trim($memoryId);
        if ($memoryId === '') return false;
        if (!$this->findById($memoryId)) return false;
    
        $this->pdo->beginTransaction();
    
        try {
            $this->pdo->prepare('DELETE FROM strategic_context_memories WHERE memory_id = ?')->execute([$memoryId]);
            $this->pdo->prepare('DELETE FROM decision_room_memories WHERE memory_id = ?')->execute([$memoryId]);
    
            $this->pdo->prepare('DELETE FROM decision_memory_links WHERE from_memory_id = ? OR to_memory_id = ?')->execute([$memoryId, $memoryId]);
            $this->pdo->prepare('DELETE FROM decision_memory_audit_events WHERE memory_id = ?')->execute([$memoryId]);
            $this->pdo->prepare('DELETE FROM decision_memory_embeddings WHERE memory_id = ?')->execute([$memoryId]);
    
            $this->pdo->prepare('UPDATE decision_memories SET superseded_by = NULL WHERE superseded_by = ?')->execute([$memoryId]);
    
            // Supprimer explicitement l’index FTS.
            $this->pdo->prepare('DELETE FROM decision_memory_fts WHERE memory_id = ?')->execute([$memoryId]);
    
            $this->pdo->prepare('DELETE FROM decision_memories WHERE memory_id = ?')->execute([$memoryId]);
    
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Filtered list for retrieval API (no embeddings; SQLite only).
     * Supported filters: playbook_id, decision_status, confidence, from, to, link_type, q
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function findFiltered(array $filters, int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        $joins = '';

        $pb = trim((string)($filters['playbook_id'] ?? ''));
        if ($pb !== '') {
            $where[] = 'm.playbook_id = :pb';
            $params[':pb'] = $pb;
        }
        $st = trim((string)($filters['decision_status'] ?? ''));
        if ($st !== '') {
            $where[] = 'm.decision_status = :st';
            $params[':st'] = $st;
        }
        $cf = trim((string)($filters['confidence'] ?? ''));
        if ($cf !== '') {
            $where[] = 'm.confidence = :cf';
            $params[':cf'] = $cf;
        }
        $from = trim((string)($filters['from'] ?? ''));
        if ($from !== '') {
            $where[] = 'm.created_at >= :from';
            $params[':from'] = $from;
        }
        $to = trim((string)($filters['to'] ?? ''));
        if ($to !== '') {
            $where[] = 'm.created_at <= :to';
            $params[':to'] = $to;
        }
        $linkType = trim((string)($filters['link_type'] ?? ''));
        if ($linkType !== '') {
            $joins .= ' INNER JOIN decision_memory_links l ON (l.from_memory_id = m.memory_id OR l.to_memory_id = m.memory_id) ';
            $where[] = 'l.link_type = :lt';
            $params[':lt'] = $linkType;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $joins .= ' ';
            $where[] = '(m.decision_summary LIKE :q OR m.unresolved_risks LIKE :q OR m.recommended_next_steps LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        // Archived hidden by default (still fetchable for Expert via include_archived=1)
        $includeArchived = (string)($filters['include_archived'] ?? '');
        if ($includeArchived !== '1' && $includeArchived !== 'true') {
            $where[] = "(m.memory_state IS NULL OR m.memory_state != 'archived')";
        }

        $sql = 'SELECT DISTINCT m.* FROM decision_memories m ' . $joins;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY m.created_at DESC LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function relatedMemories(string $memoryId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT m.*
            FROM decision_memories m
            INNER JOIN decision_memory_links l
              ON (l.from_memory_id = m.memory_id OR l.to_memory_id = m.memory_id)
            WHERE (l.from_memory_id = :id OR l.to_memory_id = :id)
              AND m.memory_id != :id
            ORDER BY m.created_at DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':id', $memoryId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /**
     * Compact reusable context for injection (server-side truth).
     * Enforces reuse safety rules (confirmed + compatible versions + has status).
     *
     * @param list<string> $ids
     * @return array{allowed:list<array<string,mixed>>, blocked:list<array{memory_id:string,reason:string}>}
     */
    public function compactReusableForIds(array $ids): array
    {
        return $this->compactReusableForIdsWithOptions($ids, ['allow_stale' => false, 'expert_override' => false]);
    }

    /**
     * @param list<string> $ids
     * @param array{allow_stale?:bool, expert_override?:bool} $options
     * @return array{allowed:list<array<string,mixed>>, blocked:list<array<string,mixed>>}
     */
    public function compactReusableForIdsWithOptions(array $ids, array $options): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['allowed' => [], 'blocked' => []];
        }

        $allowStale = ($options['allow_stale'] ?? false) === true;
        $expertOverride = ($options['expert_override'] ?? false) === true;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM decision_memories WHERE memory_id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $supersedeMap = $this->inferSupersededByFor($ids);

        $allowed = [];
        $blocked = [];
        foreach ($rows as $row) {
            $m = $this->hydrateRow($row);
            $mid = (string)($m['memory_id'] ?? '');

            $decay = $this->deriveDecayMeta($m, $supersedeMap[$mid] ?? null);
            $effectiveState = (string)($decay['memory_state'] ?? 'active');
            $staleness = (string)($decay['staleness_level'] ?? 'fresh');
            $supersededBy = (string)($decay['superseded_by'] ?? '');
            $invalidReason = (string)($decay['invalidated_reason'] ?? '');

            $confirmed = (int)($m['user_confirmed'] ?? 0) === 1;
            if (!$confirmed) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'not_confirmed', 'decay' => $decay];
                continue;
            }
            if ((string)($m['decision_status'] ?? '') === '') {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'missing_status', 'decay' => $decay];
                continue;
            }
            $cv = (string)($m['contract_version'] ?? '');
            $tv = (string)($m['taxonomy_version'] ?? '');
            if ($cv !== RuntimeContracts::DECISION_OUTCOME_CONTRACT_VERSION) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'incompatible_contract_version', 'decay' => $decay];
                continue;
            }
            if ($tv !== RuntimeContracts::TAXONOMY_VERSION) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'incompatible_taxonomy_version', 'decay' => $decay];
                continue;
            }

            // Lifecycle gates (server truth)
            if ($effectiveState === 'invalidated' && !$expertOverride) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'invalidated', 'invalidated_reason' => $invalidReason, 'decay' => $decay];
                continue;
            }
            if ($effectiveState === 'archived' && !$expertOverride) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'archived', 'decay' => $decay];
                continue;
            }
            if ($effectiveState === 'superseded' && !$expertOverride) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'superseded', 'superseded_by' => $supersededBy, 'decay' => $decay];
                continue;
            }
            if ($staleness === 'stale' && !$allowStale) {
                $blocked[] = ['memory_id' => $mid, 'reason' => 'stale_requires_confirmation', 'reuse_warning' => (string)($decay['reuse_warning'] ?? ''), 'decay' => $decay];
                continue;
            }

            $allowed[] = [
                'memory_id' => $mid,
                'playbook_id' => (string)($m['playbook_id'] ?? ''),
                'decision_status' => (string)($m['decision_status'] ?? ''),
                'confidence' => (string)($m['confidence'] ?? ''),
                'decision_summary' => (string)($m['decision_summary'] ?? ''),
                'unresolved_risks' => is_array($m['unresolved_risks'] ?? null) ? $m['unresolved_risks'] : [],
                'recommended_next_steps' => is_array($m['recommended_next_steps'] ?? null) ? $m['recommended_next_steps'] : [],
                'validated_hypotheses' => is_array($m['validated_hypotheses'] ?? null) ? $m['validated_hypotheses'] : [],
                'failed_assumptions' => is_array($m['failed_assumptions'] ?? null) ? $m['failed_assumptions'] : [],
                'created_at' => (string)($m['created_at'] ?? ''),
                'contract_version' => $cv,
                'taxonomy_version' => $tv,
                'decay' => $decay,
            ];
        }

        // Preserve requested order (stable UX)
        $pos = array_flip($ids);
        usort($allowed, fn($a, $b) => ($pos[$a['memory_id']] ?? 999999) <=> ($pos[$b['memory_id']] ?? 999999));

        return ['allowed' => $allowed, 'blocked' => $blocked];
    }

    /** @param array<string,mixed> $canonical @param array<string,mixed> $outcome @param array<string,mixed> $ps */
    private function createFromContracts(string $sessionId, array $canonical, array $outcome, array $ps, bool $userConfirmed): ?array
    {
        $memoryId = $this->uuid();
        $now = date('c');

        $playbookId = (string)($canonical['playbook_id'] ?? '');
        $status = RuntimeContracts::normalizeStatus((string)($outcome['status'] ?? ''));
        $confidence = RuntimeContracts::normalizeConfidence((string)($outcome['confidence'] ?? ''));
        $summary = trim((string)($outcome['decision_summary'] ?? ''));

        if ($playbookId === '' || $status === '' || $confidence === '' || $summary === '') {
            return null;
        }

        $claims = is_array($outcome['evidence_claims'] ?? null) ? $outcome['evidence_claims'] : [];
        $validated = [];
        $failed = [];
        foreach ($claims as $c) {
            if (!is_array($c)) continue;
            $text = trim((string)($c['claim'] ?? ''));
            if ($text === '') continue;
            $vs = (string)($c['verification_status'] ?? '');
            if ($vs === 'verified') $validated[] = $text;
            if ($vs === 'contradicted') $failed[] = $text;
        }

        $risks = array_values(array_filter(array_map('strval', (array)($canonical['risks'] ?? []))));
        $unknowns = array_values(array_filter(array_map('strval', (array)($outcome['blocking_unknowns'] ?? []))));
        $unresolved = array_slice(array_values(array_unique(array_merge($risks, $unknowns))), 0, 10);

        $nextSteps = array_values(array_filter(array_map('strval', (array)($outcome['required_next_actions'] ?? []))));

        $contractVersion = (string)($outcome['contract_version'] ?? RuntimeContracts::DECISION_OUTCOME_CONTRACT_VERSION);
        $taxonomyVersion = (string)($outcome['taxonomy_version'] ?? RuntimeContracts::TAXONOMY_VERSION);

        $payload = [
            ':memory_id' => $memoryId,
            ':session_id' => $sessionId,
            ':playbook_id' => $playbookId,
            ':decision_status' => $status,
            ':confidence' => $confidence,
            ':decision_summary' => $summary,
            ':validated_hypotheses' => json_encode(array_slice(array_values(array_unique($validated)), 0, 10), JSON_UNESCAPED_UNICODE),
            ':failed_assumptions' => json_encode(array_slice(array_values(array_unique($failed)), 0, 10), JSON_UNESCAPED_UNICODE),
            ':unresolved_risks' => json_encode($unresolved, JSON_UNESCAPED_UNICODE),
            ':recommended_next_steps' => json_encode(array_slice(array_values(array_unique($nextSteps)), 0, 10), JSON_UNESCAPED_UNICODE),
            ':historical_outcome' => $status,
            ':contract_version' => $contractVersion,
            ':taxonomy_version' => $taxonomyVersion,
            ':persistence_safety' => json_encode($ps, JSON_UNESCAPED_UNICODE),
            ':user_confirmed' => $userConfirmed ? 1 : 0,
            ':created_at' => $now,
        ];

        $stmt = $this->pdo->prepare('
            INSERT INTO decision_memories (
                memory_id, session_id, playbook_id, decision_status, confidence, decision_summary,
                validated_hypotheses, failed_assumptions, unresolved_risks, recommended_next_steps,
                historical_outcome, contract_version, taxonomy_version, persistence_safety,
                user_confirmed, created_at, memory_state
            ) VALUES (
                :memory_id, :session_id, :playbook_id, :decision_status, :confidence, :decision_summary,
                :validated_hypotheses, :failed_assumptions, :unresolved_risks, :recommended_next_steps,
                :historical_outcome, :contract_version, :taxonomy_version, :persistence_safety,
                :user_confirmed, :created_at, :memory_state
            )
        ');
        $payload[':memory_state'] = 'active';
        $stmt->execute($payload);
        $created = $this->findById($memoryId);
        if ($created) {
            $this->refreshFtsForMemoryId($memoryId);
        }
        return $created;
    }

    /** Returns whether the optional FTS index exists and is usable. */
    public function isDecisionMemoryFtsAvailable(): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='decision_memory_fts' LIMIT 1");
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rebuild the full Decision Memory FTS index (best-effort).
     *
     * @return array{search_mode:string,indexed_count:int}
     */
    public function rebuildDecisionMemoryFts(): array
    {
        if (!$this->isDecisionMemoryFtsAvailable()) {
            return ['search_mode' => 'like', 'indexed_count' => 0];
        }
        $count = 0;
        try {
            $this->pdo->exec('DELETE FROM decision_memory_fts');
            $stmt = $this->pdo->query("
                SELECT *
                FROM decision_memories
                WHERE user_confirmed = 1
                ORDER BY created_at DESC, memory_id ASC
            ");
            $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($rows as $row) {
                $m = $this->hydrateRow($row);
                if (!isset($m['memory_id'])) continue;
                if ($this->upsertFtsRow($m)) {
                    $count++;
                }
            }
        } catch (\Throwable) {
            // best-effort
        }
        return ['search_mode' => 'fts5', 'indexed_count' => $count];
    }

    /** Best-effort refresh for one memory row (insert/update/delete in FTS). */
    private function refreshFtsForMemoryId(string $memoryId): void
    {
        if ($memoryId === '') return;
        if (!$this->isDecisionMemoryFtsAvailable()) return;

        try {
            $m = $this->findById($memoryId);
            if (!$m) {
                $stmt = $this->pdo->prepare('DELETE FROM decision_memory_fts WHERE memory_id = ?');
                $stmt->execute([$memoryId]);
                return;
            }
            $this->upsertFtsRow($m);
        } catch (\Throwable) {
            // never break write paths
        }
    }

    /**
     * Upsert one row into decision_memory_fts.
     * The FTS table is a retrieval accelerator: only confirmed memories are indexed,
     * and lifecycle safety is enforced at query time.
     */
    private function upsertFtsRow(array $m): bool
    {
        if (!$this->isDecisionMemoryFtsAvailable()) return false;

        $memoryId = (string)($m['memory_id'] ?? '');
        if ($memoryId === '') return false;

        $confirmed = (int)($m['user_confirmed'] ?? 0) === 1;
        // IMPORTANT: FTS index is a retrieval accelerator, not a safety gate.
        // We index confirmed memories even if stale/archived/invalidated, and apply safety filters at query time.

        try {
            // Ensure no stale/unsafe rows remain indexed.
            $del = $this->pdo->prepare('DELETE FROM decision_memory_fts WHERE memory_id = ?');
            $del->execute([$memoryId]);
            if (!$confirmed) {
                return false;
            }

            $summary = trim((string)($m['decision_summary'] ?? ''));
            $unresolved = $this->jsonListToText($m['unresolved_risks'] ?? []);
            $nextSteps = $this->jsonListToText($m['recommended_next_steps'] ?? []);
            $validated = $this->jsonListToText($m['validated_hypotheses'] ?? []);
            $failed = $this->jsonListToText($m['failed_assumptions'] ?? []);
            $hist = trim((string)($m['historical_outcome'] ?? ''));

            $searchable = $this->buildSearchableText($summary, $unresolved, $nextSteps, $validated, $failed, $hist);

            $stmt = $this->pdo->prepare('
                INSERT INTO decision_memory_fts (
                    memory_id,
                    decision_summary,
                    unresolved_risks_text,
                    recommended_next_steps_text,
                    validated_hypotheses_text,
                    failed_assumptions_text,
                    historical_outcome_text,
                    playbook_id,
                    decision_status,
                    confidence,
                    searchable_text
                ) VALUES (
                    :memory_id,
                    :decision_summary,
                    :unresolved_risks_text,
                    :recommended_next_steps_text,
                    :validated_hypotheses_text,
                    :failed_assumptions_text,
                    :historical_outcome_text,
                    :playbook_id,
                    :decision_status,
                    :confidence,
                    :searchable_text
                )
            ');
            $stmt->execute([
                ':memory_id' => $memoryId,
                ':decision_summary' => $summary,
                ':unresolved_risks_text' => $unresolved,
                ':recommended_next_steps_text' => $nextSteps,
                ':validated_hypotheses_text' => $validated,
                ':failed_assumptions_text' => $failed,
                ':historical_outcome_text' => $hist,
                ':playbook_id' => (string)($m['playbook_id'] ?? ''),
                ':decision_status' => (string)($m['decision_status'] ?? ''),
                ':confidence' => (string)($m['confidence'] ?? ''),
                ':searchable_text' => $searchable,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function jsonListToText(mixed $v): string
    {
        $arr = $v;
        if (is_string($arr)) {
            $decoded = json_decode($arr, true);
            if (is_array($decoded)) $arr = $decoded;
        }
        if (!is_array($arr)) return '';
        $items = [];
        foreach ($arr as $x) {
            $s = trim((string)$x);
            if ($s === '') continue;
            $items[] = $s;
        }
        if ($items === []) return '';
        return implode("\n", $items);
    }

    private function buildSearchableText(
        string $summary,
        string $risks,
        string $nextSteps,
        string $validated,
        string $failed,
        string $historical
    ): string {
        // Deterministic concatenation: fixed order, fixed separators, no hidden data.
        $parts = [];
        if ($summary !== '') $parts[] = $summary;
        if ($risks !== '') $parts[] = $risks;
        if ($nextSteps !== '') $parts[] = $nextSteps;
        if ($validated !== '') $parts[] = $validated;
        if ($failed !== '') $parts[] = $failed;
        if ($historical !== '') $parts[] = $historical;
        return implode("\n\n", $parts);
    }

    /**
     * Scoped search over decision memories.
     *
     * @param array{
     *   q?:string,
     *   context_id?:string,
     *   room_id?:string,
     *   playbook_id?:string,
     *   decision_status?:string,
     *   confidence?:string,
     *   memory_state?:string,
     *   include_stale?:int|string,
     *   expert_override?:int|string,
     *   limit?:int|string,
     *   offset?:int|string
     * } $params
     * @return array{results:list<array<string,mixed>>,search_mode:string,scope:array<string,string|null>,warnings:list<string>}
     */
    public function searchScoped(array $params): array
    {
        $q = trim((string)($params['q'] ?? ''));
        $contextId = trim((string)($params['context_id'] ?? ''));
        $roomId = trim((string)($params['room_id'] ?? ''));
        $playbookId = trim((string)($params['playbook_id'] ?? ''));
        $status = trim((string)($params['decision_status'] ?? ''));
        $confidence = trim((string)($params['confidence'] ?? ''));
        $memoryState = trim((string)($params['memory_state'] ?? ''));
        $includeStale = (string)($params['include_stale'] ?? '') === '1' || (string)($params['include_stale'] ?? '') === 'true';
        $expertOverride = (string)($params['expert_override'] ?? '') === '1' || (string)($params['expert_override'] ?? '') === 'true';

        $limit = (int)($params['limit'] ?? 50);
        $offset = (int)($params['offset'] ?? 0);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $scope = [
            'context_id' => $contextId !== '' ? $contextId : null,
            'room_id' => $roomId !== '' ? $roomId : null,
        ];

        // Safety defaults: confirmed only; lifecycle exclusions unless expert override.
        $where = [];
        $bind = [];
        $joins = '';

        // Scope join: room overrides context
        if ($roomId !== '') {
            $joins .= ' INNER JOIN decision_room_memories drm ON drm.memory_id = m.memory_id AND drm.room_id = :room_id ';
            $bind[':room_id'] = $roomId;
            $scope['context_id'] = null; // overridden
        } elseif ($contextId !== '') {
            $joins .= ' INNER JOIN strategic_context_memories scm ON scm.memory_id = m.memory_id AND scm.context_id = :context_id ';
            $bind[':context_id'] = $contextId;
        }

        $where[] = 'm.user_confirmed = 1';
        if (!$expertOverride) {
            $where[] = "(m.memory_state IS NULL OR m.memory_state NOT IN ('invalidated','archived'))";
        }
        if (!$includeStale) {
            $where[] = "(m.memory_state IS NULL OR m.memory_state != 'stale')";
        }

        if ($playbookId !== '') { $where[] = 'm.playbook_id = :pb'; $bind[':pb'] = $playbookId; }
        if ($status !== '') { $where[] = 'm.decision_status = :st'; $bind[':st'] = $status; }
        if ($confidence !== '') { $where[] = 'm.confidence = :cf'; $bind[':cf'] = $confidence; }
        if ($memoryState !== '') { $where[] = 'm.memory_state = :ms'; $bind[':ms'] = $memoryState; }

        $warnings = ['These are prior decision records, not verified facts.'];

        $useFts = $this->isDecisionMemoryFtsAvailable() && $q !== '';
        if ($useFts) {
            $match = $this->buildDecisionMemoryFtsMatchQuery($q);
            if ($match === '') {
                $useFts = false;
            } else {
                $joins .= ' INNER JOIN decision_memory_fts ON decision_memory_fts.memory_id = m.memory_id ';
                // IMPORTANT: SQLite FTS MATCH requires the table name (aliases are not accepted).
                $where[] = 'decision_memory_fts MATCH :match';
                $bind[':match'] = $match;
            }
        }

        if (!$useFts && $q !== '') {
            $where[] = '('
                . 'm.decision_summary LIKE :likeq OR '
                . 'm.unresolved_risks LIKE :likeq OR '
                . 'm.recommended_next_steps LIKE :likeq OR '
                . 'm.validated_hypotheses LIKE :likeq OR '
                . 'm.failed_assumptions LIKE :likeq OR '
                . 'm.historical_outcome LIKE :likeq'
                . ')';
            $bind[':likeq'] = '%' . $q . '%';
        }

        $sqlBase = 'FROM decision_memories m ' . $joins . ' WHERE ' . implode(' AND ', $where);

        if ($useFts) {
            $sql = "
                SELECT
                    m.memory_id,
                    bm25(decision_memory_fts) AS score,
                    snippet(decision_memory_fts, -1, '[', ']', '…', 12) AS match_snippet,
                    m.decision_summary,
                    m.playbook_id,
                    m.decision_status,
                    m.confidence,
                    m.created_at,
                    m.memory_state,
                    m.superseded_by,
                    m.invalidated_reason,
                    m.last_reviewed_at
                {$sqlBase}
                ORDER BY score ASC, m.created_at DESC, m.memory_id ASC
                LIMIT :lim OFFSET :off
            ";
        } else {
            // Deterministic LIKE scoring (coarse; FTS preferred)
            $sql = "
                SELECT
                    m.memory_id,
                    (
                        (CASE WHEN m.decision_summary LIKE :likeq2 THEN 6 ELSE 0 END) +
                        (CASE WHEN m.unresolved_risks LIKE :likeq2 THEN 3 ELSE 0 END) +
                        (CASE WHEN m.recommended_next_steps LIKE :likeq2 THEN 3 ELSE 0 END) +
                        (CASE WHEN m.validated_hypotheses LIKE :likeq2 THEN 2 ELSE 0 END) +
                        (CASE WHEN m.failed_assumptions LIKE :likeq2 THEN 2 ELSE 0 END) +
                        (CASE WHEN m.historical_outcome LIKE :likeq2 THEN 1 ELSE 0 END)
                    ) AS score,
                    '' AS match_snippet,
                    m.decision_summary,
                    m.playbook_id,
                    m.decision_status,
                    m.confidence,
                    m.created_at,
                    m.memory_state,
                    m.superseded_by,
                    m.invalidated_reason,
                    m.last_reviewed_at
                {$sqlBase}
                ORDER BY score DESC, m.created_at DESC, m.memory_id ASC
                LIMIT :lim OFFSET :off
            ";
        }

        $results = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bind as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            if (!$useFts) {
                // ensure like bind for scoring is present even when q empty
                $stmt->bindValue(':likeq2', '%' . $q . '%');
            }
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $results[] = [
                    'memory_id' => (string)($r['memory_id'] ?? ''),
                    'score' => (float)($r['score'] ?? 0),
                    'match_snippet' => (string)($r['match_snippet'] ?? ''),
                    'decision_summary' => (string)($r['decision_summary'] ?? ''),
                    'playbook_id' => (string)($r['playbook_id'] ?? ''),
                    'decision_status' => (string)($r['decision_status'] ?? ''),
                    'confidence' => (string)($r['confidence'] ?? ''),
                    'created_at' => (string)($r['created_at'] ?? ''),
                    'lifecycle' => [
                        'memory_state' => (string)($r['memory_state'] ?? 'active'),
                        'superseded_by' => $r['superseded_by'] ?? null,
                        'invalidated_reason' => $r['invalidated_reason'] ?? null,
                        'last_reviewed_at' => $r['last_reviewed_at'] ?? null,
                    ],
                ];
            }
        } catch (\Throwable) {
            // fail closed: return empty
        }

        return [
            'results' => $results,
            'search_mode' => $useFts ? 'fts5' : 'like',
            'scope' => $scope,
            'warnings' => $warnings,
        ];
    }

    private function buildDecisionMemoryFtsMatchQuery(string $q): string
    {
        $q = trim($q);
        if ($q === '') return '';
        if (!preg_match_all('/\p{L}[\p{L}\p{N}]*/u', mb_strtolower($q, 'UTF-8'), $m)) {
            return '';
        }
        $seen = [];
        $tokens = [];
        foreach ($m[0] ?? [] as $raw) {
            $t = mb_strtolower($raw, 'UTF-8');
            if (mb_strlen($t, 'UTF-8') < 2) continue;
            if (isset($seen[$t])) continue;
            $seen[$t] = true;
            $tokens[] = $t;
            if (count($tokens) >= 12) break;
        }
        if ($tokens === []) return '';
        $parts = [];
        foreach ($tokens as $t) {
            $escaped = str_replace('"', '""', $t);
            // No prefix wildcard here: keep MATCH syntax compatible across SQLite builds.
            $parts[] = '"' . $escaped . '"';
        }
        return implode(' OR ', $parts);
    }

    /** @param array<string,mixed> $row */
    private function hydrateRow(array $row): array
    {
        foreach (['validated_hypotheses', 'failed_assumptions', 'unresolved_risks', 'recommended_next_steps', 'persistence_safety'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded = json_decode($row[$key], true);
                $row[$key] = $decoded;
            }
        }
        $row['user_confirmed'] = (int)($row['user_confirmed'] ?? 0);
        $row['memory_state'] = isset($row['memory_state']) ? (string)$row['memory_state'] : 'active';
        return $row;
    }

    /** @return array<string,string> from_memory_id => to_memory_id */
    private function inferSupersededByFor(array $fromIds): array
    {
        $fromIds = array_values(array_filter(array_map('strval', $fromIds)));
        if ($fromIds === []) return [];
        $place = implode(',', array_fill(0, count($fromIds), '?'));

        // Prefer explicit lifecycle superseded_by when present; otherwise infer from followup links.
        $stmt = $this->pdo->prepare("
            SELECT l.from_memory_id AS from_id, l.to_memory_id AS to_id
            FROM decision_memory_links l
            INNER JOIN decision_memories m2 ON m2.memory_id = l.to_memory_id
            WHERE l.from_memory_id IN ($place)
              AND l.link_type IN ('continuation','pivot','experiment_followup')
            ORDER BY l.created_at DESC
        ");
        $stmt->execute($fromIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $f = (string)($r['from_id'] ?? '');
            $t = (string)($r['to_id'] ?? '');
            if ($f === '' || $t === '') continue;
            if (!isset($map[$f])) {
                $map[$f] = $t;
            }
        }
        return $map;
    }

    /** @param array<string,mixed> $m */
    private function deriveDecayMeta(array $m, ?string $inferredSupersededBy): array
    {
        $state = (string)($m['memory_state'] ?? 'active');
        if (!in_array($state, self::VALID_STATES, true)) {
            $state = 'active';
        }
        $supersededBy = (string)($m['superseded_by'] ?? '');
        if ($supersededBy === '' && $inferredSupersededBy) {
            $supersededBy = $inferredSupersededBy;
        }
        $invalidReason = (string)($m['invalidated_reason'] ?? '');
        $lastReviewed = (string)($m['last_reviewed_at'] ?? '');

        $staleBasis = $lastReviewed !== '' ? $lastReviewed : (string)($m['created_at'] ?? '');
        $staleness = $this->computeStalenessLevel($staleBasis);

        $effectiveState = $state;
        if ($effectiveState === 'active' && $supersededBy !== '') {
            $effectiveState = 'superseded';
        }

        $warning = '';
        if ($effectiveState === 'invalidated') {
            $warning = $invalidReason !== '' ? ('INVALIDATED: ' . $invalidReason) : 'INVALIDATED';
        } elseif ($effectiveState === 'superseded') {
            $warning = $supersededBy !== '' ? ('SUPERSEDED: use ' . $supersededBy) : 'SUPERSEDED';
        } elseif ($effectiveState === 'archived') {
            $warning = 'ARCHIVED';
        } elseif ($staleness === 'stale') {
            $warning = 'STALE: review before reuse';
        } elseif ($staleness === 'aging') {
            $warning = 'AGING: verify assumptions still hold';
        }

        return [
            'memory_state' => $effectiveState,
            'staleness_level' => $staleness,
            'superseded_by' => $supersededBy,
            'invalidated_reason' => $invalidReason,
            'reuse_warning' => $warning,
            'last_reviewed_at' => $lastReviewed,
        ];
    }

    private function computeStalenessLevel(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') return 'fresh';
        try {
            $t = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return 'fresh';
        }
        $now = new \DateTimeImmutable('now');
        $days = (int)floor(($now->getTimestamp() - $t->getTimestamp()) / 86400);
        if ($days >= self::DEFAULT_STALE_DAYS) return 'stale';
        if ($days >= self::DEFAULT_AGING_DAYS) return 'aging';
        return 'fresh';
    }

    private function appendAudit(string $memoryId, string $eventType, ?string $prev, ?string $next, string $reason): void
    {
        $now = date('c');
        $stmt = $this->pdo->prepare('
            INSERT INTO decision_memory_audit_events (event_id, memory_id, event_type, previous_state, new_state, reason, created_at)
            VALUES (:eid, :mid, :et, :ps, :ns, :r, :ca)
        ');
        $stmt->execute([
            ':eid' => $this->uuid(),
            ':mid' => $memoryId,
            ':et' => $eventType,
            ':ps' => $prev,
            ':ns' => $next,
            ':r' => $reason !== '' ? $reason : null,
            ':ca' => $now,
        ]);
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

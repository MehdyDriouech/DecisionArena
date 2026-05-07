<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\DecisionMemory\MemoryEmbeddingProviderInterface;

final class DecisionMemoryEmbeddingsRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public static function isSemanticMemoryEnabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('SEMANTIC_MEMORY_ENABLED') ?: 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Deterministic indexed text (ONLY safe structured fields).
     * @param array<string,mixed> $m hydrated decision memory row
     */
    public static function buildIndexedText(array $m): string
    {
        $summary = trim((string)($m['decision_summary'] ?? ''));
        $risks = self::jsonListToText($m['unresolved_risks'] ?? []);
        $next = self::jsonListToText($m['recommended_next_steps'] ?? []);
        $validated = self::jsonListToText($m['validated_hypotheses'] ?? []);
        $failed = self::jsonListToText($m['failed_assumptions'] ?? []);

        $parts = [];
        if ($summary !== '') $parts[] = $summary;
        if ($risks !== '') $parts[] = $risks;
        if ($next !== '') $parts[] = $next;
        if ($validated !== '') $parts[] = $validated;
        if ($failed !== '') $parts[] = $failed;
        return implode("\n\n", $parts);
    }

    /** @param array<string,mixed> $m */
    public static function indexedTextHash(array $m): string
    {
        return hash('sha256', self::buildIndexedText($m));
    }

    /**
     * Rebuild embeddings for confirmed memories (discovery index only).
     * Applies default filters (skip stale unless include_stale=1; skip archived/invalidated unless expert_override=1).
     *
     * @param array{include_stale?:bool,expert_override?:bool} $options
     * @return array{enabled:bool,indexed_count:int,provider:string,model:string,version:string,skipped_unchanged:int,mode:string}
     */
    public function rebuildEmbeddings(MemoryEmbeddingProviderInterface $provider, array $options = []): array
    {
        if (!self::isSemanticMemoryEnabled()) {
            return [
                'enabled' => false,
                'mode' => 'experimental_semantic_similarity',
                'indexed_count' => 0,
                'skipped_unchanged' => 0,
                'provider' => $provider->providerName(),
                'model' => $provider->modelName(),
                'version' => $provider->embeddingVersion(),
            ];
        }

        $includeStale = ($options['include_stale'] ?? false) === true;
        $expertOverride = ($options['expert_override'] ?? false) === true;

        $indexed = 0;
        $skipped = 0;
        $now = date('c');

        // IMPORTANT: embeddings storage is a discovery index, not a safety gate.
        // We can store embeddings for confirmed memories (including stale/archived/invalidated),
        // and enforce lifecycle safety at query-time via include_stale/expert_override.
        // Options are kept for compatibility but do not restrict indexing.
        $sql = "SELECT * FROM decision_memories WHERE user_confirmed = 1 ORDER BY created_at DESC, memory_id ASC";
        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $row) {
            $m = $this->hydrateRow($row);
            $mid = (string)($m['memory_id'] ?? '');
            if ($mid === '') continue;

            $hash = self::indexedTextHash($m);
            $existing = $this->findEmbeddingMeta($mid, $provider);
            if ($existing && (string)($existing['indexed_text_hash'] ?? '') === $hash) {
                $skipped++;
                continue;
            }

            $text = self::buildIndexedText($m);
            if (trim($text) === '') continue;

            try {
                $vec = $provider->embed($text);
                if (count($vec) !== $provider->dimensions()) {
                    continue;
                }
                $this->upsertEmbedding($mid, $provider, $hash, $vec, $now);
                $indexed++;
            } catch (\Throwable) {
                // provider failure is non-fatal in experimental mode
                continue;
            }
        }

        return [
            'enabled' => true,
            'mode' => 'experimental_semantic_similarity',
            'indexed_count' => $indexed,
            'skipped_unchanged' => $skipped,
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'version' => $provider->embeddingVersion(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function getEmbedding(string $memoryId, MemoryEmbeddingProviderInterface $provider): ?array
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT * FROM decision_memory_embeddings
                WHERE memory_id = :mid
                  AND embedding_provider = :p
                  AND embedding_model = :m
                  AND embedding_version = :v
                LIMIT 1
            ');
            $stmt->execute([
                ':mid' => $memoryId,
                ':p' => $provider->providerName(),
                ':m' => $provider->modelName(),
                ':v' => $provider->embeddingVersion(),
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;
            $vec = json_decode((string)($row['vector_json'] ?? '[]'), true);
            if (!is_array($vec)) $vec = [];
            return [
                'memory_id' => (string)$row['memory_id'],
                'indexed_text_hash' => (string)$row['indexed_text_hash'],
                'vector' => array_map('floatval', $vec),
                'created_at' => (string)$row['created_at'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Experimental semantic similarity retrieval (discovery-only).
     *
     * @param array{
     *   memory_id?:string,
     *   q?:string,
     *   context_id?:string,
     *   room_id?:string,
     *   playbook_id?:string,
     *   limit?:int|string,
     *   include_stale?:bool|int|string,
     *   expert_override?:bool|int|string
     * } $params
     * @return array<string,mixed>
     */
    public function findSimilar(MemoryEmbeddingProviderInterface $provider, array $params): array
    {
        if (!self::isSemanticMemoryEnabled()) {
            return [
                'enabled' => false,
                'mode' => 'experimental_semantic_similarity',
            ];
        }

        $memoryId = trim((string)($params['memory_id'] ?? ''));
        $q = trim((string)($params['q'] ?? ''));
        $contextId = trim((string)($params['context_id'] ?? ''));
        $roomId = trim((string)($params['room_id'] ?? ''));
        $playbookId = trim((string)($params['playbook_id'] ?? ''));

        $includeStale = (string)($params['include_stale'] ?? '') === '1'
            || (string)($params['include_stale'] ?? '') === 'true'
            || ($params['include_stale'] ?? false) === true;
        $expertOverride = (string)($params['expert_override'] ?? '') === '1'
            || (string)($params['expert_override'] ?? '') === 'true'
            || ($params['expert_override'] ?? false) === true;

        $limit = (int)($params['limit'] ?? 12);
        $limit = max(1, min(50, $limit));

        $warnings = [
            'Similarity does not imply correctness.',
            'These are prior decision records, not verified facts.',
        ];

        // Build query vector
        $queryVec = null;
        $queryMemory = null;
        try {
            if ($memoryId !== '') {
                $queryMemory = $this->findDecisionMemoryById($memoryId);
                if (!$queryMemory || (int)($queryMemory['user_confirmed'] ?? 0) !== 1) {
                    return [
                        'enabled' => true,
                        'mode' => 'experimental_semantic_similarity',
                        'provider' => $provider->providerName(),
                        'model' => $provider->modelName(),
                        'results' => [],
                        'warnings' => $warnings,
                    ];
                }
                $text = self::buildIndexedText($queryMemory);
                $queryVec = $provider->embed($text);
            } else {
                if ($q === '') {
                    return [
                        'enabled' => true,
                        'mode' => 'experimental_semantic_similarity',
                        'provider' => $provider->providerName(),
                        'model' => $provider->modelName(),
                        'results' => [],
                        'warnings' => $warnings,
                    ];
                }
                $queryVec = $provider->embed($q);
            }
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'mode' => 'experimental_semantic_similarity',
                'provider' => $provider->providerName(),
                'model' => $provider->modelName(),
                'results' => [],
                'warnings' => $warnings,
            ];
        }

        if (!is_array($queryVec) || count($queryVec) !== $provider->dimensions()) {
            return [
                'enabled' => true,
                'mode' => 'experimental_semantic_similarity',
                'provider' => $provider->providerName(),
                'model' => $provider->modelName(),
                'results' => [],
                'warnings' => $warnings,
            ];
        }

        // Candidate memories (scoped) from SQLite source of truth, with query-time lifecycle safety.
        $where = [];
        $bind = [];
        $joins = '';

        // scope join: room overrides context
        if ($roomId !== '') {
            $joins .= ' INNER JOIN decision_room_memories drm ON drm.memory_id = m.memory_id AND drm.room_id = :room_id ';
            $bind[':room_id'] = $roomId;
        } elseif ($contextId !== '') {
            $joins .= ' INNER JOIN strategic_context_memories scm ON scm.memory_id = m.memory_id AND scm.context_id = :context_id ';
            $bind[':context_id'] = $contextId;
        }

        $where[] = 'm.user_confirmed = 1';
        if ($playbookId !== '') {
            $where[] = 'm.playbook_id = :pb';
            $bind[':pb'] = $playbookId;
        }
        if ($memoryId !== '') {
            $where[] = 'm.memory_id != :exclude_mid';
            $bind[':exclude_mid'] = $memoryId;
        }
        if (!$includeStale) {
            $where[] = "(m.memory_state IS NULL OR m.memory_state != 'stale')";
        }
        if (!$expertOverride) {
            $where[] = "(m.memory_state IS NULL OR m.memory_state NOT IN ('invalidated','archived'))";
        }

        $sql = "
            SELECT
                m.memory_id,
                m.decision_summary,
                m.playbook_id,
                m.decision_status,
                m.confidence,
                m.created_at,
                m.memory_state,
                m.superseded_by,
                m.invalidated_reason,
                m.last_reviewed_at,
                e.vector_json
            FROM decision_memories m
            INNER JOIN decision_memory_embeddings e
              ON e.memory_id = m.memory_id
             AND e.embedding_provider = :ep
             AND e.embedding_model = :em
             AND e.embedding_version = :ev
            {$joins}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.created_at DESC, m.memory_id ASC
            LIMIT 300
        ";

        $bind[':ep'] = $provider->providerName();
        $bind[':em'] = $provider->modelName();
        $bind[':ev'] = $provider->embeddingVersion();

        $rows = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $rows = [];
        }

        $scored = [];
        foreach ($rows as $r) {
            $vec = json_decode((string)($r['vector_json'] ?? '[]'), true);
            if (!is_array($vec)) continue;
            $vec = array_map('floatval', $vec);
            if (count($vec) !== $provider->dimensions()) continue;
            $score = self::cosineSimilarity($queryVec, $vec);
            $scored[] = [
                'memory_id' => (string)($r['memory_id'] ?? ''),
                'similarity_score' => $score,
                'decision_summary' => (string)($r['decision_summary'] ?? ''),
                'decision_status' => (string)($r['decision_status'] ?? ''),
                'confidence' => (string)($r['confidence'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'playbook_id' => (string)($r['playbook_id'] ?? ''),
                'lifecycle' => [
                    'memory_state' => (string)($r['memory_state'] ?? 'active'),
                    'superseded_by' => $r['superseded_by'] ?? null,
                    'invalidated_reason' => $r['invalidated_reason'] ?? null,
                    'last_reviewed_at' => $r['last_reviewed_at'] ?? null,
                ],
            ];
        }

        usort($scored, function (array $a, array $b): int {
            $da = (float)($a['similarity_score'] ?? 0.0);
            $db = (float)($b['similarity_score'] ?? 0.0);
            if ($da !== $db) return ($db <=> $da); // desc
            $ta = (string)($a['created_at'] ?? '');
            $tb = (string)($b['created_at'] ?? '');
            $c = $tb <=> $ta; // desc iso
            if ($c !== 0) return $c;
            return ((string)($a['memory_id'] ?? '')) <=> ((string)($b['memory_id'] ?? '')); // asc
        });

        $results = array_slice($scored, 0, $limit);

        return [
            'enabled' => true,
            'mode' => 'experimental_semantic_similarity',
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'warnings' => $warnings,
            'results' => $results,
        ];
    }

    /** @return array<string,mixed>|null */
    private function findDecisionMemoryById(string $memoryId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM decision_memories WHERE memory_id = ? LIMIT 1');
            $stmt->execute([$memoryId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;
            return $this->hydrateRow($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<float> $a @param list<float> $b */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $va = (float)$a[$i];
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        $den = sqrt(max(1e-12, $na)) * sqrt(max(1e-12, $nb));
        return $den > 0 ? ($dot / $den) : 0.0;
    }

    /** @return array<string,mixed>|null */
    private function findEmbeddingMeta(string $memoryId, MemoryEmbeddingProviderInterface $provider): ?array
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT memory_id, indexed_text_hash, created_at
                FROM decision_memory_embeddings
                WHERE memory_id = :mid
                  AND embedding_provider = :p
                  AND embedding_model = :m
                  AND embedding_version = :v
                LIMIT 1
            ');
            $stmt->execute([
                ':mid' => $memoryId,
                ':p' => $provider->providerName(),
                ':m' => $provider->modelName(),
                ':v' => $provider->embeddingVersion(),
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<float> $vector */
    private function upsertEmbedding(string $memoryId, MemoryEmbeddingProviderInterface $provider, string $hash, array $vector, string $createdAt): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO decision_memory_embeddings
              (memory_id, embedding_provider, embedding_model, embedding_version, indexed_text_hash, vector_json, created_at)
            VALUES
              (:mid, :p, :m, :v, :h, :vec, :ca)
            ON CONFLICT(memory_id, embedding_provider, embedding_model, embedding_version)
            DO UPDATE SET
              indexed_text_hash = excluded.indexed_text_hash,
              vector_json = excluded.vector_json,
              created_at = excluded.created_at
        ');
        $stmt->execute([
            ':mid' => $memoryId,
            ':p' => $provider->providerName(),
            ':m' => $provider->modelName(),
            ':v' => $provider->embeddingVersion(),
            ':h' => $hash,
            ':vec' => json_encode(array_values($vector), JSON_UNESCAPED_UNICODE),
            ':ca' => $createdAt,
        ]);
    }

    private static function jsonListToText(mixed $v): string
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
        return $items === [] ? '' : implode("\n", $items);
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
}


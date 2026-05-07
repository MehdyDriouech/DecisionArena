<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\DecisionMemory\MemoryEmbeddingProviderInterface;
use Domain\DecisionMemory\VectorMath;

final class DecisionMemorySimilarityRepository
{
    private \PDO $pdo;
    private DecisionMemoryEmbeddingsRepository $embeddings;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->embeddings = new DecisionMemoryEmbeddingsRepository();
    }

    /**
     * Similarity by free-text query (query embedding computed on-the-fly; no storage).
     *
     * @param array{
     *   q?:string,
     *   memory_id?:string,
     *   context_id?:string,
     *   room_id?:string,
     *   playbook_id?:string,
     *   limit?:int|string,
     *   include_stale?:int|string,
     *   expert_override?:int|string
     * } $params
     * @return array{results:list<array<string,mixed>>,mode:string,provider:string,model:string,warnings:list<string>}
     */
    public function similarByQuery(MemoryEmbeddingProviderInterface $provider, array $params): array
    {
        $q = trim((string)($params['q'] ?? ''));
        if ($q === '') {
            return $this->emptyPayload($provider);
        }

        try {
            $qVec = $provider->embed($q);
        } catch (\Throwable) {
            return $this->emptyPayload($provider);
        }

        return $this->rankCandidates($provider, $qVec, $params);
    }

    /**
     * Similarity by memory_id (uses stored embedding; does not generate embeddings at request-time).
     *
     * @param array{
     *   memory_id?:string,
     *   context_id?:string,
     *   room_id?:string,
     *   playbook_id?:string,
     *   limit?:int|string,
     *   include_stale?:int|string,
     *   expert_override?:int|string
     * } $params
     * @return array{results:list<array<string,mixed>>,mode:string,provider:string,model:string,warnings:list<string>}
     */
    public function similarByMemoryId(MemoryEmbeddingProviderInterface $provider, array $params): array
    {
        $mid = trim((string)($params['memory_id'] ?? ''));
        if ($mid === '') {
            return $this->emptyPayload($provider);
        }
        $emb = $this->embeddings->getEmbedding($mid, $provider);
        if (!$emb || !is_array($emb['vector'] ?? null)) {
            return $this->emptyPayload($provider);
        }
        /** @var list<float> $vec */
        $vec = $emb['vector'];
        return $this->rankCandidates($provider, $vec, $params, $mid);
    }

    /** @param list<float> $queryVec */
    private function rankCandidates(MemoryEmbeddingProviderInterface $provider, array $queryVec, array $params, ?string $excludeMemoryId = null): array
    {
        $contextId = trim((string)($params['context_id'] ?? ''));
        $roomId = trim((string)($params['room_id'] ?? ''));
        $playbookId = trim((string)($params['playbook_id'] ?? ''));
        $includeStale = (string)($params['include_stale'] ?? '') === '1' || (string)($params['include_stale'] ?? '') === 'true';
        $expertOverride = (string)($params['expert_override'] ?? '') === '1' || (string)($params['expert_override'] ?? '') === 'true';
        $limit = (int)($params['limit'] ?? 20);
        $limit = max(1, min(50, $limit));

        $joins = '';
        $where = [];
        $bind = [];

        // Scope join: room overrides context
        if ($roomId !== '') {
            $joins .= ' INNER JOIN decision_room_memories drm ON drm.memory_id = m.memory_id AND drm.room_id = :room_id ';
            $bind[':room_id'] = $roomId;
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
        if ($playbookId !== '') {
            $where[] = 'm.playbook_id = :pb';
            $bind[':pb'] = $playbookId;
        }
        if ($excludeMemoryId !== null && $excludeMemoryId !== '') {
            $where[] = 'm.memory_id != :exclude_mid';
            $bind[':exclude_mid'] = $excludeMemoryId;
        }

        // Pull candidate memories (SQLite source of truth), then score in PHP.
        $sql = '
            SELECT m.memory_id, m.decision_summary, m.playbook_id, m.decision_status, m.confidence, m.created_at,
                   m.memory_state, m.superseded_by, m.invalidated_reason, m.last_reviewed_at
            FROM decision_memories m
            ' . $joins . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.created_at DESC, m.memory_id ASC
            LIMIT 500
        ';

        $candidates = [];
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
            $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            $candidates = [];
        }

        $scored = [];
        foreach ($candidates as $r) {
            $mid = (string)($r['memory_id'] ?? '');
            if ($mid === '') continue;

            $emb = $this->embeddings->getEmbedding($mid, $provider);
            if (!$emb || !is_array($emb['vector'] ?? null)) continue;
            /** @var list<float> $vec */
            $vec = $emb['vector'];

            $score = VectorMath::cosineSimilarity($queryVec, $vec);
            $scored[] = [
                'memory_id' => $mid,
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
            if ($da !== $db) return $db <=> $da;
            $ca = (string)($a['created_at'] ?? '');
            $cb = (string)($b['created_at'] ?? '');
            if ($ca !== $cb) return $cb <=> $ca;
            return strcmp((string)($a['memory_id'] ?? ''), (string)($b['memory_id'] ?? ''));
        });

        $results = array_slice($scored, 0, $limit);

        return [
            'results' => $results,
            'mode' => 'experimental_semantic_similarity',
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'warnings' => [
                'Similarity does not imply correctness.',
                'These are prior decision records, not verified facts.',
            ],
        ];
    }

    private function emptyPayload(MemoryEmbeddingProviderInterface $provider): array
    {
        return [
            'results' => [],
            'mode' => 'experimental_semantic_similarity',
            'provider' => $provider->providerName(),
            'model' => $provider->modelName(),
            'warnings' => [
                'Similarity does not imply correctness.',
                'These are prior decision records, not verified facts.',
            ],
        ];
    }
}


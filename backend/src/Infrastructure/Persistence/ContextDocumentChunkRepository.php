<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

/**
 * Chunking + FTS5 retrieval for session context documents (Phase 2).
 * Text lives only in context_document_chunks; FTS is external-content linked.
 */
class ContextDocumentChunkRepository
{
    public const CHUNK_TARGET_CHARS = 800;
    public const CHUNK_OVERLAP_CHARS  = 100;
    /** FTS MATCH token length (skip shorter). */
    private const MIN_TOKEN_LEN = 3;
    private const MAX_TOKENS    = 12;

    /** @var list<string> */
    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her', 'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how', 'its', 'may', 'new', 'now', 'old', 'see', 'two', 'who', 'boy', 'did', 'let', 'put', 'say', 'she', 'too', 'use', 'les', 'des', 'une', 'dans', 'pour', 'par', 'sur', 'avec', 'sans', 'est', 'pas', 'que', 'qui', 'son', 'ses', 'leur', 'cette', 'comme', 'tout', 'tous', 'aussi', 'plus', 'mais', 'donc', 'vers',
    ];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function deleteBySession(string $sessionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM context_document_chunks WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    }

    /**
     * Replace all chunks for a session. Triggers keep FTS in sync.
     *
     * @return int number of chunks stored
     */
    public function reindexSession(string $sessionId, string $fullText): int
    {
        $fullText = str_replace(["\r\n", "\r"], "\n", $fullText);
        $this->deleteBySession($sessionId);
        $chunks = self::splitIntoChunks($fullText);
        if (empty($chunks)) {
            return 0;
        }
        $now = date('c');
        $insert = $this->pdo->prepare('
            INSERT INTO context_document_chunks (session_id, chunk_index, start_offset, end_offset, content, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        foreach ($chunks as $row) {
            $insert->execute([
                $sessionId,
                (int)$row['chunk_index'],
                (int)$row['start_offset'],
                (int)$row['end_offset'],
                (string)$row['content'],
                $now,
            ]);
        }
        return count($chunks);
    }

    public function countBySession(string $sessionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM context_document_chunks WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array{id:int,chunk_index:int,start_offset:int,end_offset:int}>
     */
    public function findChunksWithOffsetsForSession(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, chunk_index, start_offset, end_offset FROM context_document_chunks WHERE session_id = ? ORDER BY chunk_index ASC'
        );
        $stmt->execute([$sessionId]);
        $out = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $out[] = [
                'id'           => (int)$row['id'],
                'chunk_index'  => (int)$row['chunk_index'],
                'start_offset' => (int)$row['start_offset'],
                'end_offset'   => (int)$row['end_offset'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id:int,chunk_index:int,content:string,rank:float}>
     */
    public function searchTopChunks(string $sessionId, string $ftsMatchQuery, int $limit = 8): array
    {
        $ftsMatchQuery = trim($ftsMatchQuery);
        if ($ftsMatchQuery === '' || $limit <= 0) {
            return [];
        }
        try {
            $sql = '
                SELECT c.id, c.chunk_index, c.content, bm25(context_document_chunks_fts) AS rank
                FROM context_document_chunks c
                INNER JOIN context_document_chunks_fts ON context_document_chunks_fts.rowid = c.id
                WHERE c.session_id = ? AND context_document_chunks_fts MATCH ?
                ORDER BY rank ASC
                LIMIT ' . (int)$limit;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sessionId, $ftsMatchQuery]);
            $out = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[] = [
                    'id'          => (int)$row['id'],
                    'chunk_index' => (int)$row['chunk_index'],
                    'content'     => (string)$row['content'],
                    'rank'        => (float)$row['rank'],
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{chunk_index:int,start_offset:int,end_offset:int,content:string}>
     */
    public static function splitIntoChunks(string $text): array
    {
        $text   = trim($text);
        $enc    = 'UTF-8';
        $len    = mb_strlen($text, $enc);
        if ($len === 0) {
            return [];
        }
        $target  = self::CHUNK_TARGET_CHARS;
        $overlap = self::CHUNK_OVERLAP_CHARS;
        $chunks  = [];
        $start   = 0;
        $index   = 0;
        while ($start < $len) {
            $end = min($len, $start + $target);
            if ($end < $len) {
                $sliceLen = $end - $start;
                $slice    = mb_substr($text, $start, $sliceLen, $enc);
                $breakPos = mb_strrpos($slice, "\n\n", 0, $enc);
                if ($breakPos !== false && $breakPos >= 120) {
                    $end = $start + $breakPos + 2;
                }
            }
            $chunkText = trim(mb_substr($text, $start, $end - $start, $enc));
            if ($chunkText === '') {
                if ($end >= $len) {
                    break;
                }
                $start = max($start + 1, $end);
                continue;
            }
            $chunks[] = [
                'chunk_index'   => $index,
                'start_offset'  => $start,
                'end_offset'    => $end,
                'content'       => $chunkText,
            ];
            $index++;
            if ($end >= $len) {
                break;
            }
            $nextStart = $end - $overlap;
            if ($nextStart <= $start) {
                $nextStart = $end;
            }
            $start = $nextStart;
        }
        return $chunks;
    }

    public static function buildFtsMatchQuery(string $objective, ?string $extra = null): string
    {
        $combined = trim($objective . "\n" . (string)$extra);
        if ($combined === '') {
            return '';
        }
        if (!preg_match_all('/\p{L}[\p{L}\p{N}]*/u', mb_strtolower($combined, 'UTF-8'), $m)) {
            return '';
        }
        $seen = [];
        $tokens = [];
        foreach ($m[0] ?? [] as $raw) {
            $t = mb_strtolower($raw, 'UTF-8');
            if (mb_strlen($t, 'UTF-8') < self::MIN_TOKEN_LEN) {
                continue;
            }
            if (in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            if (isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $tokens[] = $t;
            if (count($tokens) >= self::MAX_TOKENS) {
                break;
            }
        }
        if (empty($tokens)) {
            return '';
        }
        $parts = [];
        foreach ($tokens as $t) {
            $escaped = str_replace('"', '""', $t);
            $parts[] = '"' . $escaped . '"';
        }
        return implode(' OR ', $parts);
    }

    /**
     * @param list<array{id:int,chunk_index:int,content:string,rank:float>> $rows
     * @return list<array{id:int,chunk_index:int,content:string,rank:float}>
     */
    public static function dedupeByChunkIndex(array $rows, int $maxExcerpts = 5): array
    {
        $byIndex = [];
        foreach ($rows as $r) {
            $ci = $r['chunk_index'];
            if (!isset($byIndex[$ci]) || $r['rank'] < $byIndex[$ci]['rank']) {
                $byIndex[$ci] = $r;
            }
        }
        $merged = array_values($byIndex);
        usort($merged, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return array_slice($merged, 0, $maxExcerpts);
    }

    public static function excerptCell(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', trim($text));
        $text = str_replace('|', '\\|', $text);
        if (mb_strlen($text, 'UTF-8') > 240) {
            $text = mb_substr($text, 0, 237, 'UTF-8') . '…';
        }
        return '"' . $text . '"';
    }
}

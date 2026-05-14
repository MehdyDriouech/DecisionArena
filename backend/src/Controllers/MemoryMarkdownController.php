<?php
declare(strict_types=1);

namespace Controllers;

use Domain\Memory\MemorySnapshotGenerator;
use Http\Request;
use Http\RawResponse;
use Http\Response;

final class MemoryMarkdownController
{
    private MemorySnapshotGenerator $gen;

    public function __construct()
    {
        $this->gen = new MemorySnapshotGenerator();
    }

    /** GET /api/strategic-contexts/{context_id}/memory.md */
    public function context(Request $req): array|RawResponse
    {
        $contextId = (string)$req->param('context_id');
        $opts = $this->optionsFromQuery($req);
        $md = $this->gen->generateContextMarkdown($contextId, $opts);
        return $this->negotiate($md, ['context_id' => $contextId]);
    }

    /** GET /api/decision-rooms/{room_id}/memory.md */
    public function room(Request $req): array|RawResponse
    {
        $roomId = (string)$req->param('room_id');
        $opts = $this->optionsFromQuery($req);
        $md = $this->gen->generateRoomMarkdown($roomId, $opts);
        return $this->negotiate($md, ['room_id' => $roomId]);
    }

    /** @return array{include_stale:bool, include_archived:bool, include_expert_metadata:bool, max_memories:int, perspective:string} */
    private function optionsFromQuery(Request $req): array
    {
        $includeStale = (string)($req->query('include_stale') ?? '');
        $includeArchived = (string)($req->query('include_archived') ?? '');
        $expert = (string)($req->query('expert') ?? '');
        $max = (int)($req->query('max_memories') ?? 20);
        // perspective is optional. Invalid values are silently coerced to
        // "default" by the snapshot generator, so existing callers that omit
        // the param keep the legacy memory.md output unchanged.
        $perspective = (string)($req->query('perspective') ?? '');
        return [
            'include_stale' => ($includeStale === '1' || $includeStale === 'true'),
            'include_archived' => ($includeArchived === '1' || $includeArchived === 'true'),
            'include_expert_metadata' => ($expert === '1' || $expert === 'true'),
            'max_memories' => $max,
            'perspective' => $perspective,
        ];
    }

    /** @param array<string,string> $ids */
    private function negotiate(string $markdown, array $ids): array|RawResponse
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $generatedAt = date('c');
        if (stripos($accept, 'application/json') !== false) {
            return Response::json([
                'markdown' => $markdown,
                'generated_at' => $generatedAt,
                ...$ids,
            ]);
        }
        return new RawResponse($markdown, 'text/markdown; charset=utf-8', 200);
    }
}


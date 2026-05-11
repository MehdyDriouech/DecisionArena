<?php
declare(strict_types=1);

namespace Controllers;

use Domain\StrategicContext\AgentContextChatService;
use Domain\StrategicContext\AgentContextMemoryService;
use Http\Request;
use Http\Response;

final class AgentContextChatController
{
    private AgentContextChatService $service;

    public function __construct()
    {
        $this->service = new AgentContextChatService();
    }

    /** POST /api/strategic-contexts/{context_id}/agents/{agent_id}/chat */
    public function chat(Request $req): array
    {
        $cid = trim((string)$req->param('context_id'));
        $aid = trim((string)$req->param('agent_id'));
        $mem = new AgentContextMemoryService();
        if (!$mem->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$mem->isValidAgentId($aid)) {
            return Response::error('Invalid agent id', 400);
        }

        $body = $req->body();
        $message = trim((string)($body['message'] ?? ''));
        $conversationId = isset($body['conversation_id']) ? trim((string)$body['conversation_id']) : null;
        if ($conversationId === '') {
            $conversationId = null;
        }
        $sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : null;
        if ($sessionId === '') {
            $sessionId = null;
        }

        $includeMemory = $this->boolPayload($body['include_memory'] ?? true);
        $includeDecisions = $this->boolPayload($body['include_recent_decisions'] ?? true);
        $includeSocial = $this->boolPayload($body['include_social_context'] ?? true);
        $language = trim((string)($body['language'] ?? 'en'));

        $out = $this->service->exchange(
            $cid,
            $aid,
            $message,
            $includeMemory,
            $includeDecisions,
            $includeSocial,
            $conversationId,
            $sessionId,
            $language
        );
        if (!($out['ok'] ?? false)) {
            return Response::error((string)($out['message'] ?? 'Error'), (int)($out['code'] ?? 500));
        }
        unset($out['ok']);
        return $out;
    }

    /** @param mixed $v */
    private function boolPayload($v): bool
    {
        if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
            return false;
        }
        return true;
    }
}

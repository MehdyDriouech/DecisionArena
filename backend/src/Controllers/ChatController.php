<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\ChatRunner;

class ChatController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private ChatRunner $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->messageRepo = new MessageRepository();
        $this->runner      = new ChatRunner();
    }

    public function send(Request $req): array {
        $data           = $req->body();
        $sessionId      = $data['session_id'] ?? '';
        $message        = $data['message'] ?? '';
        $selectedAgents = $data['selected_agents'] ?? [];
        $contextMode    = $data['context_mode'] ?? 'chat';

        if (!$sessionId || !$message) {
            return Response::error('session_id and message required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $userMsg = $this->messageRepo->create([
            'id'          => $this->uuid(),
            'session_id'  => $sessionId,
            'role'        => 'user',
            'agent_id'    => null,
            'provider_id' => null,
            'model'       => null,
            'round'       => null,
            'content'     => $message,
            'created_at'  => date('c'),
        ]);

        if (empty($selectedAgents)) {
            $selectedAgents = json_decode($session['selected_agents'] ?? '[]', true);
        }

        $language = $session['language'] ?? 'en';

        $sessionContext = $session['initial_prompt'] ?? '';
        if ($contextMode !== 'chat') {
            $sessionContext = trim($sessionContext . "\n\n[Mode: {$contextMode}] This is a follow-up question inside an active session. Answer as a direct follow-up to the prior analysis — do not restart the whole analysis.");
        }

        $contextDoc    = (new ContextDocumentRepository())->findBySession($sessionId);

        $agentMessages = $this->runner->run(
            $sessionId,
            $message,
            $selectedAgents,
            $sessionContext,
            $language,
            $contextDoc
        );

        return [
            'user_message'   => $userMsg,
            'agent_messages' => $agentMessages,
        ];
    }

    private function uuid(): string {
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

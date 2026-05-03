<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\ChatRunner;
use Domain\Orchestration\ReactiveChatRunner;
use Domain\Orchestration\PromptBuilder;

class ChatController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private ChatRunner $runner;
    private ReactiveChatRunner $reactiveRunner;

    public function __construct() {
        $this->sessionRepo     = new SessionRepository();
        $this->messageRepo     = new MessageRepository();
        $this->runner          = new ChatRunner();
        $this->reactiveRunner  = new ReactiveChatRunner();
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

        $userMeta = null;
        if ($contextMode === 'challenge') {
            $co  = trim((string)($data['challenge_origin'] ?? ''));
            $cta = trim((string)($data['challenge_target_agent'] ?? ''));
            $cl  = (($data['challenge_level'] ?? 'soft') === 'firm') ? 'firm' : 'soft';
            $userMetaArr = array_filter([
                'context_mode'            => 'challenge',
                'challenge_origin'        => $co !== '' ? $co : null,
                'challenge_target_agent'  => $cta !== '' ? $cta : null,
                'challenge_level'         => $cl,
            ], fn($v) => $v !== null && $v !== '');
            $userMeta = $userMetaArr !== [] ? json_encode($userMetaArr, JSON_UNESCAPED_UNICODE) : null;
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
            'meta_json'   => $userMeta,
        ]);

        if (empty($selectedAgents)) {
            $selectedAgents = json_decode($session['selected_agents'] ?? '[]', true);
        }

        $language = $session['language'] ?? 'en';

        $sessionContext = $session['initial_prompt'] ?? '';
        if ($contextMode === 'challenge') {
            $sessionContext = trim($sessionContext . "\n\n[Challenge mode — single-agent revision] The user contests one specific prior assistant message."
                . " Respond alone: check justification vs the context, correct if needed, flag weak assumptions. Be concise."
                . " Do not simulate other agents or broaden into a full multi-agent debate.");
        } elseif ($contextMode !== 'chat') {
            $sessionContext = trim($sessionContext . "\n\n[Mode: {$contextMode}] This is a follow-up question inside an active session. Answer as a direct follow-up to the prior analysis — do not restart the whole analysis.");
        }

        $contextDoc = (new PromptBuilder())->prepareContextDocumentForPrompt(
            (new ContextDocumentRepository())->findBySession($sessionId)
        );

        $agentMessages = $this->runner->run(
            $sessionId,
            $message,
            $selectedAgents,
            $sessionContext,
            $language,
            $contextDoc
        );

        if ($contextMode === 'challenge') {
            $origin = trim((string)($data['challenge_origin'] ?? ''));
            if ($origin !== '') {
                $this->messageRepo->patchMetaJson($origin, [
                    'challenge_status' => 'challenged',
                ], (string)$userMsg['id']);
            }
            foreach ($agentMessages as $am) {
                $aid = (string)($am['id'] ?? '');
                if ($aid === '') {
                    continue;
                }
                $patch = [
                    'context_mode'              => 'challenge',
                    'challenge_response'        => true,
                    'challenge_user_message_id'  => (string)$userMsg['id'],
                ];
                if ($origin !== '') {
                    $patch['challenge_origin_message_id'] = $origin;
                }
                $this->messageRepo->patchMetaJson($aid, $patch);
            }
        }

        return [
            'user_message'   => $userMsg,
            'agent_messages' => $agentMessages,
        ];
    }

    /**
     * POST /api/chat/reactive
     *
     * Runs a structured reactive exchange between a primary agent and reactor agents.
     */
    public function reactive(Request $req): array {
        $data = $req->body();

        $sessionId       = trim((string)($data['session_id']       ?? ''));
        $question        = trim((string)($data['question']          ?? ''));
        $primaryAgentId  = trim((string)($data['primary_agent_id']  ?? ''));
        $reactorIds      = is_array($data['reactor_agent_ids'] ?? null) ? $data['reactor_agent_ids'] : [];

        if (!$sessionId)                   return Response::error('session_id required', 400);
        if (!$question)                    return Response::error('question required', 400);
        if (!$primaryAgentId)              return Response::error('primary_agent_id required', 400);
        if (empty($reactorIds))            return Response::error('reactor_agent_ids required (at least one)', 400);
        if (in_array($primaryAgentId, $reactorIds, true)) {
            return Response::error('primary_agent_id must not appear in reactor_agent_ids', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) return Response::error('Session not found', 404);

        $turnsMin = max(1, min(10, (int)($data['turns_min'] ?? 2)));
        $turnsMax = max(1, min(10, (int)($data['turns_max'] ?? 4)));
        $turnsMax = max($turnsMax, $turnsMin);

        $config = [
            'turns_min'                      => $turnsMin,
            'turns_max'                      => $turnsMax,
            'early_stop_enabled'             => (bool)($data['early_stop_enabled']             ?? true),
            'early_stop_confidence_threshold'=> (float)($data['early_stop_confidence_threshold']?? 0.85),
            'no_new_arguments_threshold'     => max(1, min(5, (int)($data['no_new_arguments_threshold'] ?? 2))),
            'reactor_mode'                   => in_array($data['reactor_mode']      ?? '', ['independent','sequential','collective']) ? $data['reactor_mode'] : 'independent',
            'debate_intensity'               => in_array($data['debate_intensity']  ?? '', ['low','medium','high']) ? $data['debate_intensity'] : 'medium',
            'reaction_style'                 => in_array($data['reaction_style']    ?? '', ['complementary','critical','contradictory','review']) ? $data['reaction_style'] : 'critical',
            'include_final_synthesis'        => (bool)($data['include_final_synthesis'] ?? true),
            'language'                       => (string)($session['language'] ?? 'en'),
        ];

        // Persist user question as a message
        $this->messageRepo->create([
            'id'          => $this->uuid(),
            'session_id'  => $sessionId,
            'role'        => 'user',
            'agent_id'    => null,
            'provider_id' => null,
            'model'       => null,
            'round'       => null,
            'content'     => $question,
            'created_at'  => date('c'),
        ]);

        $contextDoc = (new PromptBuilder())->prepareContextDocumentForPrompt(
            (new ContextDocumentRepository())->findBySession($sessionId)
        );

        $result = $this->reactiveRunner->run(
            $sessionId,
            $question,
            $primaryAgentId,
            $reactorIds,
            $config,
            $contextDoc
        );

        return [
            'session_id'      => $sessionId,
            'reactive_thread' => $result,
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

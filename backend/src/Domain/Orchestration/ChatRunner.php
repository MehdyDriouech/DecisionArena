<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\Providers\ProviderRouter;
use Infrastructure\Persistence\MessageRepository;

class ChatRunner {
    private AgentAssembler $assembler;
    private PromptBuilder $promptBuilder;
    private MentionDetector $mentionDetector;
    private ProviderRouter $providerRouter;
    private MessageRepository $messageRepo;

    public function __construct() {
        $this->assembler       = new AgentAssembler();
        $this->promptBuilder   = new PromptBuilder();
        $this->mentionDetector = new MentionDetector();
        $this->providerRouter  = new ProviderRouter();
        $this->messageRepo     = new MessageRepository();
    }

    public function run(
        string $sessionId,
        string $userMessage,
        array $selectedAgents,
        string $sessionContext = '',
        string $language = 'en',
        ?array $contextDoc = null
    ): array {
        $mentioned        = $this->mentionDetector->detect($userMessage, $selectedAgents);
        $respondingAgents = !empty($mentioned) ? $mentioned : $selectedAgents;

        $history     = $this->messageRepo->findBySession($sessionId);
        $newMessages = [];

        foreach ($respondingAgents as $agentId) {
            $agent = $this->assembler->assemble($agentId);
            if (!$agent) continue;

            try {
                $messages = $this->promptBuilder->buildChatMessages(
                    $agent,
                    $sessionContext,
                    $history,
                    $userMessage,
                    $language,
                    $contextDoc
                );

                $routed  = $this->providerRouter->chat($messages, $agent);
                $content = $routed['content'];

                $msg = $this->messageRepo->create([
                    'id'                       => $this->uuid(),
                    'session_id'               => $sessionId,
                    'role'                     => 'assistant',
                    'agent_id'                 => $agentId,
                    'provider_id'              => $routed['provider_id'] ?? null,
                    'provider_name'            => $routed['provider_name'] ?? null,
                    'model'                    => $routed['model'] ?? null,
                    'requested_provider_id'    => $routed['requested_provider_id'] ?? null,
                    'requested_model'          => $routed['requested_model'] ?? null,
                    'provider_fallback_used'   => ($routed['fallback_used'] ?? false) ? 1 : 0,
                    'provider_fallback_reason' => $routed['fallback_reason'] ?? null,
                    'round'                    => null,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $newMessages[] = $msg;

            } catch (\Throwable $e) {
                $msg = $this->messageRepo->create([
                    'id'                       => $this->uuid(),
                    'session_id'               => $sessionId,
                    'role'                     => 'assistant',
                    'agent_id'                 => $agentId,
                    'provider_id'              => null,
                    'provider_name'            => null,
                    'model'                    => null,
                    'requested_provider_id'    => null,
                    'requested_model'          => null,
                    'provider_fallback_used'   => 0,
                    'provider_fallback_reason' => null,
                    'round'                    => null,
                    'content'                  => '[Error] ' . $e->getMessage(),
                    'created_at'               => date('c'),
                ]);
                $newMessages[] = $msg;
            }
        }

        return $newMessages;
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

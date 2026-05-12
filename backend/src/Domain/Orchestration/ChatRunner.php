<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\Providers\ProviderRouter;
use Infrastructure\Logging\Logger;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Infrastructure\Persistence\SessionRepository;

class ChatRunner {
    private AgentAssembler $assembler;
    private PromptBuilder $promptBuilder;
    private MentionDetector $mentionDetector;
    private ProviderRouter $providerRouter;
    private Logger $logger;
    private MessageRepository $messageRepo;
    private SessionRepository $sessionRepo;
    private SessionAgentProvidersRepository $agentProvidersRepo;

    public function __construct() {
        $this->assembler       = new AgentAssembler();
        $this->promptBuilder   = new PromptBuilder();
        $this->mentionDetector = new MentionDetector();
        $this->providerRouter  = new ProviderRouter();
        $this->logger          = new Logger();
        $this->messageRepo     = new MessageRepository();
        $this->sessionRepo     = new SessionRepository();
        $this->agentProvidersRepo = new SessionAgentProvidersRepository();
    }

    public function run(
        string $sessionId,
        string $userMessage,
        array $selectedAgents,
        string $sessionContext = '',
        string $language = 'en',
        ?array $contextDoc = null,
        ?string $decisionDynamicsPreset = null
    ): array {
        $result = $this->runWithRuntime(
            $sessionId,
            $userMessage,
            $selectedAgents,
            $sessionContext,
            $language,
            $contextDoc,
            $decisionDynamicsPreset
        );

        return $result['messages'];
    }

    /**
     * @return array<string,mixed>
     */
    public function runWithRuntime(
        string $sessionId,
        string $userMessage,
        array $selectedAgents,
        string $sessionContext = '',
        string $language = 'en',
        ?array $contextDoc = null,
        ?string $decisionDynamicsPreset = null
    ): array {
        $mentioned        = $this->mentionDetector->detect($userMessage, $selectedAgents);
        $respondingAgents = !empty($mentioned) ? $mentioned : $selectedAgents;

        $history     = $this->messageRepo->findBySession($sessionId);
        $newMessages = [];
        $runtimeTraces = [];
        $agentOverrides = $this->agentProvidersRepo->findBySession($sessionId);
        $dynamicsPreset = \Domain\Agents\DecisionDynamicsPreset::normalizeId($decisionDynamicsPreset);
        $strategicCtx = null;
        try {
            $sessRow = $this->sessionRepo->findById($sessionId);
            if ($sessRow && !empty($sessRow['strategic_context_id'])) {
                $strategicCtx = (string)$sessRow['strategic_context_id'];
            }
        } catch (\Throwable) {
        }

        foreach ($respondingAgents as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            if (!$agent) continue;

            try {
                $messages = $this->promptBuilder->buildChatMessages(
                    $agent,
                    $sessionContext,
                    $history,
                    $userMessage,
                    $language,
                    $contextDoc,
                    $sessionId,
                    $strategicCtx
                );
                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $messages,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => $strategicCtx,
                        'round' => null,
                        'agent_id' => $agentId,
                        'mode' => 'chat',
                    ],
                    'chat_user_payload',
                    'orchestration',
                    'chat_runtime_user_payload'
                );
                $messages = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];
                if (is_array($governed['trace'] ?? null)) {
                    $runtimeTraces[] = $governed['trace'];
                }
                $this->logger->logPromptBuild('prompt_built_chat', [
                    'agent_id' => $agent->id,
                    'metadata' => [
                        'mode' => 'chat',
                        'message_count' => count($messages),
                        'character_count' => $this->countMessageChars($messages),
                        'context_doc_injected' => !empty($contextDoc['content']),
                        'session_id' => $sessionId,
                    ],
                ]);

                $routed  = $this->providerRouter->chat(
                    $messages,
                    $agent,
                    null,
                    null,
                    $this->resolveAgentOverride($agentOverrides, (string)$agentId)
                );
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
                    'routing_source'           => $routed['routing_source'] ?? null,
                    'resolved_provider_id'     => $routed['resolved_provider_id'] ?? null,
                    'resolved_provider_label'  => $routed['resolved_provider_label'] ?? null,
                    'resolved_model'           => $routed['resolved_model'] ?? null,
                    'session_override_present' => $routed['session_override_present'] ?? null,
                    'persona_default_provider_ignored' => $routed['persona_default_provider_ignored'] ?? null,
                    'fallback_from_provider_id' => $routed['fallback_from_provider_id'] ?? null,
                    'fallback_from_model'      => $routed['fallback_from_model'] ?? null,
                    'round'                    => null,
                    'meta_json'                => $promptMetaJson,
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

        return array_merge([
            'messages' => $newMessages,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'chat'));
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

    private function countMessageChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen((string)($message['content'] ?? ''), 'UTF-8');
        }
        return $chars;
    }

    /**
     * @param array<string, array{provider_id?: string, model?: string|null}> $agentOverrides
     * @return array{provider_id?: string, model?: string|null}|null
     */
    private function resolveAgentOverride(array $agentOverrides, string $agentId): ?array
    {
        $exact = trim($agentId);
        if ($exact !== '' && isset($agentOverrides[$exact]) && is_array($agentOverrides[$exact])) {
            return $agentOverrides[$exact];
        }
        $lower = strtolower($exact);
        if ($lower === '') {
            return null;
        }
        foreach ($agentOverrides as $key => $row) {
            if (strtolower(trim((string)$key)) === $lower && is_array($row)) {
                return $row;
            }
        }
        return null;
    }
}

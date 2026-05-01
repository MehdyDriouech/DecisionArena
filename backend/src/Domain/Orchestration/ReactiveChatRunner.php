<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\Providers\ProviderRouter;
use Domain\SocialDynamics\SocialDynamicsService;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;

/**
 * ReactiveChatRunner
 *
 * Orchestrates a structured reactive exchange between a primary agent and
 * one or more reactor agents.
 *
 * Turn structure:
 *   1. Primary answers the user's question (turn 1) or responds to reactor feedback.
 *   2. Reactor agents react, each building on the primary's latest answer.
 *   3. Stop policy is evaluated after each full turn.
 *   4. Optional final synthesis by 'synthesizer' or primary.
 */
class ReactiveChatRunner
{
    private const MAX_TURNS   = 10;
    private const MIN_TURNS   = 1;
    private const MAX_CONTENT = 3000; // Truncation for context injection

    private AgentAssembler                  $assembler;
    private ProviderRouter                  $router;
    private MessageRepository               $messageRepo;
    private SessionAgentProvidersRepository $agentProvidersRepo;

    public function __construct()
    {
        $this->assembler          = new AgentAssembler();
        $this->router             = new ProviderRouter();
        $this->messageRepo        = new MessageRepository();
        $this->agentProvidersRepo = new SessionAgentProvidersRepository();
    }

    /**
     * Run a reactive chat thread.
     *
     * @param string $sessionId
     * @param string $question
     * @param string $primaryAgentId
     * @param array  $reactorAgentIds
     * @param array  $config  {turns_min, turns_max, early_stop_enabled,
     *                         early_stop_confidence_threshold, no_new_arguments_threshold,
     *                         reactor_mode, debate_intensity, reaction_style,
     *                         include_final_synthesis, language}
     * @return array  {turns_executed, early_stopped, early_stop_reason, messages, final_synthesis}
     */
    public function run(
        string $sessionId,
        string $question,
        string $primaryAgentId,
        array  $reactorAgentIds,
        array  $config = []
    ): array {
        $turnsMin   = max(self::MIN_TURNS, min(self::MAX_TURNS, (int)($config['turns_min']   ?? 2)));
        $turnsMax   = max(self::MIN_TURNS, min(self::MAX_TURNS, (int)($config['turns_max']   ?? 4)));
        $turnsMax   = max($turnsMax, $turnsMin);
        $language   = (string)($config['language']            ?? 'en');
        $reactorMode= (string)($config['reactor_mode']         ?? 'independent');
        $intensity  = (string)($config['debate_intensity']     ?? 'medium');
        $style      = (string)($config['reaction_style']       ?? 'critical');
        $synth      = (bool)($config['include_final_synthesis'] ?? true);

        $threadId = $this->uuid();
        $stopPolicy = new ReactiveChatStopPolicy($config);

        $agentOverrides = $this->agentProvidersRepo->findBySession($sessionId);

        $allMessages    = [];
        $allTurnsHistory = [];
        $earlyStop      = false;
        $earlyStopReason = null;
        $primaryLatestAnswer = '';
        $executedTurns  = 0;

        // ── Social dynamics (optional — graceful degradation) ──────────────
        $socialDynamics = null;
        try {
            $socialDynamics = new SocialDynamicsService();
            $socialDynamics->clearSession($sessionId);
        } catch (\Throwable $e) {
            $socialDynamics = null;
        }

        for ($turn = 1; $turn <= $turnsMax; $turn++) {
            $turnMessages = [];
            $executedTurns = $turn;

            // ── Step 1: Primary agent answers / responds ───────────────────
            $primaryAgent = $this->assembler->assemble($primaryAgentId);
            if (!$primaryAgent) break;

            try {
                $isFirstTurn = ($turn === 1);
                $primaryPrompt = $isFirstTurn
                    ? $this->buildPrimaryInitialPrompt($question, $language, $intensity)
                    : $this->buildPrimaryResponsePrompt($question, $primaryLatestAnswer, $this->collectReactorFeedback($allTurnsHistory, $turn - 1), $language, $intensity);

                $routed = $this->router->chat($primaryPrompt, $primaryAgent, null, null, $agentOverrides[$primaryAgentId] ?? null);
                $primaryLatestAnswer = $routed['content'];

                $msg = $this->saveMessage($sessionId, $threadId, $primaryAgentId, 'primary', $turn, $routed, null);
                $turnMessages[] = $msg;
                $allMessages[]  = $msg;

                if ($socialDynamics) {
                    try {
                        $socialDynamics->ingestAgentResponse($sessionId, $turn, $primaryAgentId, $routed['content'], null, array_merge([$primaryAgentId], $reactorAgentIds), [], []);
                    } catch (\Throwable $_) {}
                }
            } catch (\Throwable $e) {
                $msg = $this->saveErrorMessage($sessionId, $threadId, $primaryAgentId, 'primary', $turn, $e->getMessage(), $agentOverrides[$primaryAgentId] ?? null);
                $turnMessages[] = $msg;
                $allMessages[]  = $msg;
                $primaryLatestAnswer = '';
            }

            // ── Step 2: Reactor agents react ───────────────────────────────
            $previousReactionsThisTurn = [];
            foreach ($reactorAgentIds as $reactorId) {
                $reactorAgent = $this->assembler->assemble($reactorId);
                if (!$reactorAgent) continue;

                try {
                    $prevReactionsContext = $this->buildPreviousReactionsContext($previousReactionsThisTurn, $reactorMode);
                    $reactorPrompt = $this->buildReactorPrompt(
                        $question,
                        $primaryLatestAnswer,
                        $this->collectReactorFeedback($allTurnsHistory, $turn - 1),
                        $prevReactionsContext,
                        $language,
                        $intensity,
                        $style
                    );

                    $routed = $this->router->chat($reactorPrompt, $reactorAgent, null, null, $agentOverrides[$reactorId] ?? null);
                    $msg = $this->saveMessage($sessionId, $threadId, $reactorId, 'reactor', $turn, $routed, $primaryAgentId);
                    $turnMessages[] = $msg;
                    $allMessages[]  = $msg;
                    $previousReactionsThisTurn[] = ['agent' => $reactorId, 'content' => $routed['content']];

                    if ($socialDynamics) {
                        try {
                            $socialDynamics->ingestAgentResponse($sessionId, $turn, $reactorId, $routed['content'], $primaryAgentId, array_merge([$primaryAgentId], $reactorAgentIds), [], []);
                        } catch (\Throwable $_) {}
                    }
                } catch (\Throwable $e) {
                    $msg = $this->saveErrorMessage($sessionId, $threadId, $reactorId, 'reactor', $turn, $e->getMessage(), $agentOverrides[$reactorId] ?? null);
                    $turnMessages[] = $msg;
                    $allMessages[]  = $msg;
                }
            }

            $allTurnsHistory[] = $turnMessages;

            // ── Step 3: Early stop evaluation ─────────────────────────────
            $stopResult = $stopPolicy->shouldStop($turn, $turnMessages, $allTurnsHistory);
            if ($stopResult['stop']) {
                $earlyStop = ($stopResult['reason'] !== 'max_turns_reached');
                $earlyStopReason = $stopResult['reason'];
                break;
            }
        }

        // ── Final synthesis (optional) ─────────────────────────────────────
        $finalSynthesis = null;
        if ($synth) {
            $synthAgentId = 'synthesizer';
            $synthAgent   = $this->assembler->assemble($synthAgentId);
            if (!$synthAgent) {
                $synthAgentId = $primaryAgentId;
                $synthAgent   = $this->assembler->assemble($primaryAgentId);
            }
            if ($synthAgent) {
                try {
                    $synthPrompt = $this->buildSynthesisPrompt($question, $allMessages, $language);
                    $routed      = $this->router->chat($synthPrompt, $synthAgent, null, null, $agentOverrides[$synthAgentId] ?? null);
                    $msg = $this->saveMessage($sessionId, $threadId, $synthAgentId, 'synthesizer', $executedTurns + 1, $routed, null);
                    $allMessages[]  = $msg;
                    $finalSynthesis = $msg;
                } catch (\Throwable $e) {
                    $msg = $this->saveErrorMessage($sessionId, $threadId, $synthAgentId, 'synthesizer', $executedTurns + 1, $e->getMessage(), $agentOverrides[$synthAgentId] ?? null);
                    $allMessages[]  = $msg;
                    $finalSynthesis = $msg;
                }
            }
        }

        return [
            'turns_executed'   => $executedTurns,
            'early_stopped'    => $earlyStop,
            'early_stop_reason'=> $earlyStopReason,
            'messages'         => $allMessages,
            'final_synthesis'  => $finalSynthesis,
        ];
    }

    // ── Prompt builders ────────────────────────────────────────────────────

    private function buildPrimaryInitialPrompt(string $question, string $language, string $intensity): array
    {
        $intensityNote = $this->intensityNote($intensity);
        return [
            [
                'role'    => 'system',
                'content' => "You are the primary expert agent in a structured reactive debate.
Your answer will be challenged by other agents. Be clear, specific and argumented.
Language: {$language}.
{$intensityNote}
Do not add pleasantries. Be direct.",
            ],
            [
                'role'    => 'user',
                'content' => "## User Question\n{$question}\n\n## Your Role\nYou are the primary agent.\n\n## Task\nAnswer the question with your best expert answer.\nBe clear, actionable and specific.\nPrepare for other agents to challenge or improve your answer.\n\n## Output Format\n## Position\nGO | NO-GO | ITERATE | ANSWER\n\n## Answer\n[Your detailed answer]\n\n## Confidence\n[0.0 to 1.0]",
            ],
        ];
    }

    private function buildPrimaryResponsePrompt(string $question, string $previousAnswer, string $reactorFeedback, string $language, string $intensity): array
    {
        $intensityNote = $this->intensityNote($intensity);
        return [
            [
                'role'    => 'system',
                'content' => "You are the primary expert agent responding to challengers.
Acknowledge valid critiques, defend what remains valid, improve your answer.
Language: {$language}.
{$intensityNote}
Be direct and substantive. Do not be defensive without argument.",
            ],
            [
                'role'    => 'user',
                'content' => "## User Question\n{$question}\n\n## Your Previous Answer\n{$previousAnswer}\n\n## Reactor Feedback\n{$reactorFeedback}\n\n## Task\nRespond to the objections and improve your answer.\nAcknowledge valid critiques.\nDefend what remains valid.\nAdjust your final recommendation if needed.\n\n## Output Format\n## Response To Feedback\n- Acknowledged:\n- Defended:\n- Adjusted:\n\n## Improved Answer\n[Your updated answer]\n\n## New Argument\nyes|no\n\n## Confidence\n[0.0 to 1.0]",
            ],
        ];
    }

    private function buildReactorPrompt(
        string $question,
        string $primaryAnswer,
        string $priorHistory,
        string $prevReactionsThisTurn,
        string $language,
        string $intensity,
        string $style
    ): array {
        $styleNote = $this->styleNote($style);
        $intensityNote = $this->intensityNote($intensity);
        $priorHistorySection = $priorHistory ? "\n\n## Prior Exchange History\n{$priorHistory}" : '';
        $prevReactSection    = $prevReactionsThisTurn ? "\n\n## Other Reactors This Turn\n{$prevReactionsThisTurn}" : '';
        return [
            [
                'role'    => 'system',
                'content' => "You are a reactor agent in a structured debate.
{$styleNote}
{$intensityNote}
Language: {$language}.
Do not repeat the primary agent. Add value. Be specific.",
            ],
            [
                'role'    => 'user',
                'content' => "## User Question\n{$question}\n\n## Primary Agent Answer\n{$primaryAnswer}{$priorHistorySection}{$prevReactSection}\n\n## Your Role\nYou are a reactor agent.\n\n## Task\nReact to the question and to the primary agent's latest answer.\nDo not repeat generic agreement.\nQuote or summarize the specific point you react to.\nAdd a useful objection, improvement, risk or nuance.\n\n## Output Format\n## Reaction\n- Point challenged or improved:\n- Why it matters:\n- Suggested improvement:\n\n## New Argument\nyes|no\n\n## Confidence\n[0.0 to 1.0]",
            ],
        ];
    }

    private function buildSynthesisPrompt(string $question, array $allMessages, string $language): array
    {
        $threadText = $this->buildThreadText($allMessages);
        return [
            [
                'role'    => 'system',
                'content' => "You are the synthesis agent. Produce a concise, balanced final synthesis.
Language: {$language}.
Be concrete. Avoid repetition. Focus on what was resolved and what remains uncertain.",
            ],
            [
                'role'    => 'user',
                'content' => "## User Question\n{$question}\n\n## Full Reactive Thread\n{$threadText}\n\n## Task\nProduce a concise final synthesis.\n\n## Output Format\n## Final Answer\n[The best answer after discussion]\n\n## Strongest Objections\n[List objections that were not fully resolved]\n\n## Remaining Uncertainties\n[What is still unclear]\n\n## Recommendation\n[Practical next step]",
            ],
        ];
    }

    // ── Context helpers ────────────────────────────────────────────────────

    private function collectReactorFeedback(array $allTurnsHistory, int $lastTurn): string
    {
        if (empty($allTurnsHistory) || $lastTurn <= 0) return '';
        $turnIdx = $lastTurn - 1;
        if (!isset($allTurnsHistory[$turnIdx])) return '';
        $msgs = array_filter($allTurnsHistory[$turnIdx], fn($m) => ($m['reaction_role'] ?? '') === 'reactor');
        return implode("\n\n", array_map(
            fn($m) => "[" . ($m['agent_id'] ?? 'Reactor') . "]: " . $this->truncate((string)($m['content'] ?? ''), self::MAX_CONTENT),
            $msgs
        ));
    }

    private function buildPreviousReactionsContext(array $previousReactionsThisTurn, string $reactorMode): string
    {
        if ($reactorMode === 'independent' || empty($previousReactionsThisTurn)) return '';
        return implode("\n\n", array_map(
            fn($r) => "[" . $r['agent'] . "]: " . $this->truncate((string)$r['content'], self::MAX_CONTENT),
            $previousReactionsThisTurn
        ));
    }

    private function buildThreadText(array $allMessages): string
    {
        $parts = [];
        foreach ($allMessages as $msg) {
            $role = $msg['reaction_role'] ?? $msg['role'] ?? 'agent';
            $agent = $msg['agent_id'] ?? 'unknown';
            $turn  = isset($msg['thread_turn']) ? " [Turn {$msg['thread_turn']}]" : '';
            $parts[] = "**{$agent}** ({$role}){$turn}:\n" . $this->truncate((string)($msg['content'] ?? ''), self::MAX_CONTENT);
        }
        return implode("\n\n---\n\n", $parts);
    }

    private function intensityNote(string $intensity): string
    {
        return match($intensity) {
            'low'  => 'Keep the tone constructive and collaborative.',
            'high' => 'Be forceful and direct. Challenge assumptions strongly. Attack reasoning, not persons.',
            default=> 'Balance challenge and collaboration. Be substantive.',
        };
    }

    private function styleNote(string $style): string
    {
        return match($style) {
            'complementary'  => 'Your role is to add complementary perspectives and improvements.',
            'critical'       => 'Your role is to critically assess the primary answer and find weaknesses.',
            'contradictory'  => 'Your role is to challenge and contradict the primary answer where it is wrong or incomplete.',
            'review'         => 'Your role is to review the primary answer like a senior peer reviewer.',
            default          => 'React constructively to the primary agent.',
        };
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLen) return $text;
        return mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
    }

    // ── Message persistence helpers ────────────────────────────────────────

    private function saveMessage(
        string $sessionId,
        string $threadId,
        string $agentId,
        string $reactionRole,
        int    $turn,
        array  $routed,
        ?string $targetAgentId
    ): array {
        return $this->messageRepo->create([
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
            'round'                    => $turn,
            'phase'                    => "reactive-turn-{$turn}",
            'mode_context'             => 'reactive-chat',
            'message_type'             => $reactionRole,
            'target_agent_id'          => $targetAgentId,
            'thread_type'              => 'reactive_chat',
            'thread_turn'              => $turn,
            'reaction_role'            => $reactionRole,
            'reactive_thread_id'       => $threadId,
            'content'                  => $routed['content'],
            'created_at'               => date('c'),
        ]);
    }

    private function saveErrorMessage(
        string $sessionId,
        string $threadId,
        string $agentId,
        string $reactionRole,
        int    $turn,
        string $errorMsg,
        ?array $agentOverride
    ): array {
        return $this->messageRepo->create([
            'id'                       => $this->uuid(),
            'session_id'               => $sessionId,
            'role'                     => 'assistant',
            'agent_id'                 => $agentId,
            'provider_id'              => null,
            'provider_name'            => null,
            'model'                    => null,
            'requested_provider_id'    => $agentOverride['provider_id'] ?? null,
            'requested_model'          => $agentOverride['model'] ?? null,
            'provider_fallback_used'   => 0,
            'provider_fallback_reason' => null,
            'round'                    => $turn,
            'phase'                    => "reactive-turn-{$turn}",
            'mode_context'             => 'reactive-chat',
            'message_type'             => $reactionRole,
            'target_agent_id'          => null,
            'thread_type'              => 'reactive_chat',
            'thread_turn'              => $turn,
            'reaction_role'            => $reactionRole,
            'reactive_thread_id'       => $threadId,
            'content'                  => '[Error] ' . $errorMsg,
            'created_at'               => date('c'),
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

<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\Providers\ProviderRouter;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\VoteRepository;

class DecisionRoomRunner {
    private AgentAssembler     $assembler;
    private PromptBuilder      $promptBuilder;
    private ProviderRouter     $providerRouter;
    private MessageRepository  $messageRepo;
    private VerdictRepository  $verdictRepo;
    private DebateMemoryService $debateMemory;
    private VoteRepository $voteRepo;
    private VoteParser $voteParser;
    private VoteAggregator $voteAggregator;

    public function __construct() {
        $this->assembler     = new AgentAssembler();
        $this->promptBuilder = new PromptBuilder();
        $this->providerRouter = new ProviderRouter();
        $this->messageRepo   = new MessageRepository();
        $this->verdictRepo   = new VerdictRepository();
        $this->debateMemory  = new DebateMemoryService(new DebateRepository());
        $this->voteRepo      = new VoteRepository();
        $this->voteParser    = new VoteParser();
        $this->voteAggregator = new VoteAggregator($this->voteRepo);
    }

    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        int    $rounds = 2,
        string $language = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        bool   $devilAdvocateEnabled = false,
        float  $devilAdvocateThreshold = 0.65,
        array  $agentProviders = []
    ): array {
        $rounds              = min(max($rounds, 1), RoundPolicy::MAX_ROUNDS);
        $allMessages         = [];
        $previousRoundMessages = [];
        $state               = $this->debateMemory->loadState($sessionId);
        $this->voteRepo->clearSession($sessionId);

        $daPromptPath = __DIR__ . '/../../../storage/prompts/devil_advocate.md';
        $daPrompt     = file_exists($daPromptPath) ? file_get_contents($daPromptPath) : '';

        for ($round = 1; $round <= $rounds; $round++) {
            $roundMessages  = [];
            $agentsForRound = $selectedAgents;

            // Synthesizer speaks last only on the final round
            $hasSynthesizer = in_array('synthesizer', $agentsForRound, true);
            if ($hasSynthesizer) {
                $agentsForRound = array_values(
                    array_filter($agentsForRound, fn($a) => $a !== 'synthesizer')
                );
                if ($round === $rounds) {
                    $agentsForRound[] = 'synthesizer';
                }
            }

            foreach ($agentsForRound as $agentId) {
                $agent = $this->assembler->assemble($agentId);
                if (!$agent) continue;

                $assignedTarget = ($round > 1 && $agentId !== 'synthesizer')
                    ? $this->computeAssignedTarget($agentsForRound, $agentId, $round)
                    : null;

                try {
                    $messages = $this->promptBuilder->buildDecisionRoomMessages(
                        $agent,
                        $objective,
                        $previousRoundMessages,
                        $round,
                        $rounds,
                        $language,
                        $forceDisagreement,
                        $contextDoc,
                        $this->debateMemory->buildPromptContext($state),
                        $assignedTarget
                    );

                    $routed  = $this->providerRouter->chat($messages, $agent, null, null, $agentProviders[$agentId] ?? null);
                    $content = $routed['content'];

                    $msg = $this->messageRepo->create([
                        'id'           => $this->uuid(),
                        'session_id'   => $sessionId,
                        'role'         => 'assistant',
                        'agent_id'     => $agentId,
                        'provider_id'  => $routed['provider_id'] ?? null,
                        'model'        => $routed['model'] ?? null,
                        'round'        => $round,
                        'phase'        => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'mode_context' => 'decision-room',
                        'message_type' => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'content'      => $content,
                        'created_at'   => date('c'),
                    ]);
                    $roundMessages[] = $msg;
                    $targetAgentId = $this->resolveTargetAgentId($content, $previousRoundMessages, $agentId, $assignedTarget);
                    $this->debateMemory->processMessage(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        $state
                    );
                    if ($agentId !== 'synthesizer' && $round === $rounds) {
                        $parsedVote = $this->voteParser->parse($content);
                        if ($parsedVote) {
                            $this->voteRepo->createVote([
                                'id' => $this->uuid(),
                                'session_id' => $sessionId,
                                'round' => $round,
                                'agent_id' => $agentId,
                                'vote' => $parsedVote['vote'],
                                'confidence' => $parsedVote['confidence'],
                                'impact' => $parsedVote['impact'],
                                'domain_weight' => $parsedVote['domain_weight'],
                                'weight_score' => $parsedVote['weight_score'],
                                'rationale' => $parsedVote['rationale'],
                                'created_at' => date('c'),
                            ]);
                        } else {
                            error_log('[DecisionRoomRunner] Final vote parse failed for agent ' . $agentId);
                        }
                    }

                    // Parse verdict from synthesizer in final round
                    if ($agentId === 'synthesizer' && $round === $rounds) {
                        $parsed = VerdictParser::parse($content);
                        if ($parsed) {
                            $verdictData = array_merge($parsed, [
                                'id'         => $this->uuid(),
                                'session_id' => $sessionId,
                                'created_at' => date('c'),
                            ]);
                            $this->verdictRepo->create($verdictData);
                        }
                    }

                } catch (\Throwable $e) {
                    $msg = $this->messageRepo->create([
                        'id'           => $this->uuid(),
                        'session_id'   => $sessionId,
                        'role'         => 'assistant',
                        'agent_id'     => $agentId,
                        'provider_id'  => null,
                        'model'        => null,
                        'round'        => $round,
                        'phase'        => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'mode_context' => 'decision-room',
                        'message_type' => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'content'      => '[Error] ' . $e->getMessage(),
                        'created_at'   => date('c'),
                    ]);
                    $roundMessages[] = $msg;
                }
            }

            // Devil's Advocate: inject after all agents have spoken in this round
            if ($devilAdvocateEnabled && $daPrompt !== '') {
                $positiveKeywords = ['go', 'recommend', 'feasible', 'viable', 'agree'];
                $positiveCount    = 0;
                foreach ($roundMessages as $rm) {
                    $lc = strtolower((string)($rm['content'] ?? ''));
                    foreach ($positiveKeywords as $kw) {
                        if (str_contains($lc, $kw)) {
                            $positiveCount++;
                            break;
                        }
                    }
                }
                $partialConfidence = $positiveCount / max(1, count($roundMessages));
                if ($partialConfidence > $devilAdvocateThreshold) {
                    $last3   = array_slice($roundMessages, -3);
                    $context = implode("\n\n", array_map(
                        fn($m) => '[' . ($m['agent_id'] ?? 'agent') . ']: ' . ($m['content'] ?? ''),
                        $last3
                    ));
                    $daMessages = [
                        ['role' => 'system', 'content' => $daPrompt],
                        ['role' => 'user', 'content' => "Debate so far: ...$context..."],
                    ];
                    try {
                        $daRouted  = $this->providerRouter->chat($daMessages, null, null, null, null);
                        $daContent = $daRouted['content'];
                        $daMsg     = $this->messageRepo->create([
                            'id'           => $this->uuid(),
                            'session_id'   => $sessionId,
                            'role'         => 'assistant',
                            'agent_id'     => 'devil_advocate',
                            'provider_id'  => $daRouted['provider_id'] ?? null,
                            'model'        => $daRouted['model'] ?? null,
                            'round'        => $round,
                            'phase'        => 'devil-advocate',
                            'mode_context' => 'decision-room',
                            'message_type' => 'devil_advocate',
                            'content'      => $daContent,
                            'created_at'   => date('c'),
                        ]);
                        $roundMessages[] = $daMsg;
                    } catch (\Throwable $e) {
                        error_log('[DecisionRoomRunner] Devil advocate failed: ' . $e->getMessage());
                    }
                }
            }

            $previousRoundMessages = $roundMessages;
            $allMessages[$round]   = $roundMessages;
        }

        $automaticDecision = $this->voteAggregator->recompute($sessionId, 0.55);
        return [
            'rounds' => $allMessages,
            'arguments' => $state['arguments'],
            'positions' => $state['positions'],
            'interaction_edges' => $state['edges'],
            'weighted_analysis' => $this->debateMemory->buildWeightedAnalysis($state),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($state),
            'votes' => $this->voteRepo->findVotesBySession($sessionId),
            'automatic_decision' => $automaticDecision,
        ];
    }

    private function resolveTargetAgentId(string $content, array $previousRoundMessages, string $agentId, ?string $assignedTarget = null): ?string {
        if (!empty($previousRoundMessages)) {
            // 1. Explicit LLM declaration takes priority
            if (preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
                $parsed = strtolower(trim($m[1]));
                $valid  = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array($parsed, $valid, true) && $parsed !== strtolower($agentId)) {
                    return $parsed;
                }
            }
            // 2. Fall back to the pre-assigned target when LLM was silent
            if ($assignedTarget !== null) {
                $valid = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array(strtolower($assignedTarget), $valid, true)) {
                    return $assignedTarget;
                }
            }
        }
        return null;
    }

    /**
     * Assigns a unique challenge target to each agent using a round-robin
     * rotation so that all agents in the graph receive at least one incoming
     * edge per round.
     */
    private function computeAssignedTarget(array $allAgentIds, string $agentId, int $round): ?string {
        $others = array_values(array_filter($allAgentIds, fn($id) => $id !== $agentId && $id !== 'synthesizer'));
        if (empty($others)) {
            return null;
        }
        $nonSynth = array_values(array_filter($allAgentIds, fn($id) => $id !== 'synthesizer'));
        $agentIdx = (int)(array_search($agentId, $nonSynth) ?: 0);
        return $others[($agentIdx + $round) % count($others)];
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

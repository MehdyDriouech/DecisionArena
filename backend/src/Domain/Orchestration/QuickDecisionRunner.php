<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\Providers\ProviderRouter;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\VoteRepository;

class QuickDecisionRunner {
    private AgentAssembler     $assembler;
    private PromptBuilder      $promptBuilder;
    private ProviderRouter     $providerRouter;
    private MessageRepository  $messageRepo;
    private VerdictRepository  $verdictRepo;
    private VoteRepository $voteRepo;
    private VoteParser $voteParser;
    private VoteAggregator $voteAggregator;

    public function __construct() {
        $this->assembler     = new AgentAssembler();
        $this->promptBuilder = new PromptBuilder();
        $this->providerRouter = new ProviderRouter();
        $this->messageRepo   = new MessageRepository();
        $this->verdictRepo   = new VerdictRepository();
        $this->voteRepo      = new VoteRepository();
        $this->voteParser    = new VoteParser();
        $this->voteAggregator = new VoteAggregator($this->voteRepo);
    }

    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        string $language          = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc        = null
    ): array {
        $warning = null;
        $this->voteRepo->clearSession($sessionId);

        $nonSynth = array_values(array_filter($selectedAgents, fn($a) => $a !== 'synthesizer'));
        if (count($nonSynth) > 3) {
            $warning  = 'More than 3 agents selected. Using only first 3.';
            $nonSynth = array_slice($nonSynth, 0, 3);
        }
        if (empty($nonSynth)) {
            $nonSynth = ['pm', 'critic'];
        }

        $roundMessages = [];

        foreach ($nonSynth as $agentId) {
            $agent = $this->assembler->assemble($agentId);
            if (!$agent) continue;

            try {
                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $agent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc
                );

                $routed  = $this->providerRouter->chat($messages, $agent);
                $content = $routed['content'];

                $msg = $this->messageRepo->create([
                    'id'           => $this->uuid(),
                    'session_id'   => $sessionId,
                    'role'         => 'assistant',
                    'agent_id'     => $agentId,
                    'provider_id'  => $routed['provider_id'] ?? null,
                    'model'        => $routed['model'] ?? null,
                    'round'        => 1,
                    'phase'        => 'analysis',
                    'mode_context' => 'quick-decision',
                    'message_type' => 'analysis',
                    'content'      => $content,
                    'created_at'   => date('c'),
                ]);
                $roundMessages[] = $msg;
                $parsedVote = $this->voteParser->parse($content);
                if ($parsedVote) {
                    $this->voteRepo->createVote([
                        'id' => $this->uuid(),
                        'session_id' => $sessionId,
                        'round' => 1,
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
                    error_log('[QuickDecisionRunner] Final vote parse failed for agent ' . $agentId);
                }

            } catch (\Throwable $e) {
                $msg = $this->messageRepo->create([
                    'id'           => $this->uuid(),
                    'session_id'   => $sessionId,
                    'role'         => 'assistant',
                    'agent_id'     => $agentId,
                    'provider_id'  => null,
                    'model'        => null,
                    'round'        => 1,
                    'phase'        => 'analysis',
                    'mode_context' => 'quick-decision',
                    'message_type' => 'analysis',
                    'content'      => '[Error] ' . $e->getMessage(),
                    'created_at'   => date('c'),
                ]);
                $roundMessages[] = $msg;
            }
        }

        $synthesis = [];
        $verdict   = null;
        $automaticDecision = $this->voteAggregator->recompute($sessionId, 0.55);

        $synthAgent = $this->assembler->assemble('synthesizer');
        if ($synthAgent) {
            try {
                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $synthAgent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc
                );
                $routed  = $this->providerRouter->chat($messages, $synthAgent);
                $content = $routed['content'];

                $msg = $this->messageRepo->create([
                    'id'           => $this->uuid(),
                    'session_id'   => $sessionId,
                    'role'         => 'assistant',
                    'agent_id'     => 'synthesizer',
                    'provider_id'  => $routed['provider_id'] ?? null,
                    'model'        => $routed['model'] ?? null,
                    'round'        => 2,
                    'phase'        => 'synthesis',
                    'mode_context' => 'quick-decision',
                    'message_type' => 'synthesis',
                    'content'      => $content,
                    'created_at'   => date('c'),
                ]);
                $synthesis[] = $msg;

                $parsed = VerdictParser::parse($content);
                if ($parsed) {
                    $verdictData = array_merge($parsed, [
                        'id'         => $this->uuid(),
                        'session_id' => $sessionId,
                        'created_at' => date('c'),
                    ]);
                    $verdict = $this->verdictRepo->create($verdictData);
                }
            } catch (\Throwable $e) {
                $msg = $this->messageRepo->create([
                    'id'           => $this->uuid(),
                    'session_id'   => $sessionId,
                    'role'         => 'assistant',
                    'agent_id'     => 'synthesizer',
                    'provider_id'  => null,
                    'model'        => null,
                    'round'        => 2,
                    'phase'        => 'synthesis',
                    'mode_context' => 'quick-decision',
                    'message_type' => 'synthesis',
                    'content'      => '[Error] ' . $e->getMessage(),
                    'created_at'   => date('c'),
                ]);
                $synthesis[] = $msg;
            }
        }

        return [
            'round'     => $roundMessages,
            'synthesis' => $synthesis,
            'verdict'   => $verdict,
            'warning'   => $warning,
            'votes' => $this->voteRepo->findVotesBySession($sessionId),
            'automatic_decision' => $automaticDecision,
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

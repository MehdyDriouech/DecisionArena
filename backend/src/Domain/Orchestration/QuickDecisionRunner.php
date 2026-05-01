<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
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
    private DecisionReliabilityService $reliabilityService;
    private SocialDynamicsService $socialDynamics;
    private SocialPromptContextBuilder $socialPrompt;
    private EvidenceReportService $evidenceService;
    private RiskProfileAnalyzer $riskAnalyzer;

    public function __construct() {
        $this->assembler     = new AgentAssembler();
        $this->promptBuilder = new PromptBuilder();
        $this->providerRouter = new ProviderRouter();
        $this->messageRepo   = new MessageRepository();
        $this->verdictRepo   = new VerdictRepository();
        $this->voteRepo      = new VoteRepository();
        $this->voteParser    = new VoteParser();
        $this->voteAggregator = new VoteAggregator($this->voteRepo);
        $this->reliabilityService = new DecisionReliabilityService();
        $this->socialDynamics = new SocialDynamicsService();
        $this->socialPrompt   = new SocialPromptContextBuilder();
        $this->evidenceService = new EvidenceReportService();
        $this->riskAnalyzer    = new RiskProfileAnalyzer();
    }

    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        string $language          = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc        = null,
        float  $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD
    ): array {
        $warning = null;
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $this->voteRepo->clearSession($sessionId);
        $this->socialDynamics->clearSession($sessionId);

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
                $votesSnap   = $this->voteRepo->findVotesBySession($sessionId);
                $maj         = SocialDynamicsService::summarizeMajority($votesSnap, []);
                $socialBlock = null;
                if (count($roundMessages) >= 1) {
                    $socialBlock = $this->socialPrompt->buildUserBlock($sessionId, $agentId, $maj);
                }

                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $agent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc, $socialBlock
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
                    'round'                    => 1,
                    'phase'                    => 'analysis',
                    'mode_context'             => 'quick-decision',
                    'message_type'             => 'analysis',
                    'content'                  => $content,
                    'created_at'               => date('c'),
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

                $this->socialDynamics->ingestAgentResponse(
                    $sessionId,
                    1,
                    $agentId,
                    $content,
                    null,
                    $nonSynth,
                    $this->voteRepo->findVotesBySession($sessionId),
                    []
                );

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
                    'round'                    => 1,
                    'phase'                    => 'analysis',
                    'mode_context'             => 'quick-decision',
                    'message_type'             => 'analysis',
                    'content'                  => '[Error] ' . $e->getMessage(),
                    'created_at'               => date('c'),
                ]);
                $roundMessages[] = $msg;
            }
        }

        $synthesis = [];
        $verdict   = null;
        $automaticDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold);

        $synthAgent = $this->assembler->assemble('synthesizer');
        if ($synthAgent) {
            try {
                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $synthAgent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc, null
                );
                $routed  = $this->providerRouter->chat($messages, $synthAgent);
                $content = $routed['content'];

                $msg = $this->messageRepo->create([
                    'id'                       => $this->uuid(),
                    'session_id'               => $sessionId,
                    'role'                     => 'assistant',
                    'agent_id'                 => 'synthesizer',
                    'provider_id'              => $routed['provider_id'] ?? null,
                    'provider_name'            => $routed['provider_name'] ?? null,
                    'model'                    => $routed['model'] ?? null,
                    'requested_provider_id'    => $routed['requested_provider_id'] ?? null,
                    'requested_model'          => $routed['requested_model'] ?? null,
                    'provider_fallback_used'   => ($routed['fallback_used'] ?? false) ? 1 : 0,
                    'provider_fallback_reason' => $routed['fallback_reason'] ?? null,
                    'round'                    => 2,
                    'phase'                    => 'synthesis',
                    'mode_context'             => 'quick-decision',
                    'message_type'             => 'synthesis',
                    'content'                  => $content,
                    'created_at'               => date('c'),
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
                    'id'                       => $this->uuid(),
                    'session_id'               => $sessionId,
                    'role'                     => 'assistant',
                    'agent_id'                 => 'synthesizer',
                    'provider_id'              => null,
                    'provider_name'            => null,
                    'model'                    => null,
                    'requested_provider_id'    => null,
                    'requested_model'          => null,
                    'provider_fallback_used'   => 0,
                    'provider_fallback_reason' => null,
                    'round'                    => 2,
                    'phase'                    => 'synthesis',
                    'mode_context' => 'quick-decision',
                    'message_type' => 'synthesis',
                    'content'      => '[Error] ' . $e->getMessage(),
                    'created_at'   => date('c'),
                ]);
                $synthesis[] = $msg;
            }
        }

        $allSessionMessages = $this->messageRepo->findBySession($sessionId);
        $evidenceReport = null;
        try {
            $evidenceReport = $this->evidenceService->generateAndPersist(
                $sessionId, $allSessionMessages, $contextDoc
            );
        } catch (\Throwable $e) {
            error_log('[QuickDecisionRunner] Evidence generation failed: ' . $e->getMessage());
        }
        $riskProfile = null;
        try {
            $riskProfile = $this->riskAnalyzer->analyzeAndPersist(
                $sessionId, $objective, 'quick-decision',
                $allSessionMessages, $contextDoc, $decisionThreshold, $evidenceReport
            );
        } catch (\Throwable $e) {
            error_log('[QuickDecisionRunner] Risk analysis failed: ' . $e->getMessage());
        }
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $automaticDecision,
            $this->voteRepo->findVotesBySession($sessionId),
            [],
            [],
            $decisionThreshold,
            null,
            null,
            null,
            $evidenceReport,
            $riskProfile
        );

        return [
            'round'     => $roundMessages,
            'synthesis' => $synthesis,
            'verdict'   => $verdict,
            'warning'   => $warning,
            'votes' => $this->voteRepo->findVotesBySession($sessionId),
            'automatic_decision' => $automaticDecision,
            'raw_decision' => $reliability['raw_decision'],
            'adjusted_decision' => $reliability['adjusted_decision'],
            'context_quality' => $reliability['context_quality'],
            'reliability_cap' => $reliability['reliability_cap'],
            'false_consensus_risk' => $reliability['false_consensus_risk'],
            'false_consensus' => $reliability['false_consensus'],
            'reliability_warnings' => $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
            'evidence_report' => $evidenceReport,
            'risk_profile' => $riskProfile,
            'risk_threshold_info' => $reliability['risk_threshold_info'] ?? null,
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

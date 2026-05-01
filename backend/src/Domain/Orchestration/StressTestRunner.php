<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\DevilAdvocateTriggerPolicy;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\DecisionReliability\FalseConsensusDetector;
use Domain\Providers\ProviderRouter;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\VoteRepository;

class StressTestRunner {
    private AgentAssembler     $assembler;
    private PromptBuilder      $promptBuilder;
    private ProviderRouter     $providerRouter;
    private MessageRepository  $messageRepo;
    private VerdictRepository  $verdictRepo;
    private DebateMemoryService $debateMemory;
    private VoteRepository $voteRepo;
    private VoteParser $voteParser;
    private VoteAggregator $voteAggregator;
    private DevilAdvocateTriggerPolicy $daTriggerPolicy;
    private DecisionReliabilityService $reliabilityService;
    private SocialDynamicsService $socialDynamics;
    private SocialPromptContextBuilder $socialPrompt;
    private FalseConsensusDetector $falseConsensusDetector;
    private EvidenceReportService $evidenceService;
    private RiskProfileAnalyzer $riskAnalyzer;

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
        $this->daTriggerPolicy = new DevilAdvocateTriggerPolicy();
        $this->reliabilityService = new DecisionReliabilityService();
        $this->socialDynamics = new SocialDynamicsService();
        $this->socialPrompt   = new SocialPromptContextBuilder();
        $this->falseConsensusDetector = new FalseConsensusDetector();
        $this->evidenceService = new EvidenceReportService();
        $this->riskAnalyzer    = new RiskProfileAnalyzer();
    }

    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        int    $rounds = 2,
        string $language = 'en',
        bool   $forceDisagreement = true,
        ?array $contextDoc = null,
        bool   $devilAdvocateEnabled = false,
        float  $devilAdvocateThreshold = 0.65,
        array  $agentProviders = [],
        float  $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD
    ): array {
        $rounds = min(max($rounds, 1), RoundPolicy::MAX_ROUNDS);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);

        // Critic goes first if selected (risk-first posture)
        $nonSynthesizers = array_values(array_filter($selectedAgents, fn($a) => $a !== 'synthesizer'));
        $hasSynthesizer  = in_array('synthesizer', $selectedAgents, true);

        if (in_array('critic', $nonSynthesizers, true)) {
            $nonSynthesizers = array_merge(
                ['critic'],
                array_values(array_filter($nonSynthesizers, fn($a) => $a !== 'critic'))
            );
        }

        $allMessages           = [];
        $previousRoundMessages = [];
        $state                 = $this->debateMemory->loadState($sessionId);
        $this->voteRepo->clearSession($sessionId);
        $this->socialDynamics->clearSession($sessionId);
        $daPartialHistory      = [];
        $contextQuality        = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            null,
            [],
            [],
            [],
            $decisionThreshold
        )['context_quality'];

        $daPromptPath = __DIR__ . '/../../../storage/prompts/devil_advocate.md';
        $daPrompt     = file_exists($daPromptPath) ? file_get_contents($daPromptPath) : '';

        $forceStrongNext = false;

        for ($round = 1; $round <= $rounds; $round++) {
            $roundMessages  = [];
            $agentsForRound = $nonSynthesizers;

            // Synthesizer runs only on the final round
            if ($hasSynthesizer && $round === $rounds) {
                $agentsForRound[] = 'synthesizer';
            }

            foreach ($agentsForRound as $agentId) {
                $agent = $this->assembler->assemble($agentId);
                if (!$agent) continue;

                $assignedTarget = ($round > 1 && $agentId !== 'synthesizer')
                    ? $this->computeAssignedTarget($agentsForRound, $agentId, $round)
                    : null;

                $votesSnap     = $this->voteRepo->findVotesBySession($sessionId);
                $maj           = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
                $socialBlock   = null;
                if ($round > 1 && $rounds > 1 && $agentId !== 'synthesizer') {
                    $socialBlock = $this->socialPrompt->buildUserBlock($sessionId, $agentId, $maj);
                }

                try {
                    $messages = $this->promptBuilder->buildStressTestMessages(
                        $agent,
                        $objective,
                        $previousRoundMessages,
                        $round,
                        $rounds,
                        $language,
                        $forceDisagreement,
                        $contextDoc,
                        $this->debateMemory->buildPromptContext($state),
                        $assignedTarget,
                        $socialBlock,
                        $forceStrongNext && $agentId !== 'synthesizer'
                    );
                    $routed  = $this->providerRouter->chat($messages, $agent, null, null, $agentProviders[$agentId] ?? null);
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
                        'round'                    => $round,
                        'phase'                    => $agentId === 'synthesizer' ? 'stress-synthesis' : 'stress-analysis',
                        'mode_context'             => 'stress-test',
                        'message_type'             => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'content'                  => $content,
                        'created_at'               => date('c'),
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
                    $this->socialDynamics->ingestAgentResponse(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        array_values(array_filter($selectedAgents, fn($id) => $id !== 'devil_advocate')),
                        $this->voteRepo->findVotesBySession($sessionId),
                        $state['positions'] ?? []
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
                            error_log('[StressTestRunner] Final vote parse failed for agent ' . $agentId);
                        }
                    }

                    // Parse verdict from synthesizer on the final round
                    if ($agentId === 'synthesizer' && $round === $rounds) {
                        $parsed = VerdictParser::parse($content);
                        if ($parsed) {
                            $this->verdictRepo->create(array_merge($parsed, [
                                'id'         => $this->uuid(),
                                'session_id' => $sessionId,
                                'created_at' => date('c'),
                            ]));
                        }
                    }

                } catch (\Throwable $e) {
                    $msg = $this->messageRepo->create([
                        'id'                       => $this->uuid(),
                        'session_id'               => $sessionId,
                        'role'                     => 'assistant',
                        'agent_id'                 => $agentId,
                        'provider_id'              => null,
                        'provider_name'            => null,
                        'model'                    => null,
                        'requested_provider_id'    => isset($agentProviders[$agentId]) ? ($agentProviders[$agentId]['provider_id'] ?? null) : null,
                        'requested_model'          => isset($agentProviders[$agentId]) ? ($agentProviders[$agentId]['model'] ?? null) : null,
                        'provider_fallback_used'   => 0,
                        'provider_fallback_reason' => null,
                        'round'                    => $round,
                        'phase'                    => $agentId === 'synthesizer' ? 'stress-synthesis' : 'stress-analysis',
                        'mode_context'             => 'stress-test',
                        'message_type'             => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'content'                  => '[Error] ' . $e->getMessage(),
                        'created_at'               => date('c'),
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
                if ($this->daTriggerPolicy->shouldTrigger(
                    $round,
                    $partialConfidence,
                    $devilAdvocateThreshold,
                    [
                        'partial_confidence_history' => $daPartialHistory,
                        'context_quality' => $contextQuality,
                    ]
                )) {
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
                            'id'                       => $this->uuid(),
                            'session_id'               => $sessionId,
                            'role'                     => 'assistant',
                            'agent_id'                 => 'devil_advocate',
                            'provider_id'              => $daRouted['provider_id'] ?? null,
                            'provider_name'            => $daRouted['provider_name'] ?? null,
                            'model'                    => $daRouted['model'] ?? null,
                            'requested_provider_id'    => null,
                            'requested_model'          => null,
                            'provider_fallback_used'   => 0,
                            'provider_fallback_reason' => null,
                            'round'                    => $round,
                            'phase'                    => 'devil-advocate',
                            'mode_context'             => 'stress-test',
                            'message_type'             => 'devil_advocate',
                            'content'                  => $daContent,
                            'created_at'               => date('c'),
                        ]);
                        $roundMessages[] = $daMsg;
                    } catch (\Throwable $e) {
                        error_log('[StressTestRunner] Devil advocate failed: ' . $e->getMessage());
                    }
                }
                $daPartialHistory[] = $partialConfidence;
            }

            if ($round < $rounds) {
                $votesEnd = $this->voteRepo->findVotesBySession($sessionId);
                $forceStrongNext = $this->falseConsensusDetector->shouldForceChallengeNextRound(
                    $contextQuality,
                    $state['positions'] ?? [],
                    $state['edges'] ?? [],
                    $votesEnd
                );
            } else {
                $forceStrongNext = false;
            }

            $previousRoundMessages = $roundMessages;
            $allMessages[$round]   = $roundMessages;
        }

        $automaticDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold);
        $allSessionMessages = $this->messageRepo->findBySession($sessionId);
        $evidenceReport = null;
        try {
            $evidenceReport = $this->evidenceService->generateAndPersist(
                $sessionId, $allSessionMessages, $contextDoc
            );
        } catch (\Throwable $e) {
            error_log('[StressTestRunner] Evidence generation failed: ' . $e->getMessage());
        }
        $riskProfile = null;
        try {
            $riskProfile = $this->riskAnalyzer->analyzeAndPersist(
                $sessionId, $objective, 'stress-test',
                $allSessionMessages, $contextDoc, $decisionThreshold, $evidenceReport
            );
        } catch (\Throwable $e) {
            error_log('[StressTestRunner] Risk analysis failed: ' . $e->getMessage());
        }
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $automaticDecision,
            $this->voteRepo->findVotesBySession($sessionId),
            $state['positions'],
            $state['edges'],
            $decisionThreshold,
            null,
            null,
            null,
            $evidenceReport,
            $riskProfile
        );
        return [
            'rounds' => $allMessages,
            'arguments' => $state['arguments'],
            'positions' => $state['positions'],
            'interaction_edges' => $state['edges'],
            'weighted_analysis' => $this->debateMemory->buildWeightedAnalysis($state),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($state),
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

    private function resolveTargetAgentId(string $content, array $previousRoundMessages, string $agentId, ?string $assignedTarget = null): ?string {
        if (!empty($previousRoundMessages)) {
            if (preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
                $parsed = strtolower(trim($m[1]));
                $valid  = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array($parsed, $valid, true) && $parsed !== strtolower($agentId)) {
                    return $parsed;
                }
            }
            if ($assignedTarget !== null) {
                $valid = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array(strtolower($assignedTarget), $valid, true)) {
                    return $assignedTarget;
                }
            }
        }
        return null;
    }

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

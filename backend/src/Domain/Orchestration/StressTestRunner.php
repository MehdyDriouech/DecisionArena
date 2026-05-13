<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\DevilAdvocateTriggerPolicy;
use Domain\DecisionReliability\DecisionQualityScoreService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Evidence\EvidencePromptBuilder;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\DecisionReliability\FalseConsensusDetector;
use Domain\Providers\ProviderRouter;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Infrastructure\Logging\Logger;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RunStatusRepository;
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
    private DecisionQualityScoreService $qualityScoreService;
    private DecisionSummaryService $summaryService;
    private PlaybookRuntime $playbookRuntime;
    private Logger $logger;
    private RunStatusRepository $runStatusRepo;

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
        $this->qualityScoreService = new DecisionQualityScoreService();
        $this->summaryService      = new DecisionSummaryService();
        $this->playbookRuntime     = new PlaybookRuntime();
        $this->logger              = new Logger();
        $this->runStatusRepo       = new RunStatusRepository();
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
        float  $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        ?string $decisionDynamicsPreset = null,
        ?string $strategicContextId = null
    ): array {
        $rounds = min(max($rounds, 1), RoundPolicy::MAX_ROUNDS);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $dynamicsPreset = \Domain\Agents\DecisionDynamicsPreset::normalizeId($decisionDynamicsPreset);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('stress-test', [], $objective);
        $runtimeTraces = [];

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
            $this->appendRuntimeEvent($sessionId, [
                'level' => 'info',
                'phase' => 'round_started',
                'round' => $round,
                'label' => 'Round ' . $round . ' demarre',
            ], [
                'current_round' => $round,
                'total_rounds' => $rounds,
                'current_phase' => 'round_started',
                'current_phase_label' => 'Round en cours',
                'current_step' => 'round_start',
                'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.0),
            ]);

            // Synthesizer runs only on the final round
            if ($hasSynthesizer && $round === $rounds) {
                $agentsForRound[] = 'synthesizer';
            }

            foreach ($agentsForRound as $agentId) {
                $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
                if (!$agent) continue;
                $phase = $agentId === 'synthesizer' ? 'stress-synthesis' : 'stress-analysis';
                $this->appendRuntimeEvent($sessionId, [
                    'level' => 'info',
                    'phase' => $phase,
                    'round' => $round,
                    'agent_id' => $agentId,
                    'label' => $phase . ' · ' . $agentId . ' · appel LLM demarre',
                ], [
                    'current_round' => $round,
                    'total_rounds' => $rounds,
                    'current_phase' => $phase,
                    'current_phase_label' => $phase,
                    'current_agent_id' => $agentId,
                    'current_step' => 'llm_call',
                    'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.2),
                ]);

                $assignedTarget = ($round > 1 && $agentId !== 'synthesizer')
                    ? $this->computeAssignedTarget($agentsForRound, $agentId, $round)
                    : null;

                $votesSnap     = $this->voteRepo->findVotesBySession($sessionId);
                $maj           = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
                $socialBlock   = null;
                if ($round > 1 && $rounds > 1 && $agentId !== 'synthesizer') {
                    $socialBlock = $this->socialPrompt->buildUserBlock(
                        $sessionId,
                        $agentId,
                        $maj,
                        $strategicContextId,
                        false
                    );
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
                        $forceStrongNext && $agentId !== 'synthesizer',
                        $sessionId,
                        null,
                        $strategicContextId
                    );
                    $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                        $messages,
                        [
                            'session_id' => $sessionId,
                            'strategic_context_id' => $strategicContextId,
                            'round' => $round,
                            'agent_id' => $agentId,
                            'mode' => 'stress-test',
                        ],
                        'stress_test_user_payload',
                        'orchestration',
                        'stress_test_runtime_user_payload',
                        ['synthesizer' => ($agentId === 'synthesizer')]
                    );
                    $messages = $governed['messages'];
                    $promptMetaJson = $governed['meta_json'];
                    if (is_array($governed['trace'] ?? null)) {
                        $runtimeTraces[] = $governed['trace'];
                    }
                    $this->logger->logPromptBuild('prompt_built_stress_test', [
                        'agent_id' => $agent->id,
                        'metadata' => [
                            'mode' => 'stress-test',
                            'round' => $round,
                            'total_rounds' => $rounds,
                            'synthesizer' => ($agent->id === 'synthesizer'),
                            'message_count' => count($messages),
                            'character_count' => $this->countMessageChars($messages),
                            'context_doc_injected' => !empty($contextDoc['content']),
                            'memory_injected' => !empty(($state['argument_memory_summary'] ?? null)),
                            'force_disagreement' => (bool)$forceDisagreement,
                            'playbook_id' => $playbookId,
                            'session_id' => $sessionId,
                        ],
                    ]);
                    $routed  = $this->providerRouter->chat(
                        $messages,
                        $agent,
                        null,
                        null,
                        $this->resolveAgentOverride($agentProviders, (string)$agentId)
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
                        'round'                    => $round,
                        'phase'                    => $agentId === 'synthesizer' ? 'stress-synthesis' : 'stress-analysis',
                        'mode_context'             => 'stress-test',
                        'message_type'             => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'meta_json'                => $promptMetaJson,
                        'content'                  => $content,
                        'created_at'               => date('c'),
                    ]);
                    $roundMessages[] = $msg;
                    $this->appendRuntimeEvent($sessionId, [
                        'level' => 'info',
                        'phase' => $phase,
                        'round' => $round,
                        'agent_id' => $agentId,
                        'label' => $phase . ' · ' . $agentId . ' · reponse recue',
                    ], [
                        'current_round' => $round,
                        'total_rounds' => $rounds,
                        'current_phase' => $phase,
                        'current_phase_label' => $phase,
                        'current_agent_id' => $agentId,
                        'current_step' => 'response_received',
                        'percent' => RunnerProgressPercent::roundRunPercent(
                            $round,
                            $rounds,
                            $agentId === 'synthesizer' ? 0.95 : 0.6
                        ),
                    ]);
                    $targetResolution = $this->resolveTargetAgent($content, $previousRoundMessages, $agentId, $assignedTarget);
                    $targetAgentId = $targetResolution['target_agent_id'];
                    $this->debateMemory->processMessage(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        $state,
                        $targetResolution['edge_source']
                    );
                    $this->socialDynamics->ingestAgentResponse(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        array_values(array_filter($selectedAgents, fn($id) => $id !== 'devil_advocate')),
                        $this->voteRepo->findVotesBySession($sessionId),
                        $state['positions'] ?? [],
                        $strategicContextId
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
                        $parsed = VerdictParser::parse($content, $playbookId);
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
                    $this->appendRuntimeEvent($sessionId, [
                        'level' => 'error',
                        'phase' => $phase,
                        'round' => $round,
                        'agent_id' => $agentId,
                        'label' => $phase . ' · ' . $agentId . ' · erreur',
                    ], [
                        'current_round' => $round,
                        'total_rounds' => $rounds,
                        'current_phase' => $phase,
                        'current_phase_label' => $phase,
                        'current_agent_id' => $agentId,
                        'current_step' => 'failed',
                    ], 'running', (string)$e->getMessage());
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
                    $daEvidence = null;
                    try {
                        $daEvidence = $this->evidenceService->generateAndPersist(
                            $sessionId,
                            $this->messageRepo->findBySession($sessionId),
                            $contextDoc
                        );
                    } catch (\Throwable) {
                    }
                    $daUser = (new EvidencePromptBuilder())->buildDevilAdvocateUserMessage($context, $daEvidence, $contextDoc);
                    $daMessages = [
                        ['role' => 'system', 'content' => $daPrompt],
                        ['role' => 'user', 'content' => $daUser],
                    ];
                    try {
                        $governedDa = CognitiveRuntimeGovernance::tracePromptPayload(
                            $daMessages,
                            [
                                'session_id' => $sessionId,
                                'strategic_context_id' => $strategicContextId,
                                'round' => $round,
                                'agent_id' => 'devil_advocate',
                                'mode' => 'stress-test',
                            ],
                            'stress_test_user_payload',
                            'orchestration',
                            'stress_test_devil_advocate_payload'
                        );
                        $daMessages = $governedDa['messages'];
                        $daMetaJson = $governedDa['meta_json'];
                        if (is_array($governedDa['trace'] ?? null)) {
                            $runtimeTraces[] = $governedDa['trace'];
                        }
                        $daRouted  = $this->providerRouter->chat($daMessages, null, null, null);
                        $daContent = $daRouted['content'];
                        $daMsg     = $this->messageRepo->create([
                            'id'                       => $this->uuid(),
                            'session_id'               => $sessionId,
                            'role'                     => 'assistant',
                            'agent_id'                 => 'devil_advocate',
                            'provider_id'              => $daRouted['provider_id'] ?? null,
                            'provider_name'            => $daRouted['provider_name'] ?? null,
                            'model'                    => $daRouted['model'] ?? null,
                            'requested_provider_id'    => $daRouted['requested_provider_id'] ?? null,
                            'requested_model'          => $daRouted['requested_model'] ?? null,
                            'provider_fallback_used'   => ($daRouted['fallback_used'] ?? false) ? 1 : 0,
                            'provider_fallback_reason' => $daRouted['fallback_reason'] ?? null,
                            'routing_source'           => $daRouted['routing_source'] ?? null,
                            'resolved_provider_id'     => $daRouted['resolved_provider_id'] ?? null,
                            'resolved_provider_label'  => $daRouted['resolved_provider_label'] ?? null,
                            'resolved_model'           => $daRouted['resolved_model'] ?? null,
                            'session_override_present' => $daRouted['session_override_present'] ?? null,
                            'persona_default_provider_ignored' => $daRouted['persona_default_provider_ignored'] ?? null,
                            'fallback_from_provider_id' => $daRouted['fallback_from_provider_id'] ?? null,
                            'fallback_from_model'      => $daRouted['fallback_from_model'] ?? null,
                            'round'                    => $round,
                            'phase'                    => 'devil-advocate',
                            'mode_context'             => 'stress-test',
                            'message_type'             => 'devil_advocate',
                            'meta_json'                => $daMetaJson,
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

        $automaticDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold, $dynamicsPreset);
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

        $fc = $reliability['false_consensus'] ?? [];
        $fc = is_array($fc) ? $fc : [];
        $debateProxy = (float)(($fc['diversity_score'] ?? 0.5) * 100);
        $qualityScore = $this->qualityScoreService->compute(
            $reliability['context_quality'] ?? [],
            $debateProxy,
            $evidenceReport,
            $riskProfile,
            $fc
        );
        $synthOut = '';
        foreach ($allMessages[$rounds] ?? [] as $m) {
            if (($m['agent_id'] ?? '') === 'synthesizer') {
                $synthOut = (string)($m['content'] ?? '');
                break;
            }
        }
        $verdictRow = $this->verdictRepo->findBySession($sessionId);
        $canonicalSynthesis = CanonicalSynthesisExtractor::extract($synthOut, $playbookId);
        $playbookDiagnostics = $this->playbookRuntime->extractDiagnostics($synthOut, $playbookId);

        // Minimal reliability warning when a majority of agents errored.
        $guardrails = [];
        try {
            $agentIds = array_values(array_filter($selectedAgents, fn($id) => $id !== 'devil_advocate'));
            $agentIds = array_values(array_unique(array_map('strval', $agentIds)));
            $errorAgents = [];
            foreach ($allMessages as $roundMsgs) {
                foreach (($roundMsgs ?? []) as $m) {
                    $aid = (string)($m['agent_id'] ?? '');
                    $content = (string)($m['content'] ?? '');
                    if ($aid !== '' && str_starts_with($content, '[Error]')) {
                        $errorAgents[$aid] = true;
                    }
                }
            }
            $totalAgents = count($agentIds);
            $errorCount = count($errorAgents);
            if ($totalAgents > 0 && $errorCount > ($totalAgents / 2)) {
                $warn = 'Majority of agents failed during execution; decision reliability is degraded.';
                $guardrails['warnings'] = [$warn];
            }
        } catch (\Throwable) {
        }
        if (!empty($playbookDiagnostics['warnings'])) {
            $existing = isset($guardrails['warnings']) && is_array($guardrails['warnings']) ? $guardrails['warnings'] : [];
            $guardrails['warnings'] = array_values(array_unique(array_merge($existing, $playbookDiagnostics['warnings'])));
        }
        $decisionOutcome = DecisionOutcomeProjector::fromCanonical($canonicalSynthesis, [
            'playbook_runtime' => $playbookDiagnostics,
            'risk_profile' => $riskProfile,
            'guardrails' => $guardrails,
            'decision_label' => $reliability['adjusted_decision']['decision_label'] ?? null,
            'decision_status' => $reliability['adjusted_decision']['decision_status'] ?? null,
            'outcome' => $reliability['adjusted_decision']['final_outcome'] ?? null,
            'next_steps' => $reliability['decision_reliability_summary']['recommended_action'] ?? null,
        ]);

        $decisionBrief = $this->summaryService->buildDecisionBrief(
            array_merge($reliability, [
                'synthesizer_output'     => $synthOut,
                'guardrails'             => $guardrails,
                'decision_quality_score' => $qualityScore,
                'risk_profile'           => $riskProfile,
                'evidence_report'        => $evidenceReport,
                'false_consensus'        => $fc,
                'verdict'                => $verdictRow ?: null,
                'session_mode_hint'      => 'stress',
                'canonical_synthesis'    => $canonicalSynthesis,
                'playbook_runtime'        => $playbookDiagnostics,
                'decision_outcome'        => $decisionOutcome,
            ])
        );

        return StructuredRunResult::augment(array_merge([
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
            'memory_summary' => $reliability['memory_summary'] ?? null,
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
            'decision_quality_score' => $qualityScore,
            'decision_brief' => $decisionBrief,
            'canonical_synthesis' => $canonicalSynthesis,
            'decision_outcome' => $decisionOutcome,
            'playbook_runtime' => $playbookDiagnostics,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'stress-test')));
    }

    /**
     * @return array{target_agent_id:?string,edge_source:string}
     */
    private function resolveTargetAgent(string $content, array $previousRoundMessages, string $agentId, ?string $assignedTarget = null): array {
        if (!empty($previousRoundMessages)) {
            if (preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
                $parsed = strtolower(trim($m[1]));
                $valid  = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array($parsed, $valid, true) && $parsed !== strtolower($agentId)) {
                    return ['target_agent_id' => $parsed, 'edge_source' => 'explicit_target'];
                }
            }
            if ($assignedTarget !== null) {
                $valid = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array(strtolower($assignedTarget), $valid, true)) {
                    return ['target_agent_id' => $assignedTarget, 'edge_source' => 'assigned_fallback'];
                }
            }
        }
        return ['target_agent_id' => null, 'edge_source' => 'unknown'];
    }

    private function resolveTargetAgentId(string $content, array $previousRoundMessages, string $agentId, ?string $assignedTarget = null): ?string {
        return $this->resolveTargetAgent($content, $previousRoundMessages, $agentId, $assignedTarget)['target_agent_id'];
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

    private function countMessageChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen((string)($message['content'] ?? ''), 'UTF-8');
        }
        return $chars;
    }

    private function appendRuntimeEvent(
        string $sessionId,
        array $event,
        array $progressPatch = [],
        ?string $status = null,
        ?string $lastError = null
    ): void {
        try {
            $this->runStatusRepo->appendEvent($sessionId, $event, $progressPatch, $status, $lastError);
        } catch (\Throwable) {
        }
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

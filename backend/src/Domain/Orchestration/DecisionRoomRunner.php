<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\DevilAdvocateTriggerPolicy;
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
    private DevilAdvocateTriggerPolicy $daTriggerPolicy;
    private DecisionReliabilityService $reliabilityService;
    private SocialDynamicsService $socialDynamics;
    private SocialPromptContextBuilder $socialPrompt;
    private FalseConsensusDetector $falseConsensusDetector;
    private EvidenceReportService $evidenceService;
    private RiskProfileAnalyzer $riskAnalyzer;
    private \Domain\DecisionReliability\DecisionGuardrailService $guardrailService;
    private \Domain\DecisionReliability\DecisionQualityScoreService $qualityScoreService;
    private DecisionSummaryService $summaryService;
    private PlaybookRuntime $playbookRuntime;
    private Logger $logger;

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
        $this->guardrailService = new \Domain\DecisionReliability\DecisionGuardrailService();
        $this->qualityScoreService = new \Domain\DecisionReliability\DecisionQualityScoreService();
        $this->summaryService = new DecisionSummaryService();
        $this->playbookRuntime = new PlaybookRuntime();
        $this->logger = new Logger();
        // Ensure run_status column exists for auto-retry progress signaling
        try {
            $pdo = \Infrastructure\Persistence\Database::getConnection();
            $pdo->exec("ALTER TABLE sessions ADD COLUMN run_status TEXT DEFAULT NULL");
        } catch (\Exception $e) {
            // Column already exists — safe to ignore
        }
    }

    private function writeRunStatus(string $sessionId, array $status): void
    {
        try {
            $pdo  = \Infrastructure\Persistence\Database::getConnection();
            $stmt = $pdo->prepare("UPDATE sessions SET run_status = :s WHERE id = :id");
            $stmt->execute([':s' => json_encode($status), ':id' => $sessionId]);
        } catch (\Exception $e) {
            // Non-fatal — progress signaling is best-effort
        }
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
        array  $agentProviders = [],
        float  $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        array  $sessionOptions = []
    ): array {
        $rounds              = min(max($rounds, 1), RoundPolicy::MAX_ROUNDS);
        $decisionThreshold   = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $dynamicsPreset      = \Domain\Agents\DecisionDynamicsPreset::normalizeId($sessionOptions['decision_dynamics_preset'] ?? null);
        $socialStrategicCtx  = isset($sessionOptions['strategic_context_id']) && (string)$sessionOptions['strategic_context_id'] !== ''
            ? (string)$sessionOptions['strategic_context_id'] : null;
        $playbookId          = $this->playbookRuntime->resolvePlaybookId('decision-room', $sessionOptions, $objective);
        $allMessages         = [];
        $previousRoundMessages = [];
        $state               = $this->debateMemory->loadState($sessionId);
        $this->voteRepo->clearSession($sessionId);
        $this->socialDynamics->clearSession($sessionId);
        $daPartialHistory    = [];
        $contextQuality      = $this->reliabilityService->buildEnvelope(
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
                $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
                if (!$agent) continue;

                $assignedTarget = ($round > 1 && $agentId !== 'synthesizer')
                    ? $this->computeAssignedTarget($agentsForRound, $agentId, $round)
                    : null;

                $votesSnap      = $this->voteRepo->findVotesBySession($sessionId);
                $maj            = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
                $socialDynamicsBlock = null;
                if ($round > 1 && $rounds > 1 && $agentId !== 'synthesizer') {
                    $socialDynamicsBlock = $this->socialPrompt->buildUserBlock(
                        $sessionId,
                        $agentId,
                        $maj,
                        $socialStrategicCtx,
                        false
                    );
                }

                try {
                    PromptInjectionTraceCollector::begin([
                        'session_id' => $sessionId,
                        'strategic_context_id' => $socialStrategicCtx,
                        'round' => $round,
                        'agent_id' => $agentId,
                        'mode' => 'decision-room',
                    ]);
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
                        $assignedTarget,
                        $socialDynamicsBlock,
                        $forceStrongNext && $agentId !== 'synthesizer',
                        $sessionId,
                        null,
                        $sessionOptions['session_variant'] ?? '',
                        $socialStrategicCtx
                    );
                    $this->logger->logPromptBuild('prompt_built_decision_room', [
                        'agent_id' => $agent->id,
                        'metadata' => [
                            'mode' => 'decision-room',
                            'round' => $round,
                            'total_rounds' => $rounds,
                            'message_count' => count($messages),
                            'character_count' => $this->countMessageChars($messages),
                            'context_doc_injected' => !empty($contextDoc['content']),
                            'memory_injected' => !empty(($state['argument_memory_summary'] ?? null)),
                            'force_disagreement' => (bool)$forceDisagreement,
                            'playbook_id' => $playbookId,
                            'session_id' => $sessionId,
                        ],
                    ]);

                    // Inject synthesizer constraints on the final round
                    if ($agentId === 'synthesizer' && $round === $rounds) {
                        try {
                            $preEvidence = null;
                            try {
                                $preEvidence = $this->evidenceService->generateAndPersist(
                                    $sessionId,
                                    $this->messageRepo->findBySession($sessionId),
                                    $contextDoc
                                );
                            } catch (\Throwable) {
                            }
                            $preDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold, $dynamicsPreset);
                            $preEnvelope = $this->reliabilityService->buildEnvelope(
                                $objective, $contextDoc, $preDecision,
                                $this->voteRepo->findVotesBySession($sessionId),
                                $state['positions'], $state['edges'],
                                $decisionThreshold, null, null, null, null, null,
                                $preEvidence,
                                null
                            );
                            $preFcData       = $preEnvelope['false_consensus'] ?? [];
                            $preDebateQuality= (float)(($preFcData['diversity_score'] ?? 0.5) * 100);
                            $preGuardrails   = $this->guardrailService->evaluate(
                                rawDecision:       $preEnvelope['raw_decision'] ?? [],
                                adjustedDecision:  $preEnvelope['adjusted_decision'] ?? [],
                                contextQuality:    $preEnvelope['context_quality'] ?? [],
                                falseConsensus:    $preFcData,
                                debateQualityScore:$preDebateQuality,
                                evidenceReport:    null,
                                riskProfile:       null,
                                mode:              'decision-room',
                                sessionOptions:    []
                            );
                            $constraintBlock   = $this->promptBuilder->buildSynthesizerConstraintBlock(
                                array_merge($preEnvelope, [
                                    'debate_quality_score' => $preDebateQuality,
                                    'guardrails'           => $preGuardrails,
                                    'evidence_report'      => $preEvidence,
                                ])
                            );
                            $formatInstruction = $this->promptBuilder->buildSynthesizerOutputFormatInstruction($playbookId, $language);
                            if (($sessionOptions['session_variant'] ?? '') === 'premortem') {
                                $formatInstruction .= $this->promptBuilder->buildPremortemSynthesizerJsonAddendum();
                            }
                            foreach ($messages as &$msg) {
                                if ($msg['role'] === 'user') {
                                    $bConstraints = CognitiveBudgetEngine::applySegment('synthesizer_constraints', $constraintBlock);
                                    $bFormat = CognitiveBudgetEngine::applySegment('synthesizer_output_format', $formatInstruction);
                                    $msg['content'] .= $bConstraints['content'] . $bFormat['content'];
                                    if (PromptInjectionTraceCollector::active()) {
                                        $beC = [
                                            'computed_by' => 'DecisionRoomRunner::synthesizer_injection',
                                            'refused_chars' => $bConstraints['refused_chars'],
                                            'pruning_decision' => $bConstraints['pruning_decision'],
                                            'fallback_decision' => $bConstraints['fallback_policy'],
                                            'score_breakdown' => $bConstraints['score_breakdown'],
                                            'budget_layer' => $bConstraints['budget_layer'],
                                        ];
                                        $beF = [
                                            'computed_by' => 'DecisionRoomRunner::synthesizer_injection',
                                            'refused_chars' => $bFormat['refused_chars'],
                                            'pruning_decision' => $bFormat['pruning_decision'],
                                            'fallback_decision' => $bFormat['fallback_policy'],
                                            'score_breakdown' => $bFormat['score_breakdown'],
                                            'budget_layer' => $bFormat['budget_layer'],
                                            'playbook_id' => $playbookId,
                                        ];
                                        PromptInjectionTraceCollector::addStep(
                                            'synthesizer_constraints',
                                            'reliability_envelope',
                                            mb_strlen($bConstraints['content'], 'UTF-8'),
                                            'pre_model_aggregate_votes_guardrails_evidence',
                                            null,
                                            $beC
                                        );
                                        PromptInjectionTraceCollector::addStep(
                                            'synthesizer_output_format',
                                            'instruction',
                                            mb_strlen($bFormat['content'], 'UTF-8'),
                                            'playbook_output_schema_and_optional_premortem_addendum',
                                            null,
                                            $beF
                                        );
                                    }
                                    break;
                                }
                            }
                            unset($msg);
                        } catch (\Throwable $e) {
                            error_log('[DecisionRoomRunner] Synthesizer constraint injection failed: ' . $e->getMessage());
                        }
                    }

                    $promptTrace = PromptInjectionTraceCollector::finish();
                    $promptMetaJson = $promptTrace !== null
                        ? json_encode(['prompt_injection_trace' => $promptTrace], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                        : null;

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
                        'phase'                    => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'mode_context'             => 'decision-room',
                        'message_type'             => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'meta_json'                => $promptMetaJson,
                        'content'                  => $content,
                        'created_at'               => date('c'),
                    ]);
                    $roundMessages[] = $msg;
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
                        $socialStrategicCtx
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
                        $parsed = VerdictParser::parse($content, $playbookId);
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
                    PromptInjectionTraceCollector::cancel();
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
                        'phase'                    => $agentId === 'synthesizer' ? 'synthesis' : 'analysis',
                        'mode_context'             => 'decision-room',
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
                    $daEvidence = null;
                    try {
                        $daEvidence = $this->evidenceService->generateAndPersist(
                            $sessionId,
                            $this->messageRepo->findBySession($sessionId),
                            $contextDoc
                        );
                    } catch (\Throwable) {
                    }
                    $daUser = (new EvidencePromptBuilder())->buildDevilAdvocateUserMessage(
                        $context,
                        $daEvidence,
                        $contextDoc
                    );
                    $daMessages = [
                        ['role' => 'system', 'content' => $daPrompt],
                        ['role' => 'user', 'content' => $daUser],
                    ];
                    try {
                        $governedDa = CognitiveRuntimeGovernance::tracePromptPayload(
                            $daMessages,
                            [
                                'session_id' => $sessionId,
                                'strategic_context_id' => $socialStrategicCtx,
                                'round' => $round,
                                'agent_id' => 'devil_advocate',
                                'mode' => 'decision-room',
                            ],
                            'chat_user_payload',
                            'orchestration',
                            'decision_room_devil_advocate_payload'
                        );
                        $daMessages = $governedDa['messages'];
                        $daMetaJson = $governedDa['meta_json'];
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
                            'requested_provider_id'    => null,
                            'requested_model'          => null,
                            'provider_fallback_used'   => 0,
                            'provider_fallback_reason' => null,
                            'round'                    => $round,
                            'phase'                    => 'devil-advocate',
                            'mode_context'             => 'decision-room',
                            'message_type'             => 'devil_advocate',
                            'meta_json'                => $daMetaJson,
                            'content'                  => $daContent,
                            'created_at'               => date('c'),
                        ]);
                        $roundMessages[] = $daMsg;
                    } catch (\Throwable $e) {
                        error_log('[DecisionRoomRunner] Devil advocate failed: ' . $e->getMessage());
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
            error_log('[DecisionRoomRunner] Evidence generation failed: ' . $e->getMessage());
        }
        $riskProfile = null;
        try {
            $riskProfile = $this->riskAnalyzer->analyzeAndPersist(
                $sessionId, $objective, 'decision-room',
                $allSessionMessages, $contextDoc, $decisionThreshold, $evidenceReport
            );
        } catch (\Throwable $e) {
            error_log('[DecisionRoomRunner] Risk analysis failed: ' . $e->getMessage());
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

        // Debate quality proxy for non-jury modes (derived from diversity score)
        $falseConsensusData = $reliability['false_consensus'] ?? [];
        $debateQualityProxy = (float)(($falseConsensusData['diversity_score'] ?? 0.5) * 100);

        $guardrails = $this->guardrailService->evaluate(
            rawDecision:       $reliability['raw_decision'] ?? [],
            adjustedDecision:  $reliability['adjusted_decision'] ?? [],
            contextQuality:    $reliability['context_quality'] ?? [],
            falseConsensus:    $falseConsensusData,
            debateQualityScore:$debateQualityProxy,
            evidenceReport:    $evidenceReport,
            riskProfile:       $riskProfile,
            mode:              'decision-room',
            sessionOptions:    $sessionOptions
        );

        // Apply final_outcome_override if guardrails mandate it
        if ($guardrails['final_outcome_override'] !== null) {
            $reliability['adjusted_decision']['final_outcome'] = $guardrails['final_outcome_override'];
        }

        $autoRetryResult = ['triggered' => false];
        $retryRuntimeTraces = [];

        if (($guardrails['should_auto_retry'] ?? false) === true) {
            $initialScore = $debateQualityProxy;
            $this->writeRunStatus($sessionId, [
                'status'        => 'auto_retry',
                'reason'        => 'weak_parallel_debate',
                'initial_score' => $initialScore,
            ]);

            $retryInstruction = $this->promptBuilder->buildAutoRetryAdversarialPrompt($initialScore);

            $existingMessages = $this->messageRepo->findBySession($sessionId);
            $historyText = implode("\n\n", array_map(
                fn($m) => "[{$m['agent_id']}]: {$m['content']}",
                $existingMessages
            ));
            $userMsg = $retryInstruction . "\n\nPrevious debate:\n\n" . $historyText;

            foreach ($selectedAgents as $agentId) {
                if ($agentId === 'synthesizer') continue;
                $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
                if (!$agent) continue;
                try {
                    $retryMessages = [
                        ['role' => 'system', 'content' => $agent->systemPrompt ?? ''],
                        ['role' => 'user', 'content' => $userMsg],
                    ];
                    $governedRetry = CognitiveRuntimeGovernance::tracePromptPayload(
                        $retryMessages,
                        [
                            'session_id' => $sessionId,
                            'strategic_context_id' => $socialStrategicCtx,
                            'round' => $rounds + 1,
                            'agent_id' => $agentId,
                            'mode' => 'decision-room',
                        ],
                        'chat_user_payload',
                        'orchestration',
                        'decision_room_retry_round_payload',
                        ['phase' => 'retry-round']
                    );
                    $retryMessages = $governedRetry['messages'];
                    $retryMetaJson = $governedRetry['meta_json'];
                    if (is_array($governedRetry['trace'] ?? null)) {
                        $retryRuntimeTraces[] = $governedRetry['trace'];
                    }
                    $routed = $this->providerRouter->chat(
                        $retryMessages,
                        $agent, null, null, $agentProviders[$agentId] ?? null
                    );
                    $this->messageRepo->create([
                        'id'           => $this->uuid(),
                        'session_id'   => $sessionId,
                        'role'         => 'assistant',
                        'agent_id'     => $agentId,
                        'round'        => $rounds + 1,
                        'phase'        => 'retry-round',
                        'mode_context' => 'decision-room',
                        'message_type' => 'retry-round',
                        'meta_json'    => $retryMetaJson,
                        'content'      => $routed['content'] ?? '',
                        'created_at'   => date('c'),
                    ]);
                } catch (\Throwable $e) {
                    error_log('[DecisionRoomRunner] Auto-retry agent failed: ' . $e->getMessage());
                }
            }

            $allVotesRetry     = $this->voteRepo->findVotesBySession($sessionId);
            $newFalseConsensus = $this->falseConsensusDetector->detect(
                $reliability['context_quality'] ?? [],
                $state['positions'] ?? [],
                $state['edges'] ?? [],
                $allVotesRetry,
                $reliability['raw_decision'] ?? null
            );
            $newDebateProxy    = (float)(($newFalseConsensus['diversity_score'] ?? 0.5) * 100);

            $guardrails = $this->guardrailService->evaluate(
                rawDecision:       $reliability['raw_decision'] ?? [],
                adjustedDecision:  $reliability['adjusted_decision'] ?? [],
                contextQuality:    $reliability['context_quality'] ?? [],
                falseConsensus:    $newFalseConsensus,
                debateQualityScore:$newDebateProxy,
                evidenceReport:    null,
                riskProfile:       null,
                mode:              'decision-room',
                sessionOptions:    [] // empty: max 1 retry enforced
            );

            $autoRetryResult = [
                'triggered'                    => true,
                'reason'                       => 'weak_parallel_debate',
                'initial_debate_quality_score' => $initialScore,
                'retry_debate_quality_score'   => $newDebateProxy,
                'extra_rounds'                 => 1,
            ];

            $this->writeRunStatus($sessionId, ['status' => 'auto_retry_complete', 'new_score' => $newDebateProxy]);
        }

        $finalFcData      = $newFalseConsensus ?? $falseConsensusData;
        $finalDebateScore = $newDebateProxy ?? $debateQualityProxy;
        $qualityScore = $this->qualityScoreService->compute(
            contextQuality:     $reliability['context_quality'] ?? [],
            debateQualityScore: $finalDebateScore,
            evidenceReport:     $evidenceReport,
            riskProfile:        $riskProfile,
            falseConsensus:     $finalFcData
        );

        $synthesizerOutput = '';
        foreach ($allMessages[$rounds] ?? [] as $msg) {
            if (($msg['agent_id'] ?? '') === 'synthesizer') {
                $synthesizerOutput = $msg['content'] ?? '';
                break;
            }
        }

        // Minimal reliability warning when a majority of agents errored.
        // Avoids changing run status / completion semantics.
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
                $warn = 'Majority of agents failed during this run; treat conclusions as unreliable.';
                $guardrails = is_array($guardrails) ? $guardrails : [];
                $existing = isset($guardrails['warnings']) && is_array($guardrails['warnings']) ? $guardrails['warnings'] : [];
                $guardrails['warnings'] = array_values(array_unique(array_merge([$warn], $existing)));
            }
        } catch (\Throwable) {
        }

        $verdictRow = $this->verdictRepo->findBySession($sessionId);
        $canonicalSynthesis = CanonicalSynthesisExtractor::extract($synthesizerOutput, $playbookId);
        $playbookDiagnostics = $this->playbookRuntime->extractDiagnostics($synthesizerOutput, $playbookId);
        if (!empty($playbookDiagnostics['warnings'])) {
            $guardrails = is_array($guardrails) ? $guardrails : [];
            $existing = isset($guardrails['warnings']) && is_array($guardrails['warnings']) ? $guardrails['warnings'] : [];
                $guardrails['warnings'] = array_values(array_unique(array_merge($existing, $playbookDiagnostics['warnings'])));
        }
        $decisionOutcome = DecisionOutcomeProjector::fromCanonical($canonicalSynthesis, [
            'playbook_runtime' => $playbookDiagnostics,
            'risk_profile' => $riskProfile,
            'guardrails' => $guardrails,
        ]);
        $decisionBrief = $this->summaryService->buildDecisionBrief(
            array_merge($reliability, [
                'synthesizer_output'     => $synthesizerOutput,
                'guardrails'             => $guardrails,
                'decision_quality_score' => $qualityScore,
                'risk_profile'           => $riskProfile,
                'evidence_report'        => $evidenceReport,
                'verdict'                => $verdictRow ?: null,
                'canonical_synthesis'    => $canonicalSynthesis,
                'playbook_runtime'        => $playbookDiagnostics,
                'decision_outcome'        => $decisionOutcome,
            ])
        );

        $premortemSummary = null;
        if (($sessionOptions['session_variant'] ?? '') === 'premortem') {
            $premortemSummary = PremortemSummaryExtractor::fromSynthesizerOutput($synthesizerOutput);
        }

        $runtimeTraces = array_merge(
            CognitiveRuntimeGovernance::collectTracesFromRounds($allMessages),
            $retryRuntimeTraces
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
            'guardrails' => $guardrails,
            'auto_retry' => $autoRetryResult,
            'decision_quality_score' => $qualityScore,
            'decision_brief' => $decisionBrief,
            'canonical_synthesis' => $canonicalSynthesis,
            'decision_outcome' => $decisionOutcome,
            'playbook_runtime' => $playbookDiagnostics,
            'premortem_summary' => $premortemSummary,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'decision-room')));
    }

    /**
     * @return array{target_agent_id:?string,edge_source:string}
     */
    private function resolveTargetAgent(string $content, array $previousRoundMessages, string $agentId, ?string $assignedTarget = null): array {
        if (!empty($previousRoundMessages)) {
            // 1. Explicit LLM declaration takes priority
            if (preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
                $parsed = strtolower(trim($m[1]));
                $valid  = array_map('strtolower', array_column($previousRoundMessages, 'agent_id'));
                if (in_array($parsed, $valid, true) && $parsed !== strtolower($agentId)) {
                    return ['target_agent_id' => $parsed, 'edge_source' => 'explicit_target'];
                }
            }
            // 2. Fall back to the pre-assigned target when LLM was silent
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

    private function countMessageChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen((string)($message['content'] ?? ''), 'UTF-8');
        }
        return $chars;
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

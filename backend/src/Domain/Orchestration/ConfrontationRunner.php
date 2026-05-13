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
use Infrastructure\Persistence\RunStatusRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\VoteRepository;

class ConfrontationRunner {
    private AgentAssembler    $assembler;
    private PromptBuilder     $promptBuilder;
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
        $this->guardrailService = new \Domain\DecisionReliability\DecisionGuardrailService();
        $this->qualityScoreService = new \Domain\DecisionReliability\DecisionQualityScoreService();
        $this->summaryService = new DecisionSummaryService();
        $this->playbookRuntime = new PlaybookRuntime();
        $this->logger = new Logger();
        $this->runStatusRepo = new RunStatusRepository();
    }

    /**
     * New configurable runner: supports sequential and agent-to-agent interaction styles.
     */
    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        bool   $includeSynthesis       = true,
        string $language               = 'en',
        int    $rounds                 = 3,
        string $interactionStyle       = 'sequential',
        string $replyPolicy            = 'all-agents-reply',
        bool   $forceDisagreement      = false,
        ?array $contextDoc             = null,
        bool   $devilAdvocateEnabled   = false,
        float  $devilAdvocateThreshold = 0.65,
        array  $agentProviders         = [],
        float  $decisionThreshold      = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        ?string $decisionDynamicsPreset = null,
        ?string $strategicContextId = null,
        array $blueTeamAgents = [],
        array $redTeamAgents = []
    ): array {
        $rounds = min(max($rounds, 1), 15);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $dynamicsPreset    = \Domain\Agents\DecisionDynamicsPreset::normalizeId($decisionDynamicsPreset);
        $playbookId        = $this->playbookRuntime->resolvePlaybookId('confrontation', [], $objective);

        // Split synthesizer out — it runs separately at the end
        $activeAgents = array_values(array_filter($selectedAgents, fn($a) => $a !== 'synthesizer'));
        if (empty($activeAgents)) {
            $activeAgents = ['pm', 'architect', 'critic'];
        }

        $allRounds    = [];
        $prevMessages = [];
        $state        = $this->debateMemory->loadState($sessionId);
        $this->voteRepo->clearSession($sessionId);
        $this->socialDynamics->clearSession($sessionId);
        $daPartialHistory = [];
        $contextQuality = $this->reliabilityService->buildEnvelope(
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
            $this->runStatusRepo->appendEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'round_started',
                    'round' => $round,
                    'label' => 'Tour ' . $round . ' demarre',
                ],
                [
                    'current_round' => $round,
                    'total_rounds' => $rounds,
                    'current_phase' => 'round_started',
                    'current_phase_label' => 'Tour demarre',
                    'current_step' => 'round_start',
                    'percent' => RunnerProgressPercent::confrontationRunPercent($round, $rounds, 0.05),
                    'estimated' => true,
                ],
                'running'
            );
            $memoryContext = $this->debateMemory->buildPromptContext($state);
            $roundMessages = $this->runRound(
                $sessionId, $objective, $activeAgents,
                $prevMessages, $round, $rounds,
                $interactionStyle, $replyPolicy, $language, $forceDisagreement, $contextDoc, $memoryContext, $state,
                $agentProviders,
                $contextQuality,
                $dynamicsPreset,
                $strategicContextId,
                $forceStrongNext,
                $blueTeamAgents,
                $redTeamAgents
            );

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
                                'mode' => 'confrontation',
                            ],
                            'confrontation_user_payload',
                            'orchestration',
                            'confrontation_devil_advocate_payload'
                        );
                        $daMessages = $governedDa['messages'];
                        $daMetaJson = $governedDa['meta_json'];
                        $this->runStatusRepo->appendEvent(
                            $sessionId,
                            [
                                'level' => 'info',
                                'phase' => 'devil-advocate',
                                'round' => $round,
                                'agent_id' => 'devil_advocate',
                                'label' => 'Devil advocate · appel LLM demarre',
                            ],
                            [
                                'current_round' => $round,
                                'total_rounds' => $rounds,
                                'current_phase' => 'devil-advocate',
                                'current_phase_label' => $this->phaseHumanLabel('devil-advocate'),
                                'current_team' => null,
                                'current_agent_id' => 'devil_advocate',
                                'current_step' => 'llm_call',
                                'percent' => RunnerProgressPercent::confrontationRunPercent($round, $rounds, 0.35),
                                'estimated' => true,
                            ],
                            'running'
                        );
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
                            'mode_context'             => 'confrontation',
                            'message_type'             => 'devil_advocate',
                            'meta_json'                => $daMetaJson,
                            'content'                  => $daContent,
                            'created_at'               => date('c'),
                        ]);
                        $roundMessages[] = $daMsg;
                        $this->runStatusRepo->appendEvent(
                            $sessionId,
                            [
                                'level' => 'info',
                                'phase' => 'devil-advocate',
                                'round' => $round,
                                'agent_id' => 'devil_advocate',
                                'label' => 'Devil advocate · reponse recue',
                            ],
                            [
                                'current_round' => $round,
                                'total_rounds' => $rounds,
                                'current_phase' => 'devil-advocate',
                                'current_phase_label' => $this->phaseHumanLabel('devil-advocate'),
                                'current_team' => null,
                                'current_agent_id' => 'devil_advocate',
                                'current_step' => 'response_received',
                                'percent' => RunnerProgressPercent::confrontationRunPercent($round, $rounds, 0.45),
                                'estimated' => true,
                            ],
                            'running'
                        );
                    } catch (\Throwable $e) {
                        error_log('[ConfrontationRunner] Devil advocate failed: ' . $e->getMessage());
                    }
                }
                $daPartialHistory[] = $partialConfidence;
            }

            $allRounds[$round] = $roundMessages;
            $prevMessages      = $roundMessages;
        }

        // Optional synthesis by the synthesizer agent
        $automaticDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold, $dynamicsPreset);
        $synthesis = [];
        $verdict   = null;
        if ($includeSynthesis) {
            $this->runStatusRepo->appendEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'synthesis_started',
                    'round' => $rounds + 1,
                    'label' => 'Synthese demarree',
                ],
                [
                    'current_round' => $rounds,
                    'total_rounds' => $rounds,
                    'current_phase' => 'synthesis',
                    'current_phase_label' => 'Synthèse',
                    'current_team' => null,
                    'current_agent_id' => 'synthesizer',
                    'current_step' => 'llm_call',
                    'percent' => RunnerProgressPercent::confrontationRunPercent($rounds, $rounds, 0.85),
                    'estimated' => true,
                ],
                'running'
            );
            $allMessages = array_merge(...array_values($allRounds));
            $memoryContext = $this->debateMemory->buildPromptContext($state);

            // Build reliability-aware constraint block for synthesis
            $synthExtraContent = null;
            try {
                $preSynthMessages = $this->messageRepo->findBySession($sessionId);
                $preEvidence = null;
                try {
                    $preEvidence = $this->evidenceService->generateAndPersist(
                        $sessionId,
                        $preSynthMessages,
                        $contextDoc
                    );
                } catch (\Throwable) {
                }
                $preEnvelope  = $this->reliabilityService->buildEnvelope(
                    $objective, $contextDoc, $automaticDecision,
                    $this->voteRepo->findVotesBySession($sessionId),
                    $state['positions'] ?? [], $state['edges'] ?? [],
                    $decisionThreshold, null, null, null,
                    $preEvidence,
                    null
                );
                $preFcData        = $preEnvelope['false_consensus'] ?? [];
                $preDebateQuality = (float)(($preFcData['diversity_score'] ?? 0.5) * 100);
                $preGuardrails    = $this->guardrailService->evaluate(
                    rawDecision:       $preEnvelope['raw_decision'] ?? [],
                    adjustedDecision:  $preEnvelope['adjusted_decision'] ?? [],
                    contextQuality:    $preEnvelope['context_quality'] ?? [],
                    falseConsensus:    $preFcData,
                    debateQualityScore:$preDebateQuality,
                    evidenceReport:    $preEvidence,
                    riskProfile:       null,
                    mode:              'confrontation',
                    sessionOptions:    []
                );
                $synthExtraContent = $this->promptBuilder->buildSynthesizerConstraintBlock(
                    array_merge($preEnvelope, [
                        'debate_quality_score' => $preDebateQuality,
                        'guardrails'           => $preGuardrails,
                        'evidence_report'      => $preEvidence,
                    ])
                ) . $this->promptBuilder->buildSynthesizerOutputFormatInstruction();
            } catch (\Throwable $e) {
                error_log('[ConfrontationRunner] Synthesizer constraint build failed: ' . $e->getMessage());
            }

            [$synthesis, $verdict] = $this->runSynthesis(
                $sessionId,
                $objective,
                $allMessages,
                $language,
                $rounds + 1,
                $forceDisagreement,
                $contextDoc,
                $memoryContext,
                $synthExtraContent,
                $dynamicsPreset
            );
            if (!empty($synthesis[0]['content'])) {
                $this->runStatusRepo->appendEvent(
                    $sessionId,
                    [
                        'level' => 'info',
                        'phase' => 'synthesis_completed',
                        'round' => $rounds + 1,
                        'label' => 'Synthese terminee',
                    ],
                    [
                        'current_round' => $rounds,
                        'total_rounds' => $rounds,
                        'current_phase' => 'synthesis_completed',
                        'current_phase_label' => 'Synthese terminee',
                        'current_team' => null,
                        'current_agent_id' => 'synthesizer',
                        'current_step' => 'persisted',
                        'percent' => RunnerProgressPercent::confrontationRunPercent($rounds, $rounds, 0.92),
                        'estimated' => true,
                    ],
                    'running'
                );
                $this->debateMemory->processMessage(
                    $sessionId,
                    $rounds + 1,
                    'synthesizer',
                    $synthesis[0]['content'],
                    null,
                    $state
                );
            }
        }

        $weighted = $this->debateMemory->buildWeightedAnalysis($state);
        $allSessionMessages = $this->messageRepo->findBySession($sessionId);
        $evidenceReport = null;
        try {
            $evidenceReport = $this->evidenceService->generateAndPersist(
                $sessionId, $allSessionMessages, $contextDoc
            );
        } catch (\Throwable $e) {
            error_log('[ConfrontationRunner] Evidence generation failed: ' . $e->getMessage());
        }
        $riskProfile = null;
        try {
            $riskProfile = $this->riskAnalyzer->analyzeAndPersist(
                $sessionId, $objective, 'confrontation',
                $allSessionMessages, $contextDoc, $decisionThreshold, $evidenceReport
            );
        } catch (\Throwable $e) {
            error_log('[ConfrontationRunner] Risk analysis failed: ' . $e->getMessage());
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
            mode:              'confrontation',
            sessionOptions:    []
        );

        // Minimal reliability warning when a majority of agents errored.
        try {
            $agentIds = array_values(array_filter($selectedAgents, fn($id) => $id !== 'devil_advocate'));
            $agentIds = array_values(array_unique(array_map('strval', $agentIds)));
            $errorAgents = [];
            foreach ($allRounds as $roundMsgs) {
                foreach (($roundMsgs ?? []) as $m) {
                    $aid = (string)($m['agent_id'] ?? '');
                    $content = (string)($m['content'] ?? '');
                    if ($aid !== '' && str_starts_with($content, '[Error]')) {
                        $errorAgents[$aid] = true;
                    }
                }
            }
            foreach ($synthesis as $m) {
                $aid = (string)($m['agent_id'] ?? '');
                $content = (string)($m['content'] ?? '');
                if ($aid !== '' && str_starts_with($content, '[Error]')) {
                    $errorAgents[$aid] = true;
                }
            }
            $totalAgents = count($agentIds);
            $errorCount = count($errorAgents);
            if ($totalAgents > 0 && $errorCount > ($totalAgents / 2)) {
                $warn = 'Majority of agents failed during execution; decision reliability is degraded.';
                $existing = isset($guardrails['warnings']) && is_array($guardrails['warnings']) ? $guardrails['warnings'] : [];
                $guardrails['warnings'] = array_values(array_unique(array_merge([$warn], $existing)));
            }
        } catch (\Throwable) {
        }

        if ($guardrails['final_outcome_override'] !== null) {
            $reliability['adjusted_decision']['final_outcome'] = $guardrails['final_outcome_override'];
        }

        $qualityScore = $this->qualityScoreService->compute(
            contextQuality:     $reliability['context_quality'] ?? [],
            debateQualityScore: $debateQualityProxy,
            evidenceReport:     $evidenceReport,
            riskProfile:        $riskProfile,
            falseConsensus:     $falseConsensusData
        );

        $verdictRow = null;
        if (is_array($verdict) && $verdict !== []) {
            $verdictRow = $verdict;
        } else {
            $verdictRow = $this->verdictRepo->findBySession($sessionId);
        }

        $synthesizerOutput = $synthesis[0]['content'] ?? '';
        $canonicalSynthesis = CanonicalSynthesisExtractor::extract($synthesizerOutput, $playbookId);
        $playbookDiagnostics = $this->playbookRuntime->extractDiagnostics($synthesizerOutput, $playbookId);
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

        // Heuristic fallback: confrontation synthesis formatting can vary; ensure the brief has "why" content
        // even if the synthesizer section parser doesn't match expected headings.
        $heuristicBrief = [];
        try {
            $parsedVerdictBrief = VerdictParser::parse($synthesizerOutput, $playbookId);
            $heuristicBrief = $this->summaryService->build(
                ['title' => 'Confrontation'],
                $parsedVerdictBrief ?: (is_array($verdictRow) ? $verdictRow : null),
                $automaticDecision,
                $this->voteRepo->findVotesBySession($sessionId),
                $state['arguments'] ?? []
            );
        } catch (\Throwable) {
            $heuristicBrief = [];
        }
        $keyFactorsForBrief = array_map(
            fn($t) => ['text' => $t],
            $heuristicBrief['key_factors'] ?? []
        );
        $riskProfileForBrief = $riskProfile;
        if (
            (empty($riskProfileForBrief) || empty(($riskProfileForBrief['top_risks'] ?? []))) &&
            !empty(($heuristicBrief['risks'] ?? []))
        ) {
            $riskProfileForBrief = array_merge(is_array($riskProfileForBrief) ? $riskProfileForBrief : [], [
                'top_risks' => array_map(fn($t) => ['description' => $t], array_slice((array)$heuristicBrief['risks'], 0, 5)),
            ]);
        }
        $decisionBrief = $this->summaryService->buildDecisionBrief(
            array_merge($reliability, [
                'synthesizer_output'     => $synthesizerOutput,
                'key_factors'            => $keyFactorsForBrief,
                'guardrails'             => $guardrails,
                'decision_quality_score' => $qualityScore,
                'risk_profile'           => $riskProfileForBrief,
                'evidence_report'        => $evidenceReport,
                'verdict'                => $verdictRow,
                'canonical_synthesis'    => $canonicalSynthesis,
                'playbook_runtime'        => $playbookDiagnostics,
                'decision_outcome'        => $decisionOutcome,
            ])
        );

        $runtimeTraces = CognitiveRuntimeGovernance::collectTracesFromMessageBuckets(array_merge(
            array_values($allRounds),
            [$synthesis]
        ));

        return StructuredRunResult::augment(array_merge([
            'rounds'            => $allRounds,
            'synthesis'         => $synthesis,
            'verdict'           => $verdict,
            'total_rounds'      => $rounds,
            'interaction_style' => $interactionStyle,
            'reply_policy'      => $replyPolicy,
            'arguments'         => $state['arguments'],
            'positions'         => $state['positions'],
            'interaction_edges' => $state['edges'],
            'weighted_analysis' => $weighted,
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
            'synthesizer_output' => !empty($synthesis[0]['content']) ? $synthesis[0]['content'] : null,
            'decision_quality_score' => $qualityScore,
            'decision_brief' => $decisionBrief,
            'canonical_synthesis' => $canonicalSynthesis,
            'decision_outcome' => $decisionOutcome,
            'playbook_runtime' => $playbookDiagnostics,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'confrontation')));
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function runRound(
        string $sessionId,
        string $objective,
        array  $agents,
        array  $prevMessages,
        int    $currentRound,
        int    $totalRounds,
        string $interactionStyle,
        string $replyPolicy,
        string $language,
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        array  &$state,
        array  $agentProviders,
        array  $contextQuality,
        ?string $dynamicsPreset,
        ?string $strategicContextIdForAgentMemory,
        bool   &$forceStrongNextFlag,
        array  $blueTeamAgents = [],
        array  $redTeamAgents = []
    ): array {
        $roundMessages = [];
        $respondingAgents = $this->selectRespondingAgents($agents, $prevMessages, $currentRound, $interactionStyle, $replyPolicy);

        foreach ($respondingAgents as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            if (!$agent) continue;
            $team = $this->resolveTeam($agentId, $blueTeamAgents, $redTeamAgents);
            $phase = $this->resolvePhaseLabel($currentRound, $team);
            $this->runStatusRepo->appendEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => $phase,
                    'round' => $currentRound,
                    'team' => $team,
                    'agent_id' => (string)$agentId,
                    'label' => $this->eventLabel($phase, $team, (string)$agentId, true),
                ],
                [
                    'current_round' => $currentRound,
                    'total_rounds' => $totalRounds,
                    'current_phase' => $phase,
                    'current_phase_label' => $this->phaseHumanLabel($phase),
                    'current_team' => $team,
                    'current_agent_id' => (string)$agentId,
                    'current_step' => 'llm_call',
                    'percent' => RunnerProgressPercent::confrontationRunPercent($currentRound, $totalRounds, 0.2),
                    'estimated' => true,
                ],
                'running'
            );

            $assignedTarget = ($currentRound > 1 && $agentId !== 'synthesizer')
                ? $this->computeAssignedTarget($agents, $agentId, $currentRound)
                : null;

            $votesSnap    = $this->voteRepo->findVotesBySession($sessionId);
            $maj          = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
            $socialBlock  = null;
            if ($currentRound > 1 && $totalRounds > 1) {
                $socialBlock = $this->socialPrompt->buildUserBlock(
                    $sessionId,
                    $agentId,
                    $maj,
                    $strategicContextIdForAgentMemory,
                    false
                );
            }

            try {
                $messages = $this->promptBuilder->buildConfrontationRoundMessages(
                    $agent, $objective, $prevMessages,
                    $currentRound, $totalRounds,
                    $interactionStyle, $language, $forceDisagreement, $contextDoc, $memoryContext,
                    $assignedTarget,
                    $socialBlock,
                    $forceStrongNextFlag,
                    $sessionId,
                    null,
                    $strategicContextIdForAgentMemory
                );
                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $messages,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => $strategicContextIdForAgentMemory,
                        'round' => $currentRound,
                        'agent_id' => $agentId,
                        'mode' => 'confrontation',
                    ],
                    'confrontation_user_payload',
                    'orchestration',
                    'confrontation_runtime_user_payload',
                    ['interaction_style' => $interactionStyle]
                );
                $messages = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];
                $this->logger->logPromptBuild('prompt_built_confrontation', [
                    'agent_id' => $agent->id,
                    'metadata' => [
                        'mode' => 'confrontation',
                        'round' => $currentRound,
                        'total_rounds' => $totalRounds,
                        'interaction_style' => $interactionStyle,
                        'message_count' => count($messages),
                        'character_count' => $this->countMessageChars($messages),
                        'context_doc_injected' => !empty($contextDoc['content']),
                        'memory_injected' => !empty(($memoryContext['argument_memory_summary'] ?? null)),
                        'force_disagreement' => (bool)$forceDisagreement,
                        'playbook_id' => 'confrontation',
                        'session_id' => $sessionId,
                    ],
                ]);

                $routed        = $this->providerRouter->chat(
                    $messages,
                    $agent,
                    null,
                    null,
                    $this->resolveAgentOverride($agentProviders, (string)$agentId)
                );
                $content       = $routed['content'];
                $targetResolution = ($currentRound > 1)
                    ? $this->resolveTargetAgent($content, $prevMessages, $agentId, $assignedTarget)
                    : ['target_agent_id' => null, 'edge_source' => 'unknown'];
                $targetAgentId = $targetResolution['target_agent_id'];

                $msgType = $this->resolveMessageType($currentRound, $totalRounds, $interactionStyle);

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
                    'round'                    => $currentRound,
                    'phase'                    => 'round-' . $currentRound,
                    'target_agent_id'          => $targetAgentId,
                    'mode_context'             => 'confrontation',
                    'message_type'             => $msgType,
                    'meta_json'                => $promptMetaJson,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $roundMessages[] = $msg;
                $this->runStatusRepo->appendEvent(
                    $sessionId,
                    [
                        'level' => 'info',
                        'phase' => $phase,
                        'round' => $currentRound,
                        'team' => $team,
                        'agent_id' => (string)$agentId,
                        'label' => $this->eventLabel($phase, $team, (string)$agentId, false),
                        'metadata' => [
                            'provider' => $routed['provider_name'] ?? ($routed['provider_id'] ?? null),
                            'model' => $routed['model'] ?? null,
                        ],
                    ],
                    [
                        'current_round' => $currentRound,
                        'total_rounds' => $totalRounds,
                        'current_phase' => $phase,
                        'current_phase_label' => $this->phaseHumanLabel($phase),
                        'current_team' => $team,
                        'current_agent_id' => (string)$agentId,
                        'current_agent_name' => (string)($agent->persona->name ?? $agentId),
                        'current_step' => 'persisted',
                        'percent' => RunnerProgressPercent::confrontationRunPercent($currentRound, $totalRounds, 0.45),
                        'estimated' => true,
                    ],
                    'running'
                );
                $this->debateMemory->processMessage(
                    $sessionId,
                    $currentRound,
                    $agentId,
                    $content,
                    $targetAgentId,
                    $state,
                    $targetResolution['edge_source']
                );
                $this->socialDynamics->ingestAgentResponse(
                    $sessionId,
                    $currentRound,
                    $agentId,
                    $content,
                    $targetAgentId,
                    $agents,
                    $this->voteRepo->findVotesBySession($sessionId),
                    $state['positions'] ?? [],
                    $strategicContextIdForAgentMemory
                );
                if ($currentRound === $totalRounds) {
                    $parsedVote = $this->voteParser->parse($content);
                    if ($parsedVote) {
                        $this->voteRepo->createVote([
                            'id' => $this->uuid(),
                            'session_id' => $sessionId,
                            'round' => $currentRound,
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
                        error_log('[ConfrontationRunner] Final vote parse failed for agent ' . $agentId);
                    }
                }

            } catch (\Throwable $e) {
                $msgType = $this->resolveMessageType($currentRound, $totalRounds, $interactionStyle);
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
                    'round'                    => $currentRound,
                    'phase'                    => 'round-' . $currentRound,
                    'target_agent_id'          => null,
                    'mode_context'             => 'confrontation',
                    'message_type'             => $msgType,
                    'content'                  => '[Error] ' . $e->getMessage(),
                    'created_at'               => date('c'),
                ]);
                $roundMessages[] = $msg;
                $this->runStatusRepo->appendEvent(
                    $sessionId,
                    [
                        'level' => 'error',
                        'phase' => $phase,
                        'round' => $currentRound,
                        'team' => $team,
                        'agent_id' => (string)$agentId,
                        'label' => $this->eventLabel($phase, $team, (string)$agentId, false),
                    ],
                    [
                        'current_round' => $currentRound,
                        'total_rounds' => $totalRounds,
                        'current_phase' => $phase,
                        'current_phase_label' => $this->phaseHumanLabel($phase),
                        'current_team' => $team,
                        'current_agent_id' => (string)$agentId,
                        'current_step' => 'failed',
                        'percent' => RunnerProgressPercent::confrontationRunPercent($currentRound, $totalRounds, 0.45),
                        'estimated' => true,
                    ],
                    'running',
                    (string)$e->getMessage()
                );
            }
        }

        if ($currentRound < $totalRounds) {
            $votesEnd = $this->voteRepo->findVotesBySession($sessionId);
            $forceStrongNextFlag = $this->falseConsensusDetector->shouldForceChallengeNextRound(
                $contextQuality,
                $state['positions'] ?? [],
                $state['edges'] ?? [],
                $votesEnd
            );
        } else {
            $forceStrongNextFlag = false;
        }

        return $roundMessages;
    }

    private function runSynthesis(
        string $sessionId,
        string $objective,
        array  $allMessages,
        string $language,
        int    $synthRound,
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        ?string $extraUserContent = null,
        ?string $dynamicsPreset = null
    ): array {
        $agent = $this->assembler->assemble('synthesizer', null, null, $dynamicsPreset);
        if (!$agent) return [[], null];

        try {
            $messages = $this->promptBuilder->buildConfrontationSynthesisMessages(
                $agent, $objective, $allMessages, $language, $forceDisagreement, $contextDoc, $memoryContext,
                $sessionId,
                null
            );
            $this->logger->logPromptBuild('prompt_built_confrontation_synthesis', [
                'agent_id' => $agent->id,
                'metadata' => [
                    'mode' => 'confrontation',
                    'synthesis' => true,
                    'message_count' => count($messages),
                    'character_count' => $this->countMessageChars($messages),
                    'context_doc_injected' => !empty($contextDoc['content']),
                    'memory_injected' => !empty(($memoryContext['weighted_analysis'] ?? null)),
                    'force_disagreement' => (bool)$forceDisagreement,
                    'playbook_id' => 'confrontation',
                    'session_id' => $sessionId,
                ],
            ]);

            if ($extraUserContent !== null) {
                foreach ($messages as &$msg) {
                    if ($msg['role'] === 'user') {
                        $msg['content'] .= $extraUserContent;
                        break;
                    }
                }
                unset($msg);
            }
            $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                $messages,
                [
                    'session_id' => $sessionId,
                    'strategic_context_id' => null,
                    'round' => $synthRound,
                    'agent_id' => 'synthesizer',
                    'mode' => 'confrontation',
                ],
                'confrontation_synthesis_payload',
                'orchestration',
                'confrontation_runtime_synthesis_payload'
            );
            $messages = $governed['messages'];
            $promptMetaJson = $governed['meta_json'];

            $routed  = $this->providerRouter->chat($messages, $agent);
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
                'routing_source'           => $routed['routing_source'] ?? null,
                'resolved_provider_id'     => $routed['resolved_provider_id'] ?? null,
                'resolved_provider_label'  => $routed['resolved_provider_label'] ?? null,
                'resolved_model'           => $routed['resolved_model'] ?? null,
                'session_override_present' => $routed['session_override_present'] ?? null,
                'persona_default_provider_ignored' => $routed['persona_default_provider_ignored'] ?? null,
                'fallback_from_provider_id' => $routed['fallback_from_provider_id'] ?? null,
                'fallback_from_model'      => $routed['fallback_from_model'] ?? null,
                'round'                    => $synthRound,
                'phase'                    => 'synthesis',
                'mode_context'             => 'confrontation',
                'message_type'             => 'synthesis',
                'meta_json'                => $promptMetaJson,
                'content'                  => $content,
                'created_at'               => date('c'),
            ]);

            $verdict = null;
            $parsed  = VerdictParser::parse($content, 'confrontation');
            if ($parsed) {
                $verdictData = array_merge($parsed, [
                    'id'         => $this->uuid(),
                    'session_id' => $sessionId,
                    'created_at' => date('c'),
                ]);
                $verdict = $this->verdictRepo->create($verdictData);
            }

            return [[$msg], $verdict];

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
                'round'                    => $synthRound,
                'phase'                    => 'synthesis',
                'mode_context'             => 'confrontation',
                'message_type'             => 'synthesis',
                'content'                  => '[Error] ' . $e->getMessage(),
                'created_at'               => date('c'),
            ]);
            return [[$msg], null];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parse ## Target Agent\n{agent_id} from agent output.
     */
    public static function parseTargetAgent(string $content): ?string {
        if (preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * @return array{target_agent_id:?string,edge_source:string}
     */
    private function resolveTargetAgent(string $content, array $prevMessages, string $authorAgentId, ?string $assignedTarget): array {
        $parsed = self::parseTargetAgent($content);
        $validParsed = $this->validateTargetAgentId($parsed, $prevMessages, $authorAgentId);
        if ($validParsed !== null) {
            return ['target_agent_id' => $validParsed, 'edge_source' => 'explicit_target'];
        }

        $validAssigned = $this->validateTargetAgentId($assignedTarget, $prevMessages, $authorAgentId);
        if ($validAssigned !== null) {
            return ['target_agent_id' => $validAssigned, 'edge_source' => 'assigned_fallback'];
        }

        return ['target_agent_id' => null, 'edge_source' => 'unknown'];
    }

    private function validateTargetAgentId(?string $targetAgentId, array $prevMessages, string $authorAgentId): ?string {
        if (!$targetAgentId) {
            return null;
        }
        $validTargets = array_values(array_unique(array_filter(
            array_map(fn($m) => $m['agent_id'] ?? null, $prevMessages),
            fn($id) => !empty($id) && $id !== $authorAgentId
        )));
        return in_array($targetAgentId, $validTargets, true) ? $targetAgentId : null;
    }

    private function selectRespondingAgents(
        array $agents,
        array $prevMessages,
        int $currentRound,
        string $interactionStyle,
        string $replyPolicy
    ): array {
        if ($currentRound <= 1 || $interactionStyle !== 'agent-to-agent') {
            return $agents;
        }

        if ($replyPolicy === 'only-mentioned-agent-replies') {
            $targets = array_values(array_unique(array_filter(
                array_map(fn($m) => $m['target_agent_id'] ?? null, $prevMessages),
                fn($id) => !empty($id)
            )));
            $filtered = array_values(array_filter($agents, fn($id) => in_array($id, $targets, true)));
            return !empty($filtered) ? $filtered : $agents;
        }

        if ($replyPolicy === 'critic-priority') {
            $targets = array_values(array_unique(array_filter(
                array_map(fn($m) => $m['target_agent_id'] ?? null, $prevMessages),
                fn($id) => !empty($id)
            )));
            if (in_array('critic', $targets, true) && in_array('critic', $agents, true)) {
                return ['critic'];
            }
            $filtered = array_values(array_filter($agents, fn($id) => in_array($id, $targets, true)));
            return !empty($filtered) ? $filtered : $agents;
        }

        // all-agents-reply
        return $agents;
    }

    private function resolveMessageType(int $round, int $total, string $style): string {
        if ($round === 1) return 'initial-position';
        if ($round === $total) return 'final-position';
        return $style === 'agent-to-agent' ? 'agent-reply' : 'challenge';
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

    private function resolveTeam(string $agentId, array $blueTeamAgents, array $redTeamAgents): ?string
    {
        if (in_array($agentId, $blueTeamAgents, true)) {
            return 'blue';
        }
        if (in_array($agentId, $redTeamAgents, true)) {
            return 'red';
        }
        return null;
    }

    private function resolvePhaseLabel(int $round, ?string $team): string
    {
        if ($team === 'red') {
            return 'red_attack';
        }
        if ($team === 'blue' && $round <= 1) {
            return 'blue_opening';
        }
        if ($team === 'blue') {
            return 'blue_rebuttal';
        }
        return 'debate_round';
    }

    private function phaseHumanLabel(string $phase): string
    {
        return match ($phase) {
            'blue_opening' => 'Blue Team ouverture',
            'red_attack' => 'Red Team attaque',
            'blue_rebuttal' => 'Blue Team riposte',
            'devil-advocate' => 'Devil advocate',
            'synthesis' => 'Synthèse',
            default => 'Confrontation',
        };
    }

    private function eventLabel(string $phase, ?string $team, string $agentId, bool $started): string
    {
        $teamLabel = $team === 'blue' ? 'Blue Team' : ($team === 'red' ? 'Red Team' : 'Equipe');
        $stepLabel = $started ? 'appel LLM demarre' : 'reponse recue';
        $phaseLabel = $this->phaseHumanLabel($phase);
        return $phaseLabel . ' · ' . $teamLabel . ' · ' . $agentId . ' · ' . $stepLabel;
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

<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionQualityScoreService;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\Providers\LlmRoutingMeta;
use Domain\Providers\ProviderRouter;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Infrastructure\Logging\Logger;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RunStatusRepository;
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
        $this->voteRepo      = new VoteRepository();
        $this->voteParser    = new VoteParser();
        $this->voteAggregator = new VoteAggregator($this->voteRepo);
        $this->reliabilityService = new DecisionReliabilityService();
        $this->socialDynamics = new SocialDynamicsService();
        $this->socialPrompt   = new SocialPromptContextBuilder();
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
        string $language          = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc        = null,
        array  $agentProviders    = [],
        float  $decisionThreshold = ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
        ?string $decisionDynamicsPreset = null,
        ?string $strategicContextId = null
    ): array {
        $warning = null;
        $guardrails = [];
        $runtimeTraces = [];
        $playbookId = $this->playbookRuntime->resolvePlaybookId('quick-decision', [], $objective);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($decisionThreshold);
        $dynamicsPreset = \Domain\Agents\DecisionDynamicsPreset::normalizeId($decisionDynamicsPreset);
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
        $this->appendRuntimeEvent($sessionId, [
            'level' => 'info',
            'phase' => 'round_started',
            'round' => 1,
            'label' => 'Round 1 demarre',
        ], [
            'current_round' => 1,
            'total_rounds' => 2,
            'current_phase' => 'analysis',
            'current_phase_label' => 'Analyse agents',
            'current_step' => 'round_start',
            'percent' => 10,
        ]);

        foreach ($nonSynth as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            if (!$agent) continue;
            $this->appendRuntimeEvent($sessionId, [
                'level' => 'info',
                'phase' => 'analysis',
                'round' => 1,
                'agent_id' => $agentId,
                'label' => 'Analyse agents · ' . $agentId . ' · appel LLM demarre',
            ], [
                'current_round' => 1,
                'total_rounds' => 2,
                'current_phase' => 'analysis',
                'current_phase_label' => 'Analyse agents',
                'current_agent_id' => $agentId,
                'current_step' => 'llm_call',
                'percent' => 20,
            ]);

            try {
                $votesSnap   = $this->voteRepo->findVotesBySession($sessionId);
                $maj         = SocialDynamicsService::summarizeMajority($votesSnap, []);
                $socialBlock = null;
                if (count($roundMessages) >= 1) {
                    $socialBlock = $this->socialPrompt->buildUserBlock(
                        $sessionId,
                        $agentId,
                        $maj,
                        $strategicContextId,
                        false
                    );
                }

                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $agent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc, $socialBlock,
                    $sessionId, null, $strategicContextId
                );
                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $messages,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => $strategicContextId,
                        'round' => 1,
                        'agent_id' => $agentId,
                        'mode' => 'quick-decision',
                    ],
                    'quick_decision_user_payload',
                    'orchestration',
                    'quick_decision_runtime_user_payload',
                    ['synthesizer' => false]
                );
                $messages = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];
                if (is_array($governed['trace'] ?? null)) {
                    $runtimeTraces[] = $governed['trace'];
                }
                $this->logger->logPromptBuild('prompt_built_quick_decision', [
                    'agent_id' => $agent->id,
                    'metadata' => [
                        'mode' => 'quick-decision',
                        'synthesizer' => false,
                        'message_count' => count($messages),
                        'character_count' => $this->countMessageChars($messages),
                        'context_doc_injected' => !empty($contextDoc['content']),
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
                    $this->resolveAgentOverride($agentProviders, (string)$agentId),
                    RunTimeoutPolicy::routerOptionsForTelemetry($sessionId, 'quick-decision', 'analysis', $agentId, 1, null)
                );
                $content = $routed['content'];
                $promptMetaJson = LlmRoutingMeta::mergeIntoMetaJson($promptMetaJson, $routed);

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
                    'round'                    => 1,
                    'phase'                    => 'analysis',
                    'mode_context'             => 'quick-decision',
                    'message_type'             => 'analysis',
                    'meta_json'                => $promptMetaJson,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $roundMessages[] = $msg;
                $this->appendRuntimeEvent($sessionId, [
                    'level' => 'info',
                    'phase' => 'analysis',
                    'round' => 1,
                    'agent_id' => $agentId,
                    'label' => 'Analyse agents · ' . $agentId . ' · reponse recue',
                ], [
                    'current_round' => 1,
                    'total_rounds' => 2,
                    'current_phase' => 'analysis',
                    'current_phase_label' => 'Analyse agents',
                    'current_agent_id' => $agentId,
                    'current_step' => 'response_received',
                    'percent' => 45,
                ]);
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
                    [],
                    $strategicContextId
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
                $this->appendRuntimeEvent($sessionId, [
                    'level' => 'error',
                    'phase' => 'analysis',
                    'round' => 1,
                    'agent_id' => $agentId,
                    'label' => 'Analyse agents · ' . $agentId . ' · erreur',
                ], [
                    'current_round' => 1,
                    'total_rounds' => 2,
                    'current_phase' => 'analysis',
                    'current_phase_label' => 'Analyse agents',
                    'current_agent_id' => $agentId,
                    'current_step' => 'failed',
                    'percent' => 45,
                ], 'running', (string)$e->getMessage());
            }
        }

        $synthesis = [];
        $verdict   = null;
        $automaticDecision = $this->voteAggregator->recompute($sessionId, $decisionThreshold, $dynamicsPreset);

        $synthAgent = $this->assembler->assemble('synthesizer', null, null, $dynamicsPreset);
        if ($synthAgent) {
            $this->appendRuntimeEvent($sessionId, [
                'level' => 'info',
                'phase' => 'synthesis_started',
                'round' => 2,
                'agent_id' => 'synthesizer',
                'label' => 'Synthese demarree',
            ], [
                'current_round' => 2,
                'total_rounds' => 2,
                'current_phase' => 'synthesis_started',
                'current_phase_label' => 'Synthese',
                'current_agent_id' => 'synthesizer',
                'current_step' => 'llm_call',
                'percent' => 70,
            ]);
            try {
                $messages = $this->promptBuilder->buildQuickDecisionMessages(
                    $synthAgent, $objective, $roundMessages, $language, $forceDisagreement, $contextDoc, null,
                    $sessionId, null, $strategicContextId
                );
                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $messages,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => $strategicContextId,
                        'round' => 2,
                        'agent_id' => 'synthesizer',
                        'mode' => 'quick-decision',
                    ],
                    'quick_decision_user_payload',
                    'orchestration',
                    'quick_decision_runtime_user_payload',
                    ['synthesizer' => true]
                );
                $messages = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];
                if (is_array($governed['trace'] ?? null)) {
                    $runtimeTraces[] = $governed['trace'];
                }
                $this->logger->logPromptBuild('prompt_built_quick_decision', [
                    'agent_id' => $synthAgent->id,
                    'metadata' => [
                        'mode' => 'quick-decision',
                        'synthesizer' => true,
                        'message_count' => count($messages),
                        'character_count' => $this->countMessageChars($messages),
                        'context_doc_injected' => !empty($contextDoc['content']),
                        'force_disagreement' => (bool)$forceDisagreement,
                        'playbook_id' => $playbookId,
                        'session_id' => $sessionId,
                    ],
                ]);
                $routed  = $this->providerRouter->chat(
                    $messages,
                    $synthAgent,
                    null,
                    null,
                    $this->resolveAgentOverride($agentProviders, 'synthesizer'),
                    RunTimeoutPolicy::routerOptionsForTelemetry($sessionId, 'quick-decision', 'synthesis', 'synthesizer', 2, null)
                );
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
                    'round'                    => 2,
                    'phase'                    => 'synthesis',
                    'mode_context'             => 'quick-decision',
                    'message_type'             => 'synthesis',
                    'meta_json'                => $promptMetaJson,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $synthesis[] = $msg;
                $this->appendRuntimeEvent($sessionId, [
                    'level' => 'info',
                    'phase' => 'synthesis_completed',
                    'round' => 2,
                    'agent_id' => 'synthesizer',
                    'label' => 'Synthese terminee',
                ], [
                    'current_round' => 2,
                    'total_rounds' => 2,
                    'current_phase' => 'synthesis_completed',
                    'current_phase_label' => 'Synthese terminee',
                    'current_agent_id' => 'synthesizer',
                    'current_step' => 'response_received',
                    'percent' => 95,
                ]);

                $parsed = VerdictParser::parse($content, $playbookId);
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
                $this->appendRuntimeEvent($sessionId, [
                    'level' => 'error',
                    'phase' => 'synthesis_failed',
                    'round' => 2,
                    'agent_id' => 'synthesizer',
                    'label' => 'Synthese en erreur',
                ], [
                    'current_round' => 2,
                    'total_rounds' => 2,
                    'current_phase' => 'synthesis_failed',
                    'current_phase_label' => 'Synthese en erreur',
                    'current_agent_id' => 'synthesizer',
                    'current_step' => 'failed',
                    'percent' => 95,
                ], 'running', (string)$e->getMessage());
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
        $synthOut = $synthesis[0]['content'] ?? '';
        $canonicalSynthesis = CanonicalSynthesisExtractor::extract($synthOut, $playbookId);
        $playbookDiagnostics = $this->playbookRuntime->extractDiagnostics($synthOut, $playbookId);
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
        $verdictRow = is_array($verdict) ? $verdict : $this->verdictRepo->findBySession($sessionId);

        // Minimal reliability warning when a majority of agents errored.
        try {
            $agentIds = array_values(array_filter($selectedAgents, fn($id) => $id !== 'devil_advocate'));
            $agentIds = array_values(array_unique(array_map('strval', $agentIds)));
            $errorAgents = [];
            foreach ([$roundMessages, $synthesis] as $bucket) {
                foreach (($bucket ?? []) as $m) {
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
                $warning = $warning ? ($warn . ' ' . $warning) : $warn;
            }
        } catch (\Throwable) {
        }

        $decisionBrief = $this->summaryService->buildDecisionBrief(
            array_merge($reliability, [
                'synthesizer_output'     => $synthOut,
                'guardrails'             => $guardrails,
                'decision_quality_score' => $qualityScore,
                'risk_profile'           => $riskProfile,
                'evidence_report'        => $evidenceReport,
                'false_consensus'        => $fc,
                'verdict'                => $verdictRow ?: null,
                'session_mode_hint'      => 'quick',
                'canonical_synthesis'    => $canonicalSynthesis,
                'playbook_runtime'        => $playbookDiagnostics,
                'decision_outcome'        => $decisionOutcome,
            ])
        );

        return array_merge([
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
            'decision_quality_score' => $qualityScore,
            'decision_brief' => $decisionBrief,
            'canonical_synthesis' => $canonicalSynthesis,
            'decision_outcome' => $decisionOutcome,
            'playbook_runtime' => $playbookDiagnostics,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'quick-decision'));
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

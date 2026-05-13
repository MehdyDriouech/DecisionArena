<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Providers\ProviderRouter;
use Domain\Vote\VoteAggregator;
use Domain\Verdict\VerdictParser;
use Domain\Vote\VoteParser;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\DecisionReliability\FalseConsensusDetector;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\JuryAdversarialReportRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Infrastructure\Persistence\VoteRepository;

class JuryRunner {
    private AgentAssembler     $assembler;
    private ProviderRouter     $providerRouter;
    private MessageRepository  $messageRepo;
    private VoteRepository     $voteRepo;
    private VoteParser         $voteParser;
    private VoteAggregator     $voteAggregator;
    private DebateMemoryService $debateMemory;
    private DecisionReliabilityService $reliabilityService;
    private SocialDynamicsService $socialDynamics;
    private SocialPromptContextBuilder $socialPrompt;
    private FalseConsensusDetector $falseConsensusDetector;
    private EvidenceReportService $evidenceService;
    private RiskProfileAnalyzer $riskAnalyzer;
    private JuryAdversarialReportRepository $adversarialRepo;
    private \Domain\DecisionReliability\DecisionGuardrailService $guardrailService;
    private \Domain\DecisionReliability\DecisionQualityScoreService $qualityScoreService;
    private DecisionSummaryService $summaryService;
    private PromptBuilder $promptBuilder;
    private PlaybookRuntime $playbookRuntime;
    private AgentContextMemoryService $agentContextMemorySvc;
    private RunStatusRepository $runStatusRepo;

    public function __construct() {
        $this->assembler        = new AgentAssembler();
        $this->promptBuilder    = new PromptBuilder();
        $this->providerRouter   = new ProviderRouter();
        $this->messageRepo      = new MessageRepository();
        $this->voteRepo         = new VoteRepository();
        $this->voteParser       = new VoteParser();
        $this->voteAggregator   = new VoteAggregator($this->voteRepo);
        $this->debateMemory     = new DebateMemoryService(new DebateRepository());
        $this->reliabilityService = new DecisionReliabilityService();
        $this->socialDynamics   = new SocialDynamicsService();
        $this->socialPrompt     = new SocialPromptContextBuilder();
        $this->falseConsensusDetector = new FalseConsensusDetector();
        $this->evidenceService  = new EvidenceReportService();
        $this->riskAnalyzer     = new RiskProfileAnalyzer();
        $this->adversarialRepo  = new JuryAdversarialReportRepository();
        $this->guardrailService = new \Domain\DecisionReliability\DecisionGuardrailService();
        $this->qualityScoreService = new \Domain\DecisionReliability\DecisionQualityScoreService();
        $this->summaryService = new DecisionSummaryService();
        $this->playbookRuntime = new PlaybookRuntime();
        $this->agentContextMemorySvc = new AgentContextMemoryService();
        $this->runStatusRepo = new RunStatusRepository();
    }

    private function reportRuntimeEvent(
        string $sessionId,
        array $event,
        array $progressPatch = [],
        ?string $status = null,
        ?string $lastError = null
    ): void
    {
        try {
            $this->runStatusRepo->appendEvent($sessionId, $event, $progressPatch, $status, $lastError);
        } catch (\Throwable $e) {
            // Non-fatal runtime telemetry path
        }
    }

    public function run(
        string $sessionId,
        string $objective,
        array  $selectedAgents,
        int    $rounds,
        bool   $forceDisagreement,
        float  $threshold,
        string $language,
        ?array $contextDoc,
        array  $agentProviders = [],
        array  $adversarialCfg = [],
        ?string $decisionDynamicsPreset = null,
        ?string $strategicContextId = null
    ): array {
        $playbookId = $this->playbookRuntime->resolvePlaybookId('jury', [], $objective);
        $adversarialCfg = $this->normalizeAdversarialConfig($adversarialCfg);
        $threshold = ReliabilityConfig::normalizeThreshold($threshold);
        $dynamicsPreset = \Domain\Agents\DecisionDynamicsPreset::normalizeId($decisionDynamicsPreset);
        $rounds = min(max($rounds, 2), 5);

        if (!in_array('synthesizer', $selectedAgents, true)) {
            $selectedAgents[] = 'synthesizer';
        }

        $debateAgents = array_values(array_filter($selectedAgents, fn($a) => $a !== 'synthesizer'));
        if (empty($debateAgents)) {
            $debateAgents = ['pm', 'architect', 'critic'];
        }

        $this->voteRepo->clearSession($sessionId);
        $this->socialDynamics->clearSession($sessionId);

        $state       = $this->debateMemory->loadState($sessionId);
        $allRounds   = [];
        $allVotes    = [];
        $prevMessages = [];

        $contextQuality = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            null,
            [],
            [],
            [],
            $threshold
        )['context_quality'];
        $forceStrongNext    = false;
        $complianceRetries  = [];

        // ── Main debate rounds ────────────────────────────────────────────────
        $totalDebateRounds = $rounds - 1;
        for ($round = 1; $round <= $totalDebateRounds; $round++) {
            $phase        = $this->resolveJuryPhase($round, $rounds, $adversarialCfg);
            $roundMessages = [];
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'round_started',
                    'round' => $round,
                    'label' => 'Round ' . $round . ' demarre',
                ],
                [
                    'current_round' => $round,
                    'total_rounds' => $rounds,
                    'current_phase' => $phase,
                    'current_phase_label' => $this->phaseHumanLabel($phase),
                    'current_step' => 'round_start',
                    'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.05),
                ],
                'running'
            );

            foreach ($debateAgents as $agentId) {
                $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
                if (!$agent) continue;
                $this->reportRuntimeEvent(
                    $sessionId,
                    [
                        'level' => 'info',
                        'phase' => $phase,
                        'round' => $round,
                        'agent_id' => $agentId,
                        'label' => $this->agentEventLabel($phase, $agentId, true),
                    ],
                    [
                        'current_round' => $round,
                        'total_rounds' => $rounds,
                        'current_phase' => $phase,
                        'current_phase_label' => $this->phaseHumanLabel($phase),
                        'current_agent_id' => $agentId,
                        'current_step' => 'llm_call',
                        'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.2),
                    ],
                    'running'
                );

                $assignedTarget = ($phase !== 'jury-opening')
                    ? $this->computeAssignedTarget($debateAgents, $agentId, $round)
                    : null;

                try {
                    $votesSnap = $this->voteRepo->findVotesBySession($sessionId);
                    $majority  = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
                    $socialDynBlock = null;
                    if ($round > 1) {
                        $socialDynBlock = $this->socialPrompt->buildUserBlock(
                            $sessionId,
                            $agentId,
                            $majority,
                            $strategicContextId,
                            false
                        );
                    }

                    // Pass accumulated intra-round messages so agents react to each other
                    $contextMessages = array_merge($prevMessages, $roundMessages);

                    $messages = $this->buildJuryMessages(
                        $agent, $objective, $contextMessages,
                        $round, $rounds, $phase,
                        $language, $forceDisagreement, $contextDoc, $sessionId, $assignedTarget,
                        $socialDynBlock, $forceStrongNext, true, $strategicContextId
                    );
                    $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                        $messages,
                        [
                            'session_id' => $sessionId,
                            'strategic_context_id' => $strategicContextId,
                            'round' => $round,
                            'agent_id' => $agentId,
                            'mode' => 'jury',
                        ],
                        'jury_user_payload',
                        'orchestration',
                        'jury_runtime_user_payload',
                        ['phase' => $phase]
                    );
                    $messages = $governed['messages'];
                    $promptMetaJson = $governed['meta_json'];

                    $routed  = $this->providerRouter->chat(
                        $messages,
                        $agent,
                        null,
                        null,
                        $this->resolveAgentOverride($agentProviders, (string)$agentId)
                    );
                    $content = $routed['content'];

                    // ── Adversarial compliance check + single retry ───────────
                    $complianceIssue = $this->ensureAdversarialCompliance(
                        $content, $phase, $agentId, $contextMessages, $adversarialCfg
                    );
                    if ($complianceIssue !== null) {
                        $complianceRetries[] = ['agent' => $agentId, 'round' => $round, 'phase' => $phase, 'issue' => $complianceIssue];
                        $correctedMessages = $this->buildCorrectiveMessages(
                            $content, $complianceIssue, $phase, $agentId, $contextMessages
                        );
                        try {
                            $retried = $this->providerRouter->chat(
                                $correctedMessages,
                                $agent,
                                null,
                                null,
                                $this->resolveAgentOverride($agentProviders, (string)$agentId)
                            );
                            $content = $retried['content'];
                            $routed  = $retried;
                        } catch (\Throwable $retryEx) {
                            error_log('[JuryRunner] Adversarial retry failed: ' . $retryEx->getMessage());
                        }
                    }

                    $targetResolution = ($phase !== 'jury-opening')
                        ? $this->resolveJuryTargetAgent($content, $contextMessages, $agentId, $assignedTarget)
                        : ['target_agent_id' => null, 'edge_source' => 'unknown'];
                    $targetAgentId = $targetResolution['target_agent_id'];

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
                        'phase'                    => $phase,
                        'target_agent_id'          => $targetAgentId,
                        'mode_context'             => 'jury',
                        'message_type'             => $phase,
                        'meta_json'                => $promptMetaJson,
                        'content'                  => $content,
                        'created_at'               => date('c'),
                    ]);
                    $roundMessages[] = $msg;
                    $this->reportRuntimeEvent(
                        $sessionId,
                        [
                            'level' => 'info',
                            'phase' => $phase,
                            'round' => $round,
                            'agent_id' => $agentId,
                            'label' => $this->agentEventLabel($phase, $agentId, false),
                        ],
                        [
                            'current_round' => $round,
                            'total_rounds' => $rounds,
                            'current_phase' => $phase,
                            'current_phase_label' => $this->phaseHumanLabel($phase),
                            'current_agent_id' => $agentId,
                            'current_step' => 'response_received',
                            'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.65),
                        ],
                        'running'
                    );

                    $this->debateMemory->processMessage(
                        $sessionId, $round, $agentId, $content, $targetAgentId, $state, $targetResolution['edge_source']
                    );
                    $this->socialDynamics->ingestAgentResponse(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        $debateAgents,
                        $this->voteRepo->findVotesBySession($sessionId),
                        $state['positions'] ?? [],
                        $strategicContextId
                    );

                    $parsedVote = $this->voteParser->parse($content);
                    if ($parsedVote) {
                        $voteRow = $this->voteRepo->createVote([
                            'id'           => $this->uuid(),
                            'session_id'   => $sessionId,
                            'round'        => $round,
                            'agent_id'     => $agentId,
                            'vote'         => $parsedVote['vote'],
                            'confidence'   => $parsedVote['confidence'],
                            'impact'       => $parsedVote['impact'],
                            'domain_weight'=> $parsedVote['domain_weight'],
                            'weight_score' => $parsedVote['weight_score'],
                            'rationale'    => $parsedVote['rationale'],
                            'created_at'   => date('c'),
                        ]);
                        $allVotes[] = array_merge($voteRow, ['round' => $round]);
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
                        'requested_provider_id'    => null,
                        'requested_model'          => null,
                        'provider_fallback_used'   => 0,
                        'provider_fallback_reason' => null,
                        'round'                    => $round,
                        'phase'                    => $phase,
                        'target_agent_id'          => null,
                        'mode_context'             => 'jury',
                        'message_type'             => $phase,
                        'content'                  => '[Error] ' . $e->getMessage(),
                        'created_at'               => date('c'),
                    ]);
                    $roundMessages[] = $msg;
                    $this->reportRuntimeEvent(
                        $sessionId,
                        [
                            'level' => 'error',
                            'phase' => $phase,
                            'round' => $round,
                            'agent_id' => $agentId,
                            'label' => $this->agentEventLabel($phase, $agentId, false) . ' (erreur)',
                        ],
                        [
                            'current_round' => $round,
                            'total_rounds' => $rounds,
                            'current_phase' => $phase,
                            'current_phase_label' => $this->phaseHumanLabel($phase),
                            'current_agent_id' => $agentId,
                            'current_step' => 'failed',
                            'percent' => RunnerProgressPercent::roundRunPercent($round, $rounds, 0.7),
                        ],
                        'running',
                        (string)$e->getMessage()
                    );
                }
            }

            $allRounds[$round] = $roundMessages;
            $prevMessages      = $roundMessages;

            // Check whether to force strong challenge next round
            if ($round < $totalDebateRounds) {
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
        }

        // ── Sequential round tracking ─────────────────────────────────────────
        $plannedRounds = $rounds; // config value, never changes
        $nextRound     = $rounds; // incremented for each extra phase

        // ── Mini-challenge round (if debate quality is insufficient) ──────────
        $allDebateMessages = array_merge(...array_values($allRounds ?: [[]]));
        $prelimQuality = $this->computeDebateQualityScore($state, count($debateAgents), false);
        if ($adversarialCfg['enabled'] && $this->needsMiniChallengeRound($prelimQuality, $adversarialCfg)) {
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'jury-mini-challenge',
                    'round' => $nextRound,
                    'label' => 'Mini challenge demarre',
                ],
                [
                    'current_round' => $nextRound,
                    'total_rounds' => $rounds,
                    'current_phase' => 'jury-mini-challenge',
                    'current_phase_label' => $this->phaseHumanLabel('jury-mini-challenge'),
                    'current_step' => 'phase_start',
                    'percent' => RunnerProgressPercent::roundRunPercent($nextRound, $rounds, 0.15),
                ],
                'running'
            );
            $miniMessages = $this->runMiniChallengeRound(
                $sessionId, $objective, $debateAgents,
                $prevMessages, $language, $contextDoc, $adversarialCfg, $state, $nextRound, $dynamicsPreset, $agentProviders
            );
            if (!empty($miniMessages)) {
                $allRounds['mini-challenge'] = $miniMessages;
                $prevMessages      = $miniMessages;
                $allDebateMessages = array_merge($allDebateMessages, $miniMessages);
                $nextRound++;
            }
        }

        // ── Minority report phase ─────────────────────────────────────────────
        $minorityReportPresent = false;
        if ($adversarialCfg['enabled'] && $adversarialCfg['require_minority_report']) {
            $currentVotes     = $this->voteRepo->findVotesBySession($sessionId);
            $currentEdges     = $state['edges'] ?? [];
            $minorityAgentIds = $this->identifyMinorityAgents($currentVotes, $debateAgents);

            if (empty($minorityAgentIds)) {
                $forced = $this->forcedMinorityReporter(
                    $debateAgents,
                    $adversarialCfg['minority_reporter_agent_id'] ?? '',
                    $allDebateMessages,
                    $currentVotes,
                    $currentEdges
                );
                if ($forced) {
                    $minorityAgentIds = [$forced];
                }
            }

            $minorityRound        = $nextRound;
            $allDebateForMinority = $allDebateMessages;
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'jury-minority-report',
                    'round' => $minorityRound,
                    'label' => 'Minority report demarre',
                ],
                [
                    'current_round' => $minorityRound,
                    'total_rounds' => $rounds,
                    'current_phase' => 'jury-minority-report',
                    'current_phase_label' => $this->phaseHumanLabel('jury-minority-report'),
                    'current_step' => 'phase_start',
                    'percent' => RunnerProgressPercent::roundRunPercent($minorityRound, $rounds, 0.2),
                ],
                'running'
            );
            foreach (array_slice($minorityAgentIds, 0, 2) as $minorityAgentId) {
                $agent = $this->assembler->assemble($minorityAgentId, null, null, $dynamicsPreset);
                if (!$agent) continue;
                try {
                    $mrMessages = $this->buildMinorityReportMessages(
                        $agent, $objective, $allDebateForMinority, $language
                    );
                    $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                        $mrMessages,
                        [
                            'session_id' => $sessionId,
                            'strategic_context_id' => $strategicContextId,
                            'round' => $minorityRound,
                            'agent_id' => $minorityAgentId,
                            'mode' => 'jury',
                        ],
                        'jury_user_payload',
                        'orchestration',
                        'jury_runtime_user_payload',
                        ['phase' => 'jury-minority-report']
                    );
                    $mrMessages = $governed['messages'];
                    $promptMetaJson = $governed['meta_json'];
                    $routed  = $this->providerRouter->chat(
                        $mrMessages,
                        $agent,
                        null,
                        null,
                        $this->resolveAgentOverride($agentProviders, (string)$minorityAgentId)
                    );
                    $content = $routed['content'];

                    $msg = $this->messageRepo->create([
                        'id'                       => $this->uuid(),
                        'session_id'               => $sessionId,
                        'role'                     => 'assistant',
                        'agent_id'                 => $minorityAgentId,
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
                        'round'                    => $minorityRound,
                        'phase'                    => 'jury-minority-report',
                        'target_agent_id'          => null,
                        'mode_context'             => 'jury',
                        'message_type'             => 'jury-minority-report',
                        'meta_json'                => $promptMetaJson,
                        'content'                  => $content,
                        'created_at'               => date('c'),
                    ]);
                    $allRounds['minority'][] = $msg;
                    $allDebateMessages[]     = $msg;
                    $minorityReportPresent   = true;
                } catch (\Throwable $e) {
                    error_log('[JuryRunner] Minority report failed for ' . $minorityAgentId . ': ' . $e->getMessage());
                }
            }
            if ($minorityReportPresent) {
                $nextRound++;
            }
        }

        // ── Compute quality score (final, with minority report status) ────────
        $qualityData = $this->computeDebateQualityScore($state, count($debateAgents), $minorityReportPresent);

        // ── Compute automatic decision before synthesizer ─────────────────────
        $automaticDecision = $this->voteAggregator->recompute($sessionId, $threshold, $dynamicsPreset);

        // ── Final verdict (synthesizer) ───────────────────────────────────────
        $verdictRound    = $nextRound; // final sequential round number
        $verdictMessages = [];
        $synthAgent = $this->assembler->assemble('synthesizer', null, null, $dynamicsPreset);
        if ($synthAgent) {
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'jury-verdict',
                    'round' => $verdictRound,
                    'agent_id' => 'synthesizer',
                    'label' => 'Synthese jury demarree',
                ],
                [
                    'current_round' => $verdictRound,
                    'total_rounds' => $rounds,
                    'current_phase' => 'jury-verdict',
                    'current_phase_label' => $this->phaseHumanLabel('jury-verdict'),
                    'current_agent_id' => 'synthesizer',
                    'current_step' => 'llm_call',
                    'percent' => 96,
                ],
                'running'
            );
            try {
                $allPrevMessages = $allDebateMessages;
                $messages = $this->buildJuryMessages(
                    $synthAgent, $objective, $allPrevMessages,
                    $verdictRound, $verdictRound, 'jury-verdict',
                    $language, $forceDisagreement, $contextDoc, $sessionId,
                    null, null, false, false, $strategicContextId
                );

                // Inject aggregated vote + quality constraints for adversarial mode
                if ($adversarialCfg['enabled']) {
                    $constraintBlock = $this->buildConstraintBlock($automaticDecision, $qualityData);
                    $messages[1]['content'] .= "\n\n" . $constraintBlock;
                }

                // Inject reliability constraint block (always)
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
                    $preEnvelope = $this->reliabilityService->buildEnvelope(
                        $objective, $contextDoc, $automaticDecision,
                        $this->voteRepo->findVotesBySession($sessionId),
                        $state['positions'] ?? [], $state['edges'] ?? [],
                        $threshold, null, null, null,
                        $preEvidence,
                        null
                    );
                    $juryDebateScore   = (float)($qualityData['score'] ?? 0.0);
                    $preFcData         = $preEnvelope['false_consensus'] ?? [];
                    $preGuardrails     = $this->guardrailService->evaluate(
                        rawDecision:       $preEnvelope['raw_decision'] ?? [],
                        adjustedDecision:  $preEnvelope['adjusted_decision'] ?? [],
                        contextQuality:    $preEnvelope['context_quality'] ?? [],
                        falseConsensus:    $preFcData,
                        debateQualityScore:$juryDebateScore,
                        evidenceReport:    $preEvidence,
                        riskProfile:       null,
                        mode:              'jury',
                        sessionOptions:    []
                    );
                    $synthConstraint   = $this->promptBuilder->buildSynthesizerConstraintBlock(
                        array_merge($preEnvelope, [
                            'adjusted_decision'    => $preEnvelope['adjusted_decision'] ?? [],
                            'debate_quality_score' => $juryDebateScore,
                            'guardrails'           => $preGuardrails,
                            'evidence_report'      => $preEvidence,
                        ])
                    );
                    $formatInstruction = $this->promptBuilder->buildSynthesizerOutputFormatInstruction();
                    $messages[1]['content'] .= $synthConstraint . $formatInstruction;
                } catch (\Throwable $e) {
                    error_log('[JuryRunner] Synthesizer constraint injection failed: ' . $e->getMessage());
                }

                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $messages,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => $strategicContextId,
                        'round' => $verdictRound,
                        'agent_id' => 'synthesizer',
                        'mode' => 'jury',
                    ],
                    'jury_user_payload',
                    'orchestration',
                    'jury_runtime_user_payload',
                    ['phase' => 'jury-verdict']
                );
                $messages = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];

                $routed  = $this->providerRouter->chat(
                    $messages,
                    $synthAgent,
                    null,
                    null,
                    $this->resolveAgentOverride($agentProviders, 'synthesizer')
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
                    'round'                    => $verdictRound,
                    'phase'                    => 'jury-verdict',
                    'target_agent_id'          => null,
                    'mode_context'             => 'jury',
                    'message_type'             => 'jury-verdict',
                    'meta_json'                => $promptMetaJson,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $verdictMessages[] = $msg;
                $this->reportRuntimeEvent(
                    $sessionId,
                    [
                        'level' => 'info',
                        'phase' => 'jury-verdict',
                        'round' => $verdictRound,
                        'agent_id' => 'synthesizer',
                        'label' => 'Synthese jury terminee',
                    ],
                    [
                        'current_round' => $verdictRound,
                        'total_rounds' => $rounds,
                        'current_phase' => 'jury-verdict',
                        'current_phase_label' => $this->phaseHumanLabel('jury-verdict'),
                        'current_agent_id' => 'synthesizer',
                        'current_step' => 'response_received',
                        'percent' => 99,
                    ],
                    'running'
                );

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
                    'round'                    => $verdictRound,
                    'phase'                    => 'jury-verdict',
                    'target_agent_id'          => null,
                    'mode_context'             => 'jury',
                    'message_type'             => 'jury-verdict',
                    'content'                  => '[Error] ' . $e->getMessage(),
                    'created_at'               => date('c'),
                ]);
                $verdictMessages[] = $msg;
                $this->reportRuntimeEvent(
                    $sessionId,
                    [
                        'level' => 'error',
                        'phase' => 'jury-verdict',
                        'round' => $verdictRound,
                        'agent_id' => 'synthesizer',
                        'label' => 'Synthese jury en erreur',
                    ],
                    [
                        'current_round' => $verdictRound,
                        'total_rounds' => $rounds,
                        'current_phase' => 'jury-verdict',
                        'current_phase_label' => $this->phaseHumanLabel('jury-verdict'),
                        'current_agent_id' => 'synthesizer',
                        'current_step' => 'failed',
                        'percent' => 99,
                    ],
                    'running',
                    (string)$e->getMessage()
                );
            }
        }
        $allRounds[$verdictRound] = $verdictMessages;

        $allSessionMessages = $this->messageRepo->findBySession($sessionId);
        $evidenceReport = null;
        try {
            $evidenceReport = $this->evidenceService->generateAndPersist(
                $sessionId, $allSessionMessages, $contextDoc
            );
        } catch (\Throwable $e) {
            error_log('[JuryRunner] Evidence generation failed: ' . $e->getMessage());
        }
        $riskProfile = null;
        try {
            $riskProfile = $this->riskAnalyzer->analyzeAndPersist(
                $sessionId, $objective, 'jury',
                $allSessionMessages, $contextDoc, $threshold, $evidenceReport
            );
        } catch (\Throwable $e) {
            error_log('[JuryRunner] Risk analysis failed: ' . $e->getMessage());
        }
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $automaticDecision,
            $allVotes,
            $state['positions'] ?? [],
            $state['edges'] ?? [],
            $threshold,
            null,
            null,
            null,
            $evidenceReport,
            $riskProfile
        );

        // ── Adversarial warnings + decision override ──────────────────────────
        $adversarialWarnings = $this->computeAdversarialWarnings(
            $qualityData, $adversarialCfg, $reliability, $automaticDecision, $complianceRetries
        );

        // Override adjusted_decision if weak debate
        $adjustedDecision = $reliability['adjusted_decision'] ?? [];
        if ($adversarialCfg['enabled'] && $adversarialCfg['block_weak_debate_decision']) {
            if (($qualityData['score'] ?? 0) < $adversarialCfg['debate_quality_min_score']) {
                $status = $adjustedDecision['decision_status'] ?? '';
                if (!in_array($status, ['FRAGILE', 'INSUFFICIENT_CONTEXT'], true)) {
                    $adjustedDecision['decision_status'] = 'FRAGILE';
                }
                $outcome = $adjustedDecision['final_outcome'] ?? '';
                if (in_array($outcome, ['GO_CONFIDENT', 'NO_GO_CONFIDENT'], true)) {
                    $adjustedDecision['final_outcome'] = str_replace('_CONFIDENT', '_FRAGILE', $outcome);
                }
                $reliability['adjusted_decision'] = $adjustedDecision;
            }
            if (($reliability['false_consensus_risk'] ?? 'low') === 'high' && $adversarialCfg['false_consensus_blocks_decision']) {
                $outcome = $adjustedDecision['final_outcome'] ?? '';
                if ($outcome === 'GO_CONFIDENT') {
                    $adjustedDecision['final_outcome'] = 'NO_CONSENSUS_FRAGILE';
                    $reliability['adjusted_decision'] = $adjustedDecision;
                }
            }
        }

        $juryDebateScore = (float)($qualityData['score'] ?? 0.0);

        $guardrails = $this->guardrailService->evaluate(
            rawDecision:       $reliability['raw_decision'] ?? [],
            adjustedDecision:  $adjustedDecision,
            contextQuality:    $reliability['context_quality'] ?? [],
            falseConsensus:    $reliability['false_consensus'] ?? [],
            debateQualityScore:$juryDebateScore,
            evidenceReport:    $evidenceReport ?? null,
            riskProfile:       $riskProfile ?? null,
            mode:              'jury',
            sessionOptions:    ['auto_retry_on_weak_debate' => $adversarialCfg['auto_retry_on_weak_debate']]
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
            foreach ($verdictMessages as $m) {
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
            $adjustedDecision['final_outcome'] = $guardrails['final_outcome_override'];
            $reliability['adjusted_decision']  = $adjustedDecision;
        }

        $autoRetryResult = ['triggered' => false];
        $retryRuntimeTraces = [];

        if (($guardrails['should_auto_retry'] ?? false) === true) {
            $initialScore = $juryDebateScore;
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'warning',
                    'phase' => 'auto_retry_started',
                    'round' => $verdictRound + 1,
                    'label' => 'Auto-retry demarre',
                    'metadata' => [
                        'reason' => 'weak_parallel_debate',
                        'initial_score' => $initialScore,
                    ],
                ],
                [
                    'current_round' => $verdictRound + 1,
                    'total_rounds' => $rounds + 1,
                    'current_phase' => 'auto_retry_started',
                    'current_phase_label' => 'Auto-retry demarre',
                    'current_step' => 'retry',
                    'percent' => 96,
                ],
                'running'
            );

            $retryInstruction = $this->promptBuilder->buildAutoRetryAdversarialPrompt($initialScore);

            $existingMessages = $this->messageRepo->findBySession($sessionId);
            $historyText = implode("\n\n", array_map(
                fn($m) => "[{$m['agent_id']}]: {$m['content']}",
                $existingMessages
            ));
            $userMsg = $retryInstruction . "\n\nPrevious debate:\n\n" . $historyText;

            foreach ($debateAgents as $agentId) {
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
                            'strategic_context_id' => $strategicContextId,
                            'round' => $verdictRound + 1,
                            'agent_id' => $agentId,
                            'mode' => 'jury',
                        ],
                        'jury_user_payload',
                        'orchestration',
                        'jury_retry_round_payload',
                        ['phase' => 'retry-round']
                    );
                    $retryMessages = $governedRetry['messages'];
                    $retryMetaJson = $governedRetry['meta_json'];
                    if (is_array($governedRetry['trace'] ?? null)) {
                        $retryRuntimeTraces[] = $governedRetry['trace'];
                    }
                    $routed = $this->providerRouter->chat(
                        $retryMessages,
                        $agent,
                        null,
                        null,
                        $this->resolveAgentOverride($agentProviders, (string)$agentId)
                    );
                    $this->messageRepo->create([
                        'id'           => $this->uuid(),
                        'session_id'   => $sessionId,
                        'role'         => 'assistant',
                        'agent_id'     => $agentId,
                        'round'        => $verdictRound + 1,
                        'phase'        => 'retry-round',
                        'mode_context' => 'jury',
                        'message_type' => 'retry-round',
                        'provider_id'   => $routed['provider_id'] ?? null,
                        'provider_name' => $routed['provider_name'] ?? null,
                        'model'         => $routed['model'] ?? null,
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
                        'meta_json'    => $retryMetaJson,
                        'content'      => $routed['content'] ?? '',
                        'created_at'   => date('c'),
                    ]);
                } catch (\Throwable $e) {
                    error_log('[JuryRunner] Auto-retry agent failed: ' . $e->getMessage());
                }
            }

            $allVotesRetry     = $this->voteRepo->findVotesBySession($sessionId);
            $newFalseConsensus = $this->falseConsensusDetector->detect(
                $reliability['context_quality'] ?? [],
                $state['positions'] ?? [],
                $state['edges'] ?? [],
                $allVotesRetry
            );
            $newScore = (float)(($newFalseConsensus['diversity_score'] ?? 0.5) * 100);

            $guardrails = $this->guardrailService->evaluate(
                rawDecision:       $reliability['raw_decision'] ?? [],
                adjustedDecision:  $adjustedDecision,
                contextQuality:    $reliability['context_quality'] ?? [],
                falseConsensus:    $newFalseConsensus,
                debateQualityScore:$newScore,
                evidenceReport:    null,
                riskProfile:       null,
                mode:              'jury',
                sessionOptions:    [] // empty: max 1 retry
            );

            $autoRetryResult = [
                'triggered'                    => true,
                'reason'                       => 'weak_parallel_debate',
                'initial_debate_quality_score' => $initialScore,
                'retry_debate_quality_score'   => $newScore,
                'extra_rounds'                 => 1,
            ];
            $this->reportRuntimeEvent(
                $sessionId,
                [
                    'level' => 'info',
                    'phase' => 'auto_retry_completed',
                    'round' => $verdictRound + 1,
                    'label' => 'Auto-retry termine',
                    'metadata' => [
                        'new_score' => $newScore,
                    ],
                ],
                [
                    'current_round' => $verdictRound + 1,
                    'total_rounds' => $rounds + 1,
                    'current_phase' => 'auto_retry_completed',
                    'current_phase_label' => 'Auto-retry termine',
                    'current_step' => 'retry_done',
                    'percent' => 98,
                ],
                'running'
            );
        }

        $finalJuryFc    = $newFalseConsensus ?? ($reliability['false_consensus'] ?? []);
        $finalJuryScore = $newScore ?? $juryDebateScore;
        $qualityScore = $this->qualityScoreService->compute(
            contextQuality:     $reliability['context_quality'] ?? [],
            debateQualityScore: $finalJuryScore,
            evidenceReport:     $evidenceReport,
            riskProfile:        $riskProfile,
            falseConsensus:     $finalJuryFc
        );

        $synthesizerOutput = $verdictMessages[0]['content'] ?? '';
        $parsedVerdictBrief = VerdictParser::parse($synthesizerOutput, $playbookId);
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

        // Heuristic fallback fields used by DecisionSummaryService::buildDecisionBrief when
        // the synthesizer output sections are missing or differently formatted (jury often is).
        $heuristicBrief = $this->summaryService->build(
            ['title' => 'Jury'],
            $parsedVerdictBrief ?: null,
            $automaticDecision,
            $allVotes,
            $state['arguments'] ?? []
        );
        $keyFactorsForBrief = array_map(
            fn($t) => ['text' => $t],
            $heuristicBrief['key_factors'] ?? []
        );

        $decisionBrief = $this->summaryService->buildDecisionBrief(
            array_merge($reliability, [
                'synthesizer_output'     => $synthesizerOutput,
                'key_factors'            => $keyFactorsForBrief,
                'guardrails'             => $guardrails,
                'decision_quality_score' => $qualityScore,
                'risk_profile'           => $riskProfile,
                'evidence_report'        => $evidenceReport,
                'verdict'                => $parsedVerdictBrief ?: null,
                'canonical_synthesis'    => $canonicalSynthesis,
                'playbook_runtime'        => $playbookDiagnostics,
                'decision_outcome'        => $decisionOutcome,
            ])
        );

        $juryAdversarial = [
            'enabled'                 => $adversarialCfg['enabled'],
            'config'                  => $adversarialCfg,
            'debate_quality_score'    => $qualityData['score'],
            'challenge_count'         => $qualityData['challenge_count'],
            'challenge_ratio'         => $qualityData['challenge_ratio'],
            'position_changes'        => $qualityData['position_changes'],
            'position_changers'       => $qualityData['position_changers'],
            'minority_report_present' => $qualityData['minority_report_present'],
            'interaction_density'     => $qualityData['interaction_density'],
            'most_challenged_agent'   => $qualityData['most_challenged_agent'],
            'warnings'                => $adversarialWarnings,
            'compliance_retries'      => count($complianceRetries),
            'planned_rounds'          => $plannedRounds,
            'executed_rounds'         => $verdictRound,
        ];

        // Persist for session history / refresh survival
        try {
            $this->adversarialRepo->saveForSession($sessionId, $juryAdversarial);
        } catch (\Throwable $e) {
            error_log('[JuryRunner] Failed to persist jury_adversarial: ' . $e->getMessage());
        }

        $runtimeTraces = CognitiveRuntimeGovernance::collectTracesFromMessageBuckets(array_merge(
            array_values($allRounds),
            [$verdictMessages]
        ));
        $runtimeTraces = array_merge($runtimeTraces, $retryRuntimeTraces);

        return StructuredRunResult::augment(array_merge([
            'session_id'                  => $sessionId,
            'rounds'                      => $allRounds,
            'synthesis'                   => $verdictMessages,
            'verdict'                     => null,
            'total_rounds'                => $rounds,
            'arguments'                   => $state['arguments'] ?? [],
            'positions'                   => $state['positions'] ?? [],
            'interaction_edges'           => $state['edges'] ?? [],
            'votes'                       => $allVotes,
            'automatic_decision'          => $automaticDecision,
            'threshold'                   => $threshold,
            'raw_decision'                => $reliability['raw_decision'],
            'adjusted_decision'           => $reliability['adjusted_decision'],
            'memory_summary'              => $reliability['memory_summary'] ?? null,
            'context_quality'             => $reliability['context_quality'],
            'reliability_cap'             => $reliability['reliability_cap'],
            'false_consensus_risk'        => $reliability['false_consensus_risk'],
            'false_consensus'             => $reliability['false_consensus'],
            'reliability_warnings'        => $reliability['reliability_warnings'],
            'decision_reliability_summary'=> $reliability['decision_reliability_summary'] ?? null,
            'context_clarification'       => $reliability['context_clarification'] ?? null,
            'evidence_report'             => $evidenceReport,
            'risk_profile'                => $riskProfile,
            'risk_threshold_info'         => $reliability['risk_threshold_info'] ?? null,
            'jury_adversarial'            => $juryAdversarial,
            'guardrails'                  => $guardrails,
            'auto_retry'                  => $autoRetryResult,
            'decision_quality_score'      => $qualityScore,
            'decision_brief'              => $decisionBrief,
            'canonical_synthesis'         => $canonicalSynthesis,
            'decision_outcome'            => $decisionOutcome,
            'playbook_runtime'            => $playbookDiagnostics,
        ], CognitiveRuntimeGovernance::summarizeTraces($runtimeTraces, 'jury')));
    }

    // ── Config ────────────────────────────────────────────────────────────────

    private function normalizeAdversarialConfig(array $input): array {
        return [
            'enabled'                         => (bool)($input['jury_adversarial_enabled'] ?? true),
            'min_challenges_per_round'        => max(1, min(5, (int)($input['min_challenges_per_round'] ?? 2))),
            'force_agent_references'          => (bool)($input['force_agent_references'] ?? true),
            'require_position_change_check'   => (bool)($input['require_position_change_check'] ?? true),
            'require_minority_report'         => (bool)($input['require_minority_report'] ?? true),
            'block_weak_debate_decision'      => (bool)($input['block_weak_debate_decision'] ?? true),
            'debate_quality_min_score'        => max(0, min(100, (int)($input['debate_quality_min_score'] ?? 50))),
            'false_consensus_blocks_decision' => (bool)($input['false_consensus_blocks_confident_decision'] ?? true),
            'auto_retry_on_weak_debate'       => (bool)($input['auto_retry_on_weak_debate'] ?? false),
            // Explicit minority reporter: empty string = auto-detect
            'minority_reporter_agent_id'      => isset($input['minority_reporter_agent_id'])
                ? (string)$input['minority_reporter_agent_id'] : '',
        ];
    }

    // ── Phase resolution ──────────────────────────────────────────────────────

    private function resolveJuryPhase(int $round, int $totalRounds, array $adversarialCfg): string {
        $totalDebateRounds = $totalRounds - 1;
        if ($round === 1) return 'jury-opening';
        if (!$adversarialCfg['enabled']) {
            return $round === 2 ? 'jury-cross-examination' : 'jury-deliberation';
        }
        if ($round === 2) return 'jury-cross-examination';
        if ($round === 3 && $totalDebateRounds >= 3) return 'jury-defense';
        return 'jury-deliberation';
    }

    // Backward-compatible alias
    private function resolvePhase(int $round, int $totalRounds): string {
        if ($round === 1) return 'jury-opening';
        if ($round === 2) return 'jury-cross-examination';
        return 'jury-deliberation';
    }

    // ── Adversarial compliance ────────────────────────────────────────────────

    /**
     * Returns a string describing compliance issues, or null if compliant.
     * Max one retry per message is enforced by the caller.
     */
    private function ensureAdversarialCompliance(
        string $content,
        string $phase,
        string $agentId,
        array  $contextMessages,
        array  $adversarialCfg
    ): ?string {
        if (!$adversarialCfg['enabled']) return null;

        $issues = [];

        // Too short
        $wordCount = str_word_count(strip_tags($content));
        if ($wordCount < 40) {
            $issues[] = 'response too short (' . $wordCount . ' words)';
        }

        // Cross-examination: must target an agent and challenge a claim
        if ($phase === 'jury-cross-examination') {
            if (!preg_match('/##\s*Target Agent/i', $content)) {
                $issues[] = 'missing ## Target Agent section';
            }
            if (!preg_match('/##\s*Challenge/i', $content) && !$this->hasExplicitChallenge($content)) {
                $issues[] = 'missing explicit challenge';
            }
        }

        // Defense: must respond to challenges received
        if ($phase === 'jury-defense') {
            if (!preg_match('/##\s*Response To Challenges/i', $content) &&
                !$this->hasDefenseMarkers($content)) {
                $issues[] = 'missing ## Response To Challenges section';
            }
        }

        // Agent reference check (not in opening)
        if ($phase !== 'jury-opening' && $adversarialCfg['force_agent_references'] && !empty($contextMessages)) {
            $prevAgentIds = array_values(array_unique(array_filter(
                array_column($contextMessages, 'agent_id'),
                fn($id) => !empty($id) && $id !== $agentId
            )));
            $found = false;
            foreach ($prevAgentIds as $id) {
                if (stripos($content, $id) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found && !empty($prevAgentIds)) {
                $issues[] = 'no reference to other agents';
            }
        }

        return empty($issues) ? null : implode('; ', $issues);
    }

    private function hasExplicitChallenge(string $content): bool {
        $lower = mb_strtolower($content, 'UTF-8');
        $keywords = [
            'i challenge', 'i disagree', 'this is incorrect', 'weak assumption',
            'unsupported claim', 'unsupported assumption', 'missing evidence',
            'invalid assumption', 'i contest', 'this claim fails',
            'je conteste', 'je réfute', 'hypothèse faible', 'preuve manquante',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    private function hasDefenseMarkers(string $content): bool {
        $lower = mb_strtolower($content, 'UTF-8');
        return str_contains($lower, 'in response to')
            || str_contains($lower, 'responding to')
            || str_contains($lower, 'addressing the challenge')
            || str_contains($lower, 'to address')
            || str_contains($lower, 'defense:')
            || str_contains($lower, 'revised position:')
            || str_contains($lower, 'position changed:')
            || str_contains($lower, 'en réponse à')
            || str_contains($lower, 'je maintiens');
    }

    private function buildCorrectiveMessages(
        string $originalContent,
        string $issues,
        string $phase,
        string $agentId,
        array  $contextMessages
    ): array {
        $system = "You are participating in an adversarial jury deliberation. Your previous answer did not comply with the required format.";

        $user = "Your previous answer had the following issues: {$issues}\n\n";
        $user .= "You must now provide a corrected response. Do not summarize generally.\n\n";

        if ($phase === 'jury-cross-examination') {
            $prevAgentIds = array_values(array_unique(array_filter(
                array_column($contextMessages, 'agent_id'),
                fn($id) => !empty($id) && $id !== $agentId
            )));
            $target = !empty($prevAgentIds) ? $prevAgentIds[0] : 'the previous agent';
            $user .= "You MUST begin your response with:\n\n"
                . "## Target Agent\n{$target}\n\n"
                . "## Challenge\n"
                . "- Claim challenged: [state the specific claim from {$target}]\n"
                . "- Why it is weak: [explain the weakness]\n"
                . "- Evidence or assumption missing: [what is needed]\n"
                . "- What would change your mind: [be specific]\n\n";
        }

        if ($phase === 'jury-defense') {
            $user .= "You MUST begin your response with:\n\n"
                . "## Response To Challenges\n"
                . "- Challenge acknowledged: [which challenge you received]\n"
                . "- Defense: [your defense of your position]\n"
                . "- Revision: [what you revise, if anything]\n"
                . "- Position changed: yes|no\n\n";
        }

        $user .= "Your previous response (do NOT simply repeat it):\n\n" . mb_substr($originalContent, 0, 800, 'UTF-8') . "\n\n";
        $user .= "Now provide the corrected, compliant response. Include your vote at the end.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ── Mini-challenge round ──────────────────────────────────────────────────

    private function needsMiniChallengeRound(array $qualityData, array $adversarialCfg): bool {
        if ($qualityData['score'] >= $adversarialCfg['debate_quality_min_score']) return false;
        if ($qualityData['challenge_ratio'] >= 0.30) return false;
        return true;
    }

    private function runMiniChallengeRound(
        string $sessionId,
        string $objective,
        array  $debateAgents,
        array  $prevMessages,
        string $language,
        ?array $contextDoc,
        array  $adversarialCfg,
        array  &$state,
        int    $miniChallengeRound,
        ?string $dynamicsPreset,
        array  $agentProviders
    ): array {
        $miniMessages = [];
        $langNote = $language !== 'en' ? " Respond in language code: $language." : '';

        foreach (array_slice($debateAgents, 0, 3) as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            if (!$agent) continue;

            $assignedTarget = $this->computeAssignedTarget($debateAgents, $agentId, $miniChallengeRound);
            $personaName  = $agent->persona->name ?? $agentId;
            $personaTitle = $agent->persona->title ?: $personaName;

            $system = "You are {$personaName}, a {$personaTitle} in an adversarial jury.{$langNote}";

            $user = "**Objective:** {$objective}\n\n";
            if (!empty($prevMessages)) {
                $user .= "**Previous jury contributions:**\n";
                foreach ($prevMessages as $msg) {
                    $user .= "\n**[" . ($msg['agent_id'] ?? 'Agent') . "]**: " . mb_substr((string)($msg['content'] ?? ''), 0, 400, 'UTF-8') . "\n";
                }
                $user .= "\n";
            }

            $user .= "**The debate quality is currently weak.**\n\n";
            $user .= "You must now produce a direct challenge to the weakest claim made by **[{$assignedTarget}]**.\n\n";
            $user .= "Do NOT agree generally. Target one specific claim and challenge it.\n\n";
            $user .= "## Target Agent\n{$assignedTarget}\n\n";
            $user .= "## Challenge\n";
            $user .= "- Claim challenged:\n- Why it is weak:\n- Evidence or assumption missing:\n- What would change your mind:\n\n";
            $user .= "---\n\n# Final Vote\n\n## Vote\ngo | no-go | reduce-scope | needs-more-info | pivot\n\n## Confidence\n0-10\n\n## Rationale\n...\n";

            try {
                $miniPrompt = [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ];
                $governed = CognitiveRuntimeGovernance::tracePromptPayload(
                    $miniPrompt,
                    [
                        'session_id' => $sessionId,
                        'strategic_context_id' => null,
                        'round' => $miniChallengeRound,
                        'agent_id' => $agentId,
                        'mode' => 'jury',
                    ],
                    'jury_user_payload',
                    'orchestration',
                    'jury_runtime_user_payload',
                    ['phase' => 'jury-mini-challenge']
                );
                $miniPrompt = $governed['messages'];
                $promptMetaJson = $governed['meta_json'];
                $routed  = $this->providerRouter->chat(
                    $miniPrompt,
                    $agent,
                    null,
                    null,
                    $this->resolveAgentOverride($agentProviders, (string)$agentId)
                );
                $content = $routed['content'];
                $targetResolution = $this->resolveJuryTargetAgent($content, $prevMessages, $agentId, $assignedTarget);

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
                    'round'                    => $miniChallengeRound,
                    'phase'                    => 'jury-mini-challenge',
                    'target_agent_id'          => $targetResolution['target_agent_id'],
                    'mode_context'             => 'jury',
                    'message_type'             => 'jury-mini-challenge',
                    'meta_json'                => $promptMetaJson,
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $miniMessages[] = $msg;

                $this->debateMemory->processMessage(
                    $sessionId,
                    $miniChallengeRound,
                    $agentId,
                    $content,
                    $targetResolution['target_agent_id'],
                    $state,
                    $targetResolution['edge_source']
                );

                $parsedVote = $this->voteParser->parse($content);
                if ($parsedVote) {
                    $this->voteRepo->createVote([
                        'id'           => $this->uuid(),
                        'session_id'   => $sessionId,
                        'round'        => $miniChallengeRound,
                        'agent_id'     => $agentId,
                        'vote'         => $parsedVote['vote'],
                        'confidence'   => $parsedVote['confidence'],
                        'impact'       => $parsedVote['impact'],
                        'domain_weight'=> $parsedVote['domain_weight'],
                        'weight_score' => $parsedVote['weight_score'],
                        'rationale'    => $parsedVote['rationale'],
                        'created_at'   => date('c'),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[JuryRunner] Mini-challenge failed for ' . $agentId . ': ' . $e->getMessage());
            }
        }

        return $miniMessages;
    }

    // ── Minority report ───────────────────────────────────────────────────────

    private function identifyMinorityAgents(array $votes, array $debateAgents): array {
        if (empty($votes)) return [];

        $tallies = [];
        foreach ($votes as $v) {
            $label = $v['vote'] ?? '';
            if ($label !== '') $tallies[$label] = ($tallies[$label] ?? 0) + 1;
        }
        arsort($tallies);
        $winningLabel = array_key_first($tallies) ?? 'go';

        // Latest vote per agent
        $latestVoteByAgent = [];
        foreach ($votes as $v) {
            $aid = $v['agent_id'] ?? '';
            if ($aid === '') continue;
            if (!isset($latestVoteByAgent[$aid]) ||
                (int)($v['round'] ?? 0) >= (int)($latestVoteByAgent[$aid]['round'] ?? 0)) {
                $latestVoteByAgent[$aid] = $v;
            }
        }

        $minority = [];
        foreach ($latestVoteByAgent as $agentId => $v) {
            if (in_array($agentId, $debateAgents, true) && ($v['vote'] ?? '') !== $winningLabel) {
                $minority[] = $agentId;
            }
        }
        return $minority;
    }

    /**
     * Select the minority / devil-advocate reporter using a 4-priority cascade:
     *  1. Explicit payload override (minority_reporter_agent_id).
     *  2. Persona role semantics: team=red, adversarial tags.
     *  3. Name/title/id heuristic keywords.
     *  4. Best dissent score (most challenge edges + minority votes).
     * Never selects the synthesizer unless it is the only option.
     */
    private function forcedMinorityReporter(
        array  $debateAgents,
        string $explicitAgentId = '',
        array  $messages        = [],
        array  $votes           = [],
        array  $edges           = []
    ): ?string {
        // Remove synthesizer from candidates; keep as absolute last-resort
        $candidates = array_values(array_filter($debateAgents, fn($a) => $a !== 'synthesizer'));
        if (empty($candidates)) {
            return in_array('synthesizer', $debateAgents, true) ? 'synthesizer' : null;
        }

        // ── Priority 1 : explicit payload ────────────────────────────────────
        $trimmed = trim($explicitAgentId);
        if ($trimmed !== '' && in_array($trimmed, $candidates, true)) {
            return $trimmed;
        }
        if ($trimmed !== '') {
            error_log('[JuryRunner] minority_reporter_agent_id "' . $trimmed . '" not in session agents; falling back.');
        }

        // ── Priority 2 : persona metadata (team=red, adversarial tags) ────────
        $redTeamTags   = ['adversarial', 'risk', 'criticism', 'contrarian', 'challenger', 'red-team'];
        $dissentRoleIds = ['devil_advocate', 'devil-advocate', 'critic', 'contrarian', 'challenger', 'red'];
        $dynamicsPreset = null;

        foreach ($candidates as $agentId) {
            if (in_array(strtolower($agentId), $dissentRoleIds, true)) {
                return $agentId;
            }
        }

        foreach ($candidates as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            if (!$agent) continue;
            $meta = $agent->persona->meta;

            // team = red
            if (strtolower((string)($meta['team'] ?? '')) === 'red') {
                return $agentId;
            }
            // tags intersection with adversarial tags
            foreach ((array)($meta['tags'] ?? []) as $tag) {
                if (in_array(strtolower((string)$tag), $redTeamTags, true)) {
                    return $agentId;
                }
            }
        }

        // ── Priority 3 : heuristic on name / title / id ───────────────────────
        $dissentTerms = ['devil', 'advocate', 'critic', 'critique', 'contrarian',
                         'challenger', 'risk', 'risque', 'qa', 'quality', 'red', 'opposition'];

        foreach ($candidates as $agentId) {
            $agent = $this->assembler->assemble($agentId, null, null, $dynamicsPreset);
            $haystack = strtolower(
                $agentId . ' '
                . ($agent?->persona->name ?? '') . ' '
                . ($agent?->persona->title ?? '')
            );
            foreach ($dissentTerms as $term) {
                if (str_contains($haystack, $term)) {
                    return $agentId;
                }
            }
        }

        // ── Priority 4 : best dissent score ──────────────────────────────────
        $scores = $this->computeDissentScores($candidates, $messages, $votes, $edges);
        arsort($scores);
        $best = array_key_first($scores);
        if ($best !== null && ($scores[$best] ?? 0) > 0) {
            return $best;
        }

        // Absolute fallback
        error_log('[JuryRunner] forcedMinorityReporter: no adversarial persona found; using last agent as fallback.');
        return end($candidates) ?: null;
    }

    /**
     * Score each agent by adversarial behaviour:
     *  +2 per outgoing challenge edge
     *  +3 if their latest vote differs from the majority
     */
    private function computeDissentScores(
        array $agentIds,
        array $messages,
        array $votes,
        array $edges
    ): array {
        $scores = array_fill_keys($agentIds, 0.0);

        // Challenge edges
        foreach ($edges as $edge) {
            $src = $edge['source_agent_id'] ?? '';
            if (isset($scores[$src]) && ($edge['edge_type'] ?? '') === 'challenge') {
                $scores[$src] += 2.0;
            }
        }

        // Majority vote label
        $tallies = [];
        foreach ($votes as $v) {
            $label = $v['vote'] ?? '';
            if ($label !== '') $tallies[$label] = ($tallies[$label] ?? 0) + 1;
        }
        arsort($tallies);
        $winningLabel = (string)(array_key_first($tallies) ?? '');

        // Latest vote per agent
        $latestVoteByAgent = [];
        foreach ($votes as $v) {
            $aid = $v['agent_id'] ?? '';
            if ($aid === '') continue;
            if (!isset($latestVoteByAgent[$aid]) ||
                (int)($v['round'] ?? 0) >= (int)($latestVoteByAgent[$aid]['round'] ?? 0)) {
                $latestVoteByAgent[$aid] = $v;
            }
        }

        foreach ($latestVoteByAgent as $agentId => $v) {
            if (isset($scores[$agentId]) && ($v['vote'] ?? '') !== $winningLabel) {
                $scores[$agentId] += 3.0;
            }
        }

        return $scores;
    }

    private function buildMinorityReportMessages(
        \Domain\Agents\Agent $agent,
        string $objective,
        array  $allPrevMessages,
        string $language
    ): array {
        $personaName  = $agent->persona->name ?? $agent->id;
        $personaTitle = $agent->persona->title ?: $personaName;
        $langNote     = $language !== 'en' ? " Respond in language code: $language." : '';

        $system = "You are {$personaName}, a {$personaTitle} in a jury deliberation.{$langNote}"
            . " You are a dissenting voice. Your job is to clearly state why the majority view may be wrong or incomplete.";
        $system .= $this->promptBuilder->buildArgumentDisciplineSystemBlock();

        $user = "**Objective:** {$objective}\n\n";
        $user .= $this->promptBuilder->buildPlaybookDebateDisciplineBlock('jury', $language);
        if (!empty($allPrevMessages)) {
            $user .= "**The jury's contributions so far:**\n";
            foreach (array_slice($allPrevMessages, -6) as $msg) {
                $user .= "\n**[" . ($msg['agent_id'] ?? 'Agent') . "]** (" . ($msg['phase'] ?? '') . "): "
                    . mb_substr((string)($msg['content'] ?? ''), 0, 400, 'UTF-8') . "\n";
            }
            $user .= "\n";
            $user .= $this->promptBuilder->buildRepetitionReductionBlock($allPrevMessages, $language);
        }

        $user .= "**Minority Report task:**\n\n";
        $user .= "As a dissenting or minority voice, you must now write a formal Minority Report.\n\n";
        $user .= "## Minority Report\n\n";
        $user .= "**Core disagreement:**\n[Explicitly state what you disagree with]\n\n";
        $user .= "**Why the majority view is insufficient:**\n[Explain your reasoning]\n\n";
        $user .= "**Evidence or risks the majority ignored:**\n[List at least one]\n\n";
        $user .= "**Recommended alternative action:**\n[What should be done instead]\n\n";
        $user .= "**My final position:** I maintain my dissenting view.\n\n";
        $user .= "---\n\n# Final Vote\n\n## Vote\ngo | no-go | reduce-scope | needs-more-info | pivot\n\n## Confidence\n0-10\n\n## Rationale\n...";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    // ── Quality score ─────────────────────────────────────────────────────────

    private function computeDebateQualityScore(array $state, int $agentCount, bool $minorityReportPresent): array {
        $edges     = $state['edges'] ?? [];
        $positions = $state['positions'] ?? [];

        $totalEdges    = count($edges);
        $challengeEdges = count(array_filter($edges, fn($e) => ($e['edge_type'] ?? '') === 'challenge'));
        $challengeRatio = $totalEdges > 0 ? $challengeEdges / $totalEdges : 0.0;
        $challengeScore = (int)round($challengeRatio * 40);

        $positionChangers = $this->computePositionChangers($positions);
        $positionChangerCount = count($positionChangers);
        $positionScore = min(20, $positionChangerCount * 7);

        $minorityScore = $minorityReportPresent ? 20 : 0;

        $interactingPairs = $totalEdges > 0
            ? count(array_unique(array_map(
                fn($e) => min($e['source_agent_id'] ?? '', $e['target_agent_id'] ?? '')
                         . '_' .
                         max($e['source_agent_id'] ?? '', $e['target_agent_id'] ?? ''),
                $edges
              )))
            : 0;
        $maxPairs = max(1, ($agentCount * ($agentCount - 1)) / 2);
        $densityRatio = $interactingPairs / $maxPairs;
        $densityScore = (int)round(min(1.0, $densityRatio) * 20);

        $total = min(100, $challengeScore + $positionScore + $minorityScore + $densityScore);

        // Most challenged agent
        $challengesByTarget = [];
        foreach ($edges as $e) {
            if (($e['edge_type'] ?? '') === 'challenge' && !empty($e['target_agent_id'])) {
                $tid = (string)$e['target_agent_id'];
                $challengesByTarget[$tid] = ($challengesByTarget[$tid] ?? 0) + 1;
            }
        }
        arsort($challengesByTarget);
        $mostChallengedAgent = !empty($challengesByTarget) ? array_key_first($challengesByTarget) : null;

        return [
            'score'                   => $total,
            'challenge_count'         => $challengeEdges,
            'challenge_ratio'         => round($challengeRatio, 2),
            'position_changes'        => $positionChangerCount,
            'position_changers'       => $positionChangers,
            'minority_report_present' => $minorityReportPresent,
            'interaction_density'     => round($densityRatio, 2),
            'most_challenged_agent'   => $mostChallengedAgent,
            'challenge_score'         => $challengeScore,
            'position_score'          => $positionScore,
            'minority_score'          => $minorityScore,
            'density_score'           => $densityScore,
        ];
    }

    private function computePositionChangers(array $positions): array {
        $firstByAgent = [];
        $lastByAgent  = [];
        foreach ($positions as $pos) {
            $agentId = $pos['agent_id'] ?? '';
            $round   = (int)($pos['round'] ?? 0);
            if ($agentId === '' || $agentId === 'synthesizer') continue;
            if (!isset($firstByAgent[$agentId]) || $round < (int)($firstByAgent[$agentId]['round'] ?? PHP_INT_MAX)) {
                $firstByAgent[$agentId] = $pos;
            }
            if (!isset($lastByAgent[$agentId]) || $round >= (int)($lastByAgent[$agentId]['round'] ?? 0)) {
                $lastByAgent[$agentId] = $pos;
            }
        }
        $changers = [];
        foreach ($lastByAgent as $agentId => $last) {
            $first = $firstByAgent[$agentId] ?? null;
            if ($first && ($first['stance'] ?? '') !== '' && ($last['stance'] ?? '') !== ''
                && ($first['stance'] ?? '') !== ($last['stance'] ?? '')) {
                $changers[$agentId] = [
                    'from' => $first['stance'] ?? '',
                    'to'   => $last['stance'] ?? '',
                ];
            }
        }
        return $changers;
    }

    // ── Adversarial warnings ──────────────────────────────────────────────────

    private function computeAdversarialWarnings(
        array  $qualityData,
        array  $adversarialCfg,
        array  $reliability,
        ?array $automaticDecision,
        array  $complianceRetries
    ): array {
        if (!$adversarialCfg['enabled']) return [];

        $warnings = [];

        if (($qualityData['score'] ?? 0) < $adversarialCfg['debate_quality_min_score']) {
            $warnings[] = 'weak_debate_quality';
        }
        if (($qualityData['challenge_ratio'] ?? 0) < 0.20) {
            $warnings[] = 'insufficient_challenge';
        }
        if (!empty($complianceRetries)) {
            $warnings[] = 'parallel_answers_detected';
        }
        if (($reliability['false_consensus_risk'] ?? 'low') === 'high') {
            $warnings[] = 'false_consensus_risk_high';
        }
        $decisionLabel = $automaticDecision['decision_label'] ?? '';
        if ($decisionLabel === 'no-consensus') {
            $warnings[] = 'no_consensus_reached';
        }
        // Always tag when synthesis was constrained
        $warnings[] = 'synthesis_constrained_by_vote';

        return array_values(array_unique($warnings));
    }

    // ── Synthesizer constraint block ──────────────────────────────────────────

    private function buildConstraintBlock(?array $automaticDecision, array $qualityData): string {
        $voteSum      = $automaticDecision['vote_summary'] ?? [];
        $scores       = $voteSum['decision_scores'] ?? [];
        $winningLabel = $voteSum['winning_label'] ?? 'unknown';
        $winningScore = isset($scores[$winningLabel]) ? (int)round((float)$scores[$winningLabel] * 100) : 0;
        $threshold    = $automaticDecision['threshold_used'] ?? 0.55;
        $decisionLabel = $automaticDecision['decision_label'] ?? 'no-consensus';
        $confidence    = $automaticDecision['confidence_level'] ?? 'low';

        $qualScore      = $qualityData['score'] ?? 0;
        $challengeRatio = (int)round(($qualityData['challenge_ratio'] ?? 0) * 100);
        $posChanges     = $qualityData['position_changes'] ?? 0;
        $density        = $qualityData['interaction_density'] ?? 0;
        $minority       = ($qualityData['minority_report_present'] ?? false) ? 'yes' : 'no';

        $block  = "---\n\n";
        $block .= "## Aggregated Vote Result\n";
        $block .= "- winning_label: {$winningLabel}\n";
        $block .= "- winning_score: {$winningScore}%\n";
        $block .= "- threshold: " . (int)round($threshold * 100) . "%\n";
        $block .= "- decision_label: {$decisionLabel}\n";
        $block .= "- confidence: {$confidence}\n\n";
        $block .= "## Debate Quality\n";
        $block .= "- score: {$qualScore}/100\n";
        $block .= "- challenge_ratio: {$challengeRatio}%\n";
        $block .= "- position_changes: {$posChanges}\n";
        $block .= "- interaction_density: {$density}\n";
        $block .= "- minority_report: {$minority}\n\n";
        $block .= "## Reliability Constraints\n";

        if ($decisionLabel === 'no-consensus') {
            $block .= "- You MUST NOT claim a clear GO. The aggregated vote result is NO_CONSENSUS.\n";
            $block .= "- You MUST explicitly state that the committee failed to reach a reliable consensus.\n";
        }
        if ($confidence === 'low') {
            $block .= "- You MUST NOT present this decision as confident. The confidence level is LOW.\n";
        }
        if ($qualScore < 40) {
            $block .= "- You MUST note that debate quality was weak ({$qualScore}/100). Agents showed insufficient challenge and contradiction.\n";
        }
        $block .= "- You MUST align your final recommendation with the aggregated_decision above.\n";
        $block .= "- If any agent dissented, you MUST include a ## Minority Report section.\n\n";
        $block .= "## Required Synthesis Structure\n";
        $block .= "Your synthesis should cover these sections clearly; headings may vary if the reasoning stays unambiguous:\n\n";
        $block .= "## Final Jury Judgment\n## Aggregated Vote\n## Reliability Assessment\n";
        $block .= "## Majority Position\n## Minority Report\n## Why This Is Or Is Not Reliable\n## Recommended Next Step\n";

        return $block;
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildJuryMessages(
        \Domain\Agents\Agent $agent,
        string $objective,
        array  $prevMessages,
        int    $round,
        int    $totalRounds,
        string $phase,
        string $language,
        bool   $forceDisagreement,
        ?array $contextDoc,
        string $sessionId,
        ?string $assignedTarget = null,
        ?string $socialDynamicsBlock = null,
        bool   $forceStrongContradictionNext = false,
        bool   $addRoundMindset = true,
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $agentId = $agent->id;

        $personaName  = $agent->persona->name ?? $agentId;
        $personaTitle = $agent->persona->title ?: $personaName;
        $langNote     = $language !== 'en' ? " Respond in language code: $language." : '';
        $system = "You are {$personaName}, a {$personaTitle} participating in a structured adversarial jury deliberation.{$langNote}\n";
        $system .= "Your role: apply your domain expertise to evaluate the proposal rigorously.\n";
        $system .= "Be direct, evidence-based, and precise. Disagree when warranted.\n";
        $system .= "When prior contributions exist, directly reference at least one previous agent by id.\n";
        $system .= "Challenge, support, or revise a specific claim from that agent.\n";
        $system .= "Generic agreement without argument is not acceptable.";
        $system .= $this->promptBuilder->buildEvidenceDisciplineSystemBlock();
        $system .= $this->promptBuilder->buildArgumentDisciplineSystemBlock();

        $userContent = '';

        $userContent .= $this->promptBuilder->buildContextDocumentContent($contextDoc, $sessionId, $objective, null);

        $userContent .= "**Objective under jury deliberation:** $objective\n\n";
        $userContent .= $this->playbookRuntime->buildPromptBlock('jury', $language);
        $userContent .= $this->promptBuilder->buildPlaybookDebateDisciplineBlock('jury', $language);

        if (!empty($prevMessages)) {
            $userContent .= "**Previous jury contributions:**\n";
            foreach ($prevMessages as $msg) {
                $label     = $msg['agent_id'] ?? 'Agent';
                $phaseName = $msg['phase'] ?? '';
                $userContent .= "\n**[$label]** *($phaseName)*: {$msg['content']}\n";
            }
            $userContent .= "\n";
            $userContent .= $this->promptBuilder->buildRepetitionReductionBlock($prevMessages, $language);
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }

        $userContent .= $this->agentContextMemorySvc
            ->buildPromptInjectionBlock($strategicContextIdForAgentMemory, $agentId);

        $prevAgentIds = array_values(array_unique(array_filter(
            array_column($prevMessages, 'agent_id'),
            fn($id) => !empty($id) && $id !== $agentId
        )));
        $targetList = !empty($prevAgentIds) ? implode(', ', $prevAgentIds) : '';

        // Phase-specific instruction
        if ($phase === 'jury-opening') {
            $instruction = "You are participating in an adversarial jury deliberation. Give your **Opening Statement**: your initial position, your strongest argument, your biggest concern, and your **Provisional Vote** using the vote format below.\n\n"
                . "Be clear about where you stand. Do not be vague. State your position explicitly.";

        } elseif ($phase === 'jury-cross-examination') {
            $effectiveTarget = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            if ($effectiveTarget) {
                $instruction = "**Cross Examination round**: You are assigned to challenge **[{$effectiveTarget}]**'s argument.\n\n"
                    . "Begin your response with this exact block:\n\n"
                    . "## Target Agent\n{$effectiveTarget}\n\n"
                    . "## Challenge\n"
                    . "- Claim challenged: [state the exact claim from {$effectiveTarget}]\n"
                    . "- Why it is weak: [explain]\n"
                    . "- Evidence or assumption missing: [what would be needed]\n"
                    . "- What would change your mind: [be specific]\n\n"
                    . "Then update your vote below.";
            } else {
                $instruction = "**Cross Examination round**: Challenge another jury member's argument.\n\n"
                    . "Begin with:\n\n## Target Agent\n{agent_id}\n\n## Challenge\n- Claim challenged:\n- Why it is weak:\n- Evidence or assumption missing:\n- What would change your mind:\n\n"
                    . "Then update your vote.";
            }

        } elseif ($phase === 'jury-defense') {
            $effectiveTarget = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            $instruction = "**Defense round**: You have received challenges to your position. Respond to them now.\n\n"
                . "Begin your response with:\n\n"
                . "## Response To Challenges\n"
                . "- Challenge acknowledged: [which challenge you are responding to, and from whom]\n"
                . "- Defense: [why your original position still holds, or how you refine it]\n"
                . "- Revision: [what you revise, if anything]\n"
                . "- Position changed: yes|no\n\n";
            if ($effectiveTarget) {
                $instruction .= "In addition, address **[{$effectiveTarget}]**'s specific objection.\n\n";
            }
            $instruction .= "Then update your vote below.";

        } elseif ($phase === 'jury-minority-report') {
            $instruction = "**Minority Report**: As a dissenting agent, formally state your minority position.\n\n"
                . "## Minority Report\n\n"
                . "**Core disagreement:** [explicitly state what you disagree with]\n\n"
                . "**Why the majority view is insufficient:** [explain]\n\n"
                . "**Evidence or risks ignored:** [list at least one]\n\n"
                . "**Recommended alternative:** [what should happen instead]\n\n"
                . "Then cast your final vote below.";

        } elseif ($phase === 'jury-verdict') {
            $instruction = "**Committee Verdict**: As the synthesizer, produce the final committee verdict.\n\n"
                . "Include: vote distribution summary, majority position, minority report, automatic decision, decision confidence, reliability assessment, and recommended next action.\n\n"
                . "Use the synthesis structure specified in the constraints below as a clear checklist, while keeping the verdict natural and reasoned.";

        } elseif ($phase === 'jury-mini-challenge') {
            $effectiveTarget = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            $instruction = "**Additional challenge round** (debate quality was insufficient):\n\n"
                . "Target **[{$effectiveTarget}]** and challenge their weakest claim.\n\n"
                . "## Target Agent\n" . ($effectiveTarget ?? 'unknown') . "\n\n"
                . "## Challenge\n- Claim challenged:\n- Why it is weak:\n- Evidence or assumption missing:\n- What would change your mind:\n\n"
                . "Then update your vote.";

        } else {
            // jury-deliberation
            $deliberationRound = max(1, $round - 2);
            $effectiveTarget   = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            $instruction = "**Deliberation round {$deliberationRound}**: Revise or defend your position.\n\n";
            if ($effectiveTarget) {
                $instruction .= "Specifically address **[{$effectiveTarget}]**'s latest argument.\n\n"
                    . "## Target Agent\n{$effectiveTarget}\n\n";
            }
            $instruction .= "State what has changed since your last contribution, your final concern, and your **Final Vote**.";
        }

        $debateTotal = max(1, $totalRounds - 1);
        if ($addRoundMindset && $phase !== 'jury-verdict' && $phase !== 'jury-minority-report' && $agentId !== 'synthesizer' && $debateTotal > 1) {
            $policy = new RoundPolicy();
            $rType  = match ($phase) {
                'jury-opening'           => RoundPolicy::ROUND_OPENING,
                'jury-cross-examination' => RoundPolicy::ROUND_CHALLENGE,
                'jury-defense'           => RoundPolicy::ROUND_CHALLENGE,
                'jury-mini-challenge'    => RoundPolicy::ROUND_CHALLENGE,
                default                   => $policy->getRoundType($round, $debateTotal),
            };
            $instruction .= "\n\n**Round mindset:** "
                . $policy->getRoundTypeDirective($rType, $forceStrongContradictionNext);
        }

        $userContent .= "**Your task:** $instruction\n\n";

        $interactiveJuryPhases = ['jury-cross-examination', 'jury-defense', 'jury-deliberation', 'jury-mini-challenge'];
        if (in_array($phase, $interactiveJuryPhases, true)) {
            $userContent .= $this->promptBuilder->buildInteractionContractBlock(true);
            $userContent .= $this->promptBuilder->buildWeightedOpinionInstructionBlock();
        }

        if ($forceDisagreement && $agentId !== 'synthesizer') {
            $userContent .= "\n> Challenge assumptions and defend an independent position. Do not simply agree with the majority.\n";
        }

        if ($agentId !== 'synthesizer' || $phase !== 'jury-verdict') {
            $userContent .= "\n---\n\n";
            $userContent .= "# Final Vote\n\n";
            $userContent .= "## Vote\ngo | no-go | reduce-scope | needs-more-info | pivot\n\n";
            $userContent .= "## Confidence\n0-10\n\n";
            $userContent .= "## Impact\n0-10\n\n";
            $userContent .= "## Domain Weight\n0-10\n\n";
            $userContent .= "## Rationale\n- Main objection considered: ...\n- Main concession accepted: ...\n- Reason for final vote: ...\n";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function phaseHumanLabel(string $phase): string
    {
        return match ($phase) {
            'jury-opening' => 'Jury ouverture',
            'jury-cross-examination' => 'Jury contre-interrogatoire',
            'jury-defense' => 'Jury defense',
            'jury-deliberation' => 'Jury deliberation',
            'jury-mini-challenge' => 'Jury mini challenge',
            'jury-minority-report' => 'Jury minority report',
            'jury-verdict' => 'Jury verdict',
            'round_started' => 'Round en cours',
            'auto_retry_started' => 'Auto-retry demarre',
            'auto_retry_completed' => 'Auto-retry termine',
            default => $phase,
        };
    }

    private function agentEventLabel(string $phase, string $agentId, bool $started): string
    {
        $step = $started ? 'appel LLM demarre' : 'reponse recue';
        return $this->phaseHumanLabel($phase) . ' · ' . $agentId . ' · ' . $step;
    }

    private function computeAssignedTarget(array $allAgentIds, string $agentId, int $round): ?string {
        $others = array_values(array_filter($allAgentIds, fn($id) => $id !== $agentId && $id !== 'synthesizer'));
        if (empty($others)) return null;
        $nonSynth = array_values(array_filter($allAgentIds, fn($id) => $id !== 'synthesizer'));
        $agentIdx = (int)(array_search($agentId, $nonSynth) ?: 0);
        return $others[($agentIdx + $round) % count($others)];
    }

    private function parseJuryTargetAgent(string $content, array $prevMessages, string $authorId): ?string {
        if (!preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9_-]*)/im', $content, $m)) {
            return null;
        }
        $parsed = strtolower(trim($m[1]));
        $valid  = array_map('strtolower', array_filter(
            array_column($prevMessages, 'agent_id'),
            fn($id) => !empty($id)
        ));
        if (!in_array($parsed, $valid, true) || $parsed === strtolower($authorId)) {
            return null;
        }
        return $parsed;
    }

    /**
     * @return array{target_agent_id:?string,edge_source:string}
     */
    private function resolveJuryTargetAgent(string $content, array $prevMessages, string $authorId, ?string $assignedTarget): array {
        $parsed = $this->parseJuryTargetAgent($content, $prevMessages, $authorId);
        if ($parsed !== null) {
            return ['target_agent_id' => $parsed, 'edge_source' => 'explicit_target'];
        }

        if ($assignedTarget !== null) {
            $valid = array_map('strtolower', array_filter(
                array_column($prevMessages, 'agent_id'),
                fn($id) => !empty($id)
            ));
            $normalized = strtolower($assignedTarget);
            if (in_array($normalized, $valid, true) && $normalized !== strtolower($authorId)) {
                return ['target_agent_id' => $assignedTarget, 'edge_source' => 'assigned_fallback'];
            }
        }

        return ['target_agent_id' => null, 'edge_source' => 'unknown'];
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

    /**
     * @param array<string, array{provider_id?: string, model?: string|null}> $agentOverrides
     * @return array{provider_id?: string, model?: string|null}|null
     */
    private function resolveAgentOverride(array $agentOverrides, string $agentId): ?array {
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

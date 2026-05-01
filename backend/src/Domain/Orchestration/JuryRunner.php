<?php
namespace Domain\Orchestration;

use Domain\Agents\AgentAssembler;
use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Providers\ProviderRouter;
use Domain\Vote\VoteAggregator;
use Domain\Vote\VoteParser;
use Domain\Evidence\EvidenceReportService;
use Domain\Risk\RiskProfileAnalyzer;
use Domain\SocialDynamics\SocialDynamicsService;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Domain\DecisionReliability\FalseConsensusDetector;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\MessageRepository;
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

    public function __construct() {
        $this->assembler      = new AgentAssembler();
        $this->providerRouter = new ProviderRouter();
        $this->messageRepo    = new MessageRepository();
        $this->voteRepo       = new VoteRepository();
        $this->voteParser     = new VoteParser();
        $this->voteAggregator = new VoteAggregator($this->voteRepo);
        $this->debateMemory   = new DebateMemoryService(new DebateRepository());
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
        int    $rounds,
        bool   $forceDisagreement,
        float  $threshold,
        string $language,
        ?array $contextDoc
    ): array {
        $threshold = ReliabilityConfig::normalizeThreshold($threshold);
        // Clamp rounds between 2 and 5
        $rounds = min(max($rounds, 2), 5);

        // Ensure synthesizer is always present
        if (!in_array('synthesizer', $selectedAgents, true)) {
            $selectedAgents[] = 'synthesizer';
        }

        // Debate agents are all agents except synthesizer
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
        $forceStrongNext = false;

        // Run debate rounds (all rounds except last)
        for ($round = 1; $round < $rounds; $round++) {
            $phase        = $this->resolvePhase($round, $rounds);
            $roundMessages = [];

            foreach ($debateAgents as $agentId) {
                $agent = $this->assembler->assemble($agentId);
                if (!$agent) continue;

                $assignedTarget = ($phase !== 'jury-opening')
                    ? $this->computeAssignedTarget($debateAgents, $agentId, $round)
                    : null;

                try {
                    $votesSnap = $this->voteRepo->findVotesBySession($sessionId);
                    $majority  = SocialDynamicsService::summarizeMajority($votesSnap, $state['positions'] ?? []);
                    $socialDynBlock = null;
                    if ($round > 1) {
                        $socialDynBlock = $this->socialPrompt->buildUserBlock($sessionId, $agentId, $majority);
                    }

                    $messages = $this->buildJuryMessages(
                        $agent, $objective, $prevMessages,
                        $round, $rounds, $phase,
                        $language, $forceDisagreement, $contextDoc, $assignedTarget,
                        $socialDynBlock, $forceStrongNext
                    );

                    $routed  = $this->providerRouter->chat($messages, $agent);
                    $content = $routed['content'];

                    $targetAgentId = ($phase !== 'jury-opening')
                        ? ($this->parseJuryTargetAgent($content, $prevMessages, $agentId) ?? $assignedTarget)
                        : null;

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
                        'phase'                    => $phase,
                        'target_agent_id'          => $targetAgentId,
                        'mode_context'             => 'jury',
                        'message_type'             => $phase,
                        'content'                  => $content,
                        'created_at'               => date('c'),
                    ]);
                    $roundMessages[] = $msg;

                    $this->debateMemory->processMessage(
                        $sessionId, $round, $agentId, $content, $targetAgentId, $state
                    );
                    $this->socialDynamics->ingestAgentResponse(
                        $sessionId,
                        $round,
                        $agentId,
                        $content,
                        $targetAgentId,
                        $debateAgents,
                        $this->voteRepo->findVotesBySession($sessionId),
                        $state['positions'] ?? []
                    );

                    // Parse and save vote for every round
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
                }
            }

            $allRounds[$round] = $roundMessages;
            $prevMessages      = $roundMessages;

            if ($round < $rounds - 1) {
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

        // Final round: synthesizer produces Committee Verdict
        $verdictMessages = [];
        $synthAgent = $this->assembler->assemble('synthesizer');
        if ($synthAgent) {
            try {
                $allPrevMessages = array_merge(...array_values($allRounds ?: [[]]));
                $messages = $this->buildJuryMessages(
                    $synthAgent, $objective, $allPrevMessages,
                    $rounds, $rounds, 'jury-verdict',
                    $language, $forceDisagreement, $contextDoc,
                    null, null, false, false
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
                    'round'                    => $rounds,
                    'phase'                    => 'jury-verdict',
                    'target_agent_id'          => null,
                    'mode_context'             => 'jury',
                    'message_type'             => 'jury-verdict',
                    'content'                  => $content,
                    'created_at'               => date('c'),
                ]);
                $verdictMessages[] = $msg;

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
                    'round'                    => $rounds,
                    'phase'                    => 'jury-verdict',
                    'target_agent_id'          => null,
                    'mode_context'             => 'jury',
                    'message_type'             => 'jury-verdict',
                    'content'                  => '[Error] ' . $e->getMessage(),
                    'created_at'               => date('c'),
                ]);
                $verdictMessages[] = $msg;
            }
        }
        $allRounds[$rounds] = $verdictMessages;

        $automaticDecision = $this->voteAggregator->recompute($sessionId, $threshold);
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

        return [
            'session_id'         => $sessionId,
            'rounds'             => $allRounds,
            'synthesis'          => $verdictMessages,
            'verdict'            => null,
            'total_rounds'       => $rounds,
            'arguments'          => $state['arguments'] ?? [],
            'positions'          => $state['positions'] ?? [],
            'interaction_edges'  => $state['edges'] ?? [],
            'votes'              => $allVotes,
            'automatic_decision' => $automaticDecision,
            'threshold'          => $threshold,
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

    // ──────────────────────────────────────────────────────────────────────────

    private function resolvePhase(int $round, int $totalRounds): string {
        if ($round === 1) return 'jury-opening';
        if ($round === 2) return 'jury-cross-examination';
        return 'jury-deliberation';
    }

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
        ?string $assignedTarget = null,
        ?string $socialDynamicsBlock = null,
        bool   $forceStrongContradictionNext = false,
        bool   $addRoundMindset = true
    ): array {
        $agentId = $agent->id;

        // System prompt — use agent persona
        $personaName  = $agent->persona->name ?? $agentId;
        $personaTitle = $agent->persona->title ?: $personaName;
        $langNote     = $language !== 'en' ? " Respond in language code: $language." : '';
        $system       = "You are {$personaName}, a {$personaTitle} participating in a structured jury deliberation.$langNote\n";
        $system  .= "Your role: apply your domain expertise to evaluate the proposal rigorously.\n";
        $system  .= "Be direct, evidence-based, and precise. Disagree when warranted.";

        // User content
        $userContent = '';

        // Context document
        if (!empty($contextDoc['content'])) {
            $userContent .= "# Context Document\n\n" . $contextDoc['content'] . "\n\n---\n\n";
        }

        $userContent .= "**Objective under jury deliberation:** $objective\n\n";

        // Previous contributions
        if (!empty($prevMessages)) {
            $userContent .= "**Previous jury contributions:**\n";
            foreach ($prevMessages as $msg) {
                $label = $msg['agent_id'] ?? 'Agent';
                $phaseName = $msg['phase'] ?? '';
                $userContent .= "\n**[$label]** *($phaseName)*: {$msg['content']}\n";
            }
            $userContent .= "\n";
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }

        // Build list of potential targets from previous messages
        $prevAgentIds = array_values(array_unique(array_filter(
            array_column($prevMessages, 'agent_id'),
            fn($id) => !empty($id) && $id !== $agentId
        )));
        $targetList = !empty($prevAgentIds) ? implode(', ', $prevAgentIds) : '';

        // Phase-specific instruction
        if ($phase === 'jury-opening') {
            $instruction = "You are participating in a jury deliberation. Give your **Opening Statement**: your initial position, your strongest argument, your biggest concern, and your **Provisional Vote** using the vote format below.";
        } elseif ($phase === 'jury-cross-examination') {
            $effectiveTarget = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            if ($effectiveTarget) {
                $instruction = "**Cross Examination round**: You are assigned to challenge **[{$effectiveTarget}]**'s argument.\n\n"
                    . "Begin your response with this exact block (before any other text):\n\n"
                    . "## Target Agent\n{$effectiveTarget}\n\n"
                    . "Then state your specific objection to their position, what you agree or disagree with, and update your vote.";
            } else {
                $instruction = "**Cross Examination round**: Challenge another jury member's argument. Begin with `## Target Agent\n{agent_id}` then state your objection and update your vote.";
            }
        } elseif ($phase === 'jury-verdict') {
            $instruction = "**Committee Verdict**: As the synthesizer, produce the final committee verdict. Include: vote distribution summary, majority position, minority report, automatic decision, decision confidence, and recommended next action.";
        } else {
            $deliberationRound = $round - 2;
            $effectiveTarget   = $assignedTarget ?? ($targetList ? explode(', ', $targetList)[0] : null);
            $instruction = "**Deliberation round {$deliberationRound}**: Revise or defend your position.\n\n";
            if ($effectiveTarget) {
                $instruction .= "For this round, specifically address **[{$effectiveTarget}]**'s latest argument. Begin with:\n\n"
                    . "## Target Agent\n{$effectiveTarget}\n\n";
            }
            $instruction .= "State what has changed since your last contribution, your final concern, and your **Final Vote**.";
        }

        $debateTotal = max(1, $totalRounds - 1);
        if ($addRoundMindset && $phase !== 'jury-verdict' && $agentId !== 'synthesizer' && $debateTotal > 1) {
            $policy = new RoundPolicy();
            $rType  = match ($phase) {
                'jury-opening'           => RoundPolicy::ROUND_OPENING,
                'jury-cross-examination' => RoundPolicy::ROUND_CHALLENGE,
                default                   => $policy->getRoundType($round, $debateTotal),
            };
            $instruction .= "\n\n**Round mindset:** "
                . $policy->getRoundTypeDirective($rType, $forceStrongContradictionNext);
        }

        $userContent .= "**Your task:** $instruction\n\n";

        // Force disagreement nudge
        if ($forceDisagreement && $agentId !== 'synthesizer') {
            $userContent .= "\n> You are expected to challenge assumptions and defend an independent position. Do not simply agree with the majority.\n";
        }

        // Vote format — only for non-synthesizer or non-verdict phases
        if ($agentId !== 'synthesizer' || $phase !== 'jury-verdict') {
            $userContent .= "\n---\n\n";
            $userContent .= "# Final Vote\n\n";
            $userContent .= "## Vote\ngo | no-go | reduce-scope | needs-more-info | pivot\n\n";
            $userContent .= "## Confidence\n0-10\n\n";
            $userContent .= "## Impact\n0-10\n\n";
            $userContent .= "## Domain Weight\n0-10\n\n";
            $userContent .= "## Rationale\n...\n";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userContent],
        ];
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

    /**
     * Parses an explicit ## Target Agent block from LLM output and validates
     * that the declared agent actually spoke in the previous messages.
     */
    private function parseJuryTargetAgent(string $content, array $prevMessages, string $authorId): ?string {
        if (!preg_match('/##\s*Target Agent\s*\n+\s*([a-z][a-z0-9-]*)/im', $content, $m)) {
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

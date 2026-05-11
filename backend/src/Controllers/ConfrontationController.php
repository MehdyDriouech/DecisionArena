<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Domain\DecisionMemory\DecisionMemoryContextBuilder;
use Domain\Orchestration\ConfrontationRunner;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\StructuredRunResult;

class ConfrontationController {
    private SessionRepository $sessionRepo;
    private ConfrontationRunner $runner;
    private DecisionMemoryRepository $memoryRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new ConfrontationRunner();
        $this->memoryRepo  = new DecisionMemoryRepository();
    }

    public function run(Request $req): array {
        $data = $req->body();

        $sessionId       = $data['session_id'] ?? '';
        $objective       = $data['objective']  ?? '';

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        // Resolve selected agents (new unified list or legacy blue/red teams)
        $blueTeam       = $data['blue_team'] ?? [];
        $redTeam        = $data['red_team']  ?? [];
        $selectedAgents = $data['selected_agents'] ?? [];

        if (empty($selectedAgents)) {
            $selectedAgents = array_unique(array_merge(
                $blueTeam ?: ['pm', 'architect', 'po'],
                $redTeam  ?: ['analyst', 'critic']
            ));
        }

        // Confrontation settings (from request or session defaults)
        $rounds            = (int)($data['rounds']             ?? $session['cf_rounds']             ?? 3);
        $interactionStyle  = $data['interaction_style']        ?? $session['cf_interaction_style']  ?? 'sequential';
        $replyPolicy       = $data['reply_policy']             ?? $session['cf_reply_policy']       ?? 'all-agents-reply';
        $includeSynthesis  = (bool)($data['include_synthesis'] ?? $data['final_synthesis']          ?? true);
        $forceDisagreement = (bool)($data['force_disagreement'] ?? $session['force_disagreement']   ?? false);
        $language   = $session['language'] ?? 'fr';

        // Controlled Decision Memory reuse (manual, compact, auditable)
        $selectedMemoryIds = json_decode((string)($session['selected_memory_ids'] ?? '[]'), true);
        $selectedMemoryIds = is_array($selectedMemoryIds) ? $selectedMemoryIds : [];
        $reuse = $this->memoryRepo->compactReusableForIds(array_map('strval', $selectedMemoryIds));
        $injectInfo = null;
        $rawDoc = (new ContextDocumentRepository())->findBySession($sessionId);
        if (!empty($reuse['allowed'])) {
            $built = DecisionMemoryContextBuilder::buildInjectionBlock($reuse['allowed'], (string)$language);
            $injectInfo = $built;
            if (!is_array($rawDoc)) {
                $rawDoc = [
                    'id' => 'memory-only',
                    'session_id' => $sessionId,
                    'title' => 'Memory context',
                    'source_type' => 'memory',
                    'content' => '',
                    'character_count' => 0,
                ];
            }
            $rawDoc['content'] = $built['block'] . "\n\n" . (string)($rawDoc['content'] ?? '');
        }
        $contextDoc = (new PromptBuilder())->prepareContextDocumentForPrompt($rawDoc);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);

        if (!in_array($interactionStyle, ['sequential', 'agent-to-agent'], true)) {
            return Response::error('Invalid interaction_style', 400);
        }
        if (!in_array($replyPolicy, ['all-agents-reply', 'only-mentioned-agent-replies', 'critic-priority'], true)) {
            return Response::error('Invalid reply_policy', 400);
        }

        // Feature 3 & 4
        $daEnabled      = (bool)($session['devil_advocate_enabled']   ?? false);
        $daThreshold    = (float)($session['devil_advocate_threshold'] ?? 0.65);
        $agentProviders = (new \Infrastructure\Persistence\SessionAgentProvidersRepository())->findBySession($sessionId);

        $strategicCtx = isset($session['strategic_context_id']) && (string)$session['strategic_context_id'] !== ''
            ? (string)$session['strategic_context_id'] : null;
        $this->sessionRepo->update($sessionId, ['status' => 'running']);
        try {
            $result = $this->runner->run(
                $sessionId,
                $objective,
                $selectedAgents,
                $includeSynthesis,
                $language,
                $rounds,
                $interactionStyle,
                $replyPolicy,
                $forceDisagreement,
                $contextDoc,
                $daEnabled,
                $daThreshold,
                $agentProviders,
                $decisionThreshold,
                $session['decision_dynamics_preset'] ?? null,
                $strategicCtx
            );
        } catch (\Throwable $e) {
            $this->sessionRepo->update($sessionId, ['status' => 'draft']);
            return Response::error('Confrontation run failed: ' . $e->getMessage(), 500);
        }

        $memoryReuse = [
            'reuse_mode' => $injectInfo ? 'manual' : null,
            'selected_memory_ids' => array_values(array_map('strval', $selectedMemoryIds)),
            'injected_memory_ids' => $injectInfo ? (array)($injectInfo['injected_ids'] ?? []) : [],
            'blocked' => (array)($reuse['blocked'] ?? []),
            'truncated' => $injectInfo ? (bool)($injectInfo['truncated'] ?? false) : false,
            'injected_chars' => $injectInfo ? (int)($injectInfo['chars'] ?? 0) : 0,
        ];
        $result['memory_reuse'] = $memoryReuse;
        if ($injectInfo) {
            try {
                $this->sessionRepo->update($sessionId, [
                    'injected_memory_context' => (string)($injectInfo['block'] ?? ''),
                    'memory_reuse_mode' => 'manual',
                    'memory_used_at' => date('c'),
                ]);
            } catch (\Throwable $e) {}
        }

        $dynamicsRepo = new \Infrastructure\Persistence\PersonaDecisionDynamicsRepository();
        $agentDecisionDynamics = $dynamicsRepo->transparencyForAgents(
            $selectedAgents,
            $session['decision_dynamics_preset'] ?? null
        );

        // Mark session as completed
        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
            'decision_brief' => json_encode($result['decision_brief'] ?? null, JSON_UNESCAPED_UNICODE),
            'result' => json_encode(
                StructuredRunResult::persistableResultSlice($result),
                JSON_UNESCAPED_UNICODE
            ),
        ]);

        // Decision Memory v1 — persist only if memory-safe.
        try { $this->memoryRepo->persistIfSafe($result, $sessionId); } catch (\Throwable $e) {}

        return [
            'session_id'        => $sessionId,
            'rounds'            => $result['rounds'],
            'synthesis'         => $result['synthesis'],
            'verdict'           => $result['verdict'] ?? null,
            'total_rounds'      => $result['total_rounds'],
            'interaction_style' => $result['interaction_style'],
            'reply_policy'      => $result['reply_policy'] ?? $replyPolicy,
            'arguments'         => $result['arguments'] ?? [],
            'positions'         => $result['positions'] ?? [],
            'interaction_edges' => $result['interaction_edges'] ?? [],
            'weighted_analysis' => $result['weighted_analysis'] ?? [],
            'dominance_indicator' => $result['dominance_indicator'] ?? '',
            'votes'             => $result['votes'] ?? [],
            'vote_timeline'     => $result['vote_timeline'] ?? ($result['votes'] ?? []),
            'final_votes'       => $result['final_votes'] ?? null,
            'memory_summary'    => $result['memory_summary'] ?? null,
            'automatic_decision'=> $result['automatic_decision'] ?? null,
            'raw_decision' => $result['raw_decision'] ?? null,
            'adjusted_decision' => $result['adjusted_decision'] ?? null,
            'context_quality' => $result['context_quality'] ?? null,
            'reliability_cap' => $result['reliability_cap'] ?? null,
            'false_consensus_risk' => $result['false_consensus_risk'] ?? 'low',
            'false_consensus' => $result['false_consensus'] ?? null,
            'reliability_warnings' => $result['reliability_warnings'] ?? [],
            'guardrails'        => $result['guardrails'] ?? null,
            'decision_quality_score' => $result['decision_quality_score'] ?? null,
            'decision_brief'    => $result['decision_brief'] ?? null,
            'canonical_synthesis' => $result['canonical_synthesis'] ?? null,
            'decision_outcome' => $result['decision_outcome'] ?? null,
            'playbook_runtime'  => $result['playbook_runtime'] ?? null,
            'agent_decision_dynamics' => $agentDecisionDynamics,
        ];
    }
}

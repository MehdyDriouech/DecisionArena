<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Domain\DecisionMemory\DecisionMemoryContextBuilder;
use Domain\Orchestration\DecisionRoomRunner;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\StructuredRunResult;

class DecisionRoomController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DecisionRoomRunner $runner;
    private DecisionMemoryRepository $memoryRepo;
    private RunStatusRepository $runStatusRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->messageRepo = new MessageRepository();
        $this->runner      = new DecisionRoomRunner();
        $this->memoryRepo  = new DecisionMemoryRepository();
        $this->runStatusRepo = new RunStatusRepository();
    }

    public function run(Request $req): array {
        $data           = $req->body();
        $sessionId      = $data['session_id'] ?? '';
        $objective      = $data['objective'] ?? '';
        $selectedAgents = $this->normalizeJsonArray($data['selected_agents'] ?? [], []);
        $rounds            = (int)($data['rounds'] ?? 2);

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $forceDisagreement = array_key_exists('force_disagreement', $data)
            ? (bool)$data['force_disagreement']
            : (bool)($session['force_disagreement'] ?? false);

        if (empty($selectedAgents)) {
            $selectedAgents = $this->normalizeJsonArray($session['selected_agents'] ?? [], []);
        }

        $language   = $session['language'] ?? 'en';

        // Controlled Decision Memory reuse (manual, compact, auditable; no chat history)
        $selectedMemoryIds = $this->normalizeJsonArray($session['selected_memory_ids'] ?? [], []);
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

        // Feature 3: Devil's Advocate — read from session config
        $daEnabled   = (bool)($session['devil_advocate_enabled']   ?? false);
        $daThreshold = (float)($session['devil_advocate_threshold'] ?? 0.65);

        // Feature 4: per-agent provider overrides
        $agentProviders = (new \Infrastructure\Persistence\SessionAgentProvidersRepository())->findBySession($sessionId);

        $sessionOptions = [
            'auto_retry_on_weak_debate' => (bool)($data['auto_retry_on_weak_debate'] ?? $session['auto_retry_on_weak_debate'] ?? false),
            'decision_dynamics_preset'   => $session['decision_dynamics_preset'] ?? 'balanced',
            'session_variant'             => $session['session_variant'] ?? null,
            'strategic_context_id'        => isset($session['strategic_context_id']) && (string)$session['strategic_context_id'] !== ''
                ? (string)$session['strategic_context_id'] : null,
        ];

        $this->sessionRepo->update($sessionId, ['status' => 'running']);
        $this->runStatusRepo->initialize($sessionId, 'decision-room', $rounds);
        $this->runStatusRepo->appendEvent(
            $sessionId,
            [
                'level' => 'info',
                'phase' => 'session_started',
                'round' => 0,
                'label' => 'Session demarree',
            ],
            [
                'current_round' => 0,
                'total_rounds' => $rounds,
                'current_phase' => 'session_started',
                'current_phase_label' => 'Session demarree',
                'current_step' => 'startup',
                'percent' => 1,
            ],
            'running'
        );
        try {
            $result = $this->runner->run(
                $sessionId, $objective, $selectedAgents, $rounds, $language,
                $forceDisagreement, $contextDoc,
                $daEnabled, $daThreshold, $agentProviders, $decisionThreshold, $sessionOptions
            );
        } catch (\Throwable $e) {
            $this->sessionRepo->update($sessionId, ['status' => 'draft']);
            $this->runStatusRepo->appendEvent(
                $sessionId,
                [
                    'level' => 'error',
                    'phase' => 'session_failed',
                    'round' => null,
                    'label' => 'Execution echouee',
                ],
                [
                    'current_phase' => 'session_failed',
                    'current_phase_label' => 'Session en echec',
                    'current_step' => 'failed',
                ],
                'failed',
                (string)$e->getMessage()
            );
            return Response::error('Decision Room run failed: ' . $e->getMessage(), 500);
        }

        // Traceability: record what was injected (manual reuse only)
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

        $dynamicsRepo       = new \Infrastructure\Persistence\PersonaDecisionDynamicsRepository();
        $agentDecisionDynamics = $dynamicsRepo->transparencyForAgents(
            is_array($selectedAgents) ? $selectedAgents : [],
            $session['decision_dynamics_preset'] ?? null
        );

        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
            'result' => json_encode(
                StructuredRunResult::persistableResultSlice($result),
                JSON_UNESCAPED_UNICODE
            ),
            'decision_brief' => json_encode($result['decision_brief'] ?? null, JSON_UNESCAPED_UNICODE),
        ]);
        $this->runStatusRepo->appendEvent(
            $sessionId,
            [
                'level' => 'info',
                'phase' => 'session_completed',
                'round' => $rounds,
                'label' => 'Session terminee',
            ],
            [
                'current_round' => $rounds,
                'total_rounds' => $rounds,
                'current_phase' => 'session_completed',
                'current_phase_label' => 'Session terminee',
                'current_step' => 'done',
                'percent' => 100,
            ],
            'completed'
        );

        // Decision Memory v1 — persist only if memory-safe (no raw chat stored).
        try { $this->memoryRepo->persistIfSafe($result, $sessionId); } catch (\Throwable $e) {}

        return [
            'session_id'   => $sessionId,
            'rounds'       => $result['rounds'] ?? [],
            'total_rounds' => count($result['rounds'] ?? []),
            'arguments'    => $result['arguments'] ?? [],
            'positions'    => $result['positions'] ?? [],
            'interaction_edges' => $result['interaction_edges'] ?? [],
            'weighted_analysis' => $result['weighted_analysis'] ?? [],
            'dominance_indicator' => $result['dominance_indicator'] ?? '',
            'votes' => $result['votes'] ?? [],
            'vote_timeline' => $result['vote_timeline'] ?? ($result['votes'] ?? []),
            'final_votes' => $result['final_votes'] ?? null,
            'memory_summary' => $result['memory_summary'] ?? null,
            'automatic_decision' => $result['automatic_decision'] ?? null,
            'raw_decision' => $result['raw_decision'] ?? null,
            'adjusted_decision' => $result['adjusted_decision'] ?? null,
            'context_quality' => $result['context_quality'] ?? null,
            'reliability_cap' => $result['reliability_cap'] ?? null,
            'false_consensus_risk' => $result['false_consensus_risk'] ?? 'low',
            'false_consensus' => $result['false_consensus'] ?? null,
            'reliability_warnings' => $result['reliability_warnings'] ?? [],
            'guardrails' => $result['guardrails'] ?? null,
            'decision_quality_score' => $result['decision_quality_score'] ?? null,
            'canonical_synthesis' => $result['canonical_synthesis'] ?? null,
            'decision_outcome' => $result['decision_outcome'] ?? null,
            'playbook_runtime' => $result['playbook_runtime'] ?? null,
            'agent_decision_dynamics' => $agentDecisionDynamics,
            'decision_brief'       => $result['decision_brief'] ?? null,
            'premortem_summary'  => $result['premortem_summary'] ?? null,
        ];
    }

    /**
     * @param mixed $value
     * @param array<int|string,mixed> $default
     * @return array<int|string,mixed>
     */
    private function normalizeJsonArray($value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $default;
            }
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : $default;
        }
        return $default;
    }
}

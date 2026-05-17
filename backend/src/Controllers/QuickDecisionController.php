<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Domain\DecisionMemory\DecisionMemoryContextBuilder;
use Domain\Orchestration\QuickDecisionRunner;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\StructuredRunResult;

class QuickDecisionController {
    use DemoLlmQuotaTrait;
    private SessionRepository         $sessionRepo;
    private QuickDecisionRunner       $runner;
    private ContextDocumentRepository $docRepo;
    private DecisionMemoryRepository  $memoryRepo;
    private RunStatusRepository       $runStatusRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new QuickDecisionRunner();
        $this->docRepo     = new ContextDocumentRepository();
        $this->memoryRepo  = new DecisionMemoryRepository();
        $this->runStatusRepo = new RunStatusRepository();
    }

    public function run(Request $req): array {
        $data              = $req->body();
        $sessionId         = $data['session_id'] ?? '';
        $objective         = $data['objective']  ?? '';
        $selectedAgents    = $data['selected_agents'] ?? ['pm', 'architect', 'critic'];

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $language   = $session['language'] ?? 'fr';
        $forceDisagreement = array_key_exists('force_disagreement', $data)
            ? (bool)$data['force_disagreement']
            : (bool)($session['force_disagreement'] ?? false);

        // Controlled Decision Memory reuse (manual, compact, auditable)
        $selectedMemoryIds = json_decode((string)($session['selected_memory_ids'] ?? '[]'), true);
        $selectedMemoryIds = is_array($selectedMemoryIds) ? $selectedMemoryIds : [];
        $reuse = $this->memoryRepo->compactReusableForIds(array_map('strval', $selectedMemoryIds));
        $injectInfo = null;
        $rawDoc = $this->docRepo->findBySession($sessionId);
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
        $agentProviders = (new SessionAgentProvidersRepository())->findBySession($sessionId);

        $strategicCtx = isset($session['strategic_context_id']) && (string)$session['strategic_context_id'] !== ''
            ? (string)$session['strategic_context_id'] : null;
        $this->sessionRepo->update($sessionId, ['status' => 'running']);
        $this->runStatusRepo->initialize($sessionId, 'quick-decision', 2);
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
                'total_rounds' => 2,
                'current_phase' => 'session_started',
                'current_phase_label' => 'Session demarree',
                'current_step' => 'startup',
                'percent' => 1,
            ],
            'running'
        );
        try {
            $result = $this->runWithDemoQuota($req, fn () => $this->runner->run(
                $sessionId,
                $objective,
                $selectedAgents,
                $language,
                $forceDisagreement,
                $contextDoc,
                $agentProviders,
                $decisionThreshold,
                $session['decision_dynamics_preset'] ?? null,
                $strategicCtx
            ));
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
            if ($e instanceof \Domain\Demo\DemoHttpException) {
                throw $e;
            }
            return Response::error('Quick decision run failed: ' . $e->getMessage(), 500);
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

        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
            'decision_brief' => json_encode($result['decision_brief'] ?? null, JSON_UNESCAPED_UNICODE),
            'result' => json_encode(
                StructuredRunResult::persistableResultSlice($result),
                JSON_UNESCAPED_UNICODE
            ),
        ]);
        $this->runStatusRepo->appendEvent(
            $sessionId,
            [
                'level' => 'info',
                'phase' => 'result_persisted',
                'round' => 2,
                'label' => 'Resultat persiste',
            ],
            [
                'current_round' => 2,
                'total_rounds' => 2,
                'current_phase' => 'result_persisted',
                'current_phase_label' => 'Resultat persiste',
                'current_step' => 'persist',
                'percent' => 99,
            ],
            'running'
        );
        $this->runStatusRepo->appendEvent(
            $sessionId,
            [
                'level' => 'info',
                'phase' => 'session_completed',
                'round' => 2,
                'label' => 'Session terminee',
            ],
            [
                'current_round' => 2,
                'total_rounds' => 2,
                'current_phase' => 'session_completed',
                'current_phase_label' => 'Session terminee',
                'current_step' => 'done',
                'percent' => 100,
            ],
            'completed'
        );

        // Decision Memory v1 — persist only if memory-safe.
        try { $this->memoryRepo->persistIfSafe($result, $sessionId); } catch (\Throwable $e) {}

        return [
            'session_id' => $sessionId,
            'round'      => $result['round'],
            'synthesis'  => $result['synthesis'],
            'verdict'    => $result['verdict'],
            'warning'    => $result['warning'],
            'votes'      => $result['votes'] ?? [],
            'automatic_decision' => $result['automatic_decision'] ?? null,
            'raw_decision' => $result['raw_decision'] ?? null,
            'adjusted_decision' => $result['adjusted_decision'] ?? null,
            'context_quality' => $result['context_quality'] ?? null,
            'reliability_cap' => $result['reliability_cap'] ?? null,
            'false_consensus_risk' => $result['false_consensus_risk'] ?? 'low',
            'false_consensus' => $result['false_consensus'] ?? null,
            'reliability_warnings' => $result['reliability_warnings'] ?? [],
            'decision_quality_score' => $result['decision_quality_score'] ?? null,
            'decision_brief' => $result['decision_brief'] ?? null,
            'canonical_synthesis' => $result['canonical_synthesis'] ?? null,
            'decision_outcome' => $result['decision_outcome'] ?? null,
            'playbook_runtime' => $result['playbook_runtime'] ?? null,
            'agent_decision_dynamics' => $agentDecisionDynamics,
        ];
    }
}

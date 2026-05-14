<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Domain\DecisionMemory\DecisionMemoryContextBuilder;
use Domain\Orchestration\JuryRunner;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\StructuredRunResult;

class JuryController {
    private SessionRepository $sessionRepo;
    private JuryRunner        $runner;
    private DecisionMemoryRepository $memoryRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new JuryRunner();
        $this->memoryRepo  = new DecisionMemoryRepository();
    }

    public function run(Request $req): array {
        $data = $req->body();

        $sessionId = $data['session_id'] ?? '';
        $objective = $data['objective']  ?? '';

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $selectedAgents    = $data['selected_agents']    ?? ['pm', 'architect', 'critic', 'synthesizer'];
        $rounds            = (int)($data['rounds']            ?? 3);
        $forceDisagreement = (bool)($data['force_disagreement'] ?? true);
        $threshold         = ReliabilityConfig::normalizeThreshold($data['decision_threshold'] ?? $session['decision_threshold'] ?? null);
        $language = $session['language'] ?? 'en';

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

        // Adversarial jury configuration
        $adversarialCfg = array_filter([
            'jury_adversarial_enabled'              => isset($data['jury_adversarial_enabled'])
                ? (bool)$data['jury_adversarial_enabled'] : null,
            'min_challenges_per_round'              => isset($data['min_challenges_per_round'])
                ? (int)$data['min_challenges_per_round'] : null,
            'force_agent_references'                => isset($data['force_agent_references'])
                ? (bool)$data['force_agent_references'] : null,
            'require_position_change_check'         => isset($data['require_position_change_check'])
                ? (bool)$data['require_position_change_check'] : null,
            'require_minority_report'               => isset($data['require_minority_report'])
                ? (bool)$data['require_minority_report'] : null,
            'block_weak_debate_decision'            => isset($data['block_weak_debate_decision'])
                ? (bool)$data['block_weak_debate_decision'] : null,
            'debate_quality_min_score'              => isset($data['debate_quality_min_score'])
                ? (int)$data['debate_quality_min_score'] : null,
            'false_consensus_blocks_confident_decision' => isset($data['false_consensus_blocks_confident_decision'])
                ? (bool)$data['false_consensus_blocks_confident_decision'] : null,
            // Explicit minority reporter agent (empty string = auto-detect)
            'minority_reporter_agent_id'            => isset($data['minority_reporter_agent_id'])
                ? (string)$data['minority_reporter_agent_id'] : null,
        ], fn($v) => $v !== null);

        $result = $this->runner->run(
            $sessionId,
            $objective,
            $selectedAgents,
            $rounds,
            $forceDisagreement,
            $threshold,
            $language,
            $contextDoc,
            $adversarialCfg,
            $session['decision_dynamics_preset'] ?? null
        );

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

        // Decision Memory v1 — persist only if memory-safe.
        try { $this->memoryRepo->persistIfSafe($result, $sessionId); } catch (\Throwable $e) {}

        $dynamicsRepo = new \Infrastructure\Persistence\PersonaDecisionDynamicsRepository();
        $out = array_merge(['session_id' => $sessionId], $result);
        $out['agent_decision_dynamics'] = $dynamicsRepo->transparencyForAgents(
            $selectedAgents,
            $session['decision_dynamics_preset'] ?? null
        );

        return $out;
    }
}

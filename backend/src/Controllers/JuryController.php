<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\JuryRunner;

class JuryController {
    private SessionRepository $sessionRepo;
    private JuryRunner        $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new JuryRunner();
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
        $contextDoc = null;
        try {
            $contextDoc = (new ContextDocumentRepository())->findBySession($sessionId);
        } catch (\Throwable $e) {
            $contextDoc = null;
        }

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
            $adversarialCfg
        );

        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
        ]);

        return array_merge(['session_id' => $sessionId], $result);
    }
}

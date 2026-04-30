<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\QuickDecisionRunner;

class QuickDecisionController {
    private SessionRepository         $sessionRepo;
    private QuickDecisionRunner       $runner;
    private ContextDocumentRepository $docRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new QuickDecisionRunner();
        $this->docRepo     = new ContextDocumentRepository();
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
        $contextDoc = $this->docRepo->findBySession($sessionId);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);

        $result = $this->runner->run(
            $sessionId,
            $objective,
            $selectedAgents,
            $language,
            $forceDisagreement,
            $contextDoc,
            $decisionThreshold
        );

        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
        ]);

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
        ];
    }
}

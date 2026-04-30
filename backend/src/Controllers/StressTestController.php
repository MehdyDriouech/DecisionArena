<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\StressTestRunner;

class StressTestController {
    private SessionRepository         $sessionRepo;
    private ContextDocumentRepository $docRepo;
    private StressTestRunner          $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->docRepo     = new ContextDocumentRepository();
        $this->runner      = new StressTestRunner();
    }

    public function run(Request $req): array {
        $data           = $req->body();
        $sessionId      = $data['session_id'] ?? '';
        $objective      = $data['objective'] ?? '';
        $selectedAgents = $data['selected_agents'] ?? [];
        $rounds         = (int)($data['rounds'] ?? 2);
        $forceDisagree  = isset($data['force_disagreement']) ? (bool)$data['force_disagreement'] : true;

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        if (empty($selectedAgents)) {
            $selectedAgents = json_decode($session['selected_agents'] ?? '[]', true);
        }
        if (empty($selectedAgents)) {
            $selectedAgents = ['critic', 'architect', 'pm', 'ux-expert', 'synthesizer'];
        }

        $language   = $session['language'] ?? 'en';
        $contextDoc = $this->docRepo->findBySession($sessionId);

        $result = $this->runner->run(
            $sessionId,
            $objective,
            $selectedAgents,
            $rounds,
            $language,
            $forceDisagree,
            $contextDoc
        );

        $this->sessionRepo->update($sessionId, ['status' => 'completed', 'mode' => 'stress-test']);

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
            'automatic_decision' => $result['automatic_decision'] ?? null,
        ];
    }
}

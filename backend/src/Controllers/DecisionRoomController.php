<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\DecisionRoomRunner;

class DecisionRoomController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DecisionRoomRunner $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->messageRepo = new MessageRepository();
        $this->runner      = new DecisionRoomRunner();
    }

    public function run(Request $req): array {
        $data           = $req->body();
        $sessionId      = $data['session_id'] ?? '';
        $objective      = $data['objective'] ?? '';
        $selectedAgents    = $data['selected_agents'] ?? [];
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
            $selectedAgents = json_decode($session['selected_agents'] ?? '[]', true);
        }

        $language   = $session['language'] ?? 'en';
        $contextDoc = (new ContextDocumentRepository())->findBySession($sessionId);

        $result = $this->runner->run($sessionId, $objective, $selectedAgents, $rounds, $language, $forceDisagreement, $contextDoc);

        $this->sessionRepo->update($sessionId, ['status' => 'completed']);

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

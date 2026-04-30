<?php
namespace Controllers;

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

        $result = $this->runner->run($sessionId, $objective, $selectedAgents, $language, $forceDisagreement, $contextDoc);

        $this->sessionRepo->update($sessionId, ['status' => 'completed']);

        return [
            'session_id' => $sessionId,
            'round'      => $result['round'],
            'synthesis'  => $result['synthesis'],
            'verdict'    => $result['verdict'],
            'warning'    => $result['warning'],
            'votes'      => $result['votes'] ?? [],
            'automatic_decision' => $result['automatic_decision'] ?? null,
        ];
    }
}

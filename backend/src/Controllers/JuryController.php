<?php
namespace Controllers;

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
        $threshold         = (float)($data['decision_threshold'] ?? $session['decision_threshold'] ?? 0.55);
        $language = $session['language'] ?? 'en';
        $contextDoc = null;
        try {
            $contextDoc = (new ContextDocumentRepository())->findBySession($sessionId);
        } catch (\Throwable $e) {
            $contextDoc = null;
        }

        $result = $this->runner->run(
            $sessionId,
            $objective,
            $selectedAgents,
            $rounds,
            $forceDisagreement,
            $threshold,
            $language,
            $contextDoc
        );

        $this->sessionRepo->update($sessionId, ['status' => 'completed']);

        return array_merge(['session_id' => $sessionId], $result);
    }
}

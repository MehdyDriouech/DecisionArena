<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\PostmortemRepository;

class PostmortemController {
    private SessionRepository  $sessionRepo;
    private PostmortemRepository $postmortemRepo;

    private const VALID_OUTCOMES = ['correct', 'incorrect', 'partial'];

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->postmortemRepo = new PostmortemRepository();
    }

    /**
     * POST /api/sessions/{id}/postmortem
     */
    public function store(Request $req): array {
        $sessionId = $req->param('id');

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $body = $req->body();

        $outcome = $body['outcome'] ?? null;
        if (!in_array($outcome, self::VALID_OUTCOMES, true)) {
            return Response::error('Invalid outcome. Must be one of: correct, incorrect, partial', 422);
        }

        $confidence = isset($body['confidence_in_retrospect'])
            ? (float)$body['confidence_in_retrospect']
            : 0.0;

        if ($confidence < 0.0 || $confidence > 1.0) {
            return Response::error('confidence_in_retrospect must be between 0 and 1', 422);
        }

        $postmortem = $this->postmortemRepo->create([
            'id'                       => bin2hex(random_bytes(8)),
            'session_id'               => $sessionId,
            'outcome'                  => $outcome,
            'confidence_in_retrospect' => $confidence,
            'notes'                    => $body['notes'] ?? null,
            'created_at'               => date('c'),
        ]);

        return ['postmortem' => $postmortem];
    }

    /**
     * GET /api/sessions/{id}/postmortem
     */
    public function show(Request $req): array {
        $sessionId = $req->param('id');

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $postmortem = $this->postmortemRepo->findBySession($sessionId);
        return ['postmortem' => $postmortem];
    }

    /**
     * GET /api/postmortems/stats
     */
    public function stats(Request $req): array {
        return $this->postmortemRepo->getStats();
    }
}

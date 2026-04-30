<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;
use Domain\Orchestration\DebateAuditService;
use Domain\Orchestration\DebateHighlightService;

class AuditController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DebateRepository  $debateRepo;
    private VoteRepository $voteRepo;
    private DebateAuditService $auditService;
    private DebateHighlightService $highlightService;

    public function __construct() {
        $this->sessionRepo  = new SessionRepository();
        $this->messageRepo  = new MessageRepository();
        $this->debateRepo   = new DebateRepository();
        $this->voteRepo     = new VoteRepository();
        $this->auditService = new DebateAuditService();
        $this->highlightService = new DebateHighlightService();
    }

    public function audit(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages  = $this->messageRepo->findBySession($id);
        $edges     = $this->debateRepo->findEdgesBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);

        $result = $this->auditService->audit($messages, $edges, $positions, $arguments);
        $votes  = $this->voteRepo->findVotesBySession($id);
        $decision = $this->voteRepo->findDecisionBySession($id);
        $agentMsgCount = count(array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'user'));
        $highlights = $this->highlightService->compute($edges, $arguments, $result, $agentMsgCount, $votes, $decision);
        $result['highlights'] = $highlights;

        return [
            'session_id' => $id,
            'audit'      => $result,
        ];
    }
}

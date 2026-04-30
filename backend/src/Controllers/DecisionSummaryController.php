<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Domain\Orchestration\DebateAuditService;
use Domain\Orchestration\DebateHighlightService;
use Domain\Orchestration\DecisionSummaryService;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;
use Infrastructure\Persistence\VerdictRepository;

class DecisionSummaryController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DebateRepository $debateRepo;
    private VoteRepository $voteRepo;
    private VerdictRepository $verdictRepo;
    private DebateAuditService $auditService;
    private DebateHighlightService $highlightService;
    private DecisionSummaryService $summaryService;

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->messageRepo    = new MessageRepository();
        $this->debateRepo     = new DebateRepository();
        $this->voteRepo       = new VoteRepository();
        $this->verdictRepo    = new VerdictRepository();
        $this->auditService   = new DebateAuditService();
        $this->highlightService = new DebateHighlightService();
        $this->summaryService = new DecisionSummaryService();
    }

    /** GET /api/sessions/{id}/decision-summary */
    public function show(Request $req): array {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages  = $this->messageRepo->findBySession($id);
        $edges     = $this->debateRepo->findEdgesBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);
        $votes     = $this->voteRepo->findVotesBySession($id);
        $decision  = $this->voteRepo->findDecisionBySession($id);
        $verdict   = $this->verdictRepo->findBySession($id);

        $audit = $this->auditService->audit($messages, $edges, $positions, $arguments);
        $agentMsgCount = count(array_filter($messages, fn($m) => ($m['role'] ?? '') !== 'user'));

        $highlights = $this->highlightService->compute(
            $edges,
            $arguments,
            $audit,
            $agentMsgCount,
            $votes,
            $decision
        );

        $payload = $this->summaryService->build(
            $session,
            $verdict,
            $decision,
            $votes,
            $arguments,
            $highlights
        );

        return [
            'session_id'       => $id,
            'decision_summary' => $payload,
        ];
    }
}

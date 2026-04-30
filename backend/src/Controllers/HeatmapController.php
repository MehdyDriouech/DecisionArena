<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;
use Domain\Orchestration\ArgumentHeatmapService;

class HeatmapController {
    private SessionRepository      $sessionRepo;
    private MessageRepository      $messageRepo;
    private DebateRepository       $debateRepo;
    private VoteRepository         $voteRepo;
    private ArgumentHeatmapService $heatmapService;

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->messageRepo    = new MessageRepository();
        $this->debateRepo     = new DebateRepository();
        $this->voteRepo       = new VoteRepository();
        $this->heatmapService = new ArgumentHeatmapService();
    }

    public function show(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages  = $this->messageRepo->findBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);
        $edges     = $this->debateRepo->findEdgesBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);
        $votes     = $this->voteRepo->findVotesBySession($id);

        $result = $this->heatmapService->audit($id, $arguments, $messages, $votes, $edges, $positions);

        return array_merge(['session_id' => $id], $result);
    }
}

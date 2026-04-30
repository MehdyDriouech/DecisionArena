<?php
namespace Controllers;

use Domain\Vote\VoteAggregator;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\VoteRepository;

class VoteController {
    private SessionRepository $sessionRepo;
    private VoteRepository $voteRepo;
    private VoteAggregator $aggregator;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->voteRepo = new VoteRepository();
        $this->aggregator = new VoteAggregator($this->voteRepo);
    }

    public function show(Request $req): array {
        $sessionId = $req->param('id');
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = (float)($session['decision_threshold'] ?? 0.55);
        if ($threshold <= 0 || $threshold >= 1) $threshold = 0.55;
        $decision = $this->voteRepo->findDecisionBySession($sessionId);
        return [
            'votes'              => $this->voteRepo->findVotesBySession($sessionId),
            'decision'           => $decision,
            'automatic_decision' => $decision,
            'decision_threshold' => $threshold,
        ];
    }

    public function explanation(Request $req): array {
        $sessionId = $req->param('id');
        $session   = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = (float)($session['decision_threshold'] ?? 0.55);
        if ($threshold <= 0 || $threshold >= 1) $threshold = 0.55;
        return $this->aggregator->getDecisionExplanation($sessionId, $threshold);
    }

    public function recompute(Request $req): array {
        $sessionId = $req->param('id');
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = (float)($session['decision_threshold'] ?? 0.55);
        if ($threshold <= 0 || $threshold >= 1) $threshold = 0.55;
        $decision = $this->aggregator->recompute($sessionId, $threshold);
        $votes    = $this->voteRepo->findVotesBySession($sessionId);
        return [
            'votes'              => $votes,
            'decision'           => $decision,
            'automatic_decision' => $decision,
            'threshold_used'     => $threshold,
        ];
    }
}

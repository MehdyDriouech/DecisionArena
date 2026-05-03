<?php
namespace Controllers;

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Vote\VoteAggregator;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\ConfidenceTimelineRepository;
use Infrastructure\Persistence\PersonaScoreRepository;
use Infrastructure\Persistence\BiasReportRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\VoteRepository;
use Domain\Orchestration\PromptBuilder;

class VoteController {
    private SessionRepository $sessionRepo;
    private VoteRepository $voteRepo;
    private VoteAggregator $aggregator;
    private DebateRepository $debateRepo;
    private ContextDocumentRepository $contextDocRepo;
    private ConfidenceTimelineRepository $timelineRepo;
    private PersonaScoreRepository $personaScoreRepo;
    private BiasReportRepository $biasRepo;
    private DecisionReliabilityService $reliabilityService;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->voteRepo = new VoteRepository();
        $this->aggregator = new VoteAggregator($this->voteRepo);
        $this->debateRepo = new DebateRepository();
        $this->contextDocRepo = new ContextDocumentRepository();
        $this->timelineRepo = new ConfidenceTimelineRepository();
        $this->personaScoreRepo = new PersonaScoreRepository();
        $this->biasRepo = new BiasReportRepository();
        $this->reliabilityService = new DecisionReliabilityService();
    }

    public function show(Request $req): array {
        $sessionId = $req->param('id');
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        $decision = $this->voteRepo->findDecisionBySession($sessionId);
        $votes = $this->voteRepo->findVotesBySession($sessionId);
        $timelineRows = $this->timelineRepo->findBySession($sessionId);
        $reliability = $this->reliabilityService->buildEnvelope(
            (string)($session['initial_prompt'] ?? ''),
            (new PromptBuilder())->prepareContextDocumentForPrompt(
                $this->contextDocRepo->findBySession($sessionId)
            ),
            $decision,
            $votes,
            $this->debateRepo->findPositionsBySession($sessionId),
            $this->debateRepo->findEdgesBySession($sessionId),
            $threshold,
            $timelineRows ? ['rounds' => $timelineRows] : null,
            $this->personaScoreRepo->findBySession($sessionId),
            $this->biasRepo->findBySession($sessionId)
        );
        return [
            'votes'              => $votes,
            'decision'           => $decision,
            'automatic_decision' => $decision,
            'decision_threshold' => $threshold,
            'raw_decision' => $reliability['raw_decision'],
            'adjusted_decision' => $reliability['adjusted_decision'],
            'context_quality' => $reliability['context_quality'],
            'reliability_cap' => $reliability['reliability_cap'],
            'false_consensus_risk' => $reliability['false_consensus_risk'],
            'false_consensus' => $reliability['false_consensus'],
            'reliability_warnings' => $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
        ];
    }

    public function explanation(Request $req): array {
        $sessionId = $req->param('id');
        $session   = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        return $this->aggregator->getDecisionExplanation($sessionId, $threshold);
    }

    public function recompute(Request $req): array {
        $sessionId = $req->param('id');
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $threshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        $decision = $this->aggregator->recompute($sessionId, $threshold);
        $votes    = $this->voteRepo->findVotesBySession($sessionId);
        $timelineRows = $this->timelineRepo->findBySession($sessionId);
        $reliability = $this->reliabilityService->buildEnvelope(
            (string)($session['initial_prompt'] ?? ''),
            (new PromptBuilder())->prepareContextDocumentForPrompt(
                $this->contextDocRepo->findBySession($sessionId)
            ),
            $decision,
            $votes,
            $this->debateRepo->findPositionsBySession($sessionId),
            $this->debateRepo->findEdgesBySession($sessionId),
            $threshold,
            $timelineRows ? ['rounds' => $timelineRows] : null,
            $this->personaScoreRepo->findBySession($sessionId),
            $this->biasRepo->findBySession($sessionId)
        );
        return [
            'votes'              => $votes,
            'decision'           => $decision,
            'automatic_decision' => $decision,
            'threshold_used'     => $threshold,
            'raw_decision' => $reliability['raw_decision'],
            'adjusted_decision' => $reliability['adjusted_decision'],
            'context_quality' => $reliability['context_quality'],
            'reliability_cap' => $reliability['reliability_cap'],
            'false_consensus_risk' => $reliability['false_consensus_risk'],
            'false_consensus' => $reliability['false_consensus'],
            'reliability_warnings' => $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
        ];
    }
}

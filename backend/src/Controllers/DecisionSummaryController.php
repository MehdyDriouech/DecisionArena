<?php
namespace Controllers;

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
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
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\ConfidenceTimelineRepository;
use Infrastructure\Persistence\PersonaScoreRepository;
use Infrastructure\Persistence\BiasReportRepository;

class DecisionSummaryController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DebateRepository $debateRepo;
    private VoteRepository $voteRepo;
    private VerdictRepository $verdictRepo;
    private ContextDocumentRepository $contextDocRepo;
    private ConfidenceTimelineRepository $timelineRepo;
    private PersonaScoreRepository $personaScoreRepo;
    private BiasReportRepository $biasRepo;
    private DebateAuditService $auditService;
    private DebateHighlightService $highlightService;
    private DecisionSummaryService $summaryService;
    private DecisionReliabilityService $reliabilityService;

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->messageRepo    = new MessageRepository();
        $this->debateRepo     = new DebateRepository();
        $this->voteRepo       = new VoteRepository();
        $this->verdictRepo    = new VerdictRepository();
        $this->contextDocRepo = new ContextDocumentRepository();
        $this->timelineRepo   = new ConfidenceTimelineRepository();
        $this->personaScoreRepo = new PersonaScoreRepository();
        $this->biasRepo       = new BiasReportRepository();
        $this->auditService   = new DebateAuditService();
        $this->highlightService = new DebateHighlightService();
        $this->summaryService = new DecisionSummaryService();
        $this->reliabilityService = new DecisionReliabilityService();
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
        $contextDoc = $this->contextDocRepo->findBySession($id);
        $threshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        $objective = (string)($session['initial_prompt'] ?? '');
        $timelineRows = $this->timelineRepo->findBySession($id);
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $decision,
            $votes,
            $positions,
            $edges,
            $threshold,
            $timelineRows ? ['rounds' => $timelineRows] : null,
            $this->personaScoreRepo->findBySession($id),
            $this->biasRepo->findBySession($id)
        );

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
            $reliability['adjusted_decision'] ?? $decision,
            $votes,
            $arguments,
            $highlights
        );

        $decisionBriefRaw = $session['decision_brief'] ?? null;
        $decisionBrief = $decisionBriefRaw
            ? (is_array($decisionBriefRaw) ? $decisionBriefRaw : json_decode($decisionBriefRaw, true))
            : null;

        return [
            'session_id'       => $id,
            'decision_summary' => $payload,
            'context_quality' => $reliability['context_quality'],
            'reliability_cap' => $reliability['reliability_cap'],
            'adjusted_decision' => $reliability['adjusted_decision'],
            'false_consensus_risk' => $reliability['false_consensus_risk'],
            'reliability_warnings' => $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
            'decision_brief' => $decisionBrief,
        ];
    }
}

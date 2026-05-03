<?php
namespace Controllers;

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\DebateMemoryService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ConfidenceTimelineRepository;
use Infrastructure\Persistence\PersonaScoreRepository;
use Infrastructure\Persistence\BiasReportRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Infrastructure\Persistence\SnapshotRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\JuryAdversarialReportRepository;
use Infrastructure\Persistence\VoteRepository;

class SessionController {
    private SessionRepository               $sessionRepo;
    private MessageRepository               $messageRepo;
    private SnapshotRepository              $snapshotRepo;
    private DebateRepository                $debateRepo;
    private DebateMemoryService             $debateMemory;
    private VoteRepository                  $voteRepo;
    private ContextDocumentRepository       $contextDocRepo;
    private ConfidenceTimelineRepository    $timelineRepo;
    private PersonaScoreRepository          $personaScoreRepo;
    private BiasReportRepository            $biasRepo;
    private DecisionReliabilityService      $reliabilityService;
    private SessionAgentProvidersRepository $agentProvidersRepo;
    private JuryAdversarialReportRepository $adversarialRepo;

    public function __construct() {
        $this->sessionRepo        = new SessionRepository();
        $this->messageRepo        = new MessageRepository();
        $this->snapshotRepo       = new SnapshotRepository();
        $this->debateRepo         = new DebateRepository();
        $this->debateMemory       = new DebateMemoryService($this->debateRepo);
        $this->voteRepo           = new VoteRepository();
        $this->contextDocRepo     = new ContextDocumentRepository();
        $this->timelineRepo       = new ConfidenceTimelineRepository();
        $this->personaScoreRepo   = new PersonaScoreRepository();
        $this->biasRepo           = new BiasReportRepository();
        $this->reliabilityService = new DecisionReliabilityService();
        $this->agentProvidersRepo = new SessionAgentProvidersRepository();
        $this->adversarialRepo    = new JuryAdversarialReportRepository();
    }

    public function index(Request $req): array {
        return $this->sessionRepo->findAll();
    }

    public function show(Request $req): array {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $messages = $this->messageRepo->findBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);
        $edges = $this->debateRepo->findEdgesBySession($id);
        $votes = $this->voteRepo->findVotesBySession($id);
        $decision = $this->voteRepo->findDecisionBySession($id);
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
        $state = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];

        // Prefer persisted reliability data (set during the live run) over re-computed values.
        // Old sessions without a result column fall back to the re-computed envelope.
        $persisted = null;
        if (!empty($session['result'])) {
            $persisted = json_decode($session['result'], true);
        }
        $rawDecision      = $persisted['raw_decision']        ?? $reliability['raw_decision'];
        $adjustedDecision = $persisted['adjusted_decision']   ?? $reliability['adjusted_decision'];
        $falseConsensus   = $persisted['false_consensus']     ?? $reliability['false_consensus'];
        $guardrails       = $persisted['guardrails']          ?? null;
        $autoRetry        = $persisted['auto_retry']          ?? null;
        $qualityScore     = $persisted['decision_quality_score'] ?? null;

        $decisionBriefRaw = $session['decision_brief'] ?? null;
        $decisionBrief = $decisionBriefRaw
            ? (is_array($decisionBriefRaw) ? $decisionBriefRaw : json_decode($decisionBriefRaw, true))
            : null;

        // Load persisted adversarial report for jury sessions
        $juryAdversarial = null;
        if (($session['mode'] ?? '') === 'jury') {
            $juryAdversarial = $this->adversarialRepo->findBySession($id);
        }

        return [
            'session' => $session,
            'messages' => $messages,
            'arguments' => $arguments,
            'positions' => $positions,
            'interaction_edges' => $edges,
            'weighted_analysis' => $this->debateMemory->buildWeightedAnalysis($state),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($state),
            'votes' => $votes,
            'automatic_decision' => $decision,
            'raw_decision' => $rawDecision,
            'adjusted_decision' => $adjustedDecision,
            'context_quality' => $reliability['context_quality'],
            'reliability_cap' => $reliability['reliability_cap'],
            'false_consensus_risk' => $reliability['false_consensus_risk'],
            'false_consensus' => $falseConsensus,
            'reliability_warnings' => $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
            'guardrails' => $guardrails,
            'auto_retry' => $autoRetry,
            'decision_quality_score' => $qualityScore,
            'decision_brief' => $decisionBrief,
            'jury_adversarial' => $juryAdversarial,
        ];
    }

    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['title'])) {
            return Response::error('title required', 400);
        }
        $now = date('c');
        $id  = $this->uuid();
        $session = [
            'id'                   => $id,
            'title'                => $data['title'],
            'mode'                 => $data['mode'] ?? 'chat',
            'initial_prompt'       => $data['initial_prompt'] ?? '',
            'selected_agents'      => json_encode($data['selected_agents'] ?? []),
            'rounds'               => (int)($data['rounds'] ?? 2),
            'language'             => $data['language'] ?? 'en',
            'status'               => 'draft',
            'cf_rounds'            => (int)($data['cf_rounds'] ?? 3),
            'cf_interaction_style' => $data['cf_interaction_style'] ?? 'sequential',
            'cf_reply_policy'      => $data['cf_reply_policy'] ?? 'all-agents-reply',
            'is_favorite'          => 0,
            'is_reference'         => 0,
            'force_disagreement'   => (int)($data['force_disagreement'] ?? 0),
            'decision_threshold'   => ReliabilityConfig::normalizeThreshold($data['decision_threshold'] ?? null),
            'parent_session_id'    => $data['parent_session_id'] ?? null,
            'rerun_reason'         => $data['rerun_reason'] ?? null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        // Persist blue_team_agents / red_team_agents if provided
        if (!empty($data['blue_team_agents']) && is_array($data['blue_team_agents'])) {
            $session['blue_team_agents'] = json_encode($data['blue_team_agents']);
        }
        if (!empty($data['red_team_agents']) && is_array($data['red_team_agents'])) {
            $session['red_team_agents'] = json_encode($data['red_team_agents']);
        }

        $created = $this->sessionRepo->create($session);

        // Convert team_provider_assignments to agent_providers if present
        $agentProviders = is_array($data['agent_providers'] ?? null) ? $data['agent_providers'] : [];
        if (!empty($data['team_provider_assignments']) && is_array($data['team_provider_assignments'])) {
            $blueAgents = is_array($data['blue_team_agents'] ?? null) ? $data['blue_team_agents'] : [];
            $redAgents  = is_array($data['red_team_agents']  ?? null) ? $data['red_team_agents']  : [];
            $blueAssign = $data['team_provider_assignments']['blue'] ?? [];
            $redAssign  = $data['team_provider_assignments']['red']  ?? [];

            foreach ($blueAgents as $agentId) {
                if (!isset($agentProviders[$agentId]) && !empty($blueAssign['provider_id'])) {
                    $agentProviders[(string)$agentId] = [
                        'provider_id' => $blueAssign['provider_id'],
                        'model'       => $blueAssign['model'] ?? null,
                    ];
                }
            }
            foreach ($redAgents as $agentId) {
                if (!isset($agentProviders[$agentId]) && !empty($redAssign['provider_id'])) {
                    $agentProviders[(string)$agentId] = [
                        'provider_id' => $redAssign['provider_id'],
                        'model'       => $redAssign['model'] ?? null,
                    ];
                }
            }
        }

        if (!empty($agentProviders)) {
            $this->agentProvidersRepo->saveForSession($id, $agentProviders);
        }

        return $created;
    }

    /**
     * GET /api/sessions/{id}/agent-providers
     * Returns per-agent provider overrides for the session.
     */
    public function agentProviders(Request $req): array {
        $id      = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        return [
            'session_id'      => $id,
            'agent_providers' => $this->agentProvidersRepo->findBySession($id),
        ];
    }

    public function memory(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $allowed = ['is_favorite','is_reference','decision_taken','user_learnings','follow_up_notes'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (!empty($updates)) {
            $this->sessionRepo->update($id, $updates);
        }
        return ['session' => $this->sessionRepo->findById($id)];
    }

    public function updateThreshold(Request $req): array {
        $id      = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $data      = $req->body();
        $threshold = ReliabilityConfig::normalizeThreshold($data['decision_threshold'] ?? null);
        $this->sessionRepo->update($id, ['decision_threshold' => $threshold]);
        return ['session' => $this->sessionRepo->findById($id)];
    }

    public function delete(Request $req): array {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $this->pdo()->exec("DELETE FROM messages WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_snapshots WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_context_documents WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_verdicts WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_action_plans WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM arguments WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM agent_positions WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM interaction_edges WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_votes WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_decisions WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM sessions WHERE id = " . $this->pdo()->quote($id));
        return ['success' => true, 'deleted_id' => $id];
    }

    public function deleteAll(Request $req): array {
        $this->pdo()->exec("DELETE FROM messages");
        $this->pdo()->exec("DELETE FROM session_snapshots");
        $this->pdo()->exec("DELETE FROM session_context_documents");
        $this->pdo()->exec("DELETE FROM session_verdicts");
        $this->pdo()->exec("DELETE FROM session_action_plans");
        $this->pdo()->exec("DELETE FROM arguments");
        $this->pdo()->exec("DELETE FROM agent_positions");
        $this->pdo()->exec("DELETE FROM interaction_edges");
        $this->pdo()->exec("DELETE FROM session_votes");
        $this->pdo()->exec("DELETE FROM session_decisions");
        $this->pdo()->exec("DELETE FROM sessions");
        return ['success' => true];
    }

    public function updateStatus(Request $req): array {
        $id     = $req->param('id');
        $data   = $req->body();
        $status = $data['status'] ?? 'completed';
        $this->pdo()->exec(
            "UPDATE sessions SET status = " . $this->pdo()->quote($status) .
            ", updated_at = " . $this->pdo()->quote(date('c')) .
            " WHERE id = " . $this->pdo()->quote($id)
        );
        return ['success' => true];
    }

    public function runStatus(Request $req): array {
        $sessionId = $req->param('id');
        $pdo  = \Infrastructure\Persistence\Database::getConnection();
        $stmt = $pdo->prepare("SELECT run_status FROM sessions WHERE id = :id");
        $stmt->execute([':id' => $sessionId]);
        $row    = $stmt->fetch(\PDO::FETCH_ASSOC);
        $status = $row ? json_decode($row['run_status'] ?? 'null', true) : null;
        return ['run_status' => $status];
    }

    private function pdo(): \PDO {
        return \Infrastructure\Persistence\Database::getInstance()->pdo();
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

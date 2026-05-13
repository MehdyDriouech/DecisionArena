<?php
namespace Controllers;

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Agents\DecisionDynamicsPreset;
use Domain\DecisionReliability\MemorySummaryBuilder;
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
use Infrastructure\Persistence\PersonaDecisionDynamicsRepository;
use Infrastructure\Persistence\RunStatusRepository;
use Domain\Sessions\SessionStrategicContextGuard;
use Domain\Orchestration\PromptBuilder;
use Domain\Vote\VoteAggregator;
use Infrastructure\Persistence\VoteRepository;
use Infrastructure\Persistence\StrategicContextRepository;

class SessionController {
    private const LIFECYCLE_ALLOWED = ['draft', 'running', 'completed', 'archived'];
    private const LIFECYCLE_ALIASES = [
        'active' => 'running',
    ];
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
        if (trim((string)$req->query('all_contexts', '')) === '1') {
            return $this->sessionRepo->findAll('all');
        }
        $active = (new StrategicContextRepository())->getActiveContext();
        $aid = ($active['context_id'] ?? null) !== null && (string)$active['context_id'] !== ''
            ? (string)$active['context_id']
            : null;
        if ($aid !== null) {
            return $this->sessionRepo->findAll($aid);
        }
        return $this->sessionRepo->findAll('all');
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
        $contextDoc = (new PromptBuilder())->prepareContextDocumentForPrompt(
            $this->contextDocRepo->findBySession($id)
        );
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
        $premortemSummary = null;
        if (!empty($session['result'])) {
            $persisted = json_decode($session['result'], true);
            if (is_array($persisted)) {
                $premortemSummary = $persisted['premortem_summary'] ?? null;
            }
        }
        $rawDecision      = $persisted['raw_decision']        ?? $reliability['raw_decision'];
        $adjustedDecision = $persisted['adjusted_decision']   ?? $reliability['adjusted_decision'];
        $falseConsensus   = $persisted['false_consensus']     ?? $reliability['false_consensus'];
        $guardrails       = $persisted['guardrails']          ?? null;
        $autoRetry        = $persisted['auto_retry']          ?? null;
        $qualityScore     = $persisted['decision_quality_score'] ?? null;
        $canonicalSynthesis = $persisted['canonical_synthesis'] ?? null;
        $decisionOutcome = $persisted['decision_outcome'] ?? null;
        $playbookRuntime  = $persisted['playbook_runtime']    ?? null;

        $memorySummary = null;
        if (is_array($persisted) && isset($persisted['memory_summary']) && is_array($persisted['memory_summary'])) {
            $memorySummary = $persisted['memory_summary'];
        }
        $freshMemorySummary = MemorySummaryBuilder::buildMemorySummary($edges, $positions);
        if (!is_array($memorySummary)) {
            $memorySummary = $freshMemorySummary;
        } else {
            // Prefer live graph for memory_summary metrics; merge preserves any legacy extra keys from persisted.
            $memorySummary = array_merge($memorySummary, $freshMemorySummary);
        }

        $voteTimeline = (is_array($persisted) && isset($persisted['vote_timeline']) && is_array($persisted['vote_timeline']))
            ? $persisted['vote_timeline']
            : $votes;

        $finalVotes = null;
        if (is_array($persisted) && isset($persisted['final_votes']) && is_array($persisted['final_votes'])) {
            $finalVotes = $persisted['final_votes'];
        }
        if (!is_array($finalVotes) && is_array($decision)) {
            $vs = $decision['vote_summary'] ?? null;
            if (is_array($vs) && isset($vs['final_votes']) && is_array($vs['final_votes'])) {
                $finalVotes = $vs['final_votes'];
            }
        }
        if (!is_array($finalVotes)) {
            $finalVotes = (new VoteAggregator())->latestValidVotesByAgent($votes);
        }

        $decisionBriefRaw = $session['decision_brief'] ?? null;
        $decisionBrief = $decisionBriefRaw
            ? (is_array($decisionBriefRaw) ? $decisionBriefRaw : json_decode($decisionBriefRaw, true))
            : null;

        // Load persisted adversarial report for jury sessions
        $juryAdversarial = null;
        if (($session['mode'] ?? '') === 'jury') {
            $juryAdversarial = $this->adversarialRepo->findBySession($id);
        }

        $personaDynRepo            = new PersonaDecisionDynamicsRepository();
        $agentDecisionDynamicsRows = $personaDynRepo->transparencyForSession($session, $votes);

        return [
            'session' => $session,
            'messages' => $messages,
            'arguments' => $arguments,
            'positions' => $positions,
            'interaction_edges' => $edges,
            'weighted_analysis' => $this->debateMemory->buildWeightedAnalysis($state),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($state),
            'votes' => $voteTimeline,
            'vote_timeline' => $voteTimeline,
            'final_votes' => $finalVotes,
            'memory_summary' => $memorySummary,
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
            'canonical_synthesis' => $canonicalSynthesis,
            'decision_outcome' => $decisionOutcome,
            'playbook_runtime' => $playbookRuntime,
            'jury_adversarial' => $juryAdversarial,
            'agent_decision_dynamics' => $agentDecisionDynamicsRows,
            'premortem_summary'      => $premortemSummary,
        ];
    }

    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['title'])) {
            return Response::error('title required', 400);
        }

        $mode = (string)($data['mode'] ?? 'chat');
        $resolved = SessionStrategicContextGuard::resolveStrategicContextForCreation(
            $mode,
            is_array($data) ? $data : [],
            null
        );
        if ($resolved['block'] !== null) {
            return $resolved['block'];
        }
        $strategicContextId = $resolved['strategic_context_id'];

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
            'strategic_context_id' => $strategicContextId,
            'selected_memory_ids'  => is_array($data['selected_memory_ids'] ?? null)
                ? json_encode($data['selected_memory_ids'], JSON_UNESCAPED_UNICODE)
                : ($data['selected_memory_ids'] ?? '[]'),
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

        SessionStrategicContextGuard::syncStrategicContextSessionLink($strategicContextId, $id);

        // Convert team_provider_assignments to per-agent session_agent_providers (UX convenience).
        $agentProviders = $this->filterEmptyAgentProviderMap(
            is_array($data['agent_providers'] ?? null) ? $data['agent_providers'] : []
        );
        if (!empty($data['team_provider_assignments']) && is_array($data['team_provider_assignments'])) {
            $blueAgents = is_array($data['blue_team_agents'] ?? null) ? $data['blue_team_agents'] : [];
            $redAgents  = is_array($data['red_team_agents']  ?? null) ? $data['red_team_agents']  : [];
            $blueAssign = $data['team_provider_assignments']['blue'] ?? [];
            $redAssign  = $data['team_provider_assignments']['red']  ?? [];
            $bluePid = trim((string)($blueAssign['provider_id'] ?? ''));
            $redPid  = trim((string)($redAssign['provider_id'] ?? ''));

            foreach ($blueAgents as $agentId) {
                $normalizedAgentId = $this->normalizeAgentId((string)$agentId);
                if ($normalizedAgentId === '') {
                    continue;
                }
                if (!isset($agentProviders[$normalizedAgentId]) && $bluePid !== '') {
                    $m = trim((string)($blueAssign['model'] ?? ''));
                    $agentProviders[$normalizedAgentId] = [
                        'provider_id' => $bluePid,
                        'model'       => $m !== '' ? $m : null,
                    ];
                }
            }
            foreach ($redAgents as $agentId) {
                $normalizedAgentId = $this->normalizeAgentId((string)$agentId);
                if ($normalizedAgentId === '') {
                    continue;
                }
                if (!isset($agentProviders[$normalizedAgentId]) && $redPid !== '') {
                    $m = trim((string)($redAssign['model'] ?? ''));
                    $agentProviders[$normalizedAgentId] = [
                        'provider_id' => $redPid,
                        'model'       => $m !== '' ? $m : null,
                    ];
                }
            }
        }

        $agentProviders = $this->filterEmptyAgentProviderMap($agentProviders);

        if (!empty($agentProviders)) {
            $this->agentProvidersRepo->saveForSession($id, $agentProviders);
        }

        $preset = DecisionDynamicsPreset::normalizeId($data['decision_dynamics_preset'] ?? DecisionDynamicsPreset::BALANCED);
        try {
            $this->sessionRepo->update($id, ['decision_dynamics_preset' => $preset]);
        } catch (\Throwable $e) {
            // Column may be missing on very old databases
        }

        $ff = $data['facilitation_framework'] ?? null;
        if (is_string($ff) && $ff !== '' && preg_match('/^[a-z0-9_-]+$/', $ff)) {
            try {
                $this->sessionRepo->update($id, ['facilitation_framework' => $ff]);
            } catch (\Throwable $e) {
            }
        }

        $sessionVariant = $data['session_variant'] ?? null;
        if (is_string($sessionVariant) && $sessionVariant !== '' && preg_match('/^[a-z0-9_-]+$/', $sessionVariant)) {
            try {
                $this->sessionRepo->update($id, ['session_variant' => $sessionVariant]);
            } catch (\Throwable $e) {
            }
        }

        return $this->sessionRepo->findById($id);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array{provider_id: string, model: ?string}>
     */
    private function filterEmptyAgentProviderMap(array $raw): array {
        $out = [];
        foreach ($raw as $agentId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedAgentId = $this->normalizeAgentId((string)$agentId);
            if ($normalizedAgentId === '') {
                continue;
            }
            $pid = trim((string)($row['provider_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $model = trim((string)($row['model'] ?? ''));
            $out[$normalizedAgentId] = [
                'provider_id' => $pid,
                'model'       => $model !== '' ? $model : null,
            ];
        }
        return $out;
    }

    private function normalizeAgentId(string $agentId): string {
        return strtolower(trim($agentId));
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
        $rawStatus = strtolower(trim((string)($data['status'] ?? 'completed')));
        if ($rawStatus === '') {
            $rawStatus = 'completed';
        }
        $status = self::LIFECYCLE_ALIASES[$rawStatus] ?? $rawStatus;
        if (!in_array($status, self::LIFECYCLE_ALLOWED, true)) {
            return Response::error('Invalid status. Allowed: draft|running|completed|archived', 400);
        }
        $this->sessionRepo->update($id, ['status' => $status]);
        return ['success' => true, 'status' => $status];
    }

    public function runStatus(Request $req): array {
        $sessionId = $req->param('id');
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $runStatusRepo = new RunStatusRepository();
        $raw = $runStatusRepo->load($sessionId) ?? [];
        $messages = $this->messageRepo->findBySession($sessionId);
        $payload = $this->buildRunStatusPayload($session, $raw, $messages);

        $legacy = array_merge(
            is_array($raw) ? $raw : [],
            [
                'status' => $payload['status'],
                'phase' => $payload['progress']['current_phase'] ?? null,
                'current_round' => $payload['progress']['current_round'] ?? null,
                'total_rounds' => $payload['progress']['total_rounds'] ?? null,
                'started_at' => $payload['started_at'],
                'updated_at' => $payload['updated_at'],
                'completed_at' => $payload['completed_at'],
                'error' => $payload['last_error'],
            ]
        );
        return array_merge($payload, ['run_status' => $legacy]);
    }

    private function buildRunStatusPayload(array $session, array $raw, array $messages): array
    {
        $mode = (string)($session['mode'] ?? 'chat');
        $sessionStatus = strtolower((string)($session['status'] ?? 'draft'));
        $status = (string)($raw['status'] ?? ($sessionStatus === 'completed' ? 'completed' : ($sessionStatus === 'running' ? 'running' : 'idle')));

        $events = $this->normalizeRuntimeEvents($raw['events'] ?? []);
        $lastEvent = !empty($events) ? $events[count($events) - 1] : null;
        $lastMessage = !empty($messages) ? $messages[count($messages) - 1] : null;
        $lastMessageAt = $lastMessage['created_at'] ?? null;

        $startedAt = (string)($raw['started_at'] ?? ($events[0]['ts'] ?? ($session['updated_at'] ?? $session['created_at'] ?? date('c'))));
        $updatedAt = (string)($raw['updated_at'] ?? ($lastEvent['ts'] ?? ($lastMessageAt ?? ($session['updated_at'] ?? date('c')))));
        $completedAt = $raw['completed_at'] ?? (($status === 'completed' || $status === 'failed' || $status === 'blocked') ? $updatedAt : null);
        $elapsedSeconds = $this->elapsedSeconds($startedAt, $updatedAt);

        $rawProgress = isset($raw['progress']) && is_array($raw['progress']) ? $raw['progress'] : [];
        $currentRound = (int)($rawProgress['current_round'] ?? ($lastMessage['round'] ?? 0));
        $totalRounds = (int)($rawProgress['total_rounds'] ?? ($mode === 'confrontation' ? ($session['cf_rounds'] ?? 3) : ($session['rounds'] ?? 3)));
        if ($totalRounds <= 0) {
            $totalRounds = 1;
        }
        $currentPhase = (string)($rawProgress['current_phase'] ?? ($raw['phase'] ?? ($lastEvent['phase'] ?? ($lastMessage['phase'] ?? 'idle'))));
        $phaseLabel = (string)($rawProgress['current_phase_label'] ?? $this->phaseLabel($currentPhase));
        $currentTeam = $rawProgress['current_team'] ?? ($lastEvent['team'] ?? null);
        $currentAgentId = $rawProgress['current_agent_id'] ?? ($lastEvent['agent_id'] ?? ($lastMessage['agent_id'] ?? null));
        $currentAgentName = $rawProgress['current_agent_name'] ?? null;
        $currentStep = (string)($rawProgress['current_step'] ?? ($status === 'running' ? 'llm_call' : 'done'));
        $estimated = array_key_exists('estimated', $rawProgress) ? (bool)$rawProgress['estimated'] : true;
        $percent = isset($rawProgress['percent'])
            ? (int)$rawProgress['percent']
            : $this->estimatedPercent($status, $mode, $currentRound, $totalRounds, $currentPhase);
        // Stale guard: persisted percent can stay high while round/phase show "not started" yet.
        if ($status === 'running' && $currentRound <= 0
            && in_array($currentPhase, ['idle', '', 'session_started'], true)
            && count($events) === 0
            && $percent > 15
        ) {
            $percent = $this->estimatedPercent($status, $mode, $currentRound, $totalRounds, $currentPhase);
        }
        if ($status !== 'completed') {
            $percent = min(99, max(0, $percent));
        } else {
            $percent = 100;
        }

        return [
            'session_id' => (string)($session['id'] ?? ''),
            'mode' => $mode,
            'status' => $status,
            'started_at' => $startedAt,
            'updated_at' => $updatedAt,
            'completed_at' => $completedAt,
            'elapsed_seconds' => $elapsedSeconds,
            'progress' => [
                'percent' => $percent,
                'current_round' => $currentRound,
                'total_rounds' => $totalRounds,
                'current_phase' => $currentPhase,
                'current_phase_label' => $phaseLabel,
                'current_team' => $currentTeam,
                'current_agent_id' => $currentAgentId,
                'current_agent_name' => $currentAgentName,
                'current_step' => $currentStep,
                'estimated' => $estimated,
            ],
            'events' => $events,
            'last_message_at' => $lastMessageAt,
            'last_error' => $raw['last_error'] ?? null,
        ];
    }

    private function normalizeRuntimeEvents($events): array
    {
        if (!is_array($events)) {
            return [];
        }
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $out[] = [
                'ts' => (string)($event['ts'] ?? date('c')),
                'level' => (string)($event['level'] ?? 'info'),
                'phase' => $event['phase'] ?? null,
                'round' => isset($event['round']) ? (int)$event['round'] : null,
                'team' => $event['team'] ?? null,
                'agent_id' => $event['agent_id'] ?? null,
                'label' => (string)($event['label'] ?? ''),
            ];
        }
        usort($out, fn(array $a, array $b): int => strcmp((string)$a['ts'], (string)$b['ts']));
        return $out;
    }

    private function estimatedPercent(string $status, string $mode, int $currentRound, int $totalRounds, string $phase): int
    {
        if ($status === 'completed') {
            return 100;
        }
        if (in_array($status, ['idle', 'pending', 'draft'], true)) {
            if ($currentRound <= 0) {
                return 0;
            }
            $safeTotal = max(1, $totalRounds);
            return (int)round(min(0.99, $currentRound / $safeTotal) * 100);
        }
        if ($status === 'failed') {
            return 0;
        }
        if ($status === 'blocked') {
            return 10;
        }
        $safeTotal = max(1, $totalRounds);
        $ratio = ($currentRound > 0 ? $currentRound / $safeTotal : 0.02);
        if ($mode === 'confrontation') {
            $weight = match ($phase) {
                'session_started' => 0.02,
                'blue_opening' => 0.18,
                'red_attack' => 0.45,
                'blue_rebuttal' => 0.7,
                'synthesis', 'synthesis_started' => 0.88,
                default => $ratio,
            };
            return (int)round(min(0.99, max($ratio, $weight)) * 100);
        }
        return (int)round(min(0.99, max(0.02, $ratio)) * 100);
    }

    private function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'session_started' => 'Session demarree',
            'round_started' => 'Round en cours',
            'blue_opening' => 'Blue Team ouverture',
            'red_attack' => 'Red Team attaque',
            'blue_rebuttal' => 'Blue Team riposte',
            'analysis' => 'Analyse agents',
            'stress-analysis' => 'Stress test analyse',
            'stress-synthesis' => 'Stress test synthese',
            'jury-opening' => 'Jury ouverture',
            'jury-cross-examination' => 'Jury contre-interrogatoire',
            'jury-defense' => 'Jury defense',
            'jury-deliberation' => 'Jury deliberation',
            'jury-minority-report' => 'Jury minority report',
            'jury-mini-challenge' => 'Jury mini challenge',
            'jury-verdict' => 'Jury verdict',
            'synthesis' => 'Synthese',
            'synthesis_started' => 'Synthese',
            'synthesis_completed' => 'Synthese terminee',
            'auto_retry_started' => 'Auto-retry demarre',
            'auto_retry_completed' => 'Auto-retry termine',
            'session_completed' => 'Session terminee',
            'session_failed' => 'Session en echec',
            default => $phase !== '' ? $phase : 'En cours',
        };
    }

    private function elapsedSeconds(string $startedAt, string $updatedAt): int
    {
        $startTs = strtotime($startedAt);
        $endTs = strtotime($updatedAt);
        if ($startTs === false || $endTs === false || $endTs < $startTs) {
            return 0;
        }
        return (int)($endTs - $startTs);
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

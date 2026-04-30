<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\ConfrontationRunner;

class ConfrontationController {
    private SessionRepository $sessionRepo;
    private ConfrontationRunner $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->runner      = new ConfrontationRunner();
    }

    public function run(Request $req): array {
        $data = $req->body();

        $sessionId       = $data['session_id'] ?? '';
        $objective       = $data['objective']  ?? '';

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        // Resolve selected agents (new unified list or legacy blue/red teams)
        $blueTeam       = $data['blue_team'] ?? [];
        $redTeam        = $data['red_team']  ?? [];
        $selectedAgents = $data['selected_agents'] ?? [];

        if (empty($selectedAgents)) {
            $selectedAgents = array_unique(array_merge(
                $blueTeam ?: ['pm', 'architect', 'po'],
                $redTeam  ?: ['analyst', 'critic']
            ));
        }

        // Confrontation settings (from request or session defaults)
        $rounds            = (int)($data['rounds']             ?? $session['cf_rounds']             ?? 3);
        $interactionStyle  = $data['interaction_style']        ?? $session['cf_interaction_style']  ?? 'sequential';
        $replyPolicy       = $data['reply_policy']             ?? $session['cf_reply_policy']       ?? 'all-agents-reply';
        $includeSynthesis  = (bool)($data['include_synthesis'] ?? $data['final_synthesis']          ?? true);
        $forceDisagreement = (bool)($data['force_disagreement'] ?? $session['force_disagreement']   ?? false);
        $language   = $session['language'] ?? 'fr';
        $contextDoc = (new ContextDocumentRepository())->findBySession($sessionId);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);

        if (!in_array($interactionStyle, ['sequential', 'agent-to-agent'], true)) {
            return Response::error('Invalid interaction_style', 400);
        }
        if (!in_array($replyPolicy, ['all-agents-reply', 'only-mentioned-agent-replies', 'critic-priority'], true)) {
            return Response::error('Invalid reply_policy', 400);
        }

        // Feature 3 & 4
        $daEnabled      = (bool)($session['devil_advocate_enabled']   ?? false);
        $daThreshold    = (float)($session['devil_advocate_threshold'] ?? 0.65);
        $agentProviders = (new \Infrastructure\Persistence\SessionAgentProvidersRepository())->findBySession($sessionId);

        $result = $this->runner->run(
            $sessionId,
            $objective,
            $selectedAgents,
            $includeSynthesis,
            $language,
            $rounds,
            $interactionStyle,
            $replyPolicy,
            $forceDisagreement,
            $contextDoc,
            $daEnabled,
            $daThreshold,
            $agentProviders,
            $decisionThreshold
        );

        // Mark session as completed
        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
        ]);

        return [
            'session_id'        => $sessionId,
            'rounds'            => $result['rounds'],
            'synthesis'         => $result['synthesis'],
            'verdict'           => $result['verdict'] ?? null,
            'total_rounds'      => $result['total_rounds'],
            'interaction_style' => $result['interaction_style'],
            'reply_policy'      => $result['reply_policy'] ?? $replyPolicy,
            'arguments'         => $result['arguments'] ?? [],
            'positions'         => $result['positions'] ?? [],
            'interaction_edges' => $result['interaction_edges'] ?? [],
            'weighted_analysis' => $result['weighted_analysis'] ?? [],
            'dominance_indicator' => $result['dominance_indicator'] ?? '',
            'votes'             => $result['votes'] ?? [],
            'automatic_decision'=> $result['automatic_decision'] ?? null,
            'raw_decision' => $result['raw_decision'] ?? null,
            'adjusted_decision' => $result['adjusted_decision'] ?? null,
            'context_quality' => $result['context_quality'] ?? null,
            'reliability_cap' => $result['reliability_cap'] ?? null,
            'false_consensus_risk' => $result['false_consensus_risk'] ?? 'low',
            'false_consensus' => $result['false_consensus'] ?? null,
            'reliability_warnings' => $result['reliability_warnings'] ?? [],
        ];
    }
}

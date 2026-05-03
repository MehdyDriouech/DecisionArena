<?php
namespace Controllers;

use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Domain\Orchestration\DecisionRoomRunner;

class DecisionRoomController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private DecisionRoomRunner $runner;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->messageRepo = new MessageRepository();
        $this->runner      = new DecisionRoomRunner();
    }

    public function run(Request $req): array {
        $data           = $req->body();
        $sessionId      = $data['session_id'] ?? '';
        $objective      = $data['objective'] ?? '';
        $selectedAgents    = $data['selected_agents'] ?? [];
        $rounds            = (int)($data['rounds'] ?? 2);

        if (!$sessionId || !$objective) {
            return Response::error('session_id and objective required', 400);
        }

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $forceDisagreement = array_key_exists('force_disagreement', $data)
            ? (bool)$data['force_disagreement']
            : (bool)($session['force_disagreement'] ?? false);

        if (empty($selectedAgents)) {
            $selectedAgents = json_decode($session['selected_agents'] ?? '[]', true);
        }

        $language   = $session['language'] ?? 'en';
        $contextDoc = (new ContextDocumentRepository())->findBySession($sessionId);
        $decisionThreshold = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);

        // Feature 3: Devil's Advocate — read from session config
        $daEnabled   = (bool)($session['devil_advocate_enabled']   ?? false);
        $daThreshold = (float)($session['devil_advocate_threshold'] ?? 0.65);

        // Feature 4: per-agent provider overrides
        $agentProviders = (new \Infrastructure\Persistence\SessionAgentProvidersRepository())->findBySession($sessionId);

        $sessionOptions = [
            'auto_retry_on_weak_debate' => (bool)($data['auto_retry_on_weak_debate'] ?? $session['auto_retry_on_weak_debate'] ?? false),
        ];

        $result = $this->runner->run(
            $sessionId, $objective, $selectedAgents, $rounds, $language,
            $forceDisagreement, $contextDoc,
            $daEnabled, $daThreshold, $agentProviders, $decisionThreshold, $sessionOptions
        );

        $this->sessionRepo->update($sessionId, [
            'status' => 'completed',
            'context_quality_score' => (float)($result['context_quality']['score'] ?? 0.0),
            'context_quality_level' => (string)($result['context_quality']['level'] ?? 'weak'),
            'context_quality_report' => json_encode($result['context_quality'] ?? [], JSON_UNESCAPED_UNICODE),
            'reliability_cap' => (float)($result['reliability_cap'] ?? 1.0),
            'result' => json_encode([
                'guardrails'             => $result['guardrails']             ?? null,
                'auto_retry'             => $result['auto_retry']             ?? null,
                'decision_quality_score' => $result['decision_quality_score'] ?? null,
                'adjusted_decision'      => $result['adjusted_decision']      ?? null,
                'false_consensus'        => $result['false_consensus']        ?? null,
                'raw_decision'           => $result['raw_decision']           ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'decision_brief' => json_encode($result['decision_brief'] ?? null, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'session_id'   => $sessionId,
            'rounds'       => $result['rounds'] ?? [],
            'total_rounds' => count($result['rounds'] ?? []),
            'arguments'    => $result['arguments'] ?? [],
            'positions'    => $result['positions'] ?? [],
            'interaction_edges' => $result['interaction_edges'] ?? [],
            'weighted_analysis' => $result['weighted_analysis'] ?? [],
            'dominance_indicator' => $result['dominance_indicator'] ?? '',
            'votes' => $result['votes'] ?? [],
            'automatic_decision' => $result['automatic_decision'] ?? null,
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

<?php
declare(strict_types=1);
namespace Controllers;

use Domain\Agents\DecisionDynamics;
use Domain\Analysis\AgentPerformanceAnalyzer;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\PersonaDecisionDynamicsRepository;
use Infrastructure\Persistence\PostmortemRepository;
use Infrastructure\Persistence\SessionRepository;

/**
 * Post-mortem-driven recommendations for agent decision dynamics — suggestions only;
 * apply endpoint requires explicit confirm and re-validates suggestion against live analysis.
 */
class AgentDynamicsRecommendationController {
    private PostmortemRepository $postmortemRepo;
    private SessionRepository $sessionRepo;
    private PersonaDecisionDynamicsRepository $dynamicsRepo;
    private AgentPerformanceAnalyzer $analyzer;

    public function __construct() {
        $this->postmortemRepo = new PostmortemRepository();
        $this->sessionRepo    = new SessionRepository();
        $this->dynamicsRepo   = new PersonaDecisionDynamicsRepository();
        $this->analyzer       = new AgentPerformanceAnalyzer();
    }

    /**
     * GET /api/analysis/agent-dynamics-suggestions?session_id=optional
     */
    public function suggestions(Request $req): array {
        $rows          = $this->postmortemRepo->findAllWithSessions();
        $allSuggested  = $this->analyzer->buildSuggestions($rows);
        $sessionId     = trim((string)$req->query('session_id', ''));
        $filtered      = null;
        $topicKey      = null;

        if ($sessionId !== '') {
            $session = $this->sessionRepo->findById($sessionId);
            if (!$session) {
                return Response::error('Session not found', 404);
            }
            $topicKey = $this->analyzer->topicKeyForSession(
                (string)($session['mode'] ?? ''),
                (string)($session['initial_prompt'] ?? '')
            );
            $agents = $session['selected_agents'] ?? [];
            if (is_string($agents)) {
                $decoded = json_decode($agents, true);
                $agents = is_array($decoded) ? $decoded : [];
            }
            $filtered = $this->analyzer->filterSuggestionsForSession(
                $allSuggested,
                $topicKey,
                is_array($agents) ? $agents : []
            );
        }

        return [
            'disclaimer'           => 'Suggestions basees sur les post-mortems enregistres. Aucune application automatique.',
            'suggestions'          => $filtered ?? $allSuggested,
            'full_suggestion_count'=> count($allSuggested),
            'session_id'           => $sessionId !== '' ? $sessionId : null,
            'topic_key'            => $topicKey,
            'filtered'             => $filtered !== null,
        ];
    }

    /**
     * POST /api/analysis/agent-dynamics-suggestions/apply
     * Body: { suggestion_id, confirm: true }
     */
    public function apply(Request $req): array {
        $data       = $req->body();
        $id         = isset($data['suggestion_id']) ? (string)$data['suggestion_id'] : '';
        $rawConfirm = $data['confirm'] ?? null;
        $confirmed  = $rawConfirm === true || $rawConfirm === 'true' || $rawConfirm === 1 || $rawConfirm === '1';
        if (!$confirmed) {
            return Response::error('confirm doit être true pour appliquer manuellement cette suggestion', 400);
        }
        if ($id === '') {
            return Response::error('suggestion_id requis', 400);
        }

        $rows         = $this->postmortemRepo->findAllWithSessions();
        $candidates   = $this->analyzer->buildSuggestions($rows);
        $match        = null;
        foreach ($candidates as $s) {
            if (($s['id'] ?? '') === $id) {
                $match = $s;
                break;
            }
        }
        if ($match === null) {
            return Response::error(
                'Suggestion inconnue ou obsolète — recalculez la liste (les données post-mortem ont peut‑être changé).',
                409
            );
        }

        $agentId = (string)($match['agent_id'] ?? '');
        $agentId = preg_replace('/[^a-z0-9\-]/', '', $agentId);
        if ($agentId === '') {
            return Response::error('agent_id invalide', 400);
        }

        $type = (string)($match['suggestion'] ?? '');
        if (!in_array($type, ['increase_reputation', 'decrease_reputation'], true)) {
            return Response::error('Type de suggestion non pris en charge', 400);
        }

        $delta = (float)($match['reputation_delta'] ?? 0);
        if ($type === 'increase_reputation' && $delta <= 0) {
            return Response::error('Delta de réputation invalide', 400);
        }
        if ($type === 'decrease_reputation' && $delta >= 0) {
            return Response::error('Delta de réputation invalide', 400);
        }

        $current = $this->dynamicsRepo->resolveForPersona($agentId, null);
        $merged  = $current;
        $merged['reputation'] = (float)$current['reputation'] + $delta;
        $merged = DecisionDynamics::normalize($merged);

        $saved = $this->dynamicsRepo->save($agentId, $merged);

        return [
            'success'                => true,
            'applied_manually'       => true,
            'suggestion_id'          => $id,
            'agent_id'               => $agentId,
            'previous_reputation'    => $current['reputation'],
            'new_reputation'         => $saved['reputation'],
            'decision_dynamics'      => $saved,
        ];
    }
}

<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\DebateRepository;

class GraphController {
    private SessionRepository $sessionRepo;
    private DebateRepository  $debateRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->debateRepo  = new DebateRepository();
    }

    /**
     * GET /api/sessions/{id}/graph
     * Returns agents (nodes), interaction edges, argument memory, and agent positions.
     */
    public function show(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $edges     = $this->debateRepo->findEdgesBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);

        // Build unique node list from edges + positions
        $nodeIds = [];
        foreach ($edges as $edge) {
            $nodeIds[$edge['source_agent_id'] ?? ''] = true;
            $nodeIds[$edge['target_agent_id'] ?? ''] = true;
        }
        foreach ($positions as $pos) {
            $nodeIds[$pos['agent_id'] ?? ''] = true;
        }

        $selectedAgents = is_array($session['selected_agents'])
            ? $session['selected_agents']
            : (json_decode((string)($session['selected_agents'] ?? '[]'), true) ?: []);

        foreach ($selectedAgents as $agent) {
            $nodeIds[$agent] = true;
        }

        $nodes = array_values(array_filter(array_keys($nodeIds), fn($id) => $id !== ''));

        return [
            'session_id' => $id,
            'nodes'      => $nodes,
            'edges'      => $edges,
            'arguments'  => $arguments,
            'positions'  => $positions,
        ];
    }
}

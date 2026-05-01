<?php
namespace Controllers;

use Domain\SocialDynamics\SocialPromptContextBuilder;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\SessionRepository;

class SocialDynamicsController {
    private SessionRepository $sessionRepo;
    private AgentRelationshipRepository $relationshipRepo;

    public function __construct() {
        $this->sessionRepo      = new SessionRepository();
        $this->relationshipRepo = new AgentRelationshipRepository();
    }

    /** GET /api/sessions/{id}/relationships */
    public function relationships(Request $req): array {
        $id = $req->param('id');
        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $rows = $this->relationshipRepo->findBySession($id);
        $out  = [];

        foreach ($rows as $r) {
            $out[] = [
                'source_agent_id' => (string)($r['source_agent_id'] ?? ''),
                'target_agent_id' => (string)($r['target_agent_id'] ?? ''),
                'affinity'        => isset($r['affinity']) ? (float)$r['affinity'] : 0.0,
                'trust'           => isset($r['trust']) ? (float)$r['trust'] : 0.5,
                'conflict'        => isset($r['conflict']) ? (float)$r['conflict'] : 0.0,
                'support_count'   => (int)($r['support_count'] ?? 0),
                'challenge_count' => (int)($r['challenge_count'] ?? 0),
                'alliance_count'  => (int)($r['alliance_count'] ?? 0),
                'attack_count'    => (int)($r['attack_count'] ?? 0),
            ];
        }

        $highlights = SocialPromptContextBuilder::computeHighlights($rows);

        return [
            'relationships' => $out,
            'highlights'    => $highlights,
        ];
    }

    /** GET /api/sessions/{id}/relationship-events */
    public function relationshipEvents(Request $req): array {
        $id = $req->param('id');
        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $events = $this->relationshipRepo->findEventsBySession($id);
        $out    = [];

        foreach ($events as $e) {
            $out[] = [
                'round_index'       => isset($e['round_index']) ? (int)$e['round_index'] : null,
                'source_agent_id'   => (string)($e['source_agent_id'] ?? ''),
                'target_agent_id'   => $e['target_agent_id'] !== null && $e['target_agent_id'] !== ''
                    ? (string)$e['target_agent_id'] : null,
                'event_type'        => (string)($e['event_type'] ?? ''),
                'intensity'         => isset($e['intensity']) ? (float)$e['intensity'] : 0.5,
                'evidence'          => $e['evidence'] !== null && $e['evidence'] !== '' ? (string)$e['evidence'] : null,
            ];
        }

        return ['events' => $out];
    }
}

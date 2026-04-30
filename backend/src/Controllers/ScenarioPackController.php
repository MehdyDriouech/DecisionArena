<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ScenarioPackRepository;
use Infrastructure\Persistence\SessionRepository;

class ScenarioPackController {
    private ScenarioPackRepository $repo;
    private SessionRepository      $sessionRepo;

    public function __construct() {
        $this->repo        = new ScenarioPackRepository();
        $this->sessionRepo = new SessionRepository();
    }

    /** GET /api/scenario-packs */
    public function index(Request $req): array {
        $adminMode = ($req->query('admin') === '1');
        return $this->repo->findAll(!$adminMode);
    }

    /** GET /api/scenario-packs/{id} */
    public function show(Request $req): array {
        $id   = $req->param('id');
        $pack = $this->repo->findById($id);
        if (!$pack) return Response::error('Scenario pack not found', 404);
        return $pack;
    }

    /** POST /api/scenario-packs */
    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['id']) || empty($data['name']) || empty($data['recommended_mode'])) {
            return Response::error('id, name, recommended_mode are required', 400);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $data['id'])) {
            return Response::error('id must match /^[a-z0-9-]+$/', 400);
        }
        $allowed = ['chat','decision-room','confrontation','quick-decision','stress-test','jury'];
        if (!in_array($data['recommended_mode'], $allowed, true)) {
            return Response::error('Invalid recommended_mode', 400);
        }
        $data['source']     = 'custom';
        $data['created_at'] = date('c');
        $pack = $this->repo->save($data);
        return ['pack' => $pack];
    }

    /** PUT /api/scenario-packs/{id} */
    public function update(Request $req): array {
        $id       = $req->param('id');
        $existing = $this->repo->findById($id);
        if (!$existing) return Response::error('Scenario pack not found', 404);
        if ($existing['source'] === 'system') {
            return Response::error('System packs cannot be edited directly. Duplicate first.', 403);
        }
        $data       = array_merge($existing, $req->body());
        $data['id'] = $id;
        $allowed = ['chat','decision-room','confrontation','quick-decision','stress-test','jury'];
        if (!in_array($data['recommended_mode'] ?? '', $allowed, true)) {
            return Response::error('Invalid recommended_mode', 400);
        }
        $pack = $this->repo->save($data);
        return ['pack' => $pack];
    }

    /** DELETE /api/scenario-packs/{id} */
    public function destroy(Request $req): array {
        $id       = $req->param('id');
        $existing = $this->repo->findById($id);
        if (!$existing) return Response::error('Scenario pack not found', 404);
        if ($existing['source'] === 'system') {
            return Response::error('System packs cannot be deleted.', 403);
        }
        $this->repo->delete($id);
        return ['success' => true];
    }

    /** POST /api/scenario-packs/{id}/duplicate */
    public function duplicate(Request $req): array {
        $id       = $req->param('id');
        $data     = $req->body();
        $existing = $this->repo->findById($id);
        if (!$existing) return Response::error('Scenario pack not found', 404);

        $newId = $data['new_id'] ?? ('copy-of-' . $id);
        if (!preg_match('/^[a-z0-9-]+$/', $newId)) {
            return Response::error('new_id must match /^[a-z0-9-]+$/', 400);
        }
        $copy = array_merge($existing, [
            'id'         => $newId,
            'name'       => $data['name'] ?? ('Copy of ' . $existing['name']),
            'source'     => 'custom',
            'created_at' => date('c'),
        ]);
        $pack = $this->repo->save($copy);
        return ['pack' => $pack];
    }

    /**
     * POST /api/sessions/from-scenario-pack
     * Returns the prefill config so the frontend can fill the form.
     * Does NOT create a session — that is the frontend's responsibility.
     */
    public function prefill(Request $req): array {
        $data   = $req->body();
        $packId = $data['pack_id'] ?? '';
        if (!$packId) return Response::error('pack_id required', 400);

        $pack = $this->repo->findById($packId);
        if (!$pack) return Response::error('Scenario pack not found', 404);

        return [
            'mode'               => $pack['recommended_mode'],
            'persona_ids'        => $pack['persona_ids'],
            'rounds'             => $pack['rounds'],
            'force_disagreement' => $pack['force_disagreement'],
            'decision_threshold' => $pack['decision_threshold'],
            'prompt_starter'     => $pack['prompt_starter'] ?? '',
            'max_personas'       => $pack['max_personas'],
        ];
    }
}

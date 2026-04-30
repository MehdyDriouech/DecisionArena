<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Domain\Personas\PersonaMakerService;

class PersonaMakerController {
    private PersonaMakerService $service;

    public function __construct() {
        $this->service = new PersonaMakerService();
    }

    public function make(Request $req): array {
        $data        = $req->body();
        $description = trim($data['description'] ?? '');
        $providerId  = $data['provider_id'] ?? null;
        $model       = trim($data['model'] ?? '') ?: null;

        if (empty($description)) {
            return Response::error('description is required', 400);
        }

        try {
            return $this->service->make($description, $providerId, $model);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}

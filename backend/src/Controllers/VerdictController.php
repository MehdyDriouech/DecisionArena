<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\VerdictRepository;

class VerdictController {
    private VerdictRepository $verdictRepo;

    public function __construct() {
        $this->verdictRepo = new VerdictRepository();
    }

    public function show(Request $req): array {
        $id      = $req->param('id');
        $verdict = $this->verdictRepo->findBySession($id);
        return ['verdict' => $verdict];
    }
}

<?php

declare(strict_types=1);

namespace Controllers;

use Domain\Risk\RiskProfileAnalyzer;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\RiskProfileRepository;
use Infrastructure\Persistence\SessionRepository;

class RiskProfileController
{
    private SessionRepository         $sessionRepo;
    private RiskProfileRepository     $riskRepo;
    private MessageRepository         $messageRepo;
    private ContextDocumentRepository $docRepo;
    private RiskProfileAnalyzer       $analyzer;

    public function __construct()
    {
        $this->sessionRepo = new SessionRepository();
        $this->riskRepo    = new RiskProfileRepository();
        $this->messageRepo = new MessageRepository();
        $this->docRepo     = new ContextDocumentRepository();
        $this->analyzer    = new RiskProfileAnalyzer();
    }

    /** GET /api/sessions/{id}/risk-profile */
    public function show(Request $req): array
    {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $cached = $this->riskRepo->findBySession($id);

        if ($cached === null) {
            $messages   = $this->messageRepo->findBySession($id);
            $contextDoc = $this->docRepo->findBySession($id);
            $threshold  = (float)($session['decision_threshold'] ?? 0.55);
            $mode       = (string)($session['mode'] ?? 'decision-room');
            $objective  = (string)($session['initial_prompt'] ?? '');
            try {
                $cached = $this->analyzer->analyzeAndPersist(
                    $id, $objective, $mode, $messages, $contextDoc, $threshold
                );
            } catch (\Throwable $e) {
                return ['risk_profile' => null, 'generated' => false, 'error' => $e->getMessage()];
            }
            return ['risk_profile' => $cached, 'generated' => true];
        }

        return ['risk_profile' => $cached, 'generated' => false];
    }

    /** POST /api/sessions/{id}/risk-profile/recompute */
    public function recompute(Request $req): array
    {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages   = $this->messageRepo->findBySession($id);
        $contextDoc = $this->docRepo->findBySession($id);
        $threshold  = (float)($session['decision_threshold'] ?? 0.55);
        $mode       = (string)($session['mode'] ?? 'decision-room');
        $objective  = (string)($session['initial_prompt'] ?? '');

        try {
            $profile = $this->analyzer->analyzeAndPersist(
                $id, $objective, $mode, $messages, $contextDoc, $threshold
            );
        } catch (\Throwable $e) {
            return Response::error('Risk recompute failed: ' . $e->getMessage(), 500);
        }

        return ['risk_profile' => $profile, 'recomputed' => true];
    }
}

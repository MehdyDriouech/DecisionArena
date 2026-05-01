<?php

declare(strict_types=1);

namespace Controllers;

use Domain\Evidence\EvidenceReportService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\EvidenceRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionRepository;

class EvidenceController
{
    private SessionRepository         $sessionRepo;
    private EvidenceRepository        $evidenceRepo;
    private MessageRepository         $messageRepo;
    private ContextDocumentRepository $docRepo;
    private EvidenceReportService     $evidenceService;

    public function __construct()
    {
        $this->sessionRepo     = new SessionRepository();
        $this->evidenceRepo    = new EvidenceRepository();
        $this->messageRepo     = new MessageRepository();
        $this->docRepo         = new ContextDocumentRepository();
        $this->evidenceService = new EvidenceReportService();
    }

    /** GET /api/sessions/{id}/evidence-report */
    public function report(Request $req): array
    {
        $id = $req->param('id');
        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $cached = $this->evidenceRepo->findReportBySession($id);

        // If no cached report, try to generate on-the-fly
        if ($cached === null) {
            $messages   = $this->messageRepo->findBySession($id);
            $contextDoc = $this->docRepo->findBySession($id);
            try {
                $cached = $this->evidenceService->generateAndPersist($id, $messages, $contextDoc);
            } catch (\Throwable $e) {
                return ['evidence_report' => null, 'generated' => false, 'error' => $e->getMessage()];
            }
            return ['evidence_report' => $cached, 'generated' => true];
        }

        return ['evidence_report' => $cached, 'generated' => false];
    }

    /** GET /api/sessions/{id}/evidence-claims */
    public function claims(Request $req): array
    {
        $id = $req->param('id');
        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $claims = $this->evidenceRepo->findClaimsBySession($id);

        return [
            'claims' => array_map(static function (array $row): array {
                return [
                    'id'               => (int)$row['id'],
                    'agent_id'         => $row['agent_id'] ?? null,
                    'claim_text'       => (string)($row['claim_text'] ?? ''),
                    'claim_type'       => (string)($row['claim_type'] ?? ''),
                    'status'           => (string)($row['status'] ?? 'unsupported'),
                    'confidence'       => isset($row['confidence']) ? (float)$row['confidence'] : 0.5,
                    'evidence_text'    => $row['evidence_text'] ?? null,
                    'source_reference' => $row['source_reference'] ?? null,
                ];
            }, $claims),
        ];
    }

    /** POST /api/sessions/{id}/evidence/recompute */
    public function recompute(Request $req): array
    {
        $id = $req->param('id');
        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $messages   = $this->messageRepo->findBySession($id);
        $contextDoc = $this->docRepo->findBySession($id);

        try {
            $report = $this->evidenceService->recompute($id, $messages, $contextDoc);
        } catch (\Throwable $e) {
            return Response::error('Evidence recompute failed: ' . $e->getMessage(), 500);
        }

        return ['evidence_report' => $report, 'recomputed' => true];
    }
}

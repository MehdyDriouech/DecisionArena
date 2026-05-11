<?php
declare(strict_types=1);

namespace Controllers;

use Domain\Dashboard\CognitiveSummaryService;
use Http\Request;
use Http\Response;

final class DashboardController
{
    private CognitiveSummaryService $service;

    public function __construct()
    {
        $this->service = new CognitiveSummaryService();
    }

    /** GET /api/dashboard/cognitive-summary */
    public function cognitiveSummary(Request $request): array
    {
        try {
            $requestedContextId = $request->query('context_id', null);
            $requestedContextId = is_string($requestedContextId) ? trim($requestedContextId) : null;
            if ($requestedContextId === '') {
                $requestedContextId = null;
            }
            return $this->service->buildSummary($requestedContextId);
        } catch (\Throwable $e) {
            return Response::error('Dashboard summary unavailable: ' . $e->getMessage(), 500);
        }
    }
}

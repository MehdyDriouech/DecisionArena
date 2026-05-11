<?php

declare(strict_types=1);

namespace Controllers;

use Domain\CognitiveGovernance\CognitiveGovernanceCatalog;
use Http\Request;

/**
 * GET /api/cognitive-governance — catalogue statique d’invariants (expert / doc).
 */
final class CognitiveGovernanceController
{
    public function index(Request $request): array
    {
        return CognitiveGovernanceCatalog::build();
    }
}

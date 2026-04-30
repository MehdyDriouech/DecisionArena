<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ProviderRepository;
use Infrastructure\Persistence\ProviderRoutingSettingsRepository;

class ProviderRoutingController {
    private ProviderRoutingSettingsRepository $settingsRepo;
    private ProviderRepository $providerRepo;

    public function __construct() {
        $this->settingsRepo = new ProviderRoutingSettingsRepository();
        $this->providerRepo = new ProviderRepository();
    }

    public function show(Request $req): array {
        return $this->settingsRepo->get();
    }

    public function update(Request $req): array {
        $data = $req->body();

        $routingMode = (string)($data['routing_mode'] ?? 'single-primary');
        $allowedModes = ['single-primary', 'preferred-with-fallback', 'load-balance', 'agent-default'];
        if (!in_array($routingMode, $allowedModes, true)) {
            return Response::error('Invalid routing_mode', 400);
        }

        $strategy = (string)($data['load_balance_strategy'] ?? 'round-robin');
        $allowedStrategies = ['round-robin', 'random'];
        if (!in_array($strategy, $allowedStrategies, true)) {
            return Response::error('Invalid load_balance_strategy', 400);
        }

        $primaryId = $data['primary_provider_id'] ?? null;
        $preferredId = $data['preferred_provider_id'] ?? null;
        $fallbackIds = $data['fallback_provider_ids'] ?? [];

        if ($primaryId !== null && $primaryId !== '' && !$this->isEnabledProviderId((string)$primaryId)) {
            return Response::error('primary_provider_id must exist and be enabled', 400);
        }
        if ($preferredId !== null && $preferredId !== '' && !$this->isEnabledProviderId((string)$preferredId)) {
            return Response::error('preferred_provider_id must exist and be enabled', 400);
        }

        if (!is_array($fallbackIds)) {
            return Response::error('fallback_provider_ids must be an array', 400);
        }
        $fallbackIds = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', $fallbackIds), fn($x) => $x !== ''));
        foreach ($fallbackIds as $id) {
            if (!$this->isEnabledProviderId($id)) {
                return Response::error('fallback_provider_ids must contain only enabled provider ids', 400);
            }
        }

        // Normalize empties to null
        $primaryId = is_string($primaryId) ? trim($primaryId) : $primaryId;
        $preferredId = is_string($preferredId) ? trim($preferredId) : $preferredId;
        $primaryId = ($primaryId === '') ? null : $primaryId;
        $preferredId = ($preferredId === '') ? null : $preferredId;

        $saved = $this->settingsRepo->update([
            'routing_mode' => $routingMode,
            'primary_provider_id' => $primaryId,
            'preferred_provider_id' => $preferredId,
            'fallback_provider_ids' => $fallbackIds,
            'load_balance_strategy' => $strategy,
        ]);

        return $saved;
    }

    private function isEnabledProviderId(string $id): bool {
        $p = $this->providerRepo->findById($id);
        if (!$p) return false;
        return (int)($p['enabled'] ?? 0) === 1;
    }
}


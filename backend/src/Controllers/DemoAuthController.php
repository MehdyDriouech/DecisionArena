<?php
namespace Controllers;

use Domain\Demo\DemoAuthService;
use Domain\Demo\DemoQuotaService;
use Http\Request;
use Http\Response;

/** @deprecated Préférer /api/demo-auth/* (lot C1). Conservé pour compatibilité quota lot B. */
class DemoAuthController {
    public function config(Request $req): array {
        return [
            'demo_mode' => DemoAuthService::isDemoMode(),
            'auth_required' => DemoAuthService::isDemoAuthRequired(),
        ];
    }

    public function login(Request $req): array {
        $api = new DemoAuthApiController();
        $result = $api->login($req);
        if (!empty($result['error'])) {
            return $result;
        }
        $user = DemoAuthService::currentUser();
        $quota = DemoAuthService::isDemoMode()
            ? DemoQuotaService::getStatus(DemoAuthService::currentUserId())
            : null;
        return [
            'success' => true,
            'user' => $user['login'] ?? null,
            'quota' => $quota,
        ];
    }

    public function logout(Request $req): array {
        return (new DemoAuthApiController())->logout($req);
    }

    public function me(Request $req): array {
        if (!DemoAuthService::isDemoMode() && !DemoAuthService::isDemoAuthRequired()) {
            return ['demo_mode' => false, 'authenticated' => false];
        }
        $user = DemoAuthService::currentUser();
        return [
            'demo_mode' => DemoAuthService::isDemoMode(),
            'auth_required' => DemoAuthService::isDemoAuthRequired(),
            'authenticated' => $user !== null,
            'user' => $user['login'] ?? null,
            'quota' => DemoAuthService::isDemoMode()
                ? DemoQuotaService::getStatus(DemoAuthService::currentUserId())
                : null,
        ];
    }
}

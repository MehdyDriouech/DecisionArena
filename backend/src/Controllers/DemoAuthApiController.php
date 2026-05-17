<?php
namespace Controllers;

use Domain\Demo\DemoAuthService;
use Http\Request;
use Http\Response;

/**
 * Auth démo publique (login / logout / me) — sans quota (lot C1).
 */
class DemoAuthApiController {
    public function config(Request $req): array {
        return [
            'auth_required' => DemoAuthService::isDemoAuthRequired(),
        ];
    }

    public function login(Request $req): array {
        if (!DemoAuthService::isDemoAuthRequired()) {
            return Response::error('Demo auth is not enabled', 404);
        }
        $data = $req->body();
        $login = trim((string)($data['login'] ?? $data['username'] ?? ''));
        $pass = (string)($data['password'] ?? '');
        if ($login === '' || $pass === '') {
            return Response::error('login and password required', 400);
        }
        $user = DemoAuthService::login($login, $pass);
        if ($user === null) {
            return Response::error('Invalid credentials', 401);
        }
        return [
            'success' => true,
            'authenticated' => true,
            'user' => self::enrichUser($user),
        ];
    }

    public function logout(Request $req): array {
        DemoAuthService::logout();
        return ['success' => true, 'authenticated' => false, 'user' => null];
    }

    public function me(Request $req): array {
        if (!DemoAuthService::isDemoAuthRequired()) {
            return ['auth_required' => false, 'authenticated' => false, 'user' => null];
        }
        $user = DemoAuthService::currentUser();
        return [
            'auth_required' => true,
            'authenticated' => $user !== null,
            'user' => $user !== null ? self::enrichUser($user) : null,
        ];
    }

    /** @param array{login:string,role:string} $user */
    private static function enrichUser(array $user): array
    {
        $user['daily_llm_quota'] = DemoAuthService::dailyQuotaForUser((string)($user['login'] ?? ''));

        return $user;
    }
}

<?php
namespace Domain\Demo;

use Infrastructure\Persistence\DemoUserRepository;

final class DemoAuthService {
    private const SESSION_KEY = 'demo_auth_user';

    public static function isDemoAuthRequired(): bool {
        self::loadConfig();
        $env = getenv('DECISION_ARENA_DEMO_AUTH');
        if ($env !== false && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) \DemoLocalConfig::get('demo.auth_required', false);
    }

    /** Quota / ancien mode démo (probablement lié au lot B). */
    public static function isDemoMode(): bool {
        self::loadConfig();
        return class_exists(\DemoLocalConfig::class, false) && \DemoLocalConfig::isDemoMode();
    }

    private static function loadConfig(): void {
        if (!class_exists(\DemoLocalConfig::class, false)) {
            $loader = dirname(__DIR__, 3) . '/config/DemoLocalConfig.php';
            if (is_file($loader)) {
                require_once $loader;
            }
        }
    }

    public static function startSessionIfNeeded(): void {
        if (!self::isDemoAuthRequired() && !self::isDemoMode()) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    /** Libère le verrou session avant les runs longs (requêtes suivantes rouvrent la session). */
    public static function releaseSessionLock(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * @return array{login:string,role:string}|null
     */
    public static function login(string $login, string $password): ?array {
        self::startSessionIfNeeded();
        $verified = (new DemoUserRepository())->verifyCredentials($login, $password);
        if ($verified === null) {
            return null;
        }
        $_SESSION[self::SESSION_KEY] = [
            'id' => $verified['id'],
            'login' => $verified['login'],
            'role' => $verified['role'],
        ];
        return self::currentUser();
    }

    public static function logout(): void {
        self::startSessionIfNeeded();
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @return array{login:string,role:string}|null */
    public static function currentUser(): ?array {
        self::startSessionIfNeeded();
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        $login = trim((string)($raw['login'] ?? ''));
        $role = trim((string)($raw['role'] ?? ''));
        if ($login === '') {
            return null;
        }
        return ['login' => $login, 'role' => $role];
    }

    public static function currentUserId(): ?string {
        $user = self::currentUser();
        return $user['login'] ?? null;
    }

    public static function requireAuth(): void {
        if (!self::isDemoAuthRequired()) {
            return;
        }
        if (self::currentUser() === null) {
            throw new DemoHttpException('Authentication required', 401, 'auth_required');
        }
    }

    public static function dailyQuotaForUser(?string $userId): int {
        if ($userId === null || $userId === '') {
            return 0;
        }
        self::loadConfig();
        $accounts = \DemoLocalConfig::demoAccounts();
        $row = $accounts[$userId] ?? null;
        if (!is_array($row)) {
            return 0;
        }
        return max(0, (int)($row['daily_llm_quota'] ?? 0));
    }
}

<?php
/**
 * Charge backend/config/demo.local.php (non versionné).
 */
final class DemoLocalConfig {
    private static ?array $loaded = null;

    public static function all(): array {
        if (self::$loaded === null) {
            $path = __DIR__ . '/demo.local.php';
            $cfg = file_exists($path) ? require $path : [];
            self::$loaded = is_array($cfg) ? $cfg : [];
        }
        return self::$loaded;
    }

    public static function get(string $path, mixed $default = null): mixed {
        $cur = self::all();
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    public static function gemini(): array {
        $g = self::get('gemini', []);
        return is_array($g) ? $g : [];
    }

    public static function isDemoMode(): bool {
        return (bool) self::get('demo.enabled', false);
    }

    public static function isDemoAuthRequired(): bool {
        $env = getenv('DECISION_ARENA_DEMO_AUTH');
        if ($env !== false && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) self::get('demo.auth_required', false);
    }

    public static function demoAccounts(): array {
        $accounts = self::get('demo.accounts', []);
        return is_array($accounts) ? $accounts : [];
    }

    /** @return list<string> */
    public static function geminiApiKeyEnvNames(): array {
        $g = self::gemini();
        $names = [];
        $primary = trim((string)($g['api_key_env'] ?? 'GEMINI_API_KEY'));
        if ($primary !== '') {
            $names[] = $primary;
        }
        $fallback = trim((string)($g['fallback_api_key_env'] ?? 'GOOGLE_API_KEY'));
        if ($fallback !== '' && !in_array($fallback, $names, true)) {
            $names[] = $fallback;
        }
        if (empty($names)) {
            $names = ['GEMINI_API_KEY', 'GOOGLE_API_KEY'];
        }
        return $names;
    }

    public static function geminiFileApiKey(): string {
        $raw = self::gemini()['api_key'] ?? '';
        return is_string($raw) ? trim($raw) : '';
    }
}

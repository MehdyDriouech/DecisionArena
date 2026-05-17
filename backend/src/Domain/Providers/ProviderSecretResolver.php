<?php
namespace Domain\Providers;

/**
 * Injects server-side API secrets at runtime (never persisted from this class).
 */
final class ProviderSecretResolver {
    public const GEMINI_PROVIDER_ID = 'gemini';

    public static function geminiEnvKey(): string {
        if (!class_exists(\DemoLocalConfig::class, false)) {
            $loader = dirname(__DIR__, 3) . '/config/DemoLocalConfig.php';
            if (is_file($loader)) {
                require_once $loader;
            }
        }
        if (class_exists(\DemoLocalConfig::class, false)) {
            $fromFile = \DemoLocalConfig::geminiFileApiKey();
            if ($fromFile !== '') {
                return $fromFile;
            }
            foreach (\DemoLocalConfig::geminiApiKeyEnvNames() as $var) {
                $v = getenv($var);
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }
        foreach (['GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $var) {
            $v = getenv($var);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return '';
    }

    public static function resolveApiKey(array $providerData): string {
        $fromDb = trim((string)($providerData['api_key'] ?? ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
        if (self::isGeminiRow($providerData)) {
            return self::geminiEnvKey();
        }
        return '';
    }

    public static function enrich(array $providerData): array {
        $key = self::resolveApiKey($providerData);
        if ($key !== '') {
            $providerData['api_key'] = $key;
        }
        return $providerData;
    }

    public static function missingGeminiKeyMessage(): string {
        return 'Gemini API key missing. Set GEMINI_API_KEY or GOOGLE_API_KEY, or configure backend/config/demo.local.php (never in the frontend or git).';
    }

    /** @return array<string,mixed> */
    public static function geminiSeedDefaults(): array {
        if (class_exists(\DemoLocalConfig::class, false)) {
            $g = \DemoLocalConfig::gemini();
            if (!empty($g)) {
                return [
                    'id' => (string)($g['provider_id'] ?? self::GEMINI_PROVIDER_ID),
                    'name' => (string)($g['name'] ?? 'Google Gemini'),
                    'type' => (string)($g['type'] ?? 'openai-compatible'),
                    'base_url' => (string)($g['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta/openai'),
                    'default_model' => (string)($g['default_model'] ?? 'gemini-2.5-flash'),
                    'enabled' => (int)(($g['enabled'] ?? true) ? 1 : 0),
                    'priority' => (int)($g['priority'] ?? 10),
                    'is_local' => 0,
                    'demo_primary' => (bool)($g['demo_primary'] ?? false),
                ];
            }
        }
        return [
            'id' => self::GEMINI_PROVIDER_ID,
            'name' => 'Google Gemini',
            'type' => 'openai-compatible',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'default_model' => 'gemini-2.5-flash',
            'enabled' => 1,
            'priority' => 10,
            'is_local' => 0,
            'demo_primary' => false,
        ];
    }

    public static function isGeminiRow(array $providerData): bool {
        return strtolower(trim((string)($providerData['id'] ?? ''))) === self::GEMINI_PROVIDER_ID;
    }
}

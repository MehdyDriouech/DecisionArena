<?php
namespace Domain\Providers;

/**
 * Credentials BYOK envoyés dans le corps JSON des requêtes (clé provider_runtime).
 * Ne jamais journaliser ce payload.
 */
class CommercialRuntimeContext {
    private static array $rows = [];

    private const IDS = ['openai', 'anthropic', 'mistral', 'openrouter'];

    private const LABELS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'mistral' => 'Mistral AI',
        'openrouter' => 'OpenRouter',
    ];

    private const DEFAULT_BASE = [
        'openai' => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com',
        'mistral' => 'https://api.mistral.ai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    public static function clear(): void {
        self::$rows = [];
    }

    /**
     * @param array $body Request body (déjà parsé)
     */
    public static function loadFromRequestBody(array $body): void {
        self::$rows = [];
        $raw = $body['provider_runtime'] ?? null;
        if (!is_array($raw)) {
            return;
        }
        foreach ($raw as $idRaw => $cfg) {
            $id = strtolower(trim((string)$idRaw));
            if (!in_array($id, self::IDS, true)) {
                continue;
            }
            if (!is_array($cfg)) {
                continue;
            }
            $enabled = $cfg['enabled'] ?? true;
            if ($enabled === false || $enabled === 0 || $enabled === '0') {
                continue;
            }
            $key = trim((string)($cfg['api_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $base = trim((string)($cfg['base_url'] ?? ''));
            if ($base === '') {
                $base = self::DEFAULT_BASE[$id] ?? '';
            }
            if ($base === '') {
                continue;
            }
            $prio = isset($cfg['priority']) ? (int)$cfg['priority'] : 100;
            if ($prio < 0 || $prio > 1000000) {
                continue;
            }
            $model = trim((string)($cfg['default_model'] ?? ''));
            self::$rows[] = [
                'id' => $id,
                'name' => self::LABELS[$id] ?? $id,
                'type' => 'openai-compatible',
                'base_url' => $base,
                'api_key' => $key,
                'default_model' => $model,
                'enabled' => 1,
                'priority' => $prio,
                'is_local' => 0,
            ];
        }
    }

    /** @return list<array<string,mixed>> */
    public static function getRows(): array {
        return self::$rows;
    }
}

<?php
namespace Domain\Providers;

class ProviderFactory {
    public static function create(array $providerData): LlmProviderInterface {
        return match($providerData['type']) {
            'openai-compatible' => new OpenAICompatibleProvider(
                $providerData['base_url'],
                $providerData['api_key'] ?? '',
                $providerData['default_model']
            ),
            'lmstudio' => new LMStudioProvider(
                $providerData['base_url'] ?? 'http://localhost:1234',
                $providerData['api_key'] ?? '',
                $providerData['default_model']
            ),
            'ollama' => new OllamaProvider(
                $providerData['base_url'] ?? 'http://localhost:11434',
                $providerData['default_model']
            ),
            default => throw new \InvalidArgumentException('Unknown provider type: ' . $providerData['type'])
        };
    }
}

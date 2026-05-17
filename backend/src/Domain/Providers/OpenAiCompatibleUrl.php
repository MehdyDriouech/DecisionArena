<?php
namespace Domain\Providers;

/**
 * Builds OpenAI-compatible HTTP paths for heterogeneous gateways (OpenAI, LM Studio, Google Gemini).
 */
final class OpenAiCompatibleUrl {
    public static function chatCompletions(string $baseUrl): string {
        $base = rtrim($baseUrl, '/');
        if (self::usesOpenAiStyleRoot($base)) {
            return $base . '/chat/completions';
        }
        return $base . '/v1/chat/completions';
    }

    public static function models(string $baseUrl): string {
        $base = rtrim($baseUrl, '/');
        if (self::usesOpenAiStyleRoot($base)) {
            return $base . '/models';
        }
        return $base . '/v1/models';
    }

    private static function usesOpenAiStyleRoot(string $base): bool {
        if (str_contains($base, 'generativelanguage.googleapis.com')) {
            return true;
        }
        if (preg_match('#/openai$#', $base)) {
            return true;
        }
        if (preg_match('#/v1$#', $base)) {
            return true;
        }
        return false;
    }
}

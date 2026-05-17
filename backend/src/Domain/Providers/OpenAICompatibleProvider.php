<?php
namespace Domain\Providers;

use Domain\Orchestration\RunTimeoutPolicy;

class OpenAICompatibleProvider implements LlmProviderInterface {
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $defaultModel
    ) {}

    public function chat(array $messages, string $model = '', array $options = []): string {
        $model = $model ?: $this->defaultModel;
        $temperature = isset($options['temperature']) && is_numeric($options['temperature'])
            ? (float)$options['temperature']
            : 0.7;
        $url = OpenAiCompatibleUrl::chatCompletions($this->baseUrl);
        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ]);
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        $response = $this->httpPost($url, $payload, $headers, $options);
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('Invalid OpenAI response: ' . $response);
    }

    public function test(): bool {
        try {
            $this->chat([['role' => 'user', 'content' => 'Say OK']], $this->defaultModel, [
                'http_timeout_seconds' => RunTimeoutPolicy::connectTimeoutSeconds() + 50,
                'connect_timeout_seconds' => RunTimeoutPolicy::connectTimeoutSeconds(),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $options http_timeout_seconds, connect_timeout_seconds
     */
    protected function httpPost(string $url, string $payload, array $headers, array $options = []): string {
        $timeout = (int)($options['http_timeout_seconds'] ?? 120);
        $connect = (int)($options['connect_timeout_seconds'] ?? RunTimeoutPolicy::connectTimeoutSeconds());
        if ($timeout < 30) {
            $timeout = 30;
        }
        if ($connect < 1) {
            $connect = 1;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connect);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException('cURL error: ' . $error);
        return $response;
    }
}

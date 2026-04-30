<?php
namespace Domain\Providers;

class OpenAICompatibleProvider implements LlmProviderInterface {
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $defaultModel
    ) {}

    public function chat(array $messages, string $model = ''): string {
        $model = $model ?: $this->defaultModel;
        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions';
        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
        ]);
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        $response = $this->httpPost($url, $payload, $headers);
        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('Invalid OpenAI response: ' . $response);
    }

    public function test(): bool {
        try {
            $this->chat([['role' => 'user', 'content' => 'Say OK']], $this->defaultModel);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function httpPost(string $url, string $payload, array $headers): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException('cURL error: ' . $error);
        return $response;
    }
}

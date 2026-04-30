<?php
namespace Domain\Providers;

class OllamaProvider implements LlmProviderInterface {
    public function __construct(
        private string $baseUrl,
        private string $defaultModel
    ) {}

    public function chat(array $messages, string $model = ''): string {
        $model = $model ?: $this->defaultModel;
        $url = rtrim($this->baseUrl, '/') . '/api/chat';
        $payload = json_encode([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException('cURL error: ' . $error);
        $data = json_decode($response, true);
        return $data['message']['content']
            ?? throw new \RuntimeException('Invalid Ollama response: ' . $response);
    }

    public function test(): bool {
        try {
            $this->chat([['role' => 'user', 'content' => 'Say OK']]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

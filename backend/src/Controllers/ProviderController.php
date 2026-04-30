<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\ProviderRepository;
use Domain\Providers\ProviderFactory;

class ProviderController {
    private ProviderRepository $repo;

    public function __construct() {
        $this->repo = new ProviderRepository();
    }

    public function index(Request $req): array {
        $providers = $this->repo->findAll();
        return array_map(
            fn($p) => array_merge($p, ['api_key' => $p['api_key'] ? '***' : '']),
            $providers
        );
    }

    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['id']) || empty($data['name']) || empty($data['type'])) {
            return Response::error('Missing required fields: id, name, type', 400);
        }
        $now = date('c');
        $type = (string)$data['type'];
        $isLocal = in_array(strtolower($type), ['ollama', 'lmstudio'], true) ? 1 : 0;
        $provider = [
            'id'            => $data['id'],
            'name'          => $data['name'],
            'type'          => $type,
            'base_url'      => $data['base_url'] ?? '',
            'api_key'       => $data['api_key'] ?? '',
            'default_model' => $data['default_model'] ?? '',
            'enabled'       => isset($data['enabled']) ? (int)(bool)$data['enabled'] : 1,
            'priority'      => isset($data['priority']) ? (int)$data['priority'] : 100,
            'is_local'      => $isLocal,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        try {
            $saved = $this->repo->save($provider);
            return array_merge($saved, ['api_key' => $saved['api_key'] ? '***' : '']);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function test(Request $req): array {
        $data = $req->body();
        $providerId = $data['provider_id'] ?? '';
        if (!$providerId) {
            return Response::error('provider_id required', 400);
        }
        $providerData = $this->repo->findById($providerId);
        if (!$providerData) {
            return Response::error('Provider not found', 404);
        }
        try {
            $provider = ProviderFactory::create($providerData);
            $ok = $provider->test();
            return [
                'success' => $ok,
                'message' => $ok ? 'Provider is working' : 'Provider test failed',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function models(Request $req): array {
        $data = $req->body();
        $providerId = trim((string)($data['provider_id'] ?? ''));
        $type = trim((string)($data['type'] ?? ''));
        $baseUrl = trim((string)($data['base_url'] ?? ''));
        $apiKey = trim((string)($data['api_key'] ?? ''));

        if ($providerId !== '') {
            $provider = $this->repo->findById($providerId);
            if (!$provider) {
                return Response::error('Provider not found', 404);
            }
            $type = (string)($provider['type'] ?? '');
            $baseUrl = trim((string)($provider['base_url'] ?? ''));
            $apiKey = trim((string)($provider['api_key'] ?? ''));
        }

        if ($type === '') {
            return Response::error('Provider type is required', 400);
        }
        if ($baseUrl === '') {
            $baseUrl = match($type) {
                'ollama' => 'http://localhost:11434',
                'lmstudio' => 'http://localhost:1234',
                default => '',
            };
        }
        if ($baseUrl === '') {
            return Response::error('base_url is required', 400);
        }

        try {
            $models = $this->discoverModels($type, $baseUrl, $apiKey);
            return ['models' => $models];
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function destroy(Request $req): array {
        $id = $req->param('id');
        if (!$id) {
            return Response::error('provider id required', 400);
        }
        $provider = $this->repo->findById($id);
        if (!$provider) {
            return Response::error('Provider not found', 404);
        }
        $this->repo->delete($id);
        return ['success' => true, 'deleted_id' => $id];
    }

    private function discoverModels(string $type, string $baseUrl, string $apiKey): array {
        $type = strtolower($type);
        if ($type === 'ollama') {
            $data = $this->httpGetJson(rtrim($baseUrl, '/') . '/api/tags');
            $rawModels = is_array($data['models'] ?? null) ? $data['models'] : [];
            $models = array_map(function ($m) {
                $name = (string)($m['name'] ?? '');
                if ($name === '') return null;
                $details = '';
                if (isset($m['details']) && is_array($m['details'])) {
                    $family = (string)($m['details']['family'] ?? '');
                    $details = $family;
                } elseif (!empty($m['size'])) {
                    $details = (string)$m['size'];
                }
                return [
                    'id' => $name,
                    'name' => $name,
                    'details' => $details,
                ];
            }, $rawModels);
            $models = array_values(array_filter($models, fn($m) => $m !== null));
            if (empty($models)) {
                throw new \RuntimeException('No models returned by Ollama /api/tags');
            }
            return $models;
        }

        if ($type === 'lmstudio' || $type === 'openai-compatible') {
            $headers = [];
            if ($type === 'openai-compatible' && $apiKey !== '') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }
            $data = $this->httpGetJson(rtrim($baseUrl, '/') . '/v1/models', $headers);
            $rawModels = is_array($data['data'] ?? null) ? $data['data'] : [];
            $models = array_map(function ($m) {
                $id = (string)($m['id'] ?? '');
                if ($id === '') return null;
                return ['id' => $id, 'name' => $id, 'details' => ''];
            }, $rawModels);
            $models = array_values(array_filter($models, fn($m) => $m !== null));
            if (empty($models)) {
                throw new \RuntimeException('No models returned by /v1/models');
            }
            return $models;
        }

        throw new \RuntimeException('Model discovery is not supported for provider type: ' . $type);
    }

    private function httpGetJson(string $url, array $headers = []): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException('Provider unreachable: ' . $curlErr);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Provider returned HTTP ' . $status);
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON returned by provider');
        }
        return $data;
    }
}

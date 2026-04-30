<?php
namespace Infrastructure\Logging;

use Infrastructure\Persistence\Database;

class Logger {
    public function __construct() {
    }

    public function info(string $message, array $context = []): void {
        $this->log('info', 'backend', array_merge(['action' => $message], $context));
    }

    public function error(string $message, array $context = []): void {
        $this->log('error', 'backend', array_merge(['action' => $message], $context));
    }

    public function log(string $level, string $category, array $data = []): void {
        $level = strtolower(trim($level));
        $category = strtolower(trim($category));
        $allowedLevels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $allowedLevels, true)) $level = 'info';

        $now = date('c');
        $id = $data['id'] ?? $this->uuid();

        $row = [
            'id' => (string)$id,
            'level' => $level,
            'category' => $category ?: 'backend',
            'session_id' => $data['session_id'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'model' => $data['model'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
            'action' => $data['action'] ?? null,
            'request_payload' => $data['request_payload'] ?? null,
            'response_payload' => $data['response_payload'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'created_at' => $data['created_at'] ?? $now,
        ];

        $masked = $this->maskAndNormalize($row);

        try {
            $pdo = Database::getInstance()->pdo();
            $stmt = $pdo->prepare("
                INSERT INTO app_logs
                    (id, level, category, session_id, message_id, provider_id, model, agent_id, action,
                     request_payload, response_payload, metadata, error_message, created_at)
                VALUES
                    (:id, :level, :category, :session_id, :message_id, :provider_id, :model, :agent_id, :action,
                     :request_payload, :response_payload, :metadata, :error_message, :created_at)
            ");
            $stmt->execute([
                ':id' => $masked['id'],
                ':level' => $masked['level'],
                ':category' => $masked['category'],
                ':session_id' => $masked['session_id'],
                ':message_id' => $masked['message_id'],
                ':provider_id' => $masked['provider_id'],
                ':model' => $masked['model'],
                ':agent_id' => $masked['agent_id'],
                ':action' => $masked['action'],
                ':request_payload' => $masked['request_payload'],
                ':response_payload' => $masked['response_payload'],
                ':metadata' => $masked['metadata'],
                ':error_message' => $masked['error_message'],
                ':created_at' => $masked['created_at'],
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the app
        }
    }

    public function logLlmRequest(array $data): void {
        $this->log($data['level'] ?? 'debug', 'llm_request', $data);
    }

    public function logLlmResponse(array $data): void {
        $this->log($data['level'] ?? 'debug', 'llm_response', $data);
    }

    public function logBackendEvent(string $action, array $data = [], string $level = 'info'): void {
        $this->log($level, 'backend', array_merge($data, ['action' => $action]));
    }

    public function logProviderError(string $action, array $data = []): void {
        $this->log('error', 'provider', array_merge($data, ['action' => $action]));
    }

    public function logPromptBuild(string $action, array $data = []): void {
        $this->log('debug', 'prompt', array_merge($data, ['action' => $action]));
    }

    public function logRoutingDecision(string $action, array $data = []): void {
        $this->log('info', 'routing', array_merge($data, ['action' => $action]));
    }

    public function logFrontendEvent(array $data): void {
        $this->log($data['level'] ?? 'info', 'frontend', $data);
    }

    private function maskAndNormalize(array $row): array {
        $meta = $row['metadata'];
        if (is_array($meta)) {
            $meta = $this->maskSecrets($meta);
            [$metaJson, $metaTrunc] = $this->jsonEncodeTruncated($meta);
            $row['metadata'] = $metaJson;
            if ($metaTrunc) {
                // merge truncation info if possible
                $row['metadata'] = $this->mergeTruncatedFlag($row['metadata']);
            }
        } elseif (is_string($meta) && $meta !== '') {
            // best-effort: keep as-is but truncate
            [$metaStr, $metaTrunc] = $this->truncateText($meta);
            $row['metadata'] = $metaStr;
            if ($metaTrunc) $row['metadata'] = $this->mergeTruncatedFlag($row['metadata']);
        } else {
            $row['metadata'] = null;
        }

        foreach (['request_payload', 'response_payload'] as $k) {
            $v = $row[$k];
            if (is_array($v)) {
                // Redact verbatim conversation content (messages / LLM response body)
                $v = $this->redactConversationContent($v);
                $v = $this->maskSecrets($v);
                [$json, $trunc] = $this->jsonEncodeTruncated($v);
                $row[$k] = $json;
                if ($trunc) $row['metadata'] = $this->mergeTruncatedFlag($row['metadata']);
            } elseif (is_string($v) && $v !== '') {
                [$txt, $trunc] = $this->truncateText($v);
                $row[$k] = $txt;
                if ($trunc) $row['metadata'] = $this->mergeTruncatedFlag($row['metadata']);
            } else {
                $row[$k] = null;
            }
        }

        // Mask and truncate error_message (may contain sensitive data from exceptions)
        if (is_string($row['error_message']) && $row['error_message'] !== '') {
            $masked = $this->maskSecrets($row['error_message']);
            [$truncated, ] = $this->truncateText(is_string($masked) ? $masked : (string)$masked, 2000);
            $row['error_message'] = $truncated;
        } else {
            $row['error_message'] = null;
        }

        // Normalize empty strings to null for ids/models
        foreach (['session_id','message_id','provider_id','model','agent_id','action'] as $k) {
            if (is_string($row[$k]) && trim($row[$k]) === '') $row[$k] = null;
        }

        return $row;
    }

    /** Keys (normalized to lowercase) whose values must be replaced with *** */
    private const SENSITIVE_KEYS = [
        'api_key', 'apikey', 'api-key', 'authorization', 'token', 'access_token',
        'refresh_token', 'id_token', 'bearer', 'password', 'passwd', 'secret',
        'client_secret', 'private_key', 'credential', 'credentials', 'auth',
        'x-api-key', 'x_api_key',
    ];

    private function maskSecrets(mixed $value): mixed {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $normKey = is_string($k) ? strtolower(trim($k)) : $k;
                if (is_string($normKey) && (
                    in_array($normKey, self::SENSITIVE_KEYS, true)
                    || str_contains($normKey, 'api_key')
                    || str_contains($normKey, 'api-key')
                    || str_contains($normKey, 'secret')
                    || str_contains($normKey, 'token')
                    || str_contains($normKey, 'password')
                    || str_contains($normKey, 'credential')
                )) {
                    $out[$k] = '***';
                    continue;
                }
                $out[$k] = $this->maskSecrets($v);
            }
            return $out;
        }
        if (is_string($value)) {
            $value = preg_replace('/authorization\s*:\s*bearer\s+\S+/i', 'Authorization: Bearer ***', $value);
            // Mask "key": "sk-..." patterns in raw JSON strings (non-capturing group fixed)
            $value = preg_replace_callback(
                '/"(api_key|apikey|api-key|token|access_token|password|secret|credential)"\s*:\s*"[^"]{4,}"/i',
                fn($m) => '"' . $m[1] . '":"***"',
                $value
            );
            return $value;
        }
        return $value;
    }

    /**
     * Replace the actual text content of LLM messages/responses with metadata only
     * (role + character count), keeping no verbatim conversation text in logs.
     */
    private function redactConversationContent(array $payload): array {
        // Redact messages array (list of {role, content} objects)
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            $payload['messages'] = array_map(function ($msg) {
                $chars = mb_strlen((string)($msg['content'] ?? ''), 'UTF-8');
                return [
                    'role'    => $msg['role'] ?? 'unknown',
                    'content' => "[REDACTED: {$chars} chars]",
                ];
            }, $payload['messages']);
        }
        // Redact top-level string content (LLM response body)
        if (isset($payload['content']) && is_string($payload['content'])) {
            $chars = mb_strlen($payload['content'], 'UTF-8');
            $payload['content'] = "[REDACTED: {$chars} chars]";
        }
        // Redact nested raw response
        if (isset($payload['raw']) && is_string($payload['raw']) && $payload['raw'] !== '') {
            $chars = mb_strlen($payload['raw'], 'UTF-8');
            $payload['raw'] = "[REDACTED: {$chars} chars]";
        }
        return $payload;
    }

    private function jsonEncodeTruncated(array $data): array {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return ['{}', false];
        return $this->truncateText($json);
    }

    private function truncateText(string $text, int $max = 100000): array {
        if (mb_strlen($text, 'UTF-8') <= $max) return [$text, false];
        $truncated = mb_substr($text, 0, $max, 'UTF-8');
        return [$truncated, true];
    }

    private function mergeTruncatedFlag(?string $metadataJson): string {
        $meta = [];
        if (is_string($metadataJson) && $metadataJson !== '') {
            $decoded = json_decode($metadataJson, true);
            if (is_array($decoded)) $meta = $decoded;
        }
        if (!isset($meta['truncated'])) $meta['truncated'] = true;
        return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"truncated":true}';
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

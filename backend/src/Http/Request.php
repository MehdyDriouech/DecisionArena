<?php
namespace Http;

class Request {
    private array $params = [];
    private ?array $body = null;

    public function method(): string {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public function uri(): string {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($basePath && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }
        return rtrim($uri, '/') ?: '/';
    }

    public function setParams(array $params): void {
        $this->params = $params;
    }

    public function param(string $key, mixed $default = null): mixed {
        return $this->params[$key] ?? $default;
    }

    public function body(): array {
        if ($this->body === null) {
            $raw = file_get_contents('php://input');
            $this->body = json_decode($raw, true) ?? [];
        }
        return $this->body;
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->body()[$key] ?? $_GET[$key] ?? $default;
    }

    /** Query string (?key=value), for GET requests */
    public function query(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }
}

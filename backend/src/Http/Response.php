<?php
namespace Http;

class Response {
    public static function json(mixed $data, int $code = 200): array {
        http_response_code($code);
        return $data;
    }

    public static function error(string $message, int $code = 400): array {
        http_response_code($code);
        return ['error' => true, 'message' => $message];
    }
}

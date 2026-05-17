<?php
namespace Domain\Demo;

class DemoHttpException extends \RuntimeException {
    public function __construct(
        string $message,
        private int $statusCode = 403,
        private string $errorCode = ''
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }
}

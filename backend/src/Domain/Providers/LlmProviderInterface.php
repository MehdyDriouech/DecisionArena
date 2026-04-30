<?php
namespace Domain\Providers;

interface LlmProviderInterface {
    public function chat(array $messages, string $model): string;
    public function test(): bool;
}

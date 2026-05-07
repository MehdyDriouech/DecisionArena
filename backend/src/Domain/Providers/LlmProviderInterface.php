<?php
namespace Domain\Providers;

interface LlmProviderInterface {
    public function chat(array $messages, string $model, array $options = []): string;
    public function test(): bool;
}

<?php
namespace Domain\Providers;

class LMStudioProvider extends OpenAICompatibleProvider {
    public function __construct(
        string $baseUrl = 'http://localhost:1234',
        string $apiKey = '',
        string $defaultModel = 'local-model'
    ) {
        parent::__construct($baseUrl, $apiKey, $defaultModel);
    }
}

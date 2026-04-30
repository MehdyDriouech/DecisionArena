<?php
return [
    'default_providers' => [
        [
            'id'            => 'local-ollama',
            'name'          => 'Local Ollama',
            'type'          => 'ollama',
            'base_url'      => 'http://localhost:11434',
            'api_key'       => '',
            'default_model' => 'qwen2.5:14b',
            'enabled'       => 1,
        ],
        [
            'id'            => 'local-lmstudio',
            'name'          => 'Local LM Studio',
            'type'          => 'lmstudio',
            'base_url'      => 'http://localhost:1234',
            'api_key'       => '',
            'default_model' => 'local-model',
            'enabled'       => 1,
        ],
    ],
];

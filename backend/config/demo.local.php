<?php
/**
 * Exemple de configuration locale (NE PAS y mettre de vraie clé API).
 * Copier vers demo.local.php (ignoré par git) et adapter.
 */
return [
  'demo' => [
    'enabled' => false,
    'auth_required' => true,
    'accounts' => [
      'demo' => [
        'password_env' => 'DEMO_PASSWORD',
        'daily_llm_quota' => 2,
      ],
      'admin' => [
        'password_env' => 'ADMIN_PASSWORD',
        'daily_llm_quota' => 10,
      ],
    ],
  ],
  'gemini' => [
    'enabled' => true,
    'provider_id' => 'gemini',
    'name' => 'Google Gemini',
    'type' => 'openai-compatible',
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
    'default_model' => 'gemini-2.5-flash',
    'api_key_env' => 'GEMINI_API_KEY',
    'fallback_api_key_env' => 'GOOGLE_API_KEY',
    'api_key' => 'MetTaClefIci',
    'demo_primary' => true,
    'priority' => 10,
  ],
];

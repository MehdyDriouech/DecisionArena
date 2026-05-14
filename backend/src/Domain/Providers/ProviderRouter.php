<?php
namespace Domain\Providers;

use Domain\Agents\Agent;
use Infrastructure\Persistence\ProviderRepository;
use Infrastructure\Persistence\ProviderRoutingSettingsRepository;
use Infrastructure\Logging\Logger;

class ProviderRouter {
    private ProviderRepository $providerRepo;
    private ProviderRoutingSettingsRepository $settingsRepo;
    private Logger $logger;

    /** @var int */
    private static int $roundRobinIndex = 0;

    public function __construct(
        ?ProviderRepository $providerRepo = null,
        ?ProviderRoutingSettingsRepository $settingsRepo = null
    ) {
        $this->providerRepo = $providerRepo ?? new ProviderRepository();
        $this->settingsRepo = $settingsRepo ?? new ProviderRoutingSettingsRepository();
        $this->logger       = new Logger();
    }

    /**
     * Routes a chat call and returns provider/model metadata.
     *
     * Provider resolution priority order:
     * 1. $sessionAgentOverride (session_agent_providers table — per-session per-agent override)
     * 2. $explicitProviderId (explicit call parameter)
     * 3. Persona frontmatter default (agent->providerId)
     * 4. Global routing settings (routing_mode, etc.)
     *
     * @param array|null $sessionAgentOverride ['provider_id' => '...', 'model' => '...'] for the current agent
     * @return array{content:string, provider_id:string, provider_name:string, provider_type:string, model:string, routing_mode:string}
     */
    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        $explicitProviderId = $explicitProviderId !== null ? trim($explicitProviderId) : null;
        $explicitModel      = $explicitModel !== null ? trim($explicitModel) : null;
        $options            = is_array($options) ? $options : [];
        $temperature        = $this->normalizeTemperature($options['temperature'] ?? null);
        if ($temperature !== null) {
            $options['temperature'] = $temperature;
        } else {
            unset($options['temperature']);
        }

        // 1. Session-agent override (highest priority) — with graceful fallback to global routing
        $requestedProviderId = null;
        $requestedModel      = null;
        $fallbackReason      = null;

        if ($sessionAgentOverride && !empty($sessionAgentOverride['provider_id'])) {
            $requestedProviderId = (string)$sessionAgentOverride['provider_id'];
            $requestedModel      = !empty($sessionAgentOverride['model']) ? trim($sessionAgentOverride['model']) : null;

            try {
                $providerData = $this->resolveProviderRow($requestedProviderId);
                if (!$providerData) {
                    throw new \RuntimeException('Override provider not eligible or not found: ' . $requestedProviderId);
                }
                $model = $this->resolveModel($requestedModel ?? $explicitModel, $agent, $providerData);
                $provider = ProviderFactory::create($providerData);

                $start = (int)floor(microtime(true) * 1000);
                $this->logger->logLlmRequest([
                    'level'    => 'debug',
                    'category' => 'llm_request',
                    'agent_id' => $agent?->id,
                    'provider_id' => $requestedProviderId,
                    'model'    => $model,
                    'action'   => 'llm_request',
                    'request_payload' => [
                        'routing_mode' => 'session_agent_override',
                        'messages'     => $messages,
                        'options'      => ['temperature' => $temperature, 'max_tokens' => null, 'stream' => false],
                        'prompt_size'  => [
                            'message_count'   => count($messages),
                            'character_count' => $this->countChars($messages),
                        ],
                    ],
                ]);

                $content  = $provider->chat($messages, $model, $options);
                $duration = (int)floor(microtime(true) * 1000) - $start;

                $this->logger->logLlmResponse([
                    'level'    => 'debug',
                    'category' => 'llm_response',
                    'agent_id' => $agent?->id,
                    'provider_id' => $requestedProviderId,
                    'model'    => $model,
                    'action'   => 'llm_response',
                    'response_payload' => ['raw' => null, 'content' => $content, 'usage' => null],
                    'metadata' => ['duration_ms' => $duration, 'success' => true],
                ]);

                return [
                    'content'                => $content,
                    'provider_id'            => (string)$providerData['id'],
                    'provider_name'          => (string)($providerData['name'] ?? $providerData['id']),
                    'provider_type'          => (string)($providerData['type'] ?? ''),
                    'model'                  => $model,
                    'routing_mode'           => 'session_agent_override',
                    'requested_provider_id'  => $requestedProviderId,
                    'requested_model'        => $requestedModel,
                    'fallback_used'          => false,
                    'fallback_reason'        => null,
                ];

            } catch (\Throwable $e) {
                // Override failed — gracefully fall back to global routing
                $fallbackReason = 'Override provider unavailable (' . $requestedProviderId . '): ' . $e->getMessage();
                $this->logger->logProviderError('session_agent_override_fallback', [
                    'agent_id'              => $agent?->id,
                    'requested_provider_id' => $requestedProviderId,
                    'error_message'         => $e->getMessage(),
                    'metadata'              => ['action' => 'fallback_to_global_routing'],
                ]);
                // Fall through to global routing below
            }
        }

        // 2. Explicit provider selection (no routing settings)
        if ($explicitProviderId && !$fallbackReason) {
            $providerData = $this->resolveProviderRow($explicitProviderId);
            if (!$providerData) {
                throw new \RuntimeException('Selected provider is not enabled or does not exist.');
            }
            $model = $this->resolveModel($explicitModel, $agent, $providerData);
            $provider = ProviderFactory::create($providerData);

            $start = (int)floor(microtime(true) * 1000);
            $this->logger->logLlmRequest([
                'level' => 'debug',
                'category' => 'llm_request',
                'agent_id' => $agent?->id,
                'provider_id' => (string)$providerData['id'],
                'model' => $model,
                'action' => 'llm_request',
                'request_payload' => [
                    'routing_mode' => 'explicit',
                    'messages' => $messages,
                    'options' => [
                        'temperature' => $temperature,
                        'max_tokens' => null,
                        'stream' => false,
                    ],
                    'prompt_size' => [
                        'message_count' => count($messages),
                        'character_count' => $this->countChars($messages),
                    ],
                ],
            ]);

            $content = $provider->chat($messages, $model, $options);
            $duration = (int)floor(microtime(true) * 1000) - $start;

            $this->logger->logLlmResponse([
                'level' => 'debug',
                'category' => 'llm_response',
                'agent_id' => $agent?->id,
                'provider_id' => (string)$providerData['id'],
                'model' => $model,
                'action' => 'llm_response',
                'response_payload' => [
                    'raw' => null,
                    'content' => $content,
                    'usage' => null,
                ],
                'metadata' => [
                    'duration_ms' => $duration,
                    'success' => true,
                ],
            ]);
            return [
                'content'               => $content,
                'provider_id'           => (string)$providerData['id'],
                'provider_name'         => (string)($providerData['name'] ?? $providerData['id']),
                'provider_type'         => (string)($providerData['type'] ?? ''),
                'model'                 => $model,
                'routing_mode'          => 'explicit',
                'requested_provider_id' => null,
                'requested_model'       => null,
                'fallback_used'         => false,
                'fallback_reason'       => null,
            ];
        }

        $settings = $this->settingsRepo->get();
        $routingMode = (string)($settings['routing_mode'] ?? 'single-primary');
        $candidates = $this->buildCandidateProviders($routingMode, $settings, $agent);
        if (empty($candidates)) {
            throw new \RuntimeException('No eligible LLM provider. Configure a local server (base URL) or send provider_runtime with a cloud key for this request.');
        }

        $lastErr = null;
        foreach ($candidates as $providerData) {
            $start = (int)floor(microtime(true) * 1000);
            $model = '';
            try {
                $model = $this->resolveModel($explicitModel, $agent, $providerData);
                $provider = ProviderFactory::create($providerData);

                $this->logger->logLlmRequest([
                    'level' => 'debug',
                    'category' => 'llm_request',
                    'agent_id' => $agent?->id,
                    'provider_id' => (string)$providerData['id'],
                    'model' => $model,
                    'action' => 'llm_request',
                    'request_payload' => [
                        'routing_mode' => $routingMode,
                        'messages' => $messages,
                        'options' => [
                            'temperature' => $temperature,
                            'max_tokens' => null,
                            'stream' => false,
                        ],
                        'prompt_size' => [
                            'message_count' => count($messages),
                            'character_count' => $this->countChars($messages),
                        ],
                    ],
                ]);

                $content = $provider->chat($messages, $model, $options);
                $duration = (int)floor(microtime(true) * 1000) - $start;

                $this->logger->logLlmResponse([
                    'level' => 'debug',
                    'category' => 'llm_response',
                    'agent_id' => $agent?->id,
                    'provider_id' => (string)$providerData['id'],
                    'model' => $model,
                    'action' => 'llm_response',
                    'response_payload' => [
                        'raw' => null,
                        'content' => $content,
                        'usage' => null,
                    ],
                    'metadata' => [
                        'duration_ms' => $duration,
                        'success' => true,
                    ],
                ]);
                return [
                    'content'               => $content,
                    'provider_id'           => (string)$providerData['id'],
                    'provider_name'         => (string)($providerData['name'] ?? $providerData['id']),
                    'provider_type'         => (string)($providerData['type'] ?? ''),
                    'model'                 => $model,
                    'routing_mode'          => $fallbackReason ? 'fallback_from_override' : $routingMode,
                    'requested_provider_id' => $requestedProviderId,
                    'requested_model'       => $requestedModel,
                    'fallback_used'         => $fallbackReason !== null,
                    'fallback_reason'       => $fallbackReason,
                ];
            } catch (\Throwable $e) {
                $lastErr = $e;
                $duration = (int)floor(microtime(true) * 1000) - $start;
                $this->logger->logProviderError('provider_call_failed', [
                    'agent_id' => $agent?->id,
                    'provider_id' => (string)($providerData['id'] ?? ''),
                    'model' => $model ?: null,
                    'error_message' => $e->getMessage(),
                    'metadata' => [
                        'routing_mode' => $routingMode,
                        'duration_ms' => $duration,
                    ],
                ]);
                // Try next provider
            }
        }

        throw new \RuntimeException($lastErr ? $lastErr->getMessage() : 'All providers failed.');
    }

    private function countChars(array $messages): int {
        $sum = 0;
        foreach ($messages as $m) {
            if (is_array($m) && isset($m['content'])) {
                $sum += mb_strlen((string)$m['content'], 'UTF-8');
            }
        }
        return $sum;
    }

    private function resolveModel(?string $explicitModel, ?Agent $agent, array $providerData): string {
        $model = $explicitModel ?: ($agent?->model ?: (string)($providerData['default_model'] ?? ''));
        $model = trim((string)$model);
        if ($model === '') {
            throw new \RuntimeException('No model configured for this call.');
        }
        return $model;
    }

    private function normalizeTemperature(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $n = (float)$value;
        if (!is_finite($n)) {
            return null;
        }
        return max(0.0, min(2.0, $n));
    }

    private function resolveProviderRow(string $id): ?array {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $p = $this->providerRepo->findById($id);
        if ($p && $this->providerRepo->rowIsRoutingEligible($p)) {
            return $p;
        }
        foreach (CommercialRuntimeContext::getRows() as $row) {
            if ((string)($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Fournisseurs DB éligibles + stubs commerciaux (requête courante, provider_runtime).
     */
    private function getMergedRoutingCandidates(): array {
        $db = $this->providerRepo->findRoutingEligibleOrdered();
        $commercial = CommercialRuntimeContext::getRows();
        $byId = [];
        foreach ($db as $p) {
            $byId[(string)$p['id']] = $p;
        }
        foreach ($commercial as $p) {
            $cid = (string)($p['id'] ?? '');
            if ($cid !== '' && !isset($byId[$cid])) {
                $byId[$cid] = $p;
            }
        }
        $merged = array_values($byId);
        usort($merged, function ($a, $b) {
            $pa = (int)($a['priority'] ?? 100);
            $pb = (int)($b['priority'] ?? 100);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        return $merged;
    }

    private function buildCandidateProviders(string $routingMode, array $settings, ?Agent $agent): array {
        $enabled = $this->getMergedRoutingCandidates();
        if (empty($enabled)) {
            return [];
        }

        $byId = [];
        foreach ($enabled as $p) {
            $byId[(string)$p['id']] = $p;
        }

        $primaryId   = isset($settings['primary_provider_id']) ? (string)$settings['primary_provider_id'] : '';
        $preferredId = isset($settings['preferred_provider_id']) ? (string)$settings['preferred_provider_id'] : '';
        $fallbackIds = is_array($settings['fallback_provider_ids'] ?? null) ? $settings['fallback_provider_ids'] : [];
        $strategy    = (string)($settings['load_balance_strategy'] ?? 'round-robin');

        $primary   = ($primaryId !== '' && isset($byId[$primaryId])) ? $byId[$primaryId] : null;
        $preferred = ($preferredId !== '' && isset($byId[$preferredId])) ? $byId[$preferredId] : null;

        $unique = function (array $items): array {
            $seen = [];
            $out = [];
            foreach ($items as $p) {
                $id = (string)($p['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                $seen[$id] = true;
                $out[] = $p;
            }
            return $out;
        };

        $fallbackFromIds = [];
        foreach ($fallbackIds as $id) {
            $id = is_string($id) ? trim($id) : '';
            if ($id !== '' && isset($byId[$id])) $fallbackFromIds[] = $byId[$id];
        }

        $firstEnabled = $enabled[0];

        if ($routingMode === 'agent-default') {
            $agentProviderId = $agent?->providerId ? trim((string)$agent->providerId) : '';
            if ($agentProviderId !== '') {
                $resolved = $this->resolveProviderRow($agentProviderId);
                if ($resolved) {
                    return [$resolved];
                }
            }
            // Missing agent default -> fallback to primary behavior
            $routingMode = 'single-primary';
        }

        if ($routingMode === 'single-primary') {
            $chosen = $primary ?: $firstEnabled;
            $this->logger->logRoutingDecision('routing_select_primary', [
                'agent_id' => $agent?->id,
                'provider_id' => (string)($chosen['id'] ?? ''),
                'metadata' => ['routing_mode' => 'single-primary'],
            ]);
            return [$chosen];
        }

        if ($routingMode === 'preferred-with-fallback') {
            $head = $preferred ?: ($primary ?: $firstEnabled);
            $tail = !empty($fallbackFromIds)
                ? $fallbackFromIds
                : array_values(array_filter($enabled, fn($p) => (string)$p['id'] !== (string)$head['id']));
            $this->logger->logRoutingDecision('routing_select_preferred', [
                'agent_id' => $agent?->id,
                'provider_id' => (string)($head['id'] ?? ''),
                'metadata' => [
                    'routing_mode' => 'preferred-with-fallback',
                    'fallback_provider_ids' => array_map(fn($p) => (string)($p['id'] ?? ''), $tail),
                ],
            ]);
            return $unique(array_merge([$head], $tail));
        }

        if ($routingMode === 'load-balance') {
            $count = count($enabled);
            if ($count === 1) return [$enabled[0]];

            $chosenIndex = 0;
            if ($strategy === 'random') {
                $chosenIndex = random_int(0, $count - 1);
            } else {
                $chosenIndex = self::$roundRobinIndex % $count;
                self::$roundRobinIndex++;
            }

            // On failure: try next providers in list order (wraparound)
            $ordered = [];
            for ($i = 0; $i < $count; $i++) {
                $ordered[] = $enabled[($chosenIndex + $i) % $count];
            }
            $this->logger->logRoutingDecision('routing_select_load_balance', [
                'agent_id' => $agent?->id,
                'provider_id' => (string)($ordered[0]['id'] ?? ''),
                'metadata' => [
                    'routing_mode' => 'load-balance',
                    'strategy' => $strategy,
                    'candidates' => array_map(fn($p) => (string)($p['id'] ?? ''), $ordered),
                ],
            ]);
            return $unique($ordered);
        }

        // Default fallback
        return [$primary ?: $firstEnabled];
    }
}


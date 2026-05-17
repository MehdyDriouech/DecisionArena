<?php
namespace Domain\Providers;

use Domain\Agents\Agent;
use Domain\Orchestration\RunTimeoutPolicy;
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
     * Product priority (conceptual — team assignment is expanded to per-agent rows in session_agent_providers at session creation):
     * 1. session_agent_providers (session / analysis override per agent)
     * 2. Team-derived overrides (confrontation only; same table after expansion — does not beat an explicit per-agent row in payload)
     * 3. Persona frontmatter default_provider / default_model (fallback configuration)
     * 4. Global routing settings (provider_routing_settings)
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

        $options = $this->mergeDefaultHttpTimeouts($options);

        // 1. Session-agent override (highest priority) — with graceful fallback to global routing
        $requestedProviderId = null;
        $requestedModel      = null;
        $fallbackReason      = null;
        $personaProviderId = trim((string)($agent?->providerId ?? ''));
        $personaProviderDisabled = $personaProviderId !== '' && $this->isProviderDisabled($personaProviderId);

        if ($sessionAgentOverride && !empty($sessionAgentOverride['provider_id'])) {
            $requestedProviderId = (string)$sessionAgentOverride['provider_id'];
            $requestedModel      = !empty($sessionAgentOverride['model']) ? trim($sessionAgentOverride['model']) : null;

            try {
                $providerData = $this->resolveProviderRow($requestedProviderId);
                if (!$providerData) {
                    throw new \RuntimeException('Override provider not eligible or not found: ' . $requestedProviderId);
                }
                $model = $this->resolveModel(
                    $requestedModel ?? $explicitModel,
                    $agent,
                    $providerData,
                    false
                );
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

                $options = $this->enrichLlmTelemetryBeforeInvoke($options, 'session_override', (string)$providerData['id']);
                $content  = $this->invokeProviderChat($provider, $messages, $model, $options, $agent);
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

                return $this->attachBillingMetadata([
                    'content'                => $content,
                    'provider_id'            => (string)$providerData['id'],
                    'provider_name'          => (string)($providerData['name'] ?? $providerData['id']),
                    'provider_type'          => (string)($providerData['type'] ?? ''),
                    'model'                  => $model,
                    'routing_mode'           => 'session_agent_override',
                    'routing_source'         => 'session_override',
                    'requested_provider_id'  => $requestedProviderId,
                    'requested_model'        => $requestedModel,
                    'resolved_provider_id'   => (string)$providerData['id'],
                    'resolved_provider_label'=> (string)($providerData['name'] ?? $providerData['id']),
                    'resolved_model'         => $model,
                    'requested_routing_source' => 'session_override',
                    'session_override_present' => true,
                    'persona_default_provider_ignored' => (
                        trim((string)($agent?->providerId ?? '')) !== ''
                        && trim((string)($agent?->providerId ?? '')) !== (string)$providerData['id']
                    ),
                    'fallback_used'          => false,
                    'fallback_reason'        => null,
                ], $providerData);

            } catch (\Throwable $e) {
                // Override failed — gracefully fall back to global routing
                if ($this->isProviderDisabled($requestedProviderId)) {
                    $fallbackReason = 'Provider disabled';
                } else {
                    $fallbackReason = 'Override provider unavailable (' . $requestedProviderId . '): ' . $e->getMessage();
                }
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
            $model = $this->resolveModel($explicitModel, $agent, $providerData, false);
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

            $options = $this->enrichLlmTelemetryBeforeInvoke($options, 'explicit_call', (string)$providerData['id']);
            $content = $this->invokeProviderChat($provider, $messages, $model, $options, $agent);
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
            return $this->attachBillingMetadata([
                'content'               => $content,
                'provider_id'           => (string)$providerData['id'],
                'provider_name'         => (string)($providerData['name'] ?? $providerData['id']),
                'provider_type'         => (string)($providerData['type'] ?? ''),
                'model'                 => $model,
                'routing_mode'          => 'explicit',
                'routing_source'        => 'explicit_call',
                'requested_provider_id' => null,
                'requested_model'       => null,
                'resolved_provider_id'  => (string)$providerData['id'],
                    'resolved_provider_label'=> (string)($providerData['name'] ?? $providerData['id']),
                'resolved_model'        => $model,
                    'requested_routing_source' => null,
                'session_override_present' => $requestedProviderId !== null,
                'persona_default_provider_ignored' => (
                    trim((string)($agent?->providerId ?? '')) !== ''
                    && trim((string)($agent?->providerId ?? '')) !== (string)$providerData['id']
                ),
                'fallback_used'         => false,
                'fallback_reason'       => null,
            ], $providerData);
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
                $preferAgentModel = ($routingMode === 'agent-default');
                $model = $this->resolveModel($explicitModel, $agent, $providerData, $preferAgentModel);
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

                $routingSource = (
                    $personaProviderId !== '' && !$personaProviderDisabled
                        ? 'persona_default'
                        : 'global_routing'
                );
                $options = $this->enrichLlmTelemetryBeforeInvoke($options, $routingSource, (string)($providerData['id'] ?? ''));
                $content = $this->invokeProviderChat($provider, $messages, $model, $options, $agent);
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
                return $this->attachBillingMetadata([
                    'routing_source'         => (
                        $personaProviderId !== '' && !$personaProviderDisabled
                            ? 'persona_default'
                            : 'global_routing'
                    ),
                    'content'               => $content,
                    'provider_id'           => (string)$providerData['id'],
                    'provider_name'         => (string)($providerData['name'] ?? $providerData['id']),
                    'provider_type'         => (string)($providerData['type'] ?? ''),
                    'model'                 => $model,
                    'routing_mode'          => $fallbackReason ? 'fallback_from_override' : $routingMode,
                    'requested_provider_id' => $requestedProviderId,
                    'requested_model'       => $requestedModel,
                    'resolved_provider_id'  => (string)$providerData['id'],
                    'resolved_provider_label'=> (string)($providerData['name'] ?? $providerData['id']),
                    'resolved_model'        => $model,
                    'requested_routing_source' => $requestedProviderId !== null ? 'session_override' : null,
                    'session_override_present' => $requestedProviderId !== null,
                    'persona_default_provider_ignored' => (
                        $personaProviderId !== ''
                        && ($personaProviderDisabled || $personaProviderId !== (string)$providerData['id'])
                    ),
                    'fallback_used'         => ($fallbackReason !== null) || $personaProviderDisabled,
                    'fallback_reason'       => $fallbackReason ?? ($personaProviderDisabled ? 'Provider disabled' : null),
                    'fallback_from_provider_id' => $fallbackReason !== null ? $requestedProviderId : null,
                    'fallback_from_model'   => $fallbackReason !== null ? $requestedModel : null,
                ], $providerData);
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

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function mergeDefaultHttpTimeouts(array $options): array
    {
        $runMode = (string)($options['run_mode'] ?? '');
        if (!isset($options['http_timeout_seconds'])) {
            $options['http_timeout_seconds'] = RunTimeoutPolicy::llmHttpTimeoutSecondsForMode($runMode);
        }
        if (!isset($options['connect_timeout_seconds'])) {
            $options['connect_timeout_seconds'] = RunTimeoutPolicy::connectTimeoutSeconds();
        }
        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function enrichLlmTelemetryBeforeInvoke(array $options, string $routingSource, string $resolvedProviderId): array
    {
        if (!isset($options['llm_telemetry']) || !is_array($options['llm_telemetry'])) {
            return $options;
        }
        $options['llm_telemetry']['routing_source'] = $routingSource;
        if ($resolvedProviderId !== '') {
            $options['llm_telemetry']['resolved_provider_id'] = $resolvedProviderId;
        }
        return $options;
    }

    /**
     * @param array<string,mixed> $routerOptions
     * @return array<string,mixed>
     */
    private function providerFacingCurlOptions(array $routerOptions): array
    {
        $keys = ['temperature', 'http_timeout_seconds', 'connect_timeout_seconds'];
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $routerOptions)) {
                $out[$k] = $routerOptions[$k];
            }
        }
        return $out;
    }

    private function isProbablyCurlTimeout(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'timed out')
            || str_contains($m, 'timeout')
            || str_contains($m, 'curl error 28')
            || str_contains($m, 'operation timed out');
    }

    /**
     * @param array<string,mixed> $routerOptions
     */
    private function emitLlmTelemetryEvent(
        \Infrastructure\Persistence\RunStatusRepository $repo,
        string $sessionId,
        string $eventPhase,
        array $routerOptions,
        ?Agent $agent,
        string $model,
        ?int $durationMs,
        ?string $errorKind = null
    ): void {
        $tel = $routerOptions['llm_telemetry'] ?? [];
        if (!is_array($tel)) {
            return;
        }
        $agentId = $tel['agent_id'] ?? $agent?->id;
        $orchPhase = $tel['phase'] ?? null;
        $label = match ($eventPhase) {
            'llm_call_started' => 'Appel LLM demarre',
            'llm_call_completed' => 'Appel LLM termine',
            'llm_call_failed' => 'Appel LLM en echec',
            'llm_call_timeout' => 'Appel LLM timeout',
            default => $eventPhase,
        };
        $event = [
            'level' => ($eventPhase === 'llm_call_failed' || $eventPhase === 'llm_call_timeout') ? 'warning' : 'info',
            'phase' => $eventPhase,
            'round' => isset($tel['round']) ? (int)$tel['round'] : null,
            'team' => $tel['team'] ?? null,
            'agent_id' => $agentId,
            'label' => $label,
            'provider_id' => $tel['resolved_provider_id'] ?? $tel['requested_provider_id'] ?? null,
            'model' => $model !== '' ? $model : null,
            'routing_source' => $tel['routing_source'] ?? null,
            'duration_ms' => $durationMs,
            'orchestration_phase' => is_string($orchPhase) ? $orchPhase : null,
        ];
        if ($errorKind !== null && $errorKind !== '') {
            $event['error_kind'] = $errorKind;
        }
        try {
            $repo->appendEvent($sessionId, $event, [], 'running', null);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string,mixed> $routerOptions
     */
    private function invokeProviderChat(
        LlmProviderInterface $provider,
        array $messages,
        string $model,
        array $routerOptions,
        ?Agent $agent
    ): string {
        $tel = $routerOptions['llm_telemetry'] ?? null;
        $repo = null;
        $sessionId = '';
        if (is_array($tel) && !empty($tel['session_id'])) {
            $sessionId = (string)$tel['session_id'];
            try {
                $repo = new \Infrastructure\Persistence\RunStatusRepository();
            } catch (\Throwable) {
                $repo = null;
            }
        }
        $provOpts = $this->providerFacingCurlOptions($routerOptions);
        $t0 = microtime(true);
        if ($repo !== null && $sessionId !== '') {
            $this->emitLlmTelemetryEvent($repo, $sessionId, 'llm_call_started', $routerOptions, $agent, $model, null, null);
        }
        try {
            $content = $provider->chat($messages, $model, $provOpts);
            $durMs = (int)round((microtime(true) - $t0) * 1000);
            if ($repo !== null && $sessionId !== '') {
                $this->emitLlmTelemetryEvent($repo, $sessionId, 'llm_call_completed', $routerOptions, $agent, $model, $durMs, null);
            }
            return $content;
        } catch (\Throwable $e) {
            $durMs = (int)round((microtime(true) - $t0) * 1000);
            if ($repo !== null && $sessionId !== '') {
                $isT = $this->isProbablyCurlTimeout($e);
                $this->emitLlmTelemetryEvent(
                    $repo,
                    $sessionId,
                    $isT ? 'llm_call_timeout' : 'llm_call_failed',
                    $routerOptions,
                    $agent,
                    $model,
                    $durMs,
                    $isT ? 'timeout' : 'provider_error'
                );
            }
            throw $e;
        }
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

    private function resolveModel(?string $explicitModel, ?Agent $agent, array $providerData, bool $preferAgentModel = true): string {
        $providerDefault = (string)($providerData['default_model'] ?? '');
        $agentModel = (string)($agent?->model ?? '');
        $model = $explicitModel
            ?: ($preferAgentModel
                ? ($agentModel ?: $providerDefault)
                : ($providerDefault ?: $agentModel));
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

    private function isProviderDisabled(?string $id): bool
    {
        $pid = trim((string)$id);
        if ($pid === '') {
            return false;
        }
        $row = $this->providerRepo->findById($pid);
        if (!$row) {
            return false;
        }
        return (int)($row['enabled'] ?? 0) !== 1;
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
            if ($cid !== '') {
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
        $agentPreferred = null;
        $agentProviderId = trim((string)($agent?->providerId ?? ''));
        if ($agentProviderId !== '') {
            $agentPreferred = $this->resolveProviderRow($agentProviderId);
        }

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
            if ($agentPreferred) {
                return [$agentPreferred];
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
            return $agentPreferred ? $unique([$agentPreferred, $chosen]) : [$chosen];
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
            $base = $unique(array_merge([$head], $tail));
            return $agentPreferred ? $unique(array_merge([$agentPreferred], $base)) : $base;
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
            return $agentPreferred ? $unique(array_merge([$agentPreferred], $ordered)) : $unique($ordered);
        }

        // Default fallback
        $fallback = $primary ?: $firstEnabled;
        return $agentPreferred ? $unique([$agentPreferred, $fallback]) : [$fallback];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $providerData
     * @return array<string,mixed>
     */
    private function attachBillingMetadata(array $result, array $providerData): array {
        $byok = ($providerData['billing_source'] ?? '') === 'byok'
            || !empty($providerData['byok_used']);
        $result['billing_source'] = $byok ? 'byok' : 'server';
        $result['byok_used'] = $byok;
        return $result;
    }
}


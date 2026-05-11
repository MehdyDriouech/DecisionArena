<?php
namespace Controllers;

use Domain\Agents\AgentAssembler;
use Domain\Orchestration\CanonicalSynthesisExtractor;
use Domain\Orchestration\PromptBuilder;
use Domain\Providers\ProviderRouter;
use Http\Request;
use Http\Response;
use Infrastructure\Logging\Logger;

class PersonaSandboxController {
    private AgentAssembler $assembler;
    private PromptBuilder $promptBuilder;
    private ProviderRouter $providerRouter;
    private Logger $logger;

    public function __construct() {
        $this->assembler      = new AgentAssembler();
        $this->promptBuilder  = new PromptBuilder();
        $this->providerRouter = new ProviderRouter();
        $this->logger         = new Logger();
    }

    public function test(Request $req): array {
        $data = $req->body();
        $prompt = trim((string)($data['prompt'] ?? ''));
        $runs = is_array($data['runs'] ?? null) ? $data['runs'] : [];
        $language = $this->normalizeLanguage((string)($data['language'] ?? 'fr'));

        if ($prompt === '') {
            return Response::error('prompt is required', 400);
        }
        if ($runs === []) {
            return Response::error('at least one run is required', 400);
        }

        $runs = array_slice(array_values($runs), 0, 6);
        $results = [];
        foreach ($runs as $index => $run) {
            $results[] = $this->executeRun($prompt, is_array($run) ? $run : [], $language, $index);
        }

        return [
            'success' => true,
            'prompt' => $prompt,
            'language' => $language,
            'runs' => $results,
        ];
    }

    /** @param array<string,mixed> $run */
    private function executeRun(string $prompt, array $run, string $language, int $index): array {
        $personaId = $this->cleanId((string)($run['persona_id'] ?? ''));
        $providerId = $this->nullableString($run['provider_id'] ?? null);
        $model = $this->nullableString($run['model'] ?? null);
        $temperature = $this->nullableTemperature($run['temperature'] ?? null);

        $base = [
            'run_id' => 'run_' . ($index + 1),
            'persona_id' => $personaId,
            'persona_name' => '',
            'provider_id' => $providerId,
            'provider_name' => '',
            'provider_type' => '',
            'model' => $model,
            'routing_mode' => '',
            'duration_ms' => 0,
            'system_prompt' => '',
            'user_prompt' => $prompt,
            'raw_response' => '',
            'error' => null,
            'diagnostics' => [
                'message_count' => 0,
                'prompt_characters' => 0,
                'temperature_requested' => $temperature,
                'temperature_applied' => false,
                'fallback_used' => false,
                'fallback_reason' => null,
                'parser_diagnostics' => null,
            ],
        ];

        if ($personaId === '') {
            return array_merge($base, ['error' => 'persona_id is required']);
        }

        try {
            $agent = $this->assembler->assemble($personaId, $providerId, $model);
            if (!$agent) {
                return array_merge($base, ['error' => 'Persona not found: ' . $personaId]);
            }

            $messages = $this->promptBuilder->buildChatMessages(
                $agent,
                'Persona Sandbox',
                [],
                $prompt,
                $language,
                null,
                null,
                null
            );
            $this->logger->logPromptBuild('prompt_built_chat', [
                'agent_id' => $agent->id,
                'metadata' => [
                    'mode' => 'chat',
                    'message_count' => count($messages),
                    'character_count' => $this->countMessageChars($messages),
                    'context_doc_injected' => false,
                    'session_id' => null,
                    'source' => 'persona_sandbox',
                ],
            ]);

            $systemPrompt = (string)($messages[0]['content'] ?? '');
            $userPrompt = (string)($messages[1]['content'] ?? $prompt);
            $base['persona_id'] = $agent->id;
            $base['persona_name'] = $agent->persona->name;
            $base['system_prompt'] = $systemPrompt;
            $base['user_prompt'] = $userPrompt;
            $base['diagnostics']['message_count'] = count($messages);
            $base['diagnostics']['prompt_characters'] = $this->countMessageChars($messages);
            $base['diagnostics']['temperature_applied'] = $temperature !== null;
            $started = microtime(true);
            $response = $this->providerRouter->chat(
                $messages,
                $agent,
                $providerId,
                $model,
                null,
                $temperature !== null ? ['temperature' => $temperature] : []
            );
            $durationMs = (int)round((microtime(true) - $started) * 1000);
            $raw = (string)($response['content'] ?? '');
            $canonical = CanonicalSynthesisExtractor::extract($raw, null);

            return [
                'run_id' => $base['run_id'],
                'persona_id' => $agent->id,
                'persona_name' => $agent->persona->name,
                'provider_id' => (string)($response['provider_id'] ?? ($providerId ?? '')),
                'provider_name' => (string)($response['provider_name'] ?? ''),
                'provider_type' => (string)($response['provider_type'] ?? ''),
                'model' => (string)($response['model'] ?? ($model ?? '')),
                'routing_mode' => (string)($response['routing_mode'] ?? ''),
                'duration_ms' => $durationMs,
                'system_prompt' => $systemPrompt,
                'user_prompt' => $userPrompt,
                'raw_response' => $raw,
                'error' => null,
                'diagnostics' => [
                    'message_count' => count($messages),
                    'prompt_characters' => $this->countMessageChars($messages),
                    'temperature_requested' => $temperature,
                    'temperature_applied' => $temperature !== null,
                    'fallback_used' => (bool)($response['fallback_used'] ?? false),
                    'fallback_reason' => $response['fallback_reason'] ?? null,
                    'requested_provider_id' => $response['requested_provider_id'] ?? $providerId,
                    'requested_model' => $response['requested_model'] ?? $model,
                    'parser_diagnostics' => $canonical['parser_diagnostics'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return array_merge($base, ['error' => $e->getMessage()]);
        }
    }

    /** @param list<array<string,mixed>> $messages */
    private function countMessageChars(array $messages): int {
        $sum = 0;
        foreach ($messages as $message) {
            $sum += mb_strlen((string)($message['content'] ?? ''), 'UTF-8');
        }
        return $sum;
    }

    private function normalizeLanguage(string $language): string {
        return strtolower(trim($language)) === 'en' ? 'en' : 'fr';
    }

    private function cleanId(string $id): string {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($id))) ?: '';
    }

    private function nullableString(mixed $value): ?string {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableTemperature(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        $n = is_numeric($value) ? (float)$value : null;
        if ($n === null || !is_finite($n)) {
            return null;
        }
        return max(0.0, min(2.0, $n));
    }
}

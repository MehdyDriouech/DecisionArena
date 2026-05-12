<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class RuntimePromptGuard
{
    /**
     * @param array<string,mixed>|null $registryDefinition
     * @param array<string,mixed> $extra
     */
    public static function inspectStep(
        string $blockId,
        ?array $registryDefinition,
        int $injectedChars,
        string $inclusionReason,
        array $extra = []
    ): void {
        if (!CognitiveRuntimeQAMode::enabled()) {
            return;
        }
        $mapped = $registryDefinition !== null || in_array($blockId, ['global_system'], true) || str_ends_with($blockId, '_dedup_omitted');
        if (!$mapped) {
            self::violation('injection_outside_registry', [
                'block_id' => $blockId,
                'inclusion_reason' => $inclusionReason,
            ], CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::EXPERT));
        }
        if ($injectedChars > 0
            && !in_array($blockId, ['global_system'], true)
            && ($extra['budget_layer'] ?? null) === null
            && !str_ends_with($blockId, '_dedup_omitted')
        ) {
            self::violation('injection_unbudgeted', [
                'block_id' => $blockId,
                'injected_chars' => $injectedChars,
                'inclusion_reason' => $inclusionReason,
            ], CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::QA));
        }
        if ($injectedChars > 0
            && !isset($extra['content_hash'])
            && !in_array($blockId, ['global_system'], true)
            && !str_ends_with($blockId, '_dedup_omitted')
        ) {
            self::violation('injection_untraced_content_hash_missing', [
                'block_id' => $blockId,
                'injection_key' => PromptInjectionRegistry::injectionKeyForBlockId($blockId),
                'injected_chars' => $injectedChars,
                'inclusion_reason' => $inclusionReason,
                'missing_fields' => ['content_hash'],
                'phase' => 'trace_collect',
            ], false);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param list<string> $warnings
     */
    public static function inspectPolicyWarnings(array $context, array $warnings): void
    {
        if (!CognitiveRuntimeQAMode::enabled()) {
            return;
        }
        RuntimeSafetyRecorder::record('prompt_runtime_policy_warnings', [
            'mode' => (string)($context['mode'] ?? ''),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function violation(string $code, array $payload, bool $throw): void
    {
        RuntimeSafetyRecorder::record('runtime_prompt_guard_violation', [
            'code' => $code,
            'payload' => $payload,
        ]);
        if ($throw) {
            throw new \RuntimeException('RuntimePromptGuard violation: ' . $code);
        }
    }
}


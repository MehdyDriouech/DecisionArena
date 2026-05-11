<?php
declare(strict_types=1);

namespace Domain\CognitiveGovernance;

/**
 * Lightweight QA runtime guard for cognitive layer mutation boundaries.
 * Active only when QA_RUNTIME_GUARD=1.
 */
final class CanonicalLayerMutationGuard
{
    /** @var array<string, array<string, list<string>>> */
    private const FORBIDDEN = [
        'prompt_builder' => [
            'sqlite' => ['insert', 'update', 'delete', 'write', 'persist'],
            'filesystem' => ['write', 'create', 'ensure', 'persist'],
            'beliefs' => ['create', 'update', 'delete', 'archive', 'mutate'],
            'narrative' => ['create', 'update', 'delete', 'mutate'],
            'snapshots' => ['create', 'update', 'delete', 'restore', 'mutate'],
        ],
        'narrative_service' => [
            'beliefs' => ['create', 'update', 'archive', 'delete', 'mutate'],
            'memory' => ['write', 'ensure', 'delete', 'mutate'],
        ],
        'memory_compiler' => [
            'beliefs' => ['create', 'update', 'archive', 'delete', 'mutate'],
            'memory' => ['write', 'overwrite', 'ensure', 'delete', 'mutate'],
        ],
        'snapshot_service' => [
            'runtime_state' => ['implicit_restore', 'overwrite'],
        ],
    ];

    public static function enabled(): bool
    {
        return CognitiveRuntimeQAMode::enabled();
    }

    /** @param array<string,mixed> $context */
    public static function assertAllowed(string $sourceLayer, string $targetLayer, string $operation, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $src = strtolower(trim($sourceLayer));
        $tgt = strtolower(trim($targetLayer));
        $op = strtolower(trim($operation));
        $forbidden = self::FORBIDDEN[$src][$tgt] ?? [];
        if (!in_array($op, $forbidden, true)) {
            return;
        }
        $message = sprintf(
            'Cognitive mutation guard violation: %s -> %s (%s)',
            $src,
            $tgt,
            $op
        );
        self::logViolation($message, $context);
        throw new \RuntimeException($message);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function assertStrictContextSocialRead(string $sourceLayer, ?string $strategicContextId, bool $includeLegacy, array $rows): void
    {
        if (!self::enabled()) {
            return;
        }
        $ctx = is_string($strategicContextId) ? trim($strategicContextId) : '';
        if ($ctx === '') {
            return;
        }
        if ($includeLegacy) {
            self::logViolation('QA social legacy opt-in activated', [
                'source_layer' => $sourceLayer,
                'strategic_context_id' => $ctx,
            ]);
            return;
        }
        foreach ($rows as $row) {
            $rowCtx = isset($row['strategic_context_id']) ? trim((string)$row['strategic_context_id']) : '';
            if ($rowCtx !== $ctx) {
                $message = 'Strict-context social read violation: cross-context or NULL row returned';
                self::logViolation($message, [
                    'source_layer' => $sourceLayer,
                    'expected_context_id' => $ctx,
                    'row_context_id' => $rowCtx !== '' ? $rowCtx : null,
                ]);
                throw new \RuntimeException($message);
            }
        }
    }

    /** @param array<string,mixed> $context */
    public static function logViolation(string $message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $payload = [
            'at' => date('c'),
            'message' => $message,
            'context' => $context,
            'qa_mode' => CognitiveRuntimeQAMode::current(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log('[QA_RUNTIME_GUARD] ' . ($json !== false ? $json : $message));
        RuntimeSafetyRecorder::record('canonical_mutation_guard', $payload);
    }
}


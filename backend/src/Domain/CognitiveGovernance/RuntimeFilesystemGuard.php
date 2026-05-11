<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class RuntimeFilesystemGuard
{
    /**
     * @param array<string,mixed> $context
     */
    public static function inspect(string $operation, string $path, array $context = []): void
    {
        if (!CognitiveRuntimeQAMode::writeGuardsEnabled()) {
            return;
        }
        $stack = self::stack(8);
        $payload = [
            'operation' => strtolower(trim($operation)),
            'path' => str_replace('\\', '/', $path),
            'context' => $context,
            'stack' => $stack,
        ];
        RuntimeSafetyRecorder::record('filesystem_write_detected', $payload);
        if (self::stackHasClass($stack, 'Domain\\Orchestration\\PromptBuilder')) {
            RuntimeSafetyRecorder::record('runtime_filesystem_guard_violation', [
                'code' => 'prompt_builder_filesystem_write_forbidden',
                'payload' => $payload,
            ]);
            if (CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::QA)) {
                throw new \RuntimeException('RuntimeFilesystemGuard violation: prompt_builder_filesystem_write_forbidden');
            }
        }
    }

    /**
     * @return list<array{frame:string,class:string,function:string,file:string,line:int}>
     */
    private static function stack(int $limit): array
    {
        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
        $out = [];
        foreach ($raw as $f) {
            $class = (string)($f['class'] ?? '');
            $func = (string)($f['function'] ?? '');
            if ($class === self::class || $class === RuntimeSafetyRecorder::class) {
                continue;
            }
            $frame = ($class !== '' ? $class . '::' : '') . $func;
            $out[] = [
                'frame' => $frame,
                'class' => $class,
                'function' => $func,
                'file' => (string)($f['file'] ?? ''),
                'line' => (int)($f['line'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array{frame:string,class:string,function:string,file:string,line:int}> $stack
     */
    private static function stackHasClass(array $stack, string $className): bool
    {
        foreach ($stack as $f) {
            if (($f['class'] ?? '') === $className) {
                return true;
            }
        }

        return false;
    }
}


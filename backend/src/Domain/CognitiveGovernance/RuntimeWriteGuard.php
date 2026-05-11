<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class RuntimeWriteGuard
{
    private const WRITE_VERBS = ['insert', 'update', 'delete', 'replace', 'create', 'drop', 'alter', 'truncate', 'vacuum', 'reindex'];

    public static function inspectSql(string $sql, string $channel = 'unknown'): void
    {
        if (!CognitiveRuntimeQAMode::writeGuardsEnabled()) {
            return;
        }
        $verb = self::extractVerb($sql);
        if ($verb === null || !in_array($verb, self::WRITE_VERBS, true)) {
            return;
        }
        $table = self::extractTable($sql, $verb);
        $stack = self::stack(8);
        $caller = $stack[0]['frame'] ?? 'unknown';
        $payload = [
            'channel' => $channel,
            'verb' => $verb,
            'table' => $table,
            'caller' => $caller,
            'sql_hash' => hash('sha256', $sql),
            'stack' => $stack,
        ];
        RuntimeSafetyRecorder::record('sqlite_write_detected', $payload);
        self::assertPolicy($verb, $table, $stack, $payload);
    }

    /**
     * @param list<array{frame:string,class:string,function:string,file:string,line:int}> $stack
     * @param array<string,mixed> $payload
     */
    private static function assertPolicy(string $verb, ?string $table, array $stack, array $payload): void
    {
        if (self::stackHasClass($stack, 'Domain\\Orchestration\\PromptBuilder')) {
            self::violation('sqlite_write_from_prompt_builder', $payload, CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::QA));
        }
        if ($table === 'strategic_context_snapshots' && in_array($verb, ['update', 'delete', 'replace'], true)) {
            self::violation('snapshot_overwrite_forbidden', $payload, true);
        }
        if ($table === 'strategic_context_beliefs'
            && $verb === 'update'
            && !self::stackHasClass($stack, 'Domain\\StrategicContext\\BeliefEngineService')
        ) {
            self::violation('belief_mutation_outside_belief_engine', $payload, CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::EXPERT));
        }
        if (CognitiveRuntimeQAMode::isAtLeast(CognitiveRuntimeQAMode::EXPERT)
            && !self::stackHasNamespace($stack, 'Infrastructure\\Persistence\\')
            && !self::stackHasNamespace($stack, 'Infrastructure\\Persistence\\Migration')
        ) {
            self::violation('write_outside_repository_layer', $payload, true);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function violation(string $code, array $payload, bool $throw): void
    {
        RuntimeSafetyRecorder::record('runtime_write_guard_violation', [
            'code' => $code,
            'payload' => $payload,
        ]);
        if ($throw) {
            throw new \RuntimeException('RuntimeWriteGuard violation: ' . $code);
        }
    }

    private static function extractVerb(string $sql): ?string
    {
        $trim = ltrim($sql);
        if ($trim === '') {
            return null;
        }
        if (preg_match('/^([a-z]+)/i', $trim, $m) !== 1) {
            return null;
        }

        return strtolower((string)$m[1]);
    }

    private static function extractTable(string $sql, string $verb): ?string
    {
        $s = strtolower($sql);
        $patterns = match ($verb) {
            'insert', 'replace' => ['/into\s+([a-z0-9_]+)/i'],
            'update' => ['/update\s+([a-z0-9_]+)/i'],
            'delete' => ['/from\s+([a-z0-9_]+)/i'],
            'create', 'drop', 'alter', 'truncate' => ['/(table|index)\s+(if\s+not\s+exists\s+|if\s+exists\s+)?([a-z0-9_]+)/i'],
            default => [],
        };
        foreach ($patterns as $p) {
            if (preg_match($p, $s, $m) === 1) {
                if (isset($m[3]) && trim((string)$m[3]) !== '') {
                    return trim((string)$m[3]);
                }
                if (isset($m[1]) && trim((string)$m[1]) !== '') {
                    return trim((string)$m[1]);
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{frame:string,class:string,function:string,file:string,line:int}>
     */
    private static function stack(int $limit): array
    {
        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32);
        $out = [];
        foreach ($raw as $f) {
            $class = (string)($f['class'] ?? '');
            $func = (string)($f['function'] ?? '');
            if ($class === self::class || $class === RuntimeSafetyRecorder::class) {
                continue;
            }
            if (str_contains($class, 'RuntimeAwarePdo')) {
                continue;
            }
            $file = (string)($f['file'] ?? '');
            $line = (int)($f['line'] ?? 0);
            $frame = ($class !== '' ? $class . '::' : '') . $func;
            $out[] = [
                'frame' => $frame,
                'class' => $class,
                'function' => $func,
                'file' => $file,
                'line' => $line,
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

    /**
     * @param list<array{frame:string,class:string,function:string,file:string,line:int}> $stack
     */
    private static function stackHasNamespace(array $stack, string $prefix): bool
    {
        foreach ($stack as $f) {
            if (str_starts_with((string)($f['class'] ?? ''), $prefix)) {
                return true;
            }
        }

        return false;
    }
}


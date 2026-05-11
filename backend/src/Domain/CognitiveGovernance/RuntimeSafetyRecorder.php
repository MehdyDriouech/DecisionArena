<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class RuntimeSafetyRecorder
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function record(string $event, array $payload = []): void
    {
        if (!CognitiveRuntimeQAMode::enabled()) {
            return;
        }
        $row = [
            'at' => date('c'),
            'qa_mode' => CognitiveRuntimeQAMode::current(),
            'event' => $event,
            'payload' => $payload,
        ];
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log('[COGNITIVE_RUNTIME_QA] ' . ($json !== false ? $json : $event));
        self::appendLocalLog($json !== false ? $json : $event);
    }

    public static function localLogPath(): string
    {
        return dirname(__DIR__, 3) . '/storage/logs/cognitive-runtime-qa.log';
    }

    private static function appendLocalLog(string $line): void
    {
        $path = self::localLogPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}


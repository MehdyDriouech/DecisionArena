<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class CognitiveRuntimeQAMode
{
    public const OFF = 'off';
    public const DEV = 'dev';
    public const QA = 'qa';
    public const EXPERT = 'expert';

    public static function current(): string
    {
        $raw = strtolower(trim((string)getenv('COGNITIVE_RUNTIME_QA_MODE')));
        if ($raw === '') {
            $raw = (string)getenv('QA_RUNTIME_GUARD') === '1' ? self::QA : self::OFF;
        }

        return match ($raw) {
            self::DEV, self::QA, self::EXPERT => $raw,
            default => self::OFF,
        };
    }

    public static function enabled(): bool
    {
        return self::current() !== self::OFF;
    }

    public static function isAtLeast(string $level): bool
    {
        $rank = [
            self::OFF => 0,
            self::DEV => 1,
            self::QA => 2,
            self::EXPERT => 3,
        ];
        $current = self::current();

        return ($rank[$current] ?? 0) >= ($rank[$level] ?? 0);
    }

    public static function writeGuardsEnabled(): bool
    {
        $toggle = strtolower(trim((string)getenv('COGNITIVE_RUNTIME_WRITE_GUARDS')));
        if ($toggle !== '') {
            return in_array($toggle, ['1', 'true', 'yes', 'on'], true);
        }

        return self::enabled();
    }
}


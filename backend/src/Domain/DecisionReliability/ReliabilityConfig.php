<?php
namespace Domain\DecisionReliability;

final class ReliabilityConfig {
    public const DEFAULT_DECISION_THRESHOLD = 0.55;

    private function __construct() {}

    public static function normalizeThreshold(mixed $value): float {
        $threshold = is_numeric($value) ? (float)$value : self::DEFAULT_DECISION_THRESHOLD;
        if ($threshold <= 0.0 || $threshold >= 1.0) {
            return self::DEFAULT_DECISION_THRESHOLD;
        }
        return $threshold;
    }

    public static function contextLevelFromScore(float $score): string {
        if ($score < 0.45) return 'weak';
        if ($score <= 0.70) return 'medium';
        return 'strong';
    }

    public static function reliabilityCapForLevel(string $level): float {
        return match ($level) {
            'weak' => 0.45,
            'medium' => 0.70,
            default => 1.0,
        };
    }
}

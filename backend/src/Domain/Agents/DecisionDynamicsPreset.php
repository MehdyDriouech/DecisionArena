<?php
declare(strict_types=1);
namespace Domain\Agents;

/**
 * Global decision-dynamics styles for a session only — does not persist to persona_decision_dynamics.
 * Applied after resolving each agent's stored (admin) dynamics.
 */
final class DecisionDynamicsPreset {
    public const BALANCED = 'balanced';
    public const CONSERVATIVE = 'conservative';
    public const AGGRESSIVE = 'aggressive';
    public const CRITICAL = 'critical';

    /** @var list<string> */
    public const IDS = [self::BALANCED, self::CONSERVATIVE, self::AGGRESSIVE, self::CRITICAL];

    /**
     * @return array<string, array{reputation_delta: float, consensus_resistance: ?string, risk_tolerance: ?string}>
     */
    private static function table(): array {
        return [
            self::BALANCED => [
                'reputation_delta'       => 0.0,
                'consensus_resistance'   => null,
                'risk_tolerance'         => null,
            ],
            self::CONSERVATIVE => [
                'reputation_delta'       => -0.06,
                'consensus_resistance'   => 'strong',
                'risk_tolerance'         => 'cautious',
            ],
            self::AGGRESSIVE => [
                'reputation_delta'       => 0.06,
                'consensus_resistance'   => 'normal',
                'risk_tolerance'         => 'balanced',
            ],
            self::CRITICAL => [
                'reputation_delta'       => 0.0,
                'consensus_resistance'   => 'strong',
                'risk_tolerance'         => 'balanced',
            ],
        ];
    }

    public static function normalizeId(?string $id): string {
        $s = strtolower(trim((string)$id));
        return in_array($s, self::IDS, true) ? $s : self::BALANCED;
    }

    /**
     * @param array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string} $baselineNormalized
     * @return array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string}
     */
    public static function applyToBaseline(array $baselineNormalized, ?string $presetId): array {
        $pid = self::normalizeId($presetId);
        $row = self::table()[$pid];
        $out = $baselineNormalized;

        $out['reputation'] = min(
            DecisionDynamics::REPUTATION_MAX,
            max(
                DecisionDynamics::REPUTATION_MIN,
                (float)$baselineNormalized['reputation'] + (float)$row['reputation_delta']
            )
        );

        if ($row['consensus_resistance'] !== null) {
            $out['consensus_resistance'] = $row['consensus_resistance'];
        }
        if ($row['risk_tolerance'] !== null) {
            $out['risk_tolerance'] = $row['risk_tolerance'];
        }

        return DecisionDynamics::normalize($out);
    }
}

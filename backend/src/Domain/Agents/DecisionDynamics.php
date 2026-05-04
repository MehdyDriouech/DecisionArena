<?php
declare(strict_types=1);
namespace Domain\Agents;

/** Configurable agent decision posture (explicit, user-controlled — no automatic tuning). */
final class DecisionDynamics {
    public const REPUTATION_MIN = 0.8;
    public const REPUTATION_MAX = 1.3;

    public const CONSENSUS_VALUES = ['low', 'normal', 'strong'];
    public const EVIDENCE_VALUES  = ['low', 'normal', 'high'];
    public const RISK_VALUES      = ['cautious', 'balanced', 'bold'];

    /** @return array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string} */
    public static function defaults(): array {
        return [
            'reputation'              => 1.0,
            'consensus_resistance'    => 'normal',
            'evidence_sensitivity'    => 'normal',
            'risk_tolerance'          => 'balanced',
        ];
    }

    /**
     * @param ?array<string,mixed> $raw
     * @return array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string}
     */
    public static function normalize(?array $raw): array {
        $out = self::defaults();
        if (!$raw) {
            return $out;
        }
        $r = $raw['reputation'] ?? null;
        if (is_numeric($r)) {
            $out['reputation'] = min(self::REPUTATION_MAX, max(self::REPUTATION_MIN, (float)$r));
        }
        $cr = isset($raw['consensus_resistance']) ? (string)$raw['consensus_resistance'] : '';
        if (in_array($cr, self::CONSENSUS_VALUES, true)) {
            $out['consensus_resistance'] = $cr;
        }
        $ev = isset($raw['evidence_sensitivity']) ? (string)$raw['evidence_sensitivity'] : '';
        if (in_array($ev, self::EVIDENCE_VALUES, true)) {
            $out['evidence_sensitivity'] = $ev;
        }
        $rt = isset($raw['risk_tolerance']) ? (string)$raw['risk_tolerance'] : '';
        if (in_array($rt, self::RISK_VALUES, true)) {
            $out['risk_tolerance'] = $rt;
        }
        return $out;
    }
}

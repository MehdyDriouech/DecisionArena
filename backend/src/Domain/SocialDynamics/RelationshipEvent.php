<?php
namespace Domain\SocialDynamics;

/**
 * One auditable social interaction derived from agent output.
 *
 * @psalm-type Event array{
 *   session_id:string,
 *   round_index:?int,
 *   source_agent_id:string,
 *   target_agent_id:?string,
 *   event_type:string,
 *   intensity:float,
 *   evidence:?string
 * }
 */
final class RelationshipEvent {
    public const TYPE_SUPPORT = 'support';
    public const TYPE_CHALLENGE = 'challenge';
    public const TYPE_ATTACK = 'attack';
    public const TYPE_ALLIANCE = 'alliance';
    public const TYPE_CONCESSION = 'concession';
    public const TYPE_DISAGREEMENT = 'disagreement';
    public const TYPE_NEUTRAL = 'neutral';

    /** @return list<string> */
    public static function allowedTypes(): array {
        return [
            self::TYPE_SUPPORT,
            self::TYPE_CHALLENGE,
            self::TYPE_ATTACK,
            self::TYPE_ALLIANCE,
            self::TYPE_CONCESSION,
            self::TYPE_DISAGREEMENT,
            self::TYPE_NEUTRAL,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function normalizeType(string $raw): string {
        $t = strtolower(trim($raw));
        return in_array($t, self::allowedTypes(), true) ? $t : self::TYPE_NEUTRAL;
    }
}

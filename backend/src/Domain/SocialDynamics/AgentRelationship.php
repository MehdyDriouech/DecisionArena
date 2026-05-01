<?php
namespace Domain\SocialDynamics;

/**
 * Value snapshot for directed agent-agent relationship metrics.
 *
 * @psalm-type Row array{
 *   id:int,
 *   session_id:string,
 *   source_agent_id:string,
 *   target_agent_id:string,
 *   affinity:float,
 *   trust:float,
 *   conflict:float,
 *   support_count:int,
 *   challenge_count:int,
 *   alliance_count:int,
 *   attack_count:int,
 *   last_interaction_type:?string,
 *   created_at:string,
 *   updated_at:string
 * }
 */
final class AgentRelationship {
    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): array {
        return [
            'source_agent_id'        => (string)($row['source_agent_id'] ?? ''),
            'target_agent_id'        => (string)($row['target_agent_id'] ?? ''),
            'affinity'               => (float)($row['affinity'] ?? 0.0),
            'trust'                  => (float)($row['trust'] ?? 0.5),
            'conflict'               => (float)($row['conflict'] ?? 0.0),
            'support_count'          => (int)($row['support_count'] ?? 0),
            'challenge_count'        => (int)($row['challenge_count'] ?? 0),
            'alliance_count'         => (int)($row['alliance_count'] ?? 0),
            'attack_count'           => (int)($row['attack_count'] ?? 0),
            'last_interaction_type'  => isset($row['last_interaction_type']) ? (string)$row['last_interaction_type'] : null,
        ];
    }
}

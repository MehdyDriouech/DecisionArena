<?php
declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\StrategicContext\AgentContextMemoryService;

/**
 * Ré-synchronise memory.md agents (trace participation) quand une session contextualisée
 * est terminée et que les participants peuvent avoir changé (messages, votes, update session).
 */
final class ParticipantMemorySyncTrigger
{
    public static function onSessionLikelyParticipantChange(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }
        $sessions = new SessionRepository();
        $row = $sessions->findById($sessionId);
        if ($row === null) {
            return;
        }
        if (strtolower(trim((string)($row['status'] ?? ''))) !== 'completed') {
            return;
        }
        $ctx = trim((string)($row['strategic_context_id'] ?? ''));
        if ($ctx === '') {
            return;
        }
        try {
            $label = '';
            $repo = new StrategicContextRepository();
            $c = $repo->find($ctx);
            if (is_array($c)) {
                $label = trim((string)($c['title'] ?? ''));
            }
            (new AgentContextMemoryService())->syncParticipantMemoryOnSessionCompleted(
                $sessionId,
                $row,
                null,
                $label !== '' ? $label : null
            );
        } catch (\Throwable $e) {
            // Ne jamais bloquer la persistance messages / votes / sessions.
        }
    }
}

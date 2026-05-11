<?php
declare(strict_types=1);

namespace Domain\Sessions;

use Http\Response;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Centralise la règle produit : certains modes exigent un Strategic Context résolu
 * (explicite, workspace actif, ou héritage parent au rerun), sauf confirmation legacy explicite.
 */
final class SessionStrategicContextGuard
{
    private const MODES_NEEDING_WORKSPACE = [
        'chat',
        'decision-room',
        'quick-decision',
        'confrontation',
        'stress-test',
        'jury',
    ];

    /** @return list<string> */
    public static function modesNeedingWorkspace(): array
    {
        return self::MODES_NEEDING_WORKSPACE;
    }

    /**
     * Résout le `strategic_context_id` à persister sur la nouvelle session.
     * Ordre : `strategic_context_id` explicite (body) → héritage parent (rerun) → workspace actif → legacy confirmé (null) → erreur.
     *
     * @param array<string,mixed> $body Corps JSON déjà décodé (POST).
     * @return array{strategic_context_id: ?string, block: ?array} block = payload Response::error si la création doit être refusée.
     */
    public static function resolveStrategicContextForCreation(
        string $mode,
        array $body,
        ?string $inheritStrategicContextId = null
    ): array {
        if (!in_array($mode, self::MODES_NEEDING_WORKSPACE, true)) {
            return ['strategic_context_id' => null, 'block' => null];
        }
        $repo = new StrategicContextRepository();
        $legacy = filter_var($body['confirm_legacy_no_active_strategic_context'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $explicit = trim((string)($body['strategic_context_id'] ?? ''));
        if ($explicit !== '') {
            if (!self::isValidContextUuid($explicit)) {
                return [
                    'strategic_context_id' => null,
                    'block' => Response::error('Invalid strategic_context_id', 400),
                ];
            }
            $c = $repo->find($explicit);
            if (!$c) {
                return [
                    'strategic_context_id' => null,
                    'block' => Response::error('Strategic context not found', 404),
                ];
            }
            $st = (string)($c['status'] ?? '');
            if (!in_array($st, ['active', 'paused'], true)) {
                return [
                    'strategic_context_id' => null,
                    'block' => Response::error(
                        'Strategic context cannot be used for new sessions (status must be active or paused).',
                        400
                    ),
                ];
            }
            return ['strategic_context_id' => $explicit, 'block' => null];
        }

        $inherit = $inheritStrategicContextId !== null ? trim($inheritStrategicContextId) : '';
        if ($inherit !== '' && self::isValidContextUuid($inherit)) {
            $c = $repo->find($inherit);
            if ($c) {
                $st = (string)($c['status'] ?? '');
                if (in_array($st, ['active', 'paused'], true)) {
                    return ['strategic_context_id' => $inherit, 'block' => null];
                }
            }
        }

        $active = $repo->getActiveContext();
        if ($active && !empty($active['context_id'])) {
            return ['strategic_context_id' => (string)$active['context_id'], 'block' => null];
        }

        if ($legacy) {
            return ['strategic_context_id' => null, 'block' => null];
        }

        return [
            'strategic_context_id' => null,
            'block' => Response::error(
                'No strategic context for this session. Select or create a strategic context, activate it as workspace, pass strategic_context_id, or confirm legacy mode.',
                400
            ),
        ];
    }

    /**
     * @param array<string,mixed> $body Corps JSON déjà décodé (POST).
     * @return array<string,mixed>|null null si la création est autorisée ; sinon payload d’erreur API.
     */
    public static function assertSessionCreationAllowed(string $mode, array $body): ?array
    {
        return self::resolveStrategicContextForCreation($mode, $body, null)['block'];
    }

    public static function syncStrategicContextSessionLink(?string $strategicContextId, string $sessionId): void
    {
        if ($strategicContextId === null || $strategicContextId === '') {
            return;
        }
        try {
            (new StrategicContextRepository())->linkSession($strategicContextId, $sessionId);
        } catch (\Throwable) {
        }
    }

    /** @deprecated Préférer {@see syncStrategicContextSessionLink} avec l’id résolu par {@see resolveStrategicContextForCreation}. */
    public static function linkCreatedSessionToActiveWorkspace(string $sessionId): void
    {
        try {
            $repo = new StrategicContextRepository();
            $active = $repo->getActiveContext();
            $id = ($active['context_id'] ?? null) !== null && (string)$active['context_id'] !== ''
                ? (string)$active['context_id']
                : null;
            self::syncStrategicContextSessionLink($id, $sessionId);
        } catch (\Throwable) {
        }
    }

    private static function isValidContextUuid(string $id): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        );
    }
}

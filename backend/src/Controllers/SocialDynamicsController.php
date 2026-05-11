<?php
declare(strict_types=1);

namespace Controllers;

use Domain\CognitiveGovernance\CanonicalLayerMutationGuard;
use Domain\SocialDynamics\SocialPromptContextBuilder;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

final class SocialDynamicsController {
    private SessionRepository $sessionRepo;
    private AgentRelationshipRepository $relationshipRepo;
    private StrategicContextRepository $contextRepo;

    public function __construct() {
        $this->sessionRepo      = new SessionRepository();
        $this->relationshipRepo = new AgentRelationshipRepository();
        $this->contextRepo      = new StrategicContextRepository();
    }

    private function isValidContextUuid(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /** @return array{relationships: list<array<string,mixed>>, highlights: array<string,mixed>, meta?: array<string,mixed>} */
    private function formatRelationshipsPayload(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'source_agent_id' => (string)($r['source_agent_id'] ?? ''),
                'target_agent_id' => (string)($r['target_agent_id'] ?? ''),
                'affinity'        => isset($r['affinity']) ? (float)$r['affinity'] : 0.0,
                'trust'           => isset($r['trust']) ? (float)$r['trust'] : 0.5,
                'conflict'        => isset($r['conflict']) ? (float)$r['conflict'] : 0.0,
                'support_count'   => (int)($r['support_count'] ?? 0),
                'challenge_count' => (int)($r['challenge_count'] ?? 0),
                'alliance_count'  => (int)($r['alliance_count'] ?? 0),
                'attack_count'    => (int)($r['attack_count'] ?? 0),
                'strategic_context_id' => $r['strategic_context_id'] ?? null,
            ];
        }
        $highlights = SocialPromptContextBuilder::computeHighlights($rows);
        return ['relationships' => $out, 'highlights' => $highlights];
    }

    /** GET /api/sessions/{id}/relationships — ?include_legacy=1 (expert) inclut les lignes social avec strategic_context_id NULL pour une session déjà rattachée à un contexte. */
    public function relationships(Request $req): array {
        $id = (string)$req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $ctx = $this->relationshipRepo->readSessionStrategicContextId($id);
        $includeLegacy = trim((string)$req->query('include_legacy', '')) === '1';
        $includeLegacyNullRows = ($ctx === null || $ctx === '') || $includeLegacy;
        if ($ctx !== null && $ctx !== '' && $includeLegacy) {
            CanonicalLayerMutationGuard::logViolation('Social legacy opt-in activated via session relationships endpoint', [
                'session_id' => $id,
                'strategic_context_id' => $ctx,
                'endpoint' => '/api/sessions/{id}/relationships',
            ]);
        }
        $rows = $this->relationshipRepo->findBySession($id, $ctx, $includeLegacyNullRows);
        $payload = $this->formatRelationshipsPayload($rows);
        $payload['meta'] = [
            'session_strategic_context_id' => $ctx,
            'include_legacy' => $includeLegacyNullRows,
            'legacy_unscoped_session' => $ctx === null || $ctx === '',
            'filtered_strict_context' => $ctx !== null && $ctx !== '' && !$includeLegacy,
            'warning' => ($ctx === null || $ctx === '')
                ? 'Session sans contexte stratégique : données sociales legacy (non scopées par contexte).'
                : ($includeLegacy ? 'Mode expert activé : inclusion explicite des lignes legacy non scopées.' : null),
            'expert_opt_in_required' => $ctx !== null && $ctx !== '',
            'legacy_opt_in_param' => 'include_legacy=1',
            'legacy_opt_in_active' => $ctx !== null && $ctx !== '' && $includeLegacy,
            'strict_context_default' => $ctx !== null && $ctx !== '',
            'info' => ($ctx !== null && $ctx !== '' && !$includeLegacy)
                ? 'Filtrage strict sur strategic_context_id de la session (lignes NULL exclues). ?include_legacy=1 pour les inclure.'
                : null,
        ];
        return $payload;
    }

    /** GET /api/sessions/{id}/relationship-events — même sémantique `include_legacy` que relationships. */
    public function relationshipEvents(Request $req): array {
        $id = (string)$req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $ctx = $this->relationshipRepo->readSessionStrategicContextId($id);
        $includeLegacy = trim((string)$req->query('include_legacy', '')) === '1';
        $includeLegacyNullRows = ($ctx === null || $ctx === '') || $includeLegacy;
        if ($ctx !== null && $ctx !== '' && $includeLegacy) {
            CanonicalLayerMutationGuard::logViolation('Social legacy opt-in activated via relationship-events endpoint', [
                'session_id' => $id,
                'strategic_context_id' => $ctx,
                'endpoint' => '/api/sessions/{id}/relationship-events',
            ]);
        }
        $events = $this->relationshipRepo->findEventsBySession($id, $ctx, $includeLegacyNullRows);
        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'round_index'       => isset($e['round_index']) ? (int)$e['round_index'] : null,
                'source_agent_id'   => (string)($e['source_agent_id'] ?? ''),
                'target_agent_id'   => $e['target_agent_id'] !== null && $e['target_agent_id'] !== ''
                    ? (string)$e['target_agent_id'] : null,
                'event_type'        => (string)($e['event_type'] ?? ''),
                'intensity'         => isset($e['intensity']) ? (float)$e['intensity'] : 0.5,
                'evidence'          => $e['evidence'] !== null && $e['evidence'] !== '' ? (string)$e['evidence'] : null,
                'strategic_context_id' => $e['strategic_context_id'] ?? null,
            ];
        }
        return [
            'events' => $out,
            'meta' => [
                'session_strategic_context_id' => $ctx,
                'include_legacy' => $includeLegacyNullRows,
                'legacy_unscoped_session' => $ctx === null || $ctx === '',
                'filtered_strict_context' => $ctx !== null && $ctx !== '' && !$includeLegacy,
                'warning' => ($ctx === null || $ctx === '')
                    ? 'Session sans contexte stratégique : événements legacy.'
                    : ($includeLegacy ? 'Mode expert activé : inclusion explicite des événements legacy non scopés.' : null),
                'expert_opt_in_required' => $ctx !== null && $ctx !== '',
                'legacy_opt_in_param' => 'include_legacy=1',
                'legacy_opt_in_active' => $ctx !== null && $ctx !== '' && $includeLegacy,
                'strict_context_default' => $ctx !== null && $ctx !== '',
                'info' => ($ctx !== null && $ctx !== '' && !$includeLegacy)
                    ? 'Filtrage strict (événements strategic_context_id NULL exclus). ?include_legacy=1 pour les inclure.'
                    : null,
            ],
        ];
    }

    /** GET /api/strategic-contexts/{id}/relationships — uniquement lignes dont strategic_context_id = id (pas de fusion inter-contexte). */
    public function relationshipsByContext(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $rows = $this->relationshipRepo->findByStrategicContext($cid);
        $payload = $this->formatRelationshipsPayload($rows);
        $payload['meta'] = [
            'strategic_context_id' => $cid,
            'include_legacy' => false,
            'strict_context_default' => true,
            'note' => 'Agrégat par strategic_context_id uniquement ; les lignes legacy NULL n’apparaissent pas ici.',
        ];
        return $payload;
    }

    /** GET /api/strategic-contexts/{id}/relationship-events */
    public function relationshipEventsByContext(Request $req): array
    {
        $cid = trim((string)$req->param('id'));
        if (!$this->isValidContextUuid($cid)) {
            return Response::error('Invalid context id', 400);
        }
        if (!$this->contextRepo->find($cid)) {
            return Response::error('Context not found', 404);
        }
        $events = $this->relationshipRepo->findEventsByStrategicContext($cid);
        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'round_index'       => isset($e['round_index']) ? (int)$e['round_index'] : null,
                'source_agent_id'   => (string)($e['source_agent_id'] ?? ''),
                'target_agent_id'   => $e['target_agent_id'] !== null && $e['target_agent_id'] !== ''
                    ? (string)$e['target_agent_id'] : null,
                'event_type'        => (string)($e['event_type'] ?? ''),
                'intensity'         => isset($e['intensity']) ? (float)$e['intensity'] : 0.5,
                'evidence'          => $e['evidence'] !== null && $e['evidence'] !== '' ? (string)$e['evidence'] : null,
                'session_id'        => (string)($e['session_id'] ?? ''),
            ];
        }
        return [
            'events' => $out,
            'meta' => [
                'strategic_context_id' => $cid,
                'include_legacy' => false,
                'strict_context_default' => true,
                'note' => 'Événements dont strategic_context_id correspond exactement au contexte.',
            ],
        ];
    }
}

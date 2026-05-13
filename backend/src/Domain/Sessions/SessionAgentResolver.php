<?php
declare(strict_types=1);

namespace Domain\Sessions;

use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\VoteRepository;

/**
 * Détection déterministe des agents ayant participé à une session (traçable).
 *
 * Ordre de collecte des sources (union, puis filtre) : messages → votes → selected_agents → équipes confrontation → métadonnées session.result.
 */
final class SessionAgentResolver
{
    private SessionRepository $sessions;

    private MessageRepository $messages;

    private VoteRepository $votes;

    public function __construct(
        ?SessionRepository $sessions = null,
        ?MessageRepository $messages = null,
        ?VoteRepository $votes = null,
    ) {
        $this->sessions = $sessions ?? new SessionRepository();
        $this->messages = $messages ?? new MessageRepository();
        $this->votes = $votes ?? new VoteRepository();
    }

    /**
     * @param array{include_synthesizer?:bool,include_devil_advocate?:bool} $options
     * @return list<string> agent_id normalisés (lowercase), dédupliqués, triés
     */
    public function resolveParticipants(string $sessionId, array $options = []): array
    {
        $detailed = $this->resolveParticipantsWithSources($sessionId, $options);
        $ids = array_map(static fn (array $r) => (string)($r['agent_id'] ?? ''), $detailed);
        return array_values(array_filter($ids));
    }

    /**
     * @param array{include_synthesizer?:bool,include_devil_advocate?:bool} $options
     * @return list<array{agent_id:string, sources:list<string>}>
     */
    public function resolveParticipantsWithSources(string $sessionId, array $options = []): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return [];
        }
        $includeSynth = ($options['include_synthesizer'] ?? false) === true;
        $includeDa = ($options['include_devil_advocate'] ?? false) === true;

        $session = $this->sessions->findById($sessionId);
        if (!$session) {
            return [];
        }

        /** @var array<string, array{sources: list<string>}> $acc */
        $acc = [];

        $add = function (string $rawId, string $sourceLabel) use (&$acc, $includeSynth, $includeDa): void {
            $id = self::normalizeAgentId($rawId);
            if ($id === null) {
                return;
            }
            if (!$includeSynth && $id === 'synthesizer') {
                return;
            }
            if (!$includeDa && $id === 'devil_advocate') {
                return;
            }
            if (!isset($acc[$id])) {
                $acc[$id] = ['sources' => []];
            }
            if (!in_array($sourceLabel, $acc[$id]['sources'], true)) {
                $acc[$id]['sources'][] = $sourceLabel;
            }
        };

        foreach ($this->messages->findBySession($sessionId) as $m) {
            if (!empty($m['agent_id'])) {
                $add((string)$m['agent_id'], 'messages.agent_id');
            }
        }
        foreach ($this->votes->findVotesBySession($sessionId) as $v) {
            if (!empty($v['agent_id'])) {
                $add((string)$v['agent_id'], 'session_votes.agent_id');
            }
        }
        foreach ($session['selected_agents'] ?? [] as $a) {
            $add((string)$a, 'sessions.selected_agents');
        }
        foreach (['blue_team_agents', 'red_team_agents'] as $k) {
            $teams = $session[$k] ?? null;
            if (is_string($teams) && $teams !== '') {
                $decoded = json_decode($teams, true);
                $teams = is_array($decoded) ? $decoded : null;
            }
            if (is_array($teams)) {
                foreach ($teams as $a) {
                    $add((string)$a, 'sessions.' . $k);
                }
            }
        }

        $result = null;
        if (!empty($session['result']) && is_string($session['result'])) {
            $decoded = json_decode($session['result'], true);
            $result = is_array($decoded) ? $decoded : null;
        }
        if (is_array($result)) {
            $this->collectAgentsFromResultPayload($result, $add);
        }

        $out = [];
        foreach ($acc as $id => $meta) {
            $out[] = [
                'agent_id' => $id,
                'sources' => array_values(array_unique($meta['sources'])),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string)($a['agent_id'] ?? ''), (string)($b['agent_id'] ?? '')));

        return $out;
    }

    /**
     * Filtre les ids issus de {@see resolveParticipants} : ne garde que les agents au roster
     * (selected_agents + équipes confrontation) ou ayant émis un vote pour la session.
     * Retourne la liste d'entrée inchangée si aucun signal roster/vote n'est disponible
     * (sessions legacy sans sélection enregistrée).
     *
     * @param list<string> $resolvedAgentIds
     *
     * @return list<string>
     */
    public function filterParticipantsForMemorySync(string $sessionId, array $session, array $resolvedAgentIds): array
    {
        $allow = $this->participationAllowlist($sessionId, $session);
        if ($allow === null) {
            $out = [];
            foreach ($resolvedAgentIds as $raw) {
                $id = self::normalizeAgentId((string)$raw);
                if ($id !== null) {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        }
        $out = [];
        foreach ($resolvedAgentIds as $raw) {
            $id = self::normalizeAgentId((string)$raw);
            if ($id !== null && isset($allow[$id])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<array{agent_id:string,sources:list<string>}> $detailed
     *
     * @return list<array{agent_id:string,sources:list<string>}>
     */
    public function filterDetailedParticipantsForMemorySync(string $sessionId, array $session, array $detailed): array
    {
        $allow = $this->participationAllowlist($sessionId, $session);
        if ($allow === null) {
            return $detailed;
        }
        $out = [];
        foreach ($detailed as $row) {
            $id = self::normalizeAgentId((string)($row['agent_id'] ?? ''));
            if ($id !== null && isset($allow[$id])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Ensemble des agents « au roster ou votants » pour filtrer les ids message-only hors roster.
     *
     * @return array<string,true>|null null = ne pas appliquer de filtre
     */
    private function participationAllowlist(string $sessionId, array $session): ?array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return null;
        }

        $allow = [];
        $hadRosterDefinition = false;

        if (array_key_exists('selected_agents', $session)) {
            $hadRosterDefinition = true;
            $sel = $session['selected_agents'] ?? [];
            if (is_array($sel)) {
                foreach ($sel as $a) {
                    $id = self::normalizeAgentId((string)$a);
                    if ($id !== null) {
                        $allow[$id] = true;
                    }
                }
            }
        }

        foreach (['blue_team_agents', 'red_team_agents'] as $k) {
            $teams = $session[$k] ?? null;
            if (is_string($teams) && $teams !== '') {
                $decoded = json_decode($teams, true);
                $teams = is_array($decoded) ? $decoded : null;
            }
            if (is_array($teams) && $teams !== []) {
                $hadRosterDefinition = true;
            }
            if (is_array($teams)) {
                foreach ($teams as $a) {
                    $id = self::normalizeAgentId((string)$a);
                    if ($id !== null) {
                        $allow[$id] = true;
                    }
                }
            }
        }

        $hadVotes = false;
        foreach ($this->votes->findVotesBySession($sessionId) as $v) {
            if (empty($v['agent_id'])) {
                continue;
            }
            $hadVotes = true;
            $id = self::normalizeAgentId((string)$v['agent_id']);
            if ($id !== null) {
                $allow[$id] = true;
            }
        }

        if (!$hadRosterDefinition && !$hadVotes) {
            return null;
        }

        return $allow;
    }

    private static function normalizeAgentId(string $raw): ?string
    {
        $id = strtolower(trim($raw));
        if ($id === '' || $id === 'user' || $id === 'system') {
            return null;
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $result
     * @param callable(string,string):void $add
     */
    private function collectAgentsFromResultPayload(array $result, callable $add): void
    {
        $paths = [
            ['participant_agents'],
            ['participating_agents'],
            ['participants'],
            ['decision_outcome', 'participant_agents'],
            ['decision_outcome', 'participating_agents'],
            ['canonical_synthesis', 'participant_agents'],
        ];
        foreach ($paths as $path) {
            $cur = $result;
            foreach ($path as $seg) {
                if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                    $cur = null;
                    break;
                }
                $cur = $cur[$seg];
            }
            if (!is_array($cur)) {
                continue;
            }
            foreach ($cur as $item) {
                if (is_string($item)) {
                    $add($item, 'session.result.' . implode('.', $path));
                } elseif (is_array($item) && !empty($item['agent_id'])) {
                    $add((string)$item['agent_id'], 'session.result.' . implode('.', $path));
                }
            }
        }
    }
}

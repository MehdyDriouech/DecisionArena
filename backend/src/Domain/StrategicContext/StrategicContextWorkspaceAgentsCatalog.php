<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\Sessions\SessionAgentResolver;
use Infrastructure\Persistence\PersonaRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextAgentsRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Agents du panneau principal : participants aux sessions du contexte ∪ fichiers memory.md existants.
 * Les personas globales sans participation sont exposées séparément (Expert) via buildExpertPersonaFallbackForContext().
 *
 * @return list<array{
 *   agent_id:string,
 *   agent_name:string,
 *   display_name:string,
 *   participated:bool,
 *   memory_md_exists:bool,
 *   persona_fallback:bool,
 *   participation_memory_synced:bool,
 *   decision_memory_synced:bool,
 *   memory_md_empty_or_template_only:bool,
 *   needs_memory_sync:bool,
 *   source_flags:list<string>,
 *   badges:list<string>,
 *   explicit_openspace_context?:bool
 * }>
 */
final class StrategicContextWorkspaceAgentsCatalog
{
    private StrategicContextRepository $contexts;

    private SessionRepository $sessions;

    private SessionAgentResolver $resolver;

    private PersonaRepository $personas;

    private AgentContextMemoryService $agentMem;

    private StrategicContextAgentsRepository $contextAgents;

    public function __construct(
        ?StrategicContextRepository $contexts = null,
        ?SessionRepository $sessions = null,
        ?SessionAgentResolver $resolver = null,
        ?PersonaRepository $personas = null,
        ?AgentContextMemoryService $agentMem = null,
        ?StrategicContextAgentsRepository $contextAgents = null,
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->resolver = $resolver ?? new SessionAgentResolver();
        $this->personas = $personas ?? new PersonaRepository();
        $this->agentMem = $agentMem ?? new AgentContextMemoryService();
        $this->contextAgents = $contextAgents ?? new StrategicContextAgentsRepository();
    }

    /**
     * Catalogue principal (sélecteur « Mémoire agents du contexte ») : participants ∪ memory.md uniquement.
     */
    public function buildForContext(string $contextId): array
    {
        $contextId = trim($contextId);
        if ($contextId === '' || !$this->agentMem->isValidContextUuid($contextId)) {
            return [];
        }

        $pack = $this->collectWorkspaceParticipantAndMemoryKeys($contextId);
        $participants = $pack['participants'];
        $memoryAgentSet = $pack['memoryAgentSet'];
        $linkedMemIds = $pack['linkedMemIds'];

        $union = $participants;
        foreach (array_keys($memoryAgentSet) as $aid) {
            $union[$aid] = true;
        }
        $allIds = array_keys($union);

        usort($allIds, function (string $a, string $b) use ($participants, $memoryAgentSet): int {
            $pa = isset($participants[$a]);
            $pb = isset($participants[$b]);
            if ($pa !== $pb) {
                return $pa ? -1 : 1;
            }
            $ma = isset($memoryAgentSet[$a]);
            $mb = isset($memoryAgentSet[$b]);
            if ($ma !== $mb) {
                return $ma ? -1 : 1;
            }
            return strcmp($a, $b);
        });

        $personaById = $this->loadPersonaNamesById();

        $rows = [];
        foreach ($allIds as $aid) {
            $rows[] = $this->buildRow($contextId, $aid, $participants, $linkedMemIds, $personaById, false, false);
        }

        return $rows;
    }

    /**
     * Agents éligibles au sélecteur OpenSpace Agent Chat (participants, mémoire, sync, ou ajout manuel explicite).
     *
     * @return list<array<string,mixed>>
     */
    public function buildForOpenSpaceAgentChat(string $contextId): array
    {
        $contextId = trim($contextId);
        if ($contextId === '' || !$this->agentMem->isValidContextUuid($contextId)) {
            return [];
        }

        $pack = $this->collectWorkspaceParticipantAndMemoryKeys($contextId);
        $participants = $pack['participants'];
        $memoryAgentSet = $pack['memoryAgentSet'];
        $linkedMemIds = $pack['linkedMemIds'];

        $union = $participants;
        foreach (array_keys($memoryAgentSet) as $aid) {
            $union[$aid] = true;
        }
        $manualIds = $this->contextAgents->listAgentIds($contextId);
        $manualSet = array_fill_keys($manualIds, true);
        foreach ($manualIds as $aid) {
            $union[$aid] = true;
        }

        $allIds = array_keys($union);

        usort($allIds, function (string $a, string $b) use ($participants, $memoryAgentSet): int {
            $pa = isset($participants[$a]);
            $pb = isset($participants[$b]);
            if ($pa !== $pb) {
                return $pa ? -1 : 1;
            }
            $ma = isset($memoryAgentSet[$a]);
            $mb = isset($memoryAgentSet[$b]);
            if ($ma !== $mb) {
                return $ma ? -1 : 1;
            }
            return strcmp($a, $b);
        });

        $personaById = $this->loadPersonaNamesById();

        $rows = [];
        foreach ($allIds as $aid) {
            $explicit = isset($manualSet[$aid]);
            $rows[] = $this->buildRow($contextId, $aid, $participants, $linkedMemIds, $personaById, false, $explicit);
        }

        $out = [];
        foreach ($rows as $r) {
            if ($this->isOpenSpaceAgentChatEligible($r)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    public function isAgentEligibleForOpenSpaceChat(string $contextId, string $agentId): bool
    {
        $agentId = strtolower(trim($agentId));
        if ($agentId === '') {
            return false;
        }
        foreach ($this->buildForOpenSpaceAgentChat($contextId) as $row) {
            if (strtolower(trim((string)($row['agent_id'] ?? ''))) === $agentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{linkedMemIds:list<string>, participants:array<string,true>, memoryAgentSet:array<string,true>}
     */
    private function collectWorkspaceParticipantAndMemoryKeys(string $contextId): array
    {
        $linkedMemIds = $this->contexts->linkedMemoryIds($contextId);

        $sessionIds = array_values(array_unique(array_filter(array_merge(
            $this->contexts->linkedSessionIds($contextId),
            array_map(static fn ($r) => (string)($r['id'] ?? ''), $this->sessions->findAll($contextId)),
        ))));

        $participants = [];
        foreach ($sessionIds as $sid) {
            $sess = $this->sessions->findById($sid);
            if ($sess === null) {
                continue;
            }
            $resolved = $this->resolver->resolveParticipants($sid);
            foreach ($this->resolver->filterParticipantsForMemorySync($sid, $sess, $resolved) as $aid) {
                $participants[$aid] = true;
            }
        }

        $memoryDirAgents = $this->agentMem->listAgentIdsWithExistingMemoryFile($contextId);
        $memoryAgentSet = array_fill_keys($memoryDirAgents, true);

        return [
            'linkedMemIds' => $linkedMemIds,
            'participants' => $participants,
            'memoryAgentSet' => $memoryAgentSet,
        ];
    }

    /**
     * @param array<string,mixed> $r
     */
    private function isOpenSpaceAgentChatEligible(array $r): bool
    {
        if (!empty($r['explicit_openspace_context'])) {
            return true;
        }

        return !empty($r['participated'])
            || !empty($r['memory_md_exists'])
            || !empty($r['participation_memory_synced'])
            || !empty($r['decision_memory_synced']);
    }

    /**
     * Personas activées absentes du catalogue principal (bloc Expert « Autres personas disponibles »).
     *
     * @return list<array<string,mixed>>
     */
    public function buildExpertPersonaFallbackForContext(string $contextId): array
    {
        $contextId = trim($contextId);
        if ($contextId === '' || !$this->agentMem->isValidContextUuid($contextId)) {
            return [];
        }
        $core = $this->buildForContext($contextId);
        $coreSet = [];
        foreach ($core as $r) {
            $id = strtolower(trim((string)($r['agent_id'] ?? '')));
            if ($id !== '') {
                $coreSet[$id] = true;
            }
        }
        $personaById = $this->loadPersonaNamesById();
        $linkedMemIds = $this->contexts->linkedMemoryIds($contextId);
        $sessionIds = array_values(array_unique(array_filter(array_merge(
            $this->contexts->linkedSessionIds($contextId),
            array_map(static fn ($r) => (string)($r['id'] ?? ''), $this->sessions->findAll($contextId)),
        ))));
        $participants = [];
        foreach ($sessionIds as $sid) {
            $sess = $this->sessions->findById($sid);
            if ($sess === null) {
                continue;
            }
            $resolved = $this->resolver->resolveParticipants($sid);
            foreach ($this->resolver->filterParticipantsForMemorySync($sid, $sess, $resolved) as $aid) {
                $participants[$aid] = true;
            }
        }

        $out = [];
        foreach (array_keys($personaById) as $aid) {
            if (isset($coreSet[$aid])) {
                continue;
            }
            $out[] = $this->buildRow($contextId, $aid, $participants, $linkedMemIds, $personaById, true, false);
        }
        usort($out, static function (array $x, array $y): int {
            return strcmp((string)($x['agent_id'] ?? ''), (string)($y['agent_id'] ?? ''));
        });

        return $out;
    }

    /**
     * @return array<string,string> id lowercase => display name
     */
    private function loadPersonaNamesById(): array
    {
        $personaById = [];
        foreach ($this->personas->findAll() as $p) {
            $pid = strtolower(trim((string)($p['id'] ?? '')));
            if ($pid === '') {
                continue;
            }
            if (isset($p['enabled']) && $p['enabled'] === false) {
                continue;
            }
            $personaById[$pid] = trim((string)($p['name'] ?? $p['title'] ?? $pid));
        }

        return $personaById;
    }

    /**
     * Fichier réduit aux titres / commentaires : aucune ligne « substantielle » (hors #, <!-- -->).
     */
    private function isMemoryMdEmptyOrTemplateOnly(string $content): bool
    {
        $content = str_replace("\r\n", "\n", $content);
        foreach (explode("\n", $content) as $ln) {
            $t = trim($ln);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^#+\s/', $t)) {
                continue;
            }
            if (preg_match('/^<!--.*-->$/', $t)) {
                continue;
            }
            if (preg_match('/^<!--/', $t)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<string,true> $participants
     * @param list<string> $linkedMemIds
     * @param array<string,string> $personaById
     *
     * @return array<string,mixed>
     */
    private function buildRow(
        string $contextId,
        string $aid,
        array $participants,
        array $linkedMemIds,
        array $personaById,
        bool $forcePersonaFallbackRow,
        bool $explicitOpenSpaceContext = false
    ): array {
        $ex = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid);
        $memExists = $ex['exists'] === true;
        $content = $memExists ? (string)($ex['content'] ?? '') : '';
        $part = isset($participants[$aid]);
        $isPersona = isset($personaById[$aid]);
        $name = $personaById[$aid] ?? $aid;
        $displayName = $name;

        $participationSynced = $content !== '' && str_contains($content, 'participant_context_sync:');

        $shellOnly = $memExists && $this->isMemoryMdEmptyOrTemplateOnly($content);
        $needsMemorySync = $part && (!$memExists || !$participationSynced);

        $syncedFromDm = false;
        if ($content !== '' && $linkedMemIds !== []) {
            foreach ($linkedMemIds as $mid) {
                $mid = trim((string)$mid);
                if ($mid === '') {
                    continue;
                }
                if (str_contains($content, 'da-decision-memory-sync:' . $mid)
                    || str_contains($content, 'da-propagated-decision:' . $mid)) {
                    $syncedFromDm = true;
                    break;
                }
            }
        }

        $badges = [];
        $sourceFlags = [];
        if ($part) {
            $badges[] = 'participated';
            $sourceFlags[] = 'session_participant';
        }
        if ($memExists) {
            $badges[] = 'memory_md_exists';
            $sourceFlags[] = 'agent_memory_md';
        }
        if ($part && $memExists && !$participationSynced) {
            $badges[] = 'participant_memory_shell_or_unsynced';
        }
        if ($needsMemorySync) {
            $badges[] = 'needs_memory_sync';
            $sourceFlags[] = 'needs_memory_sync';
        }
        if ($shellOnly) {
            $badges[] = 'memory_md_template_only';
        }
        if ($part && !$memExists) {
            $badges[] = 'participant_memory_needs_repair';
            $badges[] = 'no_context_memory_file';
        } elseif (!$memExists) {
            $badges[] = 'no_context_memory_file';
        }
        if ($participationSynced) {
            $badges[] = 'participation_context_sync';
            $sourceFlags[] = 'participant_context_sync';
        }
        if ($part && $memExists && !$syncedFromDm && $linkedMemIds !== []) {
            $badges[] = 'no_confirmed_decision_memory';
        }
        if ($syncedFromDm) {
            $badges[] = 'agent_memory_updated';
            $sourceFlags[] = 'decision_memory_auto_sync';
        }
        if ($explicitOpenSpaceContext) {
            $badges[] = 'openspace_manual_context_agent';
            $sourceFlags[] = 'openspace_manual_context_agent';
        }
        $personaFallback = $forcePersonaFallbackRow
            || ($isPersona && !$part && !$memExists);
        if ($personaFallback) {
            $badges[] = 'persona_fallback_no_memory';
        }
        if (!$part && ($forcePersonaFallbackRow || !$memExists)) {
            $badges[] = 'not_participant';
        }

        return [
            'agent_id' => $aid,
            'agent_name' => $name,
            'display_name' => $displayName,
            'participated' => $part,
            'memory_md_exists' => $memExists,
            'persona_fallback' => $personaFallback,
            'participation_memory_synced' => $participationSynced,
            'decision_memory_synced' => $syncedFromDm,
            'memory_md_empty_or_template_only' => $shellOnly,
            'needs_memory_sync' => $needsMemorySync,
            'source_flags' => array_values(array_unique($sourceFlags)),
            'badges' => array_values(array_unique($badges)),
            'explicit_openspace_context' => $explicitOpenSpaceContext,
        ];
    }

    /**
     * Union d’ids agents pour un diff mémoire **lecture seule** entre deux contextes.
     *
     * @return array{agent_ids:list<string>, truncated:bool, cap:int}
     */
    public function unionAgentIdsForCrossContextMemoryDiff(
        string $leftContextId,
        string $rightContextId,
        int $maxAgents = 200
    ): array {
        $maxAgents = max(1, min(2000, $maxAgents));
        $set = [];
        foreach ([$leftContextId, $rightContextId] as $cid) {
            $cid = trim($cid);
            if ($cid === '' || !$this->agentMem->isValidContextUuid($cid)) {
                continue;
            }
            foreach ($this->buildForContext($cid) as $row) {
                $part = !empty($row['participated']);
                $mem = !empty($row['memory_md_exists']);
                if (!$part && !$mem) {
                    continue;
                }
                $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
                if ($aid !== '') {
                    $set[$aid] = true;
                }
            }
        }
        $ids = array_keys($set);
        sort($ids, SORT_STRING);
        $truncated = count($ids) > $maxAgents;
        if ($truncated) {
            $ids = array_slice($ids, 0, $maxAgents);
        }

        return ['agent_ids' => $ids, 'truncated' => $truncated, 'cap' => $maxAgents];
    }
}

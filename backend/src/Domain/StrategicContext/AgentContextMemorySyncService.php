<?php

declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\DecisionMemory\DecisionMemoryAgentMemoryAutoSyncService;
use Domain\Sessions\SessionAgentResolver;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Synchronisation forcée idempotente des memory.md agents d’un Strategic Context
 * (sessions completed + Decision Memories liées). Aucun LLM, beliefs, narrative, compiler, snapshots.
 */
final class AgentContextMemorySyncService
{
    private StrategicContextRepository $contexts;

    private SessionRepository $sessions;

    private DecisionMemoryRepository $memories;

    private AgentContextMemoryService $agentMem;

    public function __construct(
        ?StrategicContextRepository $contexts = null,
        ?SessionRepository $sessions = null,
        ?DecisionMemoryRepository $memories = null,
        ?AgentContextMemoryService $agentMem = null
    ) {
        $this->contexts = $contexts ?? new StrategicContextRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->memories = $memories ?? new DecisionMemoryRepository();
        $this->agentMem = $agentMem ?? new AgentContextMemoryService();
    }

    /**
     * @param array{
     *   include_participation?:bool,
     *   include_decision_memories?:bool,
     *   dry_run?:bool,
     *   include_synthesizer?:bool,
     *   include_devil_advocate?:bool
     * } $options
     *
     * @return array<string,mixed>
     */
    public function syncContextAgentMemories(string $contextId, array $options = []): array
    {
        $contextId = trim($contextId);
        $dryRun = ($options['dry_run'] ?? false) === true;
        $incPart = ($options['include_participation'] ?? true) !== false;
        $incDm = ($options['include_decision_memories'] ?? true) !== false;
        $resolverOpts = [
            'include_synthesizer' => ($options['include_synthesizer'] ?? false) === true,
            'include_devil_advocate' => ($options['include_devil_advocate'] ?? false) === true,
        ];

        if (!$this->agentMem->isValidContextUuid($contextId)) {
            return [
                'ok' => false,
                'error' => 'invalid_context_id',
                'context_id' => $contextId,
                'dry_run' => $dryRun,
            ];
        }

        $ctxRow = $this->contexts->find($contextId);
        if ($ctxRow === null) {
            return [
                'ok' => false,
                'error' => 'context_not_found',
                'context_id' => $contextId,
                'dry_run' => $dryRun,
            ];
        }

        $label = trim((string)($ctxRow['title'] ?? ''));
        $label = $label !== '' ? $label : $contextId;

        $agents = [];
        $globalWarnings = [];
        $skipped = [];

        $touchAgent = static function (array &$agents, string $aid): void {
            $aid = strtolower(trim($aid));
            if ($aid === '') {
                return;
            }
            if (!isset($agents[$aid])) {
                $agents[$aid] = [
                    'memory_file_created' => false,
                    'participation_notes_added' => 0,
                    'decision_memories_added' => 0,
                    'skipped_duplicates' => 0,
                    'warnings' => [],
                ];
            }
        };

        $sessionIds = $this->collectCompletedLinkedSessionIds($contextId);
        $memoryRows = $incDm ? $this->memories->findLinkedMemoriesForAgentMemorySync($contextId, 500) : [];

        $agentUnion = [];
        $resolver = new SessionAgentResolver();
        foreach ($sessionIds as $sid) {
            $sessRow = $this->sessions->findById($sid);
            if ($sessRow === null) {
                continue;
            }
            $resolved = $resolver->resolveParticipants($sid, $resolverOpts);
            foreach ($resolver->filterParticipantsForMemorySync($sid, $sessRow, $resolved) as $a) {
                $x = strtolower(trim($a));
                if ($x !== '') {
                    $agentUnion[$x] = true;
                }
            }
        }
        foreach ($memoryRows as $mr) {
            $sidM = strtolower(trim((string)($mr['session_id'] ?? '')));
            if ($sidM === '') {
                continue;
            }
            $sessM = $this->sessions->findById($sidM);
            if ($sessM === null) {
                continue;
            }
            foreach ($resolver->filterParticipantsForMemorySync($sidM, $sessM, $resolver->resolveParticipants($sidM, $resolverOpts)) as $a) {
                $x = strtolower(trim($a));
                if ($x !== '') {
                    $agentUnion[$x] = true;
                }
            }
        }

        $beforeFileExists = [];
        foreach (array_keys($agentUnion) as $aid) {
            if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                continue;
            }
            $beforeFileExists[$aid] = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid)['exists'];
        }

        $dmSvc = new DecisionMemoryAgentMemoryAutoSyncService($this->memories, $this->sessions);

        if ($incPart) {
            foreach ($sessionIds as $sid) {
                $session = $this->sessions->findById($sid);
                if ($session === null) {
                    $globalWarnings[] = 'session_not_found:' . $sid;
                    $skipped[] = ['session_id' => $sid, 'reason' => 'session_not_found'];

                    continue;
                }
                if ($dryRun) {
                    foreach ($this->previewParticipation($contextId, $sid, $session, $resolverOpts) as $ag) {
                        $aid = strtolower(trim((string)($ag['agent_id'] ?? '')));
                        if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                            continue;
                        }
                        $touchAgent($agents, $aid);
                        if (!empty($ag['skipped_duplicate'])) {
                            $agents[$aid]['skipped_duplicates']++;
                        } elseif (!empty($ag['would_change'])) {
                            $agents[$aid]['participation_notes_added']++;
                            if (!empty($ag['would_create_file'])) {
                                $agents[$aid]['memory_file_created'] = true;
                            }
                        }
                    }

                    continue;
                }
                $res = $this->agentMem->ensureParticipantMemoryForContext(
                    $sid,
                    $session,
                    $contextId,
                    $label,
                    $resolverOpts
                );
                if (($res['ok'] ?? false) !== true) {
                    continue;
                }
                foreach ($res['agents'] ?? [] as $ag) {
                    $aid = strtolower(trim((string)($ag['agent_id'] ?? '')));
                    if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                        continue;
                    }
                    $touchAgent($agents, $aid);
                    if (!empty($ag['skipped_duplicate'])) {
                        $agents[$aid]['skipped_duplicates']++;
                    } elseif (!empty($ag['changed'])) {
                        $agents[$aid]['participation_notes_added']++;
                    }
                }
            }
        }

        if ($incDm) {
            foreach ($memoryRows as $memory) {
                $memoryId = trim((string)($memory['memory_id'] ?? ''));
                $sidM = trim((string)($memory['session_id'] ?? ''));
                if ($memoryId === '') {
                    continue;
                }
                $session = $sidM !== '' ? $this->sessions->findById($sidM) : null;
                if ($session === null) {
                    $globalWarnings[] = 'decision_memory_session_missing:' . $memoryId;
                    $skipped[] = ['memory_id' => $memoryId, 'reason' => 'session_not_found'];

                    continue;
                }
                $merged = $session;
                $merged['strategic_context_id'] = $contextId;

                if ($dryRun) {
                    foreach ($this->previewDecisionMemory($contextId, $memory, $merged, $resolverOpts) as $row) {
                        $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
                        if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                            continue;
                        }
                        $touchAgent($agents, $aid);
                        if (!empty($row['skipped_duplicate'])) {
                            $agents[$aid]['skipped_duplicates']++;
                        } elseif (!empty($row['would_change'])) {
                            $agents[$aid]['decision_memories_added']++;
                            if (!empty($row['would_create_file'])) {
                                $agents[$aid]['memory_file_created'] = true;
                            }
                        }
                    }

                    continue;
                }
                $rep = $dmSvc->syncAfterPersist($memory, $merged, $resolverOpts);
                foreach ($rep['skipped'] ?? [] as $sk) {
                    $aid = strtolower(trim((string)($sk['agent_id'] ?? '')));
                    if ($aid === '') {
                        continue;
                    }
                    $touchAgent($agents, $aid);
                    if (($sk['reason'] ?? '') === 'duplicate_memory_id') {
                        $agents[$aid]['skipped_duplicates']++;
                    }
                }
                foreach ($rep['updated'] ?? [] as $up) {
                    $aid = strtolower(trim((string)($up['agent_id'] ?? '')));
                    if ($aid === '') {
                        continue;
                    }
                    $touchAgent($agents, $aid);
                    if (!empty($up['changed'])) {
                        $agents[$aid]['decision_memories_added']++;
                    }
                }
                foreach ($rep['warnings'] ?? [] as $w) {
                    if (is_string($w) && $w !== '') {
                        $globalWarnings[] = 'dm_sync:' . $memoryId . ':' . $w;
                    }
                }
            }
        }

        if (!$dryRun) {
            foreach (array_keys($agents) as $aid) {
                $ex = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid);
                $agents[$aid]['memory_file_created'] = !($beforeFileExists[$aid] ?? false) && ($ex['exists'] ?? false);
            }
        }

        $filesCreated = 0;
        $filesUpdated = 0;
        $dupSkipped = 0;
        foreach ($agents as $aid => $row) {
            $dupSkipped += (int)($row['skipped_duplicates'] ?? 0);
            if (!empty($row['memory_file_created'])) {
                $filesCreated++;
            }
            $p = (int)($row['participation_notes_added'] ?? 0);
            $d = (int)($row['decision_memories_added'] ?? 0);
            if (($beforeFileExists[$aid] ?? false) && ($p > 0 || $d > 0)) {
                $filesUpdated++;
            }
        }

        return [
            'ok' => true,
            'context_id' => $contextId,
            'dry_run' => $dryRun,
            'agents' => $agents,
            'summary' => [
                'sessions_scanned' => count($sessionIds),
                'decision_memories_scanned' => count($memoryRows),
                'agents_touched' => count($agents),
                'files_created' => $filesCreated,
                'files_updated' => $filesUpdated,
                'duplicates_skipped' => $dupSkipped,
                'warnings_count' => count($globalWarnings),
            ],
            'warnings' => $globalWarnings,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function previewParticipation(string $contextId, string $sessionId, array $session, array $resolverOpts): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !$this->agentMem->isValidContextUuid($contextId)) {
            return [];
        }
        if (strtolower(trim((string)($session['status'] ?? ''))) !== 'completed') {
            return [];
        }
        $sidNorm = strtolower($sessionId);
        $marker = '<!-- participant_context_sync:' . $sidNorm . ' -->';
        $resolver = new SessionAgentResolver();
        $agentIds = $resolver->filterParticipantsForMemorySync(
            $sessionId,
            $session,
            $resolver->resolveParticipants($sessionId, $resolverOpts)
        );
        $out = [];
        foreach ($agentIds as $aidRaw) {
            $aid = strtolower(trim((string)$aidRaw));
            if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                continue;
            }
            $ex = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid);
            $content = (string)($ex['content'] ?? '');
            $has = str_contains($content, $marker);
            $out[] = [
                'agent_id' => $aid,
                'skipped_duplicate' => $has,
                'would_change' => !$has,
                'would_create_file' => !$ex['exists'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $memory
     * @param array<string,mixed> $session merged avec strategic_context_id = contexte
     *
     * @return list<array<string,mixed>>
     */
    private function previewDecisionMemory(string $contextId, array $memory, array $session, array $resolverOpts): array
    {
        $memoryId = trim((string)($memory['memory_id'] ?? ''));
        $sessionId = trim((string)($memory['session_id'] ?? ($session['id'] ?? '')));
        if ($memoryId === '' || $sessionId === '' || !$this->memories->isMemoryLinkedToStrategicContext($memoryId, $contextId)) {
            return [];
        }
        $resolver = new SessionAgentResolver();
        $detailed = $resolver->filterDetailedParticipantsForMemorySync(
            $sessionId,
            $session,
            $resolver->resolveParticipantsWithSources($sessionId, $resolverOpts)
        );
        $t1 = '<!-- da-decision-memory-sync:' . $memoryId . ' -->';
        $t2 = '<!-- da-propagated-decision:' . $memoryId . ' -->';
        $out = [];
        foreach ($detailed as $row) {
            $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
            if ($aid === '' || !$this->agentMem->isValidAgentId($aid)) {
                continue;
            }
            $ex = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid);
            $content = (string)($ex['content'] ?? '');
            $has = str_contains($content, $t1) || str_contains($content, $t2);
            $out[] = [
                'agent_id' => $aid,
                'skipped_duplicate' => $has,
                'would_change' => !$has,
                'would_create_file' => !$ex['exists'],
            ];
        }

        return $out;
    }

    /**
     * @return list<string> session ids (lowercase) completed et liées au contexte
     */
    private function collectCompletedLinkedSessionIds(string $contextId): array
    {
        $out = [];

        foreach ($this->sessions->findAll($contextId) as $r) {
            $sid = strtolower(trim((string)($r['id'] ?? '')));
            if ($sid === '') {
                continue;
            }
            if (strtolower(trim((string)($r['status'] ?? ''))) !== 'completed') {
                continue;
            }
            $out[$sid] = true;
        }

        foreach ($this->contexts->linkedSessionIds($contextId) as $sidRaw) {
            $sid = strtolower(trim((string)$sidRaw));
            if ($sid === '') {
                continue;
            }
            $row = $this->sessions->findById($sid);
            if ($row === null) {
                continue;
            }
            if (strtolower(trim((string)($row['status'] ?? ''))) !== 'completed') {
                continue;
            }
            $out[$sid] = true;
        }

        return array_keys($out);
    }
}

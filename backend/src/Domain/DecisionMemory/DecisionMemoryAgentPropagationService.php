<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

use Domain\Sessions\SessionAgentResolver;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\PersonaRepository;
use Infrastructure\Persistence\SessionRepository;

/**
 * Prévisualisation et propagation explicite Decision Memory → memory.md agents (aucun runner).
 */
final class DecisionMemoryAgentPropagationService
{
    private DecisionMemoryRepository $memories;

    private SessionRepository $sessions;

    private SessionAgentResolver $resolver;

    private AgentContextMemoryService $agentMem;

    private PersonaRepository $personas;

    public function __construct(
        ?DecisionMemoryRepository $memories = null,
        ?SessionRepository $sessions = null,
        ?SessionAgentResolver $resolver = null,
        ?AgentContextMemoryService $agentMem = null,
        ?PersonaRepository $personas = null,
    ) {
        $this->memories = $memories ?? new DecisionMemoryRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->resolver = $resolver ?? new SessionAgentResolver();
        $this->agentMem = $agentMem ?? new AgentContextMemoryService();
        $this->personas = $personas ?? new PersonaRepository();
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(string $sessionId, string $memoryId, array $options = []): array
    {
        $sessionId = trim($sessionId);
        $memoryId = trim($memoryId);
        $includeSynth = ($options['include_synthesizer'] ?? false) === true;
        $includeDa = ($options['include_devil_advocate'] ?? false) === true;

        $session = $this->sessions->findById($sessionId);
        if (!$session) {
            return ['error' => 'session_not_found', 'http' => 404];
        }
        $mem = $this->memories->findById($memoryId);
        if (!$mem) {
            return ['error' => 'memory_not_found', 'http' => 404];
        }
        if ((string)($mem['session_id'] ?? '') !== $sessionId) {
            return ['error' => 'memory_session_mismatch', 'http' => 400];
        }

        $contextId = trim((string)($session['strategic_context_id'] ?? ''));
        if ($contextId === '') {
            return [
                'session_id' => $sessionId,
                'memory_id' => $memoryId,
                'strategic_context_id' => null,
                'agents' => [],
                'error' => 'session_has_no_strategic_context',
                'http' => 400,
            ];
        }

        if (!$this->memories->isMemoryLinkedToStrategicContext($memoryId, $contextId)) {
            return [
                'session_id' => $sessionId,
                'memory_id' => $memoryId,
                'strategic_context_id' => $contextId,
                'agents' => [],
                'error' => 'memory_not_linked_to_session_context',
                'http' => 400,
            ];
        }

        $participants = $this->resolver->resolveParticipants($sessionId, [
            'include_synthesizer' => $includeSynth,
            'include_devil_advocate' => $includeDa,
        ]);

        $personaNames = $this->personaNameMap();
        $agents = [];
        foreach ($participants as $aid) {
            $ex = $this->agentMem->readIfExistsNoSideEffects($contextId, $aid);
            $agents[] = [
                'agent_id' => $aid,
                'agent_name' => $personaNames[$aid] ?? $aid,
                'memory_exists' => $ex['exists'] === true,
                'patch' => $this->buildPatches($mem, $sessionId, $aid),
                'warnings' => [],
            ];
        }

        return [
            'session_id' => $sessionId,
            'memory_id' => $memoryId,
            'strategic_context_id' => $contextId,
            'agents' => $agents,
        ];
    }

    /**
     * @param list<string> $agentIds
     * @return array<string,mixed>
     */
    public function propagate(
        string $sessionId,
        string $memoryId,
        array $agentIds,
        bool $confirm,
        bool $expertOverride = false,
        array $options = []
    ): array {
        if (!$confirm) {
            return ['error' => 'confirm_required', 'http' => 400, 'results' => []];
        }
        $preview = $this->preview($sessionId, $memoryId, $options);
        if (isset($preview['error'])) {
            return $preview + ['results' => []];
        }
        $contextId = (string)$preview['strategic_context_id'];

        $participants = $this->resolver->resolveParticipants($sessionId, $options);
        $participantSet = array_fill_keys($participants, true);

        $mem = $this->memories->findById($memoryId);
        if (!$mem) {
            return ['error' => 'memory_not_found', 'http' => 404, 'results' => []];
        }

        $ids = array_values(array_unique(array_filter(array_map(static fn ($x) => strtolower(trim((string)$x)), $agentIds))));
        if ($ids === []) {
            $ids = $participants;
        }

        $results = [];
        foreach ($ids as $aid) {
            if (!isset($participantSet[$aid])) {
                if (!$expertOverride) {
                    $results[] = [
                        'agent_id' => $aid,
                        'ok' => false,
                        'changed' => false,
                        'sections_touched' => [],
                        'warnings' => ['agent_not_participant_use_expert_override'],
                    ];
                    continue;
                }
            }
            $patches = $this->buildPatches($mem, $sessionId, $aid);
            $dr = $this->agentMem->appendPropagatedDecisionBlock(
                $contextId,
                $aid,
                $memoryId,
                (string)$patches['decisions_remembered']
            );
            if (!empty($dr['skipped_duplicate'])) {
                $results[] = [
                    'agent_id' => $aid,
                    'ok' => true,
                    'changed' => false,
                    'sections_touched' => [],
                    'warnings' => ['skipped_duplicate_propagation'],
                    'skipped_duplicate' => true,
                ];
                continue;
            }
            if (!($dr['ok'] ?? false)) {
                $results[] = [
                    'agent_id' => $aid,
                    'ok' => false,
                    'changed' => false,
                    'sections_touched' => [],
                    'warnings' => $dr['warnings'] ?? [],
                    'message' => $dr['message'] ?? 'decisions_remembered_failed',
                ];
                continue;
            }
            $rn = $this->agentMem->appendRecentNote(
                $contextId,
                $aid,
                (string)$patches['recent_note'],
                $sessionId
            );
            $sections = array_merge(
                $dr['sections_touched'] ?? [],
                $rn['sections_touched'] ?? []
            );
            $warnings = array_merge($dr['warnings'] ?? [], $rn['warnings'] ?? []);
            $ok = ($rn['ok'] ?? false);
            $results[] = [
                'agent_id' => $aid,
                'ok' => $ok,
                'changed' => ($dr['changed'] ?? false) || ($rn['changed'] ?? false),
                'sections_touched' => array_values(array_unique($sections)),
                'warnings' => $warnings,
                'message' => !$ok ? ($rn['message'] ?? '') : null,
            ];
        }

        return [
            'session_id' => $sessionId,
            'memory_id' => $memoryId,
            'strategic_context_id' => $contextId,
            'results' => $results,
        ];
    }

    /** @param array<string,mixed> $mem */
    private function buildPatches(array $mem, string $sessionId, string $agentId): array
    {
        $mid = (string)($mem['memory_id'] ?? '');
        $created = substr((string)($mem['created_at'] ?? ''), 0, 10);
        if ($created === '') {
            $created = gmdate('Y-m-d');
        }
        $status = (string)($mem['decision_status'] ?? '');
        $summary = $this->oneLine((string)($mem['decision_summary'] ?? ''), 220);
        $risks = $this->flattenJsonList($mem['unresolved_risks'] ?? []);
        $next = $this->flattenJsonList($mem['recommended_next_steps'] ?? []);
        $nextFirst = $next[0] ?? '—';
        $playbook = (string)($mem['playbook_id'] ?? '');

        $dr = [];
        $dr[] = '  - [' . $created . '] Session ' . $sessionId . ' / Memory ' . $mid;
        $dr[] = '  - Decision: ' . $status;
        $dr[] = '  - Summary: ' . $summary;
        $dr[] = '  - Risks: ' . ($risks !== [] ? implode('; ', array_slice($risks, 0, 8)) : '—');
        $dr[] = '  - Next steps: ' . ($next !== [] ? implode('; ', array_slice($next, 0, 8)) : '—');
        $dr[] = '  - Playbook: ' . ($playbook !== '' ? $playbook : '—');
        $dr[] = '  - Agent role: participant (' . $agentId . ')';
        $dr[] = '  - Outcome (persisted status): ' . ($status !== '' ? $status : '—');
        $dr[] = '  - Next action (from memory): ' . $this->oneLine((string)$nextFirst, 200);

        $recent = 'Participated in decision ' . $mid . ': ' . $this->oneLine($summary, 160);

        return [
            'decisions_remembered' => implode("\n", $dr),
            'recent_note' => $recent,
        ];
    }

    /** @return array<string,string> */
    private function personaNameMap(): array
    {
        $out = [];
        foreach ($this->personas->findAll() as $p) {
            $id = strtolower(trim((string)($p['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $out[$id] = trim((string)($p['name'] ?? $p['title'] ?? $id));
        }
        return $out;
    }

    /** @param mixed $raw */
    private function flattenJsonList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $o = [];
        foreach ($raw as $v) {
            $s = trim((string)$v);
            if ($s !== '') {
                $o[] = $s;
            }
        }
        return $o;
    }

    private function oneLine(string $s, int $max): string
    {
        $x = preg_replace('/\s+/', ' ', $s);
        $x = trim((string)$x);
        if ($x === '') {
            return '—';
        }
        if (function_exists('mb_strlen') && mb_strlen($x) > $max) {
            return mb_substr($x, 0, max(0, $max - 1)) . '…';
        }
        if (strlen($x) > $max) {
            return substr($x, 0, $max - 1) . '…';
        }
        return $x;
    }
}

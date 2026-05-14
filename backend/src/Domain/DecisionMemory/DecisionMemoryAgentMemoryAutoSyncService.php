<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

use Domain\Sessions\SessionAgentResolver;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\SessionRepository;

/**
 * Synchronisation automatique et idempotente Decision Memory → memory.md agents (aucun runner).
 */
final class DecisionMemoryAgentMemoryAutoSyncService
{
    private DecisionMemoryRepository $memories;

    private SessionRepository $sessions;

    private SessionAgentResolver $resolver;

    private AgentContextMemoryService $agentMem;

    public function __construct(
        ?DecisionMemoryRepository $memories = null,
        ?SessionRepository $sessions = null,
        ?SessionAgentResolver $resolver = null,
        ?AgentContextMemoryService $agentMem = null,
    ) {
        $this->memories = $memories ?? new DecisionMemoryRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->resolver = $resolver ?? new SessionAgentResolver();
        $this->agentMem = $agentMem ?? new AgentContextMemoryService();
    }

    /**
     * @param array<string,mixed> $memory Ligne decision_memories hydratée
     * @param array<string,mixed> $session Ligne sessions (findById)
     *
     * @return array{
     *   enabled:bool,
     *   participants:list<string>,
     *   updated:list<array<string,mixed>>,
     *   skipped:list<array<string,mixed>>,
     *   warnings:list<string>
     * }
     */
    /**
     * @param array{include_synthesizer?:bool,include_devil_advocate?:bool} $resolverOptions
     */
    public function syncAfterPersist(array $memory, array $session, array $resolverOptions = []): array
    {
        $memoryId = trim((string)($memory['memory_id'] ?? ''));
        $sessionId = trim((string)($memory['session_id'] ?? ($session['id'] ?? '')));
        $report = [
            'enabled' => true,
            'participants' => [],
            'updated' => [],
            'skipped' => [],
            'warnings' => [],
        ];

        if ($memoryId === '' || $sessionId === '') {
            $report['enabled'] = false;
            $report['warnings'][] = 'invalid_memory_or_session';

            return $report;
        }

        $contextId = trim((string)($session['strategic_context_id'] ?? ''));
        if ($contextId === '') {
            $report['enabled'] = false;
            $report['warnings'][] = 'no_strategic_context_id';

            return $report;
        }

        if (!$this->memories->isMemoryLinkedToStrategicContext($memoryId, $contextId)) {
            $report['enabled'] = false;
            $report['warnings'][] = 'memory_not_linked_to_strategic_context';

            return $report;
        }

        $detailed = $this->resolver->resolveParticipantsWithSources($sessionId, $resolverOptions);
        $detailed = $this->resolver->filterDetailedParticipantsForMemorySync($sessionId, $session, $detailed);
        $participantIds = array_map(static fn (array $r) => (string)($r['agent_id'] ?? ''), $detailed);
        $participantIds = array_values(array_filter($participantIds));
        $report['participants'] = $participantIds;

        if ($participantIds === []) {
            $report['warnings'][] = 'no_participants_resolved';

            return $report;
        }

        $mode = trim((string)($session['mode'] ?? ''));
        $playbookId = trim((string)($memory['playbook_id'] ?? ''));
        $status = trim((string)($memory['decision_status'] ?? ''));
        $summary = trim((string)($memory['decision_summary'] ?? ''));
        $next = is_array($memory['recommended_next_steps'] ?? null)
            ? array_values(array_filter(array_map('strval', $memory['recommended_next_steps'])))
            : [];
        $createdAt = trim((string)($memory['created_at'] ?? ''));

        $riskLevel = '';
        if (!empty($session['result']) && is_string($session['result'])) {
            $res = json_decode($session['result'], true);
            if (is_array($res)) {
                $out = $res['decision_outcome'] ?? null;
                if (is_array($out) && isset($out['execution_risk_level'])) {
                    $riskLevel = trim((string)$out['execution_risk_level']);
                }
            }
        }

        $ps = is_array($memory['persistence_safety'] ?? null) ? $memory['persistence_safety'] : [];
        $verdictLabel = trim((string)($ps['da_verdict_label'] ?? ''));
        if ($verdictLabel === '' && !empty($session['result']) && is_string($session['result'])) {
            $res = json_decode($session['result'], true);
            if (is_array($res) && is_array($res['verdict'] ?? null)) {
                $verdictLabel = strtolower(trim((string)($res['verdict']['verdict_label'] ?? '')));
            }
        }
        $cx = is_array($ps['da_decision_signal_contradictions'] ?? null)
            ? array_values(array_filter(array_map('strval', $ps['da_decision_signal_contradictions'])))
            : [];

        foreach ($detailed as $row) {
            $aid = (string)($row['agent_id'] ?? '');
            if ($aid === '') {
                continue;
            }
            $sync = $this->agentMem->appendDecisionMemoryAutoSync(
                $contextId,
                $aid,
                [
                    'memory_id' => $memoryId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'playbook_id' => $playbookId,
                    'decision_status' => $status,
                    'decision_summary' => $summary,
                    'required_next_actions' => $next,
                    'risk_level' => $riskLevel,
                    'created_at' => $createdAt,
                    'memory_state' => trim((string)($memory['memory_state'] ?? '')),
                    'persistence_quality' => trim((string)($ps['da_persistence_quality'] ?? '')),
                    'review_required' => ($ps['da_review_required'] ?? false) === true,
                    'original_outcome_label' => trim((string)($ps['da_original_outcome_status_label'] ?? '')),
                    'verdict_label' => $verdictLabel,
                    'contradictions' => $cx,
                ]
            );
            if (!empty($sync['skipped_duplicate'])) {
                $report['skipped'][] = ['agent_id' => $aid, 'reason' => 'duplicate_memory_id'];
                continue;
            }
            if (($sync['ok'] ?? false) !== true) {
                $report['skipped'][] = ['agent_id' => $aid, 'reason' => (string)($sync['message'] ?? 'sync_failed')];
                continue;
            }
            $report['updated'][] = [
                'agent_id' => $aid,
                'changed' => (bool)($sync['changed'] ?? false),
                'sections_touched' => $sync['sections_touched'] ?? [],
                'warnings' => $sync['warnings'] ?? [],
            ];
        }

        return $report;
    }
}

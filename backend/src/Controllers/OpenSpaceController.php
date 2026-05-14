<?php
declare(strict_types=1);

namespace Controllers;

use Domain\Agents\Agent;
use Domain\Agents\AgentAssembler;
use Domain\OpenSpace\OpenSpaceAgentChatPromptBuilder;
use Domain\OpenSpace\OpenSpaceOrchestratorPromptBuilder;
use Domain\StrategicContext\AgentContextMemoryService;
use Domain\StrategicContext\StrategicContextWorkspaceAgentsCatalog;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\OpenSpaceRepository;
use Infrastructure\Persistence\PersonaRepository;
use Infrastructure\Persistence\StrategicContextRepository;
use Domain\Providers\ProviderRouter;

final class OpenSpaceController
{
    private StrategicContextRepository $contexts;
    private OpenSpaceRepository $openSpace;
    private AgentAssembler $agents;
    private ProviderRouter $router;
    private DecisionMemoryRepository $decisionMemories;
    private AgentContextMemoryService $agentMemory;
    private PersonaRepository $personas;
    private StrategicContextWorkspaceAgentsCatalog $workspaceAgents;
    private OpenSpaceOrchestratorPromptBuilder $openSpaceOrchestratorPromptBuilder;
    private OpenSpaceAgentChatPromptBuilder $openSpaceAgentChatPromptBuilder;

    public function __construct(
        ?ProviderRouter $router = null,
        ?AgentAssembler $agents = null,
        ?OpenSpaceOrchestratorPromptBuilder $openSpaceOrchestratorPromptBuilder = null,
        ?OpenSpaceAgentChatPromptBuilder $openSpaceAgentChatPromptBuilder = null,
    )
    {
        $this->contexts = new StrategicContextRepository();
        $this->openSpace = new OpenSpaceRepository();
        $this->agents = $agents ?? new AgentAssembler();
        $this->router = $router ?? new ProviderRouter();
        $this->decisionMemories = new DecisionMemoryRepository();
        $this->agentMemory = new AgentContextMemoryService();
        $this->personas = new PersonaRepository();
        $this->workspaceAgents = new StrategicContextWorkspaceAgentsCatalog();
        $this->openSpaceOrchestratorPromptBuilder = $openSpaceOrchestratorPromptBuilder ?? new OpenSpaceOrchestratorPromptBuilder();
        $this->openSpaceAgentChatPromptBuilder = $openSpaceAgentChatPromptBuilder ?? new OpenSpaceAgentChatPromptBuilder();
    }

    /** GET /api/open-space/contexts */
    public function contexts(Request $_req): array
    {
        $items = $this->contexts->list([], 200);
        $active = $this->contexts->getActiveContext();
        return [
            'contexts' => $items,
            'active_context' => $active,
        ];
    }

    /** GET /api/open-space/boards?context_id=... */
    public function listBoards(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        return ['boards' => $this->openSpace->listBoards($contextId)];
    }

    /** POST /api/open-space/boards */
    public function createBoard(Request $req): array
    {
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        $title = trim((string)($body['title'] ?? ''));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($title === '') {
            return Response::error('title is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $board = $this->openSpace->createBoard($contextId, $title, $this->nullableText($body['description'] ?? null));
        return ['board' => $board];
    }

    /** GET /api/open-space/tasks?context_id=... */
    public function listTasks(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $filters = [
            'status' => (string)$req->query('status', ''),
            'agent_id' => (string)$req->query('agent_id', ''),
        ];
        return ['tasks' => $this->openSpace->listTasks($contextId, $filters)];
    }

    /** GET /api/open-space/context-agents?context_id=... */
    public function listContextAgents(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $agents = $this->workspaceAgents->buildForOpenSpaceAgentChat($contextId);

        return [
            'context_id' => $contextId,
            'agents' => $agents,
        ];
    }

    /** POST /api/open-space/tasks */
    public function createTask(Request $req): array
    {
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return Response::error('title is required', 400);
        }
        $status = $this->normalizeTaskStatus((string)($body['status'] ?? 'backlog'));
        if ($status === null) {
            return Response::error('Invalid status', 400);
        }

        $boardId = trim((string)($body['board_id'] ?? ''));
        if ($boardId === '') {
            $board = $this->openSpace->ensureContextBoard($contextId);
            $boardId = (string)($board['id'] ?? '');
        }
        $boardRow = $this->openSpace->findBoard($boardId);
        if ($boardRow === null || strtolower(trim((string)$boardRow['strategic_context_id'])) !== $contextId) {
            return Response::error('board_id does not belong to context', 400);
        }

        $task = $this->openSpace->createTask([
            'board_id' => $boardId,
            'strategic_context_id' => $contextId,
            'title' => $title,
            'description' => $this->nullableText($body['description'] ?? null),
            'status' => $status,
            'priority' => $this->nullableText($body['priority'] ?? null),
            'assignee_agent_id' => $this->normalizeAgentId($body['assignee_agent_id'] ?? null),
            'source_type' => $this->nullableText($body['source_type'] ?? null),
            'source_id' => $this->nullableText($body['source_id'] ?? null),
            'linked_session_id' => $this->nullableText($body['linked_session_id'] ?? null),
            'linked_decision_memory_id' => $this->nullableText($body['linked_decision_memory_id'] ?? null),
            'acceptance_criteria' => $this->nullableText($body['acceptance_criteria'] ?? null),
            'created_by' => $this->nullableText($body['created_by'] ?? 'user'),
        ]);
        $this->openSpace->insertTaskEvent((string)$task['id'], $contextId, 'task_created', [
            'status' => $status,
        ]);
        return ['task' => $task];
    }

    /** PATCH /api/open-space/tasks/{id} */
    public function updateTask(Request $req): array
    {
        $taskId = trim((string)$req->param('id'));
        $task = $this->openSpace->findTask($taskId);
        if ($task === null) {
            return Response::error('Task not found', 404);
        }
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if (strtolower(trim((string)$task['strategic_context_id'])) !== $contextId) {
            return Response::error('Cross-context update is forbidden', 400);
        }
        $updated = $this->openSpace->updateTask($taskId, $contextId, $body);
        if ($updated === null) {
            return Response::error('Task not found', 404);
        }
        $this->openSpace->insertTaskEvent($taskId, $contextId, 'task_updated', []);
        return ['task' => $updated];
    }

    /** POST /api/open-space/tasks/{id}/move */
    public function moveTask(Request $req): array
    {
        $taskId = trim((string)$req->param('id'));
        $task = $this->openSpace->findTask($taskId);
        if ($task === null) {
            return Response::error('Task not found', 404);
        }
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        $status = $this->normalizeTaskStatus((string)($body['status'] ?? ''));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($status === null) {
            return Response::error('Invalid status', 400);
        }
        if (strtolower(trim((string)$task['strategic_context_id'])) !== $contextId) {
            return Response::error('Cross-context move is forbidden', 400);
        }
        $updated = $this->openSpace->updateTask($taskId, $contextId, ['status' => $status]);
        $this->openSpace->insertTaskEvent($taskId, $contextId, 'task_moved', ['status' => $status]);
        return ['task' => $updated];
    }

    /** GET /api/open-space/tasks/{id}/messages?context_id=... */
    public function listTaskMessages(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        $taskId = trim((string)$req->param('id'));
        if ($taskId === '_context') {
            $taskId = '';
        }
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        if ($taskId !== '') {
            $task = $this->openSpace->findTask($taskId);
            if ($task === null) {
                return Response::error('Task not found', 404);
            }
            if (strtolower(trim((string)$task['strategic_context_id'])) !== $contextId) {
                return Response::error('Cross-context access is forbidden', 400);
            }
        }
        return ['messages' => $this->openSpace->listTaskMessages($contextId, $taskId)];
    }

    /** POST /api/open-space/tasks/{id}/messages */
    public function createTaskMessage(Request $req): array
    {
        $taskId = trim((string)$req->param('id'));
        if ($taskId === '_context') {
            $taskId = '';
        }
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        if ($taskId !== '') {
            $task = $this->openSpace->findTask($taskId);
            if ($task === null) {
                return Response::error('Task not found', 404);
            }
            if (strtolower(trim((string)$task['strategic_context_id'])) !== $contextId) {
                return Response::error('Cross-context message is forbidden', 400);
            }
        }
        $content = trim((string)($body['content'] ?? ''));
        $role = strtolower(trim((string)($body['role'] ?? 'user')));
        if ($content === '') {
            return Response::error('content is required', 400);
        }
        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            return Response::error('Invalid role', 400);
        }
        $agentId = $this->normalizeAgentId($body['agent_id'] ?? null);
        if ($role === 'assistant' && ($agentId === null || !$this->agentMemory->isValidAgentId($agentId))) {
            return Response::error('agent_id is required for assistant message', 400);
        }

        $message = $this->openSpace->createTaskMessage([
            'task_id' => $taskId !== '' ? $taskId : null,
            'strategic_context_id' => $contextId,
            'agent_id' => $agentId,
            'role' => $role,
            'content' => $content,
            'metadata_json' => null,
        ]);

        $reply = null;
        $replyDiagnostics = null;
        $generateReply = $this->toBool($body['generate_reply'] ?? false);
        if ($generateReply) {
            if ($agentId === null || !$this->agentMemory->isValidAgentId($agentId)) {
                return Response::error('agent_id is required to generate reply', 400);
            }
            if (!$this->workspaceAgents->isAgentEligibleForOpenSpaceChat($contextId, $agentId)) {
                return Response::error('Agent is not present in this strategic context', 403);
            }
            $task = $taskId !== '' ? $this->openSpace->findTask($taskId) : null;
            $chat = $this->generateAgentReply($contextId, $agentId, $content, $task, $taskId !== '' ? $taskId : null);
            if (!($chat['ok'] ?? false)) {
                return Response::error((string)($chat['message'] ?? 'LLM error'), (int)($chat['code'] ?? 502));
            }
            $replyDiagnostics = $chat['diagnostics'] ?? null;
            $reply = $this->openSpace->createTaskMessage([
                'task_id' => $taskId !== '' ? $taskId : null,
                'strategic_context_id' => $contextId,
                'agent_id' => $agentId,
                'role' => 'assistant',
                'content' => (string)$chat['answer'],
                'metadata_json' => json_encode([
                    'provider_id' => $chat['provider_id'] ?? null,
                    'provider_name' => $chat['provider_name'] ?? null,
                    'provider_type' => $chat['provider_type'] ?? null,
                    'model' => $chat['model'] ?? null,
                    'routing_mode' => $chat['routing_mode'] ?? null,
                    'routing_source' => $chat['routing_source'] ?? null,
                    'fallback_used' => $chat['fallback_used'] ?? null,
                    'fallback_reason' => $chat['fallback_reason'] ?? null,
                    'requested_provider_id' => $chat['requested_provider_id'] ?? null,
                    'requested_model' => $chat['requested_model'] ?? null,
                    'memory_available' => $chat['memory_available'] ?? false,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }

        return [
            'message' => $message,
            'reply' => $reply,
            'reply_diagnostics' => $replyDiagnostics,
        ];
    }

    /** POST /api/open-space/orchestrate */
    public function orchestrate(Request $req): array
    {
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        $objective = trim((string)($body['objective'] ?? ''));
        $constraints = trim((string)($body['constraints'] ?? ''));
        $mode = strtolower(trim((string)($body['mode'] ?? 'proposal_only')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('strategic_context_id/context_id is required', 400);
        }
        if ($objective === '') {
            return Response::error('objective is required', 400);
        }
        if ($mode !== '' && $mode !== 'proposal_only') {
            return Response::error('mode must be proposal_only', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        $llmMeta = null;
        $source = 'llm';
        $warning = false;
        $warnMsg = null;
        try {
            $proposalJson = $this->buildStructuredProposalFromLlm($contextId, $objective, $constraints);
            $llmMeta = $proposalJson['_llm_metadata'] ?? null;
            unset($proposalJson['_llm_metadata']);
        } catch (\Throwable $e) {
            $proposalJson = $this->buildStructuredProposalFallback($objective, $constraints);
            $source = 'fallback_static';
            $warning = true;
            $warnMsg = 'Static fallback proposal used: ' . $e->getMessage();
            $llmMeta = [
                'fallback_used' => true,
                'fallback_reason' => $e->getMessage(),
            ];
        }
        $proposal = $this->openSpace->createProposal($contextId, $objective, $proposalJson, 'draft', is_array($llmMeta) ? $llmMeta : null, $source, $warning);

        return [
            'proposal' => $proposal,
            'proposal_source' => $source,
            'warning' => $warning,
            'warning_message' => $warnMsg,
            'creates_tasks_automatically' => false,
        ];
    }

    /** GET /api/open-space/proposals?context_id=... */
    public function listProposals(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        if ($this->contexts->find($contextId) === null) {
            return Response::error('Context not found', 404);
        }
        return ['proposals' => $this->openSpace->listProposals($contextId)];
    }

    /** POST /api/open-space/proposals/{id}/accept */
    public function acceptProposal(Request $req): array
    {
        $proposalId = trim((string)$req->param('id'));
        $proposal = $this->openSpace->findProposal($proposalId);
        if ($proposal === null) {
            return Response::error('Proposal not found', 404);
        }
        $body = $this->body($req);
        $contextId = strtolower(trim((string)($body['context_id'] ?? $body['strategic_context_id'] ?? '')));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        $proposalContextId = strtolower(trim((string)$proposal['strategic_context_id']));
        if ($proposalContextId !== $contextId) {
            return Response::error('Cross-context accept is forbidden', 400);
        }
        $decoded = json_decode((string)($proposal['proposal_json'] ?? '{}'), true);
        $tasks = is_array($decoded['proposed_tasks'] ?? null) ? $decoded['proposed_tasks'] : [];

        $boardId = trim((string)($body['board_id'] ?? ''));
        if ($boardId === '') {
            $board = $this->openSpace->ensureContextBoard($contextId);
            $boardId = (string)($board['id'] ?? '');
        }
        $board = $this->openSpace->findBoard($boardId);
        if ($board === null || strtolower(trim((string)$board['strategic_context_id'])) !== $contextId) {
            return Response::error('board_id does not belong to context', 400);
        }

        $createdTasks = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $title = trim((string)($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $status = $this->normalizeTaskStatus((string)($task['status'] ?? 'backlog'));
            if ($status === null) {
                $status = 'backlog';
            }
            $row = $this->openSpace->createTask([
                'board_id' => $boardId,
                'strategic_context_id' => $contextId,
                'title' => $title,
                'description' => $this->nullableText($task['description'] ?? null),
                'status' => $status,
                'priority' => $this->nullableText($task['priority'] ?? null),
                'assignee_agent_id' => $this->normalizeAgentId($task['assignee_agent_id'] ?? null),
                'source_type' => 'orchestrator_proposal',
                'source_id' => $proposalId,
                'linked_session_id' => $this->nullableText($task['linked_session_id'] ?? null),
                'linked_decision_memory_id' => $this->nullableText($task['linked_decision_memory_id'] ?? null),
                'acceptance_criteria' => $this->nullableText($task['acceptance_criteria'] ?? null),
                'created_by' => 'orchestrator_accept',
            ]);
            $this->openSpace->insertTaskEvent((string)$row['id'], $contextId, 'task_created_from_proposal', [
                'proposal_id' => $proposalId,
            ]);
            $createdTasks[] = $row;
        }

        $updatedProposal = $this->openSpace->updateProposalStatus($proposalId, 'accepted');
        return [
            'proposal' => $updatedProposal,
            'tasks' => $createdTasks,
            'tasks_created' => count($createdTasks),
        ];
    }

    /** GET /api/open-space/tasks/{id}/jira-export?context_id=... */
    public function exportTaskJira(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        $taskId = trim((string)$req->param('id'));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        $task = $this->openSpace->findTask($taskId);
        if ($task === null) {
            return Response::error('Task not found', 404);
        }
        if (strtolower(trim((string)$task['strategic_context_id'])) !== $contextId) {
            return Response::error('Cross-context export forbidden', 400);
        }
        $payload = $this->buildJiraExportPayload($contextId, [$task]);
        return [
            'filename' => $this->jiraExportFileName($contextId),
            'export' => $payload,
        ];
    }

    /** GET /api/open-space/boards/{id}/jira-export?context_id=... */
    public function exportBoardJira(Request $req): array
    {
        $contextId = strtolower(trim((string)$req->query('context_id', '')));
        $boardId = trim((string)$req->param('id'));
        if (!$this->isValidContextId($contextId)) {
            return Response::error('context_id is required', 400);
        }
        $board = $this->openSpace->findContextBoard($contextId, $boardId);
        if ($board === null) {
            return Response::error('Board not found', 404);
        }
        $tasks = $this->openSpace->listBoardTasks($contextId, $boardId);
        $payload = $this->buildJiraExportPayload($contextId, $tasks);
        return [
            'filename' => $this->jiraExportFileName($contextId),
            'export' => $payload,
        ];
    }

    /** @return array<string,mixed> */
    private function body(Request $req): array
    {
        $body = $req->body();
        return is_array($body) ? $body : [];
    }

    private function isValidContextId(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    private function nullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $value = implode("\n", array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $value), static fn ($x) => $x !== '')));
        }
        $s = trim((string)$value);
        return $s === '' ? null : $s;
    }

    private function normalizeAgentId($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = strtolower(trim((string)$value));
        return $s === '' ? null : $s;
    }

    private function toBool($value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        $s = strtolower(trim((string)$value));
        return in_array($s, ['true', 'yes', 'on'], true);
    }

    /** @return array<string,mixed> */
    private function buildStructuredProposalFallback(string $objective, string $constraints): array
    {
        $mode = 'decision-room';
        $obj = strtolower($objective);
        if (str_contains($obj, 'conflit') || str_contains($obj, 'conflict') || str_contains($obj, 'debate')) {
            $mode = 'confrontation';
        } elseif (str_contains($obj, 'quick') || str_contains($obj, 'rapide')) {
            $mode = 'quick-decision';
        } elseif (str_contains($obj, 'risk') || str_contains($obj, 'stress')) {
            $mode = 'stress-test';
        }

        return [
            'recommended_mode' => $mode,
            'mode_rationale' => 'Fallback statique utilisé: le provider LLM est indisponible.',
            'recommended_agents' => [
                ['agent_id' => 'pm', 'reason' => 'Cadre et exécution'],
                ['agent_id' => 'architect', 'reason' => 'Structuration solution'],
                ['agent_id' => 'critic', 'reason' => 'Risques et objections'],
            ],
            'proposed_tasks' => [
                [
                    'title' => 'Clarifier objectif et contraintes',
                    'description' => $constraints !== '' ? $constraints : 'Documenter contraintes, hypothèses, critères.',
                    'status' => 'backlog',
                    'priority' => 'high',
                    'assignee_agent_id' => 'pm',
                    'acceptance_criteria' => ['Objectif reformulé et validé.'],
                    'jira' => [
                        'issue_type' => 'Task',
                        'labels' => ['decision-arena', 'openspace'],
                        'summary' => 'Clarifier objectif et contraintes',
                        'description' => $constraints,
                    ],
                ],
            ],
            'risks' => ['Risque de dérive de scope sans critères explicites.'],
            'open_questions' => ['Quel livrable exact est attendu ?'],
            'assumptions' => ['Validation humaine obligatoire avant création de tâches.'],
            'next_recommended_action' => 'Valider la proposition puis créer les tâches.',
        ];
    }

    /** @return array<string,mixed> */
    private function buildStructuredProposalFromLlm(string $contextId, string $objective, string $constraints): array
    {
        $ctx = $this->contexts->find($contextId);
        if ($ctx === null) {
            throw new \RuntimeException('Context not found');
        }
        $promptInput = $this->collectOpenSpaceOrchestratorPromptInput($contextId, $objective, $constraints, $ctx);
        $messages = $this->openSpaceOrchestratorPromptBuilder->buildProposalMessages($promptInput);

        $agent = $this->assembleOpenSpaceOrchestratorAgent();
        if ($agent === null) {
            throw new \RuntimeException('orchestrator agent not available');
        }
        $res = $this->router->chat($messages, $agent);
        $content = trim((string)($res['content'] ?? ''));
        if ($content === '') {
            throw new \RuntimeException('empty LLM response');
        }
        $parsed = $this->decodeJsonFromLlm($content);
        if (!is_array($parsed)) {
            throw new \RuntimeException('LLM response is not valid JSON object');
        }
        $proposal = $this->sanitizeOrchestratorProposalPayload($parsed);
        $proposal['_llm_metadata'] = [
            'provider_id' => $res['provider_id'] ?? null,
            'provider_name' => $res['provider_name'] ?? null,
            'provider_type' => $res['provider_type'] ?? null,
            'model' => $res['model'] ?? null,
            'routing_mode' => $res['routing_mode'] ?? null,
            'routing_source' => $res['routing_source'] ?? null,
            'fallback_used' => $res['fallback_used'] ?? false,
            'fallback_reason' => $res['fallback_reason'] ?? null,
            'requested_provider_id' => $res['requested_provider_id'] ?? null,
            'requested_model' => $res['requested_model'] ?? null,
        ];
        return $proposal;
    }

    private function assembleOpenSpaceOrchestratorAgent(): ?Agent
    {
        foreach (['orchestrator', 'openspace-orchestrator', 'pm', 'architect'] as $personaId) {
            $agent = $this->agents->assemble($personaId, null, null, null);
            if ($agent !== null) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array{
     *   context_id: string,
     *   context_title: string,
     *   context_description: string,
     *   objective: string,
     *   constraints: string,
     *   existing_task_lines: list<string>,
     *   decision_lines: list<string>,
     *   agent_lines: list<string>,
     *   memory_snippets: list<string>
     * }
     */
    private function collectOpenSpaceOrchestratorPromptInput(string $contextId, string $objective, string $constraints, array $ctx): array
    {
        $linkedDecisions = $this->decisionMemories->findLinkedMemoriesForStrategicContext($contextId, 10);
        $decisionLines = [];
        foreach ($linkedDecisions as $row) {
            $decisionLines[] = sprintf(
                '- %s | %s | %s',
                (string)($row['memory_id'] ?? ''),
                (string)($row['decision_status'] ?? ''),
                $this->truncate((string)($row['decision_summary'] ?? ''), 260)
            );
        }
        $tasks = $this->openSpace->listTasks($contextId, []);
        $existingTaskLines = [];
        foreach (array_slice($tasks, 0, 20) as $task) {
            $existingTaskLines[] = sprintf(
                '- %s | %s | %s',
                (string)($task['id'] ?? ''),
                (string)($task['status'] ?? ''),
                $this->truncate((string)($task['title'] ?? ''), 150)
            );
        }
        $agents = $this->workspaceAgents->buildForContext($contextId);
        $personaRows = $this->personas->findAll();
        $agentLines = [];
        foreach ($agents as $a) {
            $agentLines[] = sprintf('- %s | %s', (string)($a['agent_id'] ?? ''), (string)($a['agent_name'] ?? ''));
        }
        foreach ($personaRows as $p) {
            $id = strtolower(trim((string)($p['id'] ?? '')));
            if ($id === '' || in_array($id, ['synthesizer', 'devil_advocate'], true)) {
                continue;
            }
            $agentLines[] = sprintf('- %s | %s', $id, (string)($p['name'] ?? $id));
        }
        $agentLines = array_values(array_unique($agentLines));

        $memorySnippets = [];
        foreach (array_slice($agents, 0, 10) as $agentRow) {
            $aid = strtolower(trim((string)($agentRow['agent_id'] ?? '')));
            if ($aid === '') {
                continue;
            }
            $m = $this->agentMemory->readIfExistsNoSideEffects($contextId, $aid);
            if (($m['exists'] ?? false) !== true) {
                continue;
            }
            $memorySnippets[] = sprintf(
                '### %s memory.md (excerpt)%s',
                $aid,
                "\n" . $this->truncate(trim((string)($m['content'] ?? '')), 420)
            );
        }

        return [
            'context_id' => $contextId,
            'context_title' => (string)($ctx['title'] ?? ''),
            'context_description' => $this->truncate((string)($ctx['description'] ?? ''), 1800),
            'objective' => $objective,
            'constraints' => $constraints,
            'existing_task_lines' => $existingTaskLines,
            'decision_lines' => $decisionLines,
            'agent_lines' => $agentLines,
            'memory_snippets' => $memorySnippets,
        ];
    }

    /**
     * Génération chat situé OpenSpace avec persona + mémoire contexte + DM compactes.
     *
     * @param ?array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function generateAgentReply(string $contextId, string $agentId, string $userContent, ?array $task, ?string $taskId): array
    {
        $agent = $this->agents->assemble($agentId, null, null, null);
        if ($agent === null) {
            return ['ok' => false, 'message' => 'Persona introuvable pour cet agent', 'code' => 404];
        }
        $ctx = $this->contexts->find($contextId);
        if ($ctx === null) {
            return ['ok' => false, 'message' => 'Contexte introuvable', 'code' => 404];
        }
        $memory = $this->agentMemory->readIfExistsNoSideEffects($contextId, $agentId);
        $memoryExists = ($memory['exists'] ?? false) === true;
        $memoryContent = trim((string)($memory['content'] ?? ''));
        $memoryBlock = $memoryExists && $memoryContent !== ''
            ? $this->truncate($memoryContent, 3200)
            : 'No context memory available for this agent yet.';

        $decisionRows = $this->decisionMemories->findLinkedMemoriesForStrategicContext($contextId, 8);
        $decisionLines = [];
        foreach ($decisionRows as $row) {
            $decisionLines[] = sprintf(
                '- %s (%s): %s',
                (string)($row['memory_id'] ?? ''),
                (string)($row['decision_status'] ?? ''),
                $this->truncate((string)($row['decision_summary'] ?? ''), 220)
            );
        }
        $decisionBlock = $decisionLines !== [] ? implode("\n", $decisionLines) : '- none';

        $linkedMemIds = $this->contexts->linkedMemoryIds($contextId);
        $memorySummary = $this->agentMemory->summarizeMemoryMdForAgentContext($contextId, $agentId, $linkedMemIds);

        $taskBlock = 'No linked task.';
        if ($taskId !== null && trim($taskId) !== '') {
            if (!is_array($task) || strtolower(trim((string)($task['strategic_context_id'] ?? ''))) !== strtolower($contextId)) {
                return ['ok' => false, 'message' => 'task_id does not belong to context', 'code' => 400];
            }
            $taskBlock = sprintf(
                "Task linked:\n- id: %s\n- title: %s\n- status: %s\n- description: %s\n- acceptance_criteria: %s",
                (string)($task['id'] ?? ''),
                (string)($task['title'] ?? ''),
                (string)($task['status'] ?? ''),
                $this->truncate((string)($task['description'] ?? ''), 500),
                $this->truncate((string)($task['acceptance_criteria'] ?? ''), 500)
            );
        }
        $historyRows = $this->openSpace->listTaskMessages($contextId, $taskId);
        $historyLines = [];
        foreach (array_slice($historyRows, -12) as $msg) {
            $historyLines[] = '[' . (string)($msg['role'] ?? 'user') . '] ' . $this->truncate((string)($msg['content'] ?? ''), 250);
        }
        $historyBlock = $historyLines !== [] ? implode("\n", $historyLines) : '- none';

        $soulContent = '';
        if ($agent->soul !== null) {
            $soulContent = $this->truncate(trim((string)$agent->soul->content), 1200);
        }

        $chatInput = [
            'strategic_context_id' => $contextId,
            'context_title' => (string)($ctx['title'] ?? ''),
            'context_description' => $this->truncate((string)($ctx['description'] ?? ''), 1500),
            'task_block' => $taskBlock,
            'persona_id' => (string)($agent->persona->id ?? $agentId),
            'persona_name' => (string)($agent->persona->name ?? $agentId),
            'persona_title' => (string)($agent->persona->title ?? ''),
            'persona_content' => $this->truncate((string)($agent->persona->content ?? ''), 2600),
            'soul_content' => $soulContent,
            'memory_md_block' => $memoryBlock,
            'decision_memories_block' => $decisionBlock,
            'history_block' => $historyBlock,
            'user_message' => $userContent,
        ];

        try {
            $messages = $this->openSpaceAgentChatPromptBuilder->buildChatMessages($chatInput);
            $res = $this->router->chat($messages, $agent);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'LLM error: ' . $e->getMessage(), 'code' => 502];
        }

        $answer = trim((string)($res['content'] ?? ''));
        if ($answer === '') {
            return ['ok' => false, 'message' => 'LLM returned empty response', 'code' => 502];
        }
        $diagnostics = [
            'context_id' => $contextId,
            'agent_id' => $agentId,
            'task_id' => ($taskId !== null && trim((string)$taskId) !== '') ? $taskId : null,
            'persona_loaded' => true,
            'memory_md_loaded' => $memoryExists,
            'memory_md_state' => $memorySummary['state'],
            'decision_memories_count' => count($decisionRows),
            'provider_id' => $res['provider_id'] ?? null,
            'model' => $res['model'] ?? null,
            'routing_source' => $res['routing_source'] ?? null,
        ];

        return [
            'ok' => true,
            'answer' => $answer,
            'memory_available' => $memoryExists,
            'diagnostics' => $diagnostics,
            'provider_id' => $res['provider_id'] ?? null,
            'provider_name' => $res['provider_name'] ?? null,
            'provider_type' => $res['provider_type'] ?? null,
            'model' => $res['model'] ?? null,
            'routing_mode' => $res['routing_mode'] ?? null,
            'routing_source' => $res['routing_source'] ?? null,
            'fallback_used' => $res['fallback_used'] ?? false,
            'fallback_reason' => $res['fallback_reason'] ?? null,
            'requested_provider_id' => $res['requested_provider_id'] ?? null,
            'requested_model' => $res['requested_model'] ?? null,
        ];
    }

    private function normalizeTaskStatus(string $status): ?string
    {
        $s = strtolower(trim($status));
        if ($s === '') {
            return null;
        }
        $legacyMap = [
            'triage' => 'backlog',
            'ready' => 'todo',
            'running' => 'doing',
            'review' => 'testing',
            'blocked' => 'backlog',
            'todo' => 'todo',
            'doing' => 'doing',
            'testing' => 'testing',
            'backlog' => 'backlog',
            'done' => 'done',
            'à tester' => 'testing',
            'a_tester' => 'testing',
            'a-tester' => 'testing',
        ];
        $mapped = $legacyMap[$s] ?? $s;
        return in_array($mapped, OpenSpaceRepository::TASK_STATUSES, true) ? $mapped : null;
    }

    /** @param array<string,mixed> $task */
    private function mapTaskToJiraIssue(string $contextId, array $task): array
    {
        $prio = strtolower(trim((string)($task['priority'] ?? 'medium')));
        $priority = $prio === 'high' ? 'High' : ($prio === 'low' ? 'Low' : 'Medium');
        $acceptance = $task['acceptance_criteria'] ?? '';
        $acceptanceList = [];
        if (is_string($acceptance) && trim($acceptance) !== '') {
            $acceptanceList = preg_split('/\r?\n+/', trim($acceptance)) ?: [];
        } elseif (is_array($acceptance)) {
            $acceptanceList = array_values(array_map('strval', $acceptance));
        }
        return [
            'issueType' => 'Task',
            'summary' => (string)($task['title'] ?? ''),
            'description' => (string)($task['description'] ?? ''),
            'priority' => $priority,
            'labels' => ['decision-arena', 'openspace'],
            'assigneeAgentId' => (string)($task['assignee_agent_id'] ?? ''),
            'acceptanceCriteria' => array_values(array_filter(array_map('trim', $acceptanceList), static fn ($x) => $x !== '')),
            'contextId' => $contextId,
            'source' => [
                'type' => 'open_space_task',
                'id' => (string)($task['id'] ?? ''),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $tasks */
    private function buildJiraExportPayload(string $contextId, array $tasks): array
    {
        $issues = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            if (strtolower(trim((string)($task['strategic_context_id'] ?? ''))) !== strtolower($contextId)) {
                continue;
            }
            $issues[] = $this->mapTaskToJiraIssue($contextId, $task);
        }
        return [
            'projectKey' => '',
            'issues' => $issues,
        ];
    }

    private function jiraExportFileName(string $contextId): string
    {
        return 'openspace-jira-export-' . strtolower(trim($contextId)) . '-' . gmdate('Ymd-His') . '.json';
    }

    private function truncate(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    }

    /** @return array<string,mixed>|null */
    private function decodeJsonFromLlm(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
            $decoded = json_decode((string)$m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $parsed */
    private function sanitizeOrchestratorProposalPayload(array $parsed): array
    {
        $recommendedMode = strtolower(trim((string)($parsed['recommended_mode'] ?? 'open-space')));
        $allowedModes = ['decision-room', 'quick-decision', 'jury', 'confrontation', 'stress-test', 'open-space'];
        if (!in_array($recommendedMode, $allowedModes, true)) {
            $recommendedMode = 'open-space';
        }
        $agents = [];
        foreach (($parsed['recommended_agents'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aid = strtolower(trim((string)($row['agent_id'] ?? '')));
            if ($aid === '' || !$this->agentMemory->isValidAgentId($aid)) {
                continue;
            }
            $agents[] = [
                'agent_id' => $aid,
                'reason' => trim((string)($row['reason'] ?? '')),
            ];
        }
        $tasks = [];
        foreach (($parsed['proposed_tasks'] ?? []) as $task) {
            if (!is_array($task)) {
                continue;
            }
            $title = trim((string)($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $status = $this->normalizeTaskStatus((string)($task['status'] ?? 'backlog')) ?? 'backlog';
            $priority = strtolower(trim((string)($task['priority'] ?? 'medium')));
            if (!in_array($priority, ['high', 'medium', 'low'], true)) {
                $priority = 'medium';
            }
            $assignee = $this->normalizeAgentId($task['assignee_agent_id'] ?? null);
            $criteria = $task['acceptance_criteria'] ?? [];
            if (is_string($criteria)) {
                $criteria = [trim($criteria)];
            }
            if (!is_array($criteria)) {
                $criteria = [];
            }
            $jira = is_array($task['jira'] ?? null) ? $task['jira'] : [];
            $labels = is_array($jira['labels'] ?? null) ? $jira['labels'] : ['decision-arena', 'openspace'];
            $tasks[] = [
                'title' => $title,
                'description' => trim((string)($task['description'] ?? '')),
                'status' => $status,
                'priority' => $priority,
                'assignee_agent_id' => $assignee,
                'acceptance_criteria' => array_values(array_filter(array_map(static fn ($x) => trim((string)$x), $criteria), static fn ($x) => $x !== '')),
                'jira' => [
                    'issue_type' => trim((string)($jira['issue_type'] ?? 'Task')),
                    'labels' => array_values(array_map(static fn ($x) => trim((string)$x), $labels)),
                    'summary' => trim((string)($jira['summary'] ?? $title)),
                    'description' => trim((string)($jira['description'] ?? (string)($task['description'] ?? ''))),
                ],
            ];
        }

        return [
            'recommended_mode' => $recommendedMode,
            'mode_rationale' => trim((string)($parsed['mode_rationale'] ?? '')),
            'recommended_agents' => $agents,
            'proposed_tasks' => $tasks,
            'risks' => array_values(array_map('strval', is_array($parsed['risks'] ?? null) ? $parsed['risks'] : [])),
            'open_questions' => array_values(array_map('strval', is_array($parsed['open_questions'] ?? null) ? $parsed['open_questions'] : [])),
            'assumptions' => array_values(array_map('strval', is_array($parsed['assumptions'] ?? null) ? $parsed['assumptions'] : [])),
            'next_recommended_action' => trim((string)($parsed['next_recommended_action'] ?? '')),
        ];
    }
}


import { apiFetch } from './apiClient.js';

const OpenSpaceService = {
  listContexts() {
    return apiFetch('/api/open-space/contexts');
  },

  listBoards(contextId) {
    return apiFetch(`/api/open-space/boards?context_id=${encodeURIComponent(String(contextId || ''))}`);
  },

  createBoard(payload) {
    return apiFetch('/api/open-space/boards', { method: 'POST', body: JSON.stringify(payload || {}) });
  },

  listTasks(contextId, filters = {}) {
    const qs = new URLSearchParams();
    qs.set('context_id', String(contextId || ''));
    if (filters.status) qs.set('status', String(filters.status));
    if (filters.agent_id) qs.set('agent_id', String(filters.agent_id));
    return apiFetch(`/api/open-space/tasks?${qs.toString()}`);
  },

  createTask(contextId, payload) {
    return apiFetch('/api/open-space/tasks', {
      method: 'POST',
      body: JSON.stringify({
        ...payload,
        strategic_context_id: contextId,
        context_id: contextId,
      }),
    });
  },

  updateTask(taskId, payload) {
    return apiFetch(`/api/open-space/tasks/${encodeURIComponent(String(taskId || ''))}`, {
      method: 'PUT',
      body: JSON.stringify(payload || {}),
    });
  },

  moveTask(taskId, contextId, status) {
    return apiFetch(`/api/open-space/tasks/${encodeURIComponent(String(taskId || ''))}/move`, {
      method: 'POST',
      body: JSON.stringify({ context_id: contextId, status }),
    });
  },

  listTaskMessages(taskId, contextId) {
    return apiFetch(
      `/api/open-space/tasks/${encodeURIComponent(String(taskId || '_context'))}/messages?context_id=${encodeURIComponent(String(contextId || ''))}`,
    );
  },

  sendTaskMessage(payload) {
    const taskId = payload?.task_id || '_context';
    return apiFetch(`/api/open-space/tasks/${encodeURIComponent(String(taskId))}/messages`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  orchestrate(payload) {
    const body = { ...(payload || {}) };
    if (body.context_id && !body.strategic_context_id) {
      body.strategic_context_id = body.context_id;
    }
    return apiFetch('/api/open-space/orchestrate', { method: 'POST', body: JSON.stringify(body) });
  },

  listProposals(contextId) {
    return apiFetch(`/api/open-space/proposals?context_id=${encodeURIComponent(String(contextId || ''))}`);
  },

  listContextAgents(contextId) {
    return apiFetch(`/api/open-space/context-agents?context_id=${encodeURIComponent(String(contextId || ''))}`);
  },

  acceptProposal(proposalId, payload) {
    return apiFetch(`/api/open-space/proposals/${encodeURIComponent(String(proposalId || ''))}/accept`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  exportTaskJira(taskId, contextId) {
    return apiFetch(
      `/api/open-space/tasks/${encodeURIComponent(String(taskId || ''))}/jira-export?context_id=${encodeURIComponent(String(contextId || ''))}`,
    );
  },

  exportBoardJira(boardId, contextId) {
    return apiFetch(
      `/api/open-space/boards/${encodeURIComponent(String(boardId || ''))}/jira-export?context_id=${encodeURIComponent(String(contextId || ''))}`,
    );
  },
};

export { OpenSpaceService };


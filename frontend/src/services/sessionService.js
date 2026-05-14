import { apiFetch } from './apiClient.js';

const SessionService = {
  list() {
    return apiFetch('/api/sessions');
  },
  get(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}`);
  },
  create(payload) {
    return apiFetch('/api/sessions', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  remove(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}`, { method: 'DELETE' });
  },
  deleteAll() {
    return apiFetch('/api/sessions/delete-all', { method: 'POST' });
  },
  export(sessionId, format) {
    return apiFetch(`/api/sessions/${sessionId}/export?format=${format}`);
  },
  saveSnapshot(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/snapshot`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  getVerdict(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/verdict`);
  },
  getDecisionSummary(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/decision-summary`);
  },
  getMemory(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/memory`);
  },
  updateMemory(sessionId, body) {
    return apiFetch(`/api/sessions/${sessionId}/memory`, {
      method: 'PUT',
      body: JSON.stringify(body),
    });
  },
  rerun(sessionId, body) {
    return apiFetch(`/api/sessions/${sessionId}/rerun`, {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },
  getActionPlan(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/action-plan`);
  },
  generateActionPlan(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/action-plan/generate`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
  updateActionPlan(sessionId, body) {
    return apiFetch(`/api/sessions/${sessionId}/action-plan`, {
      method: 'PUT',
      body: JSON.stringify(body),
    });
  },
  getVotes(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/votes`);
  },
  recomputeVotes(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/votes/recompute`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },
};

export { SessionService };

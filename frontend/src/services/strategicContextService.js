import { apiFetch } from './apiClient.js';

const StrategicContextService = {
  async list(filters = {}, limit = 100) {
    const qs = new URLSearchParams();
    qs.set('limit', String(limit));
    for (const [k, v] of Object.entries(filters || {})) {
      if (v == null) continue;
      const s = String(v).trim();
      if (!s) continue;
      qs.set(k, s);
    }
    return apiFetch(`/api/strategic-contexts?${qs.toString()}`);
  },

  async get(contextId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}`);
  },

  async create(payload) {
    return apiFetch('/api/strategic-contexts', { method: 'POST', body: JSON.stringify(payload || {}) });
  },

  async update(contextId, patch) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}`, {
      method: 'PUT',
      body: JSON.stringify(patch || {}),
    });
  },

  // New ergonomic API (preferred by UI)
  async createContext(data) {
    return StrategicContextService.create(data);
  },

  async updateContext(contextId, data) {
    return StrategicContextService.update(contextId, data);
  },

  async archiveContext(contextId) {
    // Archive is implemented as deterministic status update (no hard delete by default).
    return StrategicContextService.update(contextId, { status: 'abandoned' });
  },

  async deleteContext(contextId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}`, { method: 'DELETE' });
  },

  async linkMemory(contextId, memoryId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/link-memory`, {
      method: 'POST',
      body: JSON.stringify({ memory_id: memoryId }),
    });
  },

  async unlinkMemory(contextId, memoryId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/unlink-memory`, {
      method: 'POST',
      body: JSON.stringify({ memory_id: memoryId }),
    });
  },

  async linkSession(contextId, sessionId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/link-session`, {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId }),
    });
  },

  async unlinkSession(contextId, sessionId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/unlink-session`, {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId }),
    });
  },

  /** Organizational chains inside a strategic context (DecisionRoom API). */
  async listRooms(contextId) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/rooms`);
  },
};

export { StrategicContextService };


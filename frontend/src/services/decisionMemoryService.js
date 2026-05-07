import { apiFetch } from './apiClient.js';

const DecisionMemoryService = {
  async list(limit = 200, filters = {}) {
    const qs = new URLSearchParams();
    qs.set('limit', String(limit));
    for (const [k, v] of Object.entries(filters || {})) {
      if (v == null) continue;
      const s = String(v).trim();
      if (!s) continue;
      qs.set(k, s);
    }
    return apiFetch(`/api/decision-memories?${qs.toString()}`);
  },

  async get(memoryId) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(memoryId))}`);
  },

  async related(memoryId) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(memoryId))}/related`);
  },

  async compact(ids = []) {
    const raw = Array.isArray(ids) ? ids.filter(Boolean).join(',') : String(ids || '');
    return apiFetch(`/api/decision-memories/compact?ids=${encodeURIComponent(raw)}`);
  },

  async compactWithOptions(ids = [], options = {}) {
    const raw = Array.isArray(ids) ? ids.filter(Boolean).join(',') : String(ids || '');
    const qs = new URLSearchParams();
    qs.set('ids', raw);
    if (options.allow_stale) qs.set('allow_stale', '1');
    if (options.expert_override) qs.set('expert_override', '1');
    return apiFetch(`/api/decision-memories/compact?${qs.toString()}`);
  },

  async bySession(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(String(sessionId))}/decision-memory`);
  },

  async confirmForSession(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(String(sessionId))}/decision-memory/confirm`, {
      method: 'POST',
      body: JSON.stringify({}),
    });
  },

  async link(fromMemoryId, toMemoryId, linkType) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(fromMemoryId))}/link`, {
      method: 'POST',
      body: JSON.stringify({ to_memory_id: toMemoryId, link_type: linkType }),
    });
  },

  async lifecycle(memoryId, action, payload = {}) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(memoryId))}/lifecycle`, {
      method: 'POST',
      body: JSON.stringify({ action, ...(payload || {}) }),
    });
  },

  async delete(memoryId) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(memoryId))}`, { method: 'DELETE' });
  },

  async audit(memoryId, limit = 200) {
    return apiFetch(`/api/decision-memories/${encodeURIComponent(String(memoryId))}/audit?limit=${encodeURIComponent(String(limit))}`);
  },

  async search(params = {}) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(params || {})) {
      if (v == null) continue;
      const s = String(v).trim();
      if (!s) continue;
      qs.set(k, s);
    }
    return apiFetch(`/api/decision-memories/search?${qs.toString()}`);
  },

  async similar(params = {}) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(params || {})) {
      if (v == null) continue;
      const s = String(v).trim();
      if (!s) continue;
      qs.set(k, s);
    }
    return apiFetch(`/api/decision-memories/similar?${qs.toString()}`);
  },
};

export { DecisionMemoryService };


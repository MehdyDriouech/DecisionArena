import { apiFetch } from './apiClient.js';

const StrategicContextService = {
  async getActive() {
    return apiFetch('/api/strategic-contexts/active');
  },

  async activate(contextId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/activate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
  },

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

  /** Comparaison read-only entre deux contextes (POST /api/strategic-contexts/compare). */
  async compareContexts(payload) {
    return apiFetch('/api/strategic-contexts/compare', {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
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

  async createRoom(contextId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/rooms`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  /** Timeline workspace (read-only). GET /api/strategic-contexts/{contextId}/timeline */
  async getTimeline(contextId, opts = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const qs = opts.includeLegacy ? '?include_legacy=1' : '';
    return apiFetch(`/api/strategic-contexts/${id}/timeline${qs}`);
  },

  /** Agrégation read-only pour l’aperçu mémoire du contexte (GET /api/strategic-contexts/{id}/memory-overview). */
  async getMemoryOverview(contextId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/memory-overview`);
  },

  /** POST /api/strategic-contexts/{id}/agent-memories/sync — reconstruction idempotente memory.md agents (Expert). */
  async syncAgentMemories(contextId, payload = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/agent-memories/sync`, {
      method: 'POST',
      body: JSON.stringify(payload && typeof payload === 'object' ? payload : {}),
    });
  },

  /** Strategic Narrative (lecture seule persistée). GET /api/strategic-contexts/{id}/narrative */
  async getNarrative(contextId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/narrative`);
  },

  /** Recalcul explicite POST /api/strategic-contexts/{id}/narrative/recompute */
  async recomputeNarrative(contextId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/narrative/recompute`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });
  },

  /** GET /api/strategic-contexts/{id}/memory-governance */
  async getMemoryGovernance(contextId, opts = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const parsed = Number.parseInt(String(opts.limit ?? 180), 10);
    const limit = Number.isFinite(parsed) ? Math.max(20, Math.min(400, parsed)) : 180;
    return apiFetch(`/api/strategic-contexts/${id}/memory-governance?limit=${limit}`);
  },

  /** Beliefs Engine — GET /api/strategic-contexts/{contextId}/beliefs */
  async listBeliefs(contextId, query = {}) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query || {})) {
      if (v == null || v === '') continue;
      qs.set(k, String(v));
    }
    const q = qs.toString();
    return apiFetch(`/api/strategic-contexts/${c}/beliefs${q ? `?${q}` : ''}`);
  },

  /** GET /api/strategic-contexts/{contextId}/agents/{agentId}/beliefs */
  async listBeliefsByAgent(contextId, agentId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/beliefs`);
  },

  async createBelief(contextId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/beliefs`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async updateBelief(contextId, beliefId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const b = encodeURIComponent(String(beliefId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/beliefs/${b}`, {
      method: 'PUT',
      body: JSON.stringify(payload || {}),
    });
  },

  async archiveBelief(contextId, beliefId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const b = encodeURIComponent(String(beliefId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/beliefs/${b}/archive`, {
      method: 'POST',
      body: '{}',
    });
  },

  async deprecateBelief(contextId, beliefId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const b = encodeURIComponent(String(beliefId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/beliefs/${b}/deprecate`, {
      method: 'POST',
      body: '{}',
    });
  },

  /** Global beliefs APIs (strict scoping via context_id). */
  async listBeliefsGlobal(query = {}) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query || {})) {
      if (v == null || v === '') continue;
      qs.set(k, String(v));
    }
    return apiFetch(`/api/beliefs?${qs.toString()}`);
  },

  async getBelief(beliefId, contextId = '') {
    const b = encodeURIComponent(String(beliefId || '').trim());
    const qs = new URLSearchParams();
    if (String(contextId || '').trim()) qs.set('context_id', String(contextId).trim());
    const q = qs.toString();
    return apiFetch(`/api/beliefs/${b}${q ? `?${q}` : ''}`);
  },

  async getBeliefTimeline(beliefId, contextId = '', limit = 400) {
    const b = encodeURIComponent(String(beliefId || '').trim());
    const qs = new URLSearchParams();
    if (String(contextId || '').trim()) qs.set('context_id', String(contextId).trim());
    qs.set('limit', String(limit));
    return apiFetch(`/api/beliefs/${b}/timeline?${qs.toString()}`);
  },

  async getBeliefRelations(beliefId, contextId = '') {
    const b = encodeURIComponent(String(beliefId || '').trim());
    const qs = new URLSearchParams();
    if (String(contextId || '').trim()) qs.set('context_id', String(contextId).trim());
    const q = qs.toString();
    return apiFetch(`/api/beliefs/${b}/relations${q ? `?${q}` : ''}`);
  },

  async getBeliefsRuntime(contextId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/beliefs/runtime?context_id=${c}`);
  },

  /** @deprecated alias de getTimeline */
  async getWorkspaceTimeline(contextId, opts = {}) {
    return StrategicContextService.getTimeline(contextId, opts);
  },

  /** Mémoire markdown locale par agent et par contexte stratégique (GET). */
  async getAgentContextMemory(contextId, agentId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory`);
  },

  async putAgentContextMemory(contextId, agentId, content) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory`, {
      method: 'PUT',
      body: JSON.stringify({ content: String(content ?? '') }),
    });
  },

  async appendAgentContextMemoryNote(contextId, agentId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/append`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async consolidateAgentContextMemory(contextId, agentId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/consolidate`, {
      method: 'POST',
      body: '{}',
    });
  },

  async postAgentContextMemoryRecentNote(contextId, agentId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/recent-note`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async postAgentContextMemoryContradiction(contextId, agentId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/contradiction`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async postAgentContextMemoryDeprecate(contextId, agentId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/deprecate`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async postAgentContextMemoryCompact(contextId, agentId) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/memory/compact`, {
      method: 'POST',
      body: '{}',
    });
  },

  /** Situated Agent Chat — conversation par agent dans un contexte (hors /api/chat/send). */
  async chatWithAgent(contextId, agentId, payload) {
    const c = encodeURIComponent(String(contextId || '').trim());
    const a = encodeURIComponent(String(agentId || '').trim());
    return apiFetch(`/api/strategic-contexts/${c}/agents/${a}/chat`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  /** Memory Compiler — consolidations dérivées par contexte stratégique. */
  async listMemoryCompilations(contextId, query = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query || {})) {
      if (v == null || v === '') continue;
      qs.set(k, String(v));
    }
    const q = qs.toString();
    return apiFetch(`/api/strategic-contexts/${id}/memory-compilations${q ? `?${q}` : ''}`);
  },

  async compileMemory(contextId, payload = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/memory-compilations/compile`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async getMemoryCompilation(contextId, compilationId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const cid = encodeURIComponent(String(compilationId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/memory-compilations/${cid}`);
  },

  async archiveMemoryCompilation(contextId, compilationId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const cid = encodeURIComponent(String(compilationId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/memory-compilations/${cid}/archive`, {
      method: 'POST',
      body: '{}',
    });
  },

  async supersedeMemoryCompilation(contextId, compilationId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const cid = encodeURIComponent(String(compilationId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/memory-compilations/${cid}/supersede`, {
      method: 'POST',
      body: '{}',
    });
  },

  /** Context Snapshots — état stratégique immuable à un instant T. */
  async listContextSnapshots(contextId, query = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query || {})) {
      if (v == null || v === '') continue;
      qs.set(k, String(v));
    }
    const q = qs.toString();
    return apiFetch(`/api/strategic-contexts/${id}/snapshots${q ? `?${q}` : ''}`);
  },

  async createContextSnapshot(contextId, payload = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/snapshots`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },

  async getContextSnapshot(contextId, snapshotId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const sid = encodeURIComponent(String(snapshotId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/snapshots/${sid}`);
  },

  async compareContextSnapshots(contextId, snapshotAId, snapshotBId) {
    const id = encodeURIComponent(String(contextId || '').trim());
    return apiFetch(`/api/strategic-contexts/${id}/snapshots/compare`, {
      method: 'POST',
      body: JSON.stringify({
        snapshot_a_id: String(snapshotAId || '').trim(),
        snapshot_b_id: String(snapshotBId || '').trim(),
      }),
    });
  },

  async getContextSnapshotsLongitudinal(contextId, query = {}) {
    const id = encodeURIComponent(String(contextId || '').trim());
    const qs = new URLSearchParams();
    if (query.limit != null && String(query.limit).trim() !== '') qs.set('limit', String(query.limit));
    const q = qs.toString();
    return apiFetch(`/api/strategic-contexts/${id}/snapshots/longitudinal${q ? `?${q}` : ''}`);
  },
};

export { StrategicContextService };


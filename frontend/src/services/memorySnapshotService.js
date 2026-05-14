import { API_BASE, apiFetch } from './apiClient.js';

async function fetchMarkdown(path, params = {}) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params || {})) {
    if (v == null) continue;
    const s = String(v).trim();
    if (!s) continue;
    qs.set(k, s);
  }
  const url = API_BASE + path + (qs.toString() ? `?${qs.toString()}` : '');
  const res = await fetch(url, { headers: { Accept: 'text/markdown' } });
  if (!res.ok) {
    const txt = await res.text().catch(() => '');
    throw new Error(`HTTP ${res.status}: ${txt || res.statusText}`);
  }
  return res.text();
}

const MemorySnapshotService = {
  async getContextMarkdown(contextId, opts = {}) {
    return fetchMarkdown(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/memory.md`, opts);
  },

  async getRoomMarkdown(roomId, opts = {}) {
    return fetchMarkdown(`/api/decision-rooms/${encodeURIComponent(String(roomId))}/memory.md`, opts);
  },

  // Optional JSON mode for programmatic consumption
  async getContextMarkdownJson(contextId, opts = {}) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/memory.md?${new URLSearchParams(opts).toString()}`, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
  },
};

export { MemorySnapshotService };


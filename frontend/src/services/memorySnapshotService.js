import { API_BASE, apiFetch } from './apiClient.js';

const SUPPORTED_PERSPECTIVES = ['default', 'ceo', 'cto', 'cfo', 'product', 'growth', 'legal'];

function normalizePerspective(value) {
  const v = String(value == null ? '' : value).trim().toLowerCase();
  if (!v) return '';
  return SUPPORTED_PERSPECTIVES.includes(v) ? v : 'default';
}

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

function withPerspective(opts = {}) {
  // Phase 1 — Perspective Snapshots: only forward the param when explicitly
  // set to a non-empty value, so the legacy memory.md route stays unchanged
  // when no operational view is selected.
  const out = { ...opts };
  const p = normalizePerspective(out.perspective);
  if (p && p !== 'default') {
    out.perspective = p;
  } else {
    delete out.perspective;
  }
  return out;
}

const MemorySnapshotService = {
  SUPPORTED_PERSPECTIVES,
  normalizePerspective,

  async getContextMarkdown(contextId, opts = {}) {
    return fetchMarkdown(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/memory.md`, withPerspective(opts));
  },

  async getRoomMarkdown(roomId, opts = {}) {
    return fetchMarkdown(`/api/decision-rooms/${encodeURIComponent(String(roomId))}/memory.md`, withPerspective(opts));
  },

  // Optional JSON mode for programmatic consumption
  async getContextMarkdownJson(contextId, opts = {}) {
    return apiFetch(`/api/strategic-contexts/${encodeURIComponent(String(contextId))}/memory.md?${new URLSearchParams(withPerspective(opts)).toString()}`, {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
  },
};

export { MemorySnapshotService };


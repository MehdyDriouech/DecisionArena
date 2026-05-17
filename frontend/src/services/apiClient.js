// API_BASE is resolved once at module load from window.location (no build-time config).
// logService.js imports API_BASE from here (one-way dependency: logService → apiClient).
// apiClient.js intentionally accesses LogService through window.DecisionArena.services
// rather than a direct import to prevent a circular dependency
// (apiClient ← apiFetch ← every feature, logService ← apiClient would form a cycle).

/**
 * Derive the backend public URL from the current page location.
 * - Prod at domain root (/frontend/…): {origin}/backend/public
 * - Local subfolder (/decision-room-ai/frontend/…): {origin}/decision-room-ai/backend/public
 */
function resolveApiBase() {
  const { origin, pathname } = window.location;
  const frontendIdx = pathname.indexOf('/frontend');
  const appRoot = frontendIdx >= 0 ? pathname.slice(0, frontendIdx) : '';
  const base = `${origin}${appRoot}/backend/public`;
  return base.replace(/([^:]\/)\/+/g, '$1');
}

const API_BASE = resolveApiBase();

async function apiFetch(path, options = {}) {
  const res = await fetch(API_BASE + path, {
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    ...options,
  });
  if (!res.ok) {
    let detail = '';
    try {
      const data = await res.clone().json();
      detail = data?.message || data?.error || JSON.stringify(data);
    } catch (_) {
      detail = await res.text().catch(() => '');
    }
    const msg = detail ? `${detail}` : (res.statusText || 'Request failed');
    // LogService is accessed via window to avoid a circular ES module dependency.
    // Optional chaining ensures graceful no-op if LogService is not yet registered.
    try { window.DecisionArena?.services?.LogService?.logApiError?.(path, `HTTP ${res.status}: ${msg}`); } catch (_) {}
    throw new Error(`HTTP ${res.status}: ${msg}`);
  }
  return res.json();
}

export {
  API_BASE,
  apiFetch,
};

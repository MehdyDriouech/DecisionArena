// API_BASE is a static string constant — fully resolved at module evaluation time.
// logService.js imports API_BASE from here (one-way dependency: logService → apiClient).
// apiClient.js intentionally accesses LogService through window.DecisionArena.services
// rather than a direct import to prevent a circular dependency
// (apiClient ← apiFetch ← every feature, logService ← apiClient would form a cycle).
const API_BASE = 'http://localhost/decision-room-ai/backend/public';

async function apiFetch(path, options = {}) {
  const res = await fetch(API_BASE + path, {
    headers: { 'Content-Type': 'application/json' },
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

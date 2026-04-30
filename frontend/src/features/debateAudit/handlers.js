/**
 * Debate Quality Audit — event handlers.
 *
 * Actions registered:
 *   run-debate-audit — fetch audit from backend and update state
 */

import { registerAction } from '../../core/events.js';

function registerDebateAuditHandlers() {
  registerAction('run-debate-audit', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;

    const arena    = window.DecisionArena;
    const state    = arena.store.state;
    const apiFetch = arena.services.apiFetch;
    const render   = () => arena.render();

    state.auditData    = null;
    state.auditLoading = true;
    state.auditError   = null;
    render();

    try {
      const json = await apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/audit`);
      state.auditData = json?.audit ?? null;
      const hl = json?.audit?.highlights;
      if (state.sessionHistory?.session?.id === sessionId && Array.isArray(hl)) {
        state.sessionHistory.panelHighlights = hl;
      }
    } catch (err) {
      console.error('[debateAudit] fetch error', err);
      state.auditData  = null;
      state.auditError = err?.message || String(err);
    } finally {
      state.auditLoading = false;
      render();
    }
  });
}

export { registerDebateAuditHandlers };

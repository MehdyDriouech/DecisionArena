/**
 * Graph View — event handlers.
 *
 * Actions registered:
 *   load-graph-data — fetch graph from backend and update state
 */

import { registerAction } from '../../core/events.js';

function registerGraphViewHandlers() {
  registerAction('load-graph-data', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;

    const arena    = window.DecisionArena;
    const state    = arena.store.state;
    const apiFetch = arena.services.apiFetch;
    const render   = () => arena.render();

    state.graphData    = null;
    state.graphLoading = true;
    state.graphError   = null;
    render();

    try {
      const json = await apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/graph`);

      state.graphData = {
        nodes:     json.nodes     ?? [],
        edges:     json.edges     ?? [],
        arguments: json.arguments ?? [],
        positions: json.positions ?? [],
      };
    } catch (err) {
      console.error('[graphView] fetch error', err);
      state.graphData  = null;
      state.graphError = err?.message || String(err);
    } finally {
      state.graphLoading = false;
      render();
    }
  });
}

export { registerGraphViewHandlers };

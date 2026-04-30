import { registerAction } from '../../core/events.js';

function registerArgumentHeatmapHandlers() {
  registerAction('load-argument-heatmap', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;
    const arena    = window.DecisionArena;
    const state    = arena.store.state;
    const apiFetch = arena.services.apiFetch;
    const render   = () => arena.render();
    state.heatmapData    = null;
    state.heatmapLoading = true;
    state.heatmapError   = null;
    render();
    try {
      const json = await apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/argument-heatmap`);
      state.heatmapData = json;
    } catch (err) {
      console.error('[heatmap] error', err);
      state.heatmapData  = null;
      state.heatmapError = err?.message || String(err);
    } finally {
      state.heatmapLoading = false;
      render();
    }
  });

  registerAction('set-heatmap-filter', ({ element }) => {
    const arena  = window.DecisionArena;
    arena.store.state.heatmapFilter = element?.dataset?.filter ?? 'all';
    arena.render();
  });
}

export { registerArgumentHeatmapHandlers };

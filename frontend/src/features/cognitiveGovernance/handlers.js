/**
 * Cognitive Governance — handlers (catalogue invariants, lecture seule).
 */

import { registerAction } from '../../core/events.js';

function getCtx() {
  const arena = window.DecisionArena;
  return {
    state: arena.store.state,
    render: arena.render,
    apiFetch: arena.services.apiFetch,
  };
}

function registerCognitiveGovernanceHandlers() {
  registerAction('load-cognitive-governance', async () => {
    const { state, render, apiFetch } = getCtx();
    const cg = state.cognitiveGovernance || (state.cognitiveGovernance = {});
    cg.loading = true;
    cg.error = null;
    render();
    try {
      const catalog = await apiFetch('/api/cognitive-governance');
      cg.catalog = catalog && typeof catalog === 'object' ? catalog : null;
      cg.error = null;
    } catch (err) {
      console.error('[cognitive-governance]', err);
      cg.error = err?.message || String(err);
      cg.catalog = null;
    } finally {
      cg.loading = false;
      render();
    }
  });
}

export { registerCognitiveGovernanceHandlers };

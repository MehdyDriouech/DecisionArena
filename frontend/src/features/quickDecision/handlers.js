/* Quick Decision feature — action handler */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:    a.store.state,
    render:   () => a.render?.(),
    apiFetch: a.services.apiFetch,
  };
}

function registerQuickDecisionHandlers() {
  registerAction('run-quick-decision', async () => {
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.qdRunning = true;
    state.qdResults = null;
    state.error     = null;
    render();

    try {
      const result = await apiFetch('/api/quick-decision/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:        session.id,
          objective:         session.initial_prompt || session.idea || session.title || '',
          selected_agents:   session.selected_agents || ['pm', 'architect', 'critic'],
          force_disagreement: !!session.force_disagreement,
        }),
      });
      state.qdResults = result;
    } catch (err) {
      state.error = 'Quick Decision failed: ' + err.message;
    } finally {
      state.qdRunning = false;
      render();
    }
  });
}

export { registerQuickDecisionHandlers };

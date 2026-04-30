import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:             a.store.state,
    render:            () => a.render?.(),
    navigate:          (v) => a.router.navigate(v),
    apiFetch:          a.services.apiFetch,
    SessionService:    a.services.SessionService,
    ContextDocService: a.services.ContextDocService,
  };
}

function registerJuryHandlers() {
  registerAction('open-jury', async ({ element }) => {
    const { state, navigate, SessionService, ContextDocService } = getCtx();
    const sessionId = element.dataset.sessionId;
    try {
      if (!state.currentSession || state.currentSession.id !== sessionId) {
        const data = await SessionService.get(sessionId);
        state.currentSession    = data.session || data;
        state.currentContextDoc = null;
        state.currentContextDoc = await ContextDocService.loadContextDoc(sessionId);
      }
      state.juryResults   = null;
      state.juryRunning   = false;
      state.heatmapData   = null;
      state.replayEvents  = null;
      state.auditData     = null;
      navigate('jury');
    } catch (err) {
      const { state: s, render } = getCtx();
      s.error = 'Failed to open Jury: ' + err.message;
      render();
    }
  });

  registerAction('run-jury', async () => {
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.juryRunning = true;
    state.juryResults = null;
    state.error       = null;
    render();

    try {
      const result = await apiFetch('/api/jury/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:         session.id,
          objective:          session.initial_prompt || session.idea || session.title || '',
          selected_agents:    session.selected_agents || [],
          rounds:             session.rounds || 3,
          force_disagreement: session.force_disagreement ?? true,
          decision_threshold: session.decision_threshold ?? 0.55,
        }),
      });
      state.juryResults = result;
    } catch (err) {
      state.error = 'Jury failed: ' + err.message;
    } finally {
      state.juryRunning = false;
      render();
    }
  });
}

export { registerJuryHandlers };

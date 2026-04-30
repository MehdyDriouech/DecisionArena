/* Decision Room feature — action handlers */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:          a.store.state,
    render:         () => a.render?.(),
    navigate:       (v) => a.router.navigate(v),
    apiFetch:       a.services.apiFetch,
    SessionService: a.services.SessionService,
    ContextDocService: a.services.ContextDocService,
  };
}

async function runDecisionRoom() {
  const { state, render, apiFetch } = getCtx();
  const session = state.currentSession;
  if (!session) return;

  state.drRunning = true;
  state.drResults = null;
  state.error     = null;
  render();

  try {
    const result = await apiFetch('/api/decision-room/run', {
      method: 'POST',
      body: JSON.stringify({
        session_id:      session.id,
        objective:       session.initial_prompt || session.idea || session.title || '',
        selected_agents: session.selected_agents || [],
        rounds:          session.rounds || 3,
      }),
    });
    state.drResults = result;
  } catch (err) {
    state.error = 'Decision Room failed: ' + err.message;
  } finally {
    state.drRunning = false;
    render();
  }
}

function registerDecisionRoomHandlers() {
  registerAction('open-decision-room', async ({ element }) => {
    const { state, navigate, SessionService, ContextDocService } = getCtx();
    const sessionId = element.dataset.sessionId;
    try {
      if (!state.currentSession || state.currentSession.id !== sessionId) {
        const data = await SessionService.get(sessionId);
        state.currentSession    = data.session || data;
        state.currentContextDoc = null;
        state.currentContextDoc = await ContextDocService.loadContextDoc(sessionId);
      }
      state.drResults        = null;
      state.followUpMessages = [];
      navigate('decision-room');
    } catch (err) {
      const { state: s, render } = getCtx();
      s.error = 'Failed to open Decision Room: ' + err.message;
      render();
    }
  });

  registerAction('run-decision-room', () => runDecisionRoom());

  registerAction('load-vote-explanation', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;

    state.voteExplanation        = null;
    state.voteExplanationLoading = true;
    state.voteExplanationError   = null;
    render();

    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/votes/explanation`);
      state.voteExplanation = result;
    } catch (err) {
      console.error('[voteExplanation] fetch error', err);
      state.voteExplanation      = null;
      state.voteExplanationError = err?.message || String(err);
    } finally {
      state.voteExplanationLoading = false;
      render();
    }
  });

  registerAction('recompute-decision', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/votes/recompute`, { method: 'POST' });
      const decision = result.automatic_decision ?? result.decision ?? null;
      const votes    = result.votes ?? null;
      if (state.sessionHistory) {
        if (decision !== null) state.sessionHistory.automatic_decision = decision;
        if (votes !== null)    state.sessionHistory.votes = votes;
      }
      if (state.drResults) {
        if (decision !== null) state.drResults.automatic_decision = decision;
        if (votes !== null)    state.drResults.votes = votes;
      }
      if (state.confrontationResults) {
        if (decision !== null) state.confrontationResults.automatic_decision = decision;
        if (votes !== null)    state.confrontationResults.votes = votes;
      }
      state.voteExplanation      = null;
      state.voteExplanationError = null;
      state.lastRecomputeThreshold = result.threshold_used != null
        ? { sessionId, threshold: result.threshold_used }
        : null;
      render();
    } catch (err) {
      state.error = err.message;
      render();
    }
  });
}

/* Expose runDecisionRoom so newSession/handlers.js can call it after navigation */
window.DecisionArena = window.DecisionArena || {};
window.DecisionArena._runDecisionRoom = runDecisionRoom;

export { registerDecisionRoomHandlers, runDecisionRoom };

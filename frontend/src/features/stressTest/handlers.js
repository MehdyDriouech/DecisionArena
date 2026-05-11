/* Stress Test feature — action handler */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:          a.store.state,
    render:         () => a.render?.(),
    ChatService:    a.services.ChatService,
    SessionService: a.services.SessionService,
  };
}

function registerStressTestHandlers() {
  registerAction('run-stress-test', async () => {
    const { state, render, ChatService, SessionService } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.currentSession = { ...session, status: 'running' };
    const runningIndex = (state.sessions || []).findIndex((s) => String(s.id) === String(session.id));
    if (runningIndex >= 0) {
      state.sessions[runningIndex] = { ...state.sessions[runningIndex], status: 'running' };
    }
    state.stRunning = true;
    state.stResults = null;
    state.error     = null;
    render();

    try {
      const result = await ChatService.runStressTest({
        session_id:        session.id,
        objective:         session.initial_prompt || session.title || '',
        selected_agents:   session.selected_agents || ['critic', 'architect', 'pm', 'ux-expert', 'synthesizer'],
        rounds:            session.rounds || 2,
        force_disagreement: true,
      });
      state.stResults = result;
      try {
        const full = await SessionService.get(session.id);
        const refreshed = full?.session || null;
        if (refreshed) {
          state.currentSession = refreshed;
          const idx = (state.sessions || []).findIndex((s) => String(s.id) === String(refreshed.id));
          if (idx >= 0) state.sessions[idx] = refreshed;
        } else {
          state.currentSession = { ...state.currentSession, status: 'completed' };
        }
      } catch (_) {
        state.currentSession = { ...state.currentSession, status: 'completed' };
      }
    } catch (err) {
      state.error = 'Stress Test failed: ' + err.message;
      state.currentSession = { ...state.currentSession, status: 'draft' };
      const idx = (state.sessions || []).findIndex((s) => String(s.id) === String(session.id));
      if (idx >= 0) state.sessions[idx] = { ...state.sessions[idx], status: 'draft' };
    } finally {
      state.stRunning = false;
      render();
    }
  });
}

export { registerStressTestHandlers };

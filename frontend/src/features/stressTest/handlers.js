/* Stress Test feature — action handler */
import { registerAction } from '../../core/events.js';
import {
  ensureRunProgressEntry,
  updateRunProgressEntry,
  startRunProgressPolling,
} from '../../services/runProgressService.js';
import { finalizeLiveRunAfterTerminalPoll } from '../../utils/liveRunCompletion.js';

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
    state._liveRunStaleTicks = 0;
    state.stResults = null;
    state.error     = null;
    state.runProgress = {
      session_id: session.id,
      mode: 'stress-test',
      status: 'running',
      started_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      elapsed_seconds: 0,
      progress: {
        percent: 1,
        current_round: 0,
        total_rounds: Number(session.rounds || 2),
        current_phase: 'session_started',
        current_phase_label: 'Session started',
        estimated: true,
      },
      events: [],
      last_error: null,
    };
    ensureRunProgressEntry(state, session.id, state.runProgress);
    updateRunProgressEntry(state, session.id, {
      data: state.runProgress,
      loading: true,
      error: null,
      pollActive: true,
      pollStartedAt: new Date().toISOString(),
    });
    state.runProgressPolling = { active: true, intervalMs: 1500, error: null };
    render();

    const localTicker = setInterval(() => {
      const { state: s, render: r } = getCtx();
      if (!s.stRunning || s.view !== 'stress-test') {
        clearInterval(localTicker);
        return;
      }
      if (s.runProgress?.started_at) {
        const startedMs = new Date(s.runProgress.started_at).getTime();
        if (Number.isFinite(startedMs)) {
          const elapsed = Math.max(0, Math.floor((Date.now() - startedMs) / 1000));
          s.runProgress.elapsed_seconds = Math.max(Number(s.runProgress.elapsed_seconds || 0), elapsed);
          const entry = s.runProgressBySessionId?.[session.id];
          if (entry?.data) {
            entry.data.elapsed_seconds = Math.max(Number(entry.data.elapsed_seconds || 0), elapsed);
          }
        }
      }
      if (
        String(s.runProgress?.status || '').toLowerCase() === 'completed'
        && s.stRunning
        && String(s.currentSession?.id || '') === String(session.id)
      ) {
        s._liveRunStaleTicks = (s._liveRunStaleTicks || 0) + 1;
        if (s._liveRunStaleTicks >= 2) {
          s._liveRunStaleTicks = 0;
          void finalizeLiveRunAfterTerminalPoll({
            state: s,
            sessionId: session.id,
            mode: 'stress-test',
            SessionService,
            render: r,
            payload: s.runProgress,
          });
        }
      } else {
        s._liveRunStaleTicks = 0;
      }
      try { r(); } catch (_) {}
    }, 1000);

    const stopPolling = startRunProgressPolling({
      sessionId: session.id,
      state,
      intervalMs: 1500,
      fetchRunStatus: (sessionId, signal) => SessionService.getRunStatus(sessionId, { signal }),
      shouldContinue: () => {
        const { state: s } = getCtx();
        return s.stRunning
          && s.view === 'stress-test'
          && String(s.currentSession?.id || '') === String(session.id);
      },
      onTick: (payload, pollErr, meta) => {
        const { state: s, render: r } = getCtx();
        if (payload) {
          s.runProgress = payload;
          s.runProgressPolling = { active: !meta?.terminal, intervalMs: 1500, error: null, lastUpdateAt: Date.now() };
        } else if (pollErr) {
          s.runProgressPolling = {
            active: true,
            intervalMs: 1500,
            error: pollErr?.message || String(pollErr),
            lastUpdateAt: Date.now(),
          };
        }
        if (meta?.terminal) {
          const st = String(payload?.status || '').toLowerCase();
          if (st === 'completed') {
            void finalizeLiveRunAfterTerminalPoll({
              state: s,
              sessionId: session.id,
              mode: 'stress-test',
              SessionService,
              render: r,
              payload,
            });
          } else {
            s.stRunning = false;
          }
          try { r(); } catch (_) {}
          return;
        }
        try { r(); } catch (_) {}
      },
    });

    try {
      const result = await ChatService.runStressTest({
        session_id:        session.id,
        objective:         session.initial_prompt || session.title || '',
        selected_agents:   session.selected_agents || ['critic', 'architect', 'pm', 'ux-expert', 'synthesizer'],
        rounds:            session.rounds || 2,
        force_disagreement: true,
      });
      state.stResults = result;
      await finalizeLiveRunAfterTerminalPoll({
        state,
        sessionId: session.id,
        mode: 'stress-test',
        SessionService,
        render,
        payload: { status: 'completed', session_id: session.id },
      });
    } catch (err) {
      state.error = 'Stress Test failed: ' + err.message;
      state.currentSession = { ...state.currentSession, status: 'draft' };
      const idx = (state.sessions || []).findIndex((s) => String(s.id) === String(session.id));
      if (idx >= 0) state.sessions[idx] = { ...state.sessions[idx], status: 'draft' };
    } finally {
      stopPolling();
      clearInterval(localTicker);
      state.runProgressPolling = { active: false, intervalMs: 1500, error: null, lastUpdateAt: Date.now() };
      updateRunProgressEntry(state, session.id, { loading: false, pollActive: false });
      state.stRunning = false;
      render();
    }
  });
}

export { registerStressTestHandlers };

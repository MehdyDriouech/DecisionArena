/* Confrontation feature — action handlers */
import { registerAction } from '../../core/events.js';
import {
  ensureRunProgressEntry,
  updateRunProgressEntry,
  startRunProgressPolling,
} from '../../services/runProgressService.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:    a.store.state,
    render:   () => a.render?.(),
    apiFetch: a.services.apiFetch,
    SessionService: a.services.SessionService,
  };
}

function isTerminalStatus(status) {
  return ['completed', 'failed', 'blocked'].includes(String(status || '').toLowerCase());
}

function registerConfrontationHandlers() {
  registerAction('run-confrontation', async () => {
    const { state, render, apiFetch, SessionService } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.confrontationRunning  = true;
    state.confrontationResults  = null;
    state.error                 = null;
    state.runProgress = {
      session_id: session.id,
      mode: 'confrontation',
      status: 'running',
      started_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      elapsed_seconds: 0,
      progress: {
        percent: 1,
        current_round: 0,
        total_rounds: Number(session.cf_rounds || 3),
        current_phase: 'session_started',
        current_phase_label: 'Session démarrée',
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
      if (!s.confrontationRunning || s.view !== 'confrontation') {
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
      r();
    }, 1000);

    const stopPolling = startRunProgressPolling({
      sessionId: session.id,
      state,
      intervalMs: 1500,
      fetchRunStatus: (sessionId, signal) => SessionService.getRunStatus(sessionId, { signal }),
      shouldContinue: () => {
        const { state: s } = getCtx();
        return s.confrontationRunning
          && s.view === 'confrontation'
          && String(s.currentSession?.id || '') === String(session.id);
      },
      onTick: (payload, pollErr, meta) => {
        const { state: s, render: r } = getCtx();
        if (payload) {
          s.runProgress = payload;
          s.runProgressPolling = {
            active: !meta?.terminal,
            intervalMs: 1500,
            error: null,
            lastUpdateAt: Date.now(),
          };
        } else if (pollErr) {
          s.runProgressPolling = {
            active: true,
            intervalMs: 1500,
            error: pollErr?.message || String(pollErr),
            lastUpdateAt: Date.now(),
          };
        }
        r();
      },
    });

    try {
      const blueTeam         = session._blueTeam    || [];
      const redTeam          = session._redTeam     || [];
      const selectedAgents   = (session.selected_agents || []).filter((a) => a !== 'synthesizer');
      const includeSynthesis = session._includeSynthesis !== false;

      const result = await apiFetch('/api/confrontation/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:        session.id,
          objective:         session.idea || session.initial_prompt || session.title || '',
          selected_agents:   selectedAgents.length > 0 ? selectedAgents : [...blueTeam, ...redTeam],
          blue_team:         blueTeam,
          red_team:          redTeam,
          include_synthesis: includeSynthesis,
          final_synthesis:   includeSynthesis,
          rounds:            session.cf_rounds            || 3,
          interaction_style: session.cf_interaction_style || 'sequential',
          reply_policy:      session.cf_reply_policy      || 'all-agents-reply',
        }),
      });
      state.confrontationResults = result;
    } catch (err) {
      state.error = 'Confrontation failed: ' + err.message;
    } finally {
      stopPolling();
      clearInterval(localTicker);
      state.runProgressPolling = { active: false, intervalMs: 1500, error: null, lastUpdateAt: Date.now() };
      updateRunProgressEntry(state, session.id, { loading: false, pollActive: false });
      state.confrontationRunning = false;
      render();
    }
  });
}

export { registerConfrontationHandlers };

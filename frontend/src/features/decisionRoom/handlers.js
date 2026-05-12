/* Decision Room feature — action handlers */
import { registerAction } from '../../core/events.js';
import {
  ensureRunProgressEntry,
  updateRunProgressEntry,
  startRunProgressPolling,
} from '../../services/runProgressService.js';

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
  const { state, render, apiFetch, SessionService } = getCtx();
  const session = state.currentSession;
  if (!session) return;

  state.drRunning = true;
  state.drResults = null;
  state.drAutoRetryBanner = null;
  state.error     = null;
  state.runProgress = {
    session_id: session.id,
    mode: 'decision-room',
    status: 'running',
    started_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    elapsed_seconds: 0,
    progress: {
      percent: 1,
      current_round: 0,
      total_rounds: Number(session.rounds || 3),
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
    if (!s.drRunning || s.view !== 'decision-room') {
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
      return s.drRunning
        && s.view === 'decision-room'
        && String(s.currentSession?.id || '') === String(session.id);
    },
    onTick: (payload, pollErr, meta) => {
      const { state: s, render: r } = getCtx();
      const runStatus = payload?.run_status || null;
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
      if (runStatus?.phase === 'auto_retry') {
        s.drAutoRetryBanner = 'running';
      } else if (runStatus?.phase === 'auto_retry_complete') {
        s.drAutoRetryBanner = 'complete';
      }
      r();
    },
  });

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
    stopPolling();
    clearInterval(localTicker);
    state.runProgressPolling = { active: false, intervalMs: 1500, error: null, lastUpdateAt: Date.now() };
    updateRunProgressEntry(state, session.id, { loading: false, pollActive: false });
    state.drAutoRetryBanner = null;
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
        state.sessionHistory.raw_decision = result.raw_decision ?? state.sessionHistory.raw_decision;
        state.sessionHistory.adjusted_decision = result.adjusted_decision ?? state.sessionHistory.adjusted_decision;
        state.sessionHistory.context_quality = result.context_quality ?? state.sessionHistory.context_quality;
        state.sessionHistory.reliability_cap = result.reliability_cap ?? state.sessionHistory.reliability_cap;
        state.sessionHistory.false_consensus_risk = result.false_consensus_risk ?? state.sessionHistory.false_consensus_risk;
        state.sessionHistory.false_consensus = result.false_consensus ?? state.sessionHistory.false_consensus;
        state.sessionHistory.reliability_warnings = result.reliability_warnings ?? state.sessionHistory.reliability_warnings;
        state.sessionHistory.decision_reliability_summary = result.decision_reliability_summary ?? state.sessionHistory.decision_reliability_summary;
        state.sessionHistory.context_clarification = result.context_clarification ?? state.sessionHistory.context_clarification;
      }
      if (state.drResults) {
        if (decision !== null) state.drResults.automatic_decision = decision;
        if (votes !== null)    state.drResults.votes = votes;
        state.drResults.raw_decision = result.raw_decision ?? state.drResults.raw_decision;
        state.drResults.adjusted_decision = result.adjusted_decision ?? state.drResults.adjusted_decision;
        state.drResults.context_quality = result.context_quality ?? state.drResults.context_quality;
        state.drResults.reliability_cap = result.reliability_cap ?? state.drResults.reliability_cap;
        state.drResults.false_consensus_risk = result.false_consensus_risk ?? state.drResults.false_consensus_risk;
        state.drResults.false_consensus = result.false_consensus ?? state.drResults.false_consensus;
        state.drResults.reliability_warnings = result.reliability_warnings ?? state.drResults.reliability_warnings;
        state.drResults.decision_reliability_summary = result.decision_reliability_summary ?? state.drResults.decision_reliability_summary;
        state.drResults.context_clarification = result.context_clarification ?? state.drResults.context_clarification;
      }
      if (state.confrontationResults) {
        if (decision !== null) state.confrontationResults.automatic_decision = decision;
        if (votes !== null)    state.confrontationResults.votes = votes;
        state.confrontationResults.raw_decision = result.raw_decision ?? state.confrontationResults.raw_decision;
        state.confrontationResults.adjusted_decision = result.adjusted_decision ?? state.confrontationResults.adjusted_decision;
        state.confrontationResults.context_quality = result.context_quality ?? state.confrontationResults.context_quality;
        state.confrontationResults.reliability_cap = result.reliability_cap ?? state.confrontationResults.reliability_cap;
        state.confrontationResults.false_consensus_risk = result.false_consensus_risk ?? state.confrontationResults.false_consensus_risk;
        state.confrontationResults.false_consensus = result.false_consensus ?? state.confrontationResults.false_consensus;
        state.confrontationResults.reliability_warnings = result.reliability_warnings ?? state.confrontationResults.reliability_warnings;
        state.confrontationResults.decision_reliability_summary = result.decision_reliability_summary ?? state.confrontationResults.decision_reliability_summary;
        state.confrontationResults.context_clarification = result.context_clarification ?? state.confrontationResults.context_clarification;
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

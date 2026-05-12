import { registerAction } from '../../core/events.js';
import { withProviderRuntime } from '../../core/providerRuntime.js';
import { isConfirmationConfirmed, requestConfirmation } from '../../utils/confirmationUi.js';
import {
  ensureRunProgressEntry,
  updateRunProgressEntry,
  startRunProgressPolling,
} from '../../services/runProgressService.js';

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
      state.juryResults            = null;
      state.juryRunning            = false;
      state.heatmapData            = null;
      state.replayEvents           = null;
      state.auditData              = null;
      state.juryAdversarialConfig  = state.juryAdversarialConfig ?? {};
      navigate('jury');
    } catch (err) {
      const { state: s, render } = getCtx();
      s.error = 'Failed to open Jury: ' + err.message;
      render();
    }
  });

  registerAction('run-jury', async () => {
    const { state, render, apiFetch, SessionService } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.juryRunning = true;
    state.juryResults = null;
    state.juryAutoRetryBanner = null;
    state.error       = null;
    state.runProgress = {
      session_id: session.id,
      mode: 'jury',
      status: 'running',
      started_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      elapsed_seconds: 0,
      progress: {
        percent: 1,
        current_round: 0,
        total_rounds: Number(session.rounds || 3),
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
    try { render(); } catch (_) { /* render errors must not block the jury run */ }

    const localTicker = setInterval(() => {
      const { state: s, render: r } = getCtx();
      if (!s.juryRunning || s.view !== 'jury') {
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
      try { r(); } catch (_) {}
    }, 1000);

    const stopPolling = startRunProgressPolling({
      sessionId: session.id,
      state,
      intervalMs: 1500,
      fetchRunStatus: (sessionId, signal) => SessionService.getRunStatus(sessionId, { signal }),
      shouldContinue: () => {
        const { state: s } = getCtx();
        return s.juryRunning
          && s.view === 'jury'
          && String(s.currentSession?.id || '') === String(session.id);
      },
      onTick: (payload, pollErr, meta) => {
        const { state: s, render: r } = getCtx();
        const runStatus = payload?.run_status || payload?.progress || null;
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
        if (runStatus?.phase === 'auto_retry' || runStatus?.current_phase === 'auto_retry') {
          s.juryAutoRetryBanner = 'running';
        } else if (runStatus?.phase === 'auto_retry_complete' || runStatus?.current_phase === 'auto_retry_complete') {
          s.juryAutoRetryBanner = 'complete';
        }
        try { r(); } catch (_) {}
      },
    });

    try {
      const adversarialCfg = state.juryAdversarialConfig ?? {};
      const minorityReporter = adversarialCfg.minority_reporter_agent_id || null;
      const result = await apiFetch('/api/jury/run', {
        method: 'POST',
        body: JSON.stringify(
          withProviderRuntime({
            session_id: session.id,
            objective: session.initial_prompt || session.idea || session.title || '',
            selected_agents: session.selected_agents || [],
            rounds: session.rounds || 3,
            force_disagreement: session.force_disagreement ?? true,
            decision_threshold: session.decision_threshold ?? 0.55,
            jury_adversarial_enabled: adversarialCfg.jury_adversarial_enabled !== false,
            min_challenges_per_round: adversarialCfg.min_challenges_per_round ?? 2,
            force_agent_references: adversarialCfg.force_agent_references !== false,
            require_minority_report: adversarialCfg.require_minority_report !== false,
            block_weak_debate_decision: adversarialCfg.block_weak_debate_decision !== false,
            debate_quality_min_score: adversarialCfg.debate_quality_min_score ?? 50,
            false_consensus_blocks_confident_decision:
              adversarialCfg.false_consensus_blocks_confident_decision !== false,
            ...(minorityReporter ? { minority_reporter_agent_id: minorityReporter } : {}),
          })
        ),
      });
      state.juryResults = result;
    } catch (err) {
      state.error = 'Jury failed: ' + err.message;
    } finally {
      stopPolling();
      clearInterval(localTicker);
      state.runProgressPolling = { active: false, intervalMs: 1500, error: null, lastUpdateAt: Date.now() };
      updateRunProgressEntry(state, session.id, { loading: false, pollActive: false });
      state.juryAutoRetryBanner = null;
      state.juryRunning = false;
      try { render(); } catch (_) { /* prevent render crash from hiding results */ }
    }
  });

  // Rerun with stronger adversarial settings
  registerAction('rerun-jury-strong', async (ctx = {}) => {
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    if (!isConfirmationConfirmed(ctx)) {
      requestConfirmation(state, {
        id: `rerun-jury-strong:${session.id}`,
        mode: 'modal',
        tone: 'warning',
        title: 'Relancer le jury avec debat renforce ?',
        body: 'Une nouvelle analyse sera creee. La session originale est conservee.',
        expertBody: 'Le nombre de rounds peut augmenter et les parametres adversariaux sont renforces.',
        confirmLabel: 'Relancer le jury',
        action: 'rerun-jury-strong',
      });
      render();
      return;
    }
    

    state.juryRunning = true;
    state.juryResults = null;
    state.error       = null;
    try { render(); } catch (_) { /* render errors must not block the jury run */ }

    try {
      const currentRounds = Math.min(5, (session.rounds || 3) + 1);
      const result = await apiFetch('/api/jury/run', {
        method: 'POST',
        body: JSON.stringify(
          withProviderRuntime({
            session_id: session.id,
            objective: session.initial_prompt || session.idea || session.title || '',
            selected_agents: session.selected_agents || [],
            rounds: currentRounds,
            force_disagreement: true,
            decision_threshold: session.decision_threshold ?? 0.55,
            jury_adversarial_enabled: true,
            min_challenges_per_round: 3,
            force_agent_references: true,
            require_minority_report: true,
            block_weak_debate_decision: true,
            debate_quality_min_score: 50,
            false_consensus_blocks_confident_decision: true,
          })
        ),
      });
      state.juryResults = result;
    } catch (err) {
      state.error = 'Rerun failed: ' + err.message;
    } finally {
      state.juryRunning = false;
      try { render(); } catch (_) { /* prevent render crash from hiding results */ }
    }
  });

  // Toggle adversarial checkbox option (expert mode)
  registerAction('toggle-jury-adversarial-opt', ({ element }) => {
    const { state, render } = getCtx();
    const key = element.dataset.key;
    if (!key) return;
    state.juryAdversarialConfig = state.juryAdversarialConfig ?? {};
    state.juryAdversarialConfig[key] = element.checked;
    render();
  });

  // Set adversarial numeric option (expert mode)
  registerAction('set-jury-adversarial-num', ({ element }) => {
    const { state, render } = getCtx();
    const key = element.dataset.key;
    if (!key) return;
    const val = parseInt(element.value, 10);
    if (!isNaN(val)) {
      state.juryAdversarialConfig = state.juryAdversarialConfig ?? {};
      state.juryAdversarialConfig[key] = val;
      render();
    }
  });

  // Set adversarial string option (expert mode — e.g. minority_reporter_agent_id dropdown)
  registerAction('set-jury-adversarial-str', ({ element }) => {
    const { state, render } = getCtx();
    const key = element.dataset.key;
    if (!key) return;
    state.juryAdversarialConfig = state.juryAdversarialConfig ?? {};
    // Empty string means "auto" — we store null so the backend uses auto-detection
    state.juryAdversarialConfig[key] = element.value.trim() || null;
    render();
  });
}

export { registerJuryHandlers };

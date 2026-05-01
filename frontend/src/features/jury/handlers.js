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
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.juryRunning = true;
    state.juryResults = null;
    state.error       = null;
    try { render(); } catch (_) { /* render errors must not block the jury run */ }

    try {
      const adversarialCfg = state.juryAdversarialConfig ?? {};
      const minorityReporter = adversarialCfg.minority_reporter_agent_id || null;
      const result = await apiFetch('/api/jury/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:         session.id,
          objective:          session.initial_prompt || session.idea || session.title || '',
          selected_agents:    session.selected_agents || [],
          rounds:             session.rounds || 3,
          force_disagreement: session.force_disagreement ?? true,
          decision_threshold: session.decision_threshold ?? 0.55,
          // Adversarial config — defaults match backend normalizeAdversarialConfig()
          jury_adversarial_enabled:              adversarialCfg.jury_adversarial_enabled !== false,
          min_challenges_per_round:              adversarialCfg.min_challenges_per_round ?? 2,
          force_agent_references:                adversarialCfg.force_agent_references !== false,
          require_minority_report:               adversarialCfg.require_minority_report !== false,
          block_weak_debate_decision:            adversarialCfg.block_weak_debate_decision !== false,
          debate_quality_min_score:              adversarialCfg.debate_quality_min_score ?? 50,
          false_consensus_blocks_confident_decision: adversarialCfg.false_consensus_blocks_confident_decision !== false,
          ...(minorityReporter ? { minority_reporter_agent_id: minorityReporter } : {}),
        }),
      });
      state.juryResults = result;
    } catch (err) {
      state.error = 'Jury failed: ' + err.message;
    } finally {
      state.juryRunning = false;
      try { render(); } catch (_) { /* prevent render crash from hiding results */ }
    }
  });

  // Rerun with stronger adversarial settings
  registerAction('rerun-jury-strong', async () => {
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    const confirmed = window.confirm(
      'Relancer le jury avec débat renforcé ? Une nouvelle analyse sera créée. La session originale est conservée.'
    );
    if (!confirmed) return;

    state.juryRunning = true;
    state.juryResults = null;
    state.error       = null;
    try { render(); } catch (_) { /* render errors must not block the jury run */ }

    try {
      const currentRounds = Math.min(5, (session.rounds || 3) + 1);
      const result = await apiFetch('/api/jury/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:         session.id,
          objective:          session.initial_prompt || session.idea || session.title || '',
          selected_agents:    session.selected_agents || [],
          rounds:             currentRounds,
          force_disagreement: true,
          decision_threshold: session.decision_threshold ?? 0.55,
          // Maximally adversarial settings
          jury_adversarial_enabled:              true,
          min_challenges_per_round:              3,
          force_agent_references:                true,
          require_minority_report:               true,
          block_weak_debate_decision:            true,
          debate_quality_min_score:              50,
          false_consensus_blocks_confident_decision: true,
        }),
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

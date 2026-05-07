/* Sessions feature — action handlers */
import { registerAction } from '../../core/events.js';
import {
  shouldHydrateQuickDecisionResults,
  buildQuickDecisionResultsFromSession,
} from '../../utils/quickDecisionHydrate.js';
import { applyIntentPreset } from '../../utils/intentPresets.js';
import { applyAnalysisFamily } from '../newSession/analysisCatalog.js';
import { isConfirmationConfirmed, requestConfirmation, uiCopy } from '../../utils/confirmationUi.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:           a.store.state,
    render:          () => a.render?.(),
    navigate:        (v, ex) => a.router.navigate(v, ex),
    SessionService:  a.services.SessionService,
    apiFetch:        a.services.apiFetch,
    ComparisonService: a.services.ComparisonService,
    LoaderService:   a.services.LoaderService,
    t:               (key) => window.i18n?.t(key) ?? key,
  };
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a   = document.createElement('a');
  a.href    = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function registerSessionsHandlers() {
  registerAction('launch-quick-analysis', () => {
    const DA = window.DecisionArena;
    applyAnalysisFamily('validate');
    DA.store.state.newSession.language = DA.store.state.newSession.language || window.i18n?.getLanguage?.() || 'fr';
    DA.router.navigate('new-session');
    DA.render?.();
  });

  registerAction('dashboard-select-family', ({ element }) => {
    const DA = window.DecisionArena;
    const family = element?.dataset?.family;
    if (!family) return;
    applyAnalysisFamily(family);
    DA.router.navigate('new-session');
    DA.render?.();
  });

  registerAction('dashboard-intent-explore', () => {
    const DA = window.DecisionArena;
    applyIntentPreset('explore');
    DA.router.navigate('new-session');
    DA.render?.();
  });

  registerAction('dashboard-intent-decide', () => {
    const DA = window.DecisionArena;
    applyIntentPreset('decide');
    DA.router.navigate('new-session');
    DA.render?.();
  });

  registerAction('dashboard-intent-test', () => {
    const DA = window.DecisionArena;
    applyIntentPreset('test');
    DA.router.navigate('new-session');
    DA.render?.();
  });

  registerAction('open-session', async ({ element }) => {
    const { state, render, navigate, SessionService } = getCtx();
    const sessionId = element.dataset.sessionId;
    const mode      = element.dataset.mode || 'chat';
    const ContextDocService = window.DecisionArena.services.ContextDocService;

    try {
      state.isLoading      = true;
      state.showDebateDetails = false;
      state.currentContextDoc = null;
      state.ctxDocPanelOpen   = false;
      state.ctxDocEditor      = null;
      render();
      state.followUpMessages = [];

      const data     = await SessionService.get(sessionId);
      const session  = data.session || data;
      const messages = data.messages || [];
      state.currentSession = session;

      state.currentContextDoc = await ContextDocService.loadContextDoc(sessionId);
      state.isLoading = false;

      if (mode === 'chat') {
        state.currentMessages = messages;
        navigate('chat');
        setTimeout(() => window.DecisionArena.router.scrollMessagesToBottom?.(), 50);
      } else if (mode === 'quick-decision') {
        state.followUpMessages = [];
        let qdResults = null;
        if (shouldHydrateQuickDecisionResults(session, data)) {
          let verdict = null;
          try {
            const vd = await SessionService.getVerdict(sessionId);
            verdict = vd?.verdict ?? null;
          } catch (_) {
            /* verdict optionnel */
          }
          qdResults = buildQuickDecisionResultsFromSession(data, verdict);
        }
        state.qdResults = qdResults;
        navigate('quick-decision');
      } else if (mode === 'stress-test') {
        state.stResults = null;
        state.stRunning = false;
        navigate('stress-test');
      } else {
        state.sessionHistory = {
          session,
          messages,
          arguments:           data.arguments           || [],
          positions:           data.positions           || [],
          interaction_edges:   data.interaction_edges   || [],
          weighted_analysis:   data.weighted_analysis   || {},
          dominance_indicator: data.dominance_indicator || '',
          votes:               data.votes               || [],
          vote_timeline:       data.vote_timeline       || data.votes || [],
          final_votes:         data.final_votes         || null,
          memory_summary:      data.memory_summary      || null,
          automatic_decision:  data.automatic_decision  || null,
          raw_decision:        data.raw_decision        || null,
          adjusted_decision:   data.adjusted_decision   || null,
          context_quality:     data.context_quality     || null,
          reliability_cap:     data.reliability_cap     || null,
          false_consensus_risk: data.false_consensus_risk || 'low',
          false_consensus:     data.false_consensus     || null,
          reliability_warnings: data.reliability_warnings || [],
          decision_reliability_summary: data.decision_reliability_summary ?? null,
          context_clarification: data.context_clarification ?? null,
          guardrails:          data.guardrails          || null,
          decision_quality_score: data.decision_quality_score ?? null,
          decision_brief:       data.decision_brief ?? null,
          decision_outcome:     data.decision_outcome ?? data.decision_brief?.decision_outcome ?? null,
          premortem_summary:   data.premortem_summary ?? null,
          jury_adversarial:    data.jury_adversarial || null,
        };
        try {
          const vd = await SessionService.getVerdict(sessionId);
          if (state.sessionHistory) state.sessionHistory.verdict = vd.verdict;
        } catch (_) {}
        try {
          const ap = await SessionService.getActionPlan(sessionId);
          if (state.sessionHistory) state.sessionHistory.actionPlan = ap.action_plan || null;
        } catch (_) {}
        try {
          const ds = await SessionService.getDecisionSummary(sessionId);
          const sum = ds?.decision_summary ?? null;
          if (state.sessionHistory) {
            state.sessionHistory.decisionSummary  = sum;
            state.sessionHistory.panelHighlights = Array.isArray(sum?.highlights) ? sum.highlights : [];
            state.sessionHistory.context_quality = ds?.context_quality ?? state.sessionHistory.context_quality;
            state.sessionHistory.reliability_cap = ds?.reliability_cap ?? state.sessionHistory.reliability_cap;
            state.sessionHistory.adjusted_decision = ds?.adjusted_decision ?? state.sessionHistory.adjusted_decision;
            state.sessionHistory.false_consensus_risk = ds?.false_consensus_risk ?? state.sessionHistory.false_consensus_risk;
            state.sessionHistory.reliability_warnings = ds?.reliability_warnings ?? state.sessionHistory.reliability_warnings;
            state.sessionHistory.decision_reliability_summary = ds?.decision_reliability_summary ?? state.sessionHistory.decision_reliability_summary;
            state.sessionHistory.context_clarification = ds?.context_clarification ?? state.sessionHistory.context_clarification;
            state.sessionHistory.decision_outcome = ds?.decision_outcome ?? state.sessionHistory.decision_outcome;
          }
        } catch (_) {
          if (state.sessionHistory) {
            state.sessionHistory.decisionSummary  = null;
            state.sessionHistory.panelHighlights = [];
          }
        }
        navigate('session-history');
      }
    } catch (err) {
      state.isLoading = false;
      state.error     = 'Failed to open session: ' + err.message;
      render();
    }
  });

  registerAction('delete-session', async (ctx = {}) => {
    const { element } = ctx;
    const { state, render, SessionService, t } = getCtx();
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      render();
      return;
    }
    const sessionId    = element.dataset.sessionId;
    const sessionTitle = element.dataset.sessionTitle || '';
    if (!isConfirmationConfirmed(ctx)) {
      requestConfirmation(state, {
        id: `delete-session:${sessionId}`,
        mode: 'modal',
        tone: 'danger',
        title: `${t('sessions.confirmDelete')} "${sessionTitle}" ?`,
        body: uiCopy('Cette session sera retirée de l’historique.', 'This session will be removed from history.'),
        expertBody: uiCopy('Les vues liées devront être rechargées si cette session était ouverte.', 'Linked views will need to be reloaded if this session was open.'),
        confirmLabel: uiCopy('Supprimer la session', 'Delete session'),
        action: 'delete-session',
        payload: { sessionId, sessionTitle },
      });
      render();
      return;
    }
    try {
      await SessionService.remove(sessionId);
      state.sessions = state.sessions.filter((s) => s.id !== sessionId);
      if (state.currentSession?.id === sessionId) state.currentSession = null;
      render();
    } catch (err) {
      state.error = 'Delete failed: ' + err.message;
      render();
    }
  });

  registerAction('delete-all-sessions', async (ctx = {}) => {
    const { state, render, apiFetch, t } = getCtx();
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      render();
      return;
    }
    if (!isConfirmationConfirmed(ctx)) {
      requestConfirmation(state, {
        id: 'delete-all-sessions',
        mode: 'modal',
        tone: 'danger',
        title: t('sessions.confirmDeleteAll'),
        body: uiCopy('Toutes les sessions de l’historique seront supprimées.', 'All sessions in history will be deleted.'),
        expertBody: uiCopy('Cette action vide aussi la session courante côté UI.', 'This also clears the current session in the UI.'),
        confirmLabel: uiCopy('Supprimer toutes les sessions', 'Delete all sessions'),
        action: 'delete-all-sessions',
      });
      render();
      return;
    }
    try {
      await apiFetch('/api/sessions/delete-all', { method: 'POST' });
      state.sessions      = [];
      state.currentSession = null;
      state.sessionHistory = null;
      render();
    } catch (err) {
      state.error = 'Delete all failed: ' + err.message;
      render();
    }
  });

  registerAction('export-session', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    const format    = element.dataset.format || 'markdown';
    const redacted  = element.dataset.redacted || '';
    if (format === 'json' && state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      render();
      return;
    }
    try {
      let url = `/api/sessions/${sessionId}/export?format=${format}`;
      if (redacted) url += `&redacted=${redacted}`;
      const result = await apiFetch(url);
      if (format === 'json') {
        const blob = new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' });
        downloadBlob(blob, result.filename || `session-${sessionId}.json`);
      } else {
        const blob = new Blob([result.content || ''], { type: 'text/markdown;charset=utf-8' });
        downloadBlob(blob, result.filename || `session-${sessionId}.md`);
      }
    } catch (err) {
      state.error = 'Export failed: ' + err.message;
      render();
    }
  });

  registerAction('set-session-filter', ({ element }) => {
    const { state, render } = getCtx();
    state.sessionFilter = element.dataset.filter;
    render();
  });

  registerAction('toggle-compare-session', ({ element }) => {
    const { state, render } = getCtx();
    const sid = element.dataset.sessionId;
    const idx = (state.compareSelectedIds || []).indexOf(sid);
    if (idx >= 0) {
      state.compareSelectedIds.splice(idx, 1);
    } else if ((state.compareSelectedIds || []).length < 4) {
      if (!state.compareSelectedIds) state.compareSelectedIds = [];
      state.compareSelectedIds.push(sid);
    }
    render();
  });

  registerAction('save-snapshot', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    state.snapshotStatus = null;
    try {
      await apiFetch(`/api/sessions/${sessionId}/snapshot`, { method: 'POST', body: JSON.stringify({}) });
      state.snapshotStatus = { ok: true };
      setTimeout(() => { state.snapshotStatus = null; render(); }, 3000);
    } catch (err) {
      state.snapshotStatus = { ok: false, message: err.message };
      setTimeout(() => { state.snapshotStatus = null; render(); }, 3000);
    }
    render();
  });
}

export { registerSessionsHandlers };

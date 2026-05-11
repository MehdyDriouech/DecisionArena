import { registerAction, dispatchAction } from '../../core/events.js';
import { requestConfirmation, isConfirmationConfirmed, uiCopy } from '../../utils/confirmationUi.js';
import { mapAnalysisLifecycle } from '../../core/store.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state: a.store.state,
    render: () => a.render?.(),
    SessionService: a.services.SessionService,
    LoaderService: a.services.LoaderService,
    t: (key) => window.i18n?.t(key) ?? key,
  };
}

function ensureWorkspace(state) {
  if (!state.analysesWorkspace || typeof state.analysesWorkspace !== 'object') {
    state.analysesWorkspace = {
      query: '',
      mode: 'all',
      status: 'all',
      contextId: 'all',
      verdict: 'all',
      dateRange: 'all',
      selectedIds: [],
      visibleIds: [],
    };
  }
  return state.analysesWorkspace;
}

function readFilterInputs() {
  const query = document.getElementById('analyses-filter-query')?.value || '';
  const mode = document.getElementById('analyses-filter-mode')?.value || 'all';
  const status = document.getElementById('analyses-filter-status')?.value || 'all';
  const contextId = document.getElementById('analyses-filter-context')?.value || 'all';
  const dateRange = document.getElementById('analyses-filter-date')?.value || 'all';
  const verdictRaw = document.getElementById('analyses-filter-verdict')?.value || '';
  return {
    query: String(query).trim(),
    mode: String(mode),
    status: String(status),
    contextId: String(contextId),
    dateRange: String(dateRange),
    verdict: String(verdictRaw).trim() || 'all',
  };
}

function setUiError(state, message, metadata = {}) {
  state.error = message;
  try {
    window.DecisionArena.services?.LogService?.logFrontendEvent?.('warning', 'frontend', {
      action: 'ui_error',
      metadata,
      error_message: message,
    });
  } catch (_) {}
}

function registerAnalysesHandlers() {
  registerAction('analyses-apply-filters', () => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    Object.assign(ws, readFilterInputs());
    render();
  });

  registerAction('analyses-clear-filters', () => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    ws.query = '';
    ws.mode = 'all';
    ws.status = 'all';
    ws.contextId = 'all';
    ws.verdict = 'all';
    ws.dateRange = 'all';
    render();
  });

  registerAction('analyses-toggle-selection', ({ element }) => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    const id = String(element?.dataset?.sessionId || '');
    if (!id) return;
    const selected = new Set(Array.isArray(ws.selectedIds) ? ws.selectedIds : []);
    if (selected.has(id)) selected.delete(id);
    else selected.add(id);
    ws.selectedIds = [...selected];
    render();
  });

  registerAction('analyses-select-visible', () => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    ws.selectedIds = Array.isArray(ws.visibleIds) ? [...ws.visibleIds] : [];
    render();
  });

  registerAction('analyses-clear-selection', () => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    ws.selectedIds = [];
    render();
  });

  registerAction('archive-session', async ({ element }) => {
    const { state, render, SessionService, t } = getCtx();
    const sessionId = String(element?.dataset?.sessionId || '');
    if (!sessionId) return;
    const current = state.sessions.find((s) => String(s.id) === sessionId);
    const lifecycle = mapAnalysisLifecycle(current || null);
    if (!current || !['completed', 'archived'].includes(lifecycle.primaryStatus)) {
      setUiError(state, t('error.lifecycleRequiresCompletedOrArchived'), { action: 'archive-session', sessionId });
      render();
      return;
    }
    const nextStatus = lifecycle.primaryStatus === 'archived' ? 'completed' : 'archived';
    try {
      await SessionService.updateStatus(sessionId, nextStatus);
      const idx = state.sessions.findIndex((s) => s.id === sessionId);
      if (idx >= 0) {
        state.sessions[idx] = { ...state.sessions[idx], status: nextStatus };
      }
    } catch (err) {
      setUiError(state, err?.message || String(err), { action: 'archive-session', sessionId });
    }
    render();
  });

  registerAction('analyses-bulk-archive', async () => {
    const { state, render, SessionService } = getCtx();
    const ws = ensureWorkspace(state);
    const ids = Array.isArray(ws.selectedIds) ? ws.selectedIds : [];
    if (ids.length === 0) return;
    const ops = ids.map(async (id) => {
      const current = state.sessions.find((s) => String(s.id) === String(id));
      const lifecycle = mapAnalysisLifecycle(current || null);
      if (!current || !['completed', 'archived'].includes(lifecycle.primaryStatus)) {
        return null;
      }
      const nextStatus = lifecycle.primaryStatus === 'archived' ? 'completed' : 'archived';
      await SessionService.updateStatus(id, nextStatus);
      return { id, nextStatus };
    });
    const results = await Promise.allSettled(ops);
    results.forEach((res) => {
      if (res.status !== 'fulfilled') {
        setUiError(state, res.reason?.message || String(res.reason), { action: 'analyses-bulk-archive' });
        return;
      }
      if (!res.value) return;
      const idx = state.sessions.findIndex((s) => s.id === res.value.id);
      if (idx >= 0) state.sessions[idx] = { ...state.sessions[idx], status: res.value.nextStatus };
    });
    ws.selectedIds = [];
    render();
  });

  registerAction('analyses-bulk-delete', async (ctx = {}) => {
    const { state, render, SessionService, t } = getCtx();
    const ws = ensureWorkspace(state);
    const ids = Array.isArray(ws.selectedIds) ? ws.selectedIds : [];
    if (ids.length === 0) return;

    if (!isConfirmationConfirmed(ctx)) {
      requestConfirmation(state, {
        id: 'analyses-bulk-delete',
        mode: 'modal',
        tone: 'danger',
        title: `${t('sessions.confirmDeleteAll')} (${ids.length})`,
        body: uiCopy('Les analyses sélectionnées seront supprimées.', 'Selected analyses will be deleted.'),
        confirmLabel: uiCopy('Supprimer la sélection', 'Delete selection'),
        action: 'analyses-bulk-delete',
      });
      render();
      return;
    }

    const results = await Promise.allSettled(ids.map((id) => SessionService.remove(id)));
    results.forEach((res) => {
      if (res.status !== 'fulfilled') {
        setUiError(state, res.reason?.message || String(res.reason), { action: 'analyses-bulk-delete' });
      }
    });
    state.sessions = state.sessions.filter((session) => !ids.includes(session.id));
    ws.selectedIds = [];
    render();
  });

  registerAction('analyses-bulk-compare', async () => {
    const { state, render } = getCtx();
    const ws = ensureWorkspace(state);
    const ids = Array.isArray(ws.selectedIds) ? ws.selectedIds.slice(0, 4) : [];
    if (ids.length < 2) return;
    state.compareSelectedIds = ids;
    render();
    await dispatchAction('goto-compare-sessions', {});
  });
}

export { registerAnalysesHandlers };

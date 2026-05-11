import { registerAction } from '../../core/events.js';

function getCtx() {
  const arena = window.DecisionArena;
  return {
    state: arena.store.state,
    render: arena.render,
    DashboardService: arena.services.DashboardService,
  };
}

function ensureDashboardState(state) {
  if (!state.dashboardSummary || typeof state.dashboardSummary !== 'object') {
    state.dashboardSummary = {
      loading: false,
      error: null,
      data: null,
      lastLoadedAt: null,
      collapsedSections: {},
      contextsFilterStatus: 'all',
      contextsSortBy: 'open_risks_desc',
      contextsHighRiskOnly: false,
      scopeContextId: 'auto',
      scopePickerOpen: false,
      detailPanel: { open: false, kind: '' },
    };
  }
  if (!state.dashboardSummary.collapsedSections || typeof state.dashboardSummary.collapsedSections !== 'object') {
    state.dashboardSummary.collapsedSections = {};
  }
  if (typeof state.dashboardSummary.contextsFilterStatus !== 'string') {
    state.dashboardSummary.contextsFilterStatus = 'all';
  }
  if (typeof state.dashboardSummary.contextsSortBy !== 'string') {
    state.dashboardSummary.contextsSortBy = 'open_risks_desc';
  }
  if (typeof state.dashboardSummary.contextsHighRiskOnly !== 'boolean') {
    state.dashboardSummary.contextsHighRiskOnly = false;
  }
  if (typeof state.dashboardSummary.scopeContextId !== 'string') {
    state.dashboardSummary.scopeContextId = 'auto';
  }
  if (typeof state.dashboardSummary.scopePickerOpen !== 'boolean') {
    state.dashboardSummary.scopePickerOpen = false;
  }
  if (!state.dashboardSummary.detailPanel || typeof state.dashboardSummary.detailPanel !== 'object') {
    state.dashboardSummary.detailPanel = { open: false, kind: '' };
  }
  return state.dashboardSummary;
}

async function loadSummary(forceRefresh = false) {
  const { state, render, DashboardService } = getCtx();
  const ds = ensureDashboardState(state);
  if (ds.loading) return;
  if (!forceRefresh && ds.data) return;

  ds.loading = true;
  ds.error = null;
  render();
  try {
    const data = await DashboardService.getCognitiveSummary({ contextId: ds.scopeContextId });
    ds.data = data || null;
    ds.lastLoadedAt = new Date().toISOString();
  } catch (err) {
    ds.error = err?.message || String(err);
  } finally {
    ds.loading = false;
    render();
  }
}

function registerDashboardHandlers() {
  registerAction('load-dashboard-summary', async () => {
    await loadSummary(false);
  });

  registerAction('refresh-dashboard-summary', async () => {
    await loadSummary(true);
  });

  registerAction('toggle-dashboard-section', ({ element }) => {
    const sectionKey = String(element?.dataset?.sectionKey || '').trim();
    if (!sectionKey) return;
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.collapsedSections[sectionKey] = !ds.collapsedSections[sectionKey];
    try {
      localStorage.setItem('da_dashboard_collapsed_sections', JSON.stringify(ds.collapsedSections));
    } catch (_) {}
    render();
  });

  registerAction('set-dashboard-context-status-filter', ({ element }) => {
    const next = String(element?.value || element?.dataset?.value || 'all').toLowerCase().trim();
    const allowed = new Set(['all', 'active', 'paused', 'completed', 'abandoned']);
    if (!allowed.has(next)) return;
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.contextsFilterStatus = next;
    try {
      localStorage.setItem('da_dashboard_context_filter_status', next);
    } catch (_) {}
    render();
  });

  registerAction('set-dashboard-context-sort', ({ element }) => {
    const next = String(element?.value || element?.dataset?.value || 'open_risks_desc').trim();
    const allowed = new Set(['open_risks_desc', 'analyses_desc', 'reruns_desc', 'snapshot_desc']);
    if (!allowed.has(next)) return;
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.contextsSortBy = next;
    try {
      localStorage.setItem('da_dashboard_context_sort_by', next);
    } catch (_) {}
    render();
  });

  registerAction('toggle-dashboard-context-high-risk-only', ({ element }) => {
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    const checked = typeof element?.checked === 'boolean' ? element.checked : !ds.contextsHighRiskOnly;
    ds.contextsHighRiskOnly = !!checked;
    try {
      localStorage.setItem('da_dashboard_context_high_risk_only', ds.contextsHighRiskOnly ? '1' : '0');
    } catch (_) {}
    render();
  });

  registerAction('toggle-dashboard-scope-picker', () => {
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.scopePickerOpen = !ds.scopePickerOpen;
    render();
  });

  registerAction('set-dashboard-scope', async ({ element }) => {
    const next = String(element?.value || element?.dataset?.scopeContextId || 'auto').trim();
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.scopeContextId = next || 'auto';
    ds.scopePickerOpen = false;
    try {
      localStorage.setItem('da_dashboard_scope_context_id', ds.scopeContextId);
    } catch (_) {}
    render();
    await loadSummary(true);
  });

  registerAction('open-dashboard-kpi-detail', ({ element }) => {
    const kind = String(element?.dataset?.detailKind || '').trim();
    if (!kind) return;
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.detailPanel = { open: true, kind };
    render();
  });

  registerAction('close-dashboard-kpi-detail', () => {
    const { state, render } = getCtx();
    const ds = ensureDashboardState(state);
    ds.detailPanel = { open: false, kind: '' };
    render();
  });
}

export { registerDashboardHandlers, loadSummary };

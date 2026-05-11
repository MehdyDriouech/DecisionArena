import { mapAnalysisLifecycle } from '../../core/store.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, formatDate, t };
}

function parseResult(resultRaw) {
  if (!resultRaw) return null;
  if (typeof resultRaw === 'object') return resultRaw;
  if (typeof resultRaw !== 'string') return null;
  try { return JSON.parse(resultRaw); } catch (_) { return null; }
}

function deriveVerdict(session) {
  const result = parseResult(session?.result);
  const adjusted = session?.adjusted_decision ?? result?.adjusted_decision ?? null;
  const raw = session?.raw_decision ?? session?.automatic_decision ?? result?.raw_decision ?? result?.automatic_decision ?? null;
  return (
    adjusted?.ui_decision_label ||
    adjusted?.legacy_decision_label ||
    adjusted?.decision_label ||
    adjusted?.vote_label ||
    adjusted?.final_outcome ||
    adjusted?.decision ||
    raw?.ui_decision_label ||
    raw?.legacy_decision_label ||
    raw?.decision_label ||
    raw?.vote_label ||
    raw?.final_outcome ||
    raw?.decision ||
    result?.decision_outcome?.decision ||
    result?.decision_outcome?.label ||
    result?.verdict?.label ||
    ''
  );
}

function deriveQualityScore(session) {
  const result = parseResult(session?.result);
  const score = session?.decision_quality_score ?? result?.decision_quality_score ?? null;
  if (typeof score !== 'number' || !Number.isFinite(score)) return null;
  return Math.max(0, Math.min(100, Math.round(score)));
}

function deriveFalseConsensus(session) {
  const result = parseResult(session?.result);
  return String(
    session?.false_consensus_risk ||
    session?.false_consensus?.risk_level ||
    result?.false_consensus_risk ||
    result?.false_consensus?.risk_level ||
    'n/a',
  ).toLowerCase();
}

function ensureWorkspaceFilters(state) {
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

function withinDateRange(dateIso, range) {
  if (!dateIso || range === 'all') return true;
  const ts = Date.parse(dateIso);
  if (!Number.isFinite(ts)) return true;
  const now = Date.now();
  const days = range === '7d' ? 7 : range === '30d' ? 30 : range === '90d' ? 90 : null;
  if (!days) return true;
  return now - ts <= days * 24 * 60 * 60 * 1000;
}

function matchesFilters(session, filters, contextMap) {
  const query = String(filters.query || '').trim().toLowerCase();
  const mode = String(filters.mode || 'all');
  const status = String(filters.status || 'all');
  const contextId = String(filters.contextId || 'all');
  const verdict = String(filters.verdict || 'all');
  const dateRange = String(filters.dateRange || 'all');

  const lifecycle = mapAnalysisLifecycle(session);
  const sessionVerdict = String(deriveVerdict(session) || '').toLowerCase();
  const sessionContextId = String(session?.strategic_context_id || '');
  const contextTitle = String(contextMap.get(sessionContextId)?.title || '').toLowerCase();
  const searchable = [
    session?.title || '',
    session?.mode || '',
    session?.id || '',
    sessionVerdict,
    contextTitle,
  ].join(' ').toLowerCase();

  if (query && !searchable.includes(query)) return false;
  if (mode !== 'all' && String(session?.mode || '') !== mode) return false;
  if (status !== 'all' && !lifecycle.allStatuses.includes(status)) return false;
  if (contextId !== 'all' && sessionContextId !== contextId) return false;
  if (verdict !== 'all' && !sessionVerdict.includes(verdict.toLowerCase())) return false;
  if (!withinDateRange(session?.updated_at || session?.created_at, dateRange)) return false;

  return true;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'completed': return 'badge-success';
    case 'running': return 'badge-info';
    case 'archived': return 'badge-muted';
    case 'fragile': return 'badge-warning';
    case 'blocked': return 'badge-danger';
    case 'rerun': return 'badge-primary';
    case 'forked': return 'badge-primary';
    default: return 'badge-muted';
  }
}

function lifecycleLabel(status, t) {
  const key = `analysis.lifecycle.${status}`;
  const translated = t(key);
  return translated === key ? status : translated;
}

function analysisDateRangeLabel(range, t) {
  if (range === '7d') return t('analyses.filters.date.7d');
  if (range === '30d') return t('analyses.filters.date.30d');
  if (range === '90d') return t('analyses.filters.date.90d');
  return t('analyses.filters.date.all');
}

function renderAnalyses() {
  const { state, escHtml, formatDate, t } = getCtx();
  const filters = ensureWorkspaceFilters(state);
  const sessions = Array.isArray(state.sessions) ? state.sessions : [];
  const contexts = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
  const contextMap = new Map(contexts.map((ctx) => [String(ctx.context_id), ctx]));
  const filtered = sessions.filter((session) => matchesFilters(session, filters, contextMap));
  filters.visibleIds = filtered.map((session) => session.id);

  const selectedIds = new Set(Array.isArray(filters.selectedIds) ? filters.selectedIds : []);
  const modeOptions = [...new Set(sessions.map((s) => String(s.mode || '')).filter(Boolean))].sort();
  const contextOptions = contexts.map((ctx) => ({ id: String(ctx.context_id), title: String(ctx.title || '').trim() || String(ctx.context_id) }));

  return `
    <div class="page-header">
      <div class="page-title">${escHtml(t('sessions.title'))}</div>
      <div class="page-subtitle">${escHtml(t('sessions.subtitle'))}</div>
    </div>

    <div class="card analyses-workspace-filters" style="padding:14px;margin-bottom:14px;">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;">
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.queryLabel'))}</span>
          <input id="analyses-filter-query" class="input" type="search" value="${escHtml(filters.query || '')}" placeholder="${escHtml(t('analyses.filters.queryPlaceholder'))}" />
        </label>
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.modeLabel'))}</span>
          <select id="analyses-filter-mode" class="input">${['all', ...modeOptions].map((mode) => `<option value="${escHtml(mode)}" ${String(filters.mode) === mode ? 'selected' : ''}>${mode === 'all' ? escHtml(t('analyses.common.all')) : escHtml(mode)}</option>`).join('')}</select>
        </label>
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.statusLabel'))}</span>
          <select id="analyses-filter-status" class="input">${['all', 'draft', 'running', 'completed', 'archived', 'blocked', 'fragile', 'rerun', 'forked'].map((status) => `<option value="${escHtml(status)}" ${String(filters.status) === status ? 'selected' : ''}>${status === 'all' ? escHtml(t('analyses.common.all')) : escHtml(lifecycleLabel(status, t))}</option>`).join('')}</select>
        </label>
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.contextLabel'))}</span>
          <select id="analyses-filter-context" class="input">
            <option value="all" ${String(filters.contextId) === 'all' ? 'selected' : ''}>${escHtml(t('analyses.common.all'))}</option>
            ${contextOptions.map((ctx) => `<option value="${escHtml(ctx.id)}" ${String(filters.contextId) === ctx.id ? 'selected' : ''}>${escHtml(ctx.title)}</option>`).join('')}
          </select>
        </label>
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.dateLabel'))}</span>
          <select id="analyses-filter-date" class="input">${['all', '7d', '30d', '90d'].map((range) => `<option value="${range}" ${String(filters.dateRange) === range ? 'selected' : ''}>${escHtml(analysisDateRangeLabel(range, t))}</option>`).join('')}</select>
        </label>
        <label class="form-group" style="margin:0;">
          <span class="label">${escHtml(t('analyses.filters.verdictLabel'))}</span>
          <input id="analyses-filter-verdict" class="input" type="text" value="${escHtml(filters.verdict === 'all' ? '' : filters.verdict)}" placeholder="${escHtml(t('analyses.filters.verdictPlaceholder'))}" />
        </label>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <button class="btn btn-primary btn-sm" data-action="analyses-apply-filters">${escHtml(t('analyses.actions.applyFilters'))}</button>
        <button class="btn btn-secondary btn-sm" data-action="analyses-clear-filters">${escHtml(t('analyses.actions.clearFilters'))}</button>
        <button class="btn btn-secondary btn-sm" data-action="analyses-select-visible">${escHtml(t('analyses.actions.selectVisible'))} (${filtered.length})</button>
        <button class="btn btn-secondary btn-sm" data-action="analyses-clear-selection">${escHtml(t('analyses.actions.clearSelection'))}</button>
      </div>
    </div>

    ${selectedIds.size > 0 ? `
      <div class="alert alert-info" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span><strong>${selectedIds.size}</strong> ${escHtml(t('analyses.selectedCount'))}</span>
        <button class="btn btn-secondary btn-sm" data-action="analyses-bulk-archive">${escHtml(t('analyses.actions.bulkArchive'))}</button>
        <button class="btn btn-danger btn-sm" data-action="analyses-bulk-delete">${escHtml(t('analyses.actions.bulkDelete'))}</button>
        <button class="btn btn-primary btn-sm" data-action="analyses-bulk-compare">${escHtml(t('analyses.actions.bulkCompare'))}</button>
      </div>
    ` : ''}

    <div class="sessions-list">
      ${filtered.length === 0 ? `<div class="empty-state"><p>${escHtml(t('sessions.empty'))}</p></div>` : filtered.map((session) => {
        const contextId = String(session?.strategic_context_id || '');
        const contextTitle = contextId ? (contextMap.get(contextId)?.title || `${contextId.slice(0, 8)}...`) : t('analyses.context.none');
        const verdict = deriveVerdict(session);
        const score = deriveQualityScore(session);
        const falseConsensus = deriveFalseConsensus(session);
        const lifecycle = mapAnalysisLifecycle(session);
        const isChecked = selectedIds.has(session.id);
        const agentsCount = Array.isArray(session?.selected_agents) ? session.selected_agents.length : 0;
        const rounds = Number(session?.rounds || 0);
        const canArchiveToggle = lifecycle.primaryStatus === 'completed' || lifecycle.primaryStatus === 'archived';
        const archiveLabel = lifecycle.primaryStatus === 'archived' ? t('analyses.actions.restore') : t('analyses.actions.archive');
        const canRerunOrFork = lifecycle.primaryStatus === 'completed' || lifecycle.primaryStatus === 'archived';

        return `
          <div class="session-card-full">
            <div class="session-card-full-header" style="align-items:flex-start;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:2px;">
                <input type="checkbox" data-action="analyses-toggle-selection" data-session-id="${escHtml(session.id)}" ${isChecked ? 'checked' : ''} style="accent-color:var(--accent);" />
              </label>
              <div style="flex:1;min-width:0;">
                <div class="session-title">${escHtml(session.title || t('analyses.untitled'))}</div>
                <div class="session-meta" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px;">
                  <span class="badge badge-default">${escHtml(String(session.mode || ''))}</span>
                  <span class="badge ${statusBadgeClass(lifecycle.primaryStatus)}">${escHtml(lifecycleLabel(lifecycle.primaryStatus, t))}</span>
                  ${lifecycle.overlays.map((ov) => `<span class="badge ${statusBadgeClass(ov)}">${escHtml(lifecycleLabel(ov, t))}</span>`).join('')}
                  <span class="badge badge-info">${escHtml(contextTitle)}</span>
                  ${verdict ? `<span class="badge badge-primary">${escHtml(t('analyses.badges.verdict'))}: ${escHtml(String(verdict))}</span>` : ''}
                  ${score !== null ? `<span class="badge badge-muted">${escHtml(t('analyses.badges.quality'))}: ${score}</span>` : ''}
                  <span class="badge ${falseConsensus === 'high' || falseConsensus === 'critical' ? 'badge-warning' : 'badge-muted'}">${escHtml(t('analyses.badges.falseConsensus'))}: ${escHtml(falseConsensus)}</span>
                  <span class="badge badge-muted">${escHtml(t('analyses.badges.personas'))}: ${agentsCount}</span>
                  <span class="badge badge-muted">${escHtml(t('analyses.badges.rounds'))}: ${rounds}</span>
                  <span class="badge badge-muted">${escHtml(t('analyses.badges.updatedAt'))}: ${escHtml(formatDate(session.updated_at || session.created_at))}</span>
                </div>
                ${(lifecycle.overlays.includes('blocked') || lifecycle.overlays.includes('fragile')) ? `
                  <div data-ui="expert-only" style="margin-top:6px;font-size:11px;color:var(--text-muted);">
                    ⚠ ${escHtml(t('analyses.runtimeSignal'))}: ${escHtml(lifecycle.overlays.join(', '))}
                  </div>
                ` : ''}
              </div>
            </div>
            <div class="session-card-full-actions">
              <button class="btn btn-primary btn-sm" data-action="open-session" data-session-id="${escHtml(session.id)}" data-mode="history">${escHtml(t('sessions.open'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="open-rerun-modal" data-session-id="${escHtml(session.id)}" ${canRerunOrFork ? '' : 'disabled'}>${escHtml(t('sessions.rerun'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="fork-session" data-session-id="${escHtml(session.id)}" ${canRerunOrFork ? '' : 'disabled'}>${escHtml(t('hitl.forkVariant'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="markdown">${escHtml(t('sessions.exportMd'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="toggle-compare-session" data-session-id="${escHtml(session.id)}">${escHtml(t('sessions.compareSelected'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="archive-session" data-session-id="${escHtml(session.id)}" ${canArchiveToggle ? '' : 'disabled'}>${archiveLabel}</button>
              <button class="btn btn-danger btn-sm" data-action="delete-session" data-session-id="${escHtml(session.id)}" data-session-title="${escHtml(session.title || '')}">${escHtml(t('sessions.delete'))}</button>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function registerAnalysesFeature() {
  window.DecisionArena.views.analyses = renderAnalyses;
  // Compat ascendante: l'ancienne vue "sessions" reste routable.
  window.DecisionArena.views.sessions = renderAnalyses;
}

export { registerAnalysesFeature, renderAnalyses };

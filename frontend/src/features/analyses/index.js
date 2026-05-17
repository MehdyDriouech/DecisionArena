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

function analysisStatusBadgeClass(status) {
  switch (status) {
    case 'completed': return 'analysis-status-badge analysis-status-completed';
    case 'running': return 'analysis-status-badge analysis-status-running';
    case 'archived': return 'analysis-status-badge analysis-status-archived';
    case 'fragile': return 'analysis-status-badge analysis-status-fragile';
    case 'blocked': return 'analysis-status-badge analysis-status-blocked';
    case 'rerun':
    case 'forked': return 'analysis-status-badge analysis-status-rerun';
    case 'draft':
    default: return 'analysis-status-badge analysis-status-draft';
  }
}

function verdictBadgeClass(verdict) {
  const raw = String(verdict || '').trim().toLowerCase();
  const norm = raw.replace(/[\s_]+/g, '-');
  if (!norm || norm === 'n/a' || norm === 'na' || norm === 'none') {
    return 'analysis-verdict-badge analysis-verdict-na';
  }
  if (norm.includes('no-go') || norm.includes('nogo') || norm === 'no') {
    return 'analysis-verdict-badge analysis-verdict-no-go';
  }
  if (norm.includes('reduce') && norm.includes('scope')) {
    return 'analysis-verdict-badge analysis-verdict-reduce-scope';
  }
  if (norm.includes('needs') || norm.includes('more-info') || norm.includes('more_info')) {
    return 'analysis-verdict-badge analysis-verdict-needs-more-info';
  }
  if (norm === 'go' || (norm.includes('go') && !norm.includes('no'))) {
    return 'analysis-verdict-badge analysis-verdict-go';
  }
  return 'analysis-verdict-badge';
}

function renderAnalysesFiltersBlock({ filters, modeOptions, contextOptions, filteredCount, escHtml, t }) {
  return `
    <div class="card analyses-workspace-filters">
      <div class="analyses-filters-grid">
        <label class="analyses-filter-field analyses-filter-field--wide">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.queryLabel'))}</span>
          <input id="analyses-filter-query" class="input analyses-filter-input" type="search" value="${escHtml(filters.query || '')}" placeholder="${escHtml(t('analyses.filters.queryPlaceholder'))}" />
        </label>
        <label class="analyses-filter-field">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.modeLabel'))}</span>
          <select id="analyses-filter-mode" class="input analyses-filter-input">${['all', ...modeOptions].map((mode) => `<option value="${escHtml(mode)}" ${String(filters.mode) === mode ? 'selected' : ''}>${mode === 'all' ? escHtml(t('analyses.common.all')) : escHtml(mode)}</option>`).join('')}</select>
        </label>
        <label class="analyses-filter-field">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.statusLabel'))}</span>
          <select id="analyses-filter-status" class="input analyses-filter-input">${['all', 'draft', 'running', 'completed', 'archived', 'blocked', 'fragile', 'rerun', 'forked'].map((status) => `<option value="${escHtml(status)}" ${String(filters.status) === status ? 'selected' : ''}>${status === 'all' ? escHtml(t('analyses.common.all')) : escHtml(lifecycleLabel(status, t))}</option>`).join('')}</select>
        </label>
        <label class="analyses-filter-field">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.contextLabel'))}</span>
          <select id="analyses-filter-context" class="input analyses-filter-input">
            <option value="all" ${String(filters.contextId) === 'all' ? 'selected' : ''}>${escHtml(t('analyses.common.all'))}</option>
            ${contextOptions.map((ctx) => `<option value="${escHtml(ctx.id)}" ${String(filters.contextId) === ctx.id ? 'selected' : ''}>${escHtml(ctx.title)}</option>`).join('')}
          </select>
        </label>
        <label class="analyses-filter-field">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.dateLabel'))}</span>
          <select id="analyses-filter-date" class="input analyses-filter-input">${['all', '7d', '30d', '90d'].map((range) => `<option value="${range}" ${String(filters.dateRange) === range ? 'selected' : ''}>${escHtml(analysisDateRangeLabel(range, t))}</option>`).join('')}</select>
        </label>
        <label class="analyses-filter-field">
          <span class="analyses-filter-label">${escHtml(t('analyses.filters.verdictLabel'))}</span>
          <input id="analyses-filter-verdict" class="input analyses-filter-input" type="text" value="${escHtml(filters.verdict === 'all' ? '' : filters.verdict)}" placeholder="${escHtml(t('analyses.filters.verdictPlaceholder'))}" />
        </label>
      </div>
      <div class="analyses-filters-actions">
        <div class="analyses-filters-actions__primary">
          <button type="button" class="btn btn-primary btn-sm" data-action="analyses-apply-filters">${escHtml(t('analyses.actions.applyFilters'))}</button>
          <button type="button" class="btn btn-secondary btn-sm" data-action="analyses-clear-filters">${escHtml(t('analyses.actions.clearFilters'))}</button>
        </div>
        <div class="analyses-filters-actions__bulk">
          <button type="button" class="btn btn-secondary btn-sm analyses-filters-bulk-btn" data-action="analyses-select-visible">${escHtml(t('analyses.actions.selectVisible'))} <span class="analyses-filters-count">(${filteredCount})</span></button>
          <button type="button" class="btn btn-secondary btn-sm analyses-filters-bulk-btn" data-action="analyses-clear-selection">${escHtml(t('analyses.actions.clearSelection'))}</button>
        </div>
      </div>
    </div>`;
}

function renderAnalysesEmptyState({ sessionsCount, filteredCount, escHtml, t }) {
  if (sessionsCount === 0) {
    return `
      <div class="analyses-empty-state empty-state">
        <p class="analyses-empty-state__text">${escHtml(t('sessions.empty'))}</p>
        <button type="button" class="btn btn-primary" data-nav="new-session">${escHtml(t('nav.newSession'))}</button>
      </div>`;
  }
  if (filteredCount === 0) {
    return `
      <div class="analyses-empty-state empty-state">
        <p class="analyses-empty-state__text">${escHtml(t('analyses.empty.filtered'))}</p>
        <button type="button" class="btn btn-secondary" data-action="analyses-clear-filters">${escHtml(t('analyses.actions.clearFilters'))}</button>
      </div>`;
  }
  return '';
}

function renderAnalysisCard(session, opts) {
  const {
    contextMap, selectedIds, escHtml, formatDate, t,
  } = opts;
  const contextId = String(session?.strategic_context_id || '');
  const contextTitle = contextId
    ? (contextMap.get(contextId)?.title || `${contextId.slice(0, 8)}...`)
    : t('analyses.context.none');
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
  const updatedLabel = formatDate(session.updated_at || session.created_at);
  const overlayBadges = lifecycle.overlays
    .filter((ov) => ov !== lifecycle.primaryStatus)
    .map((ov) => `<span class="${analysisStatusBadgeClass(ov)}">${escHtml(lifecycleLabel(ov, t))}</span>`)
    .join('');

  return `
    <article class="session-card-full analysis-card">
      <div class="analysis-card__top">
        <label class="analysis-card__select">
          <input type="checkbox" data-action="analyses-toggle-selection" data-session-id="${escHtml(session.id)}" ${isChecked ? 'checked' : ''} />
        </label>
        <div class="analysis-card__title-wrap">
          <h3 class="analysis-card__title">${escHtml(session.title || t('analyses.untitled'))}</h3>
        </div>
        <div class="analysis-card__status-group">
          <span class="${analysisStatusBadgeClass(lifecycle.primaryStatus)}">${escHtml(lifecycleLabel(lifecycle.primaryStatus, t))}</span>
          ${overlayBadges}
        </div>
      </div>
      <div class="analysis-card__meta-primary">
        ${session.mode ? `<span class="analysis-meta-badge">${escHtml(String(session.mode))}</span>` : ''}
        <span class="analysis-context-badge" title="${escHtml(contextTitle)}">${escHtml(contextTitle)}</span>
        ${verdict ? `<span class="${verdictBadgeClass(verdict)}"><span class="analysis-verdict-badge__label">${escHtml(t('analyses.badges.verdict'))}</span><span class="analysis-verdict-badge__value">${escHtml(String(verdict))}</span></span>` : ''}
        ${score !== null ? `<span class="analysis-quality-badge"><span class="analysis-quality-badge__label">${escHtml(t('analyses.badges.quality'))}</span><span class="analysis-quality-badge__value">${score}</span></span>` : ''}
      </div>
      <p class="analysis-card__meta-secondary">
        <span class="analysis-meta-item"><span class="analysis-meta-item__label">${escHtml(t('analyses.badges.updatedAt'))}</span> ${escHtml(updatedLabel)}</span>
        <span class="analysis-meta-sep" aria-hidden="true">·</span>
        <span class="analysis-meta-item"><span class="analysis-meta-item__label">${escHtml(t('analyses.badges.personas'))}</span> ${agentsCount}</span>
        <span class="analysis-meta-sep" aria-hidden="true">·</span>
        <span class="analysis-meta-item"><span class="analysis-meta-item__label">${escHtml(t('analyses.badges.rounds'))}</span> ${rounds}</span>
        <span class="analysis-meta-sep" aria-hidden="true">·</span>
        <span class="analysis-meta-item analysis-meta-item--${escHtml(falseConsensus === 'high' || falseConsensus === 'critical' ? 'warn' : 'muted')}"><span class="analysis-meta-item__label">${escHtml(t('analyses.badges.falseConsensus'))}</span> ${escHtml(falseConsensus)}</span>
      </p>
      ${(lifecycle.overlays.includes('blocked') || lifecycle.overlays.includes('fragile')) ? `
        <div class="analysis-card__runtime" data-ui="expert-only">
          <span aria-hidden="true">⚠</span> ${escHtml(t('analyses.runtimeSignal'))}: ${escHtml(lifecycle.overlays.join(', '))}
        </div>
      ` : ''}
      <div class="analysis-card__actions">
        <div class="analysis-card__actions-primary">
          <button type="button" class="btn btn-primary btn-sm" data-action="open-session" data-session-id="${escHtml(session.id)}" data-mode="history">${escHtml(t('sessions.open'))}</button>
          <button type="button" class="btn btn-secondary btn-sm" data-action="open-rerun-modal" data-session-id="${escHtml(session.id)}" ${canRerunOrFork ? '' : 'disabled'}>${escHtml(t('sessions.rerun'))}</button>
          <button type="button" class="btn btn-secondary btn-sm" data-action="fork-session" data-session-id="${escHtml(session.id)}" ${canRerunOrFork ? '' : 'disabled'}>${escHtml(t('hitl.forkVariant'))}</button>
        </div>
        <div class="analysis-card__actions-secondary">
          <button type="button" class="btn btn-secondary btn-sm analysis-card__btn-ghost" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="markdown">${escHtml(t('sessions.exportMd'))}</button>
          <button type="button" class="btn btn-secondary btn-sm analysis-card__btn-ghost" data-action="toggle-compare-session" data-session-id="${escHtml(session.id)}">${escHtml(t('sessions.compareSelected'))}</button>
          <button type="button" class="btn btn-secondary btn-sm analysis-card__btn-ghost" data-action="archive-session" data-session-id="${escHtml(session.id)}" ${canArchiveToggle ? '' : 'disabled'}>${escHtml(archiveLabel)}</button>
          <button type="button" class="btn btn-danger btn-sm analysis-card__btn-danger" data-action="delete-session" data-session-id="${escHtml(session.id)}" data-session-title="${escHtml(session.title || '')}">${escHtml(t('sessions.delete'))}</button>
        </div>
      </div>
    </article>`;
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
  const cardOpts = { contextMap, selectedIds, escHtml, formatDate, t };
  const listHtml = filtered.length === 0
    ? renderAnalysesEmptyState({ sessionsCount: sessions.length, filteredCount: filtered.length, escHtml, t })
    : filtered.map((session) => renderAnalysisCard(session, cardOpts)).join('');

  return `
    <div class="analyses-workspace">
    <div class="page-header">
      <div class="page-title">${escHtml(t('sessions.title'))}</div>
      <div class="page-subtitle">${escHtml(t('sessions.subtitle'))}</div>
    </div>

    ${renderAnalysesFiltersBlock({ filters, modeOptions, contextOptions, filteredCount: filtered.length, escHtml, t })}

    ${selectedIds.size > 0 ? `
      <div class="alert alert-info analyses-selection-bar">
        <span class="analyses-selection-bar__count"><strong>${selectedIds.size}</strong> ${escHtml(t('analyses.selectedCount'))}</span>
        <div class="analyses-selection-bar__actions">
          <button type="button" class="btn btn-secondary btn-sm" data-action="analyses-bulk-archive">${escHtml(t('analyses.actions.bulkArchive'))}</button>
          <button type="button" class="btn btn-danger btn-sm" data-action="analyses-bulk-delete">${escHtml(t('analyses.actions.bulkDelete'))}</button>
          <button type="button" class="btn btn-primary btn-sm" data-action="analyses-bulk-compare">${escHtml(t('analyses.actions.bulkCompare'))}</button>
        </div>
      </div>
    ` : ''}

    <div class="analyses-list sessions-list">
      ${listHtml}
    </div>
    </div>
  `;
}

function registerAnalysesFeature() {
  window.DecisionArena.views.analyses = renderAnalyses;
  // Compat ascendante: l'ancienne vue "sessions" reste routable.
  window.DecisionArena.views.sessions = renderAnalyses;
}

export { registerAnalysesFeature, renderAnalyses };

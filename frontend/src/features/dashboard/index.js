function getCtx() {
  const arena = window.DecisionArena;
  return {
    state: arena.store.state,
    escHtml: arena.utils.escHtml,
    t: (key) => window.i18n?.t(key) ?? key,
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

function formatPct(v) {
  if (typeof v !== 'number' || !Number.isFinite(v)) return '—';
  return `${Math.round(v * 100)}%`;
}

function formatNumber(v) {
  if (typeof v !== 'number' || !Number.isFinite(v)) return '—';
  return String(Math.round(v * 100) / 100);
}

function kpiCard(title, value, subtitle = '', tone = '', options = {}) {
  const toneClass = tone ? ` dashboard-kpi-${tone}` : '';
  const clickable = options.clickable ? ' cursor:pointer;' : '';
  const attrs = options.clickable
    ? ` data-action="open-dashboard-kpi-detail" data-detail-kind="${options.detailKind || ''}" role="button" tabindex="0"`
    : '';
  return `
    <div class="card dashboard-kpi${toneClass}" style="padding:12px;${clickable}"${attrs}>
      <div style="font-size:12px;color:var(--text-muted);">${title}</div>
      <div style="font-size:24px;font-weight:800;line-height:1.1;margin-top:6px;">${value}</div>
      ${subtitle ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:6px;">${subtitle}</div>` : ''}
      ${options.clickable ? `<div style="font-size:11px;color:var(--text-muted);margin-top:6px;">${options.hint || ''}</div>` : ''}
    </div>
  `;
}

function reliabilityBadge(summary, sectionKey, escHtml, t) {
  const raw = String(summary?.reliability?.kpi_quality?.[sectionKey] || '').trim();
  if (!raw) return '';
  const norm = raw.toLowerCase();
  let cls = 'badge-muted';
  if (norm === 'high') cls = 'badge-success';
  else if (norm === 'medium') cls = 'badge-warning';
  else if (norm.includes('low')) cls = 'badge-danger';
  return `<span class="badge ${cls}">${escHtml(t('dashboard.cockpit.reliability'))}: ${escHtml(raw)}</span>`;
}

function section(title, sectionKey, bodyHtml, collapsed, t, summary, escHtml) {
  return `
    <section class="card" style="padding:14px;margin-bottom:12px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;" data-action="toggle-dashboard-section" data-section-key="${sectionKey}">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <h3 style="margin:0;font-size:15px;">${title}</h3>
          ${reliabilityBadge(summary, sectionKey, escHtml, t)}
        </div>
        <span style="font-size:14px;color:var(--text-muted);">${collapsed ? t('dashboard.cockpit.expand') : t('dashboard.cockpit.collapse')}</span>
      </div>
      ${collapsed ? '' : `<div style="margin-top:10px;">${bodyHtml}</div>`}
    </section>
  `;
}

function renderActivity(summary, escHtml, t) {
  const a = summary?.activity || {};
  const verdict = a?.verdict_breakdown || {};
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.activeAnalyses')), formatNumber(a.active_analyses), '', 'info')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.completedAnalyses')), formatNumber(a.completed_analyses))}
      ${kpiCard('GO', formatNumber(verdict.go), '', 'success')}
      ${kpiCard('ITERATE', formatNumber(verdict.iterate), '', 'warning')}
      ${kpiCard('NO_GO', formatNumber(verdict.no_go), '', 'danger')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.reruns')), formatNumber(a.rerun_analyses))}
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
      <span class="badge badge-info">${escHtml(t('dashboard.cockpit.kpi.activeContext'))}: ${escHtml(String(a?.active_strategic_context?.title || t('dashboard.cockpit.none')))}</span>
      <span class="badge badge-muted">${escHtml(t('dashboard.cockpit.kpi.recentContexts'))}: ${formatNumber(a.contexts_recent_activity)}</span>
      <button class="btn btn-secondary btn-sm" data-nav="analyses">${escHtml(t('dashboard.cockpit.openAnalyses'))}</button>
      <button class="btn btn-secondary btn-sm" data-nav="strategic-contexts">${escHtml(t('dashboard.cockpit.openContexts'))}</button>
    </div>
  `;
}

function renderDecisionQuality(summary, escHtml, t) {
  const q = summary?.decision_quality || {};
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.avgQuality')), formatNumber(q.avg_quality_score))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.falseConsensusRate')), formatPct(q.false_consensus_rate))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.avgConfidence')), formatPct(q.avg_confidence_score))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.fragileRate')), formatPct(q.fragile_rate), '', 'warning')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.blockedRate')), formatPct(q.blocked_rate), '', 'danger')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.avgDebateDepth')), formatNumber(q.avg_debate_depth))}
    </div>
  `;
}

function renderRisks(summary, escHtml, t) {
  const r = summary?.risks || {};
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.criticalOpen')), formatNumber(r.critical_open_analyses), '', 'danger', { clickable: true, detailKind: 'high_risks', hint: escHtml(t('dashboard.cockpit.detail.openHint')) })}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.highRiskDetected')), formatNumber(r.high_risks_detected), '', 'warning', { clickable: true, detailKind: 'high_risks', hint: escHtml(t('dashboard.cockpit.detail.openHint')) })}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.activeContradictions')), formatNumber(r.active_contradictions), '', '', { clickable: true, detailKind: 'active_contradictions', hint: escHtml(t('dashboard.cockpit.detail.openHint')) })}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.interAgentConflicts')), formatNumber(r.inter_agent_conflicts), '', '', { clickable: true, detailKind: 'inter_agent_conflicts', hint: escHtml(t('dashboard.cockpit.detail.openHint')) })}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.contextsHighRisk')), formatNumber(r.contexts_high_risk), '', 'warning')}
    </div>
  `;
}

function renderRuntime(summary, escHtml, t) {
  const rt = summary?.runtime_expert || {};
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeCoverage')), formatPct(rt.coverage_ratio))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeWarnings')), formatNumber(rt.runtime_warnings), '', 'warning')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeRetries')), formatNumber(rt.retries))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeBudgetPressure')), formatNumber(rt.budget_pressure), '', 'warning')}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimePruning')), formatNumber(rt.pruning_events))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeLargeTraces')), formatNumber(rt.large_traces))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeTruncated')), formatNumber(rt.truncated_payloads))}
      ${kpiCard(escHtml(t('dashboard.cockpit.kpi.runtimeQaMode')), formatNumber(rt.qa_mode_active))}
    </div>
  `;
}

function sortContexts(items, sortBy) {
  const sorted = [...items];
  const byDate = (v) => {
    const ts = Date.parse(String(v || ''));
    return Number.isFinite(ts) ? ts : 0;
  };
  sorted.sort((a, b) => {
    switch (sortBy) {
      case 'analyses_desc':
        return Number(b?.analyses_count || 0) - Number(a?.analyses_count || 0);
      case 'reruns_desc':
        return Number(b?.reruns_count || 0) - Number(a?.reruns_count || 0);
      case 'snapshot_desc':
        return byDate(b?.last_snapshot_at) - byDate(a?.last_snapshot_at);
      case 'open_risks_desc':
      default:
        return Number(b?.open_risks_count || 0) - Number(a?.open_risks_count || 0);
    }
  });
  return sorted;
}

function renderContexts(summary, dashboardState, escHtml, t) {
  const rawItems = Array.isArray(summary?.strategic_contexts?.items) ? summary.strategic_contexts.items : [];
  if (rawItems.length === 0) {
    return `<div class="empty-state">${escHtml(t('dashboard.cockpit.emptyContexts'))}</div>`;
  }
  const statusFilter = String(dashboardState.contextsFilterStatus || 'all');
  const highRiskOnly = !!dashboardState.contextsHighRiskOnly;
  const sortBy = String(dashboardState.contextsSortBy || 'open_risks_desc');

  const filtered = rawItems.filter((ctx) => {
    const st = String(ctx?.status || '').toLowerCase();
    if (statusFilter !== 'all' && st !== statusFilter) return false;
    if (highRiskOnly && Number(ctx?.open_risks_count || 0) <= 0) return false;
    return true;
  });
  const items = sortContexts(filtered, sortBy);

  return `
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
      <label style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.filter.status'))}</span>
        <select class="input" data-action="set-dashboard-context-status-filter" style="min-width:140px;">
          <option value="all" ${statusFilter === 'all' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.filter.all'))}</option>
          <option value="active" ${statusFilter === 'active' ? 'selected' : ''}>${escHtml(t('contexts.status.active'))}</option>
          <option value="paused" ${statusFilter === 'paused' ? 'selected' : ''}>${escHtml(t('contexts.status.paused'))}</option>
          <option value="completed" ${statusFilter === 'completed' ? 'selected' : ''}>${escHtml(t('contexts.status.completed'))}</option>
          <option value="abandoned" ${statusFilter === 'abandoned' ? 'selected' : ''}>${escHtml(t('contexts.status.abandoned'))}</option>
        </select>
      </label>
      <label style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.filter.sort'))}</span>
        <select class="input" data-action="set-dashboard-context-sort" style="min-width:180px;">
          <option value="open_risks_desc" ${sortBy === 'open_risks_desc' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.sort.openRisks'))}</option>
          <option value="analyses_desc" ${sortBy === 'analyses_desc' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.sort.analyses'))}</option>
          <option value="reruns_desc" ${sortBy === 'reruns_desc' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.sort.reruns'))}</option>
          <option value="snapshot_desc" ${sortBy === 'snapshot_desc' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.sort.snapshot'))}</option>
        </select>
      </label>
      <label style="display:flex;gap:6px;align-items:center;">
        <input type="checkbox" data-action="toggle-dashboard-context-high-risk-only" ${highRiskOnly ? 'checked' : ''} />
        <span style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.filter.highRiskOnly'))}</span>
      </label>
      <span class="badge badge-muted">${escHtml(t('dashboard.cockpit.filter.visibleCount'))}: ${items.length}/${rawItems.length}</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
      ${items.map((ctx) => `
        <div class="card" style="padding:12px;">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <strong>${escHtml(String(ctx.title || ctx.context_id || ''))}</strong>
            ${Number(ctx.is_workspace_active) === 1 ? '<span class="badge badge-success">workspace</span>' : ''}
            <span class="badge badge-muted">${escHtml(String(ctx.status || ''))}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:8px;font-size:12px;">
            <span>${escHtml(t('dashboard.cockpit.kpi.ctxAnalyses'))}: <strong>${formatNumber(ctx.analyses_count)}</strong></span>
            <span>${escHtml(t('dashboard.cockpit.kpi.ctxDecisions'))}: <strong>${formatNumber(ctx.major_decisions_count)}</strong></span>
            <span>${escHtml(t('dashboard.cockpit.kpi.ctxOpenRisks'))}: <strong>${formatNumber(ctx.open_risks_count)}</strong></span>
            <span>${escHtml(t('dashboard.cockpit.kpi.ctxReruns'))}: <strong>${formatNumber(ctx.reruns_count)}</strong></span>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
            ${escHtml(t('dashboard.cockpit.kpi.ctxLastSnapshot'))}: ${escHtml(String(ctx.last_snapshot_at || t('dashboard.cockpit.none')))}
          </div>
          <div style="font-size:11px;color:var(--text-muted);">
            ${escHtml(t('dashboard.cockpit.kpi.ctxLastCompilation'))}: ${escHtml(String(ctx.last_memory_compilation_at || t('dashboard.cockpit.none')))}
          </div>
        </div>
      `).join('')}
    </div>
    ${items.length === 0 ? `<div class="empty-state" style="margin-top:10px;">${escHtml(t('dashboard.cockpit.filter.emptyAfterFilter'))}</div>` : ''}
  `;
}

function renderScopeSelector(summary, dashboardState, escHtml, t) {
  const items = Array.isArray(summary?.strategic_contexts?.items) ? summary.strategic_contexts.items : [];
  const selected = String(dashboardState.scopeContextId || 'auto');
  const pickerOpen = !!dashboardState.scopePickerOpen;
  const selectedLabel = selected === 'auto'
    ? t('dashboard.cockpit.scope.auto')
    : selected === 'all'
      ? t('dashboard.cockpit.scope.all')
      : (items.find((i) => String(i?.context_id || '') === selected)?.title || selected);

  return `
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button class="btn btn-secondary btn-sm" data-action="toggle-dashboard-scope-picker">
        ${escHtml(t('dashboard.cockpit.scope'))}: ${escHtml(String(selectedLabel))}
      </button>
      ${pickerOpen ? `
        <label style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.scope.select'))}</span>
          <select class="input" data-action="set-dashboard-scope" style="min-width:260px;">
            <option value="auto" ${selected === 'auto' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.scope.auto'))}</option>
            <option value="all" ${selected === 'all' ? 'selected' : ''}>${escHtml(t('dashboard.cockpit.scope.all'))}</option>
            ${items.map((ctx) => {
              const cid = String(ctx?.context_id || '');
              const label = String(ctx?.title || cid);
              return `<option value="${escHtml(cid)}" ${selected === cid ? 'selected' : ''}>${escHtml(label)}</option>`;
            }).join('')}
          </select>
        </label>
      ` : ''}
    </div>
  `;
}

function renderRiskDetails(summary, dashboardState, escHtml, t) {
  const panel = dashboardState.detailPanel || { open: false, kind: '' };
  if (!panel.open) return '';
  const kind = String(panel.kind || '');
  const details = summary?.risks?.details || {};
  const rows = Array.isArray(details?.[kind]) ? details[kind] : [];
  const titleByKind = {
    active_contradictions: t('dashboard.cockpit.detail.activeContradictions'),
    inter_agent_conflicts: t('dashboard.cockpit.detail.interAgentConflicts'),
    high_risks: t('dashboard.cockpit.detail.highRisks'),
  };
  const title = titleByKind[kind] || t('dashboard.cockpit.detail.title');

  let content = '';
  if (!rows.length) {
    content = `<div class="empty-state">${escHtml(t('dashboard.cockpit.detail.empty'))}</div>`;
  } else if (kind === 'active_contradictions') {
    content = rows.map((r) => `
      <div class="card" style="padding:10px;margin-bottom:8px;">
        <div><strong>${escHtml(String(r.event_type || 'event'))}</strong> · ${escHtml(t('dashboard.cockpit.detail.session'))} ${escHtml(String(r.session_id || ''))}</div>
        <div style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.detail.source'))}: ${escHtml(String(r.source_agent_id || ''))} · ${escHtml(t('dashboard.cockpit.detail.target'))}: ${escHtml(String(r.target_agent_id || t('dashboard.cockpit.none')))}</div>
        <div style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.detail.intensity'))}: ${escHtml(formatNumber(Number(r.intensity || 0)))} · ${escHtml(String(r.created_at || ''))}</div>
        ${r.evidence ? `<div style="margin-top:6px;font-size:12px;">${escHtml(String(r.evidence || ''))}</div>` : ''}
      </div>
    `).join('');
  } else if (kind === 'inter_agent_conflicts') {
    content = rows.map((r) => `
      <div class="card" style="padding:10px;margin-bottom:8px;">
        <div><strong>${escHtml(t('dashboard.cockpit.detail.session'))} ${escHtml(String(r.session_id || ''))}</strong></div>
        <div style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.detail.source'))}: ${escHtml(String(r.source_agent_id || ''))} · ${escHtml(t('dashboard.cockpit.detail.target'))}: ${escHtml(String(r.target_agent_id || ''))}</div>
        <div style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.detail.conflict'))}: ${escHtml(formatNumber(Number(r.conflict || 0)))} · ${escHtml(t('dashboard.cockpit.detail.trust'))}: ${escHtml(formatNumber(Number(r.trust || 0)))}</div>
      </div>
    `).join('');
  } else {
    content = rows.map((r) => `
      <div class="card" style="padding:10px;margin-bottom:8px;">
        <div><strong>${escHtml(String(r.title || r.session_id || ''))}</strong></div>
        <div style="font-size:12px;color:var(--text-muted);">${escHtml(t('dashboard.cockpit.detail.riskLevel'))}: ${escHtml(String(r.risk_level || ''))} · ${escHtml(t('dashboard.cockpit.detail.status'))}: ${escHtml(String(r.status || ''))}</div>
      </div>
    `).join('');
  }

  return `
    <div class="dashboard-detail-drawer-wrap">
      <button class="dashboard-detail-drawer-overlay" data-action="close-dashboard-kpi-detail" aria-label="${escHtml(t('dashboard.cockpit.detail.close'))}"></button>
      <aside class="dashboard-detail-drawer" role="dialog" aria-modal="true" aria-label="${escHtml(title)}">
        <div class="dashboard-detail-drawer-head">
          <h3 style="margin:0;font-size:14px;">${escHtml(title)}</h3>
          <button class="btn btn-secondary btn-sm" data-action="close-dashboard-kpi-detail">${escHtml(t('dashboard.cockpit.detail.close'))}</button>
        </div>
        <div class="dashboard-detail-drawer-body">${content}</div>
      </aside>
    </div>
  `;
}

function renderDashboardContent(summary, dashboardState, collapsed, isAdvancedOrExpert, isExpert, escHtml, t) {
  return `
    ${section(escHtml(t('dashboard.cockpit.section.activity')), 'activity', renderActivity(summary, escHtml, t), !!collapsed.activity, t, summary, escHtml)}
    ${section(escHtml(t('dashboard.cockpit.section.risks')), 'risks', renderRisks(summary, escHtml, t), !!collapsed.risks, t, summary, escHtml)}
    ${isAdvancedOrExpert ? section(escHtml(t('dashboard.cockpit.section.quality')), 'decision_quality', renderDecisionQuality(summary, escHtml, t), !!collapsed.decision_quality, t, summary, escHtml) : ''}
    ${section(escHtml(t('dashboard.cockpit.section.contexts')), 'strategic_contexts', renderContexts(summary, dashboardState, escHtml, t), !!collapsed.strategic_contexts, t, summary, escHtml)}
    ${isExpert ? section(escHtml(t('dashboard.cockpit.section.runtime')), 'runtime_expert', renderRuntime(summary, escHtml, t), !!collapsed.runtime_expert, t, summary, escHtml) : ''}
  `;
}

function renderDashboardHeader(summary, dashboardState, metaScope, lastLoaded, escHtml, t) {
  return `
    <div class="page-header">
      <div class="page-title">${escHtml(t('dashboard.cockpit.title'))}</div>
      <div class="page-subtitle">${escHtml(t('dashboard.cockpit.subtitle'))}</div>
    </div>
    <div class="card" style="padding:12px;margin-bottom:12px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        ${renderScopeSelector(summary, dashboardState, escHtml, t)}
        <span class="badge badge-muted">${escHtml(t('dashboard.cockpit.scope.mode'))}: ${escHtml(String(metaScope))}</span>
        <span class="badge badge-muted">${escHtml(t('dashboard.cockpit.lastRefresh'))}: ${escHtml(String(lastLoaded || t('dashboard.cockpit.none')))}</span>
        <button class="btn btn-secondary btn-sm" data-action="refresh-dashboard-summary">↺ ${escHtml(t('dashboard.cockpit.refresh'))}</button>
      </div>
    </div>
  `;
}

function renderDashboardCockpit() {
  const { state, escHtml, t } = getCtx();
  const dashboardState = ensureDashboardState(state);
  const summary = dashboardState.data;
  const isExpert = state.uiMode === 'expert';
  const isAdvancedOrExpert = state.uiMode === 'expert'; // app currently supports basic/expert

  const collapsed = dashboardState.collapsedSections || {};
  const loadingHtml = dashboardState.loading
    ? `<div class="alert alert-info">${escHtml(t('dashboard.cockpit.loading'))}</div>`
    : '';
  const errorHtml = dashboardState.error
    ? `<div class="alert alert-danger">${escHtml(dashboardState.error)}</div>`
    : '';
  const emptyHtml = !summary && !dashboardState.loading
    ? `<div class="empty-state"><p>${escHtml(t('dashboard.cockpit.empty'))}</p><button class="btn btn-primary btn-sm" data-action="load-dashboard-summary">${escHtml(t('dashboard.cockpit.loadNow'))}</button></div>`
    : '';

  const metaScope = summary?.scope?.mode || 'unknown';
  const lastLoaded = dashboardState.lastLoadedAt || summary?.generated_at || null;

  const mainContent = summary
    ? renderDashboardContent(summary, dashboardState, collapsed, isAdvancedOrExpert, isExpert, escHtml, t)
    : '';
  const detailDrawer = summary ? renderRiskDetails(summary, dashboardState, escHtml, t) : '';

  return `
    ${renderDashboardHeader(summary, dashboardState, metaScope, lastLoaded, escHtml, t)}
    ${loadingHtml}
    ${errorHtml}
    ${emptyHtml}
    ${mainContent}
    ${detailDrawer}
  `;
}

function registerDashboardFeature() {
  window.DecisionArena.views.dashboard = renderDashboardCockpit;
}

export { registerDashboardFeature, renderDashboardCockpit };

/**
 * Argument Heatmap — view module.
 * State: state.heatmapData | null, state.heatmapLoading | false, state.heatmapFilter | 'all'
 */

import { renderPanelRecommendBadge, renderTooltip } from '../../ui/components.js';

function panelHl(state) {
  return state.sessionHistory?.panelHighlights || state.auditData?.highlights || [];
}

function heatmapTitleRow(t, state) {
  return `🔥 ${t('heatmap.title')} ${renderTooltip(t('tooltip.heatmap'))} ${renderPanelRecommendBadge('heatmap', panelHl(state), t)}`;
}

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

function renderArgumentHeatmapPanel(sessionId) {
  const { state, escHtml, t } = getCtx();
  const data        = state.heatmapData    ?? null;
  const loading     = state.heatmapLoading ?? false;
  const error       = state.heatmapError   ?? null;
  const filter      = state.heatmapFilter  ?? 'all';
  const sessionMode = state.sessionHistory?.session?.mode ?? state.currentSession?.mode ?? null;

  if (loading) {
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${heatmapTitleRow(t, state)}</div>
      <div class="loading-state" style="padding:24px 0;"><span class="spinner"></span> ${t('heatmap.loading')}</div>
    </div>`;
  }

  if (error) {
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${heatmapTitleRow(t, state)}</div>
      <div style="padding:12px 0;color:var(--danger);font-size:13px;">
        ⚠️ ${t('heatmap.error')} <span style="color:var(--text-muted);font-size:11px;">${escHtml(error)}</span>
        <div style="margin-top:10px;"><button class="btn btn-secondary btn-sm" data-action="load-argument-heatmap" data-session-id="${escHtml(sessionId)}">⟳ ${t('heatmap.load')}</button></div>
      </div>
    </div>`;
  }

  if (!data) {
    const isChatMode = sessionMode === 'chat';
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${heatmapTitleRow(t, state)}</div>
      <div class="card-description">${t('panel.heatmap.desc')}</div>
      <div class="card-usage">${t('panel.heatmap.usage')}</div>
      <div style="padding:12px 0;">
        ${isChatMode
          ? `<p style="color:var(--text-secondary);font-size:13px;margin:0;font-style:italic;">ℹ️ ${t('heatmap.chatModeNotice')}</p>`
          : `<p style="color:var(--text-secondary);font-size:13px;margin:0 0 12px;">${t('heatmap.noData')}</p>
             <button class="btn btn-secondary btn-sm" data-action="load-argument-heatmap" data-session-id="${escHtml(sessionId)}">${t('heatmap.load')}</button>`
        }
      </div>
    </div>`;
  }

  const items = data.items ?? [];
  const filterTypes = [
    ['all', t('heatmap.filter.all')],
    ['risk', t('heatmap.filter.risk')],
    ['claim', t('heatmap.filter.claim')],
    ['assumption', t('heatmap.filter.assumption')],
    ['counter_argument', t('heatmap.filter.counter')],
  ];
  const filtered = filter === 'all' ? items : items.filter((i) => i.type === filter);

  const typeColors = {
    risk: '#f87171', claim: '#60a5fa', assumption: '#fbbf24',
    counter_argument: '#a78bfa', question: '#34d399',
  };

  const itemsHtml = filtered.slice(0, 15).map((item) => {
    const pct   = Math.round((item.dominance_score ?? 0) * 100);
    const color = typeColors[item.type] ?? 'var(--accent)';
    const agentsStr = (item.agents ?? []).join(', ');
    const barWidth = pct;
    return `
      <div class="heatmap-item">
        <div class="heatmap-item-header">
          <span class="badge" style="background:${color}20;color:${color};font-size:10px;text-transform:uppercase;letter-spacing:.05em;">${escHtml(item.type ?? 'claim')}</span>
          <span class="heatmap-item-label">${escHtml(item.label ?? '')}</span>
        </div>
        <div class="heatmap-dominance-row">
          <span style="font-size:11px;color:var(--text-muted);min-width:70px;">${t('heatmap.dominance')}</span>
          <div class="heatmap-bar-wrap">
            <div class="heatmap-bar" style="width:${barWidth}%;background:${color};"></div>
          </div>
          <span style="font-size:11px;font-weight:700;color:${color};min-width:32px;">${pct}%</span>
        </div>
        <div class="heatmap-meta">
          ${agentsStr ? `<span class="badge badge-muted">${escHtml(agentsStr)}</span>` : ''}
          <span class="badge badge-neutral">${item.mentions ?? 0} ${t('heatmap.mentions')}</span>
          ${(item.challenge_count ?? 0) > 0 ? `<span class="badge badge-danger">${item.challenge_count} ${t('heatmap.challenged')}</span>` : ''}
          ${(item.support_count ?? 0) > 0 ? `<span class="badge badge-success">${item.support_count} ${t('heatmap.supported')}</span>` : ''}
        </div>
        ${item.summary ? `<div class="heatmap-summary">${escHtml(item.summary)}</div>` : ''}
      </div>
    `;
  }).join('');

  const filtersHtml = filterTypes.map(([type, label]) => `
    <button class="btn btn-sm ${filter === type ? 'btn-primary' : 'btn-secondary'}" 
      data-action="set-heatmap-filter" data-filter="${type}" data-session-id="${escHtml(sessionId)}"
      style="font-size:11px;padding:3px 8px;">${label}</button>
  `).join('');

  return `
    <div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span>${heatmapTitleRow(t, state)}</span>
        <button class="btn btn-secondary btn-sm" data-action="load-argument-heatmap" data-session-id="${escHtml(sessionId)}"
          style="font-size:11px;padding:3px 8px;">⟳</button>
      </div>
      <div class="heatmap-filters" style="display:flex;flex-wrap:wrap;gap:6px;margin:10px 0;">${filtersHtml}</div>
      <div class="heatmap-list">${itemsHtml || `<p style="color:var(--text-muted);font-size:13px;">${t('heatmap.noData')}</p>`}</div>
    </div>
  `;
}

function registerArgumentHeatmapFeature() {
  if (!window.DecisionArena.views.shared) window.DecisionArena.views.shared = {};
  window.DecisionArena.views.shared.renderArgumentHeatmapPanel = renderArgumentHeatmapPanel;
}

export { registerArgumentHeatmapFeature, renderArgumentHeatmapPanel };

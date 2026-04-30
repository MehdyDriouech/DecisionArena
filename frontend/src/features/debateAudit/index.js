/**
 * Debate Quality Audit — view module.
 *
 * Exposes renderDebateAuditPanel() which can be embedded in any session
 * detail view (Decision Room, Confrontation, Stress Test, Quick Decision).
 *
 * State used:
 *   state.auditData    — { score, metrics, summary, warnings } | null
 *   state.auditLoading — boolean
 */

import { renderPanelRecommendBadge, renderTooltip } from '../../ui/components.js';

function panelHl(state) {
  return state.sessionHistory?.panelHighlights || state.auditData?.highlights || [];
}

function auditTitleRow(t, state) {
  return `🎯 ${t('audit.title')} ${renderTooltip(t('tooltip.audit'))} ${renderPanelRecommendBadge('audit', panelHl(state), t)}`;
}

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

/**
 * Render a metric bar row.
 * @param {string} label
 * @param {number} value   0-10
 * @param {boolean} inverted  true = lower is better (redundancy)
 */
function renderMetricBar(label, value, inverted = false) {
  const pct   = (value / 10) * 100;
  const color = inverted
    ? (value <= 3 ? '#34d399' : value <= 6 ? '#fbbf24' : '#f87171')
    : (value >= 7 ? '#34d399' : value >= 4 ? '#fbbf24' : '#f87171');

  return `
    <div class="audit-metric-row">
      <div class="audit-metric-label">${label}</div>
      <div class="audit-metric-bar-wrap">
        <div class="audit-metric-bar" style="width:${pct}%;background:${color};"></div>
      </div>
      <div class="audit-metric-value">${value}/10</div>
    </div>
  `;
}

/**
 * Returns the HTML for the Debate Quality Audit panel.
 * Reads from state.auditData / state.auditLoading.
 *
 * @param {string} sessionId — used for the data attribute on the action button
 */
function renderDebateAuditPanel(sessionId) {
  const { state, escHtml, t } = getCtx();

  const data        = state.auditData    ?? null;
  const loading     = state.auditLoading ?? false;
  const error       = state.auditError   ?? null;
  const sessionMode = state.sessionHistory?.session?.mode ?? state.currentSession?.mode ?? null;

  const hasData = data && typeof data === 'object';

  // ── loading state ──────────────────────────────────────────────────────
  if (loading) {
    return `
      <div class="card debate-card audit-card" style="margin:16px 0;">
        <div class="debate-card-title">${auditTitleRow(t, state)}</div>
        <div class="loading-state" style="padding:24px 0;">
          <span class="spinner"></span> ${t('audit.running')}
        </div>
      </div>
    `;
  }

  // ── error state ────────────────────────────────────────────────────────
  if (error) {
    return `
      <div class="card debate-card audit-card" style="margin:16px 0;">
        <div class="debate-card-title">${auditTitleRow(t, state)}</div>
        <div class="audit-empty" style="color:var(--danger);">
          <p style="font-size:13px;margin:0 0 10px;">⚠️ ${t('audit.error')} <span style="color:var(--text-muted);font-size:11px;">${escHtml(error)}</span></p>
          <button class="btn btn-secondary btn-sm" data-action="run-debate-audit" data-session-id="${escHtml(sessionId)}">⟳ ${t('audit.run')}</button>
        </div>
      </div>
    `;
  }

  // ── empty state ────────────────────────────────────────────────────────
  if (!hasData) {
    const isChatMode = sessionMode === 'chat';
    return `
      <div class="card debate-card audit-card" style="margin:16px 0;">
        <div class="debate-card-title">${auditTitleRow(t, state)}</div>
        <div class="card-description">${t('panel.audit.desc')}</div>
        <div class="card-usage">${t('panel.audit.usage')}</div>
        <div class="audit-empty" style="margin-top:10px;">
          ${isChatMode
            ? `<p style="color:var(--text-secondary);font-size:13px;margin:0;font-style:italic;">ℹ️ ${t('audit.chatModeNotice')}</p>`
            : `<p style="color:var(--text-secondary);font-size:13px;margin:0 0 12px;">${t('audit.noData')}</p>
               <button class="btn btn-secondary btn-sm" data-action="run-debate-audit" data-session-id="${escHtml(sessionId)}">${t('audit.run')}</button>`
          }
        </div>
      </div>
    `;
  }

  // ── results ────────────────────────────────────────────────────────────
  const score    = data.score   ?? 0;
  const metrics  = data.metrics ?? {};
  const summary  = data.summary ?? '';
  const warnings = data.warnings ?? [];

  const scoreColor = score >= 70 ? '#34d399' : score >= 45 ? '#fbbf24' : '#f87171';

  const metricKeys = [
    ['interaction_density',  false],
    ['argument_reuse',       false],
    ['disagreement_quality', false],
    ['position_evolution',   false],
    ['redundancy',           true],
  ];

  const barsHtml = metricKeys.map(([key, inv]) =>
    renderMetricBar(t(`audit.metric.${key}`), metrics[key] ?? 0, inv)
  ).join('');

  const warningsHtml = warnings.length > 0
    ? `<div class="audit-warnings">
        <div class="audit-warnings-title">⚠️ ${t('audit.warnings')}</div>
        ${warnings.map((w) => `<div class="audit-warning-item">${escHtml(w)}</div>`).join('')}
       </div>`
    : '';

  return `
    <div class="card debate-card audit-card" style="margin:16px 0;">
      <div class="debate-card-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span>${auditTitleRow(t, state)}</span>
        <button class="btn btn-secondary btn-sm" data-action="run-debate-audit" data-session-id="${escHtml(sessionId)}"
          style="font-size:11px;padding:3px 8px;">
          ${t('audit.retry')}
        </button>
      </div>

      <div class="audit-score-row">
        <div class="audit-score-circle" style="border-color:${scoreColor};color:${scoreColor};">
          ${score}
        </div>
        <div class="audit-score-label">${t('audit.score')}</div>
      </div>

      <div class="audit-metrics">${barsHtml}</div>

      ${summary ? `<div class="audit-summary">${escHtml(summary)}</div>` : ''}
      ${warningsHtml}
    </div>
  `;
}

function registerDebateAuditFeature() {
  if (!window.DecisionArena.views.shared) window.DecisionArena.views.shared = {};
  window.DecisionArena.views.shared.renderDebateAuditPanel = renderDebateAuditPanel;
}

export { registerDebateAuditFeature, renderDebateAuditPanel };

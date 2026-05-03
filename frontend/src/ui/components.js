function createErrorBanner(message, clearLabel = 'Clear') {
  if (!message) return '';
  return `
    <div class="error-banner">
      ⚠️ ${message}
      <button data-action="clear-error">${clearLabel}</button>
    </div>
  `;
}

function mountHtml(target, html) {
  if (!target) return;
  target.innerHTML = html;
}

/**
 * Renders a contextual description + optional "when to use" line below a card title.
 * @param {{ text: string, usage?: string }} opts
 */
function renderCardDescription({ text, usage = '' }) {
  if (!text) return '';
  return `<div class="card-description">${text}</div>${usage ? `<div class="card-usage">👉 ${usage}</div>` : ''}`;
}

/**
 * Renders a pure-CSS tooltip badge (shows on hover, no JS).
 * @param {string} tooltip — tooltip text
 */
function renderTooltip(tooltip) {
  if (!tooltip) return '';
  // Escape quotes for attribute safety
  const safe = tooltip.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  return `<span class="info-tooltip" data-tooltip="${safe}" aria-label="${safe}">?</span>`;
}

function renderPanelRecommendBadge(panelType, highlights, t) {
  const list = Array.isArray(highlights) ? highlights : [];
  const h = list.find((x) => x && x.type === panelType);
  if (!h) return '';
  const key = `highlight.reason.${h.reason_key}`;
  const reason = t(key);
  const text = reason !== key ? reason : String(h.reason_key || '').replace(/_/g, ' ');
  return `<span class="panel-rec-badge"><span class="badge badge-info" style="font-size:10px;">${t('highlight.recommended')}</span>${renderTooltip(text)}</span>`;
}

/**
 * Decision-first summary card, safe for missing fields.
 * @param {{ decision?: string, confidence?: string|number, why?: string[]|string, risks?: string[]|string, next_step?: string, primary_warning?: string, quality_score?: number, reliability?: string }} data
 * @param {{ sessionId?: string }} opts
 */
function renderDecisionBrief(data, opts = {}) {
  if (!data || typeof data !== 'object') return '';
  const d = data || {};
  const hasCore = Boolean(d.decision || d.confidence || d.next_step || d.primary_warning || d.quality_score != null);
  const whyList = Array.isArray(d.why) ? d.why.filter(Boolean) : (d.why ? [String(d.why)] : []);
  const riskList = Array.isArray(d.risks) ? d.risks.filter(Boolean) : (d.risks ? [String(d.risks)] : []);
  if (!hasCore && whyList.length === 0 && riskList.length === 0) return '';

  const escHtml = window.DecisionArena?.utils?.escHtml || ((v) => String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;'));
  const t = (key) => window.i18n?.t(key) ?? key;

  const colorMap = {
    GO_CONFIDENT: '#15803d', GO_FRAGILE: '#854d0e',
    NO_GO_CONFIDENT: '#991b1b', NO_GO_FRAGILE: '#92400e',
    ITERATE_CONFIDENT: '#92400e', ITERATE_FRAGILE: '#78350f',
    NO_CONSENSUS: '#7f1d1d', NO_CONSENSUS_FRAGILE: '#7f1d1d',
    INSUFFICIENT_CONTEXT: '#374151',
  };
  const outcome = `${String(d.decision || '').toUpperCase()}_${String(d.reliability || '').toUpperCase()}`;
  const bgColor = colorMap[outcome] || colorMap[String(d.decision || '').toUpperCase()] || '#374151';
  const sessionId = opts.sessionId || '';
  const hasSessionActions = Boolean(sessionId);

  const confidenceLabel = d.confidence != null ? String(d.confidence) : '—';
  const scoreLabel = d.quality_score != null ? `${d.quality_score}/100` : '—';

  return `
<div class="decision-brief-card" style="margin-bottom:16px;">
  <div class="brief-header" style="background:${bgColor}">
    <span class="brief-decision">${escHtml(d.decision || 'Decision')}</span>
    <span class="brief-meta">${escHtml(String(d.reliability || ''))} · ${escHtml(confidenceLabel)} · ${t('brief.score')}: ${escHtml(scoreLabel)}</span>
  </div>
  <div class="brief-body">
    ${whyList.length ? `<p><strong>${t('brief.why')}:</strong> ${whyList.map((w) => `<span>${escHtml(w)}</span>`).join(' ')}</p>` : ''}
    ${riskList.length ? `<p><strong>${t('brief.risks')}:</strong> ${riskList.map((r) => `<span>${escHtml(r)}</span>`).join(' ')}</p>` : ''}
    ${d.next_step ? `<p><strong>${t('brief.next_step')}:</strong> ${escHtml(d.next_step)}</p>` : ''}
    ${d.primary_warning ? `<div class="brief-warning">⚠ ${escHtml(d.primary_warning)}</div>` : ''}
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;padding:0 14px 14px;">
    ${hasSessionActions ? `<button class="btn btn-secondary btn-sm" data-action="show-debate-details">Voir le debat complet</button>` : ''}
    <details>
      <summary class="btn btn-secondary btn-sm" style="list-style:none;">Voir details</summary>
      <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);">
        <div><strong>Decision:</strong> ${escHtml(d.decision || '—')}</div>
        <div><strong>Confiance:</strong> ${escHtml(confidenceLabel)}</div>
        <div><strong>Qualite:</strong> ${escHtml(scoreLabel)}</div>
      </div>
    </details>
    ${hasSessionActions ? `<button class="btn btn-secondary btn-sm" data-action="rerun-with-contradiction" data-session-id="${escHtml(sessionId)}">Relancer avec contradiction</button>` : ''}
  </div>
</div>`;
}

export {
  createErrorBanner,
  mountHtml,
  renderCardDescription,
  renderTooltip,
  renderPanelRecommendBadge,
  renderDecisionBrief,
};

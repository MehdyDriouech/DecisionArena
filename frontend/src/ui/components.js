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

export {
  createErrorBanner,
  mountHtml,
  renderCardDescription,
  renderTooltip,
  renderPanelRecommendBadge,
};

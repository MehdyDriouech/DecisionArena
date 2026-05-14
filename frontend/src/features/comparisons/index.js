/**
 * Session Comparisons feature – view registration.
 */

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, formatDate } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, renderMarkdown, formatDate, t };
}

function renderSessionComparisons() {
  const { state, escHtml, formatDate, t } = getCtx();
  const comps    = state.comparisons || [];
  const sessions = state.sessions || [];
  const selected = state.compareSelectedIds || [];

  return `
    <div style="max-width:900px;margin:0 auto;padding:24px 20px;">
      <div class="page-header">
        <div class="page-title">⚖️ ${t('compare.title')}</div>
        <div class="page-subtitle">${t('compare.subtitle')}</div>
        <button class="btn btn-secondary btn-sm" data-nav="dashboard">${t('nav.back')}</button>
      </div>

      <div class="card" style="padding:20px;margin-bottom:20px;">
        <div style="font-weight:600;font-size:14px;margin-bottom:12px;">${t('compare.selectSessions')}</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">${t('compare.selectHint')}</div>
        <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;">
          ${sessions.length === 0
            ? `<div style="padding:16px;text-align:center;color:var(--text-muted);">${t('sessions.empty')}</div>`
            : sessions.map((s) => `
                <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;">
                  <input type="checkbox" data-action="toggle-compare-session" data-session-id="${escHtml(s.id)}" ${selected.includes(s.id) ? 'checked' : ''} style="accent-color:var(--accent);flex-shrink:0;">
                  <span style="font-size:16px;">${{ chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥' }[s.mode] || '💬'}</span>
                  <span style="flex:1;min-width:0;">
                    <span style="font-weight:600;font-size:13px;">${escHtml(s.title)}</span>
                    <span style="font-size:11px;color:var(--text-muted);margin-left:8px;">${s.mode} · ${formatDate(s.created_at)}</span>
                  </span>
                </label>
              `).join('')
          }
        </div>
        <div style="display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
          <button class="btn btn-primary" data-action="create-comparison" ${selected.length < 2 || state.comparisonLoading ? 'disabled' : ''}>
            ${state.comparisonLoading ? '<span class="spinner"></span>' : '⚖️'} ${t('compare.compare')} ${selected.length >= 2 ? `(${selected.length})` : ''}
          </button>
          ${selected.length < 2 ? `<span style="font-size:12px;color:var(--text-muted);">${t('compare.selectHint')}</span>` : ''}
        </div>
      </div>

      ${comps.length > 0 ? `
        <div class="section">
          <div class="section-header"><span class="section-label">${t('compare.savedComparisons')}</span></div>
          ${comps.map((c) => `
            <div class="session-card-full" style="margin-bottom:10px;">
              <div class="session-card-full-header">
                <span class="session-icon" style="font-size:20px;">⚖️</span>
                <div style="flex:1;">
                  <div style="font-weight:600;">${escHtml(c.title || t('compare.untitled'))}</div>
                  <div style="font-size:12px;color:var(--text-muted);">${formatDate(c.created_at)} · ${(c.session_ids || []).length} ${t('compare.sessions')}</div>
                </div>
              </div>
              <div class="session-card-full-actions">
                <button class="btn btn-primary btn-sm" data-action="open-comparison" data-comp-id="${escHtml(c.id)}">${t('compare.open')}</button>
                <button class="btn btn-danger btn-sm" data-action="delete-comparison" data-comp-id="${escHtml(c.id)}">${t('compare.delete')}</button>
              </div>
            </div>
          `).join('')}
        </div>
      ` : ''}
    </div>
  `;
}

function renderSessionComparisonView() {
  const { state, escHtml, renderMarkdown, formatDate, t } = getCtx();
  const comp = state.currentComparison;
  if (!comp) return `<div class="view-container"><p>${t('compare.notFound')}</p></div>`;

  return `
    <div style="max-width:900px;margin:0 auto;padding:24px 20px;">
      <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;">
        <div>
          <div class="page-title">⚖️ ${escHtml(comp.title || t('compare.title'))}</div>
          <div class="page-subtitle">${formatDate(comp.created_at)}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
          <button class="btn btn-secondary btn-sm" data-action="export-comparison" data-comp-id="${escHtml(comp.id)}">📄 ${t('compare.exportMd')}</button>
          <button class="btn btn-danger btn-sm" data-action="delete-comparison" data-comp-id="${escHtml(comp.id)}">${t('compare.delete')}</button>
          <button class="btn btn-secondary btn-sm" data-nav="session-comparisons">${t('nav.back')}</button>
        </div>
      </div>
      <div class="card" style="padding:24px;">
        <div class="md-content">${renderMarkdown(comp.content_markdown || '')}</div>
      </div>
    </div>
  `;
}

function registerComparisonsFeature() {
  window.DecisionArena.views['session-comparisons'] = renderSessionComparisons;
  window.DecisionArena.views['session-comparison']  = renderSessionComparisonView;
}

export { registerComparisonsFeature, renderSessionComparisons, renderSessionComparisonView };

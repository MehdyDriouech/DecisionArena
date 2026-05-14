function esc(s) {
  return window.DecisionArena.utils.escHtml(String(s ?? ''));
}

function renderExperimentalFeatureGate() {
  const { state } = window.DecisionArena.store;
  const t = (k) => (window.i18n?.t?.(k) ?? k);
  const isExpert = window.DecisionArena.store.normalizeUiMode(state.uiMode) === 'expert';
  const body = isExpert
    ? t('experimental.featureDisabled.bodyExpert')
    : t('experimental.featureDisabled.bodyBasic');
  const expertCta = !isExpert
    ? `<button type="button" class="btn btn-secondary btn-sm" data-action="set-ui-mode" data-ui-mode="expert">${esc(t('experimental.featureDisabled.goExpert'))}</button>`
    : '';
  return `
    <div class="page-header">
      <div class="page-title">${esc(t('experimental.featureDisabled.title'))}</div>
      <div class="page-subtitle">${esc(body)}</div>
    </div>
    <div class="card" style="padding:18px 20px;max-width:640px;border:1px solid var(--border-color);background:var(--bg-secondary, rgba(255,255,255,0.03));">
      <div style="font-size:12px;color:var(--text-muted);line-height:1.5;margin-bottom:14px;">${esc(t('experimental.featureDisabled.detailOpenSpace'))}</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        ${isExpert ? `<button type="button" class="btn btn-primary btn-sm" data-nav="administration">${esc(t('experimental.featureDisabled.goAdmin'))}</button>` : ''}
        ${expertCta}
        <button type="button" class="btn btn-secondary btn-sm" data-nav="dashboard">${esc(t('experimental.featureDisabled.goDashboard'))}</button>
      </div>
    </div>
  `;
}

function registerExperimentalGateFeature() {
  window.DecisionArena.views['experimental-gate'] = renderExperimentalFeatureGate;
}

export { registerExperimentalGateFeature, renderExperimentalFeatureGate };

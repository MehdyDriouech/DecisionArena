/**
 * Launch Assistant feature – view registration.
 */

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, agentIcon, agentName, t };
}

function renderLaunchAssistant() {
  const { state, escHtml, agentIcon, agentName, t } = getCtx();
  const la = state.launchAssistant;

  const intents = [
    { id: 'validate-idea',       icon: '💡', label: t('la.intentValidateIdea') },
    { id: 'challenge-product',   icon: '⚔️', label: t('la.intentChallengeProduct') },
    { id: 'review-architecture', icon: '🏗️', label: t('la.intentReviewArch') },
    { id: 'find-risks',          icon: '⚠️', label: t('la.intentFindRisks') },
    { id: 'compare-options',     icon: '⚖️', label: t('la.intentCompareOptions') },
    { id: 'prepare-decision',    icon: '🎯', label: t('la.intentPrepareDecision') },
    { id: 'stress-test-idea',    icon: '🔥', label: t('la.intentStressTest') },
    { id: 'custom',              icon: '🔧', label: t('la.intentCustom') },
  ];

  const rec = la.recommendation;

  return `
    <div style="max-width:800px;margin:0 auto;padding:24px 20px;">
      <div class="page-header">
        <div class="page-title">🚀 ${t('la.title')}</div>
        <div class="page-subtitle">${t('la.subtitle')}</div>
        <button class="btn btn-secondary btn-sm" data-nav="dashboard">${t('nav.back')}</button>
      </div>

      ${la.step === 1 ? `
        <div class="card" style="padding:24px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:16px;">${t('la.step1Question')}</div>
          <div class="agents-select-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));">
            ${intents.map((i) => `
              <label class="agent-select-card ${la.intent === i.id ? 'selected' : ''}" style="cursor:pointer;" data-action="la-select-intent" data-intent="${escHtml(i.id)}">
                <span style="font-size:24px;">${i.icon}</span>
                <div style="font-size:13px;font-weight:600;text-align:center;">${i.label}</div>
              </label>
            `).join('')}
          </div>
          <button class="btn btn-primary" style="margin-top:20px;" data-action="la-next-step" ${!la.intent ? 'disabled' : ''}>${t('la.next')}</button>
        </div>
      ` : ''}

      ${la.step === 2 ? `
        <div class="card" style="padding:24px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:12px;">${t('la.step2Question')}</div>
          <textarea class="textarea" id="la-description" style="min-height:120px;" placeholder="${t('la.descriptionPlaceholder')}">${escHtml(la.description)}</textarea>
          <div style="display:flex;gap:10px;margin-top:16px;">
            <button class="btn btn-secondary" data-action="la-prev-step">${t('la.back')}</button>
            <button class="btn btn-primary" data-action="la-get-recommendation" ${la.loading ? 'disabled' : ''}>
              ${la.loading ? '<span class="spinner"></span>' : '✨'} ${t('la.getRecommendation')}
            </button>
          </div>
        </div>
      ` : ''}

      ${la.step === 3 && rec ? `
        <div class="card" style="padding:24px;margin-bottom:16px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:4px;">✅ ${t('la.recommendationTitle')}</div>
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">${escHtml(rec.explanation || '')}</div>

          <div class="form-row" style="margin-bottom:12px;">
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">${t('newSession.mode')}</div>
              <span class="badge badge-info" style="font-size:13px;padding:4px 10px;">${rec.mode}</span>
            </div>
            <div>
              <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">${t('newSession.rounds')}</div>
              <span class="badge" style="font-size:13px;padding:4px 10px;">${rec.rounds}</span>
            </div>
            ${rec.force_disagreement ? `
              <div>
                <span class="badge badge-warning" style="margin-top:16px;">${t('newSession.forceDisagreementActive')}</span>
              </div>
            ` : ''}
          </div>

          <div style="margin-bottom:16px;">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">${t('newSession.selectAgents')}</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
              ${(rec.selected_agents || []).map((id) => `
                <span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id) || id)}</span>
              `).join('')}
            </div>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-primary" data-action="la-launch-session">
              🚀 ${t('la.launchRecommended')}
            </button>
            <button class="btn btn-secondary" data-action="la-edit-recommendation">
              ✏️ ${t('la.editRecommendation')}
            </button>
            <button class="btn btn-secondary" data-action="la-prev-step">${t('la.back')}</button>
          </div>
        </div>
      ` : ''}

      ${la.step === 4 ? `
        <div class="card" style="padding:24px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:16px;">✏️ ${t('la.editRecommendation')}</div>
          <div class="form-group">
            <label>${t('newSession.sessionTitle')}</label>
            <input class="input" id="la-title" type="text" value="${escHtml(la.editTitle || '')}" placeholder="${t('newSession.titlePlaceholder')}">
          </div>
          <div class="form-group">
            <label>${t('newSession.idea')}</label>
            <textarea class="textarea" id="la-idea" style="min-height:80px;">${escHtml(la.description || '')}</textarea>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn btn-primary" data-action="la-launch-from-edit">🚀 ${t('la.launchRecommended')}</button>
            <button class="btn btn-secondary" data-action="la-back-to-rec">${t('la.back')}</button>
          </div>
        </div>
      ` : ''}
    </div>
  `;
}

function registerLaunchAssistantFeature() {
  window.DecisionArena.views['launch-assistant'] = renderLaunchAssistant;
}

export { registerLaunchAssistantFeature, renderLaunchAssistant };

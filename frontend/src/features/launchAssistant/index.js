/**
 * Launch Assistant feature – view registration.
 */

import { getPlaybookById, getPlaybookIdForLaunchIntent } from '../../core/playbooks.js';

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
  const lang = window.i18n?.getLanguage?.() || 'fr';
  const playbookMeta = (intentId) => {
    const playbook = getPlaybookById(getPlaybookIdForLaunchIntent(intentId), lang);
    if (!playbook) return null;
    return {
      label: playbook.intention,
      description: playbook.tagline,
      duration: playbook.estimated_duration,
      depth: playbook.cognitive_load,
    };
  };
  const intentMeta = {
    'validate-idea': {
      ...playbookMeta('validate-idea'),
    },
    'challenge-product': {
      ...playbookMeta('challenge-product'),
    },
    'review-architecture': {
      description: 'Evaluer une architecture avec plusieurs angles techniques',
      duration: '3-5 min',
      depth: 'Approfondi',
    },
    'find-risks': {
      ...playbookMeta('find-risks'),
    },
    'compare-options': {
      ...playbookMeta('compare-options'),
    },
    'prepare-decision': {
      ...playbookMeta('prepare-decision'),
    },
    'facilitation-workshop': {
      description: '',
      duration: '3-5 min',
      depth: 'Approfondi',
    },
    'stress-test-idea': {
      ...playbookMeta('stress-test-idea'),
    },
    custom: {
      description: 'Configurer manuellement le mode, les agents et le niveau de detail',
      duration: 'Variable',
      depth: 'Avance',
    },
  };

  ['facilitation-workshop'].forEach((k) => {
    if (!intentMeta[k]) return;
    intentMeta[k].description = String(t(`la.${k}Desc`) || '').trim() || intentMeta[k].description;
  });

  const intents = [
    { id: 'validate-idea',       icon: '💡', label: intentMeta['validate-idea']?.label || 'validate-idea' },
    { id: 'challenge-product',   icon: '⚔️', label: intentMeta['challenge-product']?.label || 'challenge-product' },
    { id: 'review-architecture', icon: '🏗️', label: t('la.intentReviewArch') },
    { id: 'find-risks',          icon: '⚠️', label: intentMeta['find-risks']?.label || 'find-risks' },
    { id: 'compare-options',     icon: '⚖️', label: intentMeta['compare-options']?.label || 'compare-options' },
    { id: 'prepare-decision',    icon: '🎯', label: intentMeta['prepare-decision']?.label || 'prepare-decision' },
    { id: 'facilitation-workshop', icon: '🎩', label: t('la.intentFacilitationWorkshop') },
    { id: 'stress-test-idea',    icon: '🔥', label: intentMeta['stress-test-idea']?.label || 'stress-test-idea' },
    { id: 'custom',              icon: '🔧', label: 'Configuration avancee' },
  ];

  const rec = la.recommendation;
  const selectedMeta = la.intent ? intentMeta[la.intent] : null;
  const analysisPreview = `
    <div style="margin-top:14px;padding:14px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);">
      <div style="font-weight:700;font-size:13px;margin-bottom:8px;">Voici comment l'analyse va se derouler :</div>
      <div style="font-size:13px;color:var(--text-secondary);line-height:1.5;">
        <div><strong>Etape 1 :</strong> analyse</div>
        <div><strong>Etape 2 :</strong> debat</div>
        <div><strong>Etape 3 :</strong> synthese</div>
      </div>
    </div>
  `;

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
          <div class="la-options-grid">
            ${intents.map((i) => `
              <div class="la-option-card ${la.intent === i.id ? 'selected' : ''}" role="button" tabindex="0" data-action="la-select-intent" data-intent="${escHtml(i.id)}">
                <div class="la-option-main">
                  <div class="la-option-icon">${i.icon}</div>
                  <div class="la-option-content">
                    <div class="la-option-title">${escHtml(i.label)}</div>
                    <div class="la-option-description">${escHtml(intentMeta[i.id]?.description || '')}</div>
                  </div>
                </div>
                <div class="la-option-meta">
                  <span class="badge badge-muted">⏱ ${escHtml(intentMeta[i.id]?.duration || '')}</span>
                  <span class="badge badge-info">🎯 ${escHtml(intentMeta[i.id]?.depth || '')}</span>
                </div>
              </div>
            `).join('')}
          </div>
            ${selectedMeta ? `
            <div style="margin-top:16px;padding:14px;border:1px solid var(--border);border-radius:8px;background:rgba(99,102,241,0.06);">
              <div style="font-size:13px;color:var(--text-secondary);">
                On va simuler plusieurs experts qui vont analyser votre idee et produire une decision argumentee.
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <span class="badge badge-muted">⏱ ${escHtml(selectedMeta.duration)}</span>
                <span class="badge badge-info">🎯 ${escHtml(selectedMeta.depth)}</span>
                ${la.intent === 'facilitation-workshop' ? `<span class="badge badge-info" style="text-transform:none;">${escHtml(t('sixHats.frameworkBadge'))}</span>` : ''}
              </div>
            </div>
            ${analysisPreview}
          ` : ''}
          <button class="btn btn-primary" style="margin-top:20px;" data-action="la-next-step" ${!la.intent ? 'disabled' : ''}>Lancer l'analyse</button>
        </div>
      ` : ''}

      ${la.step === 2 ? `
        <div class="card" style="padding:24px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:12px;">${t('la.step2Question')}</div>
          <textarea class="textarea" id="la-description" style="min-height:120px;" placeholder="${t('la.descriptionPlaceholder')}">${escHtml(la.description)}</textarea>
          <div style="display:flex;gap:10px;margin-top:16px;">
            <button class="btn btn-secondary" data-action="la-prev-step">${t('la.back')}</button>
            <button class="btn btn-primary" data-action="la-get-recommendation" ${la.loading ? 'disabled' : ''}>
              ${la.loading ? '<span class="spinner"></span>' : '✨'} Lancer l'analyse
            </button>
          </div>
          ${analysisPreview}
        </div>
      ` : ''}

      ${la.step === 3 && rec ? `
        <div class="card" style="padding:24px;margin-bottom:16px;">
          <div style="font-weight:700;font-size:15px;margin-bottom:4px;">✅ ${t('la.recommendationTitle')}</div>
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">${escHtml(rec.explanation || '')}</div>
          ${rec.recommended_template_id === 'six-thinking-hats' ? `<div style="margin-bottom:12px;"><span class="badge badge-info" style="text-transform:none;">${escHtml(t('sixHats.shortTitle'))}</span></div>` : ''}

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
            <button class="btn btn-primary" data-action="la-launch-from-intent" data-intent="${escHtml(la.intent || '')}">
              🚀 Lancer l'analyse
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

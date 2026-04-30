/**
 * Admin feature – view registration.
 * Covers: administration (hub par sections + Bien démarrer), personas, souls, providers,
 *         templates, template-maker, persona-maker, persona-builder, scenario-packs,
 *         logs, retrospective.
 */

import { renderTooltip } from '../../ui/components.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, renderMarkdown, agentIcon, agentName, t };
}

/* ── Administration hub (sections + cartes intentionnelles) ─────────────── */

const ADMIN_HOME_SECTIONS = [
  {
    id: 'agents',
    titleKey: 'admin.section.agents',
    sectionIcon: '🧠',
    items: [
      { nav: 'personas',        titleKey: 'admin.personas',        icon: '🎭', descKey: 'admin.card.personas.desc',        usageKey: 'admin.card.personas.usage',        badgeKey: null },
      { nav: 'persona-maker',   titleKey: 'admin.personaMaker',    icon: '🤖', descKey: 'admin.card.personaMaker.desc',    usageKey: 'admin.card.personaMaker.usage',    badgeKey: 'admin.badge.recommended' },
      { nav: 'persona-builder', titleKey: 'admin.personaBuilder',  icon: '🔧', descKey: 'admin.card.personaBuilder.desc',  usageKey: 'admin.card.personaBuilder.usage',  badgeKey: 'admin.badge.advanced' },
      { nav: 'souls',           titleKey: 'admin.souls',           icon: '✨', descKey: 'admin.card.souls.desc',           usageKey: 'admin.card.souls.usage',           badgeKey: 'admin.badge.advanced' },
    ],
  },
  {
    id: 'experiences',
    titleKey: 'admin.section.experiences',
    sectionIcon: '🧩',
    items: [
      { nav: 'templates',        titleKey: 'admin.templates',         icon: '📋', descKey: 'admin.card.templates.desc',        usageKey: 'admin.card.templates.usage',        badgeKey: 'admin.badge.recommended' },
      { nav: 'template-maker',   titleKey: 'admin.templateMaker',     icon: '🧩', descKey: 'admin.card.templateMaker.desc',    usageKey: 'admin.card.templateMaker.usage',    badgeKey: 'admin.badge.advanced' },
      { nav: 'scenario-packs',   titleKey: 'scenario.admin.title',    icon: '🎯', descKey: 'admin.card.scenarios.desc',        usageKey: 'admin.card.scenarios.usage',        badgeKey: 'admin.badge.recommended' },
    ],
  },
  {
    id: 'engine',
    titleKey: 'admin.section.engine',
    sectionIcon: '⚙️',
    items: [
      {
        nav: 'providers',
        titleKey: 'admin.providers',
        icon: '⚙️',
        descKey: 'admin.card.providers.desc',
        usageKey: 'admin.card.providers.usage',
        badgeKey: 'admin.badge.technical',
        noteKey: 'admin.card.providers.noteRouting',
      },
    ],
  },
  {
    id: 'monitoring',
    titleKey: 'admin.section.monitoring',
    sectionIcon: '📊',
    items: [
      { nav: 'logs', titleKey: 'admin.logs', icon: '🧾', descKey: 'admin.card.logs.desc', usageKey: 'admin.card.logs.usage', badgeKey: 'admin.badge.technical' },
    ],
  },
  {
    id: 'intelligence',
    titleKey: 'admin.section.intelligence',
    sectionIcon: '✨',
    items: [
      {
        nav: 'retrospective',
        titleKey: 'admin.retrospective',
        icon: '🔮',
        descKey: 'admin.card.retrospective.desc',
        usageKey: 'admin.card.retrospective.usage',
        badgeKey: 'admin.badge.analysis',
        featured: true,
      },
    ],
  },
];

function renderAdminCard(item, t, escHtml) {
  const badge = item.badgeKey
    ? `<span class="admin-card-badge" aria-label="${escHtml(t(item.badgeKey))}">${escHtml(t(item.badgeKey))}</span>`
    : '';
  const note = item.noteKey
    ? `<div class="admin-card-note">${escHtml(t(item.noteKey))}</div>`
    : '';
  const feat = item.featured ? ' admin-card-featured' : '';
  const label = `${t(item.titleKey)} — ${t(item.descKey)}`;
  return `
    <div class="admin-card${feat}" data-nav="${escHtml(item.nav)}" tabindex="0" role="link" aria-label="${escHtml(label)}">
      ${badge}
      <span class="admin-card-icon" aria-hidden="true">${item.icon}</span>
      <div class="admin-card-title">${escHtml(t(item.titleKey))}</div>
      <div class="admin-card-description">${escHtml(t(item.descKey))}</div>
      <div class="admin-card-usage">
        <span class="admin-card-usage-label">${escHtml(t('admin.card.usageLabel'))}</span>
        ${escHtml(t(item.usageKey))}
      </div>
      ${note}
    </div>`;
}

function renderAdminSection(section, t, escHtml) {
  return `
    <section class="admin-section" aria-labelledby="admin-section-${escHtml(section.id)}">
      <h2 class="admin-section-title" id="admin-section-${escHtml(section.id)}">
        <span class="admin-section-emoji" aria-hidden="true">${section.sectionIcon}</span>
        ${escHtml(t(section.titleKey))}
      </h2>
      <div class="admin-card-grid">
        ${section.items.map((it) => renderAdminCard(it, t, escHtml)).join('')}
      </div>
    </section>`;
}

function renderGetStarted(t, escHtml) {
  return `
    <div class="admin-get-started" aria-labelledby="admin-get-started-title">
      <div class="admin-get-started-title" id="admin-get-started-title">${escHtml(t('admin.home.getStarted.title'))}</div>
      <p class="admin-get-started-lead">${escHtml(t('admin.home.getStarted.lead'))}</p>
      <ol class="admin-get-started-list">
        <li>
          <button type="button" class="admin-get-started-link" data-nav="providers">
            <span class="admin-get-started-num">1.</span>
            <span>${escHtml(t('admin.home.getStarted.step.provider'))}</span>
          </button>
        </li>
        <li>
          <button type="button" class="admin-get-started-link" data-nav="personas">
            <span class="admin-get-started-num">2.</span>
            <span>${escHtml(t('admin.home.getStarted.step.agents'))}</span>
          </button>
        </li>
        <li class="admin-get-started-li-split">
          <span class="admin-get-started-num" aria-hidden="true">3.</span>
          <div class="admin-get-started-split-body">
            <div class="admin-get-started-split-intro">${escHtml(t('admin.home.getStarted.step.templatesIntro'))}</div>
            <div class="admin-get-started-split-actions">
              <button type="button" class="admin-get-started-chip" data-nav="templates" aria-label="${escHtml(t('admin.home.getStarted.step.templatesAria.templates'))}">
                📋 ${escHtml(t('admin.templates'))}
              </button>
              <button type="button" class="admin-get-started-chip" data-nav="scenario-packs" aria-label="${escHtml(t('admin.home.getStarted.step.templatesAria.scenarios'))}">
                🎯 ${escHtml(t('scenario.admin.title'))}
              </button>
            </div>
          </div>
        </li>
        <li class="admin-get-started-li-split">
          <span class="admin-get-started-num" aria-hidden="true">4.</span>
          <div class="admin-get-started-split-body">
            <div class="admin-get-started-split-intro">${escHtml(t('admin.home.getStarted.step.analyzeIntro'))}</div>
            <div class="admin-get-started-split-actions">
              <button type="button" class="admin-get-started-chip" data-nav="new-session" aria-label="${escHtml(t('admin.home.getStarted.step.analyzeAria.launch'))}">
                ✨ ${escHtml(t('nav.newSession'))}
              </button>
              <button type="button" class="admin-get-started-chip" data-nav="sessions" aria-label="${escHtml(t('admin.home.getStarted.step.analyzeAria.history'))}">
                📁 ${escHtml(t('nav.sessions'))}
              </button>
            </div>
          </div>
        </li>
      </ol>
    </div>`;
}

function renderAdministration() {
  const { t, escHtml } = getCtx();
  return `
    <div class="page-header">
      <div class="page-title">${t('admin.title')}</div>
      <div class="page-subtitle">${t('admin.home.subtitle')}</div>
    </div>
    <div class="admin-home">
      ${renderGetStarted(t, escHtml)}
      <div class="admin-sections">
        ${ADMIN_HOME_SECTIONS.map((sec) => renderAdminSection(sec, t, escHtml)).join('')}
      </div>
    </div>
  `;
}

/* ── Personas ── */

function renderPersonas() {
  const { state, escHtml, t } = getCtx();
  const personas  = state.personas;
  const allModes  = ['chat', 'decision-room', 'confrontation'];
  const modeLabels = { 'chat': t('personas.modeChat'), 'decision-room': t('personas.modeDR'), 'confrontation': t('personas.modeConfrontation') };
  return `
    <div class="page-header">
      <div class="page-title">${t('personas.title')}</div>
      <div class="page-subtitle">${t('personas.subtitle')}</div>
      <div class="card-description" style="margin-top:6px;">${t('admin.personas.page.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>
    ${personas.length === 0 ? `<div class="loading-state"><span class="spinner"></span> ${t('personas.loading')}</div>` : `
      <div class="personas-grid">
        ${personas.map((p) => {
          const enabledModes = Array.isArray(p.available_modes) ? p.available_modes : allModes;
          return `
            <div class="persona-card persona-card-admin">
              <div class="persona-card-top" data-action="show-persona" data-persona-id="${escHtml(p.id)}" style="cursor:pointer;">
                <span class="persona-icon">${escHtml(p.icon || '🤖')}</span>
                <div class="persona-name">${escHtml(p.name)}</div>
                <div class="persona-title-text">${escHtml(p.title || '')}</div>
                <div class="persona-tags">${(p.tags || []).slice(0, 3).map((tag) => `<span class="tag">${escHtml(tag)}</span>`).join('')}</div>
              </div>
              <div class="persona-modes-section">
                <div class="persona-modes-label">${t('personas.availableModes')}</div>
                <div class="persona-modes-checks">
                  ${allModes.map((mode) => `
                    <label class="mode-check-label">
                      <input type="checkbox" class="mode-checkbox" data-persona-id="${escHtml(p.id)}" data-mode="${escHtml(mode)}" ${enabledModes.includes(mode) ? 'checked' : ''} style="accent-color:var(--accent);">
                      <span>${modeLabels[mode]}</span>
                    </label>
                  `).join('')}
                </div>
                <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="save-persona-modes" data-persona-id="${escHtml(p.id)}">${t('personas.saveModes')}</button>
                <span class="mode-save-status" id="mode-status-${escHtml(p.id)}"></span>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `}
  `;
}

function showPersonaModal(personaId) {
  const { state, escHtml, renderMarkdown } = getCtx();
  const p = state.personas.find((x) => x.id === personaId);
  if (!p) return;
  const existing = document.getElementById('persona-modal-overlay');
  if (existing) existing.remove();
  const overlay = document.createElement('div');
  overlay.className = 'persona-modal-overlay';
  overlay.id = 'persona-modal-overlay';
  overlay.innerHTML = `
    <div class="persona-modal">
      <button class="persona-modal-close" id="persona-modal-close">✕</button>
      <span style="font-size:48px;display:block;margin-bottom:12px;">${escHtml(p.icon || '🤖')}</span>
      <div style="font-size:20px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">${escHtml(p.name)}</div>
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">${escHtml(p.title || '')}</div>
      <div class="persona-tags" style="margin-bottom:16px;">${(p.tags || []).map((tag) => `<span class="tag">${escHtml(tag)}</span>`).join('')}</div>
      <div class="md-content" style="font-size:13.5px;">${renderMarkdown(p.content || p.description || '')}</div>
    </div>
  `;
  document.body.appendChild(overlay);
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay || e.target.id === 'persona-modal-close') overlay.remove();
  });
}

/* ── Souls ── */

function renderSouls() {
  const { state, escHtml, renderMarkdown, t } = getCtx();
  const souls = state.souls;
  return `
    <div class="page-header">
      <div class="page-title">${t('souls.title')}</div>
      <div class="page-subtitle">${t('souls.subtitle')}</div>
      <button class="btn btn-secondary btn-sm" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>
    ${souls.length === 0 ? `<div class="loading-state"><span class="spinner"></span> ${t('souls.loading')}</div>` : `
      <div class="souls-list">
        ${souls.map((s) => `
          <div class="soul-card">
            <div class="soul-card-header">
              <div class="soul-name">${escHtml(s.name || s.id || 'Soul')}</div>
              ${s.tags ? `<div class="persona-tags">${(Array.isArray(s.tags) ? s.tags : [s.tags]).slice(0, 4).map((tag) => `<span class="tag">${escHtml(tag)}</span>`).join('')}</div>` : ''}
            </div>
            ${s.content ? `<details class="soul-preview"><summary>${t('souls.preview')}</summary><div class="md-content" style="padding:12px;font-size:13px;">${renderMarkdown(s.content)}</div></details>` : ''}
          </div>
        `).join('')}
      </div>
    `}
  `;
}

/* ── Templates (admin) ── */

function renderTemplateAdminCard(template) {
  const { escHtml, agentIcon, agentName, t } = getCtx();
  const isSystem  = template.source === 'system';
  const agents    = (template.selected_agents || []).slice(0, 5);
  const modeIcons = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡' };
  return `
    <div class="session-card-full">
      <div class="session-card-full-header">
        <span style="font-size:22px;">${modeIcons[template.mode] || '📋'}</span>
        <div class="session-info" style="flex:1;">
          <div class="session-title">${escHtml(template.name)}</div>
          <div class="session-meta" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
            <span class="badge badge-default">${escHtml(template.mode)}</span>
            <span class="badge badge-muted">${t('template.rounds')}: ${template.rounds}</span>
            ${template.force_disagreement ? `<span class="badge badge-warning">${t('template.forceDisagreement')}</span>` : ''}
            ${isSystem ? `<span class="badge badge-info">${t('template.system')}</span>` : `<span class="badge badge-muted">${t('template.custom')}</span>`}
          </div>
        </div>
      </div>
      ${agents.length > 0 ? `<div class="session-agents" style="margin:8px 0;">${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}</div>` : ''}
      <div class="session-card-full-actions">
        <button class="btn btn-primary btn-sm" data-action="use-template" data-template-id="${escHtml(template.id)}">${t('template.use')}</button>
        ${!isSystem ? `<button class="btn btn-secondary btn-sm" data-action="edit-template" data-template-id="${escHtml(template.id)}">${t('template.edit')}</button>` : ''}
        <button class="btn btn-secondary btn-sm" data-action="duplicate-template" data-template-id="${escHtml(template.id)}">${t('template.duplicate')}</button>
        ${!isSystem ? `<button class="btn btn-danger btn-sm" data-action="delete-template" data-template-id="${escHtml(template.id)}" data-template-name="${escHtml(template.name)}">${t('template.delete')}</button>` : ''}
      </div>
    </div>
  `;
}

function renderTemplates() {
  const { state, t } = getCtx();
  const templates = state.templates;
  return `
    <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;">
      <div>
        <div class="page-title">${t('admin.templates')}</div>
        <div class="page-subtitle">${t('admin.templatesDesc')}</div>
        <div class="card-description" style="margin-top:4px;">${t('admin.templates.page.desc')}</div>
        <button class="btn btn-secondary btn-sm" data-nav="administration" style="margin-top:8px;">${t('nav.backAdmin')}</button>
      </div>
      <button class="btn btn-primary btn-sm" data-nav="template-maker" style="margin-top:4px;">+ ${t('template.create')}</button>
    </div>
    ${templates.length === 0 ? `<div class="empty-state"><div class="empty-state-icon">📋</div><div class="empty-state-text">${t('template.empty')}</div></div>` : `
      <div style="display:flex;flex-direction:column;gap:10px;">${templates.map(renderTemplateAdminCard).join('')}</div>
    `}
  `;
}

/* ── Template Maker ── */

function renderTemplateMaker() {
  const { state, escHtml, t } = getCtx();
  const td = state.templateMakerData || {};
  const tm = state.templateMaker;
  const allModes  = ['chat', 'decision-room', 'confrontation', 'quick-decision', 'stress-test'];
  const modeIcons = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥' };
  const modeLabels = {
    chat: t('mode.chat').replace('💬 ', ''),
    'decision-room': t('mode.decisionRoom').replace('🏛️ ', ''),
    confrontation: t('mode.confrontation').replace('⚔️ ', ''),
    'quick-decision': t('mode.quickDecision').replace('⚡ ', ''),
    'stress-test': t('mode.stressTest').replace('🔥 ', ''),
  };
  const providers = state.providers;
  const personas  = state.personas;

  return `
    <div style="max-width:900px;margin:0 auto;padding:24px 20px;">
      <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;">
        <div>
          <div class="page-title">${td.editingId ? t('template.editTitle') : t('template.createTitle')}</div>
          <div class="page-subtitle">${t('template.createSubtitle')}</div>
          <button class="btn btn-secondary btn-sm" data-nav="templates" style="margin-top:8px;">${t('nav.back')}</button>
        </div>
      </div>

      <div class="card" style="margin-bottom:20px;padding:20px;">
        <div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;">${t('template.generateWithLlm')}</div>
        <div class="form-group">
          <textarea class="textarea" id="tm-description" placeholder="${t('template.descriptionPlaceholder')}" rows="3" data-tm-field="description">${escHtml(tm.description || '')}</textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>${t('providers.title')}</label>
            <select class="input" id="tm-provider" data-tm-field="providerId">
              <option value="">— ${t('providers.auto')} —</option>
              ${providers.map((p) => `<option value="${escHtml(p.id)}" ${tm.providerId === p.id ? 'selected' : ''}>${escHtml(p.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>${t('personaMaker.model')}</label>
            <input class="input" id="tm-model" type="text" placeholder="qwen2.5:14b" value="${escHtml(tm.model || '')}" data-tm-field="model">
          </div>
        </div>
        <button class="btn btn-secondary" data-action="tm-generate" ${tm.isGenerating ? 'disabled' : ''}>
          ${tm.isGenerating ? '<span class="spinner"></span>' : '🤖'} ${t('template.generateDraft')}
        </button>
        ${tm.error  ? `<div class="provider-test-result fail" style="margin-top:8px;">❌ ${escHtml(tm.error)}</div>` : ''}
        ${tm.result ? `<div class="provider-test-result ok" style="margin-top:8px;">✅ ${t('template.draftReady')} — ${t('template.reviewBelow')}</div>` : ''}
      </div>

      <div class="card" style="padding:20px;">
        <div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:14px;text-transform:uppercase;letter-spacing:.05em;">${t('template.formTitle')}</div>
        <div class="form-row">
          <div class="form-group">
            <label for="tmd-id">${t('template.fieldId')}</label>
            <input class="input" id="tmd-id" type="text" placeholder="my-template" value="${escHtml(td.id || '')}" data-tmd-field="id" ${td.editingId ? 'disabled' : ''}>
          </div>
          <div class="form-group">
            <label for="tmd-name">${t('template.fieldName')}</label>
            <input class="input" id="tmd-name" type="text" placeholder="${t('template.namePlaceholder')}" value="${escHtml(td.name || '')}" data-tmd-field="name">
          </div>
        </div>
        <div class="form-group">
          <label for="tmd-description">${t('template.fieldDescription')}</label>
          <input class="input" id="tmd-description" type="text" placeholder="${t('template.descriptionShortPlaceholder')}" value="${escHtml(td.description || '')}" data-tmd-field="description">
        </div>
        <div class="form-group">
          <label>${t('newSession.mode')}</label>
          <div class="mode-selector" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">
            ${allModes.map((m) => `
              <label class="mode-option ${(td.mode || 'decision-room') === m ? 'selected' : ''}">
                <input type="radio" name="tmd-mode" value="${m}" ${(td.mode || 'decision-room') === m ? 'checked' : ''} data-tmd-field="mode">
                <div><div class="mode-option-label">${modeIcons[m]} ${modeLabels[m]}</div></div>
              </label>
            `).join('')}
          </div>
        </div>
        <div class="form-group">
          <label>${t('newSession.selectAgents')}</label>
          <div class="agents-select-grid">
            ${personas.map((p) => { const sel = (td.selectedAgents || []).includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="tmd-toggle-agent" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} style="pointer-events:none;"><span style="font-size:18px;">${escHtml(p.icon || '🤖')}</span><div style="font-size:12px;font-weight:600;">${escHtml(p.name)}</div></label>`; }).join('')}
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="tmd-rounds">${t('newSession.rounds')} (${td.rounds || 2})</label>
            <input class="input" id="tmd-rounds" type="range" min="1" max="5" value="${td.rounds || 2}" data-tmd-field="rounds" style="padding:6px 0;">
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:16px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;"><input type="checkbox" ${td.forceDisagreement ? 'checked' : ''} data-tmd-field="forceDisagreement" style="width:15px;height:15px;accent-color:var(--accent);"> ${t('newSession.forceDisagreement')}</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;"><input type="checkbox" ${td.finalSynthesis !== false ? 'checked' : ''} data-tmd-field="finalSynthesis" style="width:15px;height:15px;accent-color:var(--accent);"> ${t('newSession.includeSynthesis')}</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;"><input type="checkbox" ${td.enabled !== false ? 'checked' : ''} data-tmd-field="enabled" style="width:15px;height:15px;accent-color:var(--accent);"> ${t('template.enabled')}</label>
        </div>
        <div class="form-group">
          <label for="tmd-prompt-starter">${t('template.promptStarter')}</label>
          <textarea class="textarea" id="tmd-prompt-starter" rows="3" placeholder="${t('template.promptStarterPlaceholder')}" data-tmd-field="promptStarter">${escHtml(td.promptStarter || '')}</textarea>
        </div>
        <div class="form-group">
          <label for="tmd-expected-output">${t('template.expectedOutput')}</label>
          <textarea class="textarea" id="tmd-expected-output" rows="2" placeholder="${t('template.expectedOutputPlaceholder')}" data-tmd-field="expectedOutput">${escHtml(td.expectedOutput || '')}</textarea>
        </div>
        <div class="form-group">
          <label for="tmd-notes">${t('template.notes')}</label>
          <textarea class="textarea" id="tmd-notes" rows="2" placeholder="${t('template.notesPlaceholder')}" data-tmd-field="notes">${escHtml(td.notes || '')}</textarea>
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <input type="checkbox" id="tmd-overwrite" ${td.overwrite ? 'checked' : ''} data-tmd-field="overwrite" style="width:15px;height:15px;accent-color:var(--accent);">
          <label for="tmd-overwrite" style="text-transform:none;font-size:13px;font-weight:500;cursor:pointer;margin:0;">${t('template.overwrite')}</label>
        </div>
        <button class="btn btn-primary" data-action="tmd-save" ${!td.id || !td.name ? 'disabled' : ''}>
          💾 ${td.editingId ? t('template.saveEdit') : t('template.saveNew')}
        </button>
        ${td.saveStatus === 'success' ? `<div class="provider-test-result ok" style="margin-top:8px;">✅ ${escHtml(td.saveMessage)}</div>` : ''}
        ${td.saveStatus === 'error'   ? `<div class="provider-test-result fail" style="margin-top:8px;">❌ ${escHtml(td.saveMessage)}</div>` : ''}
      </div>
    </div>
  `;
}

/* ── Persona Maker ── */

function renderPersonaMaker() {
  const { state, escHtml, t } = getCtx();
  const pm = state.personaMaker;
  const allModes   = ['chat', 'decision-room', 'confrontation'];
  const modeLabels = { 'chat': t('personas.modeChat'), 'decision-room': t('personas.modeDR'), 'confrontation': t('personas.modeConfrontation') };
  const resultPersona = pm.result?.persona || null;
  const resultSoul    = pm.result?.soul    || null;

  const previewContent = (() => {
    if (!resultPersona) return '';
    if (pm.previewTab === 'soul') {
      if (!resultSoul) return '';
      const soul = resultSoul;
      return [
        '---', `id: ${resultPersona.id}-soul`, `name: ${resultPersona.name} Soul`,
        `applies_to:`, `  - ${resultPersona.id}`, `intensity: ${soul.challenge_level}`, '---', '',
        soul.personality ? `# Personality\n\n${soul.personality}` : '',
        soul.behavioral_rules?.length ? `\n# Behavioral Rules\n\n${soul.behavioral_rules.map((r) => `- ${r}`).join('\n')}` : '',
        soul.reasoning_style ? `\n# Reasoning Style\n\n${soul.reasoning_style}` : '',
        soul.communication_style ? `\n# Communication Style\n\n${soul.communication_style}` : '',
        soul.default_bias ? `\n# Default Bias\n\n${soul.default_bias}` : '',
        soul.guardrails?.length ? `\n# Guardrails\n\n${soul.guardrails.map((r) => `- ${r}`).join('\n')}` : '',
      ].filter(Boolean).join('\n');
    }
    const p = resultPersona;
    const modes = Array.isArray(p.available_modes) ? p.available_modes : allModes;
    return [
      '---', `id: ${p.id}`, `name: ${p.name}`, `title: ${p.title}`, `icon: ${p.icon}`,
      p.tags?.length ? `tags:\n${p.tags.map((tg) => `  - ${tg}`).join('\n')}` : '',
      `available_modes:\n${modes.map((m) => `  - ${m}`).join('\n')}`, '---', '',
      p.role ? `# Role\n\n${p.role}` : '',
      p.when_to_use ? `\n# When To Use\n\n${p.when_to_use}` : '',
      p.style ? `\n# Style\n\n${p.style}` : '',
      p.focus ? `\n# Focus\n\n${p.focus}` : '',
      p.core_principles?.length ? `\n# Core Principles\n\n${p.core_principles.map((r) => `- ${r}`).join('\n')}` : '',
      p.system_instructions ? `\n# System Instructions\n\n${p.system_instructions}` : '',
    ].filter(Boolean).join('\n');
  })();

  return `
    <div class="pb-layout">
      <div class="pb-form-panel">
        <div class="page-header" style="padding:24px 24px 0;">
          <div class="page-title">${t('personaMaker.title')}</div>
          <div class="page-subtitle">${t('personaMaker.subtitle')}</div>
          <button class="btn btn-secondary btn-sm" data-nav="administration">${t('nav.backAdmin')}</button>
        </div>
        <div class="pb-form-body">
          <div class="pb-section">
            <div class="form-group">
              <label for="pm-description">${t('personaMaker.description')}</label>
              <textarea class="textarea" id="pm-description" placeholder="${t('personaMaker.descriptionPlaceholder')}" rows="5" data-pm-field="description">${escHtml(pm.description)}</textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="pm-provider">${t('personaMaker.provider')}</label>
                <select class="input" id="pm-provider" data-pm-field="providerId">
                  <option value="">— ${t('providers.empty').replace(/\.$/, '')} —</option>
                  ${state.providers.map((p) => `<option value="${escHtml(p.id)}" ${pm.providerId === p.id ? 'selected' : ''}>${escHtml(p.name)}</option>`).join('')}
                </select>
              </div>
              <div class="form-group">
                <label for="pm-model">${t('personaMaker.model')}</label>
                <input class="input" id="pm-model" type="text" placeholder="${t('personaMaker.modelPlaceholder')}" value="${escHtml(pm.model)}" data-pm-field="model">
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
              <button class="btn btn-primary" data-action="pm-generate" ${pm.isGenerating ? 'disabled' : ''}>${pm.isGenerating ? `<span class="spinner"></span> ${t('personaMaker.generating')}` : t('personaMaker.generate')}</button>
              ${resultPersona ? `<button class="btn btn-secondary" data-action="pm-generate-improve" ${pm.isGenerating ? 'disabled' : ''}>${t('personaMaker.improve')}</button>` : ''}
            </div>
            ${pm.error ? `<div class="error-banner" style="margin-top:12px;">${escHtml(pm.error)}</div>` : ''}
          </div>
          ${resultPersona ? `
            <div class="pb-section">
              <div class="pb-section-title">${t('personaMaker.availableModes')}</div>
              <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;">
                ${allModes.map((mode) => { const enabled = (resultPersona.available_modes || allModes).includes(mode); return `<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;"><input type="checkbox" data-pm-mode="${escHtml(mode)}" ${enabled ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;"><span>${modeLabels[mode]}</span></label>`; }).join('')}
              </div>
            </div>
            <div class="pb-section">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <input type="checkbox" id="pm-overwrite" ${pm.overwrite ? 'checked' : ''} data-pm-field="overwrite" style="width:16px;height:16px;accent-color:var(--accent);">
                <label for="pm-overwrite" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;">${t('personaMaker.overwrite')}</label>
              </div>
              <button class="btn btn-primary" data-action="pm-save">${t('personaMaker.save')}</button>
              ${pm.saveStatus === 'success' ? `<div class="provider-test-result ok" style="margin-top:8px;">✅ ${escHtml(pm.saveMessage)}</div>` : ''}
              ${pm.saveStatus === 'error'   ? `<div class="provider-test-result fail" style="margin-top:8px;">❌ ${escHtml(pm.saveMessage)}</div>` : ''}
            </div>
          ` : ''}
        </div>
      </div>
      <div class="pb-preview-panel">
        <div class="pb-preview-tabs">
          <button class="pb-tab ${pm.previewTab === 'persona' ? 'active' : ''}" data-action="pm-tab" data-tab="persona">persona.md</button>
          <button class="pb-tab ${pm.previewTab === 'soul'    ? 'active' : ''}" data-action="pm-tab" data-tab="soul">soul.md</button>
        </div>
        <pre class="pb-preview-content">${resultPersona ? escHtml(previewContent) : `# ${t('personaMaker.preview')}\n\n(${t('personaMaker.generate')} first)`}</pre>
      </div>
    </div>
  `;
}

/* ── Persona Builder ── */

function renderProviderOptions() {
  const { state, escHtml } = getCtx();
  return state.providers.map((p) => `<option value="${escHtml(p.id)}">${escHtml(p.name)}</option>`).join('');
}

function buildPersonaMarkdownPreview() {
  const { state } = getCtx();
  const pb = state.personaBuilder;
  const tagsArr = pb.tags ? pb.tags.split(',').map((t) => t.trim()).filter(Boolean) : [];
  return [
    '---', `id: ${pb.id || 'my-agent'}`, `name: ${pb.name || 'My Agent'}`, `title: ${pb.title || 'AI Agent'}`,
    `icon: ${pb.icon || '🤖'}`, pb.tags ? `tags: [${tagsArr.join(', ')}]` : '',
    pb.defaultProvider ? `default_provider: ${pb.defaultProvider}` : '',
    pb.defaultModel ? `default_model: ${pb.defaultModel}` : '',
    `enabled: ${pb.enabled}`, '---', '',
    pb.role ? `## Role\n${pb.role}` : '',
    pb.whenToUse ? `\n## When to Use\n${pb.whenToUse}` : '',
    pb.style ? `\n## Style\n${pb.style}` : '',
    pb.identity ? `\n## Identity\n${pb.identity}` : '',
    pb.focus ? `\n## Focus\n${pb.focus}` : '',
    pb.corePrinciples ? `\n## Core Principles\n${pb.corePrinciples}` : '',
    pb.capabilities ? `\n## Capabilities\n${pb.capabilities}` : '',
    pb.constraints ? `\n## Constraints\n${pb.constraints}` : '',
    pb.defaultResponseFormat ? `\n## Default Response Format\n${pb.defaultResponseFormat}` : '',
    pb.systemInstructions ? `\n## System Instructions\n${pb.systemInstructions}` : '',
  ].filter(Boolean).join('\n');
}

function buildSoulMarkdownPreview() {
  const { state } = getCtx();
  const pb = state.personaBuilder;
  return [
    '---', `id: ${pb.id || 'my-agent'}`, `name: ${pb.name || 'My Agent'} Soul`, '---', '',
    pb.personality ? `## Personality\n${pb.personality}` : '',
    pb.behavioralRules ? `\n## Behavioral Rules\n${pb.behavioralRules}` : '',
    pb.reasoningStyle ? `\n## Reasoning Style\n${pb.reasoningStyle}` : '',
    pb.communicationStyle ? `\n## Communication Style\n${pb.communicationStyle}` : '',
    pb.defaultBias ? `\n## Default Bias\n${pb.defaultBias}` : '',
    pb.challengeLevel ? `\n## Challenge Level\n${pb.challengeLevel}` : '',
    pb.outputPreferences ? `\n## Output Preferences\n${pb.outputPreferences}` : '',
    pb.guardrails ? `\n## Guardrails\n${pb.guardrails}` : '',
  ].filter(Boolean).join('\n');
}

function renderPersonaBuilder() {
  const { state, escHtml } = getCtx();
  const pb = state.personaBuilder;
  const previewContent = pb.previewTab === 'persona' ? buildPersonaMarkdownPreview() : buildSoulMarkdownPreview();

  return `
    <div class="pb-layout">
      <div class="pb-form-panel">
        <div class="page-header" style="padding:24px 24px 0;">
          <div class="page-title">${'personaBuilder.title' in {} ? '' : 'Persona Builder'}</div>
          <div class="page-subtitle">${'personaBuilder.subtitle' in {} ? '' : 'Build a persona manually'}</div>
          <button class="btn btn-secondary btn-sm" data-nav="administration">${window.i18n?.t('nav.backAdmin') ?? 'Back'}</button>
        </div>
        <div class="pb-form-body">
          <div class="pb-section">
            <div class="pb-section-title">AI Generation</div>
            <div class="form-group">
              <label for="pb-description">Describe this persona</label>
              <textarea class="textarea" id="pb-description" placeholder="e.g. A senior security engineer who challenges every architectural decision with threat modeling..." rows="4" data-pb-field="description">${escHtml(pb.description)}</textarea>
            </div>
            <div class="form-group">
              <label for="pb-gen-provider">Provider for generation</label>
              <select class="input" id="pb-gen-provider" data-pb-field="defaultProvider">
                <option value="">— Default —</option>${renderProviderOptions()}
              </select>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn btn-primary" data-action="pb-generate-draft" ${pb.isGenerating ? 'disabled' : ''}>${pb.isGenerating ? '<span class="spinner"></span> Generating…' : '✨ Generate Draft'}</button>
              ${pb.id ? `<button class="btn btn-secondary" data-action="pb-improve-draft" ${pb.isGenerating ? 'disabled' : ''}>🔄 Improve Draft</button>` : ''}
            </div>
            ${pb.generationError ? `<div class="error-banner" style="margin-top:12px;">${escHtml(pb.generationError)}</div>` : ''}
          </div>

          <div class="pb-section">
            <div class="pb-section-title">Identity</div>
            <div class="form-row">
              <div class="form-group"><label for="pb-id">ID</label><input class="input" id="pb-id" type="text" placeholder="my-agent" value="${escHtml(pb.id)}" data-pb-field="id"></div>
              <div class="form-group"><label for="pb-icon">Icon</label><input class="input" id="pb-icon" type="text" value="${escHtml(pb.icon)}" data-pb-field="icon" style="max-width:80px;"></div>
            </div>
            <div class="form-group"><label for="pb-name">Name</label><input class="input" id="pb-name" type="text" placeholder="Alex" value="${escHtml(pb.name)}" data-pb-field="name"></div>
            <div class="form-group"><label for="pb-title">Title</label><input class="input" id="pb-title" type="text" placeholder="Senior Security Engineer" value="${escHtml(pb.title)}" data-pb-field="title"></div>
            <div class="form-group"><label for="pb-tags">Tags (comma-separated)</label><input class="input" id="pb-tags" type="text" placeholder="security, architecture, risk" value="${escHtml(pb.tags)}" data-pb-field="tags"></div>
          </div>

          <div class="pb-section">
            <div class="pb-section-title">Persona Content</div>
            <div class="form-group"><label for="pb-role">Role</label><textarea class="textarea" id="pb-role" rows="3" data-pb-field="role">${escHtml(pb.role)}</textarea></div>
            <div class="form-group"><label for="pb-when-to-use">When to Use</label><textarea class="textarea" id="pb-when-to-use" rows="2" data-pb-field="whenToUse">${escHtml(pb.whenToUse)}</textarea></div>
            <div class="form-group"><label for="pb-style">Style</label><textarea class="textarea" id="pb-style" rows="2" data-pb-field="style">${escHtml(pb.style)}</textarea></div>
            <div class="form-group"><label for="pb-focus">Focus</label><textarea class="textarea" id="pb-focus" rows="2" data-pb-field="focus">${escHtml(pb.focus)}</textarea></div>
            <div class="form-group"><label for="pb-core-principles">Core Principles</label><textarea class="textarea" id="pb-core-principles" rows="3" data-pb-field="corePrinciples">${escHtml(pb.corePrinciples)}</textarea></div>
            <div class="form-group"><label for="pb-response-format">Default Response Format</label><textarea class="textarea" id="pb-response-format" rows="3" data-pb-field="defaultResponseFormat">${escHtml(pb.defaultResponseFormat)}</textarea></div>
            <div class="form-group"><label for="pb-system-instructions">System Instructions</label><textarea class="textarea" id="pb-system-instructions" rows="3" data-pb-field="systemInstructions">${escHtml(pb.systemInstructions)}</textarea></div>
          </div>

          <div class="pb-section">
            <div class="pb-section-title">Soul / Personality</div>
            <div class="form-group"><label for="pb-personality">Personality</label><textarea class="textarea" id="pb-personality" rows="3" data-pb-field="personality">${escHtml(pb.personality)}</textarea></div>
            <div class="form-group"><label for="pb-behavioral-rules">Behavioral Rules</label><textarea class="textarea" id="pb-behavioral-rules" rows="3" data-pb-field="behavioralRules">${escHtml(pb.behavioralRules)}</textarea></div>
            <div class="form-group"><label for="pb-reasoning-style">Reasoning Style</label><textarea class="textarea" id="pb-reasoning-style" rows="2" data-pb-field="reasoningStyle">${escHtml(pb.reasoningStyle)}</textarea></div>
            <div class="form-group"><label for="pb-communication-style">Communication Style</label><textarea class="textarea" id="pb-communication-style" rows="2" data-pb-field="communicationStyle">${escHtml(pb.communicationStyle)}</textarea></div>
            <div class="form-group"><label for="pb-guardrails">Guardrails</label><textarea class="textarea" id="pb-guardrails" rows="2" data-pb-field="guardrails">${escHtml(pb.guardrails)}</textarea></div>
          </div>

          <div class="pb-section">
            <div class="pb-section-title">Provider</div>
            <div class="form-row">
              <div class="form-group"><label for="pb-provider">Default Provider</label><select class="input" id="pb-provider" data-pb-field="defaultProvider"><option value="">— Auto —</option>${renderProviderOptions()}</select></div>
              <div class="form-group"><label for="pb-model">Default Model</label><input class="input" id="pb-model" type="text" placeholder="auto" value="${escHtml(pb.defaultModel)}" data-pb-field="defaultModel"></div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:8px;">
              <input type="checkbox" id="pb-enabled" ${pb.enabled ? 'checked' : ''} data-pb-field="enabled" style="width:16px;height:16px;accent-color:var(--accent);">
              <label for="pb-enabled" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;">Enabled</label>
            </div>
          </div>

          <div class="pb-section">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
              <input type="checkbox" id="pb-overwrite" ${pb.overwrite ? 'checked' : ''} data-pb-field="overwrite" style="width:16px;height:16px;accent-color:var(--accent);">
              <label for="pb-overwrite" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;">Overwrite if exists</label>
            </div>
            <button class="btn btn-primary" data-action="pb-save" ${!pb.id ? 'disabled' : ''}>💾 Save Persona</button>
            ${pb.saveStatus === 'success' ? `<div class="provider-test-result ok" style="margin-top:8px;">✅ ${escHtml(pb.saveMessage)}</div>` : ''}
            ${pb.saveStatus === 'error'   ? `<div class="provider-test-result fail" style="margin-top:8px;">❌ ${escHtml(pb.saveMessage)}</div>` : ''}
          </div>
        </div>
      </div>

      <div class="pb-preview-panel">
        <div class="pb-preview-tabs">
          <button class="pb-tab ${pb.previewTab === 'persona' ? 'active' : ''}" data-action="pb-tab" data-tab="persona">persona.md</button>
          <button class="pb-tab ${pb.previewTab === 'soul'    ? 'active' : ''}" data-action="pb-tab" data-tab="soul">soul.md</button>
        </div>
        <pre class="pb-preview-content">${escHtml(previewContent)}</pre>
      </div>
    </div>
  `;
}

/* ── Providers ── */

function renderProviderItem(provider) {
  const { escHtml, t } = getCtx();
  return `
    <div class="provider-item">
      <div class="provider-item-header">
        <div>
          <div class="provider-name">${escHtml(provider.name)}</div>
          <div class="provider-meta">
            <span class="tag">${escHtml(provider.type)}</span>
            ${provider.priority !== undefined ? `<span class="tag" style="background:rgba(255,255,255,.06);color:var(--text-muted);">P${escHtml(String(provider.priority))}</span>` : ''}
            <span style="font-size:12px;color:var(--text-muted);">${escHtml(provider.base_url || '')}</span>
            ${provider.default_model ? `<span style="font-size:12px;color:var(--text-muted);">/ ${escHtml(provider.default_model)}</span>` : ''}
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;">
          <button class="btn btn-secondary btn-sm" data-action="test-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.test')}</button>
          <button class="btn btn-secondary btn-sm" data-action="refresh-provider-models" data-provider-id="${escHtml(provider.id)}">${t('providers.refreshModels')}</button>
          <button class="btn btn-secondary btn-sm" data-action="edit-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.edit')}</button>
          <button class="btn btn-danger btn-sm" data-action="delete-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.delete')}</button>
        </div>
      </div>
      <div id="provider-test-result-${escHtml(provider.id)}"></div>
    </div>
  `;
}

function renderProviderForm(provider = null) {
  const { state, escHtml, t } = getCtx();
  const p = provider || {};
  const options = state.providerModelOptions || [];
  const currentModel = p.default_model || '';
  const priority = (p.priority !== undefined && p.priority !== null) ? String(p.priority) : '100';
  return `
    <form id="provider-form" class="card" style="max-width:600px;">
      <div class="form-row">
        <div class="form-group"><label for="pf-id">${t('providers.fieldId')}</label><input class="input" id="pf-id" type="text" placeholder="my-provider" value="${escHtml(p.id || '')}" required></div>
        <div class="form-group"><label for="pf-name">${t('providers.fieldName')}</label><input class="input" id="pf-name" type="text" placeholder="My Provider" value="${escHtml(p.name || '')}"></div>
      </div>
      <div class="form-group">
        <label for="pf-type">${t('providers.fieldType')}</label>
        <select class="input" id="pf-type" data-action="provider-type-change">
          ${['ollama', 'lmstudio', 'openai-compatible'].map((type) => `<option value="${type}" ${(p.type || 'ollama') === type ? 'selected' : ''}>${type}</option>`).join('')}
        </select>
      </div>
      <div class="form-group"><label for="pf-base-url">${t('providers.fieldBaseUrl')}</label><input class="input" id="pf-base-url" type="text" placeholder="http://localhost:11434" value="${escHtml(p.base_url || '')}"></div>
      <div class="form-group"><label for="pf-api-key">${t('providers.fieldApiKey')} <span style="font-weight:400;color:var(--text-muted);">${t('contextDoc.optional')}</span></label><input class="input" id="pf-api-key" type="password" placeholder="sk-…" value=""></div>
      <div class="form-group">
        <label for="pf-model">${t('providers.fieldModel')}</label>
        <input class="input" id="pf-model" type="text" placeholder="qwen2.5:14b" value="${escHtml(currentModel)}">
        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap;">
          <button class="btn btn-secondary btn-sm" type="button" data-action="fetch-provider-models">${t('providers.fetchModels')}</button>
          <select class="input" id="pf-model-select" style="min-width:260px;max-width:100%;">
            <option value="">— ${t('providers.selectFetchedModel')} —</option>
            ${options.map((m) => `<option value="${escHtml(m.id)}" ${m.id === currentModel ? 'selected' : ''}>${escHtml(m.name)}${m.details ? ` (${escHtml(m.details)})` : ''}</option>`).join('')}
          </select>
        </div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${t('providers.modelManualHint')}</div>
        <div id="provider-model-status" style="margin-top:8px;"></div>
      </div>
      <div class="form-group">
        <label for="pf-priority">${t('providers.fieldPriority')}</label>
        <input class="input" id="pf-priority" type="number" step="1" min="0" value="${escHtml(priority)}">
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${t('providers.priorityHint')}</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
        <input type="checkbox" id="pf-enabled" ${(p.enabled !== false) ? 'checked' : ''} style="width:16px;height:16px;accent-color:var(--accent);">
        <label for="pf-enabled" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;">${t('providers.fieldEnabled')}</label>
      </div>
      <button class="btn btn-primary" data-action="save-provider" type="submit">${t('providers.save')}</button>
      <div id="provider-form-result" style="margin-top:8px;"></div>
    </form>
  `;
}

function renderProviderRoutingSection() {
  const { state, escHtml, t } = getCtx();
  const providers = (state.providers || [])
    .filter((p) => p.enabled !== false)
    .slice()
    .sort((a, b) => (Number(a.priority ?? 100) - Number(b.priority ?? 100)) || String(a.id).localeCompare(String(b.id)));
  const s = state.providerRoutingSettings || {
    routing_mode: 'single-primary',
    primary_provider_id: '',
    preferred_provider_id: '',
    fallback_provider_ids: [],
    load_balance_strategy: 'round-robin',
  };

  const mode = s.routing_mode || 'single-primary';
  const fallback = Array.isArray(s.fallback_provider_ids) ? s.fallback_provider_ids : [];

  const providerOptionsFor = (selectedId) => providers.map((p) => {
    const sel = selectedId && selectedId === p.id ? 'selected' : '';
    return `<option value="${escHtml(p.id)}" ${sel}>${escHtml(p.name || p.id)}</option>`;
  }).join('');

  const modeOptions = [
    { v: 'single-primary',         label: t('providers.routing.singlePrimary') },
    { v: 'preferred-with-fallback',label: t('providers.routing.preferredFallback') },
    { v: 'load-balance',           label: t('providers.routing.loadBalance') },
    { v: 'agent-default',          label: t('providers.routing.agentDefault') },
  ];

  const showPrimary   = mode === 'single-primary' || mode === 'agent-default';
  const showPreferred = mode === 'preferred-with-fallback';
  const showFallback  = mode === 'preferred-with-fallback';
  const showLb        = mode === 'load-balance';

  return `
    <div class="section" style="margin-top:24px;">
      <div class="section-label" style="margin-bottom:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">${t('providers.routing.title')} ${renderTooltip(t('tooltip.adminRouting'))}</div>
      <div class="card-description" style="margin-bottom:10px;">${t('admin.routing.desc')}</div>
      <div class="card" style="max-width:800px;padding:18px;">
        <div class="form-row">
          <div class="form-group" style="flex:1;">
            <label for="pr-routing-mode">${t('providers.routing.mode')}</label>
            <select class="input" id="pr-routing-mode">
              ${modeOptions.map((o) => `<option value="${escHtml(o.v)}" ${mode === o.v ? 'selected' : ''}>${escHtml(o.label)}</option>`).join('')}
            </select>
          </div>
        </div>

        ${showPrimary ? `
          <div class="form-group">
            <label for="pr-primary">${t('providers.routing.primary')}</label>
            <select class="input" id="pr-primary">
              <option value="">—</option>
              ${providerOptionsFor(s.primary_provider_id || '')}
            </select>
          </div>
        ` : ''}

        ${showPreferred ? `
          <div class="form-group">
            <label for="pr-preferred">${t('providers.routing.preferred')}</label>
            <select class="input" id="pr-preferred">
              <option value="">—</option>
              ${providerOptionsFor(s.preferred_provider_id || '')}
            </select>
          </div>
        ` : ''}

        ${showFallback ? `
          <div class="form-group">
            <label>${t('providers.routing.fallback')}</label>
            <div style="display:flex;flex-direction:column;gap:6px;">
              ${providers.map((p) => {
                const checked = fallback.includes(p.id) ? 'checked' : '';
                return `
                  <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" class="pr-fallback" data-provider-id="${escHtml(p.id)}" ${checked} style="width:15px;height:15px;accent-color:var(--accent);">
                    <span>${escHtml(p.name || p.id)} <span style="color:var(--text-muted);font-size:12px;">(${escHtml(p.id)})</span></span>
                  </label>
                `;
              }).join('')}
            </div>
            <div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${t('providers.routing.fallbackHint')}</div>
          </div>
        ` : ''}

        ${showLb ? `
          <div class="form-row">
            <div class="form-group" style="flex:1;">
              <label for="pr-lb-strategy">${t('providers.routing.strategy')}</label>
              <select class="input" id="pr-lb-strategy">
                <option value="round-robin" ${(s.load_balance_strategy || 'round-robin') === 'round-robin' ? 'selected' : ''}>${t('providers.routing.roundRobin')}</option>
                <option value="random" ${(s.load_balance_strategy || 'round-robin') === 'random' ? 'selected' : ''}>${t('providers.routing.random')}</option>
              </select>
            </div>
          </div>
          <div class="provider-test-result" style="margin-top:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">
            ${t('providers.routing.loadBalanceHint')}
          </div>
        ` : ''}

        ${mode === 'agent-default' ? `
          <div class="provider-test-result" style="margin-top:8px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">
            ${t('providers.routing.agentDefaultHint')}
          </div>
        ` : ''}

        <div style="display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap;">
          <button class="btn btn-primary btn-sm" data-action="save-provider-routing">${t('providers.routing.save')}</button>
          ${state.providerRoutingSaveStatus === 'success' ? `<span class="provider-test-result ok" style="margin:0;">✅ ${escHtml(state.providerRoutingSaveMessage || t('providers.routing.saved'))}</span>` : ''}
          ${state.providerRoutingSaveStatus === 'error' ? `<span class="provider-test-result fail" style="margin:0;">❌ ${escHtml(state.providerRoutingSaveMessage || t('providers.routing.saveError'))}</span>` : ''}
        </div>
      </div>
    </div>
  `;
}

function renderProviders() {
  const { state, t } = getCtx();
  const providers = state.providers;
  return `
    <div class="page-header">
      <div class="page-title">${t('providers.title')}</div>
      <div class="page-subtitle">${t('providers.subtitle')}</div>
      <div class="card-description" style="margin-top:6px;">${t('admin.providers.page.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>
    <div data-ui="expert-only">
    ${renderProviderRoutingSection()}
    </div>
    ${providers.length === 0 ? `<div class="empty-state"><div class="empty-state-icon">⚙️</div><div class="empty-state-text">${t('providers.empty')}</div></div>` : providers.map(renderProviderItem).join('')}
    <div class="section" style="margin-top:24px;">
      <div class="section-label" style="margin-bottom:12px;">${t('providers.add')}</div>
      ${renderProviderForm()}
    </div>
  `;
}

/* ── Logs (admin) ── */

function renderLogs() {
  const { state, escHtml, t, formatDate } = (() => {
    const arena = window.DecisionArena;
    const s = arena.store.state;
    const { escHtml, formatDate } = arena.utils;
    const t = (key) => window.i18n?.t(key) ?? key;
    return { state: s, escHtml, formatDate, t };
  })();

  const logsState = state.logs || {};
  const filters = logsState.filters || {};
  const items = Array.isArray(logsState.items) ? logsState.items : [];

  const levels = ['', 'debug', 'info', 'warning', 'error'];
  const categories = [
    '',
    'llm_request', 'llm_response',
    'backend', 'frontend',
    'provider', 'prompt', 'routing', 'ui_action',
  ];

  const levelLabel = (lvl) => lvl ? t(`logs.level.${lvl}`) : `— ${t('logs.all')} —`;
  const catLabel = (c) => c ? t(`logs.category.${c}`) : `— ${t('logs.all')} —`;

  const badge = (text, kind) => {
    const cls = kind === 'error' ? 'badge-danger'
      : kind === 'warning' ? 'badge-warning'
        : kind === 'info' ? 'badge-info'
          : kind === 'debug' ? 'badge-muted'
            : 'badge-default';
    return `<span class="badge ${cls}" style="font-size:11px;">${escHtml(text)}</span>`;
  };

  const selected = logsState.selected;

  const quickBtn = (id, label) =>
    `<button class="btn btn-secondary btn-sm" data-action="logs-quick-filter" data-filter="${escHtml(id)}">${escHtml(label)}</button>`;

  const warning = `
    <div class="provider-test-result" style="margin:10px 0;background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.2);">
      ⚠️ ${t('logs.privacyWarning')}
      <span style="display:block;margin-top:4px;font-size:11px;color:var(--text-muted);">🗓 ${t('logs.retentionInfo')}</span>
    </div>
  `;

  return `
    <div class="page-header">
      <div class="page-title">${t('logs.title')}</div>
      <div class="page-subtitle">${t('logs.subtitle')}</div>
      <div class="card-description" style="margin-top:6px;">${t('admin.logs.page.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>

    ${warning}

    <div class="card" style="padding:16px;margin-bottom:14px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
        ${quickBtn('llm_requests', t('logs.quick.llmRequests'))}
        ${quickBtn('llm_responses', t('logs.quick.llmResponses'))}
        ${quickBtn('errors', t('logs.quick.errors'))}
        ${quickBtn('provider_issues', t('logs.quick.providerIssues'))}
        ${quickBtn('frontend_actions', t('logs.quick.frontendActions'))}
        ${state.currentSession?.id ? quickBtn('current_session', t('logs.quick.currentSession')) : ''}
      </div>

      <div class="form-row" style="align-items:flex-end;">
        <div class="form-group">
          <label>${t('logs.level')}</label>
          <select class="input" id="logs-level" data-logs-filter="level">
            ${levels.map((lvl) => `<option value="${escHtml(lvl)}" ${(filters.level||'') === lvl ? 'selected' : ''}>${escHtml(levelLabel(lvl))}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label>${t('logs.category')}</label>
          <select class="input" id="logs-category" data-logs-filter="category">
            ${categories.map((c) => `<option value="${escHtml(c)}" ${(filters.category||'') === c ? 'selected' : ''}>${escHtml(catLabel(c))}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label>${t('logs.session')}</label>
          <select class="input" id="logs-session" data-logs-filter="session_id">
            <option value="">— ${t('logs.all')} —</option>
            ${(state.sessions||[]).slice().sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||''))).slice(0,200).map((s) => (
              `<option value="${escHtml(s.id)}" ${(filters.session_id||'') === s.id ? 'selected' : ''}>${escHtml(s.title || s.id)} (${escHtml(s.id)})</option>`
            )).join('')}
          </select>
        </div>
      </div>

      <div class="form-row" style="align-items:flex-end;">
        <div class="form-group">
          <label>${t('logs.provider')}</label>
          <select class="input" id="logs-provider" data-logs-filter="provider_id">
            <option value="">— ${t('logs.all')} —</option>
            ${(state.providers||[]).filter((p)=>p.enabled!==false).map((p) => `<option value="${escHtml(p.id)}" ${(filters.provider_id||'') === p.id ? 'selected' : ''}>${escHtml(p.name || p.id)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label>${t('logs.agent')}</label>
          <select class="input" id="logs-agent" data-logs-filter="agent_id">
            <option value="">— ${t('logs.all')} —</option>
            ${(state.personas||[]).map((p) => `<option value="${escHtml(p.id)}" ${(filters.agent_id||'') === p.id ? 'selected' : ''}>${escHtml(p.name || p.id)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="flex:1;min-width:240px;">
          <label>${t('logs.search')}</label>
          <input class="input" id="logs-search" type="text" placeholder="${escHtml(t('logs.searchPlaceholder'))}" value="${escHtml(filters.search||'')}" data-logs-filter="search">
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center;">
        <button class="btn btn-primary btn-sm" data-action="logs-refresh">${t('logs.refresh')}</button>
        <button class="btn btn-secondary btn-sm" data-action="logs-clear-filters">${t('logs.clearFilters')}</button>
        <span style="font-size:12px;color:var(--text-muted);">${logsState.loading ? `<span class="spinner"></span> ${t('loading')}` : (logsState.error ? `❌ ${escHtml(logsState.error)}` : `${items.length} ${t('logs.rows')}`)}</span>
        <span style="margin-left:auto;"></span>
        <button class="btn btn-secondary btn-sm" data-action="logs-export" data-format="json">${t('logs.exportJson')}</button>
        <button class="btn btn-secondary btn-sm" data-action="logs-export" data-format="markdown">${t('logs.exportMarkdown')}</button>
      </div>
      ${logsState.exportStatus ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(logsState.exportStatus)}</div>` : ''}
    </div>

    <div style="display:grid;grid-template-columns:1.2fr .8fr;gap:14px;align-items:start;">
      <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:10px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
          <div style="font-weight:700;">${t('logs.tableTitle')}</div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary btn-sm" data-action="logs-delete-old">${t('logs.deleteOld7d')}</button>
            <button class="btn btn-danger btn-sm" data-action="logs-delete-all">${t('logs.deleteAll')}</button>
          </div>
        </div>
        ${logsState.maintenanceStatus ? `<div style="padding:10px 12px;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border);">${escHtml(logsState.maintenanceStatus)}</div>` : ''}
        <div style="max-height:520px;overflow:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead>
              <tr style="text-align:left;background:var(--bg-secondary);position:sticky;top:0;z-index:1;">
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.time')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.level')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.category')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.session')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.agent')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.provider')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.action')}</th>
                <th style="padding:8px 10px;border-bottom:1px solid var(--border);">${t('logs.col.message')}</th>
              </tr>
            </thead>
            <tbody>
              ${items.length === 0 ? `
                <tr><td colspan="8" style="padding:14px 10px;color:var(--text-muted);">${t('logs.empty')}</td></tr>
              ` : items.map((r) => {
                const lvl = r.level || 'info';
                const cat = r.category || '';
                const msg = r.error_message || r.action || '';
                const rowCls = (logsState.selectedId === r.id) ? 'background:rgba(99,102,241,0.10);' : '';
                return `
                  <tr data-action="logs-open" data-log-id="${escHtml(r.id)}" style="cursor:pointer;${rowCls}">
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);white-space:nowrap;">${escHtml((r.created_at||'').replace('T',' ').replace('Z',''))}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${badge(lvl, lvl)}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${escHtml(cat)}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${escHtml(r.session_id || '')}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${escHtml(r.agent_id || '')}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${escHtml(r.provider_id ? `${r.provider_id}${r.model ? ' / ' + r.model : ''}` : '')}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);">${escHtml(r.action || '')}</td>
                    <td style="padding:8px 10px;border-bottom:1px solid var(--border);color:var(--text-muted);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(msg)}</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card" style="padding:14px;position:sticky;top:12px;">
        <div style="font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">${t('logs.detailTitle')} ${renderTooltip(t('tooltip.adminLogsDetail'))}</div>
        ${!selected ? `<div style="font-size:12.5px;color:var(--text-muted);">${t('logs.selectHint')}</div>` : `
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
            ${badge(selected.level || 'info', selected.level || 'info')}
            ${selected.category ? badge(selected.category, 'default') : ''}
            <span style="font-size:12px;color:var(--text-muted);">${escHtml(selected.created_at || '')}</span>
          </div>
          <div style="font-size:12.5px;color:var(--text-secondary);margin-bottom:8px;">
            <div><strong>${t('logs.field.action')}:</strong> ${escHtml(selected.action || '')}</div>
            <div><strong>${t('logs.field.session')}:</strong> ${escHtml(selected.session_id || '')}</div>
            <div><strong>${t('logs.field.agent')}:</strong> ${escHtml(selected.agent_id || '')}</div>
            <div><strong>${t('logs.field.provider')}:</strong> ${escHtml(selected.provider_id || '')}</div>
            <div><strong>${t('logs.field.model')}:</strong> ${escHtml(selected.model || '')}</div>
            ${selected.error_message ? `<div style="margin-top:6px;"><strong>${t('logs.field.error')}:</strong> <span style="color:var(--danger);">${escHtml(selected.error_message)}</span></div>` : ''}
          </div>

          <div data-ui="expert-only">
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 8px;">
            <button class="btn btn-secondary btn-sm" data-action="logs-copy" data-copy-field="request_payload">${t('logs.copyPrompt')}</button>
            <button class="btn btn-secondary btn-sm" data-action="logs-copy" data-copy-field="response_payload">${t('logs.copyResponse')}</button>
            <button class="btn btn-secondary btn-sm" data-action="logs-copy" data-copy-field="metadata">${t('logs.copyMetadata')}</button>
          </div>

          <details open style="margin-top:8px;">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">${t('logs.requestPayload')}</summary>
            <pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:var(--bg-secondary);padding:10px;border-radius:6px;border:1px solid var(--border);">${escHtml(selected.request_payload || '')}</pre>
          </details>
          <details style="margin-top:10px;">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">${t('logs.responsePayload')}</summary>
            <pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:var(--bg-secondary);padding:10px;border-radius:6px;border:1px solid var(--border);">${escHtml(selected.response_payload || '')}</pre>
          </details>
          <details style="margin-top:10px;">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">${t('logs.metadata')}</summary>
            <pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:var(--bg-secondary);padding:10px;border-radius:6px;border:1px solid var(--border);">${escHtml(selected.metadata || '')}</pre>
          </details>
          </div>
        `}
      </div>
    </div>
  `;
}

/* ── Scenario Packs (admin) ── */

function renderScenarioPackAdminCard(pack) {
  const { escHtml, agentIcon, agentName, t } = getCtx();
  const isSystem  = pack.source === 'system';
  const modeIcons = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥', jury: '⚖️' };
  const agents    = (pack.persona_ids || []).slice(0, 6);
  return `
    <div class="session-card-full">
      <div class="session-card-full-header">
        <span style="font-size:22px;">${modeIcons[pack.recommended_mode] || '🎯'}</span>
        <div class="session-info" style="flex:1;">
          <div class="session-title">${escHtml(pack.name)}</div>
          <div class="session-meta" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
            <span class="badge badge-default">${escHtml(pack.recommended_mode)}</span>
            ${pack.target_profile ? `<span class="badge badge-info">${escHtml(pack.target_profile)}</span>` : ''}
            <span class="badge badge-muted">${t('scenario.admin.personaCount')}: ${agents.length}</span>
            <span class="badge badge-muted">${t('template.rounds')}: ${pack.rounds}</span>
            ${pack.force_disagreement ? `<span class="badge badge-warning">${t('template.forceDisagreement')}</span>` : ''}
            ${isSystem ? `<span class="badge badge-info">${t('scenario.admin.system')}</span>` : `<span class="badge badge-muted">${t('scenario.admin.custom')}</span>`}
          </div>
          ${pack.description ? `<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${escHtml(pack.description)}</div>` : ''}
        </div>
      </div>
      ${agents.length > 0 ? `<div class="session-agents" style="margin:8px 0;">${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}</div>` : ''}
      ${isSystem ? `<div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;font-style:italic;">ℹ️ ${t('scenario.admin.systemCannotEdit')}</div>` : ''}
      <div class="session-card-full-actions">
        <button class="btn btn-secondary btn-sm" data-action="duplicate-scenario-pack" data-scenario-id="${escHtml(pack.id)}">${t('scenario.admin.duplicate')}</button>
        ${!isSystem ? `<button class="btn btn-secondary btn-sm" data-action="edit-scenario-pack" data-scenario-id="${escHtml(pack.id)}">${t('scenario.admin.edit')}</button>` : ''}
        ${!isSystem ? `<button class="btn btn-danger btn-sm" data-action="delete-scenario-pack" data-scenario-id="${escHtml(pack.id)}" data-scenario-name="${escHtml(pack.name)}">${t('scenario.admin.delete')}</button>` : ''}
      </div>
    </div>
  `;
}

function renderScenarioPackForm(pack = null) {
  const { state, escHtml, t } = getCtx();
  const p = pack || {};
  const personas = state.personas || [];
  const modes    = ['chat','decision-room','confrontation','quick-decision','stress-test','jury'];
  const personaIds = Array.isArray(p.persona_ids) ? p.persona_ids.join(',') : '';
  return `
    <form id="scenario-pack-form" class="card" style="max-width:700px;margin-top:16px;">
      <div style="font-weight:600;font-size:14px;margin-bottom:14px;">
        ${p.id ? t('scenario.admin.edit') : t('scenario.admin.new')}
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="sp-id">${t('scenario.form.id')}</label>
          <input class="input" id="sp-id" type="text" placeholder="my-scenario" value="${escHtml(p.id || '')}" ${p.id ? 'readonly style="opacity:.6;"' : ''}>
        </div>
        <div class="form-group">
          <label for="sp-name">${t('scenario.form.name')}</label>
          <input class="input" id="sp-name" type="text" placeholder="My Scenario" value="${escHtml(p.name || '')}">
        </div>
      </div>
      <div class="form-group">
        <label for="sp-desc">${t('scenario.form.description')}</label>
        <textarea class="textarea" id="sp-desc" style="min-height:60px;">${escHtml(p.description || '')}</textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="sp-target">${t('scenario.form.targetProfile')}</label>
          <input class="input" id="sp-target" type="text" placeholder="Product Owner" value="${escHtml(p.target_profile || '')}">
        </div>
        <div class="form-group">
          <label for="sp-mode">${t('scenario.form.mode')}</label>
          <select class="input" id="sp-mode">
            ${modes.map((m) => `<option value="${m}" ${(p.recommended_mode || 'decision-room') === m ? 'selected' : ''}>${m}</option>`).join('')}
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="sp-personas">${t('scenario.form.personas')}</label>
        <input class="input" id="sp-personas" type="text" placeholder="dev,qa,architect" value="${escHtml(personaIds)}">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          ${t('scenario.admin.personaCount')} disponibles : ${personas.map((p) => p.id).join(', ')}
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="sp-rounds">${t('scenario.form.rounds')}</label>
          <input class="input" id="sp-rounds" type="number" min="1" max="10" value="${escHtml(String(p.rounds ?? 2))}">
        </div>
        <div class="form-group">
          <label for="sp-threshold">${t('scenario.form.threshold')} (${Math.round((p.decision_threshold || 0.55) * 100)}%)</label>
          <input class="input" id="sp-threshold" type="range" min="0.50" max="0.80" step="0.01" value="${escHtml(String(p.decision_threshold || 0.55))}">
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <input type="checkbox" id="sp-force" ${p.force_disagreement ? 'checked' : ''} style="width:15px;height:15px;accent-color:var(--accent);">
        <label for="sp-force" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;">${t('scenario.form.forceDisagreement')}</label>
      </div>
      <div class="form-group">
        <label for="sp-prompt">${t('scenario.form.promptStarter')}</label>
        <textarea class="textarea" id="sp-prompt" style="min-height:80px;">${escHtml(p.prompt_starter || '')}</textarea>
      </div>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-primary btn-sm" type="submit" data-action="save-scenario-pack" data-scenario-id="${escHtml(p.id || '')}">${t('scenario.form.save')}</button>
        <button class="btn btn-secondary btn-sm" type="button" data-action="cancel-scenario-pack-form">${t('scenario.form.cancel')}</button>
      </div>
      <div id="scenario-pack-form-result" style="margin-top:8px;"></div>
    </form>
  `;
}

function renderScenarioPacks() {
  const { state, t } = getCtx();
  const packs    = (state.scenarioPacks || []);
  const editing  = state.scenarioPackEditing || null;
  const showForm = state.scenarioPackShowForm || false;

  // Admin mode: show all packs (enabled + disabled)
  // Trigger a background load if not yet loaded
  const allPacks = (state.scenarioPacksAdmin || packs);
  if (!state.scenarioPacksAdmin) {
    requestAnimationFrame(() => {
      window.DecisionArena?.store?.state?.view === 'scenario-packs' &&
        window.DecisionArena.services?.ScenarioPackService?.list(true)
          .then((data) => {
            window.DecisionArena.store.state.scenarioPacksAdmin = Array.isArray(data) ? data : [];
            window.DecisionArena.render?.();
          }).catch(() => {});
    });
  }

  return `
    <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;">
      <div>
        <div class="page-title">${t('scenario.admin.title')}</div>
        <div class="page-subtitle">${t('scenario.admin.desc')}</div>
        <button class="btn btn-secondary btn-sm" data-nav="administration" style="margin-top:8px;">${t('nav.backAdmin')}</button>
      </div>
      <button class="btn btn-primary btn-sm" data-action="new-scenario-pack" style="margin-top:4px;">${t('scenario.admin.new')}</button>
    </div>

    ${showForm ? renderScenarioPackForm(editing) : ''}

    ${allPacks.length === 0
      ? `<div class="empty-state"><div class="empty-state-icon">🎯</div><div class="empty-state-text">${t('scenario.admin.title')} — aucun pack</div></div>`
      : `<div style="display:flex;flex-direction:column;gap:10px;">${allPacks.map(renderScenarioPackAdminCard).join('')}</div>`
    }
  `;
}

/* ═══════════════════════════════════════════════════════════════════════
   Feature 5 — Retrospective (Post-mortem Stats)
════════════════════════════════════════════════════════════════════════ */

function renderRetrospective() {
  const { state, t, escHtml } = getCtx();
  const stats = state.postmortemStats;
  const loading = state.postmortemStatsLoading;
  const loadErr = state.postmortemStatsError;

  const loadBtn = `<button type="button" class="btn btn-secondary btn-sm" data-action="load-postmortem-stats" ${loading ? 'disabled' : ''}>📊 ${loading ? '…' : t('postmortem.stats.load')}</button>`;

  const errBlock = loadErr
    ? `<div class="error-banner" style="margin-bottom:12px;">⚠️ ${escHtml(t('postmortem.stats.loadError'))} <span style="opacity:0.85;font-size:12px;">${escHtml(loadErr)}</span></div>`
    : '';

  if (!stats && !loadErr && !loading) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">${loadBtn}</div>`;
  }

  if (loading && !stats) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        ${errBlock}
        <div style="margin-bottom:12px;"><span class="spinner"></span> ${t('postmortem.stats.loading')}</div>
        ${loadBtn}
      </div>`;
  }

  const total = Number(stats?.total ?? 0);
  if (stats && total === 0 && !loading) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;">
        ${errBlock}
        <div style="color:var(--text-muted);font-size:13px;">${t('postmortem.stats.empty')}</div>
        <div style="color:var(--text-muted);font-size:12px;margin:10px 0;">${t('postmortem.stats.emptyHint')}</div>
        ${loadBtn}
      </div>`;
  }

  if (!stats || loadErr) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;">
        ${errBlock}
        ${loadBtn}
      </div>`;
  }

  // Global stats
  const totalPct = (n) => stats.total > 0 ? Math.round(n / stats.total * 100) : 0;
  const globalHtml = `
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
      <div class="card" style="padding:14px 18px;min-width:120px;text-align:center;">
        <div style="font-size:24px;font-weight:700;">${stats.total}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('postmortem.stats.total')}</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:120px;text-align:center;border:1px solid #22c55e30;">
        <div style="font-size:24px;font-weight:700;color:#22c55e;">${stats.correct}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('postmortem.stats.correct')} (${totalPct(stats.correct)}%)</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:120px;text-align:center;border:1px solid #f59e0b30;">
        <div style="font-size:24px;font-weight:700;color:#f59e0b;">${stats.partial}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('postmortem.stats.partial')} (${totalPct(stats.partial)}%)</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:120px;text-align:center;border:1px solid #ef444430;">
        <div style="font-size:24px;font-weight:700;color:#ef4444;">${stats.incorrect}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('postmortem.stats.incorrect')} (${totalPct(stats.incorrect)}%)</div>
      </div>
    </div>`;

  // By mode (SVG bar chart)
  const byMode = stats.by_mode || {};
  const modeKeys = Object.keys(byMode);
  const byModeHtml = modeKeys.length === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div style="font-weight:600;font-size:13px;margin-bottom:14px;">📊 ${t('postmortem.stats.by_mode')}</div>
      ${modeKeys.map((mode) => {
        const d = byMode[mode];
        const pct = d.total > 0 ? Math.round(d.correct / d.total * 100) : 0;
        return `
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <span style="font-size:12px;min-width:140px;color:var(--text-secondary);">${escHtml(mode.replace(/_/g, '-'))}</span>
            <div style="flex:1;height:12px;background:var(--border);border-radius:6px;overflow:hidden;">
              <div style="height:100%;width:${pct}%;background:#22c55e;border-radius:6px;"></div>
            </div>
            <span style="font-size:12px;color:var(--text-muted);min-width:60px;">${d.correct}/${d.total} (${pct}%)</span>
          </div>`;
      }).join('')}
    </div>`;

  // By agent
  const byAgent = stats.by_agent || {};
  const agentKeys = Object.keys(byAgent);
  const byAgentHtml = agentKeys.length === 0 ? '' : `
    <div class="card" style="padding:18px;">
      <div style="font-weight:600;font-size:13px;margin-bottom:14px;">🎭 ${t('postmortem.stats.by_agent')}</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        ${agentKeys.map((aid) => {
          const d = byAgent[aid];
          return `
            <div style="padding:10px 14px;background:var(--bg-secondary);border-radius:8px;min-width:120px;text-align:center;">
              <div style="font-weight:600;font-size:12px;margin-bottom:4px;">${escHtml(aid)}</div>
              <div style="font-size:11px;color:var(--text-muted);">${d.correct_sessions}/${d.sessions_rated} correct</div>
            </div>`;
        }).join('')}
      </div>
    </div>`;

  return `
    <div class="page-header">
      <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
    </div>
    <div style="max-width:800px;">
      ${globalHtml}
      ${byModeHtml}
      ${byAgentHtml}
      <div style="margin-top:12px;">${loadBtn}</div>
    </div>`;
}

/* ── Registration ── */

function registerAdminFeature() {
  window.DecisionArena.views.administration  = renderAdministration;
  window.DecisionArena.views.personas        = renderPersonas;
  window.DecisionArena.views.souls           = renderSouls;
  window.DecisionArena.views.providers       = renderProviders;
  window.DecisionArena.views.logs            = renderLogs;
  window.DecisionArena.views.templates       = renderTemplates;
  window.DecisionArena.views['template-maker']  = renderTemplateMaker;
  window.DecisionArena.views['persona-maker']   = renderPersonaMaker;
  window.DecisionArena.views['persona-builder'] = renderPersonaBuilder;
  window.DecisionArena.views['scenario-packs']  = renderScenarioPacks;
  window.DecisionArena.views.retrospective      = renderRetrospective;
  window.DecisionArena.views.shared.showPersonaModal             = showPersonaModal;
  window.DecisionArena.views.shared.buildPersonaMarkdownPreview  = buildPersonaMarkdownPreview;
  window.DecisionArena.views.shared.buildSoulMarkdownPreview     = buildSoulMarkdownPreview;
}

export {
  registerAdminFeature,
  renderAdministration,
  renderPersonas,
  showPersonaModal,
  renderSouls,
  renderTemplates,
  renderTemplateMaker,
  renderPersonaMaker,
  renderPersonaBuilder,
  renderProviders,
};

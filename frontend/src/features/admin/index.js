/**
 * Admin feature – view registration.
 * Covers: administration (hub par intention : Setup, Build, Run & Analyze, Avancé), personas, souls, providers,
 *         templates (y compris contexte scénario), template-maker, persona-maker, persona-builder,
 *         logs, retrospective.
 */

import {
  renderTooltip,
  renderDecisionDynamicsEditor,
  renderEmptyState,
  renderAlert,
  renderByokProviderConnectModal,
  renderDecisionOutcomeCard,
  renderDecisionBrief,
} from '../../ui/components.js';
import { maskProviderKey } from '../../core/store.js';
import { getAvailableProviders, formatRoutingOptionLabel } from '../../core/providerRouting.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, renderMarkdown, agentIcon, agentName, t };
}

/* ── Administration hub (blocs par intention : Setup → Build → Run → Avancé) ── */

function buildAdminBuildAgentsItems(uiMode) {
  const items = [
    {
      nav: 'personas',
      titleKey: 'admin.personas',
      icon: '🎭',
      descKey: 'admin.hub.short.personas',
      actionKey: 'admin.hub.action.open',
    },
  ];
  if (uiMode === 'expert') {
    items.push({
      nav: 'souls',
      titleKey: 'admin.souls',
      icon: '✨',
      descKey: 'admin.hub.short.souls',
      actionKey: 'admin.hub.action.open',
      expertOnly: true,
    });
  } else {
    items.push({
      nav: 'persona-maker',
      titleKey: 'admin.personaMaker',
      icon: '🤖',
      descKey: 'admin.hub.short.personaMaker',
      actionKey: 'admin.hub.action.create',
    });
  }
  return items;
}

const ADMIN_TEMPLATES_HUB_CARD = {
  nav: 'templates',
  titleKey: 'admin.templates',
  icon: '📋',
  descKey: 'admin.hub.short.templates',
  actionKey: 'admin.hub.action.open',
};

/** Mode simple : encart Démarrage (ordre conseillé) + 5 accès (pas de carte « nouvelle session » dupliquée). */
const ADMIN_SIMPLE_HUB_CARDS = [
  {
    nav: 'providers',
    icon: '⚙️',
    titleKey: 'admin.providers',
    descKey: 'admin.hub.short.providers',
    actionKey: 'admin.hub.action.configure',
  },
  {
    nav: 'personas',
    icon: '🎭',
    titleKey: 'admin.personas',
    descKey: 'admin.hub.short.personas',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'persona-maker',
    icon: '🤖',
    titleKey: 'admin.personaMaker',
    descKey: 'admin.hub.short.personaMaker',
    actionKey: 'admin.hub.action.create',
  },
  {
    nav: 'templates',
    icon: '📋',
    titleKey: 'admin.templates',
    descKey: 'admin.hub.short.templates',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'memories',
    icon: '🧠',
    titleKey: 'admin.memories',
    descKey: 'admin.hub.short.memories',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'sessions',
    icon: '📁',
    titleKey: 'sessions.title',
    descKey: 'admin.hub.short.history',
    actionKey: 'admin.hub.action.open',
  },
];

const ADMIN_RUN_ANALYZE_ITEMS = [
  {
    nav: 'sessions',
    titleKey: 'admin.run.label.history',
    icon: '📁',
    descKey: 'admin.hub.short.history',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'retrospective',
    titleKey: 'admin.retrospective',
    icon: '🔮',
    descKey: 'admin.hub.short.retrospective',
    actionKey: 'admin.hub.action.open',
    expertOnly: true,
  },
];

const ADMIN_ADVANCED_ITEMS = [
  {
    nav: 'memories',
    titleKey: 'admin.memories',
    icon: '🧠',
    descKey: 'admin.hub.short.memories',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'persona-builder',
    titleKey: 'admin.personaBuilder',
    icon: '🔧',
    descKey: 'admin.hub.short.personaBuilder',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'prompt-policies',
    titleKey: 'admin.promptPolicies.title',
    icon: '📝',
    descKey: 'admin.hub.short.policies',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'logs',
    titleKey: 'admin.logs',
    icon: '🧾',
    descKey: 'admin.hub.short.logs',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'learning',
    titleKey: 'admin.learning.title',
    icon: '🧬',
    descKey: 'admin.hub.short.learning',
    actionKey: 'admin.hub.action.open',
  },
  {
    nav: 'cognitive-governance',
    titleKey: 'admin.cognitiveGovernance.title',
    icon: '🧭',
    descKey: 'admin.hub.short.cognitiveGovernance',
    actionKey: 'admin.hub.action.open',
    expertOnly: true,
  },
];

function renderAdminCard(item, t, escHtml) {
  const expertAttr = item.expertOnly ? ' data-ui="expert-only"' : '';
  const actionKey = item.actionKey || 'admin.hub.action.open';
  const label = `${t(item.titleKey)} — ${t(item.descKey)}`;
  return `
    <div class="admin-card admin-card--compact"${expertAttr} data-nav="${escHtml(item.nav)}" tabindex="0" role="link" aria-label="${escHtml(label)}">
      <span class="admin-card-icon" aria-hidden="true">${item.icon}</span>
      <div class="admin-card-main">
        <div class="admin-card-title">${escHtml(t(item.titleKey))}</div>
        <p class="admin-card-description">${escHtml(t(item.descKey))}</p>
        <span class="admin-card-cta">${escHtml(t(actionKey))}</span>
      </div>
    </div>`;
}

function renderAdminSection(section, t, escHtml) {
  const expertAttr = section.expertOnly ? ' data-ui="expert-only"' : '';
  const hasHeading = Boolean(section.titleKey);
  const labelledBy = hasHeading ? ` aria-labelledby="admin-section-${escHtml(section.id)}"` : '';
  const titleHtml = hasHeading
    ? `<h3 class="admin-section-title" id="admin-section-${escHtml(section.id)}">
        <span class="admin-section-emoji" aria-hidden="true">${section.sectionIcon}</span>
        ${escHtml(t(section.titleKey))}
      </h3>`
    : '';
  const gridCls = ['admin-card-grid', section.gridClass].filter(Boolean).join(' ');
  return `
    <section class="admin-section"${expertAttr}${labelledBy}>
      ${titleHtml}
      <div class="${escHtml(gridCls)}">
        ${section.items.map((it) => renderAdminCard(it, t, escHtml)).join('')}
      </div>
    </section>`;
}

function renderAdminCardGrid(items, t, escHtml, gridExtraClass = '') {
  const cls = ['admin-card-grid', gridExtraClass].filter(Boolean).join(' ');
  return `<div class="${escHtml(cls)}">${items.map((it) => renderAdminCard(it, t, escHtml)).join('')}</div>`;
}

/** Bloc visuel principal (Setup, Build, Run & Analyze, Advanced) */
function renderAdminIntentBlock(t, escHtml, { blockId, titleKey, icon, innerHtml, expertOnly }) {
  const eid = escHtml(blockId);
  const expertAttr = expertOnly ? ' data-ui="expert-only"' : '';
  const iconHtml = icon ? `<span class="admin-block-emoji" aria-hidden="true">${icon}</span>` : '';
  return `
    <div class="admin-intent-block"${expertAttr}>
      <h2 class="admin-block-title" id="admin-block-${eid}">
        ${iconHtml}${escHtml(t(titleKey))}
      </h2>
      <div class="admin-intent-body" aria-labelledby="admin-block-${eid}">
        ${innerHtml}
      </div>
    </div>`;
}

function renderSetupQuickPath(t, escHtml) {
  return `
    <div class="admin-get-started admin-get-started--compact" aria-labelledby="admin-block-setup">
      <p class="admin-get-started-lead">${escHtml(t('admin.home.setup.leadShort'))}</p>
      <ol class="admin-get-started-list">
        <li>
          <button type="button" class="admin-get-started-link" data-nav="providers">
            <span class="admin-get-started-num">1.</span>
            <span>${escHtml(t('admin.home.setup.step.connectAi'))}</span>
          </button>
        </li>
        <li>
          <button type="button" class="admin-get-started-link" data-nav="personas">
            <span class="admin-get-started-num">2.</span>
            <span>${escHtml(t('admin.home.setup.step.createAgent'))}</span>
          </button>
        </li>
        <li>
          <button type="button" class="admin-get-started-link" data-nav="templates">
            <span class="admin-get-started-num">3.</span>
            <span>${escHtml(t('admin.home.setup.step.createTemplate'))}</span>
          </button>
        </li>
        <li>
          <button type="button" class="admin-get-started-link" data-nav="new-session">
            <span class="admin-get-started-num">4.</span>
            <span>${escHtml(t('admin.home.setup.step.startSession'))}</span>
          </button>
        </li>
      </ol>
    </div>`;
}

function renderSystemStatus() {
  const { state, t, escHtml } = getCtx();
  const providers = state.providers || [];
  const hasProvider = providers.length > 0;
  const isExpert = state.uiMode === 'expert';
  const statusText = hasProvider
    ? (t('admin.status.ready') !== 'admin.status.ready' ? t('admin.status.ready') : 'Setup prêt : vous pouvez lancer une analyse.')
    : (t('admin.status.noProvider') !== 'admin.status.noProvider' ? t('admin.status.noProvider') : 'Setup incomplet : configurez un provider LLM.');
  const expertNote = isExpert
    ? `<div class="system-status-note">${t('admin.status.expertMode') !== 'admin.status.expertMode' ? escHtml(t('admin.status.expertMode')) : 'Mode expert : outils avancés visibles.'}</div>`
    : '';
  return `
    <div class="system-status-card system-status-card--compact${hasProvider ? ' system-status-ok' : ' system-status-warning'}">
      <div class="system-status-text">${escHtml(statusText)}</div>
      ${expertNote}
    </div>`;
}

function renderAdministrationSimple(t, escHtml, pageTitle) {
  return `
    <div class="page-header">
      <div class="page-title">${escHtml(pageTitle)}</div>
      <div class="page-subtitle">${escHtml(t('admin.home.subtitleSimple'))}</div>
    </div>
    <div class="admin-home admin-home--simple admin-workspace">
      ${renderSystemStatus()}
      ${renderAdminIntentBlock(t, escHtml, {
        blockId: 'setup',
        titleKey: 'admin.block.setup',
        icon: '🛠️',
        innerHtml: renderSetupQuickPath(t, escHtml),
        expertOnly: false,
      })}
      ${renderAdminCardGrid(ADMIN_SIMPLE_HUB_CARDS, t, escHtml, ' admin-card-grid--hub-simple')}
    </div>
  `;
}

function renderAdministrationExpert(t, escHtml, state, pageTitle) {
  const setupBlock = renderAdminIntentBlock(t, escHtml, {
    blockId: 'setup',
    titleKey: 'admin.block.setup',
    icon: '🛠️',
    innerHtml: renderSetupQuickPath(t, escHtml),
    expertOnly: false,
  });
  const buildBlock = renderAdminIntentBlock(t, escHtml, {
    blockId: 'build',
    titleKey: 'admin.block.build',
    icon: '🧱',
    innerHtml: `
      <div class="admin-sections admin-sections--nested">
        ${renderAdminSection(
          {
            id: 'build-agents',
            titleKey: 'admin.section.build.agents',
            sectionIcon: '🧠',
            gridClass: 'admin-card-grid--hub-expert',
            items: buildAdminBuildAgentsItems(state.uiMode),
          },
          t,
          escHtml,
        )}
        ${renderAdminSection(
          {
            id: 'build-templates',
            titleKey: 'admin.section.build.templates',
            sectionIcon: '📋',
            gridClass: 'admin-card-grid--hub-expert',
            items: [ADMIN_TEMPLATES_HUB_CARD],
          },
          t,
          escHtml,
        )}
      </div>`,
    expertOnly: false,
  });
  const runBlock = renderAdminIntentBlock(t, escHtml, {
    blockId: 'run-analyze',
    titleKey: 'admin.block.runAnalyze',
    icon: '📊',
    innerHtml: `
      ${renderAdminCardGrid(ADMIN_RUN_ANALYZE_ITEMS, t, escHtml, ' admin-card-grid--hub-expert')}`,
    expertOnly: false,
  });
  const expertToolsBlock = renderAdminIntentBlock(t, escHtml, {
    blockId: 'expert-tools',
    titleKey: 'admin.block.expertTools',
    icon: '🔬',
    innerHtml: renderAdminCardGrid(ADMIN_ADVANCED_ITEMS, t, escHtml, ' admin-card-grid--hub-expert'),
    expertOnly: true,
  });
  const experimentalFeaturesBlock = renderAdminIntentBlock(t, escHtml, {
    blockId: 'experimental-features',
    titleKey: 'admin.experimental.title',
    icon: '🧪',
    expertOnly: true,
    innerHtml: `
      <div class="card admin-card" style="padding:14px 16px;border:1px solid var(--border-color);background:var(--bg-secondary, rgba(255,255,255,0.03));">
        <div style="font-size:13px;color:var(--text-secondary);line-height:1.5;margin-bottom:12px;">${escHtml(t('admin.experimental.description'))}</div>
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-experimental-features" ${state.experimentalFeaturesEnabled ? 'checked' : ''} style="margin-top:4px;" />
          <span>
            <span style="font-weight:700;color:var(--text-primary);">${escHtml(t('admin.experimental.toggle'))}</span>
            <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">${escHtml(state.experimentalFeaturesEnabled ? t('admin.experimental.enabled') : t('admin.experimental.disabled'))}</div>
          </span>
        </label>
      </div>`,
  });
  return `
    <div class="page-header">
      <div class="page-title">${escHtml(pageTitle)}</div>
      <div class="page-subtitle">${escHtml(t('admin.home.subtitle'))}</div>
    </div>
    <div class="admin-home admin-home--expert admin-workspace">
      ${renderSystemStatus()}
      ${setupBlock}
      ${buildBlock}
      ${runBlock}
      ${expertToolsBlock}
      ${experimentalFeaturesBlock}
    </div>
  `;
}

function renderAdministration() {
  const { state, t, escHtml } = getCtx();
  const isSimple = state.uiMode === 'basic';
  const pageTitle = isSimple
    ? (t('admin.home.titleSimple') !== 'admin.home.titleSimple' ? t('admin.home.titleSimple') : 'Configuration')
    : t('admin.title');
  if (isSimple) {
    return renderAdministrationSimple(t, escHtml, pageTitle);
  }
  return renderAdministrationExpert(t, escHtml, state, pageTitle);
}

/* ── Agent dynamics recommendations (post-mortem, suggestions only) ── */

function dynamicsRecoDismissedSet() {
  try {
    const raw = JSON.parse(localStorage.getItem('da_dynamics_reco_dismissed') || '[]');
    return new Set(Array.isArray(raw) ? raw : []);
  } catch (_) {
    return new Set();
  }
}

/** @param {object} reco */
function formatDynamicsRecoMessageAdmin(reco, agentLabel, t) {
  const key = reco.reason_key || '';
  let msg = key && t(key) !== key ? t(key) : '';
  if (!msg) msg = reco.reason || '';
  const ra = reco.reason_args || {};
  const deltaDisp = reco.suggestion === 'decrease_reputation'
    ? String(Math.abs(Number(ra.delta ?? reco.reputation_delta ?? 0)))
    : String(Number(ra.delta ?? reco.reputation_delta ?? 0));
  return msg
    .replace(/\{agent\}/g, agentLabel)
    .replace(/\{count\}/g, String(ra.count ?? ''))
    .replace(/\{delta\}/g, deltaDisp)
    .replace(/\{mode\}/g, String(ra.mode ?? '–'));
}

function renderDynamicsRecoAdminPanel() {
  const { state, escHtml, t, agentIcon, agentName } = getCtx();
  const pkg = state.adminDynamicsReco;
  const dismissed = dynamicsRecoDismissedSet();
  const list = pkg?.suggestions;
  const visible = Array.isArray(list) ? list.filter((r) => r.id && !dismissed.has(r.id)) : [];

  let body = '';
  if (!pkg) {
    body = `
      <p class="card-description" style="font-size:12px;margin:0 0 12px;line-height:1.45;">${escHtml(t('dynamicsReco.adminHelp'))}</p>
      <button type="button" class="btn btn-secondary btn-sm" data-action="load-dynamics-reco-admin">${escHtml(t('dynamicsReco.loadButton'))}</button>`;
  } else if (pkg.loading) {
    body = `
      <div style="display:flex;align-items:center;gap:10px;">
        <span class="spinner"></span>
        <span style="font-size:13px;color:var(--text-muted);">${escHtml(t('learning.loading'))}</span>
      </div>`;
  } else if (pkg.error) {
    body = `
      <div class="error-banner" style="margin-bottom:10px;padding:10px 12px;font-size:12px;">${escHtml(pkg.error)}</div>
      <button type="button" class="btn btn-secondary btn-sm" data-action="load-dynamics-reco-admin">${escHtml(t('dynamicsReco.loadButton'))}</button>`;
  } else if (visible.length === 0) {
    body = `
      <div style="font-size:13px;color:var(--text-muted);">${escHtml(t('dynamicsReco.none'))}</div>
      <button type="button" class="btn btn-secondary btn-sm" style="margin-top:10px;" data-action="load-dynamics-reco-admin">${escHtml(t('dynamicsReco.loadButton'))}</button>`;
  } else {
    body = visible.map((reco) => {
      const aid = reco.agent_id || '';
      const an = agentName(aid);
      const text = formatDynamicsRecoMessageAdmin(reco, `${agentIcon(aid)} ${escHtml(an)}`, t);
      const confPct = Math.round(Math.min(99, Math.max(35, Number(reco.confidence || 0) * 100)));
      const sig = reco.suggestion === 'decrease_reputation' ? '−' : '+';
      const rid = escHtml(reco.id);
      const absDelta = Math.abs(Number(reco.reputation_delta ?? 0));
      return `
        <div style="padding:14px;background:var(--bg-secondary);border-radius:10px;margin-bottom:12px;border:1px solid var(--border);">
          <div style="font-size:14px;line-height:1.5;color:var(--text-primary);">${text}</div>
          <div style="margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="badge badge-muted">${escHtml(t('dynamicsReco.confidence'))} ${confPct}%</span>
            <span class="badge badge-warning" style="font-size:11px;">${sig}${escHtml(absDelta.toFixed(2))}</span>
            <div style="margin-left:auto;display:flex;gap:8px;">
              <button type="button" class="btn btn-primary btn-sm" data-action="apply-dynamics-reco-suggestion" data-suggestion-id="${rid}" data-source="admin">${escHtml(t('dynamicsReco.apply'))}</button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="dismiss-dynamics-reco-suggestion" data-suggestion-id="${rid}">${escHtml(t('dynamicsReco.ignore'))}</button>
            </div>
          </div>
        </div>`;
    }).join('');
  }

  return `
    <div class="card dynamics-reco-admin-card" style="padding:18px;margin-bottom:20px;background:rgba(99,102,241,0.04);border:1px solid rgba(99,102,241,0.2);">
      <div style="font-weight:700;font-size:15px;margin-bottom:6px;">💡 ${escHtml(t('dynamicsReco.adminSection'))}</div>
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;line-height:1.45;">${escHtml(pkg?.disclaimer || t('dynamicsReco.adminHelp'))}</div>
      ${body}
    </div>`;
}

function renderDecisionDynamicsPresetAdminReference() {
  const { escHtml, t } = getCtx();
  const items = ['balanced', 'conservative', 'aggressive', 'critical'].map((id) => `
    <li style="margin-bottom:6px;"><strong>${escHtml(t(`dynamicsPreset.${id}`))}</strong> — ${escHtml(t(`dynamicsPreset.desc.${id}`))}</li>
  `).join('');
  return `
    <div class="card dynamics-preset-admin-ref" style="padding:16px;margin-bottom:20px;font-size:12px;line-height:1.45;">
      <div style="font-weight:700;margin-bottom:10px;">${escHtml(t('dynamicsPreset.adminReferenceTitle'))}</div>
      <ul style="margin:0;padding-left:18px;color:var(--text-secondary);">${items}</ul>
    </div>`;
}

/* ── Personas ── */

function getPersonaSandboxState(state) {
  if (!state.personaSandbox) {
    state.personaSandbox = {
      prompt: '',
      personaId: '',
      providerId: '',
      model: '',
      temperature: '',
      compareMode: 'single',
      comparePersonaIds: [],
      compareProviderIds: [],
      compareModelsText: '',
      loading: false,
      error: null,
      results: [],
    };
  }
  return state.personaSandbox;
}

function renderSandboxChecklist(items, selectedIds, listName, escHtml) {
  const selected = new Set(selectedIds || []);
  return `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
      ${items.map((item) => {
        const id = String(item.id || '');
        const label = item.label || item.name || id;
        return `
          <label class="mode-check-label" style="background:var(--bg-primary);border:1px solid var(--border);border-radius:8px;padding:8px 10px;">
            <input type="checkbox" data-ps-list="${escHtml(listName)}" value="${escHtml(id)}" ${selected.has(id) ? 'checked' : ''} style="accent-color:var(--accent);">
            <span>${escHtml(label)}</span>
          </label>`;
      }).join('')}
    </div>`;
}

function renderPersonaSandboxResults(results, escHtml) {
  if (!Array.isArray(results) || results.length === 0) {
    return '';
  }
  return `
    <div style="margin-top:16px;display:grid;gap:12px;">
      ${results.map((run) => {
        const diagnostics = run.diagnostics || {};
        const parser = diagnostics.parser_diagnostics || null;
        const confidence = typeof parser?.parser_confidence === 'number'
          ? `${Math.round(parser.parser_confidence * 100)}%`
          : 'n/a';
        const diagJson = JSON.stringify(diagnostics, null, 2);
        const statusCls = run.error ? 'fail' : 'ok';
        const statusText = run.error ? run.error : `${run.duration_ms || 0} ms`;
        return `
          <div class="card" style="padding:14px;border-radius:8px;">
            <div style="display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
              <div>
                <div style="font-weight:700;color:var(--text-primary);">${escHtml(run.persona_name || run.persona_id || 'Persona')}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                  ${escHtml(run.provider_name || run.provider_id || 'Auto routing')} - ${escHtml(run.model || 'model default')} - parser ${escHtml(confidence)}
                </div>
              </div>
              <span class="provider-test-result ${statusCls}" style="margin-top:0;">${escHtml(statusText)}</span>
            </div>
            <div style="margin-top:12px;">
              <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Reponse brute</div>
              <pre class="code-preview" style="max-height:320px;margin:0;">${escHtml(run.raw_response || '')}</pre>
            </div>
            <details style="margin-top:10px;">
              <summary style="cursor:pointer;font-size:12px;color:var(--text-muted);font-weight:600;">Prompt systeme</summary>
              <pre class="code-preview" style="max-height:260px;margin-top:8px;">${escHtml(run.system_prompt || '')}</pre>
            </details>
            <details style="margin-top:8px;">
              <summary style="cursor:pointer;font-size:12px;color:var(--text-muted);font-weight:600;">Diagnostics runtime</summary>
              <pre class="code-preview" style="max-height:260px;margin-top:8px;">${escHtml(diagJson)}</pre>
            </details>
          </div>`;
      }).join('')}
    </div>`;
}

function renderPersonaSandboxPanel() {
  const { state, escHtml } = getCtx();
  const personas = state.personas || [];
  const providers = getAvailableProviders(state);
  const sb = getPersonaSandboxState(state);
  const selectedPersonaId = sb.personaId || personas[0]?.id || '';
  const selectedProviderId = sb.providerId || '';
  const selectedPersonaIds = Array.isArray(sb.comparePersonaIds) && sb.comparePersonaIds.length
    ? sb.comparePersonaIds
    : personas.slice(0, 2).map((p) => p.id);
  const selectedProviderIds = Array.isArray(sb.compareProviderIds) && sb.compareProviderIds.length
    ? sb.compareProviderIds
    : providers.slice(0, 2).map((p) => p.id);
  const providerOptions = providers.map((p) => `
    <option value="${escHtml(p.id)}" ${selectedProviderId === p.id ? 'selected' : ''}>${escHtml(formatRoutingOptionLabel(p))}</option>
  `).join('');
  const personaOptions = personas.map((p) => `
    <option value="${escHtml(p.id)}" ${selectedPersonaId === p.id ? 'selected' : ''}>${escHtml(p.name || p.id)}</option>
  `).join('');
  const compareMode = sb.compareMode || 'single';
  const compareControls = compareMode === 'persona'
    ? `<div class="form-group" style="grid-column:1/-1;"><label>Personas a comparer</label>${renderSandboxChecklist(personas, selectedPersonaIds, 'comparePersonaIds', escHtml)}</div>`
    : compareMode === 'provider'
      ? `<div class="form-group" style="grid-column:1/-1;"><label>Providers a comparer</label>${renderSandboxChecklist(providers, selectedProviderIds, 'compareProviderIds', escHtml)}</div>`
      : compareMode === 'model'
        ? `<div class="form-group" style="grid-column:1/-1;"><label>Modeles a comparer</label><textarea class="input" rows="3" data-ps-field="compareModelsText" placeholder="qwen2.5:7b&#10;qwen2.5:14b">${escHtml(sb.compareModelsText || '')}</textarea><div class="card-description" style="font-size:12px;margin-top:6px;">Un modele par ligne, ou separe par virgules.</div></div>`
        : '';

  return `
    <div class="card persona-sandbox-card" style="padding:18px;margin-bottom:20px;border-radius:8px;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
          <div style="font-weight:800;font-size:16px;color:var(--text-primary);">Test Persona</div>
          <div class="card-description" style="font-size:12px;margin-top:4px;">Sandbox local pour tuner une persona, comparer providers/modeles, et inspecter prompt + sortie brute.</div>
        </div>
        <button class="btn btn-primary btn-sm" data-action="run-persona-sandbox" ${sb.loading ? 'disabled' : ''}>
          ${sb.loading ? '<span class="spinner"></span> Test en cours...' : 'Run test'}
        </button>
      </div>
      <div class="form-group">
        <label>Prompt utilisateur</label>
        <textarea class="input" rows="4" data-ps-field="prompt" placeholder="Ex: Challenge cette idee de go-to-market en 5 points concrets.">${escHtml(sb.prompt || '')}</textarea>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
        <div class="form-group">
          <label>Persona</label>
          <select class="input" data-ps-field="personaId">${personaOptions}</select>
        </div>
        <div class="form-group">
          <label>Provider</label>
          <select class="input" data-ps-field="providerId">
            <option value="" ${selectedProviderId === '' ? 'selected' : ''}>Auto / routage global</option>
            ${providerOptions}
          </select>
        </div>
        <div class="form-group">
          <label>Modele</label>
          <input class="input" data-ps-field="model" value="${escHtml(sb.model || '')}" placeholder="default ou nom exact">
        </div>
        <div class="form-group">
          <label>Temperature optionnelle</label>
          <input class="input" data-ps-field="temperature" type="number" min="0" max="2" step="0.1" value="${escHtml(sb.temperature || '')}" placeholder="ex: 0.4">
        </div>
        <div class="form-group">
          <label>Comparaison</label>
          <select class="input" data-ps-field="compareMode">
            <option value="single" ${compareMode === 'single' ? 'selected' : ''}>Single run</option>
            <option value="persona" ${compareMode === 'persona' ? 'selected' : ''}>Persona vs persona</option>
            <option value="model" ${compareMode === 'model' ? 'selected' : ''}>Modele vs modele</option>
            <option value="provider" ${compareMode === 'provider' ? 'selected' : ''}>Provider vs provider</option>
          </select>
        </div>
        ${compareControls}
      </div>
      ${sb.error ? `<div class="provider-test-result fail">${escHtml(sb.error)}</div>` : ''}
      ${renderPersonaSandboxResults(sb.results, escHtml)}
    </div>`;
}

function renderPersonas() {
  const { state, escHtml, t } = getCtx();
  const personas  = state.personas;
  const providers = Array.isArray(state.providers) ? state.providers : [];
  const activeProviders = providers.filter((p) => p.enabled == 1 || p.enabled === true);
  const disabledById = new Map(
    providers
      .filter((p) => !(p.enabled == 1 || p.enabled === true))
      .map((p) => [String(p.id), p]),
  );
  const providerOptsForPersona = (selectedId) => [
    `<option value="">${escHtml(t('personas.defaultLlm.noProvider'))}</option>`,
    ...(selectedId && disabledById.has(String(selectedId))
      ? [`<option value="${escHtml(String(selectedId))}" selected>${escHtml((disabledById.get(String(selectedId))?.name || selectedId) + ' — ' + t('providers.statusDisabled'))}</option>`]
      : []),
    ...activeProviders.map((pr) => `<option value="${escHtml(pr.id)}" ${String(selectedId || '') === String(pr.id) ? 'selected' : ''}>${escHtml(pr.name || pr.id)}</option>`),
  ].join('');
  const allModes  = ['chat', 'decision-room', 'confrontation'];
  const modeLabels = { 'chat': t('personas.modeChat'), 'decision-room': t('personas.modeDR'), 'confrontation': t('personas.modeConfrontation') };
  return `
    <div class="page-header">
      <div class="page-title">${t('personas.title')}</div>
      <div class="page-subtitle">${t('personas.subtitle')}</div>
      <div class="card-description" style="margin-top:6px;">${t('admin.personas.page.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>
    ${renderPersonaSandboxPanel()}
    ${renderDynamicsRecoAdminPanel()}
    ${renderDecisionDynamicsPresetAdminReference()}
    ${personas.length === 0 ? `<div class="loading-state"><span class="spinner"></span> ${t('personas.loading')}</div>` : `
      <div class="personas-grid">
        ${personas.map((p) => {
          const enabledModes = Array.isArray(p.available_modes) ? p.available_modes : allModes;
          const pid = escHtml(p.id);
          return `
            <div class="persona-card persona-card-admin">
              <div class="persona-card-top" data-action="show-persona" data-persona-id="${pid}" style="cursor:pointer;">
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
                      <input type="checkbox" class="mode-checkbox" data-persona-id="${pid}" data-mode="${escHtml(mode)}" ${enabledModes.includes(mode) ? 'checked' : ''} style="accent-color:var(--accent);">
                      <span>${modeLabels[mode]}</span>
                    </label>
                  `).join('')}
                </div>
                <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="save-persona-modes" data-persona-id="${pid}">${t('personas.saveModes')}</button>
                <span class="mode-save-status" id="mode-status-${pid}"></span>
              </div>
              <div class="persona-dynamics-section" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                ${renderDecisionDynamicsEditor(p, { uiMode: state.uiMode, escHtml, t })}
              </div>
              ${state.uiMode === 'expert' ? `
              <div class="persona-default-llm-section" data-ui="expert-only" data-persona-llm-card="${pid}" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
                <div style="font-weight:600;font-size:12px;color:var(--text-secondary);margin-bottom:6px;">${escHtml(t('personas.defaultLlm.title'))}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;line-height:1.45;">${escHtml(t('personas.defaultLlm.help'))}</div>
                <div class="form-group" style="margin-bottom:8px;">
                  <label style="font-size:12px;">${escHtml(t('personas.defaultLlm.provider'))}</label>
                  <select class="input" data-persona-llm-prov style="margin-top:4px;">
                    ${providerOptsForPersona(p.default_provider || '')}
                  </select>
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                  <label style="font-size:12px;">${escHtml(t('personas.defaultLlm.model'))}</label>
                  <input class="input" type="text" data-persona-llm-model style="margin-top:4px;" value="${escHtml(p.default_model || '')}" placeholder="ex: qwen2.5:14b">
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-action="save-persona-default-llm" data-persona-id="${pid}">${escHtml(t('personas.defaultLlm.save'))}</button>
                <span class="persona-llm-save-status" id="persona-llm-status-${pid}" style="margin-left:8px;font-size:11px;color:var(--text-muted);"></span>
              </div>` : ''}
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
  const aria = `${template.name} — ${t('template.use')}`;
  return `
    <div class="session-card-full session-card-full--template-select"
         data-action="use-template"
         data-template-id="${escHtml(template.id)}"
         role="button"
         tabindex="0"
         aria-label="${escHtml(aria)}">
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
      <div class="session-card-full-primary-hint" aria-hidden="true">▶ ${t('template.use')}</div>
      <div class="session-card-full-actions">
        ${!isSystem ? `<button type="button" class="btn btn-secondary btn-sm" data-action="edit-template" data-template-id="${escHtml(template.id)}">${t('template.edit')}</button>` : ''}
        <button type="button" class="btn btn-secondary btn-sm" data-action="duplicate-template" data-template-id="${escHtml(template.id)}">${t('template.duplicate')}</button>
        ${!isSystem ? `<button type="button" class="btn btn-danger btn-sm" data-action="delete-template" data-template-id="${escHtml(template.id)}" data-template-name="${escHtml(template.name)}">${t('template.delete')}</button>` : ''}
      </div>
    </div>
  `;
}

function renderTemplatesCreatePanel(t, escHtml) {
  return `
    <div class="card admin-templates-create-panel" style="margin-bottom:20px;padding:18px;">
      <div style="font-weight:700;font-size:14px;margin-bottom:10px;color:var(--text-primary);">${escHtml(t('admin.templates.create.heading'))}</div>
      <fieldset class="admin-templates-create-fieldset" style="border:none;margin:0;padding:0;">
        <legend style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">${escHtml(t('admin.templates.create.typeLegend'))}</legend>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
          <label class="admin-templates-type-option" style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;">
            <input type="radio" name="admin-template-create-type" value="simple" checked style="accent-color:var(--accent);">
            <span>${escHtml(t('admin.templates.create.typeSimple'))}</span>
          </label>
          <label class="admin-templates-type-option" style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;">
            <input type="radio" name="admin-template-create-type" value="scenario" style="accent-color:var(--accent);">
            <span>${escHtml(t('admin.templates.create.typeScenario'))}</span>
          </label>
        </div>
      </fieldset>
      <button type="button" class="btn btn-primary btn-sm" data-action="admin-open-template-create">${escHtml(t('admin.templates.create.continue'))}</button>
    </div>`;
}

function renderTemplates() {
  const { state, t, escHtml } = getCtx();
  const templates = state.templates;
  const allPacks = state.scenarioPacksAdmin || state.scenarioPacks || [];
  const showScenarioForm = state.scenarioPackShowForm;
  const editing = state.scenarioPackEditing;

  if (!state.scenarioPacksAdmin) {
    requestAnimationFrame(() => {
      const v = window.DecisionArena?.store?.state?.view;
      if (v === 'templates' || v === 'scenario-packs') {
        window.DecisionArena.services?.ScenarioPackService?.list(true)
          .then((data) => {
            window.DecisionArena.store.state.scenarioPacksAdmin = Array.isArray(data) ? data : [];
            window.DecisionArena.render?.();
          }).catch(() => {});
      }
    });
  }

  const scenarioFormBlock = showScenarioForm ? renderScenarioPackForm(editing) : '';

  return `
    <div class="page-header" style="flex-direction:column;align-items:stretch;">
      <div>
        <div class="page-title">${t('admin.templates')}</div>
        <div class="page-subtitle">${t('admin.templatesDesc')}</div>
        <div class="card-description" style="margin-top:4px;">${t('admin.templates.page.desc')}</div>
        <button class="btn btn-secondary btn-sm" data-nav="administration" style="margin-top:8px;">${t('nav.backAdmin')}</button>
      </div>
    </div>

    ${renderTemplatesCreatePanel(t, escHtml)}

    ${scenarioFormBlock}

    <h3 class="admin-templates-section-title" style="font-size:15px;font-weight:700;margin:24px 0 10px;color:var(--text-primary);">${escHtml(t('admin.templates.section.simple'))}</h3>
    ${templates.length === 0 ? `<div class="empty-state"><div class="empty-state-icon">📋</div><div class="empty-state-text">${t('template.empty')}</div></div>` : `
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:8px;">${templates.map(renderTemplateAdminCard).join('')}</div>
    `}

    <h3 class="admin-templates-section-title" style="font-size:15px;font-weight:700;margin:28px 0 10px;color:var(--text-primary);">${escHtml(t('admin.templates.section.scenarioContext'))}</h3>
    <p class="card-description" style="margin:0 0 14px;font-size:12px;line-height:1.45;">${escHtml(t('admin.templates.scenarioLead'))}</p>
    ${allPacks.length === 0
      ? renderEmptyState({ icon: '📋', text: t('admin.templates.scenarioEmpty') })
      : `<div style="display:flex;flex-direction:column;gap:10px;">${allPacks.map(renderScenarioPackAdminCard).join('')}</div>`
    }
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

/** Types gérés par le bloc « provider serveur » / Provider local (même périmètre que l’ancien formulaire). */
const LOCAL_SERVER_PROVIDER_TYPES = new Set(['ollama', 'lmstudio', 'openai-compatible']);

function sortedLocalServerProviders(providerList) {
  return (providerList || [])
    .filter((p) => LOCAL_SERVER_PROVIDER_TYPES.has(p.type))
    .slice()
    .sort((a, b) => {
      const pa = Number(a.priority ?? 100);
      const pb = Number(b.priority ?? 100);
      if (pa !== pb) return pa - pb;
      return String(a.id).localeCompare(String(b.id));
    });
}

function renderProviderItem(provider) {
  const { escHtml, t } = getCtx();
  const isEnabled = provider.enabled == 1 || provider.enabled === true;
  const statusClass = isEnabled ? 'badge-success' : 'badge-danger';
  const statusLabel = isEnabled ? t('providers.statusActive') : t('providers.statusDisabled');
  const toggleLabel = isEnabled ? t('providers.disable') : t('providers.enable');
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
            <span class="badge ${statusClass}" style="margin-left:4px;">${escHtml(statusLabel)}</span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;">
          <button class="btn btn-secondary btn-sm" data-action="test-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.test')}</button>
          <button class="btn btn-secondary btn-sm" data-action="refresh-provider-models" data-provider-id="${escHtml(provider.id)}">${t('providers.refreshModels')}</button>
          <button class="btn btn-secondary btn-sm" data-action="edit-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.edit')}</button>
          <button class="btn btn-secondary btn-sm" data-action="toggle-local-provider-enabled" data-provider-id="${escHtml(provider.id)}">${escHtml(toggleLabel)}</button>
          <button class="btn btn-danger btn-sm" data-action="delete-provider" data-provider-id="${escHtml(provider.id)}">${t('providers.delete')}</button>
        </div>
      </div>
      ${!isEnabled ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('providers.disabledHint'))}</div>` : ''}
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
  const originalId = p.id ? String(p.id) : '';
  return `
    <form id="provider-form" class="provider-form-embed">
      <input type="hidden" id="pf-original-id" value="${escHtml(originalId)}">
      <div class="form-row">
        <div class="form-group"><label for="pf-id">${t('providers.fieldId')}</label><input class="input" id="pf-id" type="text" placeholder="local-mon-serveur (auto si vide)" value="${escHtml(p.id || '')}"></div>
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
      <div id="provider-form-result" style="margin-top:8px;"></div>
    </form>
  `;
}

const BYOK_PROVIDER_ROWS = [
  { id: 'openai', label: 'OpenAI' },
  { id: 'anthropic', label: 'Anthropic' },
  { id: 'mistral', label: 'Mistral AI' },
  { id: 'openrouter', label: 'OpenRouter' },
  { id: 'gemini', label: 'Google Gemini', quotaHint: true },
];

function renderProviderLocalQuickRow() {
  const { escHtml, state } = getCtx();
  const locals = sortedLocalServerProviders(state.providers || []);
  const activeLocals = locals.filter((p) => p.enabled !== false);
  const n = locals.length;
  const na = activeLocals.length;

  let mid = '';
  if (n === 0) mid = '0 configuré';
  else if (n === 1) mid = na === 1 ? '1 configuré · 1 actif' : '1 configuré · 0 actif';
  else mid = `${n} configurés / ${na} actif${na > 1 ? 's' : ''}`;

  const actionsHtml = `<button type="button"
          class="btn btn-primary btn-sm"
          data-action="open-local-provider-modal">Ajouter</button>`;

  return `
      <div class="byok-quick-row card"
           role="group"
           aria-label="Providers locaux"
           data-local-quick-provider="1">
        <div class="byok-quick-row-main">
          <span class="byok-quick-name">Providers locaux</span>
          <span class="byok-quick-status-cell"><span class="byok-quick-status byok-quick-status--muted">${escHtml(mid)}</span></span>
          <span class="byok-quick-actions">${actionsHtml}</span>
        </div>
        <div class="local-provider-quick-feedback provider-test-result byok-quick-feedback" hidden role="status" aria-live="polite"></div>
      </div>`;
}

/** Dialogue : même formulaire / ids (#pf-*) que l’ancien ajout permanent (persisté via API `/api/providers`). */
function renderProviderLocalModalShell() {
  const { escHtml } = getCtx();
  return `
<dialog id="provider-local-modal" class="provider-byok-modal provider-local-modal" aria-labelledby="provider-local-modal-title">
  <div class="provider-byok-modal-panel card">
    <div class="provider-byok-modal-head">
      <h3 id="provider-local-modal-title" class="provider-byok-modal-title">${escHtml('Configurer un provider local')}</h3>
      <button type="button" class="provider-byok-modal-close" data-action="close-local-provider-modal" aria-label="Fermer">×</button>
    </div>
    <p class="card-description provider-local-modal-lead">${escHtml(
      'Ollama, LM Studio ou compatible OpenAI. Les réglages sont enregistrés sur le serveur.',
    )}</p>
    <div id="provider-local-modal-form-host"></div>
    <div class="provider-local-modal-footer">
      <button type="button" class="btn btn-secondary btn-sm" data-action="close-local-provider-modal">${escHtml(
        'Annuler',
      )}</button>
      <button type="submit" form="provider-form" class="btn btn-primary btn-sm">${escHtml('Enregistrer')}</button>
    </div>
  </div>
</dialog>`;
}

/** Quick setup BYOK : liste compacte + dialogue Connecter / Modifier. */
function renderProviderQuickSetupSection() {
  const { escHtml, state, t } = getCtx();
  const ps =
    state.providerSettings && typeof state.providerSettings === 'object'
      ? state.providerSettings
      : {};

  const localRowHtml = renderProviderLocalQuickRow();

  const rows = BYOK_PROVIDER_ROWS.map(({ id, label, quotaHint }) => {
    const row = ps[id] || {};
    const rawKey = typeof row.apiKey === 'string' ? row.apiKey : '';
    const hasKey = rawKey.trim() !== '';
    const mask = hasKey ? maskProviderKey(rawKey) : '';

    const statusHtml = hasKey
      ? `<span class="byok-quick-status byok-quick-status--ok">Connecté ${escHtml(mask)}</span>`
      : `<span class="byok-quick-status byok-quick-status--off">Non connecté</span>`;

    const actionsHtml = hasKey
      ? `<button type="button"
            class="btn btn-secondary btn-sm"
            data-action="open-provider-modal"
            data-provider="${escHtml(id)}"
            data-modal-mode="edit">Modifier</button>`
      : `<button type="button"
            class="btn btn-primary btn-sm"
            data-action="open-provider-modal"
            data-provider="${escHtml(id)}"
            data-modal-mode="connect">Connecter</button>`;

    return `
      <div class="byok-quick-row card"
           role="group"
           aria-label="${escHtml(label)}"
           data-byok-provider="${escHtml(id)}">
        <div class="byok-quick-row-main">
          <span class="byok-quick-name">${escHtml(label)}</span>
          <span class="byok-quick-status-cell">${statusHtml}</span>
          <span class="byok-quick-actions">${actionsHtml}</span>
        </div>
        ${quotaHint ? `<p class="card-description byok-quota-hint" style="margin:6px 0 0;font-size:12px;">${escHtml(t('providers.geminiByokQuotaHint'))}</p>` : ''}
        <div class="byok-feedback provider-test-result byok-quick-feedback" hidden role="status" aria-live="polite"></div>
      </div>`;
  }).join('');

  return `
    <section class="section byok-quick-section" aria-labelledby="byok-quick-heading">
      <h2 id="byok-quick-heading" class="section-label" style="margin-bottom:8px;font-size:15px;font-weight:600;">
        Connecter votre fournisseur IA
      </h2>
      <p class="card-description" style="margin-bottom:14px;">
        ${escHtml(t('providers.byokSectionLead'))}
      </p>
      <div class="byok-quick-list">
        ${localRowHtml}
        ${rows}
      </div>
      ${renderProviderLocalModalShell()}
      ${renderByokProviderConnectModal()}
    </section>
  `;
}

function renderProviderRoutingSection() {
  const { state, escHtml, t } = getCtx();
  const routingCandidates = getAvailableProviders(state);
  const allProviders = Array.isArray(state.providers) ? state.providers : [];
  const disabledProviderIds = new Set(
    allProviders
      .filter((p) => !(p.enabled == 1 || p.enabled === true))
      .map((p) => String(p.id)),
  );
  const emptyHint =
    routingCandidates.length === 0
      ? `<div class="provider-test-result fail" style="margin-bottom:12px;">Aucun fournisseur LLM valide pour le routage (serveur local avec URL, ou cloud avec clé API, priorité entre 0 et 1000000, fournisseur activé).</div>`
      : '';
  const s = state.providerRoutingSettings || {
    routing_mode: 'single-primary',
    primary_provider_id: '',
    preferred_provider_id: '',
    fallback_provider_ids: [],
    load_balance_strategy: 'round-robin',
  };

  const mode = s.routing_mode || 'single-primary';
  const fallback = Array.isArray(s.fallback_provider_ids) ? s.fallback_provider_ids : [];
  const disabledConfiguredIds = []
    .concat(
      s.primary_provider_id ? [String(s.primary_provider_id)] : [],
      s.preferred_provider_id ? [String(s.preferred_provider_id)] : [],
      fallback.map((id) => String(id)),
    )
    .filter((id, idx, arr) => id && arr.indexOf(id) === idx && disabledProviderIds.has(id));
  const disabledConfigHint = disabledConfiguredIds.length > 0
    ? `<div class="provider-test-result fail" style="margin-bottom:12px;">
        ${escHtml(t('providers.routing.disabledConfiguredWarning'))}
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${disabledConfiguredIds.map((id) => escHtml(id)).join(', ')}</div>
      </div>`
    : '';

  const providerOptionsFor = (selectedId) =>
    routingCandidates
      .map((p) => {
        const sel = selectedId && selectedId === p.id ? 'selected' : '';
        return `<option value="${escHtml(p.id)}" ${sel}>${escHtml(formatRoutingOptionLabel(p))}</option>`;
      })
      .join('');

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
    <div class="section providers-routing-section admin-provider-routing">
      <div class="section-label" style="margin-bottom:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">${t('providers.routing.title')} ${renderTooltip(t('tooltip.adminRouting'))}</div>
      <div class="card-description" style="margin-bottom:10px;">${t('admin.routing.desc')}</div>
      <div class="card admin-provider-routing-card">
        ${emptyHint}
        ${disabledConfigHint}
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
              ${routingCandidates.map((p) => {
                const checked = fallback.includes(p.id) ? 'checked' : '';
                return `
                  <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" class="pr-fallback" data-provider-id="${escHtml(p.id)}" ${checked} style="width:15px;height:15px;accent-color:var(--accent);">
                    <span>${escHtml(formatRoutingOptionLabel(p))} <span style="color:var(--text-muted);font-size:12px;">(${escHtml(p.id)})</span></span>
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
  const providers = state.providers || [];
  const localsOnly = sortedLocalServerProviders(providers);
  return `
    <div class="page-header">
      <div class="page-title">${t('providers.title')}</div>
      <div class="page-subtitle">${t('providers.subtitle')}</div>
      <div class="card-description" style="margin-top:6px;">${t('admin.providers.page.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>
    ${renderProviderQuickSetupSection()}
    <div data-ui="expert-only" class="providers-expert-servers">
      <div class="section" style="margin-top:28px;">
        <div class="section-label" style="margin-bottom:12px;">Serveurs techniques</div>
        <p class="card-description" style="margin-bottom:12px;">
          Liste des providers locaux enregistrés sur le serveur. Utilisez <strong>Ajouter</strong> dans la section Quick setup ci-dessus pour en créer un nouveau, ou les actions ci-dessous pour tester, rafraîchir les modèles, modifier ou supprimer chaque entrée.
        </p>
      </div>
      ${localsOnly.length === 0 ? `<div class="empty-state"><div class="empty-state-icon">⚙️</div><div class="empty-state-text">${t('providers.empty')}</div></div>` : localsOnly.map(renderProviderItem).join('')}
    </div>
    <div data-ui="expert-only" class="providers-expert-routing">
      ${renderProviderRoutingSection()}
    </div>
  `;
}

function renderMemories() {
  const { state, escHtml, t } = getCtx();
  const formatDate = window.DecisionArena?.utils?.formatDate || ((x) => String(x || ''));
  const pkg = state.decisionMemory || { loading: false, error: null, memories: null };
  const list = Array.isArray(pkg.memories)
    ? pkg.memories.slice().sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')))
    : [];
  const selectedIds = Array.isArray(state.selectedMemoryIds) ? state.selectedMemoryIds.map(String) : [];
  const selectedSet = new Set(selectedIds);
  const sessionsById = new Map((Array.isArray(state.sessions) ? state.sessions : []).map((s) => [String(s?.id || ''), s]));

  const badgeStatus = (status) => {
    const s = String(status || '');
    if (s === 'proceed') return 'badge-success';
    if (s === 'proceed_with_constraints' || s === 'validate_first' || s === 'pivot') return 'badge-warning';
    if (s === 'kill') return 'badge-danger';
    return 'badge-muted';
  };
  const badgeConfidence = (confidence) => {
    const c = String(confidence || '').toLowerCase();
    if (c === 'strong') return 'badge-success';
    if (c === 'moderate') return 'badge-warning';
    if (c === 'weak') return 'badge-danger';
    return 'badge-muted';
  };
  const badgeLifecycle = (memoryState) => {
    const s = String(memoryState || 'active').toLowerCase();
    if (s === 'active') return 'badge-success';
    if (s === 'superseded' || s === 'stale') return 'badge-warning';
    if (s === 'invalidated') return 'badge-danger';
    if (s === 'archived') return 'badge-muted';
    return 'badge-default';
  };
  const parseJson = (raw) => {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    if (typeof raw !== 'string') return null;
    try {
      return JSON.parse(raw);
    } catch (_) {
      return null;
    }
  };
  const parseSessionResultPayload = (resultRaw) => {
    if (!resultRaw) return null;
    if (typeof resultRaw === 'object') return resultRaw;
    if (typeof resultRaw !== 'string') return null;
    try {
      return JSON.parse(resultRaw);
    } catch (_) {
      return null;
    }
  };
  const extractOutcomeForMemory = (memory) => {
    const sid = String(memory?.session_id || '');
    const session = sessionsById.get(sid);
    if (!session) return null;
    if (session.decision_outcome && typeof session.decision_outcome === 'object') return session.decision_outcome;
    const parsed = parseJson(session.result);
    if (!parsed || typeof parsed !== 'object') return null;
    if (parsed.decision_outcome && typeof parsed.decision_outcome === 'object') return parsed.decision_outcome;
    if (parsed.decision_brief?.decision_outcome && typeof parsed.decision_brief.decision_outcome === 'object') return parsed.decision_brief.decision_outcome;
    return null;
  };
  const renderOutcomeMini = (outcome) => {
    if (!outcome || typeof outcome !== 'object') {
      return `<div style="margin-top:6px;font-size:11px;color:var(--text-muted);">—</div>`;
    }
    const status = String(outcome.status || '').trim().toLowerCase() || 'validate_first';
    const confidence = String(outcome.confidence || '').trim().toLowerCase() || 'weak';
    const risk = String(outcome.execution_risk_level || '').trim().toLowerCase() || 'unknown';
    const summary = String(outcome.decision_summary || '').trim() || 'Outcome incomplet';
    const nextActions = Array.isArray(outcome.required_next_actions) ? outcome.required_next_actions.filter(Boolean).slice(0, 2) : [];
    const unknowns = Array.isArray(outcome.blocking_unknowns) ? outcome.blocking_unknowns.filter(Boolean).slice(0, 2) : [];
    return `
      <div style="margin-top:8px;padding:8px 10px;border:1px solid rgba(16,185,129,0.18);border-radius:8px;background:rgba(16,185,129,0.05);font-size:12px;line-height:1.4;">
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">
          <span class="badge ${badgeStatus(status)}">${escHtml(status.replace(/_/g, ' '))}</span>
          <span class="badge ${badgeConfidence(confidence)}">${escHtml(confidence)}</span>
          <span class="badge badge-warning">${escHtml(`risk: ${risk}`)}</span>
        </div>
        <div style="font-size:12px;color:var(--text-primary);margin-bottom:6px;">${escHtml(summary)}</div>
        ${nextActions.length ? `<div style="font-size:11px;color:var(--text-secondary);"><strong>Next:</strong> ${escHtml(nextActions.join(' | '))}</div>` : ''}
        ${unknowns.length ? `<div style="font-size:11px;color:var(--text-secondary);margin-top:4px;"><strong>Unknowns:</strong> ${escHtml(unknowns.join(' | '))}</div>` : ''}
      </div>
    `;
  };
  const contextByMemoryId = (() => {
    const out = new Map();
    const contexts = Array.isArray(state.decisionMemoryNav?.contexts) ? state.decisionMemoryNav.contexts : [];
    contexts.forEach((ctx) => {
      const label = String(ctx?.title || ctx?.context_id || '').trim();
      if (!label) return;
      const ids = Array.isArray(ctx?.linked_memory_ids) ? ctx.linked_memory_ids : [];
      ids.forEach((id) => {
        const key = String(id || '').trim();
        if (!key) return;
        if (!out.has(key)) out.set(key, []);
        out.get(key).push(label);
      });
    });
    return out;
  })();
  const contextTitleById = (() => {
    const out = new Map();
    const contexts = Array.isArray(state.decisionMemoryNav?.contexts) ? state.decisionMemoryNav.contexts : [];
    contexts.forEach((ctx) => {
      const id = String(ctx?.context_id || '').trim();
      const title = String(ctx?.title || id || '').trim();
      if (id && title) out.set(id, title);
    });
    return out;
  })();

  const pastAnalysisCards = (() => {
    const sessions = Array.isArray(state.sessions) ? state.sessions : [];
    const sorted = sessions.slice().sort((a, b) => String(b?.created_at || '').localeCompare(String(a?.created_at || '')));
    const cards = [];
    for (const session of sorted.slice(0, 80)) {
      const payload = parseSessionResultPayload(session?.result);
      const outcome = session?.decision_outcome || payload?.decision_outcome || payload?.decision_brief?.decision_outcome || null;
      const brief = payload?.decision_brief || null;
      if (!outcome && !brief) continue;
      const sid = String(session?.id || '').trim();
      const mem = list.find((x) => String(x?.session_id || '').trim() === sid) || null;
      const linkedCtx = String(session?.strategic_context_id || '').trim();
      const linkedCtxTitle = linkedCtx ? (contextTitleById.get(linkedCtx) || linkedCtx) : '';
      cards.push(`
        <article class="card" style="padding:14px 16px;margin-bottom:12px;">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
            <span class="badge badge-info">${escHtml(String(session?.mode || 'session'))}</span>
            ${mem ? `<span class="badge badge-success">${escHtml(t('admin.memories.linkedMemory'))}</span>` : `<span class="badge badge-muted">${escHtml(t('admin.memories.unlinkedMemory'))}</span>`}
            ${linkedCtxTitle ? `<span class="badge badge-muted">${escHtml(linkedCtxTitle)}</span>` : ''}
            <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(session?.created_at))}</span>
          </div>
          ${outcome ? renderDecisionOutcomeCard(outcome, { uiMode: 'basic', sessionId: sid }) : ''}
          ${brief ? `<div style="margin-top:10px;">${renderDecisionBrief(brief, { uiMode: 'basic' })}</div>` : ''}
          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" data-action="open-session" data-session-id="${escHtml(sid)}" data-mode="${escHtml(String(session?.mode || 'chat'))}">
              ${escHtml(t('decisionMemory.openSession'))}
            </button>
            ${mem ? `<button class="btn btn-danger btn-sm" data-action="request-delete-decision-memory" data-memory-id="${escHtml(String(mem.memory_id || ''))}">🗑️ ${escHtml(t('decisionMemory.deleteOne'))}</button>` : ''}
          </div>
        </article>
      `);
      if (cards.length >= 20) break;
    }
    return cards;
  })();

  const rows = list.slice(0, 200).map((m) => {
    const id = String(m.memory_id || '');
    const lifeState = String(m?.decay?.memory_state || m?.memory_state || 'active');
    const outcome = extractOutcomeForMemory(m);
    const linkedContexts = contextByMemoryId.get(id) || [];
    const extraContexts = Math.max(0, linkedContexts.length - 2);
    const contextCell = linkedContexts.length
      ? `
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
          ${linkedContexts.slice(0, 2).map((label) => `<span class="badge badge-muted">${escHtml(label)}</span>`).join('')}
          ${extraContexts > 0 ? `<span class="badge badge-info">+${extraContexts}</span>` : ''}
        </div>
      `
      : `<span style="font-size:12px;color:var(--text-muted);">—</span>`;
    return `
      <tr>
        <td>
          <input
            type="checkbox"
            data-action="toggle-memory-selection"
            data-memory-id="${escHtml(id)}"
            ${selectedSet.has(id) ? 'checked' : ''}
            style="accent-color:var(--accent);"
          >
        </td>
        <td style="white-space:nowrap;font-size:11px;color:var(--text-muted);"><code>${escHtml(id.slice(0, 10))}</code></td>
        <td style="font-size:11px;color:var(--text-muted);">${escHtml(formatDate(m.created_at))}</td>
        <td><span class="badge badge-info">${escHtml(String(m.playbook_id || '—'))}</span></td>
        <td><span class="badge ${badgeStatus(m.decision_status)}">${escHtml(String(m.decision_status || '—'))}</span></td>
        <td><span class="badge ${badgeConfidence(m.confidence)}">${escHtml(String(m.confidence || '—'))}</span></td>
        <td><span class="badge ${badgeLifecycle(lifeState)}">${escHtml(lifeState || 'active')}</span></td>
        <td>${contextCell}</td>
        <td style="max-width:360px;">
          <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(String(m.decision_summary || '—'))}</div>
          ${renderOutcomeMini(outcome)}
        </td>
        <td style="min-width:280px;">
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-start;">
            <button class="btn btn-secondary btn-sm" data-action="open-session" data-session-id="${escHtml(String(m.session_id || ''))}" data-mode="session-history">${escHtml(t('decisionMemory.openSession'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(id)}" data-lifecycle-action="review">${escHtml(t('memoryLifecycle.review'))}</button>
            ${lifeState === 'archived'
              ? `<button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(id)}" data-lifecycle-action="restore">${escHtml(t('memoryLifecycle.restore'))}</button>`
              : `<button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(id)}" data-lifecycle-action="archive">${escHtml(t('memoryLifecycle.archive'))}</button>`
            }
            <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(id)}" data-lifecycle-action="supersede">${escHtml(t('memoryLifecycle.supersede'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(id)}" data-lifecycle-action="invalidate">${escHtml(t('memoryLifecycle.invalidate'))}</button>
            <button class="btn btn-danger btn-sm" data-action="request-delete-decision-memory" data-memory-id="${escHtml(id)}">🗑️</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');

  return `
    <div class="page-header">
      <div class="page-title">${escHtml(t('admin.memories'))}</div>
      <div class="page-subtitle">${escHtml(t('admin.memories.subtitle'))}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>

    <div class="card" style="padding:14px 16px;margin-bottom:14px;">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-primary btn-sm" data-action="load-admin-memories-data">${escHtml(t('admin.memories.refresh'))}</button>
        <button class="btn btn-secondary btn-sm" data-nav="decision-memory">${escHtml(t('admin.memories.openWorkspace'))}</button>
        <button class="btn btn-secondary btn-sm" data-action="select-all-visible-memories">${escHtml(t('decisionMemory.selectAllVisible'))}</button>
        <button class="btn btn-secondary btn-sm" data-action="clear-selected-memories" ${selectedIds.length ? '' : 'disabled'}>${escHtml(t('decisionMemory.clearSelection'))}</button>
        <button class="btn btn-danger btn-sm" data-action="request-delete-selected-memories" ${selectedIds.length ? '' : 'disabled'}>
          🗑️ ${escHtml(t('decisionMemory.deleteSelected'))}
        </button>
        <span style="font-size:12px;color:var(--text-muted);">
          ${pkg.loading ? `<span class="spinner"></span> ${escHtml(t('loading'))}` : `${list.length} ${escHtml(t('admin.memories.countLabel'))}`}
        </span>
        ${selectedIds.length ? `<span class="badge badge-info">${escHtml(t('decisionMemory.selected'))}: ${selectedIds.length}</span>` : ''}
      </div>
      ${pkg.error ? `<div class="error-banner" style="margin-top:10px;">⚠️ ${escHtml(pkg.error)}</div>` : ''}
    </div>

    <div class="card" style="padding:14px 16px;margin-bottom:14px;">
      <div style="font-weight:700;font-size:14px;margin-bottom:8px;">${escHtml(t('admin.memories.pastAnalyses'))}</div>
      ${pastAnalysisCards.length
        ? pastAnalysisCards.join('')
        : `<div class="empty-state"><div class="empty-state-icon">🧠</div><div class="empty-state-text">${escHtml(t('admin.memories.noPastAnalyses'))}</div></div>`
      }
    </div>

    ${!Array.isArray(pkg.memories) ? `
      <div class="empty-state">
        <div class="empty-state-icon">🧠</div>
        <div class="empty-state-text">${escHtml(t('admin.memories.emptyUnloaded'))}</div>
      </div>
    ` : list.length === 0 ? `
      <div class="empty-state">
        <div class="empty-state-icon">🧠</div>
        <div class="empty-state-text">${escHtml(t('admin.memories.empty'))}</div>
      </div>
    ` : `
      <div class="card" style="padding:0;">
        <div class="data-table-wrap" style="overflow-x:auto;overflow-y:hidden;">
        <table class="data-table" style="min-width:1560px;">
          <thead>
            <tr>
              <th style="width:44px;">
                <button class="btn btn-secondary btn-sm" data-action="select-all-visible-memories" title="${escHtml(t('decisionMemory.selectAllVisible'))}">✓</button>
              </th>
              <th>ID</th>
              <th>${escHtml(t('logs.col.time'))}</th>
              <th>Playbook</th>
              <th>${escHtml(t('decisionMemory.filters.status'))}</th>
              <th>${escHtml(t('decisionMemory.filters.confidence'))}</th>
              <th>Lifecycle</th>
              <th>${escHtml(t('memoryReuse.contextLabel'))}</th>
              <th>${escHtml(t('memoryReuse.decisionLabel'))}</th>
              <th>${escHtml(t('admin.memories.actions'))}</th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>
        </table>
        </div>
      </div>
    `}
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
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>${t('logs.col.time')}</th>
                <th>${t('logs.col.level')}</th>
                <th>${t('logs.col.category')}</th>
                <th>${t('logs.col.session')}</th>
                <th>${t('logs.col.agent')}</th>
                <th>${t('logs.col.provider')}</th>
                <th>${t('logs.col.action')}</th>
                <th>${t('logs.col.message')}</th>
              </tr>
            </thead>
            <tbody>
              ${items.length === 0 ? `
                <tr><td colspan="8" style="padding:14px 10px;color:var(--text-muted);">${t('logs.empty')}</td></tr>
              ` : items.map((r) => {
                const lvl = r.level || 'info';
                const cat = r.category || '';
                const msg = r.error_message || r.action || '';
                const rowStyle = (logsState.selectedId === r.id) ? ' style="background:rgba(99,102,241,0.10);"' : '';
                return `
                  <tr data-action="logs-open" data-log-id="${escHtml(r.id)}"${rowStyle}>
                    <td style="white-space:nowrap;">${escHtml((r.created_at||'').replace('T',' ').replace('Z',''))}</td>
                    <td>${badge(lvl, lvl)}</td>
                    <td>${escHtml(cat)}</td>
                    <td>${escHtml(r.session_id || '')}</td>
                    <td>${escHtml(r.agent_id || '')}</td>
                    <td>${escHtml(r.provider_id ? `${r.provider_id}${r.model ? ' / ' + r.model : ''}` : '')}</td>
                    <td>${escHtml(r.action || '')}</td>
                    <td style="color:var(--text-muted);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(msg)}</td>
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
            <pre class="code-preview">${escHtml(selected.request_payload || '')}</pre>
          </details>
          <details style="margin-top:10px;">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">${t('logs.responsePayload')}</summary>
            <pre class="code-preview">${escHtml(selected.response_payload || '')}</pre>
          </details>
          <details style="margin-top:10px;">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">${t('logs.metadata')}</summary>
            <pre class="code-preview">${escHtml(selected.metadata || '')}</pre>
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
        ${p.id ? t('scenario.admin.edit') : t('admin.templates.scenarioFormNew')}
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

/** Même contenu que `templates` (les vues `scenario-packs` restent enregistrées pour compatibilité). */
function renderScenarioPacks() {
  return renderTemplates();
}

/* ═══════════════════════════════════════════════════════════════════════
   Feature 5 — Retrospective (Post-mortem Stats)
════════════════════════════════════════════════════════════════════════ */

function renderRetrospective() {
  const { state, t, escHtml } = getCtx();
  const stats = state.postmortemStats;
  const loading = !!state.postmortemStatsLoading;
  const awaiting = !!state.postmortemStatsAwaiting;
  const loadErr = state.postmortemStatsError;
  const fullscreenSpinner = loading && !stats;
  const btnBusy = awaiting || loading;

  const loadBtn = `<button type="button" class="btn btn-secondary btn-sm" data-action="load-postmortem-stats" ${btnBusy ? 'disabled' : ''}>📊 ${btnBusy ? '…' : t('postmortem.stats.load')}</button>`;

  const errBlock = loadErr
    ? `<div class="error-banner" style="margin-bottom:12px;">⚠️ ${escHtml(t('postmortem.stats.loadError'))} <span style="opacity:0.85;font-size:12px;">${escHtml(loadErr)}</span></div>`
    : '';

  // Aucune requête en cours : état initial (bouton uniquement)
  if (!stats && !loadErr && !awaiting && !loading) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">${loadBtn}</div>`;
  }

  // Phase calme (~220 ms max) : pas de spinner animé (évite le clignotement sur réponse ultra-rapide)
  if (!stats && !loadErr && awaiting && !loading) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        ${errBlock}
        <div style="color:var(--text-muted);font-size:14px;line-height:1.45;max-width:400px;margin:0 auto;">${escHtml(t('postmortem.stats.loadingSoft'))}</div>
        <div style="margin-top:14px;">${loadBtn}</div>
      </div>`;
  }

  // Requête lente sans données encore : spinner + durée minimale gérée par le handler
  if (fullscreenSpinner) {
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

  const refreshingHint =
    awaiting && !loading ? `<div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">${escHtml(t('postmortem.stats.refreshing'))}</div>` : '';

  // Bilan présent mais 0 entrée API (après succès uniquement ou actualisation sans spinner plein écran)
  if (stats && total === 0 && !loadErr && !fullscreenSpinner) {
    return `
      <div class="page-header">
        <div class="page-title">🔮 ${t('postmortem.stats.title')}</div>
      </div>
      <div class="card" style="padding:20px;">
        ${errBlock}
        ${refreshingHint}
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
      <div class="stats-section-title">📊 ${t('postmortem.stats.by_mode')}</div>
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
      <div class="stats-section-title">🎭 ${t('postmortem.stats.by_agent')}</div>
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
      ${refreshingHint}
      ${globalHtml}
      ${byModeHtml}
      ${byAgentHtml}
      <div style="margin-top:12px;">${loadBtn}</div>
    </div>`;
}

/* ── Learning Layer (admin) ── */

function renderLearning() {
  const { state, escHtml, t } = getCtx();
  const data    = state.learningReport;
  const loading = !!state.learningLoading;
  const loadErr = state.learningError || null;

  const recomputeBtn = `<button type="button" class="btn btn-secondary btn-sm" data-action="recompute-learning" ${loading ? 'disabled' : ''}>🔄 ${loading ? '…' : t('learning.recompute')}</button>`;
  const loadBtn = `<button type="button" class="btn btn-primary btn-sm" data-action="load-learning" ${loading ? 'disabled' : ''}>📊 ${loading ? '…' : t('learning.load')}</button>`;

  const errBlock = loadErr
    ? `<div class="error-banner" style="margin-bottom:12px;">⚠️ ${escHtml(t('learning.loadError'))} <span style="opacity:0.85;font-size:12px;">${escHtml(loadErr)}</span></div>`
    : '';

  if (!data && !loadErr && !loading) {
    return `
      <div class="page-header">
        <div class="page-title">🧬 ${t('admin.learning.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">${loadBtn}</div>`;
  }

  if (loading && !data) {
    return `
      <div class="page-header">
        <div class="page-title">🧬 ${t('admin.learning.title')}</div>
      </div>
      <div class="card" style="padding:20px;text-align:center;">
        ${errBlock}
        <div style="margin-bottom:12px;"><span class="spinner"></span> ${t('learning.loading')}</div>
        ${loadBtn}
      </div>`;
  }

  if (!data || loadErr) {
    return `
      <div class="page-header"><div class="page-title">🧬 ${t('admin.learning.title')}</div></div>
      <div class="card" style="padding:20px;">${errBlock}${loadBtn}</div>`;
  }

  const total = data.postmortems_count ?? 0;
  const minRequired = data.overview?.min_required ?? 5;
  const sufficient  = !!data.sufficient_data;

  const insufficientBanner = !sufficient
    ? `<div class="card" style="padding:16px 20px;margin-bottom:16px;border:1px solid #f59e0b40;background:#f59e0b08;color:var(--text-secondary);font-size:13px;">
        ⚠️ ${escHtml(t('learning.insufficientData').replace('{n}', String(minRequired)))}
        <span style="font-size:11px;color:var(--text-muted);margin-left:8px;">(${total} ${t('learning.postmortemsFound')})</span>
       </div>`
    : '';

  // ── Overview cards ─────────────────────────────────────────────────────────
  const ov = data.overview || {};
  const pct = (r) => Math.round((r || 0) * 100);

  const overviewHtml = `
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px;">
      <div class="card" style="padding:14px 18px;min-width:110px;text-align:center;">
        <div style="font-size:24px;font-weight:700;">${ov.total_postmortems ?? 0}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('learning.postmortems')}</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:110px;text-align:center;border:1px solid #22c55e30;">
        <div style="font-size:24px;font-weight:700;color:#22c55e;">${pct(ov.correct_rate)}%</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('learning.correctRate')}</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:110px;text-align:center;border:1px solid #ef444430;">
        <div style="font-size:24px;font-weight:700;color:#ef4444;">${pct(ov.incorrect_rate)}%</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('learning.incorrectRate')}</div>
      </div>
      <div class="card" style="padding:14px 18px;min-width:110px;text-align:center;">
        <div style="font-size:14px;font-weight:600;color:var(--text-secondary);">${escHtml(ov.data_confidence ?? 'none')}</div>
        <div style="font-size:12px;color:var(--text-muted);">${t('learning.dataConfidence')}</div>
      </div>
    </div>`;

  // ── Mode performance ───────────────────────────────────────────────────────
  const modes = Array.isArray(data.mode_performance) ? data.mode_performance : [];
  const modeHtml = modes.length === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div class="stats-section-title">🗂️ ${t('learning.modePerformance')}</div>
      ${modes.map((m) => {
        const correct   = m.insufficient_data ? 'N/A' : `${pct(m.correct_rate)}%`;
        const incorrect = m.insufficient_data ? 'N/A' : `${pct(m.incorrect_rate)}%`;
        const barWidth  = m.insufficient_data ? 0 : Math.round((m.correct_rate || 0) * 100);
        const badgeColor = m.insufficient_data ? '#9ca3af'
          : m.correct_rate >= 0.7 ? '#22c55e'
          : m.correct_rate >= 0.5 ? '#f59e0b' : '#ef4444';
        return `
          <div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
              <span style="font-weight:600;font-size:12px;">${escHtml(m.mode_label || m.mode)}</span>
              <span style="font-size:11px;color:var(--text-muted);">(${m.sessions_count} ${t('learning.sessions')})</span>
              ${m.insufficient_data ? `<span style="font-size:10px;color:#9ca3af;font-style:italic;">${t('learning.lowData')}</span>` : ''}
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
              <div style="flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
                <div style="height:100%;width:${barWidth}%;background:${badgeColor};border-radius:4px;"></div>
              </div>
              <span style="font-size:12px;color:var(--text-muted);min-width:80px;">${correct} ${t('learning.correct')}</span>
            </div>
            ${m.recommendation ? `<div style="font-size:11px;color:var(--text-secondary);font-style:italic;">${escHtml(m.recommendation)}</div>` : ''}
          </div>`;
      }).join('')}
    </div>`;

  // ── Agent performance ──────────────────────────────────────────────────────
  const agents = Array.isArray(data.agent_performance) ? data.agent_performance : [];
  const agentHtml = agents.length === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div class="stats-section-title">🎭 ${t('learning.agentPerformance')}</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
        ${agents.map((a) => {
          const warnColor = a.calibration_warning === 'overconfident_when_wrong' ? '#ef4444'
            : a.calibration_warning === 'high_incorrect_rate' ? '#f59e0b' : 'transparent';
          const border = a.calibration_warning ? `border:1px solid ${warnColor}40` : '';
          return `
            <div style="padding:10px 14px;background:var(--bg-secondary);border-radius:8px;min-width:140px;${border}">
              <div style="font-weight:600;font-size:12px;margin-bottom:4px;">${escHtml(a.agent_id)}</div>
              ${a.insufficient_data
                ? `<div style="font-size:11px;color:#9ca3af;font-style:italic;">${t('learning.insufficientDataShort')}</div>`
                : `<div style="font-size:11px;color:var(--text-muted);">${t('learning.correct')}: <strong>${pct(a.correct_rate)}%</strong></div>
                   <div style="font-size:11px;color:var(--text-muted);">${t('learning.incorrect')}: <strong style="color:#ef4444;">${pct(a.incorrect_rate)}%</strong></div>`
              }
              ${a.calibration_warning ? `<div style="font-size:10px;color:${warnColor};margin-top:4px;">⚠ ${escHtml(a.calibration_warning)}</div>` : ''}
            </div>`;
        }).join('')}
      </div>
    </div>`;

  // ── Calibration ────────────────────────────────────────────────────────────
  const cal = data.calibration || {};
  const calHtml = (cal.total_sessions_analyzed || 0) === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div class="stats-section-title">🎯 ${t('learning.calibration')}</div>
      <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
        <div style="min-width:140px;">
          <div style="font-size:20px;font-weight:700;color:${(cal.overconfidence_rate||0)>0.2?'#ef4444':'#22c55e'};">${pct(cal.overconfidence_rate)}%</div>
          <div style="font-size:11px;color:var(--text-muted);">${t('learning.overconfidenceRate')}</div>
        </div>
        <div style="min-width:140px;">
          <div style="font-size:20px;font-weight:700;color:${(cal.go_failure_rate||0)>0.25?'#ef4444':'#22c55e'};">${pct(cal.go_failure_rate)}%</div>
          <div style="font-size:11px;color:var(--text-muted);">${t('learning.goFailureRate')}</div>
        </div>
        <div style="min-width:140px;">
          <div style="font-size:20px;font-weight:700;">${cal.weak_context_success_rate!=null?pct(cal.weak_context_success_rate)+'%':'N/A'}</div>
          <div style="font-size:11px;color:var(--text-muted);">${t('learning.weakCtxSuccessRate')}</div>
        </div>
        <div style="min-width:140px;">
          <div style="font-size:20px;font-weight:700;color:${(cal.false_consensus_failure_rate||0)>0.35?'#ef4444':'#22c55e'};">${pct(cal.false_consensus_failure_rate)}%</div>
          <div style="font-size:11px;color:var(--text-muted);">${t('learning.falseConsensusFailureRate')}</div>
        </div>
      </div>
      ${(cal.recommendations||[]).length>0
        ? `<div style="font-size:12px;color:var(--text-secondary);">${cal.recommendations.map((r)=>`<div style="margin-bottom:6px;">• ${escHtml(r)}</div>`).join('')}</div>`
        : ''}
    </div>`;

  // ── Insights ───────────────────────────────────────────────────────────────
  const insights = Array.isArray(data.insights) ? data.insights : [];
  const insightsHtml = insights.length === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div style="font-weight:600;font-size:13px;margin-bottom:12px;">💡 ${t('learning.insights')}</div>
      ${insights.map((ins) => {
        const ic = ins.level === 'warning' ? '#f59e0b' : '#3b82f6';
        return `<div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;">
          <span style="color:${ic};font-size:14px;">${ins.level==='warning'?'⚠️':'ℹ️'}</span>
          <span style="font-size:12px;color:var(--text-secondary);">${escHtml(ins.message)}</span>
        </div>`;
      }).join('')}
    </div>`;

  // ── Recommendations ────────────────────────────────────────────────────────
  const recs = Array.isArray(data.recommendations) ? data.recommendations : [];
  const recsHtml = recs.length === 0 ? '' : `
    <div class="card" style="padding:18px;margin-bottom:16px;">
      <div style="font-weight:600;font-size:13px;margin-bottom:12px;">📋 ${t('learning.recommendations')}</div>
      ${recs.map((r) => `<div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px;">• ${escHtml(r)}</div>`).join('')}
    </div>`;

  return `
    <div class="page-header">
      <div class="page-title">🧬 ${t('admin.learning.title')}</div>
    </div>
    <div style="max-width:860px;">
      ${insufficientBanner}
      ${overviewHtml}
      ${modeHtml}
      ${agentHtml}
      ${calHtml}
      ${insightsHtml}
      ${recsHtml}
      <div style="margin-top:12px;display:flex;gap:10px;">
        ${loadBtn}
        ${recomputeBtn}
        <button class="btn btn-secondary btn-sm" data-action="export-learning" data-format="markdown">📥 ${t('learning.export')}</button>
        <button class="btn btn-secondary btn-sm" data-action="export-learning" data-format="json">📥 JSON</button>
        ${state.learningExportStatus === 'ok' ? `<span style="color:var(--success);font-size:12px;">✓ ${t('learning.exportDone')}</span>` : ''}
        ${state.learningExportStatus === 'error' ? `<span style="color:var(--danger);font-size:12px;">⚠ ${t('learning.exportError')}</span>` : ''}
      </div>
    </div>`;
}

/* ── Prompt Policies (admin) ── */

function renderPromptPolicies() {
  const { state, escHtml, t } = getCtx();
  const ps = state.promptPolicies || {};
  const items  = Array.isArray(ps.items)  ? ps.items  : [];
  const active = ps.activeId || null;
  const draft  = ps.draft   ?? null;          // edited but not yet saved
  const saved  = ps.savedId || null;
  const saving = !!ps.saving;
  const loadingId = ps.loadingId || null;
  const error  = ps.error  || null;

  const statusHtml = (() => {
    if (error)  return `<span class="admin-policy-status admin-policy-status--error">⚠ ${escHtml(error)}</span>`;
    if (saving) return `<span class="admin-policy-status admin-policy-status--saving"><span class="spinner"></span> ${t('admin.promptPolicies.saving')}</span>`;
    if (saved === active && !draft && saved)
      return `<span class="admin-policy-status admin-policy-status--saved">✓ ${t('admin.promptPolicies.saved')}</span>`;
    if (draft !== null)
      return `<span class="admin-policy-status admin-policy-status--unsaved">● ${t('admin.promptPolicies.unsaved')}</span>`;
    return '';
  })();

  const textareaContent = draft !== null ? draft : (ps.content || '');

  return `
    <div class="page-header">
      <div class="page-title">📝 ${t('admin.promptPolicies.title')}</div>
      <div class="page-subtitle">${t('admin.promptPolicies.desc')}</div>
      <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-nav="administration">${t('nav.backAdmin')}</button>
    </div>

    <div class="admin-policy-layout">
      <!-- List -->
      <div class="admin-policy-list" role="list">
        <div class="admin-policy-list-title">${escHtml(t('admin.promptPolicies.listTitle'))}</div>
        ${items.length === 0
          ? `<div style="padding:12px;font-size:13px;color:var(--text-muted);">${t('loading')}</div>`
          : items.map((item) => `
            <div class="admin-policy-item${active === item.id ? ' admin-policy-item-active' : ''}"
                 data-action="policy-select"
                 data-policy-id="${escHtml(item.id)}"
                 role="listitem"
                 tabindex="0"
                 aria-current="${active === item.id ? 'true' : 'false'}">
              <div class="admin-policy-item-title">${escHtml(t('admin.promptPolicies.' + item.id.replace(/-([a-z])/g, (_, c) => c.toUpperCase())) || item.title)}</div>
              <div class="admin-policy-item-filename">${escHtml(item.filename)}</div>
            </div>
          `).join('')
        }
      </div>

      <!-- Editor -->
      <div class="admin-policy-editor">
        ${!active ? `
          <div style="padding:32px;color:var(--text-muted);font-size:14px;">${t('admin.promptPolicies.selectHint')}</div>
        ` : loadingId === active ? `
          <div style="padding:32px;display:flex;align-items:center;gap:10px;"><span class="spinner"></span> ${t('loading')}</div>
        ` : `
          <div class="admin-policy-editor-header">
            <div>
              <div class="admin-policy-editor-title">${escHtml(ps.activeTitle || active)}</div>
              <div class="admin-policy-editor-filename">${escHtml(ps.activeFilename || '')}</div>
              ${ps.activeDescription ? `<div class="admin-policy-editor-desc">${escHtml(ps.activeDescription)}</div>` : ''}
            </div>
            <div class="admin-policy-status-area">${statusHtml}</div>
          </div>
          <textarea
            class="admin-policy-textarea"
            id="policy-textarea-${escHtml(active)}"
            data-action="policy-draft"
            data-policy-id="${escHtml(active)}"
            spellcheck="false"
            autocomplete="off"
            aria-label="${escHtml(t('admin.promptPolicies.editorTitle'))}"
          >${escHtml(textareaContent)}</textarea>
          <div class="admin-policy-actions">
            <button class="btn btn-primary btn-sm"
                    data-action="policy-save"
                    data-policy-id="${escHtml(active)}"
                    ${saving ? 'disabled' : ''}>
              💾 ${t('admin.promptPolicies.save')}
            </button>
            <button class="btn btn-secondary btn-sm"
                    data-action="policy-reset"
                    data-policy-id="${escHtml(active)}"
                    ${saving ? 'disabled' : ''}>
              ↺ ${t('admin.promptPolicies.reset')}
            </button>
          </div>
        `}
      </div>
    </div>
  `;
}

/* ── Registration ── */

function registerAdminFeature() {
  window.DecisionArena.views.administration  = renderAdministration;
  window.DecisionArena.views.personas        = renderPersonas;
  window.DecisionArena.views.souls           = renderSouls;
  window.DecisionArena.views.providers       = renderProviders;
  window.DecisionArena.views.memories        = renderMemories;
  window.DecisionArena.views.logs            = renderLogs;
  window.DecisionArena.views.templates       = renderTemplates;
  window.DecisionArena.views['template-maker']  = renderTemplateMaker;
  window.DecisionArena.views['persona-maker']   = renderPersonaMaker;
  window.DecisionArena.views['persona-builder'] = renderPersonaBuilder;
  window.DecisionArena.views['scenario-packs']  = renderScenarioPacks;
  window.DecisionArena.views.retrospective      = renderRetrospective;
  window.DecisionArena.views.learning           = renderLearning;
  window.DecisionArena.views['prompt-policies'] = renderPromptPolicies;
  window.DecisionArena.views.shared.showPersonaModal             = showPersonaModal;
  window.DecisionArena.views.shared.buildPersonaMarkdownPreview  = buildPersonaMarkdownPreview;
  window.DecisionArena.views.shared.buildSoulMarkdownPreview     = buildSoulMarkdownPreview;
  window.DecisionArena.views.shared.renderProviderForm           = renderProviderForm;
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
  renderLearning,
};

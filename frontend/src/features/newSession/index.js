/**
 * New Session feature – view registration.
 */

import { decisionDynamicsPresetOptionsHtml } from '../../utils/sessionDynamicsPresetUi.js';
import { getPlaybookById, getPlaybookIntentGroups, resolvePlaybookForNewSession } from '../../core/playbooks.js';
import {
  renderPlaybookCard,
  renderPlaybookDecisionGuide,
  renderPlaybookOutputContract,
} from '../../ui/components.js';

function renderFastDecisionBadge() {
  const t = (key) => window.i18n?.t(key) ?? key;
  return `
    <div class="fast-decision-badge" style="margin-bottom:16px;padding:14px 18px;background:linear-gradient(135deg,rgba(99,102,241,0.15),rgba(139,92,246,0.1));border:1px solid rgba(99,102,241,0.4);border-radius:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-weight:700;font-size:15px;color:var(--text-primary);">⚡ ${t('fast.title')}</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">${t('fast.subtitle')}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${t('fast.agents_hint')}</div>
      </div>
      <button class="btn btn-secondary btn-sm" data-action="ns-fast-customize" style="white-space:nowrap;">${t('fast.customize')}</button>
    </div>
  `;
}

function renderContextHintBanner(questions) {
  const t = (key) => window.i18n?.t(key) ?? key;
  if (!questions || questions.length === 0) return '';
  const items = questions.slice(0, 3).map((q) => {
    const text = q.fallback || '';
    return `<li style="margin-bottom:4px;">${text}</li>`;
  }).join('');
  return `
    <div id="context-hint-banner" style="margin-top:8px;padding:12px 14px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:6px;font-size:12px;color:var(--text-secondary);">
      <div style="font-weight:600;color:#d97706;margin-bottom:6px;">⚠ ${t('context.hint.weak')}</div>
      <div style="color:var(--text-muted);margin-bottom:6px;">${t('context.hint.expand')}</div>
      <ul style="margin:0 0 8px;padding-left:18px;">${items}</ul>
    </div>
  `;
}

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const tip = (tooltip) => {
    if (!tooltip) return '';
    const safe = tooltip.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    return `<span class="info-tooltip" data-tooltip="${safe}" aria-label="${safe}">?</span>`;
  };
  return { state, escHtml, t, tip };
}

/** Liste fusionnée templates + scénarios (UI « modèles de départ »). */
function buildStarterCards(state) {
  const templates = Array.isArray(state.templates) ? state.templates : [];
  const packs = Array.isArray(state.scenarioPacks) ? state.scenarioPacks : [];
  const templateIds = new Set(templates.map((x) => x.id));
  const out = [];

  templates.forEach((tmpl) => {
    out.push({
      kind: 'template',
      id: tmpl.id,
      title: tmpl.name || tmpl.title || tmpl.id,
      description: tmpl.description || '',
      mode: tmpl.mode,
      agents: Array.isArray(tmpl.selected_agents) ? tmpl.selected_agents : [],
      rounds: tmpl.rounds,
      forceDisagreement: !!tmpl.force_disagreement,
      largeWarning: false,
      targetProfile: '',
    });
  });

  packs.forEach((pack) => {
    if (templateIds.has(pack.id)) return;
    out.push({
      kind: 'scenario',
      id: pack.id,
      title: pack.name || pack.id,
      description: pack.description || '',
      mode: pack.recommended_mode,
      agents: Array.isArray(pack.persona_ids) ? pack.persona_ids : [],
      rounds: pack.rounds,
      forceDisagreement: !!pack.force_disagreement,
      largeWarning: !!(pack.max_personas && pack.max_personas > 10),
      targetProfile: pack.target_profile || '',
    });
  });

  return out;
}

function renderStarterCard(card, ns, { escHtml, t, agentIcon, isSimple }) {
  const modeIcons = {
    chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥', jury: '⚖️',
  };
  const modeLabels = {
    chat: t('mode.chat').replace(/^💬\s*/, ''),
    'decision-room': t('mode.decisionRoom').replace(/^🏛️\s*/, ''),
    confrontation: t('mode.confrontation').replace(/^⚔️\s*/, ''),
    'quick-decision': t('mode.quickDecision').replace(/^⚡\s*/, ''),
    'stress-test': t('mode.stressTest').replace(/^🔥\s*/, ''),
    jury: t('jury.title').replace(/^⚖️\s*/, ''),
  };

  const icon = modeIcons[card.mode] || '📋';

  let selected = false;
  if (card.kind === 'template') {
    selected = ns.selectedStarter?.type === 'template' && ns.selectedStarter?.id === card.id;
  } else if (card.kind === 'scenario') {
    selected =
      (ns.selectedStarter?.type === 'scenario' && ns.selectedStarter?.id === card.id)
      || (!ns.selectedStarter && ns.selectedScenarioId === card.id);
  }

  const modeLabel = card.mode ? (modeLabels[card.mode] || card.mode) : '';
  const roundsPart = card.rounds != null ? `${t('template.rounds')}: ${card.rounds}` : '';

  const isSixHats = card.kind === 'template' && card.id === 'six-thinking-hats';
  const displayTitle = isSixHats ? t('sixHats.shortTitle') : card.title;
  const titleHtml = escHtml(displayTitle);
  const descSource = isSixHats ? t('sixHats.starterSubtitle') : card.description;
  const descHtml = descSource ? escHtml(descSource) : '';
  const frameworkBadgeRow = isSixHats
    ? `<div style="margin-top:8px;"><span class="badge badge-info" style="font-size:10px;text-transform:none;">${escHtml(t('sixHats.frameworkBadge'))}</span></div>`
    : '';

  const isPremortem = card.kind === 'template' && card.id === 'pre-mortem';
  const premortemBadgeRow = isPremortem
    ? `<div style="margin-top:8px;"><span class="badge badge-warning" style="font-size:10px;">${escHtml(t('premortem.badge'))}</span></div>`
    : '';

  const profileLine = card.kind === 'scenario' && card.targetProfile
    ? `<div class="template-card-desc starter-card-profile">${escHtml(card.targetProfile)}</div>`
    : '';

  const flagLine = !isSimple && card.forceDisagreement
    ? `<div class="template-card-flag">⚠ ${t('template.forceDisagreement')}</div>`
    : '';

  const warnLine = !isSimple && card.largeWarning
    ? `<div class="template-card-flag starter-card-warn">⚠️ ${t('scenario.warningLarge')}</div>`
    : '';

  const agentsRow = !isSimple && card.agents?.length
    ? `<div class="template-card-agents starter-agents">${card.agents.slice(0, 8).map((id) => `<span class="agent-badge" style="font-size:11px;">${agentIcon(id)}</span>`).join('')}</div>`
    : '';

  const metaInner = isSimple ? '' : `
      <span class="badge badge-default">${escHtml(modeLabel)}</span>
      ${roundsPart ? `<span class="starter-meta-rounds">${escHtml(roundsPart)}</span>` : ''}
    `;

  const sid = escHtml(card.id);

  return `
    <div class="template-card starter-card ${selected ? 'selected' : ''}"
         data-action="select-starter"
         data-starter-type="${card.kind}"
         data-starter-id="${sid}"
         role="button"
         tabindex="0"
         aria-pressed="${selected}">
      <div class="template-card-header starter-card-header">
        <span class="template-card-icon starter-icon">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="template-card-name starter-title">${titleHtml}</div>
          ${frameworkBadgeRow}
          ${premortemBadgeRow}
          ${descHtml ? `<div class="template-card-desc starter-description">${descHtml}</div>` : ''}
          ${profileLine}
          ${flagLine}
          ${warnLine}
        </div>
      </div>
      ${metaInner ? `<div class="template-card-meta starter-meta">${metaInner}</div>` : ''}
      ${agentsRow}
      <div class="template-card-hint starter-use-hint" aria-hidden="true">${t('starter.use')}</div>
    </div>`;
}

function renderPlaybookSelectionSection(ns, { escHtml }) {
  const lang = window.i18n?.getLanguage?.() || ns.language || 'fr';
  const current = resolvePlaybookForNewSession(ns, lang);
  const groups = getPlaybookIntentGroups(lang).map((group) => `
    <section class="playbook-intent-group">
      <div class="playbook-intent-group-head">
        <div class="playbook-intent-label">${escHtml(group.label)}</div>
        <div class="playbook-intent-question">${escHtml(group.question)}</div>
        <div class="playbook-intent-description">${escHtml(group.description)}</div>
      </div>
      <div class="playbook-intent-cards">
        ${group.playbooks.map((playbook) => renderPlaybookCard(playbook, {
          escHtml,
          active: current?.id === playbook.id,
          language: lang,
          compact: true,
        })).join('')}
      </div>
    </section>
  `).join('');

  return `
    <div class="section playbook-selection-section" style="margin-bottom:22px;max-width:1100px;width:100%;">
      <div class="section-header" style="margin-bottom:10px;">
        <span class="section-label">${escHtml(lang === 'en' ? 'Choose by decision intent' : 'Choisir par intention de decision')}</span>
      </div>
      <p class="card-description" style="margin-bottom:12px;">${escHtml(lang === 'en'
        ? 'Start from the decision you need, then pick the playbook that produces the right outcome.'
        : 'Partez de la decision a prendre, puis choisissez le playbook qui produit le bon outcome.')}</p>
      <div class="playbook-intent-groups">
        ${groups}
      </div>
    </div>
  `;
}

function renderSelectedPlaybookBlocks(ns, { escHtml }) {
  const lang = window.i18n?.getLanguage?.() || ns.language || 'fr';
  const playbook = resolvePlaybookForNewSession(ns, lang);
  if (!playbook) return '';
  const outputs = Array.isArray(playbook.output_contract) ? playbook.output_contract : [];
  const outputsPreview = outputs.slice(0, 4);
  const youWillGetLabel = lang === 'en' ? 'You will get' : 'Vous allez obtenir';
  const whyLabel = lang === 'en' ? 'Why this playbook?' : 'Pourquoi ce playbook ?';
  const whyHint = lang === 'en'
    ? 'Details, decision guide, and full output contract.'
    : 'Détails, guide de décision, et contrat de sortie complet.';
  return `
    <div class="card" style="padding:14px 16px;">
      <div style="font-weight:800;font-size:13px;color:var(--text-primary);margin-bottom:8px;">${escHtml(youWillGetLabel)}</div>
      ${outputsPreview.length ? `
        <ul style="margin:0;padding-left:18px;color:var(--text-secondary);font-size:12px;line-height:1.5;">
          ${outputsPreview.map((x) => `<li style="margin:0 0 6px;">${escHtml(x)}</li>`).join('')}
        </ul>
      ` : `<div style="font-size:12px;color:var(--text-muted);">${escHtml(lang === 'en' ? 'Structured decision outcome.' : 'Un outcome décisionnel structuré.')}</div>`}
    </div>

    <details class="ns-collapsible" data-ui="expert-only" style="margin-top:12px;">
      <summary class="ns-collapsible-summary">
        <span>${escHtml(whyLabel)}</span>
        <span class="ns-collapsible-hint">${escHtml(whyHint)}</span>
      </summary>
      <div class="ns-collapsible-body">
        ${renderPlaybookDecisionGuide(playbook, { escHtml, language: lang, uiMode: window.DecisionArena?.store?.state?.uiMode })}
        ${renderPlaybookOutputContract(playbook, { escHtml, language: lang })}
      </div>
    </details>
  `;
}

function renderFounderInterrogationPanel(ns, { escHtml, t }) {
  if (ns.productPreset !== 'founder-sprint') return '';
  const isExpertUi = window.DecisionArena?.store?.state?.uiMode === 'expert';
  const fi = ns.founderInterrogation && typeof ns.founderInterrogation === 'object' ? ns.founderInterrogation : null;
  const fiField = (key, labelKey, placeholderKey) => `
    <div class="form-group" style="margin:0;">
      <label for="fi-${escHtml(key)}" style="font-size:12px;">${escHtml(t(labelKey))}</label>
      <textarea class="textarea" id="fi-${escHtml(key)}" data-fi-field="${escHtml(key)}"
        placeholder="${escHtml(t(placeholderKey))}" style="min-height:64px;resize:vertical;">${escHtml((fi && fi[key]) || '')}</textarea>
    </div>
  `;
  const fiBody = `
    <div class="card-description" style="margin-bottom:12px;">${escHtml(t('founderInterrogation.subtitle'))}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
      ${fiField('pain', 'founderInterrogation.q1.label', 'founderInterrogation.q1.placeholder')}
      ${fiField('icp', 'founderInterrogation.q2.label', 'founderInterrogation.q2.placeholder')}
      ${fiField('statusQuo', 'founderInterrogation.q3.label', 'founderInterrogation.q3.placeholder')}
      ${fiField('criticalAssumption', 'founderInterrogation.q4.label', 'founderInterrogation.q4.placeholder')}
      ${fiField('wedge', 'founderInterrogation.q5.label', 'founderInterrogation.q5.placeholder')}
      ${fiField('validationSignal', 'founderInterrogation.q6.label', 'founderInterrogation.q6.placeholder')}
    </div>
  `;
  return `
    <div class="card" style="max-width:1100px;width:100%;margin:0 0 18px;padding:16px;border:1px solid var(--border);background:var(--bg-secondary);">
      ${isExpertUi ? `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <div style="font-weight:700;">${escHtml(t('founderInterrogation.title'))}</div>
          <button type="button" class="btn btn-secondary btn-sm" data-action="toggle-founder-interrogation" aria-expanded="${!!fi?.open}">
            ${fi?.open ? escHtml(t('founderInterrogation.hide')) : escHtml(t('founderInterrogation.show'))}
          </button>
        </div>
        ${fi?.open ? `<div style="margin-top:12px;">${fiBody}</div>` : `<div class="card-description" style="margin-top:10px;">${escHtml(t('founderInterrogation.expertHint'))}</div>`}
      ` : `
        <div style="font-weight:700;margin-bottom:10px;">${escHtml(t('founderInterrogation.title'))}</div>
        ${fiBody}
      `}
    </div>
  `;
}

function renderStarterModelsSection() {
  const { state, escHtml, t } = getCtx();
  const ns = state.newSession;
  const isSimple = state.uiMode === 'basic';
  const agentIcon = (id) => window.DecisionArena.utils.agentIcon(state.personas, id);
  const cards = buildStarterCards(state);
  const visibleCards = isSimple
    ? cards.filter((c) => ['chat', 'quick-decision', 'stress-test', 'decision-room', 'confrontation'].includes(c.mode || ''))
    : cards;
  const html = visibleCards.map((c) => renderStarterCard(c, ns, { escHtml, t, agentIcon, isSimple })).join('');
  const appliedHint = (ns.selectedStarter || ns.selectedScenarioId) ? `
      <div class="starter-applied-hint" style="margin-top:10px;font-size:12px;color:var(--success,#10b981);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        ✅ ${t('scenario.applied')}
        <button type="button" class="btn btn-secondary btn-sm" data-action="clear-scenario" style="font-size:11px;padding:2px 8px;">
          ${t('scenario.clearSelection')}
        </button>
      </div>
    ` : '';
  return `
    <details class="ns-collapsible starter-models-section" style="margin-bottom:18px;max-width:1100px;width:100%;">
      <summary class="ns-collapsible-summary">
        <span>${t('starter.section.title')}</span>
        <span class="ns-collapsible-hint">${t('starter.section.subtitle')}</span>
      </summary>
      <div class="ns-collapsible-body">
        <div class="starter-models-body">
          <div class="starter-grid">${html}</div>
        </div>
        ${appliedHint}
      </div>
    </details>
  `;
}

function renderContextDocumentSection() {
  const { state, escHtml, t, tip } = getCtx();
  const ns = state.newSession;
  const charCount = (ns.ctxDocContent || '').length;
  const isLarge   = charCount > 30000;
  const isOver    = charCount > 50000;

  return `
    <div class="card ctx-doc-section" style="max-width:1100px;width:100%;margin-top:24px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:${ns.ctxDocEnabled ? '16px' : '0'};">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;">
          <input type="checkbox" id="ctx-doc-enabled" ${ns.ctxDocEnabled ? 'checked' : ''} data-action="toggle-ctx-doc-enabled" style="width:16px;height:16px;accent-color:var(--accent);">
          <span style="font-weight:600;font-size:14px;color:var(--text-primary);">${t('contextDoc.sectionTitle')} <span style="font-weight:400;font-size:12px;color:var(--text-muted);">${t('contextDoc.optional')}</span> ${tip(t('option.contextDoc.desc'))}</span>
        </label>
      </div>

      ${ns.ctxDocEnabled ? `
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">${t('contextDoc.enabledHint')}</div>
        <div class="ctx-doc-tabs">
          <button class="ctx-doc-tab ${ns.ctxDocTab === 'manual' ? 'active' : ''}" data-action="ctx-doc-tab" data-tab="manual">${t('contextDoc.tabs.manual')}</button>
          <button class="ctx-doc-tab ${ns.ctxDocTab === 'upload' ? 'active' : ''}" data-action="ctx-doc-tab" data-tab="upload">${t('contextDoc.tabs.upload')}</button>
        </div>

        ${ns.ctxDocTab === 'manual' ? `
          <div class="form-group" style="margin-top:14px;">
            <label for="ctx-doc-title-manual">${t('contextDoc.titleLabel')}</label>
            <input class="input" id="ctx-doc-title-manual" type="text" value="${escHtml(ns.ctxDocTitle)}" data-action="ctx-doc-title-change">
          </div>
          <div class="form-group">
            <label for="ctx-doc-content">${t('contextDoc.contentLabel')}</label>
            <textarea class="textarea" id="ctx-doc-content" placeholder="${escHtml(t('contextDoc.contentPlaceholder'))}" style="min-height:160px;" data-action="ctx-doc-content-change">${escHtml(ns.ctxDocContent)}</textarea>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">
              <span style="font-size:12px;color:${isOver ? '#ef4444' : isLarge ? '#f59e0b' : 'var(--text-muted)'};">
                ${charCount.toLocaleString()} / 50,000
                ${isOver ? ` — ${t('contextDoc.limitExceeded')}` : ''}
              </span>
            </div>
            ${isLarge && !isOver ? `<div class="ctx-doc-warning">⚠️ ${t('contextDoc.largeWarning')}</div>` : ''}
            ${isOver ? `<div class="ctx-doc-warning error">⛔ ${t('contextDoc.limitExceeded')}</div>` : ''}
            <div style="display:flex;gap:8px;margin-top:10px;">
              <button class="btn btn-secondary btn-sm" data-action="save-ctx-doc-draft-manual" ${isOver || !ns.ctxDocContent.trim() ? 'disabled' : ''}>${t('contextDoc.save')}</button>
            </div>
          </div>
        ` : `
          <div class="form-group" style="margin-top:14px;">
            <label for="ctx-doc-title-upload">${t('contextDoc.titleLabel')}</label>
            <input class="input" id="ctx-doc-title-upload" type="text" value="${escHtml(ns.ctxDocTitle)}" data-action="ctx-doc-title-change">
          </div>
          <div class="form-group">
            <label>${t('contextDoc.fileLabel')}</label>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">${t('contextDoc.fileHint')}</div>
            <input class="input" id="ctx-doc-file" type="file" accept=".txt,.md,.pdf,.docx" style="padding:6px;">
            <div style="display:flex;gap:8px;margin-top:10px;">
              <button class="btn btn-secondary btn-sm" data-action="save-ctx-doc-draft-upload">${t('contextDoc.save')}</button>
            </div>
          </div>
        `}

        ${ns.ctxDocDraftSaved && ns.ctxDocDraftSummary ? `
          <div class="ctx-doc-warning" style="margin-top:10px;background:rgba(16,185,129,0.1);border-color:rgba(16,185,129,0.25);color:#059669;">
            ✅ ${t('contextDoc.attachedBadge')} — ${escHtml(ns.ctxDocDraftSummary)}
          </div>
        ` : ''}
      ` : ''}
    </div>
  `;
}

function renderDecisionMemoryReuseSection(ns, { state, escHtml, t }) {
  const mp = ns.memoryPicker || { open: false, loading: false, error: null, filters: {}, memories: null, compactPreview: null };
  const selected = Array.isArray(ns.selectedMemoryIds) ? ns.selectedMemoryIds : [];
  const preview = mp.compactPreview || null;
  const allowed = Array.isArray(preview?.allowed) ? preview.allowed : [];
  const blocked = Array.isArray(preview?.blocked) ? preview.blocked : [];
  const isExpert = state.uiMode === 'expert';

  const blockedById = (() => {
    const m = new Map();
    blocked.forEach((b) => {
      if (b && b.memory_id) m.set(String(b.memory_id), b);
    });
    return m;
  })();

  const warning = (() => {
    const weak = allowed.some((m) => String(m.confidence || '') === 'weak');
    const hasRisks = allowed.some((m) => Array.isArray(m.unresolved_risks) && m.unresolved_risks.length > 0);
    const hasStaleBlocked = blocked.some((b) => String(b.reason || '') === 'stale_requires_confirmation');
    if (!weak && !hasRisks && !hasStaleBlocked) return '';
    return `<div class="ctx-doc-warning" style="margin-top:10px;">
      ⚠️ ${escHtml(hasStaleBlocked ? t('memoryReuse.warningStale') : t('memoryReuse.warningWeak'))}
    </div>`;
  })();

  const staleConfirmCta = (() => {
    const hasStaleBlocked = blocked.some((b) => String(b.reason || '') === 'stale_requires_confirmation');
    if (!hasStaleBlocked) return '';
    return `
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <span class="badge badge-warning">${escHtml(t('memoryReuse.staleNeedsConfirm'))}</span>
        <button type="button" class="btn btn-secondary btn-sm" data-action="confirm-allow-stale-memories">
          ${escHtml(t('memoryReuse.allowStale'))}
        </button>
      </div>
    `;
  })();

  const expertOverrideToggle = isExpert ? `
    <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-size:12px;color:var(--text-secondary);">
        <input type="checkbox" ${mp.expertOverride ? 'checked' : ''} data-action="toggle-expert-memory-override" style="accent-color:var(--accent);">
        ${escHtml(t('memoryReuse.expertOverride'))}
      </label>
    </div>
  ` : '';

  const filters = mp.filters || {};
  const filterRow = (key, label, placeholder = '') => `
    <div class="form-group" style="margin:0;">
      <label style="font-size:12px;">${escHtml(label)}</label>
      <input class="input" type="text" value="${escHtml(filters[key] || '')}" placeholder="${escHtml(placeholder)}"
        data-action="set-memory-filter" data-filter-key="${escHtml(key)}" style="padding:6px 8px;font-size:12px;">
    </div>`;

  const list = Array.isArray(mp.memories) ? mp.memories : [];
  const listHtml = list.length === 0
    ? `<div style="font-size:12px;color:var(--text-muted);">${escHtml(t('memoryReuse.emptySearch'))}</div>`
    : list.slice(0, 80).map((m) => {
      const id = String(m.memory_id || '');
      const checked = selected.includes(id) ? 'checked' : '';
      const confirmed = m.user_confirmed === 1 || m.user_confirmed === true;
      const canReuse = confirmed && String(m.contract_version || '') === 'decision_outcome.v1' && String(m.taxonomy_version || '') === 'taxonomy.v1';
      const badge = canReuse ? `<span class="badge badge-success">${escHtml(t('memoryReuse.reusable'))}</span>` : `<span class="badge badge-warning">${escHtml(t('memoryReuse.notReusable'))}</span>`;
      const decay = m.decay && typeof m.decay === 'object' ? m.decay : null;
      const staleness = decay ? String(decay.staleness_level || '') : '';
      const stateBadge = decay ? String(decay.memory_state || '') : String(m.memory_state || 'active');
      const staleBadge = staleness ? `<span class="badge ${staleness === 'stale' ? 'badge-danger' : staleness === 'aging' ? 'badge-warning' : 'badge-muted'}">${escHtml(staleness)}</span>` : '';
      const lifecycleBadge = stateBadge ? `<span class="badge ${stateBadge === 'invalidated' ? 'badge-danger' : stateBadge === 'archived' ? 'badge-muted' : stateBadge === 'superseded' ? 'badge-warning' : 'badge-success'}">${escHtml(stateBadge)}</span>` : '';
      const blockedInfo = blockedById.get(id);
      const disablePick = !isExpert && blockedInfo && ['invalidated', 'archived'].includes(String(blockedInfo.reason || ''));
      return `
        <label style="display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer;background:var(--bg-secondary);">
          <input type="checkbox" ${checked} ${disablePick ? 'disabled' : ''} data-action="toggle-select-memory-for-new-session" data-memory-id="${escHtml(id)}" style="margin-top:3px;accent-color:var(--accent);">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <span class="badge badge-muted">#${escHtml(id.slice(0, 8))}</span>
              <span class="badge badge-info">${escHtml(String(m.playbook_id || ''))}</span>
              <span class="badge badge-muted">${escHtml(String(m.decision_status || ''))}</span>
              <span class="badge badge-muted">${escHtml(String(m.confidence || ''))}</span>
              ${lifecycleBadge}
              ${staleBadge}
              ${badge}
            </div>
            <div style="margin-top:6px;font-size:12px;color:var(--text-secondary);line-height:1.45;">
              ${escHtml(String(m.decision_summary || ''))}
            </div>
            ${blockedInfo ? `<div style="margin-top:8px;font-size:11px;color:var(--text-muted);">⚠ ${escHtml(t('memoryReuse.blocked'))}: <code>${escHtml(String(blockedInfo.reason || ''))}</code></div>` : ''}
          </div>
        </label>
      `;
    }).join('');

  const previewJson = preview ? escHtml(JSON.stringify({ allowed, blocked }, null, 2)) : '';

  return `
    <details class="ns-collapsible" style="max-width:1100px;width:100%;margin-top:14px;">
      <summary class="ns-collapsible-summary">
        <span>＋ ${escHtml(t('memoryReuse.title'))}</span>
        <span class="ns-collapsible-hint">${escHtml(t('memoryReuse.subtitle'))}</span>
      </summary>
      <div class="ns-collapsible-body">
        <div class="card" style="max-width:1100px;width:100%;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div style="font-weight:600;font-size:14px;color:var(--text-primary);">🗂️ ${escHtml(t('memoryReuse.title'))}</div>
            <button class="btn btn-secondary btn-sm" type="button" data-action="toggle-memory-picker">
              ${mp.open ? escHtml(t('memoryReuse.hide')) : escHtml(t('memoryReuse.browse'))}
            </button>
          </div>
          ${selected.length ? `
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <span class="badge badge-info">${escHtml(t('memoryReuse.selected'))}: ${selected.length}/5</span>
              <button class="btn btn-secondary btn-sm" type="button" data-action="clear-selected-memories-new-session">${escHtml(t('memoryReuse.clear'))}</button>
            </div>
          ` : ''}
          ${warning}
          ${staleConfirmCta}
          ${expertOverrideToggle}
          ${mp.open ? `
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
              <div data-ui="expert-only" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:10px;">
                ${filterRow('playbook_id', t('memoryReuse.filter.playbook'), 'founder-sprint')}
                ${filterRow('decision_status', t('memoryReuse.filter.status'), 'proceed')}
                ${filterRow('confidence', t('memoryReuse.filter.confidence'), 'moderate')}
                ${filterRow('from', t('memoryReuse.filter.from'), '2026-01-01')}
                ${filterRow('to', t('memoryReuse.filter.to'), '2026-12-31')}
                ${filterRow('link_type', t('memoryReuse.filter.linkType'), 'pivot')}
                ${filterRow('q', t('memoryReuse.filter.search'), t('memoryReuse.filter.searchHint'))}
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                <button class="btn btn-secondary btn-sm" type="button" data-action="load-memory-picker" ${mp.loading ? 'disabled' : ''}>
                  ${mp.loading ? '<span class="spinner"></span>' : '↺'} ${escHtml(t('memoryReuse.search'))}
                </button>
                ${mp.error ? `<span style="font-size:12px;color:var(--danger);">⚠️ ${escHtml(mp.error)}</span>` : ''}
              </div>
              ${listHtml}
              <details data-ui="expert-only" style="margin-top:12px;">
                <summary style="cursor:pointer;color:var(--text-muted);">${escHtml(t('memoryReuse.previewInjected'))}</summary>
                <pre style="margin:10px 0 0;white-space:pre-wrap;font-size:11px;color:var(--text-secondary);">${previewJson}</pre>
              </details>
            </div>
          ` : ''}
        </div>
      </div>
    </details>
  `;
}

function renderNewSession() {
  const { state, escHtml, t, tip } = getCtx();
  const ns = state.newSession;
  /** Simple vs Expert (sidebar AFFICHAGE) */
  const isSimpleDisplay = state.uiMode === 'basic';
  const personas = state.personas.filter((p) => {
    const modes = Array.isArray(p.available_modes) ? p.available_modes : ['chat', 'decision-room', 'confrontation'];
    return modes.includes(ns.mode) || ns.mode === 'quick-decision' || ns.mode === 'stress-test' || ns.mode === 'jury';
  });

  const agentSectionHtml = ns.mode === 'confrontation' ? `
    <div class="form-group">
      <label>${t('newSession.mode')}</label>
      <div class="team-selector">
        <div class="team-selector-blue">
          <div class="team-label">${t('newSession.blueTeam')}</div>
          ${personas.length === 0 ? `<div style="padding:8px 0;color:var(--text-muted);font-size:13px;">${t('newSession.loadingPersonas')}</div>` : `
            <div class="agents-select-grid">
              ${personas.map((p) => { const sel = ns.blueTeam.includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="toggle-blue-team" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} style="pointer-events:none;accent-color:#3b82f6;"><span style="font-size:20px;">${escHtml(p.icon || '🤖')}</span><div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(p.name)}</div></label>`; }).join('')}
            </div>
          `}
        </div>
        <div class="team-selector-red">
          <div class="team-label">${t('newSession.redTeam')}</div>
          ${personas.length === 0 ? `<div style="padding:8px 0;color:var(--text-muted);font-size:13px;">${t('newSession.loadingPersonas')}</div>` : `
            <div class="agents-select-grid">
              ${personas.map((p) => { const sel = ns.redTeam.includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="toggle-red-team" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} style="pointer-events:none;accent-color:#ef4444;"><span style="font-size:20px;">${escHtml(p.icon || '🤖')}</span><div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(p.name)}</div></label>`; }).join('')}
            </div>
          `}
        </div>
      </div>
      <div class="cf-config-section" data-ui="expert-only" style="margin-top:20px;padding:16px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border);">
        <div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:14px;letter-spacing:.05em;text-transform:uppercase;">${t('confrontation.settings')}</div>
        <div class="form-row">
          <div class="form-group">
            <label for="cf-rounds">${t('confrontation.rounds')} (${ns.cfRounds}) ${tip(t('tooltip.rounds'))}</label>
            <input class="input" id="cf-rounds" type="range" min="1" max="15" value="${ns.cfRounds}" data-cf-field="cfRounds" style="padding:6px 0;">
            <div class="range-scale"><span>1</span><span>5</span><span>10</span><span>15</span></div>
          </div>
        </div>
        <div class="form-group">
          <label>${t('confrontation.interactionStyle')} ${tip(t('option.cfStyle.desc'))}</label>
          <div class="mode-selector" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <label class="mode-option ${ns.cfStyle === 'sequential' ? 'selected' : ''}"><input type="radio" name="cf-style" value="sequential" ${ns.cfStyle === 'sequential' ? 'checked' : ''} data-cf-field="cfStyle"><div><div class="mode-option-label">${t('confrontation.styleSequential')}</div><div class="mode-option-desc">${t('confrontation.styleSequentialDesc')}</div></div></label>
            <label class="mode-option ${ns.cfStyle === 'agent-to-agent' ? 'selected' : ''}"><input type="radio" name="cf-style" value="agent-to-agent" ${ns.cfStyle === 'agent-to-agent' ? 'checked' : ''} data-cf-field="cfStyle"><div><div class="mode-option-label">${t('confrontation.styleAgentToAgent')}</div><div class="mode-option-desc">${t('confrontation.styleAgentToAgentDesc')}</div></div></label>
          </div>
        </div>
        <div class="form-group">
          <label for="cf-reply-policy">${t('confrontation.replyPolicy')} ${tip(t('option.replyPolicy.desc'))}</label>
          <select class="input" id="cf-reply-policy" data-cf-field="cfReplyPolicy">
            <option value="all-agents-reply" ${ns.cfReplyPolicy === 'all-agents-reply' ? 'selected' : ''}>${t('confrontation.policyAllAgents')}</option>
            <option value="only-mentioned-agent-replies" ${ns.cfReplyPolicy === 'only-mentioned-agent-replies' ? 'selected' : ''}>${t('confrontation.policyMentioned')}</option>
            <option value="critic-priority" ${ns.cfReplyPolicy === 'critic-priority' ? 'selected' : ''}>${t('confrontation.policyCritic')}</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <input type="checkbox" id="ns-synthesis" ${ns.includeSynthesis ? 'checked' : ''} data-field="includeSynthesis" style="width:16px;height:16px;accent-color:var(--accent);">
          <label for="ns-synthesis" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;color:var(--text-secondary);">${t('newSession.includeSynthesis')}</label>
        </div>
      </div>
    </div>
  ` : `
    <div class="form-group">
      <label>${t('newSession.selectAgents')}</label>
      ${personas.length === 0 ? `<div class="empty-state" style="padding:16px 0;"><div class="empty-state-text">${t('newSession.loadingPersonas')}</div></div>` : `
        <div class="agents-select-grid">
          ${personas.map((p) => { const sel = ns.selectedAgents.includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="toggle-agent" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} data-agent-id="${escHtml(p.id)}" style="pointer-events:none;"><span style="font-size:22px;">${escHtml(p.icon || '🤖')}</span><div><div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(p.name)}</div><div style="font-size:11px;color:var(--text-muted);">${escHtml(p.title || '')}</div></div></label>`; }).join('')}
        </div>
      `}
    </div>
  `;

  const submitLabel = ns.mode === 'decision-room' ? t('newSession.launchDR')
    : ns.mode === 'confrontation' ? t('newSession.launchConfrontation')
    : ns.mode === 'quick-decision' ? t('newSession.launchQuickDecision')
    : ns.mode === 'stress-test' ? t('newSession.launchStressTest')
    : ns.mode === 'jury' ? t('jury.run')
    : t('newSession.launchChat');

  const forkBannerHtml = ns.isFork ? `
    <div class="info-banner" style="margin-bottom:16px;padding:12px 16px;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.35);border-radius:8px;font-size:13px;color:var(--text-secondary);">
      ${escHtml(t('hitl.forkBanner'))}
    </div>` : '';

  const continueLabel = ns.isFork ? t('hitl.forkContinue') : submitLabel;

  const isFastDecision = ns.mode === 'decision-room' && ns.fastDecisionEnabled !== false;
  const uiLang = window.i18n?.getLanguage?.() || ns.language || 'fr';
  const modePlaybook = (id) => getPlaybookById(id, uiLang);
  const confrontationPlaybook = modePlaybook('confrontation');
  const quickPlaybook = modePlaybook('quick-decision');
  const stressPlaybook = modePlaybook('stress-test');
  const juryPlaybook = modePlaybook('jury');
  if (isSimpleDisplay) {
    const playbook = resolvePlaybookForNewSession(ns, uiLang);
    return `
      <section class="simple-new-session">
        <header class="simple-new-session-header">
          <h1>${escHtml(t('newSession.simple.title'))}</h1>
          <p>${escHtml(t('newSession.simple.subtitle'))}</p>
        </header>

        ${forkBannerHtml}

        ${renderPlaybookSelectionSection(ns, { escHtml })}

        ${playbook ? `
          <div class="card" style="max-width:1100px;width:100%;margin:0 0 14px;padding:14px 16px;">
            <div style="font-size:11px;color:var(--text-muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em;">${escHtml(playbook.intention)}</div>
            <div style="font-weight:800;font-size:16px;color:var(--text-primary);margin-top:2px;">${escHtml(playbook.name)}</div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;line-height:1.45;">${escHtml(playbook.tagline)}</div>
          </div>
        ` : ''}

        <div class="card simple-new-session-card" id="new-session-form-card">
          <div class="simple-form-grid">
            <div class="form-group">
              <label for="ns-title-simple">${escHtml(t('newSession.question.label'))}</label>
              <input class="input" id="ns-title-simple" type="text" placeholder="${escHtml(t('newSession.question.placeholder'))}" value="${escHtml(ns.title)}" data-field="title">
            </div>

            <div class="form-group">
              <label for="ns-idea-simple">${escHtml(t('newSession.context.label'))}</label>
              <textarea class="textarea" id="ns-idea-simple" placeholder="${escHtml(t('newSession.context.placeholder'))}" data-field="idea">${escHtml(ns.idea)}</textarea>
              <div id="context-hint-banner-container">${renderContextHintBanner(ns.contextHintQuestions || null)}</div>
            </div>

            ${renderSelectedPlaybookBlocks(ns, { escHtml })}

            <div class="form-group">
              <label>${t('newSession.responseLanguage')}</label>
              <div class="language-selector">
                <button type="button" class="language-option ${ns.language === 'en' ? 'active' : ''}" data-action="select-language" data-lang="en">English</button>
                <button type="button" class="language-option ${ns.language === 'fr' ? 'active' : ''}" data-action="select-language" data-lang="fr">Français</button>
              </div>
            </div>

            <div class="simple-submit-row">
              <button class="btn btn-primary btn-lg" data-action="launch-session" ${state.isLoading ? 'disabled' : ''}>
                ${state.isLoading ? '<span class="spinner"></span>' : ''}
                ${escHtml(ns.isFork ? t('hitl.forkContinue') : t('newSession.launchAnalysis'))}
              </button>
            </div>
          </div>
        </div>

        ${renderFounderInterrogationPanel(ns, { escHtml, t })}
        ${renderStarterModelsSection()}
        ${renderDecisionMemoryReuseSection(ns, { state, escHtml, t })}
      </section>
    `;
  }

  return `
    <div class="page-header">
      <div class="page-title">${t('newSession.title')}</div>
      <div class="page-subtitle">${t('newSession.subtitle')}</div>
    </div>

    ${forkBannerHtml}

    ${renderPlaybookSelectionSection(ns, { escHtml })}

    ${renderFounderInterrogationPanel(ns, { escHtml, t })}

    ${renderStarterModelsSection()}

    ${renderDecisionMemoryReuseSection(ns, { state, escHtml, t })}

    <div class="card" id="new-session-form-card" style="max-width:1100px;width:100%;">
      <div class="form-group">
        <label for="ns-title">${t('newSession.sessionTitle')}</label>
        <input class="input" id="ns-title" type="text" placeholder="${t('newSession.titlePlaceholder')}" value="${escHtml(ns.title)}" data-field="title">
      </div>
      <div class="form-group">
        <label for="ns-idea">${t('newSession.idea')}</label>
        <textarea class="textarea" id="ns-idea" placeholder="${t('newSession.ideaPlaceholder')}" style="min-height:120px;" data-field="idea">${escHtml(ns.idea)}</textarea>
        <div id="context-hint-banner-container">${renderContextHintBanner(ns.contextHintQuestions || null)}</div>
      </div>
      ${renderSelectedPlaybookBlocks(ns, { escHtml })}
      <div class="form-group">
        <label>${t('newSession.responseLanguage')}</label>
        <div class="language-selector">
          <button class="language-option ${ns.language === 'en' ? 'active' : ''}" data-action="select-language" data-lang="en">🇬🇧 English</button>
          <button class="language-option ${ns.language === 'fr' ? 'active' : ''}" data-action="select-language" data-lang="fr">🇫🇷 Français</button>
        </div>
      </div>
      ${decisionDynamicsPresetOptionsHtml(ns, escHtml, t, 'ns-dd-preset-select')}
      <div class="form-group">
        <label>${t('newSession.mode')} ${tip(t('tooltip.sessionMode'))}</label>
        <div class="mode-selector">
          <label class="mode-option ${ns.mode === 'chat' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="chat" ${ns.mode === 'chat' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.chat')}</div><div class="mode-option-desc">${t('mode.chatDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'decision-room' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="decision-room" ${ns.mode === 'decision-room' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.decisionRoom')}</div><div class="mode-option-desc">${t('mode.decisionRoomDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'confrontation' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="confrontation" ${ns.mode === 'confrontation' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${escHtml(confrontationPlaybook?.name || 'confrontation')}</div><div class="mode-option-desc">${escHtml(confrontationPlaybook?.intention || '')}</div></div></label>
          <label class="mode-option ${ns.mode === 'quick-decision' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="quick-decision" ${ns.mode === 'quick-decision' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${escHtml(quickPlaybook?.name || 'quick-decision')}</div><div class="mode-option-desc">${escHtml(quickPlaybook?.intention || '')}</div></div></label>
          <label class="mode-option ${ns.mode === 'stress-test' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="stress-test" ${ns.mode === 'stress-test' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${escHtml(stressPlaybook?.name || 'stress-test')}</div><div class="mode-option-desc">${escHtml(stressPlaybook?.intention || '')}</div></div></label>
          <label class="mode-option ${ns.mode === 'jury' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="jury" ${ns.mode === 'jury' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${escHtml(juryPlaybook?.name || 'jury')}</div><div class="mode-option-desc">${escHtml(juryPlaybook?.intention || '')}</div></div></label>
        </div>
      </div>

      ${(() => {
        const currentPlaybook = resolvePlaybookForNewSession(ns, uiLang);
        if (currentPlaybook?.tagline) {
          return `<div class="card-usage" style="margin-bottom:14px;font-size:12px;">👉 ${escHtml(currentPlaybook.tagline)}</div>`;
        }
        const usageKey = { chat: 'mode.chatUsage', 'decision-room': 'mode.decisionRoomUsage' }[ns.mode];
        return usageKey ? `<div class="card-usage" style="margin-bottom:14px;font-size:12px;">👉 ${escHtml(t(usageKey))}</div>` : '';
      })()}

      ${isFastDecision ? renderFastDecisionBadge() : ''}
      <div ${isFastDecision ? 'style="display:none"' : ''}>
      ${agentSectionHtml}

      ${['chat', 'decision-room', 'quick-decision', 'stress-test', 'jury'].includes(ns.mode) ? `
        <div class="form-group" id="rounds-field">
          <label for="ns-rounds">${ns.mode === 'jury' ? t('jury.rounds') : t('newSession.rounds')} (${ns.rounds}) ${tip(t('tooltip.rounds'))}</label>
          <input class="input" id="ns-rounds" type="range" min="1" max="5" value="${ns.rounds}" data-field="rounds" style="padding:6px 0;">
          <div class="range-scale"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
        </div>
      ` : ''}

      ${ns.mode === 'quick-decision' ? `<div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">⚡ ${t('mode.quickDecisionRoundsHint')}</div>` : ''}

      ${['decision-room', 'confrontation', 'quick-decision', 'stress-test', 'jury'].includes(ns.mode) ? `
        <!-- Devil's Advocate toggle (always visible) -->
        <div class="form-group" style="margin-top:16px;">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;text-transform:none;font-weight:500;font-size:13px;">
            <input type="checkbox" id="ns-devil-advocate" ${ns.devilAdvocateEnabled ? 'checked' : ''} data-action="toggle-devil-advocate" style="width:16px;height:16px;accent-color:#dc2626;">
            😈 ${t('devil.advocate.enable')} ${tip(t('devil.advocate.tooltip'))}
          </label>
        </div>
        ${ns.devilAdvocateEnabled ? `
        <div class="form-group" data-ui="expert-only">
          <label for="ns-da-threshold">${t('devil.advocate.threshold')}: <strong id="ns-da-threshold-val">${Math.round((ns.devilAdvocateThreshold || 0.65) * 100)}%</strong></label>
          <input class="input" id="ns-da-threshold" type="range" min="0.50" max="0.90" step="0.05" value="${ns.devilAdvocateThreshold || 0.65}" data-action="change-da-threshold" style="padding:6px 0;">
          <div class="range-scale"><span>50%</span><span>65%</span><span>80%</span><span>90%</span></div>
        </div>` : ''}

        <div data-ui="expert-only">
        <div class="form-group" style="margin-top:16px;">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;text-transform:none;font-weight:500;font-size:13px;">
            <input type="checkbox" id="ns-force-disagreement" ${ns.forceDisagreement ? 'checked' : ''} data-field="forceDisagreement" style="width:16px;height:16px;accent-color:var(--accent);">
            ${t('newSession.forceDisagreement')} ${tip(t('tooltip.forceDisagreement'))}
          </label>
          <div class="card-description">${t('newSession.forceDisagreementDesc')}</div>
        </div>
        <div class="form-group" style="margin-top:16px;">
          <label for="jury-threshold">${t('vote.consensusThreshold')}: <strong id="jury-threshold-val">${((ns.juryThreshold || 0.55) * 100).toFixed(0)}%</strong> ${tip(t('tooltip.threshold'))}</label>
          <input class="input" id="jury-threshold" type="range" min="0.50" max="0.80" step="0.01" value="${ns.juryThreshold || 0.55}" data-field="juryThreshold" style="padding:6px 0;">
          <div class="range-scale"><span>50%</span><span>55%</span><span>65%</span><span>70%</span><span>80%</span></div>
          <div class="card-description">${t('vote.consensusThresholdDesc')}</div>
        </div>
        </div>

        ${(() => {
          // LLM Assignment block — only if 2+ providers configured
          const providers = state.providers || [];
          const activeProviders = providers.filter((p) => p.enabled == 1 || p.enabled === true);
          if (activeProviders.length < 2) return '';

          const assignMode = ns.llmAssignmentMode || 'global';
          const teamAssign = ns.teamProviderAssignments || { blue: {provider_id:'',model:''}, red: {provider_id:'',model:''} };
          const agentsForLLM = ns.mode === 'confrontation'
            ? [...new Set([...(ns.blueTeam||[]), ...(ns.redTeam||[])])]
            : (ns.selectedAgents || []);

          const providerOpts = (selectedId) => [
            `<option value="">${t('newSession.llmAssignment.provider')} (${t('newSession.llmAssignment.global')})</option>`,
            ...activeProviders.map((p) => `<option value="${escHtml(p.id)}" ${selectedId === p.id ? 'selected' : ''}>${escHtml(p.name || p.id)}</option>`)
          ].join('');

          const modeTabStyle = (m) => assignMode === m
            ? 'padding:6px 14px;font-size:12px;font-weight:600;border-radius:6px;background:var(--accent);color:#fff;border:none;cursor:pointer;'
            : 'padding:6px 14px;font-size:12px;border-radius:6px;background:var(--bg-secondary);color:var(--text-secondary);border:1px solid var(--border);cursor:pointer;';

          const teamRows = ns.mode === 'confrontation' ? `
            <div class="llm-assignment-grid" style="margin-top:10px;">
              <div class="llm-agent-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:600;min-width:90px;color:#3b82f6;">🔵 ${t('newSession.llmAssignment.blueTeam')}</span>
                <select class="input" style="flex:1;min-width:120px;padding:4px 6px;font-size:12px;" data-action="set-team-provider" data-team="blue">
                  ${providerOpts(teamAssign.blue?.provider_id || '')}
                </select>
                <input class="input" type="text" placeholder="${t('newSession.llmAssignment.model')}" style="width:130px;padding:4px 6px;font-size:12px;" value="${escHtml(teamAssign.blue?.model || '')}" data-action="set-team-model" data-team="blue">
              </div>
              <div class="llm-agent-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:600;min-width:90px;color:#ef4444;">🔴 ${t('newSession.llmAssignment.redTeam')}</span>
                <select class="input" style="flex:1;min-width:120px;padding:4px 6px;font-size:12px;" data-action="set-team-provider" data-team="red">
                  ${providerOpts(teamAssign.red?.provider_id || '')}
                </select>
                <input class="input" type="text" placeholder="${t('newSession.llmAssignment.model')}" style="width:130px;padding:4px 6px;font-size:12px;" value="${escHtml(teamAssign.red?.model || '')}" data-action="set-team-model" data-team="red">
              </div>
            </div>
          ` : `<div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${t('newSession.llmAssignment.teamNotAvailable')}</div>`;

          const agentRows = agentsForLLM.length === 0
            ? `<div style="font-size:12px;color:var(--text-muted);margin-top:8px;">${t('newSession.llmAssignment.selectAgentsFirst')}</div>`
            : agentsForLLM.map((agId) => {
                const override = (ns.agentProviders || {})[agId] || {};
                return `
                  <div class="llm-agent-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                    <span style="font-size:12px;min-width:90px;color:var(--text-secondary);">${escHtml(agId)}</span>
                    <select class="input" style="flex:1;min-width:120px;padding:4px 6px;font-size:12px;" data-action="set-agent-provider" data-agent-id="${escHtml(agId)}">
                      ${providerOpts(override.provider_id || '')}
                    </select>
                    <input class="input" type="text" placeholder="${t('newSession.llmAssignment.model')}" style="width:130px;padding:4px 6px;font-size:12px;" value="${escHtml(override.model || '')}" data-action="set-agent-model" data-agent-id="${escHtml(agId)}">
                  </div>`;
              }).join('');

          return `
            <div class="llm-assignment-panel" style="margin-top:16px;padding:16px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border);">
              <div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:10px;letter-spacing:.03em;">
                🤖 ${t('newSession.llmAssignment.title')}
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">${t('newSession.llmAssignment.desc')}</div>
              <div class="llm-assignment-mode" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
                <button type="button" style="${modeTabStyle('global')}" data-action="set-llm-assignment-mode" data-mode="global">${t('newSession.llmAssignment.global')}</button>
                <button type="button" style="${modeTabStyle('team')}"   data-action="set-llm-assignment-mode" data-mode="team">${t('newSession.llmAssignment.team')}</button>
                <button type="button" style="${modeTabStyle('agent')}"  data-action="set-llm-assignment-mode" data-mode="agent">${t('newSession.llmAssignment.agent')}</button>
              </div>
              ${assignMode === 'global' ? `<div style="font-size:12px;color:var(--text-muted);">${t('newSession.llmAssignment.globalDesc')}</div>` : ''}
              ${assignMode === 'team'  ? teamRows : ''}
              ${assignMode === 'agent' ? `<div class="llm-assignment-grid" style="margin-top:10px;">${agentRows}</div>` : ''}
            </div>`;
        })()}
      ` : ''}

      </div>
      <button class="btn btn-primary" data-action="launch-session" ${state.isLoading ? 'disabled' : ''}>
        ${state.isLoading ? '<span class="spinner"></span>' : ''}
        ${continueLabel}
      </button>
    </div>

    ${renderContextDocumentSection()}
  `;
}

function registerNewSessionFeature() {
  window.DecisionArena.views['new-session'] = renderNewSession;
}

export { registerNewSessionFeature, renderNewSession, renderContextDocumentSection };

/**
 * New Session feature – view registration.
 */

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

function renderNewSession() {
  const { state, escHtml, t, tip } = getCtx();
  const ns = state.newSession;
  const renderTemplateCard = window.DecisionArena.views.shared.renderTemplateCard || (() => '');
  const allModes = ['chat', 'decision-room', 'confrontation', 'quick-decision', 'stress-test', 'jury'];
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
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:2px;"><span>1</span><span>5</span><span>10</span><span>15</span></div>
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

  const scenarioSection = (() => {
    const packs = state.scenarioPacks || [];
    if (!packs.length) return '';
    const modeIcon = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥', jury: '⚖️' };
    const cards = packs.map((pack) => {
      const sel = ns.selectedScenarioId === pack.id;
      const isLarge = pack.max_personas && pack.max_personas > 10;
      return `
        <div class="scenario-pack-card ${sel ? 'selected' : ''}"
             data-action="apply-scenario" data-scenario-id="${escHtml(pack.id)}"
             role="button" tabindex="0" aria-pressed="${sel}">
          <span class="scenario-pack-card-badge">${modeIcon[pack.recommended_mode] || '🎯'}</span>
          <div class="scenario-pack-card-target">${escHtml(pack.target_profile || '')}</div>
          <div class="scenario-pack-card-name">${escHtml(pack.name)}</div>
          <div class="scenario-pack-card-desc">${escHtml(pack.description || '')}</div>
          ${isLarge ? `<div class="scenario-pack-card-hint" style="color:var(--warning,#f59e0b);">⚠️ ${t('scenario.warningLarge')}</div>` : `<div class="scenario-pack-card-hint">${t('scenario.hint')}</div>`}
        </div>
      `;
    }).join('');

    const appliedBanner = ns.selectedScenarioId ? `
      <div style="margin-top:8px;font-size:12px;color:var(--success,#10b981);display:flex;align-items:center;gap:8px;">
        ✅ ${t('scenario.applied')}
        <button class="btn btn-secondary btn-sm" data-action="clear-scenario" style="font-size:11px;padding:2px 8px;">
          ${t('scenario.clearSelection')}
        </button>
      </div>
    ` : '';

    return `
      <div class="section" style="margin-bottom:24px;max-width:1100px;width:100%;">
        <div class="section-header">
          <span class="section-label">${t('scenario.sectionTitle')}</span>
        </div>
        <div class="card-description" style="margin-bottom:10px;">${t('scenario.sectionDesc')}</div>
        <div class="scenario-packs-grid">${cards}</div>
        ${appliedBanner}
      </div>
    `;
  })();

  return `
    <div class="page-header">
      <div class="page-title">${t('newSession.title')}</div>
      <div class="page-subtitle">${t('newSession.subtitle')}</div>
    </div>

    ${state.templates.length > 0 ? `
      <div class="section" style="margin-bottom:28px;">
        <div class="section-header"><span class="section-label">${t('newSession.templates')}</span></div>
        <div class="templates-grid">${state.templates.map(renderTemplateCard).join('')}</div>
      </div>
    ` : ''}

    ${scenarioSection}

    <div class="card" style="max-width:1100px;width:100%;">
      <div class="form-group">
        <label for="ns-title">${t('newSession.sessionTitle')}</label>
        <input class="input" id="ns-title" type="text" placeholder="${t('newSession.titlePlaceholder')}" value="${escHtml(ns.title)}" data-field="title">
      </div>
      <div class="form-group">
        <label for="ns-idea">${t('newSession.idea')}</label>
        <textarea class="textarea" id="ns-idea" placeholder="${t('newSession.ideaPlaceholder')}" style="min-height:120px;" data-field="idea">${escHtml(ns.idea)}</textarea>
      </div>
      <div class="form-group">
        <label>${t('newSession.responseLanguage')}</label>
        <div class="language-selector">
          <button class="language-option ${ns.language === 'en' ? 'active' : ''}" data-action="select-language" data-lang="en">🇬🇧 English</button>
          <button class="language-option ${ns.language === 'fr' ? 'active' : ''}" data-action="select-language" data-lang="fr">🇫🇷 Français</button>
        </div>
      </div>
      <div class="form-group">
        <label>${t('newSession.mode')} ${tip(t('tooltip.sessionMode'))}</label>
        <div class="mode-selector">
          <label class="mode-option ${ns.mode === 'chat' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="chat" ${ns.mode === 'chat' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.chat')}</div><div class="mode-option-desc">${t('mode.chatDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'decision-room' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="decision-room" ${ns.mode === 'decision-room' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.decisionRoom')}</div><div class="mode-option-desc">${t('mode.decisionRoomDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'confrontation' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="confrontation" ${ns.mode === 'confrontation' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.confrontation')}</div><div class="mode-option-desc">${t('mode.confrontationDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'quick-decision' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="quick-decision" ${ns.mode === 'quick-decision' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.quickDecision')}</div><div class="mode-option-desc">${t('mode.quickDecisionDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'stress-test' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="stress-test" ${ns.mode === 'stress-test' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('mode.stressTest')}</div><div class="mode-option-desc">${t('mode.stressTestDesc')}</div></div></label>
          <label class="mode-option ${ns.mode === 'jury' ? 'selected' : ''}"><input type="radio" name="ns-mode" value="jury" ${ns.mode === 'jury' ? 'checked' : ''} data-field="mode"><div><div class="mode-option-label">${t('jury.title')}</div><div class="mode-option-desc">${t('jury.desc')}</div></div></label>
        </div>
      </div>

      ${(() => {
        const usageKey = { chat: 'mode.chatUsage', 'decision-room': 'mode.decisionRoomUsage', confrontation: 'mode.confrontationUsage', 'quick-decision': 'mode.quickDecisionUsage', 'stress-test': 'mode.stressTestUsage', jury: 'mode.juryUsage' }[ns.mode];
        return usageKey ? `<div class="card-usage" style="margin-bottom:14px;font-size:12px;">👉 ${t(usageKey)}</div>` : '';
      })()}

      ${ns.mode === 'stress-test' ? `<div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;padding:8px 12px;background:rgba(239,68,68,0.07);border-radius:6px;border:1px solid rgba(239,68,68,0.2);">🔥 ${t('mode.stressTestHint')}</div>` : ''}

      ${ns.mode === 'jury' ? `
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;padding:8px 12px;background:rgba(99,102,241,0.07);border-radius:6px;border:1px solid rgba(99,102,241,0.2);">
          ⚖️ ${t('jury.desc')}
        </div>
      ` : ''}

      ${agentSectionHtml}

      ${['decision-room', 'stress-test', 'jury'].includes(ns.mode) ? `
        <div class="form-group" id="rounds-field">
          <label for="ns-rounds">${ns.mode === 'jury' ? t('jury.rounds') : t('newSession.rounds')} (${ns.rounds}) ${tip(t('tooltip.rounds'))}</label>
          <input class="input" id="ns-rounds" type="range" min="1" max="5" value="${ns.rounds}" data-field="rounds" style="padding:6px 0;">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:2px;"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
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
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:2px;"><span>50%</span><span>65%</span><span>80%</span><span>90%</span></div>
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
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:2px;"><span>50%</span><span>55%</span><span>65%</span><span>70%</span><span>80%</span></div>
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

      <button class="btn btn-primary" data-action="launch-session" ${state.isLoading ? 'disabled' : ''}>
        ${state.isLoading ? '<span class="spinner"></span>' : ''}
        ${submitLabel}
      </button>
    </div>

    ${renderContextDocumentSection()}
  `;
}

function registerNewSessionFeature() {
  window.DecisionArena.views['new-session'] = renderNewSession;
}

export { registerNewSessionFeature, renderNewSession, renderContextDocumentSection };

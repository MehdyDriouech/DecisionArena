/**
 * Chat feature – view functions.
 * Covers: renderChat, renderMessage, renderExportButtons,
 *         renderAgentChatPanel, renderDRAgentMessage.
 *
 * renderContextDocBadge / renderContextDocPanel are shared helpers
 * imported from src/ui/contextDoc.js and exposed via window.DecisionArena.views.shared.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderDecisionBrief } from '../../ui/components.js';
import { formatHitlMessageBadges, formatRerunWithChallengeButton } from '../../utils/messageLookup.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, formatDate, agentIcon: _ai, agentName: _an, agentTitleText: _at } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon      = (id) => _ai(state.personas, id);
  const agentName      = (id) => _an(state.personas, id);
  const agentTitleText = (id) => _at(state.personas, id);
  return { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, agentTitleText, t };
}

/* ===== SHARED: message renderers ===== */

function renderMessage(msg) {
  const { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, agentTitleText, t } = getCtx();

  if (msg.role === 'user') {
    const hitl = formatHitlMessageBadges(msg, t, escHtml);
    const rerun = formatRerunWithChallengeButton(state.currentSession?.id, msg, t, escHtml);
    return `
      <div class="message-user">
        ${hitl}
        ${escHtml(msg.content)}
        <div class="message-user-meta">${formatDate(msg.created_at)}</div>
        ${rerun}
      </div>
    `;
  }
  const icon     = agentIcon(msg.agent_id);
  const name     = agentName(msg.agent_id);
  const titleTxt = agentTitleText(msg.agent_id);
  const chBtn = window.DecisionArena?.utils?.canChallengeMessage?.(msg)
    ? `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;font-size:11px;" data-action="challenge-claim" data-message-id="${escHtml(String(msg.id))}">${escHtml(t('hitl.challenge'))}</button>`
    : '';
  const hitlAgent = formatHitlMessageBadges(msg, t, escHtml);
  return `
    <div class="message-agent">
      <div class="message-agent-header">
        <span style="font-size:20px;">${icon}</span>
        <div>
          <div class="message-agent-name">${escHtml(name)}</div>
          ${titleTxt ? `<div style="font-size:11px;color:var(--text-muted);">${escHtml(titleTxt)}</div>` : ''}
        </div>
      </div>
      ${hitlAgent}
      <div class="message-agent-body md-content">${renderMarkdown(msg.content)}</div>
      <div class="message-agent-footer">
        ${msg.provider_id ? `<span>${t('label.provider')}: ${escHtml(msg.provider_id)}</span>` : ''}
        ${msg.model ? `<span>${t('label.model')}: ${escHtml(msg.model)}</span>` : ''}
        <span style="margin-left:auto;">${formatDate(msg.created_at)}</span>
      </div>
      ${chBtn}
    </div>
  `;
}

/** Agent card used in DR / confrontation / stress-test results. */
function renderDRAgentMessage(msg, isSynth = false, messageKey = '') {
  const { state, escHtml, renderMarkdown, agentIcon, agentName, t } = getCtx();
  const icon = agentIcon(msg.agent_id);
  const name = agentName(msg.agent_id);
  const messageId = String(msg.id || messageKey || `${msg.agent_id || 'agent'}-${msg.created_at || Date.now()}`);
  const isLong = String(msg.content || '').length > 650;
  const collapsed = !!state.collapsedMessages?.[messageId];
  const preview = escHtml(String(msg.content || '').slice(0, 320));
  const contentHtml = isLong && collapsed
    ? `<div class="agent-content md-content"><p>${preview}…</p></div>`
    : `<div class="agent-content md-content">${renderMarkdown(msg.content || '')}</div>`;
  const chBtn = window.DecisionArena?.utils?.canChallengeMessage?.(msg)
    ? `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;font-size:11px;" data-action="challenge-claim" data-message-id="${escHtml(String(msg.id))}">${escHtml(t('hitl.challenge'))}</button>`
    : '';
  const hitl = formatHitlMessageBadges(msg, t, escHtml);
  return `
    <div class="agent-card ${isSynth ? 'synthesis-card' : ''}">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="agent-name">${escHtml(name)}</div>
        </div>
        ${isSynth ? `<span class="badge badge-success" style="font-size:11px;">✨ ${t('dr.synthesis')}</span>` : ''}
      </div>
      ${hitl}
      ${contentHtml}
      ${isLong ? `<button class="btn btn-secondary btn-sm" data-action="toggle-agent-message" data-message-id="${escHtml(messageId)}">${collapsed ? 'Voir' : 'Masquer'}</button>` : ''}
      ${chBtn}
    </div>
  `;
}

/* ===== SHARED: export buttons ===== */

function renderExportButtons(sessionId) {
  const { state, escHtml, t } = getCtx();
  const snapStatus = state.snapshotStatus;
  const uiC = state.uiComplexity || 'advanced';
  const isExpert = uiC === 'expert';
  return `
    <button class="btn btn-secondary btn-sm" data-action="save-snapshot" data-session-id="${escHtml(sessionId)}" title="${t('export.saveSnapshot')}">
      ${t('export.saveSnapshot')}
    </button>
    <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(sessionId)}" data-format="markdown" title="${t('export.exportMd')}">
      ${t('export.exportMd')}
    </button>
    <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(sessionId)}" data-format="json" title="${t('export.exportJson')}">
      ${t('export.exportJson')}
    </button>
    <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(sessionId)}" data-format="markdown" data-redacted="1" title="${t('export.redacted')}">
      🔒 ${t('export.redacted')}
    </button>
    ${isExpert ? `
    <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(sessionId)}" data-format="json" data-redacted="1" title="${t('export.redactedJson')}">
      🔒 ${t('export.redactedJson')}
    </button>
    <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(sessionId)}" data-format="markdown" data-redacted="strong" title="${t('export.redactedStrong')}">
      🔐 ${t('export.redactedStrong')}
    </button>` : ''}
    ${snapStatus ? `<span class="snapshot-status ${snapStatus.ok ? 'ok' : 'fail'}">${escHtml(snapStatus.ok ? t('export.snapshotSaved') : t('export.snapshotError'))}</span>` : ''}
  `;
}

/* ===== SHARED: follow-up agent panel ===== */

function renderAgentChatPanel(contextMode) {
  const { state, escHtml, t } = getCtx();
  const session  = state.currentSession;
  if (!session) return '';
  const messages = state.followUpMessages;

  return `
    <div class="followup-panel">
      <div class="followup-panel-header">
        <span>💬 ${t('followup.title')}</span>
      </div>
      <div class="followup-messages" id="followup-messages">
        ${messages.length === 0 ? `
          <div class="empty-state" style="padding:16px 0;font-size:13px;">
            <div class="empty-state-text">${t('followup.empty')}</div>
          </div>
        ` : messages.map(renderMessage).join('')}
        ${state.followUpLoading ? `<div class="message-loading"><span class="spinner"></span> ${t('followup.thinking')}</div>` : ''}
      </div>
      <div class="chat-input-area" style="border-top:1px solid var(--border);padding-top:12px;">
        <div class="chat-input-row">
          <textarea class="textarea" id="followup-input" placeholder="${t('followup.placeholder')}" rows="2" data-context-mode="${escHtml(contextMode)}"></textarea>
          <button class="btn btn-primary" data-action="send-followup" ${state.followUpLoading ? 'disabled' : ''}>
            ${state.followUpLoading ? '<span class="spinner"></span>' : t('followup.send')}
          </button>
        </div>
      </div>
    </div>
  `;
}

/* ===== REACTIVE CHAT: helpers ===== */

function renderReactiveRoleBadge(role, t) {
  if (!role) return '';
  const cfg = {
    primary:     { cls: 'badge-info',    label: t('chat.reactive.primary') },
    reactor:     { cls: 'badge-warning', label: t('chat.reactive.reactor') },
    synthesizer: { cls: 'badge-success', label: `✨ ${t('chat.reactive.synthesizer')}` },
  };
  const c = cfg[role];
  if (!c) return '';
  return `<span class="reactive-role-badge badge ${c.cls}" style="font-size:10px;">${c.label}</span>`;
}

function renderReactiveMessage(msg) {
  const { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, t } = getCtx();
  if (msg.role === 'user') {
    const hitl = formatHitlMessageBadges(msg, t, escHtml);
    const rerun = formatRerunWithChallengeButton(state.currentSession?.id, msg, t, escHtml);
    return `<div class="message-user">${hitl}${escHtml(msg.content)}<div class="message-user-meta">${formatDate(msg.created_at)}</div>${rerun}</div>`;
  }
  const icon  = agentIcon(msg.agent_id);
  const name  = agentName(msg.agent_id);
  const role  = msg.reaction_role || null;
  const roleBadge  = renderReactiveRoleBadge(role, t);
  const providerLabel = msg.provider_name || msg.provider_id || null;
  const modelLabel    = msg.model || null;
  const hasFallback   = msg.provider_fallback_used == 1;
    const provBadge = (providerLabel || modelLabel)
    ? `<span class="message-llm-meta provider-badge" style="font-size:10px;">${modelLabel ? escHtml(modelLabel) : ''}${providerLabel ? ` via ${escHtml(providerLabel)}` : ''}${hasFallback ? ` <span class="message-llm-fallback">⚠ ${t('message.llm.fallback')}</span>` : ''}</span>`
    : '';
    const chBtn = window.DecisionArena?.utils?.canChallengeMessage?.(msg)
      ? `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;font-size:11px;" data-action="challenge-claim" data-message-id="${escHtml(String(msg.id))}">${escHtml(t('hitl.challenge'))}</button>`
      : '';
    const hitl = formatHitlMessageBadges(msg, t, escHtml);
    return `
    <div class="message-agent" style="border-left:3px solid ${role === 'reactor' ? 'var(--warning,#f59e0b)' : role === 'synthesizer' ? 'var(--success,#10b981)' : 'var(--accent)'};">
      <div class="message-agent-header">
        <span style="font-size:20px;">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="message-agent-name">${escHtml(name)}</div>
        </div>
        ${roleBadge}
      </div>
      ${hitl}
      <div class="message-agent-body md-content">${renderMarkdown(msg.content)}</div>
      <div class="message-agent-footer">
        ${provBadge}
        <span style="margin-left:auto;">${formatDate(msg.created_at)}</span>
      </div>
      ${chBtn}
    </div>`;
}

function renderReactiveThread(thread) {
  const { t } = getCtx();
  if (!thread) return '';

  // Group messages by turn (excluding synthesizer turn)
  const byTurn = {};
  const synthMsgs = [];
  (thread.messages || []).forEach((msg) => {
    if (msg.reaction_role === 'synthesizer') { synthMsgs.push(msg); return; }
    const turn = msg.thread_turn || 1;
    if (!byTurn[turn]) byTurn[turn] = [];
    byTurn[turn].push(msg);
  });

  const earlyBanner = thread.early_stopped ? `
    <div style="padding:8px 12px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:6px;font-size:12px;color:#d97706;margin-bottom:12px;">
      ⚡ ${t('chat.reactive.earlyStopped')} — ${t('chat.reactive.earlyStopReason.' + (thread.early_stop_reason || 'noNewArguments'))}
    </div>` : '';

  const turnGroups = Object.keys(byTurn).sort((a, b) => Number(a) - Number(b)).map((turn) => {
    const msgs = byTurn[turn];
    return `
      <div class="reactive-turn-group" style="margin-bottom:16px;">
        <div class="reactive-turn-title">
          <span class="badge badge-muted" style="font-size:11px;">⟳ ${t('chat.reactive.turn')} ${turn}</span>
        </div>
        <div style="padding-left:4px;margin-top:6px;">${msgs.map(renderReactiveMessage).join('')}</div>
      </div>`;
  }).join('');

  const synthSection = synthMsgs.length > 0 ? `
    <div class="reactive-synthesis-card" style="margin-top:8px;padding:16px;background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.2);border-radius:8px;">
      <div style="font-weight:600;font-size:13px;color:#059669;margin-bottom:10px;">✨ ${t('chat.reactive.synthesizer')}</div>
      ${synthMsgs.map(renderReactiveMessage).join('')}
    </div>` : '';

  return `
    <div class="reactive-thread-result" style="padding:16px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border);margin-bottom:16px;">
      <div style="font-weight:600;font-size:13px;margin-bottom:12px;color:var(--text-secondary);">
        🔄 Reactive Chat — ${thread.turns_executed} ${t('chat.reactive.turn')}(s)
      </div>
      ${earlyBanner}
      ${turnGroups}
      ${synthSection}
    </div>`;
}

/** Reactive Chat config panel rendered inside the chat view */
function renderReactiveChatPanel() {
  const { state, escHtml, t } = getCtx();
  const rc = state.reactiveChat || {};
  const session = state.currentSession;
  if (!session) return '';

  const agentIds = session.selected_agents || [];
  const personas = state.personas || [];
  const agentName = (id) => {
    const p = personas.find((x) => x.id === id);
    return p ? p.name : id;
  };

  const primaryOpts = agentIds.map((id) =>
    `<option value="${escHtml(id)}" ${rc.primaryAgentId === id ? 'selected' : ''}>${escHtml(agentName(id))}</option>`
  ).join('');

  const reactorCheckboxes = agentIds.filter((id) => id !== rc.primaryAgentId).map((id) => {
    const sel = (rc.reactorAgentIds || []).includes(id);
    return `<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
      <input type="checkbox" ${sel ? 'checked' : ''} data-action="toggle-reactive-reactor-agent" data-agent-id="${escHtml(id)}" style="accent-color:var(--accent);">
      ${escHtml(agentName(id))}
    </label>`;
  }).join('');

  const modeOpt = (v, key) => `<option value="${v}" ${(rc.reactorMode || 'independent') === v ? 'selected' : ''}>${t(key)}</option>`;
  const intOpt  = (v, key) => `<option value="${v}" ${(rc.debateIntensity || 'medium') === v ? 'selected' : ''}>${t(key)}</option>`;
  const styOpt  = (v, key) => `<option value="${v}" ${(rc.reactionStyle || 'critical') === v ? 'selected' : ''}>${t(key)}</option>`;

  const uiC = state.uiComplexity || 'advanced';
  const isBasic  = uiC === 'basic';
  const isExpert = uiC === 'expert';

  const presetsHtml = `
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">${t('reactive.preset.label')} :</span>
      <button class="btn btn-secondary btn-sm" style="font-size:11px;" data-action="apply-reactive-preset" data-preset="minimal">${t('reactive.preset.minimal')}</button>
      <button class="btn btn-secondary btn-sm" style="font-size:11px;" data-action="apply-reactive-preset" data-preset="standard">${t('reactive.preset.standard')}</button>
      <button class="btn btn-secondary btn-sm" style="font-size:11px;" data-action="apply-reactive-preset" data-preset="intense">${t('reactive.preset.intense')}</button>
    </div>`;

  const advancedParamsHtml = isBasic ? '' : `
        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.turnsMin')} (${rc.turnsMin || 2})</label>
          <input type="range" class="input" min="1" max="10" value="${rc.turnsMin || 2}" style="padding:4px 0;" data-action="set-reactive-turns-min">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);"><span>1</span><span>5</span><span>10</span></div>
        </div>

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.turnsMax')} (${rc.turnsMax || 4})</label>
          <input type="range" class="input" min="1" max="10" value="${rc.turnsMax || 4}" style="padding:4px 0;" data-action="set-reactive-turns-max">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);"><span>1</span><span>5</span><span>10</span></div>
        </div>

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.intensity')}</label>
          <select class="input" style="font-size:12px;padding:4px 6px;" data-action="set-reactive-debate-intensity">
            ${intOpt('low',    'chat.reactive.intensity.low')}
            ${intOpt('medium', 'chat.reactive.intensity.medium')}
            ${intOpt('high',   'chat.reactive.intensity.high')}
          </select>
        </div>

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.style')}</label>
          <select class="input" style="font-size:12px;padding:4px 6px;" data-action="set-reactive-reaction-style">
            ${styOpt('complementary',  'chat.reactive.style.complementary')}
            ${styOpt('critical',       'chat.reactive.style.critical')}
            ${styOpt('contradictory',  'chat.reactive.style.contradictory')}
            ${styOpt('review',         'chat.reactive.style.review')}
          </select>
        </div>`;

  const expertParamsHtml = !isExpert ? '' : `
        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.reactorMode')}</label>
          <select class="input" style="font-size:12px;padding:4px 6px;" data-action="set-reactive-reactor-mode">
            ${modeOpt('independent', 'chat.reactive.reactorMode.independent')}
            ${modeOpt('sequential',  'chat.reactive.reactorMode.sequential')}
            ${modeOpt('collective',  'chat.reactive.reactorMode.collective')}
          </select>
        </div>

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">${t('chat.reactive.earlyStop')}</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
            <input type="checkbox" ${rc.earlyStopEnabled !== false ? 'checked' : ''} data-action="toggle-reactive-early-stop" style="accent-color:var(--accent);">
            ${t('chat.reactive.earlyStop')}
          </label>
          ${rc.earlyStopEnabled !== false ? `
            <div style="margin-top:6px;">
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">${t('chat.reactive.confidenceThreshold')} (${((rc.earlyStopConfidenceThreshold || 0.85)*100).toFixed(0)}%)</div>
              <input type="range" class="input" min="0.60" max="0.95" step="0.05" value="${rc.earlyStopConfidenceThreshold || 0.85}" style="padding:4px 0;" data-action="set-reactive-confidence-threshold">
            </div>
            <div style="margin-top:4px;">
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px;">${t('chat.reactive.noNewArguments')} (${rc.noNewArgumentsThreshold || 2})</div>
              <input type="range" class="input" min="1" max="3" value="${rc.noNewArgumentsThreshold || 2}" style="padding:4px 0;" data-action="set-reactive-no-new-arguments-threshold">
            </div>
          ` : ''}
        </div>`;

  return `
    <div class="reactive-chat-panel" style="margin-bottom:16px;padding:14px 16px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border);">
      <div style="font-weight:600;font-size:13px;margin-bottom:10px;color:var(--text-secondary);">🔄 ${t('chat.reactive.title')}</div>

      ${presetsHtml}

      <div class="reactive-chat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.primaryAgent')}</label>
          <select class="input" style="font-size:12px;padding:4px 6px;" data-action="set-reactive-primary-agent">
            <option value="">— ${t('chat.reactive.primaryAgent')}</option>
            ${primaryOpts}
          </select>
        </div>

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">${t('chat.reactive.reactors')}</label>
          <div style="display:flex;flex-direction:column;gap:4px;">${reactorCheckboxes || `<span style="font-size:11px;color:var(--text-muted);">${t('chat.reactive.selectPrimaryFirst')}</span>`}</div>
        </div>

        ${advancedParamsHtml}
        ${expertParamsHtml}

        <div class="reactive-chat-option">
          <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">${t('chat.reactive.synthesis')}</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
            <input type="checkbox" ${rc.includeFinalSynthesis !== false ? 'checked' : ''} data-action="toggle-reactive-synthesis" style="accent-color:var(--accent);">
            ${t('chat.reactive.synthesis')}
          </label>
        </div>

      </div>
    </div>`;
}

/* ===== VIEW: CHAT ===== */

function renderChat() {
  const { state, escHtml, t } = getCtx();
  const session  = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;

  const agentIds = session.selected_agents || [];
  const { agentIcon, agentName } = getCtx();
  const messages = state.currentMessages;
  const rc = state.reactiveChat || {};
  const rcEnabled = !!rc.enabled;
  const rcRunning = !!rc.running;
  const rcError   = rc.error || null;
  const rcResults = state.reactiveChatResults || null;
  let brief = null;
  if (session?.decision_brief && typeof session.decision_brief === 'object') brief = session.decision_brief;
  if (!brief && session?.decision_brief && typeof session.decision_brief === 'string') {
    try { brief = JSON.parse(session.decision_brief); } catch (_) {}
  }

  // Determine send button label / action
  const sendAction = rcEnabled ? 'send-reactive-message' : 'send-message';
  const sendLabel  = rcEnabled ? t('chat.reactive.send') : t('chat.send');
  const isLoading  = state.isLoading || rcRunning;

  return `
    <div class="full-height-view">
      <div class="chat-header">
        <div style="flex:1;min-width:0;">
          <div class="chat-header-title">${escHtml(session.title || 'Untitled')}</div>
          <div class="chat-agents-badges" style="margin-top:6px;">
            ${agentIds.map((id) => `
              <span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>
            `).join('')}
            ${renderContextDocBadge()}
          </div>
        </div>
        <div class="export-actions">
          ${renderExportButtons(session.id)}
        </div>
        <button class="btn btn-secondary btn-sm" data-action="open-decision-room" data-session-id="${escHtml(session.id)}">
          ${t('chat.decisionRoom')}
        </button>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderContextDocPanel()}

      <!-- Reactive Chat toggle -->
      <div style="padding:8px 0;margin-bottom:4px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;">
          <input type="checkbox" ${rcEnabled ? 'checked' : ''} data-action="toggle-reactive-chat" style="width:16px;height:16px;accent-color:var(--accent);">
          🔄 ${t('chat.reactive.enabled')}
          <span style="font-size:11px;color:var(--text-muted);">${t('chat.reactive.desc')}</span>
        </label>
      </div>

      ${rcEnabled ? renderReactiveChatPanel() : ''}

      ${rcError ? `<div style="padding:8px 12px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:6px;font-size:12px;color:#dc2626;margin-bottom:8px;">${escHtml(rcError)}</div>` : ''}
      ${renderDecisionBrief(brief, { sessionId: session.id })}

      <div class="messages-timeline" id="messages-timeline">
        ${messages.length === 0 && !rcResults ? `
          <div class="empty-state">
            <div class="empty-state-icon">💬</div>
            <div class="empty-state-text">${t('chat.empty')}</div>
          </div>
        ` : messages.map(renderMessage).join('')}

        ${rcResults ? renderReactiveThread(rcResults) : ''}

        ${isLoading ? `<div class="message-loading"><span class="spinner"></span> ${t(rcRunning ? 'chat.reactive.running' : 'chat.thinking')}</div>` : ''}
      </div>

      <div class="chat-input-area">
        <div class="chat-input-row">
          <textarea class="textarea" id="chat-input" placeholder="${t(rcEnabled ? 'chat.reactive.placeholder' : 'chat.placeholder')}" rows="2" ${isLoading ? 'disabled' : ''}></textarea>
          ${isLoading
            ? `<button class="btn btn-danger" data-action="stop-generation" title="${t('chat.stop')}">
                ⏹ ${t('chat.stop')}
               </button>`
            : `<button class="btn btn-primary" data-action="${sendAction}">
                ${sendLabel}
               </button>`
          }
        </div>
        <div class="chat-input-hint">${t('chat.hint')}</div>
      </div>
    </div>
  `;
}

export {
  renderChat,
  renderMessage,
  renderReactiveMessage,
  renderReactiveThread,
  renderDRAgentMessage,
  renderExportButtons,
  renderAgentChatPanel,
};

/**
 * Chat feature – view functions.
 * Covers: renderChat, renderMessage, renderExportButtons,
 *         renderAgentChatPanel, renderDRAgentMessage.
 *
 * renderContextDocBadge / renderContextDocPanel are shared helpers
 * imported from src/ui/contextDoc.js and exposed via window.DecisionArena.views.shared.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';

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
  const { escHtml, renderMarkdown, formatDate, agentIcon, agentName, agentTitleText, t } = getCtx();

  if (msg.role === 'user') {
    return `
      <div class="message-user">
        ${escHtml(msg.content)}
        <div class="message-user-meta">${formatDate(msg.created_at)}</div>
      </div>
    `;
  }
  const icon     = agentIcon(msg.agent_id);
  const name     = agentName(msg.agent_id);
  const titleTxt = agentTitleText(msg.agent_id);
  return `
    <div class="message-agent">
      <div class="message-agent-header">
        <span style="font-size:20px;">${icon}</span>
        <div>
          <div class="message-agent-name">${escHtml(name)}</div>
          ${titleTxt ? `<div style="font-size:11px;color:var(--text-muted);">${escHtml(titleTxt)}</div>` : ''}
        </div>
      </div>
      <div class="message-agent-body md-content">${renderMarkdown(msg.content)}</div>
      <div class="message-agent-footer">
        ${msg.provider_id ? `<span>${t('label.provider')}: ${escHtml(msg.provider_id)}</span>` : ''}
        ${msg.model ? `<span>${t('label.model')}: ${escHtml(msg.model)}</span>` : ''}
        <span style="margin-left:auto;">${formatDate(msg.created_at)}</span>
      </div>
    </div>
  `;
}

/** Agent card used in DR / confrontation / stress-test results. */
function renderDRAgentMessage(msg, isSynth = false) {
  const { escHtml, renderMarkdown, agentIcon, agentName, t } = getCtx();
  const icon = agentIcon(msg.agent_id);
  const name = agentName(msg.agent_id);
  return `
    <div class="agent-card ${isSynth ? 'synthesis-card' : ''}">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="agent-name">${escHtml(name)}</div>
        </div>
        ${isSynth ? `<span class="badge badge-success" style="font-size:11px;">✨ ${t('dr.synthesis')}</span>` : ''}
      </div>
      <div class="agent-content md-content">${renderMarkdown(msg.content || '')}</div>
    </div>
  `;
}

/* ===== SHARED: export buttons ===== */

function renderExportButtons(sessionId) {
  const { state, escHtml, t } = getCtx();
  const snapStatus = state.snapshotStatus;
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

/* ===== VIEW: CHAT ===== */

function renderChat() {
  const { state, escHtml, t } = getCtx();
  const session  = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;

  const agentIds = session.selected_agents || [];
  const { agentIcon, agentName } = getCtx();
  const messages = state.currentMessages;

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

      <div class="messages-timeline" id="messages-timeline">
        ${messages.length === 0 ? `
          <div class="empty-state">
            <div class="empty-state-icon">💬</div>
            <div class="empty-state-text">${t('chat.empty')}</div>
          </div>
        ` : messages.map(renderMessage).join('')}
        ${state.isLoading ? `<div class="message-loading"><span class="spinner"></span> ${t('chat.thinking')}</div>` : ''}
      </div>

      <div class="chat-input-area">
        <div class="chat-input-row">
          <textarea class="textarea" id="chat-input" placeholder="${t('chat.placeholder')}" rows="2" ${state.isLoading ? 'disabled' : ''}></textarea>
          ${state.isLoading
            ? `<button class="btn btn-danger" data-action="stop-generation" title="${t('chat.stop')}">
                ⏹ ${t('chat.stop')}
               </button>`
            : `<button class="btn btn-primary" data-action="send-message">
                ${t('chat.send')}
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
  renderDRAgentMessage,
  renderExportButtons,
  renderAgentChatPanel,
};

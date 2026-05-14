import {
  ensureOpenSpaceState,
  renderOpenSpaceContextSwitcher,
  renderContextRequiredEmptyState,
  selectedContextFromState,
  t,
  esc,
} from './shared.js';

function formatContextAgentOptionLabel(agent) {
  const id = String(agent.agent_id || '').trim().toLowerCase();
  const name = String(agent.agent_name || agent.display_name || id);
  const bits = [];
  if (agent.participated) bits.push(t('openspace.agentChat.lblParticipated'));
  if (agent.memory_md_exists) bits.push(t('openspace.agentChat.lblMemoryMd'));
  if (agent.participation_memory_synced) bits.push(t('openspace.agentChat.lblSyncParticipation'));
  if (agent.decision_memory_synced) bits.push(t('openspace.agentChat.lblSyncDm'));
  if (agent.explicit_openspace_context) bits.push(t('openspace.agentChat.lblManualContext'));
  const tail = bits.length ? ` — ${bits.join(', ')}` : '';
  return `${name} (${id})${tail}`;
}

function renderMemoryBadges(meta) {
  if (!meta) return '';
  const st = String(meta.memory_md_state || '');
  const flags = meta.memory_md_flags && typeof meta.memory_md_flags === 'object' ? meta.memory_md_flags : {};
  const items = [];
  if (st === 'template_only' || flags.template_only) {
    items.push(`<span class="openspace-tag">${esc(t('openspace.agentChat.badgeTemplateSync'))}</span>`);
  }
  if (st === 'participation_sync' || flags.participation_sync) {
    items.push(`<span class="openspace-tag">${esc(t('openspace.agentChat.badgeSyncParticipationShort'))}</span>`);
  }
  if (st === 'decision_memory_sync' || flags.decision_memory_sync) {
    items.push(`<span class="openspace-tag">${esc(t('openspace.agentChat.badgeSyncDmShort'))}</span>`);
  }
  if (st === 'missing') {
    items.push(`<span class="openspace-tag">${esc(t('openspace.agentChat.badgeMissing'))}</span>`);
  }
  return items.join(' ');
}

function renderExpertDiagnostics(state, os) {
  if (state.uiMode !== 'expert' || !os.lastChatDiagnostics) return '';
  const d = os.lastChatDiagnostics;
  const lines = Object.entries(d).map(([k, v]) => `${k}: ${v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)}`);
  return `
    <details class="card openspace-card openspace-expert-diag" data-ui="expert-only" style="margin-bottom:12px;">
      <summary style="cursor:pointer;font-weight:700;">${esc(t('openspace.agentChat.expertDiagTitle'))}</summary>
      <pre class="openspace-agent-memory-pre" style="margin-top:10px;">${esc(lines.join('\n'))}</pre>
    </details>
  `;
}

function renderOpenSpaceAgentChat() {
  const state = window.DecisionArena.store.state;
  const os = ensureOpenSpaceState(state);
  const selectedContextId = selectedContextFromState(state);
  const switcher = renderOpenSpaceContextSwitcher(state);

  if (!selectedContextId) {
    return `
      <div class="page-header"><div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.agentChat'))}</div></div>
      ${switcher}
      ${renderContextRequiredEmptyState()}
    `;
  }

  const agents = Array.isArray(os.contextChatAgents) ? os.contextChatAgents : [];
  const personas = Array.isArray(state.personas) ? state.personas : [];
  const inCtx = new Set(agents.map((a) => String(a.agent_id || '').trim().toLowerCase()));
  const addCandidates = personas.filter((p) => {
    const id = String(p.id || '').trim().toLowerCase();
    return id && !inCtx.has(id) && p.enabled !== false;
  });

  const tasks = os.tasks.filter((row) => String(row.strategic_context_id || '') === String(selectedContextId));
  const selectedTask = tasks.find((task) => String(task.id) === String(os.selectedTaskId || '')) || null;

  const agentSelect =
    agents.length === 0
      ? `<div class="openspace-warning-banner">${esc(t('openspace.agentChat.emptyAgents'))}</div>
         <button type="button" class="btn btn-primary btn-sm" data-action="open-space-open-add-agent-modal">${esc(t('openspace.agentChat.addAgentCta'))}</button>`
      : `<select class="select openspace-select" data-action="open-space-select-agent">
          <option value="">${esc(t('openspace.agentChat.selectAgent'))}</option>
          ${agents
            .map((agent) => {
              const id = String(agent.agent_id || '').trim().toLowerCase();
              const label = formatContextAgentOptionLabel(agent);
              return `<option value="${esc(id)}" ${os.selectedAgentId === id ? 'selected' : ''}>${esc(label)}</option>`;
            })
            .join('')}
        </select>`;

  const memoryBlock = `
    <details class="card openspace-card"${os.agentMemoryPanelOpen ? ' open' : ''}>
      <summary style="cursor:pointer;font-weight:700;">${esc(t('openspace.agentChat.memoryCardTitle'))}</summary>
      <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        ${renderMemoryBadges(os.agentMemoryMeta)}
        ${os.agentMemoryLoading ? `<span class="badge badge-muted">${esc(t('openspace.agentChat.loading'))}</span>` : ''}
      </div>
      <pre class="openspace-agent-memory-pre" style="margin-top:10px;max-height:320px;overflow:auto;">${esc(os.agentMemoryContent || t('openspace.agentChat.memoryPlaceholder'))}</pre>
      <div class="openspace-actions-row">
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-refresh-agent-memory">${esc(t('openspace.agentChat.refreshMemory'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-copy-agent-memory">${esc(t('openspace.agentChat.copyMemory'))}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-goto-context-agent-memory">${esc(t('openspace.agentChat.openInContext'))}</button>
      </div>
    </details>
  `;

  const modal =
    os.addAgentModalOpen && addCandidates.length > 0
      ? `        <div class="openspace-modal-backdrop" data-action="open-space-close-add-agent-modal">
          <div class="card openspace-card openspace-modal" onclick="event.stopPropagation();">
            <div style="font-weight:800;margin-bottom:8px;">${esc(t('openspace.agentChat.addAgentTitle'))}</div>
            <select id="openspace-add-agent-select" class="select openspace-select" style="width:100%;margin-bottom:12px;">
              ${addCandidates
                .map((p) => {
                  const id = String(p.id || '').trim().toLowerCase();
                  const nm = String(p.name || id);
                  return `<option value="${esc(id)}">${esc(`${nm} (${id})`)}</option>`;
                })
                .join('')}
            </select>
            <div class="openspace-actions-row">
              <button type="button" class="btn btn-primary btn-sm" data-action="open-space-confirm-add-agent">${esc(t('openspace.agentChat.addAgentSubmit'))}</button>
              <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-close-add-agent-modal">${esc(t('openspace.agentChat.addAgentCancel'))}</button>
            </div>
          </div>
        </div>`
      : os.addAgentModalOpen
        ? `<div class="openspace-modal-backdrop" data-action="open-space-close-add-agent-modal">
            <div class="card openspace-card openspace-modal" onclick="event.stopPropagation();">
              <div>${esc(t('openspace.agentChat.addAgentNoPersonas'))}</div>
              <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px;" data-action="open-space-close-add-agent-modal">${esc(t('openspace.agentChat.addAgentCancel'))}</button>
            </div>
          </div>`
        : '';

  return `
    <div class="page-header">
      <div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.agentChat'))}</div>
    </div>
    ${switcher}
    ${os.error ? `<div class="error-banner">⚠️ ${esc(os.error)}</div>` : ''}
    ${renderExpertDiagnostics(state, os)}
    <div class="card openspace-card" style="margin-bottom:12px;">
      <div class="openspace-chat-controls" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        ${agentSelect}
        <select class="select openspace-select" data-action="open-space-select-task-for-chat">
          <option value="">${esc(t('openspace.agentChat.selectTask'))}</option>
          ${tasks.map((task) => `<option value="${esc(task.id || '')}" ${String(task.id) === String(os.selectedTaskId || '') ? 'selected' : ''}>${esc(task.title || task.id || '')}</option>`).join('')}
        </select>
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-open-add-agent-modal">${esc(t('openspace.agentChat.addAgentCta'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-load-agent-memory" ${!os.selectedAgentId ? 'disabled' : ''}>${esc(t('openspace.agentChat.viewMemory'))}</button>
      </div>
      ${selectedTask ? `<div class="openspace-task-meta" style="margin-top:8px;">${esc(selectedTask.title || '')} · ${esc(t(`openspace.status.${selectedTask.status || 'backlog'}`))}</div>` : ''}
    </div>
    ${memoryBlock}
    <div class="card openspace-card">
      <div class="openspace-chat-timeline">
        ${os.messages.length === 0 ? `<div class="openspace-empty-column">${esc(t('openspace.agentChat.empty'))}</div>` : os.messages.map((msg) => `
          <div class="openspace-chat-bubble ${esc(msg.role || 'user')}">
            <div class="openspace-chat-meta">${esc(msg.role || 'user')} ${msg.agent_id ? `· ${esc(msg.agent_id)}` : ''}</div>
            <div class="openspace-chat-content">${esc(msg.content || '')}</div>
          </div>
        `).join('')}
      </div>
      <textarea rows="3" class="textarea openspace-textarea" data-action="open-space-set-field" data-field="chatInput" placeholder="${esc(t('openspace.agentChat.inputPh'))}">${esc(os.chatInput)}</textarea>
      <div class="openspace-actions-row">
        <button type="button" class="btn btn-primary btn-sm" data-action="open-space-send-message" ${!os.selectedAgentId ? 'disabled' : ''}>${esc(t('chat.send'))}</button>
      </div>
    </div>
    ${modal}
  `;
}

export { renderOpenSpaceAgentChat };

function t(key) {
  return window.i18n?.t(key) ?? key;
}

function esc(value) {
  return window.DecisionArena.utils.escHtml(value ?? '');
}

const OPEN_SPACE_STATUSES = ['backlog', 'todo', 'doing', 'testing', 'done'];

/** Libellé i18n pour source_type (ex. user → « utilisateur »). */
function formatOpenSpaceSourceLine(task) {
  const st = String(task?.source_type || 'manual').toLowerCase();
  const key = `openspace.sourceType.${st}`;
  const translated = t(key);
  const label = translated !== key ? translated : st;
  const sid = task?.source_id ? String(task.source_id).trim() : '';
  if (st === 'user' || st === 'manual') return label;
  return sid ? `${label} · ${sid}` : label;
}

function normDraftText(s) {
  return String(s ?? '').trim();
}

/** True si le brouillon diffère de la tâche serveur (champs éditables). */
function hasTaskDraftChanges(task, draft) {
  if (!task || !draft) return false;
  return (
    normDraftText(task.title) !== normDraftText(draft.title) ||
    normDraftText(task.description) !== normDraftText(draft.description) ||
    String(task.status || '').toLowerCase() !== String(draft.status || '').toLowerCase() ||
    String(task.priority || '').toLowerCase() !== String(draft.priority || '').toLowerCase() ||
    String(task.assignee_agent_id || '').trim().toLowerCase() !== String(draft.assignee_agent_id || '').trim().toLowerCase() ||
    normDraftText(task.acceptance_criteria) !== normDraftText(draft.acceptance_criteria)
  );
}

function contextIdOf(row) {
  return String(row?.context_id || row?.id || '').trim();
}

function ensureOpenSpaceState(state) {
  if (!state.openSpace || typeof state.openSpace !== 'object') {
    state.openSpace = {};
  }
  const os = state.openSpace;
  if (!Array.isArray(os.tasks)) os.tasks = [];
  if (!Array.isArray(os.messages)) os.messages = [];
  if (!Array.isArray(os.boards)) os.boards = [];
  if (!Array.isArray(os.proposals)) os.proposals = [];
  if (!Array.isArray(os.contexts)) os.contexts = [];
  if (!Array.isArray(os.agents)) os.agents = [];
  if (!Array.isArray(os.warnings)) os.warnings = [];
  if (!Object.prototype.hasOwnProperty.call(os, 'selectedContextId')) os.selectedContextId = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'activeBoardId')) os.activeBoardId = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'orchestratorProposal')) os.orchestratorProposal = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'selectedTaskId')) os.selectedTaskId = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'selectedAgentId')) os.selectedAgentId = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'loading')) os.loading = false;
  if (!Object.prototype.hasOwnProperty.call(os, 'error')) os.error = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'orchestratorObjective')) os.orchestratorObjective = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'orchestratorConstraints')) os.orchestratorConstraints = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'newTaskTitle')) os.newTaskTitle = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'newTaskDescription')) os.newTaskDescription = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'newTaskPriority')) os.newTaskPriority = 'medium';
  if (!Object.prototype.hasOwnProperty.call(os, 'newTaskStatus')) os.newTaskStatus = 'backlog';
  if (!Object.prototype.hasOwnProperty.call(os, 'filterStatus')) os.filterStatus = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'filterAgent')) os.filterAgent = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'chatInput')) os.chatInput = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'taskEditDraft')) os.taskEditDraft = null;
  if (!Array.isArray(os.contextChatAgents)) os.contextChatAgents = [];
  if (!Object.prototype.hasOwnProperty.call(os, 'agentMemoryPanelOpen')) os.agentMemoryPanelOpen = false;
  if (!Object.prototype.hasOwnProperty.call(os, 'agentMemoryLoading')) os.agentMemoryLoading = false;
  if (!Object.prototype.hasOwnProperty.call(os, 'agentMemoryContent')) os.agentMemoryContent = '';
  if (!Object.prototype.hasOwnProperty.call(os, 'agentMemoryMeta')) os.agentMemoryMeta = null;
  if (!Object.prototype.hasOwnProperty.call(os, 'addAgentModalOpen')) os.addAgentModalOpen = false;
  if (!Object.prototype.hasOwnProperty.call(os, 'lastChatDiagnostics')) os.lastChatDiagnostics = null;
  return os;
}

function resolveContexts(state) {
  const os = ensureOpenSpaceState(state);
  const fromStore = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
  if (fromStore.length > 0) {
    os.contexts = fromStore;
  }
  return os.contexts;
}

function selectedContextFromState(state) {
  const os = ensureOpenSpaceState(state);
  const explicit = String(os.selectedContextId || '').trim();
  if (explicit) return explicit;
  const active = String(state.activeStrategicContextId || state.activeStrategicContext?.context_id || '').trim();
  if (active) return active;
  const ctxs = resolveContexts(state);
  const first = contextIdOf(ctxs[0]);
  return first || '';
}

function renderOpenSpaceContextSwitcher(state) {
  const os = ensureOpenSpaceState(state);
  const contexts = resolveContexts(state);
  const selectedId = selectedContextFromState(state);
  const selected = contexts.find((c) => contextIdOf(c) === String(selectedId)) || null;
  const options = contexts.map((c) => {
    const id = contextIdOf(c);
    const isSel = id === selectedId;
    return `<option value="${esc(id)}" ${isSel ? 'selected' : ''}>${esc(c.title || id)}</option>`;
  }).join('');
  const status = selected ? String(selected.status || 'active') : '';
  const statusKey = status ? `contexts.status.${status}` : '';
  const statusLabel = status ? t(statusKey) : '';

  return `
    <div class="card openspace-card openspace-context-switcher">
      <div class="openspace-context-switcher-row">
        <label class="openspace-context-switcher-label" for="openspace-context-switcher">${esc(t('openspace.context.switch'))}</label>
        <select id="openspace-context-switcher" class="select openspace-select" data-action="open-space-select-context">
          <option value="">${esc(t('openspace.context.choose'))}</option>
          ${options}
        </select>
        <span class="badge badge-muted">${esc(statusLabel || t('contexts.status.active'))}</span>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-view-context">${esc(t('openspace.context.view'))}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-create-context">${esc(t('openspace.context.create'))}</button>
      </div>
      <div class="openspace-context-active">${esc(t('openspace.context.active'))}: <strong>${esc(selected?.title || selectedId || '—')}</strong></div>
      ${os.warnings.length ? `<div class="openspace-context-warning">${esc(os.warnings.join(' · '))}</div>` : ''}
    </div>
  `;
}

function renderContextRequiredEmptyState() {
  return `
    <div class="card" style="padding:24px;text-align:center;">
      <p style="margin:0;color:var(--text-muted);">${esc(t('openspace.context.required'))}</p>
    </div>
  `;
}

export {
  OPEN_SPACE_STATUSES,
  formatOpenSpaceSourceLine,
  hasTaskDraftChanges,
  normDraftText,
  contextIdOf,
  ensureOpenSpaceState,
  resolveContexts,
  selectedContextFromState,
  renderOpenSpaceContextSwitcher,
  renderContextRequiredEmptyState,
  t,
  esc,
};


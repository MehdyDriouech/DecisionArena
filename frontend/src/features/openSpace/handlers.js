import { registerAction, registerChangeListener, registerInputListener } from '../../core/events.js';
import {
  ensureOpenSpaceState,
  formatOpenSpaceSourceLine,
  hasTaskDraftChanges,
  selectedContextFromState,
  resolveContexts,
  t,
} from './shared.js';

function getState() {
  return window.DecisionArena.store.state;
}

function initTaskEditDraft(os, task) {
  os.taskEditDraft = {
    taskId: String(task.id || ''),
    title: String(task.title ?? ''),
    description: String(task.description ?? ''),
    status: String(task.status || 'backlog').toLowerCase(),
    priority: String(task.priority || 'medium').toLowerCase(),
    assignee_agent_id: String(task.assignee_agent_id || '').trim().toLowerCase(),
    acceptance_criteria: String(task.acceptance_criteria ?? ''),
  };
}

function syncTaskEditDraftAfterTasksLoad(state) {
  const os = ensureOpenSpaceState(state);
  const contextId = selectedContextFromState(state);
  const tid = String(os.selectedTaskId || '').trim();
  if (!tid || !contextId) {
    os.taskEditDraft = null;
    return;
  }
  const task = os.tasks.find(
    (row) => String(row.id) === tid && String(row.strategic_context_id || '') === String(contextId),
  );
  if (!task) {
    os.taskEditDraft = null;
    return;
  }
  if (!os.taskEditDraft || String(os.taskEditDraft.taskId) !== tid) {
    initTaskEditDraft(os, task);
    return;
  }
  if (!hasTaskDraftChanges(task, os.taskEditDraft)) {
    initTaskEditDraft(os, task);
  }
}

/** Mise à jour légère du panneau détail sans re-render complet (évite de détruire les champs en cours d’édition). */
function syncOpenSpaceTaskDetailChrome() {
  const state = getState();
  const os = ensureOpenSpaceState(state);
  const d = os.taskEditDraft;
  const tid = String(os.selectedTaskId || '').trim();
  const contextId = selectedContextFromState(state);
  if (!d || String(d.taskId) !== tid || !contextId) return;
  const task = os.tasks.find(
    (row) => String(row.id) === tid && String(row.strategic_context_id || '') === String(contextId),
  );
  if (!task) return;
  const h3 = document.getElementById('openspace-task-detail-title');
  if (h3) h3.textContent = d.title || '';
  const srcEl = document.getElementById('openspace-task-detail-source');
  if (srcEl) {
    const dirty = hasTaskDraftChanges(task, d);
    srcEl.textContent = dirty
      ? `${t('openspace.sourceType.user')} (${t('openspace.task.sourceUnsaved')})`
      : formatOpenSpaceSourceLine(task);
  }
}

function updateTaskEditDraftField(state, el, { redraw = true } = {}) {
  const field = String(el?.dataset?.draftField || '').trim();
  if (!field) return false;
  const allowed = new Set(['title', 'description', 'status', 'priority', 'assignee_agent_id', 'acceptance_criteria']);
  if (!allowed.has(field)) return false;
  const os = ensureOpenSpaceState(state);
  const d = os.taskEditDraft;
  if (!d || String(d.taskId) !== String(os.selectedTaskId || '').trim()) return false;
  d[field] = el.value;
  if (!redraw) {
    syncOpenSpaceTaskDetailChrome();
    return true;
  }
  window.DecisionArena.render?.();
  return true;
}

async function loadOpenSpaceContextsIfNeeded(state) {
  const os = ensureOpenSpaceState(state);
  const contexts = resolveContexts(state);
  if (contexts.length > 0) return contexts;
  const data = await window.DecisionArena.services.StrategicContextService.list({ status: 'active' }, 120);
  state.strategicContexts = { loading: false, error: null, items: data.contexts || [] };
  state.activeStrategicContext = data.active_context ?? null;
  state.activeStrategicContextId = data.active_context?.context_id ? String(data.active_context.context_id) : null;
  os.contexts = Array.isArray(data.contexts) ? data.contexts : [];
  return os.contexts;
}

function setOpenSpaceSelectedContext(state, contextId) {
  const os = ensureOpenSpaceState(state);
  const prev = String(os.selectedContextId || '');
  const next = String(contextId || '').trim();
  os.selectedContextId = next || null;
  if (prev !== next) {
    const selectedAgentId = String(os.selectedAgentId || '').trim().toLowerCase();
    const allowed = new Set(
      (Array.isArray(os.contextChatAgents) ? os.contextChatAgents : []).map((a) =>
        String(a.agent_id || '')
          .trim()
          .toLowerCase(),
      ),
    );
    if (selectedAgentId === '' || !allowed.has(selectedAgentId)) {
      os.selectedAgentId = null;
    }
    os.selectedTaskId = null;
    os.taskEditDraft = null;
    os.messages = [];
    os.orchestratorProposal = null;
    os.warnings = [];
    os.contextChatAgents = [];
    os.agentMemoryPanelOpen = false;
    os.agentMemoryContent = '';
    os.agentMemoryMeta = null;
    os.addAgentModalOpen = false;
    os.lastChatDiagnostics = null;
  }
}

async function loadOpenSpaceContextData(state) {
  const os = ensureOpenSpaceState(state);
  const contextId = selectedContextFromState(state);
  os.loading = true;
  os.error = null;
  os.warnings = [];
  window.DecisionArena.render?.();
  try {
    if (!contextId) {
      os.boards = [];
      os.tasks = [];
      os.proposals = [];
      os.messages = [];
      os.taskEditDraft = null;
      os.loading = false;
      window.DecisionArena.render?.();
      return;
    }
    setOpenSpaceSelectedContext(state, contextId);
    const [boardsRes, tasksRes, proposalsRes, agentsRes] = await Promise.all([
      window.DecisionArena.services.OpenSpaceService.listBoards(contextId),
      window.DecisionArena.services.OpenSpaceService.listTasks(contextId),
      window.DecisionArena.services.OpenSpaceService.listProposals(contextId),
      window.DecisionArena.services.OpenSpaceService.listContextAgents(contextId),
    ]);
    os.boards = Array.isArray(boardsRes?.boards) ? boardsRes.boards : [];
    os.tasks = Array.isArray(tasksRes?.tasks) ? tasksRes.tasks : [];
    os.proposals = Array.isArray(proposalsRes?.proposals) ? proposalsRes.proposals : [];
    os.contextChatAgents = Array.isArray(agentsRes?.agents) ? agentsRes.agents : [];
    os.activeBoardId = os.boards[0]?.id || null;
    if (os.selectedTaskId) {
      const kept = os.tasks.find((task) => String(task.id) === String(os.selectedTaskId));
      if (!kept || String(kept.strategic_context_id) !== String(contextId)) {
        os.selectedTaskId = null;
        os.taskEditDraft = null;
        os.messages = [];
        os.warnings.push('Task supprimée de la sélection (cross-context).');
      }
    }
    syncTaskEditDraftAfterTasksLoad(state);
    const allowed = new Set(
      (os.contextChatAgents || []).map((a) => String(a.agent_id || '').trim().toLowerCase()),
    );
    const sel = String(os.selectedAgentId || '').trim().toLowerCase();
    if (sel && !allowed.has(sel)) {
      os.selectedAgentId = null;
    }
    if (!os.orchestratorProposal && os.proposals.length > 0) {
      os.orchestratorProposal = os.proposals[0];
    }
  } catch (err) {
    os.error = String(err?.message || err);
  } finally {
    os.loading = false;
    window.DecisionArena.render?.();
  }
}

async function loadMessagesForSelectedTask(state) {
  const os = ensureOpenSpaceState(state);
  const contextId = selectedContextFromState(state);
  if (!contextId) {
    os.messages = [];
    return;
  }
  const taskId = String(os.selectedTaskId || '').trim() || '_context';
  const res = await window.DecisionArena.services.OpenSpaceService.listTaskMessages(taskId, contextId);
  os.messages = Array.isArray(res?.messages) ? res.messages : [];
}

function registerOpenSpaceHandlers() {
  registerInputListener((e) => {
    const el = e.target;
    if (!el) return false;
    if (el.dataset?.action === 'open-space-task-draft-input') {
      const tag = (el.tagName || '').toUpperCase();
      if (tag === 'SELECT') return false;
      return updateTaskEditDraftField(getState(), el, { redraw: false });
    }
    if (el.dataset?.action !== 'open-space-set-field') return false;
    const field = String(el.dataset?.field || '').trim();
    if (!field) return false;
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os[field] = el.value;
    return true;
  });

  registerChangeListener(async (e) => {
    const el = e.target;
    if (!el || el.dataset?.action !== 'open-space-task-draft-input') return false;
    if ((el.tagName || '').toUpperCase() !== 'SELECT') return false;
    return updateTaskEditDraftField(getState(), el);
  });

  registerAction('open-space-enter', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    try {
      await loadOpenSpaceContextsIfNeeded(state);
      if (!os.selectedContextId) {
        const fallback = selectedContextFromState(state);
        os.selectedContextId = fallback || null;
      }
      await loadOpenSpaceContextData(state);
      if (state.view === 'openspace-agent-chat') {
        await loadMessagesForSelectedTask(state);
      }
    } catch (err) {
      os.error = String(err?.message || err);
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-select-context', async ({ element }) => {
    const state = getState();
    setOpenSpaceSelectedContext(state, element?.value || '');
    await loadOpenSpaceContextData(state);
  });

  registerAction('open-space-refresh', async () => {
    const state = getState();
    await loadOpenSpaceContextData(state);
  });

  registerAction('open-space-create-context', () => {
    window.DecisionArena.router.navigate('strategic-contexts');
    queueMicrotask(() => {
      window.DecisionArena.router.navigate('strategic-contexts');
      window.DecisionArena.services?.LogService?.logUiAction?.('action', { name: 'open-create-strategic-context' });
      window.DecisionArena.render?.();
    });
  });

  registerAction('open-space-view-context', () => {
    const state = getState();
    const contextId = selectedContextFromState(state);
    if (!contextId) return;
    state.view = 'strategic-contexts';
    state.strategicContextUi = state.strategicContextUi || {};
    state.strategicContextUi.selectedContextId = contextId;
    window.DecisionArena.render?.();
  });

  registerAction('open-space-propose-plan', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    if (!contextId) {
      os.error = window.i18n?.t('openspace.context.required') ?? 'Contexte requis.';
      window.DecisionArena.render?.();
      return;
    }
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      const payload = {
        strategic_context_id: contextId,
        context_id: contextId,
        objective: String(os.orchestratorObjective || '').trim(),
        constraints: String(os.orchestratorConstraints || '').trim(),
        mode: 'proposal_only',
      };
      const res = await window.DecisionArena.services.OpenSpaceService.orchestrate(payload);
      os.orchestratorProposal = res?.proposal || null;
      await loadOpenSpaceContextData(state);
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-accept-proposal', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const proposalId = String(element?.dataset?.proposalId || os.orchestratorProposal?.id || '').trim();
    if (!contextId || !proposalId) return;
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.OpenSpaceService.acceptProposal(proposalId, { context_id: contextId });
      await loadOpenSpaceContextData(state);
      state.toast = window.i18n?.t('openspace.orchestrator.accepted') ?? 'Tâches créées depuis la proposition.';
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-create-task', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    if (!contextId) {
      os.error = window.i18n?.t('openspace.context.required') ?? 'Contexte requis.';
      window.DecisionArena.render?.();
      return;
    }
    const title = String(os.newTaskTitle || '').trim();
    if (!title) return;
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.OpenSpaceService.createTask(contextId, {
        board_id: os.activeBoardId || undefined,
        title,
        description: String(os.newTaskDescription || '').trim(),
        status: String(os.newTaskStatus || 'backlog'),
        priority: String(os.newTaskPriority || 'medium'),
        assignee_agent_id: os.selectedAgentId || null,
      });
      os.newTaskTitle = '';
      os.newTaskDescription = '';
      await loadOpenSpaceContextData(state);
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-move-task', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const taskId = String(element?.dataset?.taskId || '').trim();
    const nextStatus = String(element?.dataset?.status || '').trim();
    const contextId = selectedContextFromState(state);
    if (!taskId || !nextStatus || !contextId) return;
    try {
      await window.DecisionArena.services.OpenSpaceService.moveTask(taskId, contextId, nextStatus);
      await loadOpenSpaceContextData(state);
    } catch (err) {
      os.error = String(err?.message || err);
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-open-task', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    os.selectedTaskId = String(element?.dataset?.taskId || '').trim() || null;
    const task = os.tasks.find(
      (row) => String(row.id) === String(os.selectedTaskId) && String(row.strategic_context_id || '') === String(contextId),
    );
    if (task) initTaskEditDraft(os, task);
    else os.taskEditDraft = null;
    await loadMessagesForSelectedTask(state);
    window.DecisionArena.render?.();
    queueMicrotask(() => {
      document.getElementById('openspace-task-detail')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });

  registerAction('open-space-close-task-detail', () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.selectedTaskId = null;
    os.taskEditDraft = null;
    window.DecisionArena.render?.();
  });

  registerAction('open-space-save-task-detail', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const d = os.taskEditDraft;
    const tid = String(os.selectedTaskId || '').trim();
    if (!contextId || !d || String(d.taskId) !== tid) return;
    const task = os.tasks.find(
      (row) => String(row.id) === tid && String(row.strategic_context_id || '') === String(contextId),
    );
    if (!task) return;
    const title = String(d.title || '').trim();
    if (!title) {
      os.error = window.i18n?.t('openspace.task.titleRequired') ?? 'Le titre est obligatoire.';
      window.DecisionArena.render?.();
      return;
    }
    if (!hasTaskDraftChanges(task, d)) {
      state.toast = window.i18n?.t('openspace.task.noChanges') ?? 'Aucune modification.';
      window.DecisionArena.render?.();
      return;
    }
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      const payload = {
        context_id: contextId,
        strategic_context_id: contextId,
        title,
        description: String(d.description || '').trim() || null,
        status: d.status,
        priority: d.priority,
        assignee_agent_id: String(d.assignee_agent_id || '').trim() || null,
        acceptance_criteria: String(d.acceptance_criteria || '').trim() || null,
        source_type: 'user',
        source_id: null,
      };
      await window.DecisionArena.services.OpenSpaceService.updateTask(tid, payload);
      await loadOpenSpaceContextData(state);
      state.toast = window.i18n?.t('openspace.task.saved') ?? 'Tâche enregistrée.';
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-discuss-task', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.taskEditDraft = null;
    os.selectedTaskId = String(element?.dataset?.taskId || '').trim() || null;
    state.view = 'openspace-agent-chat';
    await loadMessagesForSelectedTask(state);
    window.DecisionArena.render?.();
  });

  registerAction('open-space-select-agent', ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.selectedAgentId = String(element?.value || '').trim().toLowerCase() || null;
    os.agentMemoryContent = '';
    os.agentMemoryMeta = null;
    os.agentMemoryPanelOpen = false;
    window.DecisionArena.render?.();
  });

  registerAction('open-space-select-task-for-chat', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.selectedTaskId = String(element?.value || '').trim() || null;
    await loadMessagesForSelectedTask(state);
    window.DecisionArena.render?.();
  });

  registerAction('open-space-send-message', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const content = String(os.chatInput || '').trim();
    if (!contextId || !content) return;
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      const res = await window.DecisionArena.services.OpenSpaceService.sendTaskMessage({
        strategic_context_id: contextId,
        context_id: contextId,
        task_id: os.selectedTaskId || null,
        role: 'user',
        content,
        agent_id: os.selectedAgentId || null,
        generate_reply: true,
      });
      os.chatInput = '';
      os.lastChatDiagnostics = res?.reply_diagnostics ?? null;
      await loadMessagesForSelectedTask(state);
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-export-task-jira', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const taskId = String(element?.dataset?.taskId || '').trim();
    if (!contextId || !taskId) return;
    try {
      const res = await window.DecisionArena.services.OpenSpaceService.exportTaskJira(taskId, contextId);
      const text = JSON.stringify(res?.export || {}, null, 2);
      const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = String(res?.filename || `openspace-jira-export-${contextId}.json`);
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      state.toast = window.i18n?.t('openspace.kanban.exportDone') || 'Export Jira JSON généré.';
      window.DecisionArena.render?.();
    } catch (err) {
      os.error = String(err?.message || err);
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-export-board-jira', async ({ element }) => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const boardId = String(element?.dataset?.boardId || os.activeBoardId || '').trim();
    if (!contextId || !boardId) return;
    try {
      const res = await window.DecisionArena.services.OpenSpaceService.exportBoardJira(boardId, contextId);
      const text = JSON.stringify(res?.export || {}, null, 2);
      const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = String(res?.filename || `openspace-jira-export-${contextId}.json`);
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      state.toast = window.i18n?.t('openspace.kanban.exportDone') || 'Export Jira JSON généré.';
      window.DecisionArena.render?.();
    } catch (err) {
      os.error = String(err?.message || err);
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-load-agent-memory', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const aid = String(os.selectedAgentId || '').trim().toLowerCase();
    if (!contextId || !aid) return;
    os.agentMemoryLoading = true;
    os.agentMemoryPanelOpen = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(contextId, aid);
      os.agentMemoryContent = String(data?.content ?? '');
      os.agentMemoryMeta = {
        memory_md_state: data?.memory_md_state ?? null,
        memory_md_flags: data?.memory_md_flags ?? null,
        path: data?.path ?? null,
      };
    } catch (err) {
      os.error = String(err?.message || err);
      os.agentMemoryContent = '';
      os.agentMemoryMeta = null;
    } finally {
      os.agentMemoryLoading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-refresh-agent-memory', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const aid = String(os.selectedAgentId || '').trim().toLowerCase();
    if (!contextId || !aid) return;
    os.agentMemoryLoading = true;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(contextId, aid);
      os.agentMemoryContent = String(data?.content ?? '');
      os.agentMemoryMeta = {
        memory_md_state: data?.memory_md_state ?? null,
        memory_md_flags: data?.memory_md_flags ?? null,
        path: data?.path ?? null,
      };
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.agentMemoryLoading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-copy-agent-memory', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const text = String(os.agentMemoryContent || '');
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      state.toast = window.i18n?.t('openspace.agentChat.memoryCopied') ?? 'Copié.';
    } catch {
      state.toast = window.i18n?.t('openspace.agentChat.memoryCopyFail') ?? 'Copie impossible.';
    }
    window.DecisionArena.render?.();
  });

  registerAction('open-space-open-add-agent-modal', () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.addAgentModalOpen = true;
    window.DecisionArena.render?.();
  });

  registerAction('open-space-close-add-agent-modal', () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    os.addAgentModalOpen = false;
    window.DecisionArena.render?.();
  });

  registerAction('open-space-confirm-add-agent', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const sel = document.getElementById('openspace-add-agent-select');
    const agentId = String(sel?.value || '').trim().toLowerCase();
    if (!contextId || !agentId) return;
    os.loading = true;
    os.error = null;
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.addContextAgent(contextId, {
        agent_id: agentId,
        source: 'manual',
      });
      os.addAgentModalOpen = false;
      const agentsRes = await window.DecisionArena.services.OpenSpaceService.listContextAgents(contextId);
      os.contextChatAgents = Array.isArray(agentsRes?.agents) ? agentsRes.agents : [];
      os.selectedAgentId = agentId;
      state.toast = window.i18n?.t('openspace.agentChat.agentAdded') ?? 'Agent ajouté au contexte.';
    } catch (err) {
      os.error = String(err?.message || err);
    } finally {
      os.loading = false;
      window.DecisionArena.render?.();
    }
  });

  registerAction('open-space-goto-context-agent-memory', async () => {
    const state = getState();
    const os = ensureOpenSpaceState(state);
    const contextId = selectedContextFromState(state);
    const aid = String(os.selectedAgentId || '').trim().toLowerCase();
    if (!contextId || !aid) return;
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    ui.selectedContextId = contextId;
    ui.agentContextMemory = ui.agentContextMemory || {};
    ui.agentContextMemory.open = true;
    ui.agentContextMemory.agentId = aid;
    ui.agentContextMemory.loading = true;
    ui.agentContextMemory.error = '';
    state.view = 'strategic-contexts';
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(contextId, aid);
      ui.agentContextMemory.content = String(data?.content ?? '');
    } catch (err) {
      ui.agentContextMemory.error = String(err?.message || err);
      ui.agentContextMemory.content = '';
    } finally {
      ui.agentContextMemory.loading = false;
      window.DecisionArena.render?.();
    }
  });
}

export { registerOpenSpaceHandlers };


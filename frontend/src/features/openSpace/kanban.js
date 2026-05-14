import {
  OPEN_SPACE_STATUSES,
  ensureOpenSpaceState,
  formatOpenSpaceSourceLine,
  hasTaskDraftChanges,
  renderOpenSpaceContextSwitcher,
  renderContextRequiredEmptyState,
  selectedContextFromState,
  t,
  esc,
} from './shared.js';

function nextStatus(status) {
  const idx = OPEN_SPACE_STATUSES.indexOf(String(status || '').toLowerCase());
  if (idx < 0) return 'todo';
  return OPEN_SPACE_STATUSES[(idx + 1) % OPEN_SPACE_STATUSES.length];
}

function renderTaskCard(task, selectedTaskId) {
  const next = nextStatus(task.status);
  const jiraType = task?.jira?.issue_type || 'Task';
  const selected = String(selectedTaskId || '') === String(task.id || '');
  return `
    <article class="openspace-task-card${selected ? ' openspace-task-card--selected' : ''}">
      <div class="openspace-task-title">${esc(task.title || '')}</div>
      <div class="openspace-task-meta">
        ${esc(t('openspace.task.assignee'))}: ${esc(task.assignee_agent_id || '—')} ·
        ${esc(t('openspace.task.priority'))}: ${esc(task.priority || '—')}
      </div>
      <div class="openspace-task-meta">
        ${esc(t('openspace.task.source'))}: ${esc(formatOpenSpaceSourceLine(task))}
      </div>
      <div class="openspace-task-meta">Jira: ${esc(jiraType)}</div>
      <div class="openspace-task-actions">
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-open-task" data-task-id="${esc(task.id || '')}">${esc(t('openspace.task.open'))}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-discuss-task" data-task-id="${esc(task.id || '')}">${esc(t('openspace.task.discuss'))}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-move-task" data-task-id="${esc(task.id || '')}" data-status="${esc(next)}">${esc(t('openspace.task.moveTo'))}: ${esc(t(`openspace.status.${next}`))}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-export-task-jira" data-task-id="${esc(task.id || '')}">${esc(t('openspace.task.exportJira'))}</button>
      </div>
    </article>
  `;
}

function renderColumn(status, tasks, selectedTaskId) {
  const empty = t('openspace.kanban.emptyColumn');
  return `
    <section class="openspace-column">
      <header class="openspace-column-header">
        <h3>${esc(t(`openspace.status.${status}`))}</h3>
        <span class="badge badge-muted">${tasks.length}</span>
      </header>
      <div class="openspace-column-body">
        ${tasks.length ? tasks.map((task) => renderTaskCard(task, selectedTaskId)).join('') : `<div class="openspace-empty-column">${esc(empty)}</div>`}
      </div>
    </section>
  `;
}

function renderTaskDetailPanel(os, selectedContextId, state) {
  const id = String(os.selectedTaskId || '').trim();
  if (!id) return '';
  const task = os.tasks.find(
    (row) => String(row.id) === id && String(row.strategic_context_id || '') === String(selectedContextId),
  );
  const d = os.taskEditDraft;
  if (!task || !d || String(d.taskId) !== id) return '';
  const agents = Array.isArray(state?.personas) ? state.personas : [];
  const dirty = hasTaskDraftChanges(task, d);
  const sourceLabel = dirty
    ? `${t('openspace.sourceType.user')} (${t('openspace.task.sourceUnsaved')})`
    : formatOpenSpaceSourceLine(task);
  const statusOpts = OPEN_SPACE_STATUSES.map(
    (s) => `<option value="${esc(s)}" ${String(d.status || '') === s ? 'selected' : ''}>${esc(t(`openspace.status.${s}`))}</option>`,
  ).join('');
  const priOpts = ['low', 'medium', 'high']
    .map((s) => `<option value="${esc(s)}" ${String(d.priority || '') === s ? 'selected' : ''}>${esc(s)}</option>`)
    .join('');
  const assigneeOpts = `<option value="">${esc(t('openspace.task.assigneeNone'))}</option>${agents
    .map((agent) => {
      const aid = String(agent.id || '').trim().toLowerCase();
      const label = String(agent.name || aid || '');
      return `<option value="${esc(aid)}" ${String(d.assignee_agent_id || '') === aid ? 'selected' : ''}>${esc(label)}</option>`;
    })
    .join('')}`;
  return `
    <div id="openspace-task-detail" class="card openspace-card openspace-task-detail" role="region" aria-labelledby="openspace-task-detail-title">
      <div class="openspace-task-detail-header">
        <h3 id="openspace-task-detail-title">${esc(d.title || '')}</h3>
        <button type="button" class="btn btn-ghost btn-sm" data-action="open-space-close-task-detail">${esc(t('openspace.task.closeDetail'))}</button>
      </div>
      <dl class="openspace-task-detail-body openspace-task-detail-form">
        <dt>${esc(t('openspace.task.title'))}</dt>
        <dd><input type="text" class="input openspace-input" data-action="open-space-task-draft-input" data-draft-field="title" value="${esc(d.title || '')}" /></dd>
        <dt>${esc(t('openspace.task.status'))}</dt>
        <dd><select class="select openspace-select" data-action="open-space-task-draft-input" data-draft-field="status">${statusOpts}</select></dd>
        <dt>${esc(t('openspace.task.priority'))}</dt>
        <dd><select class="select openspace-select" data-action="open-space-task-draft-input" data-draft-field="priority">${priOpts}</select></dd>
        <dt>${esc(t('openspace.task.assignee'))}</dt>
        <dd><select class="select openspace-select" data-action="open-space-task-draft-input" data-draft-field="assignee_agent_id">${assigneeOpts}</select></dd>
        <dt>${esc(t('openspace.task.description'))}</dt>
        <dd><textarea rows="4" class="textarea openspace-textarea" data-action="open-space-task-draft-input" data-draft-field="description">${esc(d.description || '')}</textarea></dd>
        <dt>${esc(t('openspace.task.acceptance'))}</dt>
        <dd><textarea rows="4" class="textarea openspace-textarea" data-action="open-space-task-draft-input" data-draft-field="acceptance_criteria">${esc(d.acceptance_criteria || '')}</textarea></dd>
        <dt>${esc(t('openspace.task.source'))}</dt>
        <dd id="openspace-task-detail-source">${esc(sourceLabel)}</dd>
      </dl>
      <div class="openspace-task-detail-actions">
        <button type="button" class="btn btn-primary btn-sm" data-action="open-space-save-task-detail">${esc(t('openspace.task.save'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-discuss-task" data-task-id="${esc(task.id || '')}">${esc(t('openspace.task.discuss'))}</button>
      </div>
    </div>
  `;
}

function renderOpenSpaceKanban() {
  const state = window.DecisionArena.store.state;
  const os = ensureOpenSpaceState(state);
  const selectedContextId = selectedContextFromState(state);
  const switcher = renderOpenSpaceContextSwitcher(state);
  if (!selectedContextId) {
    return `
      <div class="page-header"><div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.kanban'))}</div></div>
      ${switcher}
      ${renderContextRequiredEmptyState()}
    `;
  }
  const filtered = os.tasks.filter((task) => {
    if (String(task.strategic_context_id || '') !== String(selectedContextId)) return false;
    if (os.filterStatus && String(task.status || '') !== String(os.filterStatus)) return false;
    if (os.filterAgent && String(task.assignee_agent_id || '') !== String(os.filterAgent)) return false;
    return true;
  });

  const agentOptions = ['', ...new Set(filtered.map((task) => String(task.assignee_agent_id || '').trim()).filter(Boolean))];

  const boardId = os.activeBoardId || '';
  return `
    <div class="page-header">
      <div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.kanban'))}</div>
    </div>
    ${switcher}
    ${os.error ? `<div class="error-banner">⚠️ ${esc(os.error)}</div>` : ''}
    <div class="card openspace-card openspace-kanban-toolbar">
      <div class="openspace-toolbar-row">
        <input type="text" class="input openspace-input" data-action="open-space-set-field" data-field="newTaskTitle" value="${esc(os.newTaskTitle)}" placeholder="${esc(t('openspace.task.title'))}" />
        <input type="text" class="input openspace-input" data-action="open-space-set-field" data-field="newTaskDescription" value="${esc(os.newTaskDescription)}" placeholder="${esc(t('openspace.task.description'))}" />
        <select class="select openspace-select" data-action="open-space-set-field" data-field="newTaskStatus">
          ${OPEN_SPACE_STATUSES.map((s) => `<option value="${esc(s)}" ${os.newTaskStatus === s ? 'selected' : ''}>${esc(t(`openspace.status.${s}`))}</option>`).join('')}
        </select>
        <select class="select openspace-select" data-action="open-space-set-field" data-field="newTaskPriority">
          ${['low', 'medium', 'high'].map((s) => `<option value="${esc(s)}" ${os.newTaskPriority === s ? 'selected' : ''}>${esc(s)}</option>`).join('')}
        </select>
        <button type="button" class="btn btn-primary btn-sm" data-action="open-space-create-task">${esc(t('openspace.kanban.newTask'))}</button>
      </div>
      <div class="openspace-toolbar-row">
        <select class="select openspace-select" data-action="open-space-set-field" data-field="filterStatus">
          <option value="">${esc(t('openspace.filters.all'))}</option>
          ${OPEN_SPACE_STATUSES.map((s) => `<option value="${esc(s)}" ${os.filterStatus === s ? 'selected' : ''}>${esc(t(`openspace.status.${s}`))}</option>`).join('')}
        </select>
        <select class="select openspace-select" data-action="open-space-set-field" data-field="filterAgent">
          <option value="">${esc(t('openspace.filters.allAgents'))}</option>
          ${agentOptions.map((a) => `<option value="${esc(a)}" ${os.filterAgent === a ? 'selected' : ''}>${esc(a || '—')}</option>`).join('')}
        </select>
        <button type="button" class="btn btn-secondary btn-sm" data-action="open-space-export-board-jira" data-board-id="${esc(boardId)}">${esc(t('openspace.kanban.exportBacklog'))}</button>
      </div>
    </div>
    <div class="openspace-kanban">
      ${OPEN_SPACE_STATUSES.map((status) => {
        const tasks = filtered.filter((task) => String(task.status || '') === status);
        return renderColumn(status, tasks, os.selectedTaskId);
      }).join('')}
    </div>
    ${renderTaskDetailPanel(os, selectedContextId, state)}
  `;
}

export { renderOpenSpaceKanban };


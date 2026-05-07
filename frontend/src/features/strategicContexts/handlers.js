import { registerAction } from '../../core/events.js';
import { isConfirmationConfirmed, requestConfirmation, uiCopy } from '../../utils/confirmationUi.js';

function registerStrategicContextsHandlers() {
  const refreshList = async (state) => {
    const ui = state.strategicContextUi || { statusFilter: 'active' };
    const filters = ui.statusFilter ? { status: ui.statusFilter } : {};
    const data = await window.DecisionArena.services.StrategicContextService.list(filters, 120);
    state.strategicContexts = { loading: false, error: null, items: data.contexts || [] };

    const items = Array.isArray(state.strategicContexts.items) ? state.strategicContexts.items : [];
    const sel = ui.selectedContextId;
    if (sel && items.some((c) => c.context_id === sel)) return;
    ui.selectedContextId = items[0]?.context_id ?? null;
  };

  const openContextForm = (state, mode, ctxId = null) => {
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    ui.formOpen = true;
    ui.formMode = mode;
    ui.formContextId = ctxId;
    ui.formError = '';
    ui.archiveError = '';
    ui.deleteError = '';
    ui.deleteConfirmContextId = null;
    ui.linkFormOpen = false;
    ui.linkError = '';
    ui.linkSuccess = '';

    if (mode === 'edit' && ctxId) {
      const cur = (state.strategicContexts?.items || []).find((c) => c.context_id === ctxId);
      ui.formValues = {
        title: cur?.title || '',
        description: cur?.description || '',
        status: cur?.status || 'active',
      };
      ui.selectedContextId = ctxId;
    } else {
      ui.formValues = { title: '', description: '', status: 'active' };
      ui.formContextId = null;
    }
  };

  const closeContextForm = (state) => {
    const ui = state.strategicContextUi || {};
    ui.formOpen = false;
    ui.formError = '';
    ui.formContextId = null;
  };

  const openLinkForm = (state, contextId) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.linkFormOpen = true;
    ui.linkType = ui.linkType || 'session';
    ui.linkId = '';
    ui.linkError = '';
    ui.linkSuccess = '';

    ui.formOpen = false;
    ui.formError = '';
    ui.formContextId = null;
    ui.deleteConfirmContextId = null;
    ui.deleteError = '';
    ui.archiveError = '';

    ui.selectedContextId = contextId || ui.selectedContextId;
  };

  const closeLinkForm = (state) => {
    const ui = state.strategicContextUi || {};
    ui.linkFormOpen = false;
    ui.linkError = '';
    ui.linkSuccess = '';
  };

  registerAction('load-strategic-contexts', async () => {
    const state = window.DecisionArena.store.state;
    state.strategicContexts = { loading: true, error: null, items: state.strategicContexts?.items ?? [] };
    window.DecisionArena.render?.();
    try {
      await refreshList(state);
    } catch (err) {
      state.strategicContexts = { loading: false, error: String(err.message || err), items: state.strategicContexts?.items ?? [] };
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-context-status-filter', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    state.strategicContextUi = state.strategicContextUi || {};
    state.strategicContextUi.statusFilter = element.value;
    try {
      await refreshList(state);
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('toggle-bulk-context-selection', ({ element }) => {
    const contextId = element?.dataset?.contextId;
    if (!contextId) return;
    const state = window.DecisionArena.store.state;
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    const ids = new Set(Array.isArray(ui.bulkSelectedIds) ? ui.bulkSelectedIds : []);
    if (element.checked) ids.add(contextId);
    else ids.delete(contextId);
    ui.bulkSelectedIds = Array.from(ids);
    ui.bulkDeleteConfirm = false;
    window.DecisionArena.render?.();
  });

  registerAction('select-all-visible-contexts', () => {
    const state = window.DecisionArena.store.state;
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    const items = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
    ui.bulkSelectedIds = items.map((c) => String(c.context_id || '')).filter(Boolean);
    ui.bulkDeleteConfirm = false;
    window.DecisionArena.render?.();
  });

  registerAction('clear-bulk-context-selection', () => {
    const state = window.DecisionArena.store.state;
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    ui.bulkSelectedIds = [];
    ui.bulkDeleteConfirm = false;
    window.DecisionArena.render?.();
  });

  registerAction('request-bulk-delete-contexts', () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    state.strategicContextUi = state.strategicContextUi || {};
    const ui = state.strategicContextUi;
    const ids = Array.isArray(ui.bulkSelectedIds) ? ui.bulkSelectedIds : [];
    if (!ids.length) return;
    ui.bulkDeleteConfirm = true;
    ui.deleteError = '';
    window.DecisionArena.render?.();
  });

  registerAction('cancel-bulk-delete-contexts', () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.bulkDeleteConfirm = false;
    window.DecisionArena.render?.();
  });

  registerAction('confirm-bulk-delete-contexts', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const ids = Array.isArray(ui.bulkSelectedIds) ? ui.bulkSelectedIds.map(String).filter(Boolean) : [];
    if (!ids.length) return;
    ui.deleteError = '';
    ui.bulkDeleteConfirm = false;
    window.DecisionArena.render?.();

    const failures = [];
    for (const id of ids) {
      try {
        // eslint-disable-next-line no-await-in-loop
        await window.DecisionArena.services.StrategicContextService.deleteContext(id);
      } catch (err) {
        failures.push({ id, err: String(err?.message || err) });
      }
    }

    try { await refreshList(state); } catch (_) {}

    if (failures.length) {
      ui.deleteError = `Suppression partielle: ${failures.length} échec(s).`;
    } else {
      state.toast = 'Contextes supprimés.';
      ui.bulkSelectedIds = [];
    }
    window.DecisionArena.render?.();
  });

  registerAction('select-strategic-context', ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    state.strategicContextUi = state.strategicContextUi || {};
    state.strategicContextUi.selectedContextId = id;
    window.DecisionArena.render?.();
  });

  const loadContextMemoryMd = async (state, contextId) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryMdLoading = true;
    ui.memoryMdError = '';
    window.DecisionArena.render?.();
    try {
      const perspective = window.DecisionArena.services.MemorySnapshotService.normalizePerspective(
        ui.memoryMdPerspective || 'default'
      );
      const md = await window.DecisionArena.services.MemorySnapshotService.getContextMarkdown(contextId, {
        expert: state.uiMode === 'expert' ? '1' : '',
        perspective,
      });
      ui.memoryMdContent = md;
    } catch (err) {
      ui.memoryMdError = String(err?.message || err);
      ui.memoryMdContent = '';
    } finally {
      ui.memoryMdLoading = false;
    }
  };

  registerAction('toggle-context-memory-md', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryMdOpen = !ui.memoryMdOpen;
    ui.memoryMdError = '';
    // Track the context the panel was opened for so a perspective change
    // can refresh the preview without depending on selectedContextId, which
    // can stay null when the user has not explicitly clicked a card yet.
    ui.memoryMdContextId = cid;
    if (ui.memoryMdOpen && !ui.memoryMdContent) {
      await loadContextMemoryMd(state, cid);
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-context-memory-perspective', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const requested = String(
      element?.dataset?.perspective
        ?? element?.value
        ?? ''
    ).trim();
    const perspective = window.DecisionArena.services.MemorySnapshotService.normalizePerspective(requested) || 'default';
    if (ui.memoryMdPerspective === perspective) return;

    let preservedScroll = 0;
    try {
      const sc = document.querySelector('[data-snapshot-scroll="strategic-context"]');
      if (sc) preservedScroll = sc.scrollTop || 0;
    } catch (_) { preservedScroll = 0; }

    ui.memoryMdPerspective = perspective;
    ui.memoryMdContent = '';
    ui.memoryMdError = '';
    if (ui.memoryMdOpen) {
      const cid = String(ui.memoryMdContextId || ui.selectedContextId || '');
      if (cid) await loadContextMemoryMd(state, cid);
    }
    window.DecisionArena.render?.();

    if (preservedScroll > 0) {
      try {
        requestAnimationFrame(() => {
          const sc2 = document.querySelector('[data-snapshot-scroll="strategic-context"]');
          if (sc2) sc2.scrollTop = preservedScroll;
        });
      } catch (_) { /* noop */ }
    }
  });

  registerAction('close-context-memory-md', () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryMdOpen = false;
    window.DecisionArena.render?.();
  });

  registerAction('copy-context-memory-md', async () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || {};
    const text = String(ui.memoryMdContent || '');
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
    } catch (_) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    state.toast = 'Copié: memory.md';
    setTimeout(() => { try { state.toast = null; window.DecisionArena.render?.(); } catch (_) {} }, 2000);
    window.DecisionArena.render?.();
  });

  registerAction('goto-context', ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    state.view = 'strategic-contexts';
    state.strategicContextUi = state.strategicContextUi || {};
    state.strategicContextUi.selectedContextId = id;
    window.DecisionArena.render?.();
  });

  registerAction('open-create-strategic-context', () => {
    const state = window.DecisionArena.store.state;
    openContextForm(state, 'create', null);
    window.DecisionArena.render?.();
  });

  registerAction('open-edit-strategic-context', ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    openContextForm(state, 'edit', id);
    window.DecisionArena.render?.();
  });

  registerAction('open-link-to-context', ({ element }) => {
    const contextId = element?.dataset?.contextId;
    if (!contextId) return;
    const state = window.DecisionArena.store.state;
    openLinkForm(state, contextId);
    window.DecisionArena.render?.();
  });

  registerAction('set-context-form-field', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const key = element?.dataset?.field;
    if (!key) return;
    ui.formValues = ui.formValues || { title: '', description: '', status: 'active' };
    ui.formValues[key] = element.value;
  });

  registerAction('cancel-context-form', () => {
    const state = window.DecisionArena.store.state;
    closeContextForm(state);
    window.DecisionArena.render?.();
  });

  registerAction('submit-context-form', async () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.formError = '';

    const v = ui.formValues || {};
    const title = String(v.title || '').trim();
    const description = String(v.description || '');
    const status = String(v.status || 'active');

    if (!title) {
      ui.formError = 'Titre obligatoire.';
      window.DecisionArena.render?.();
      return;
    }

    try {
      const svc = window.DecisionArena.services.StrategicContextService;
      if (ui.formMode === 'edit' && ui.formContextId) {
        await svc.updateContext(ui.formContextId, { title, description, status });
        state.toast = 'Contexte mis à jour.';
      } else {
        const res = await svc.createContext({ title, description, status });
        const createdId = res?.context?.context_id || null;
        if (createdId) ui.selectedContextId = createdId;
        state.toast = 'Contexte créé.';
      }
      await refreshList(state);
      closeContextForm(state);
    } catch (err) {
      ui.formError = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-link-type', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.linkType = element?.value === 'memory' ? 'memory' : 'session';
    ui.linkId = '';
    ui.linkError = '';
    ui.linkSuccess = '';
    window.DecisionArena.render?.();
  });

  registerAction('set-link-id', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.linkId = element?.value || '';
  });

  registerAction('cancel-link-form', () => {
    const state = window.DecisionArena.store.state;
    closeLinkForm(state);
    window.DecisionArena.render?.();
  });

  registerAction('submit-link-form', async () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.linkError = '';
    ui.linkSuccess = '';
    const contextId = ui.selectedContextId;
    if (!contextId) {
      ui.linkError = 'Aucun contexte sélectionné.';
      window.DecisionArena.render?.();
      return;
    }
    const id = String(ui.linkId || '').trim();
    if (!id) {
      ui.linkError = ui.linkType === 'memory' ? 'memory_id requis.' : 'session_id requis.';
      window.DecisionArena.render?.();
      return;
    }
    try {
      const svc = window.DecisionArena.services.StrategicContextService;
      if (ui.linkType === 'memory') {
        await svc.linkMemory(contextId, id);
        ui.linkSuccess = 'Mémoire liée.';
      } else {
        await svc.linkSession(contextId, id);
        ui.linkSuccess = 'Session liée.';
      }
      await refreshList(state);
    } catch (err) {
      ui.linkError = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('archive-strategic-context', async (ctx = {}) => {
    const { element } = ctx;
    const contextId = element?.dataset?.contextId;
    if (!contextId) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.archiveError = '';
    if (!isConfirmationConfirmed(ctx)) {
      requestConfirmation(state, {
        id: `archive-strategic-context:${contextId}`,
        mode: 'modal',
        tone: 'warning',
        title: uiCopy('Archiver ce contexte stratégique ?', 'Archive this strategic context?'),
        body: uiCopy('Il sortira de la vue active, sans supprimer ses mémoires ni ses sessions.', 'It leaves the active view without deleting memories or sessions.'),
        expertBody: uiCopy('Les sessions et mémoires liées restent accessibles.', 'Linked sessions and memories remain accessible.'),
        confirmLabel: uiCopy('Archiver le contexte', 'Archive context'),
        action: 'archive-strategic-context',
        payload: { contextId },
      });
      window.DecisionArena.render?.();
      return;
    }
    try {
      await window.DecisionArena.services.StrategicContextService.archiveContext(contextId);
      state.toast = 'Contexte archivé.';
      await refreshList(state);
      if (ui.selectedContextId === contextId) {
        const items = state.strategicContexts?.items || [];
        ui.selectedContextId = items[0]?.context_id ?? null;
      }
    } catch (err) {
      ui.archiveError = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('request-delete-strategic-context', ({ element }) => {
    const contextId = element?.dataset?.contextId;
    if (!contextId) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.deleteConfirmContextId = contextId;
    ui.deleteError = '';
    window.DecisionArena.render?.();
  });

  registerAction('cancel-delete-strategic-context', () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.deleteConfirmContextId = null;
    ui.deleteError = '';
    window.DecisionArena.render?.();
  });

  registerAction('confirm-delete-strategic-context', async ({ element }) => {
    const contextId = element?.dataset?.contextId;
    if (!contextId) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.deleteError = '';
    try {
      await window.DecisionArena.services.StrategicContextService.deleteContext(contextId);
      state.toast = 'Contexte supprimé.';
      ui.deleteConfirmContextId = null;
      if (ui.selectedContextId === contextId) ui.selectedContextId = null;
      if (Array.isArray(ui.bulkSelectedIds)) {
        ui.bulkSelectedIds = ui.bulkSelectedIds.filter((x) => String(x) !== String(contextId));
      }
      await refreshList(state);
    } catch (err) {
      ui.deleteError = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });
}

export { registerStrategicContextsHandlers };


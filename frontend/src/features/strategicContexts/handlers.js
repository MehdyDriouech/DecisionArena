import { registerAction, registerInputListener } from '../../core/events.js';
import { isConfirmationConfirmed, requestConfirmation, uiCopy } from '../../utils/confirmationUi.js';

function registerStrategicContextsHandlers() {
  registerInputListener((e) => {
    const el = e.target;
    if (!el || el.dataset?.action !== 'set-context-form-field') return false;
    const field = String(el.dataset?.field || '').trim();
    if (!field) return false;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.formValues = ui.formValues || { title: '', description: '', status: 'active' };
    ui.formValues[field] = el.value;
    return true;
  });

  registerInputListener((e) => {
    const el = e.target;
    if (!el || el.dataset?.action !== 'set-agent-context-memory-field') return false;
    const field = el.dataset?.field;
    if (field !== 'content' && field !== 'appendNote') return false;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || {};
    const am = ui.agentContextMemory;
    if (!am?.open) return false;
    if (field === 'content') {
      am.content = el.value;
    } else {
      am.appendNote = el.value;
    }
    return true;
  });

  registerInputListener((e) => {
    const el = e.target;
    if (!el || el.dataset?.action !== 'set-situated-agent-chat-field') return false;
    if (el.dataset?.field !== 'input') return false;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || {};
    const sc = ui.situatedAgentChat;
    if (!sc?.open) return false;
    sc.input = el.value;
    return true;
  });

  const refreshList = async (state) => {
    const ui = state.strategicContextUi || { statusFilter: 'active' };
    const filters = ui.statusFilter ? { status: ui.statusFilter } : {};
    const data = await window.DecisionArena.services.StrategicContextService.list(filters, 120);
    state.strategicContexts = { loading: false, error: null, items: data.contexts || [] };
    const active = data.active_context ?? null;
    state.activeStrategicContext = active;
    state.activeStrategicContextId = active?.context_id ? String(active.context_id) : null;

    const items = Array.isArray(state.strategicContexts.items) ? state.strategicContexts.items : [];
    const sel = ui.selectedContextId;
    if (sel && items.some((c) => c.context_id === sel)) return;
    const act = state.activeStrategicContextId;
    if (act && items.some((c) => c.context_id === act)) {
      ui.selectedContextId = act;
      return;
    }
    ui.selectedContextId = items[0]?.context_id ?? null;
  };

  const resolveSelectedContextId = (state) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const explicit = String(ui.selectedContextId || '').trim();
    if (explicit) return explicit;
    const items = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
    const fallback = String(items[0]?.context_id || '').trim();
    if (fallback) ui.selectedContextId = fallback;
    return fallback;
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
    state.strategicContextUi.compareOpen = false;
    state.strategicContextUi.compareLeftId = null;
    state.strategicContextUi.compareRightId = null;
    state.strategicContextUi.workspaceTimeline = { loading: false, error: '', data: null };
    state.strategicContextUi.strategicNarrative = { loading: false, error: '', data: null };
    state.strategicContextUi.beliefsEngine = {
      loading: false, error: '', items: [], mode: 'context',
      filterAgent: '', filterType: '', filterStatus: '', disputedOnly: false, byAgentId: '',
      saving: false, formError: '',
    };
    state.strategicContextUi.beliefsRuntime = {
      loading: false,
      error: '',
      data: null,
      selectedBeliefId: '',
      timelineLoading: false,
      timeline: [],
      relationsLoading: false,
      relations: [],
    };
    state.strategicContextUi.agentContextMemory = {
      open: false, agentId: 'pm', loading: false, saving: false, consolidating: false,
      recentNoteBusy: false, contradictionBusy: false, deprecateBusy: false, compactBusy: false,
      error: '', content: '', appendNote: '', appendSection: 'recent',
      maintenanceRecentNote: '', maintenanceSessionId: '', contradictionText: '', contradictionSource: '',
      deprecateText: '', deprecateReason: '',
    };
    state.strategicContextUi.situatedAgentChat = {
      open: false, conversationId: null, agentId: 'pm', messages: [], input: '', loading: false,
      includeMemory: true, includeRecentDecisions: true, includeSocialContext: true, lastWarnings: [],
      lastCognitiveRuntime: null, lastPromptTrace: null, error: '',
    };
    state.strategicContextUi.memoryGovernance = {
      panelOpen: false, loading: false, error: '', data: null,
    };
    state.strategicContextUi.contextDeepCompare = {
      panelOpen: false, leftId: null, rightId: null,
      includeSessions: true, includeDecisions: true, includeAgentMemories: false,
      includeSocialDynamics: true, includeTimeline: true,
      loading: false, error: '', result: null,
    };
    window.DecisionArena.render?.();
  });

  registerAction('open-strategic-context', ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    state.view = 'strategic-contexts';
    state.strategicContextUi = state.strategicContextUi || {};
    state.strategicContextUi.selectedContextId = id;
    state.strategicContextUi.compareOpen = false;
    state.strategicContextUi.compareLeftId = null;
    state.strategicContextUi.compareRightId = null;
    state.strategicContextUi.workspaceTimeline = { loading: false, error: '', data: null };
    state.strategicContextUi.strategicNarrative = { loading: false, error: '', data: null };
    state.strategicContextUi.beliefsEngine = {
      loading: false, error: '', items: [], mode: 'context',
      filterAgent: '', filterType: '', filterStatus: '', disputedOnly: false, byAgentId: '',
      saving: false, formError: '',
    };
    state.strategicContextUi.beliefsRuntime = {
      loading: false,
      error: '',
      data: null,
      selectedBeliefId: '',
      timelineLoading: false,
      timeline: [],
      relationsLoading: false,
      relations: [],
    };
    state.strategicContextUi.agentContextMemory = {
      open: false, agentId: 'pm', loading: false, saving: false, consolidating: false,
      recentNoteBusy: false, contradictionBusy: false, deprecateBusy: false, compactBusy: false,
      error: '', content: '', appendNote: '', appendSection: 'recent',
      maintenanceRecentNote: '', maintenanceSessionId: '', contradictionText: '', contradictionSource: '',
      deprecateText: '', deprecateReason: '',
    };
    state.strategicContextUi.situatedAgentChat = {
      open: false, conversationId: null, agentId: 'pm', messages: [], input: '', loading: false,
      includeMemory: true, includeRecentDecisions: true, includeSocialContext: true, lastWarnings: [],
      lastCognitiveRuntime: null, lastPromptTrace: null, error: '',
    };
    state.strategicContextUi.memoryGovernance = {
      panelOpen: false, loading: false, error: '', data: null,
    };
    state.strategicContextUi.contextDeepCompare = {
      panelOpen: false, leftId: null, rightId: null,
      includeSessions: true, includeDecisions: true, includeAgentMemories: false,
      includeSocialDynamics: true, includeTimeline: true,
      loading: false, error: '', result: null,
    };
    window.DecisionArena.render?.();
  });

  registerAction('load-workspace-timeline', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.workspaceTimeline = { loading: true, error: '', data: null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getTimeline(id, {});
      ui.workspaceTimeline = { loading: false, error: '', data };
    } catch (err) {
      ui.workspaceTimeline = { loading: false, error: String(err?.message || err), data: null };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-workspace-timeline-legacy', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.workspaceTimeline = { loading: true, error: '', data: null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getTimeline(id, { includeLegacy: true });
      ui.workspaceTimeline = { loading: false, error: '', data };
    } catch (err) {
      ui.workspaceTimeline = { loading: false, error: String(err?.message || err), data: null };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-strategic-narrative', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.strategicNarrative = { loading: true, error: '', data: null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getNarrative(id);
      ui.strategicNarrative = { loading: false, error: '', data };
    } catch (err) {
      ui.strategicNarrative = { loading: false, error: String(err?.message || err), data: null };
    }
    window.DecisionArena.render?.();
  });

  registerAction('recompute-strategic-narrative', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.strategicNarrative = { ...ui.strategicNarrative, loading: true, error: '' };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.recomputeNarrative(id);
      ui.strategicNarrative = { loading: false, error: '', data };
      state.toast = window.i18n?.t('contexts.strategicNarrative.recomputeToast') ?? 'Narrative mise à jour.';
    } catch (err) {
      ui.strategicNarrative = { loading: false, error: String(err?.message || err), data: ui.strategicNarrative?.data ?? null };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-context-beliefs', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = {
      ...ui.beliefsEngine,
      loading: true,
      error: '',
      mode: 'context',
      byAgentId: '',
      formError: '',
    };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefs(id, {});
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        loading: false,
        error: '',
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
        mode: 'context',
      };
    } catch (err) {
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        loading: false,
        error: String(err?.message || err),
        items: [],
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-agent-beliefs', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    let aid = element?.dataset?.agentId;
    if (!aid && cid) {
      const sel = document.getElementById(`da-beliefs-agent-${cid}`);
      aid = sel?.value ? String(sel.value).trim() : '';
    }
    if (!cid || !aid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, loading: true, error: '', mode: 'agent', byAgentId: aid, formError: '' };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefsByAgent(cid, aid);
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        loading: false,
        error: '',
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
        mode: 'agent',
        byAgentId: aid,
      };
    } catch (err) {
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        loading: false,
        error: String(err?.message || err),
        items: [],
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('filter-beliefs-by-agent', ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, filterAgent: v };
    window.DecisionArena.render?.();
  });

  registerAction('filter-beliefs-by-type', ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, filterType: v };
    window.DecisionArena.render?.();
  });

  registerAction('filter-beliefs-by-status', ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, filterStatus: v };
    window.DecisionArena.render?.();
  });

  registerAction('toggle-beliefs-disputed-only', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const next = element?.type === 'checkbox' ? !!element.checked : !ui.beliefsEngine?.disputedOnly;
    ui.beliefsEngine = { ...ui.beliefsEngine, disputedOnly: next };
    window.DecisionArena.render?.();
  });

  registerAction('create-belief', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const root = document.querySelector(`[data-beliefs-form-root="${cid}"]`);
    if (!root) return;
    const fd = new FormData(root);
    const beliefText = String(fd.get('belief_text') || '').trim();
    if (!beliefText) {
      ui.beliefsEngine = { ...ui.beliefsEngine, formError: window.i18n?.t('contexts.beliefsEngine.errText') ?? 'Texte requis.' };
      window.DecisionArena.render?.();
      return;
    }
    const body = {
      belief_text: beliefText,
      belief_type: String(fd.get('belief_type') || 'hypothesis'),
      agent_id: String(fd.get('agent_id') || '').trim() || null,
      confidence: Number.isFinite(Number.parseFloat(String(fd.get('confidence') ?? '')))
        ? Number.parseFloat(String(fd.get('confidence')))
        : 0.6,
      status: String(fd.get('status') || 'proposed'),
      supporting_agents: String(fd.get('supporting_agents') || ''),
      disagreeing_agents: String(fd.get('disagreeing_agents') || ''),
      source_type: String(fd.get('source_type') || '').trim() || null,
      source_reference_id: String(fd.get('source_reference_id') || '').trim() || null,
      created_by: String(fd.get('created_by') || 'user').trim() || 'user',
    };
    ui.beliefsEngine = { ...ui.beliefsEngine, saving: true, formError: '' };
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.createBelief(cid, body);
      state.toast = window.i18n?.t('contexts.beliefsEngine.created') ?? 'Belief créé.';
      root.reset();
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefs(cid, {});
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        saving: false,
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
        mode: 'context',
        byAgentId: '',
      };
    } catch (err) {
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        saving: false,
        formError: String(err?.message || err),
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('archive-belief', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const bid = element?.dataset?.beliefId;
    if (!cid || !bid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, saving: true, formError: '' };
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.archiveBelief(cid, bid);
      state.toast = window.i18n?.t('contexts.beliefsEngine.archived') ?? 'Archivé.';
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefs(cid, {});
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        saving: false,
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
      };
    } catch (err) {
      ui.beliefsEngine = { ...ui.beliefsEngine, saving: false, formError: String(err?.message || err) };
    }
    window.DecisionArena.render?.();
  });

  registerAction('deprecate-belief', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const bid = element?.dataset?.beliefId;
    if (!cid || !bid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, saving: true, formError: '' };
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.deprecateBelief(cid, bid);
      state.toast = window.i18n?.t('contexts.beliefsEngine.deprecated') ?? 'Déprécié.';
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefs(cid, {});
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        saving: false,
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
      };
    } catch (err) {
      ui.beliefsEngine = { ...ui.beliefsEngine, saving: false, formError: String(err?.message || err) };
    }
    window.DecisionArena.render?.();
  });

  registerAction('update-belief', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const bid = element?.dataset?.beliefId;
    if (!cid || !bid) return;
    const status = String(element?.dataset?.nextStatus || '').trim();
    if (!status) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsEngine = { ...ui.beliefsEngine, saving: true, formError: '' };
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.updateBelief(cid, bid, { status });
      state.toast = window.i18n?.t('contexts.beliefsEngine.updated') ?? 'Mis à jour.';
      const data = await window.DecisionArena.services.StrategicContextService.listBeliefs(cid, {});
      ui.beliefsEngine = {
        ...ui.beliefsEngine,
        saving: false,
        items: Array.isArray(data?.beliefs) ? data.beliefs : [],
      };
    } catch (err) {
      ui.beliefsEngine = { ...ui.beliefsEngine, saving: false, formError: String(err?.message || err) };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-beliefs-runtime', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsRuntime = {
      ...(ui.beliefsRuntime || {}),
      loading: true,
      error: '',
      data: null,
      timeline: [],
      relations: [],
    };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getBeliefsRuntime(cid);
      const first = Array.isArray(data?.beliefs) && data.beliefs.length ? String(data.beliefs[0].id || '') : '';
      ui.beliefsRuntime = {
        ...(ui.beliefsRuntime || {}),
        loading: false,
        error: '',
        data,
        selectedBeliefId: first,
      };
      if (first) {
        ui.beliefsRuntime.timelineLoading = true;
        ui.beliefsRuntime.relationsLoading = true;
        window.DecisionArena.render?.();
        const [tRes, rRes] = await Promise.all([
          window.DecisionArena.services.StrategicContextService.getBeliefTimeline(first, cid, 120).catch(() => ({ timeline: [] })),
          window.DecisionArena.services.StrategicContextService.getBeliefRelations(first, cid).catch(() => ({ relations: [] })),
        ]);
        ui.beliefsRuntime.timeline = Array.isArray(tRes?.timeline) ? tRes.timeline : [];
        ui.beliefsRuntime.relations = Array.isArray(rRes?.relations) ? rRes.relations : [];
        ui.beliefsRuntime.timelineLoading = false;
        ui.beliefsRuntime.relationsLoading = false;
      }
    } catch (err) {
      ui.beliefsRuntime = {
        ...(ui.beliefsRuntime || {}),
        loading: false,
        error: String(err?.message || err),
        data: null,
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('select-runtime-belief', async ({ element }) => {
    const bid = String(element?.dataset?.beliefId || '').trim();
    const cid = String(element?.dataset?.contextId || '').trim();
    if (!bid || !cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.beliefsRuntime = {
      ...(ui.beliefsRuntime || {}),
      selectedBeliefId: bid,
      timelineLoading: true,
      relationsLoading: true,
      error: '',
    };
    window.DecisionArena.render?.();
    try {
      const [tRes, rRes] = await Promise.all([
        window.DecisionArena.services.StrategicContextService.getBeliefTimeline(bid, cid, 200),
        window.DecisionArena.services.StrategicContextService.getBeliefRelations(bid, cid),
      ]);
      ui.beliefsRuntime.timeline = Array.isArray(tRes?.timeline) ? tRes.timeline : [];
      ui.beliefsRuntime.relations = Array.isArray(rRes?.relations) ? rRes.relations : [];
      ui.beliefsRuntime.timelineLoading = false;
      ui.beliefsRuntime.relationsLoading = false;
    } catch (err) {
      ui.beliefsRuntime.timelineLoading = false;
      ui.beliefsRuntime.relationsLoading = false;
      ui.beliefsRuntime.error = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  const reloadMemoryCompilations = async (state, contextId) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const mc = ui.memoryCompiler || {};
    const q = {};
    if (String(mc.filterType || '').trim()) q.compilation_type = String(mc.filterType).trim();
    if (String(mc.filterStatus || '').trim()) q.status = String(mc.filterStatus).trim();
    const data = await window.DecisionArena.services.StrategicContextService.listMemoryCompilations(contextId, q);
    ui.memoryCompiler = {
      ...mc,
      loading: false,
      error: '',
      items: Array.isArray(data?.compilations) ? data.compilations : [],
    };
  };

  registerAction('load-memory-compilations', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryCompiler = { ...(ui.memoryCompiler || {}), loading: true, error: '' };
    window.DecisionArena.render?.();
    try {
      await reloadMemoryCompilations(state, cid);
    } catch (err) {
      ui.memoryCompiler = {
        ...(ui.memoryCompiler || {}),
        loading: false,
        error: String(err?.message || err),
        items: [],
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('filter-memory-compilations-type', async ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryCompiler = { ...(ui.memoryCompiler || {}), filterType: v };
    const cid = String(ui.selectedContextId || '').trim();
    if (cid) {
      ui.memoryCompiler = { ...ui.memoryCompiler, loading: true };
      window.DecisionArena.render?.();
      try {
        await reloadMemoryCompilations(state, cid);
      } catch (_) { /* ignore */ }
    }
    window.DecisionArena.render?.();
  });

  registerAction('filter-memory-compilations-status', async ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryCompiler = { ...(ui.memoryCompiler || {}), filterStatus: v };
    const cid = String(ui.selectedContextId || '').trim();
    if (cid) {
      ui.memoryCompiler = { ...ui.memoryCompiler, loading: true };
      window.DecisionArena.render?.();
      try {
        await reloadMemoryCompilations(state, cid);
      } catch (_) { /* ignore */ }
    }
    window.DecisionArena.render?.();
  });

  registerAction('compile-memory', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const sel = document.querySelector(`select[data-mc-compile-type="${cid}"]`);
    const compilationType = String(sel?.value || 'strategic').trim() || 'strategic';
    ui.memoryCompiler = { ...(ui.memoryCompiler || {}), compileSaving: true, error: '' };
    window.DecisionArena.render?.();
    try {
      const res = await window.DecisionArena.services.StrategicContextService.compileMemory(cid, {
        compilation_type: compilationType,
        supersede_previous: true,
        created_by: 'ui',
      });
      state.toast = window.i18n?.t('contexts.memoryCompiler.compiled') ?? 'Compilation créée.';
      const comp = res?.compilation;
      ui.memoryCompiler = {
        ...(ui.memoryCompiler || {}),
        compileSaving: false,
        selectedCompilationId: comp?.id ? String(comp.id) : null,
        detail: comp || null,
      };
      await reloadMemoryCompilations(state, cid);
    } catch (err) {
      ui.memoryCompiler = {
        ...(ui.memoryCompiler || {}),
        compileSaving: false,
        error: String(err?.message || err),
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-memory-compilation-detail', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const compId = element?.dataset?.compilationId;
    if (!cid || !compId) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryCompiler = {
      ...(ui.memoryCompiler || {}),
      detailLoading: true,
      selectedCompilationId: String(compId),
      error: '',
    };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getMemoryCompilation(cid, compId);
      ui.memoryCompiler = {
        ...(ui.memoryCompiler || {}),
        detailLoading: false,
        detail: data?.compilation || null,
      };
    } catch (err) {
      ui.memoryCompiler = {
        ...(ui.memoryCompiler || {}),
        detailLoading: false,
        error: String(err?.message || err),
        detail: null,
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('archive-compilation', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const compId = element?.dataset?.compilationId;
    if (!cid || !compId) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    try {
      await window.DecisionArena.services.StrategicContextService.archiveMemoryCompilation(cid, compId);
      state.toast = window.i18n?.t('contexts.memoryCompiler.archived') ?? 'Compilation archivée.';
      if (String(ui.memoryCompiler?.selectedCompilationId) === String(compId)) {
        ui.memoryCompiler = { ...(ui.memoryCompiler || {}), detail: null, selectedCompilationId: null };
      }
      await reloadMemoryCompilations(state, cid);
    } catch (err) {
      ui.memoryCompiler = { ...(ui.memoryCompiler || {}), error: String(err?.message || err) };
    }
    window.DecisionArena.render?.();
  });

  registerAction('supersede-compilation', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const compId = element?.dataset?.compilationId;
    if (!cid || !compId) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    try {
      await window.DecisionArena.services.StrategicContextService.supersedeMemoryCompilation(cid, compId);
      state.toast = window.i18n?.t('contexts.memoryCompiler.superseded') ?? 'Compilation marquée superseded.';
      await reloadMemoryCompilations(state, cid);
      if (String(ui.memoryCompiler?.selectedCompilationId) === String(compId)) {
        const data = await window.DecisionArena.services.StrategicContextService.getMemoryCompilation(cid, compId).catch(() => ({}));
        ui.memoryCompiler = { ...(ui.memoryCompiler || {}), detail: data?.compilation || ui.memoryCompiler?.detail };
      }
    } catch (err) {
      ui.memoryCompiler = { ...(ui.memoryCompiler || {}), error: String(err?.message || err) };
    }
    window.DecisionArena.render?.();
  });

  const reloadContextSnapshots = async (state, contextId) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const cs = ui.contextSnapshots || {};
    const data = await window.DecisionArena.services.StrategicContextService.listContextSnapshots(contextId, { limit: 80 });
    ui.contextSnapshots = {
      ...cs,
      loading: false,
      error: '',
      items: Array.isArray(data?.snapshots) ? data.snapshots : [],
    };
  };

  registerAction('load-context-snapshots', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), loading: true, error: '' };
    window.DecisionArena.render?.();
    try {
      await reloadContextSnapshots(state, cid);
    } catch (err) {
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        loading: false,
        error: String(err?.message || err),
        items: [],
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('create-context-snapshot', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const sel = document.querySelector(`select[data-cs-snapshot-type="${cid}"]`);
    const snapshotType = String(sel?.value || 'manual').trim() || 'manual';
    const titleInp = document.querySelector(`input[data-cs-snapshot-title="${cid}"]`);
    const title = String(titleInp?.value || '').trim();
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), createSaving: true, error: '' };
    window.DecisionArena.render?.();
    try {
      const res = await window.DecisionArena.services.StrategicContextService.createContextSnapshot(cid, {
        snapshot_type: snapshotType,
        title: title || undefined,
        created_by: 'ui',
      });
      state.toast = window.i18n?.t('contexts.contextSnapshots.created') ?? 'Snapshot créé.';
      const snap = res?.snapshot;
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        createSaving: false,
        selectedSnapshotId: snap?.id ? String(snap.id) : null,
        detail: snap || null,
      };
      await reloadContextSnapshots(state, cid);
    } catch (err) {
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        createSaving: false,
        error: String(err?.message || err),
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-context-snapshot-detail', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const sid = element?.dataset?.snapshotId;
    if (!cid || !sid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextSnapshots = {
      ...(ui.contextSnapshots || {}),
      detailLoading: true,
      selectedSnapshotId: String(sid),
      error: '',
    };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getContextSnapshot(cid, sid);
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        detailLoading: false,
        detail: data?.snapshot || null,
      };
    } catch (err) {
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        detailLoading: false,
        error: String(err?.message || err),
        detail: null,
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-context-snapshot-compare-left', ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), compareLeft: v };
    window.DecisionArena.render?.();
  });

  registerAction('set-context-snapshot-compare-right', ({ element }) => {
    const v = String(element?.value ?? '').trim();
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), compareRight: v };
    window.DecisionArena.render?.();
  });

  registerAction('compare-context-snapshots', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const a = String(ui.contextSnapshots?.compareLeft || '').trim();
    const b = String(ui.contextSnapshots?.compareRight || '').trim();
    if (!a || !b || a === b) {
      ui.contextSnapshots = { ...(ui.contextSnapshots || {}), error: window.i18n?.t('contexts.contextSnapshots.compareNeedTwo') ?? 'Sélectionnez deux snapshots distincts.' };
      window.DecisionArena.render?.();
      return;
    }
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), compareLoading: true, compareResult: null, error: '' };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.compareContextSnapshots(cid, a, b);
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        compareLoading: false,
        compareResult: data?.diff || null,
      };
    } catch (err) {
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        compareLoading: false,
        error: String(err?.message || err),
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('load-context-longitudinal-view', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextSnapshots = { ...(ui.contextSnapshots || {}), longLoading: true, longError: '', longViewMarkdown: '' };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getContextSnapshotsLongitudinal(cid, { limit: 14 });
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        longLoading: false,
        longViewMarkdown: String(data?.view_markdown || ''),
      };
    } catch (err) {
      ui.contextSnapshots = {
        ...(ui.contextSnapshots || {}),
        longLoading: false,
        longError: String(err?.message || err),
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('activate-strategic-context', async ({ element }) => {
    const id = element?.dataset?.contextId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    try {
      const res = await window.DecisionArena.services.StrategicContextService.activate(id);
      const ac = res?.active_context ?? null;
      state.activeStrategicContext = ac;
      state.activeStrategicContextId = ac?.context_id ? String(ac.context_id) : null;
      state.toast = window.i18n?.t('contexts.activateToast') ?? 'Contexte activé.';
      await refreshList(state);
      try {
        await window.DecisionArena.services.LoaderService.loadSessions();
      } catch (_) { /* liste sessions best-effort */ }
    } catch (err) {
      state.error = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('compare-strategic-contexts', ({ element }) => {
    const other = element?.dataset?.otherContextId;
    if (!other) return;
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    const base = ui.selectedContextId;
    if (!base || base === other) return;
    ui.compareOpen = true;
    ui.compareLeftId = base;
    ui.compareRightId = other;
    window.DecisionArena.render?.();
  });

  registerAction('close-strategic-context-compare', () => {
    const state = window.DecisionArena.store.state;
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.compareOpen = false;
    ui.compareLeftId = null;
    ui.compareRightId = null;
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
    const nextOpen = !ui.memoryMdOpen;
    const previousContextId = String(ui.memoryMdContextId || '').trim();
    const nextContextId = String(cid).trim();
    const contextChanged = previousContextId !== '' && previousContextId !== nextContextId;
    ui.memoryMdOpen = nextOpen;
    ui.memoryMdError = '';
    // Track the context the panel was opened for so a perspective change
    // can refresh the preview without depending on selectedContextId, which
    // can stay null when the user has not explicitly clicked a card yet.
    ui.memoryMdContextId = cid;
    if (nextOpen) {
      if (contextChanged) {
        ui.memoryMdContent = '';
      }
      window.DecisionArena.render?.();
      if (!ui.memoryMdContent) {
        await loadContextMemoryMd(state, cid);
      } else {
        window.DecisionArena.render?.();
      }
      try {
        requestAnimationFrame(() => {
          const panel = document.querySelector('[data-snapshot-panel="strategic-context"]');
          if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      } catch (_) {}
      return;
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

    // Safety: if the user types then clicks "Enregistrer" immediately,
    // ensure we capture the latest DOM values before validating.
    const titleInput = document.querySelector('input[data-action="set-context-form-field"][data-field="title"]');
    const descInput = document.querySelector('textarea[data-action="set-context-form-field"][data-field="description"]');
    const statusInput = document.querySelector('select[data-action="set-context-form-field"][data-field="status"]');
    ui.formValues = ui.formValues || { title: '', description: '', status: 'active' };
    if (titleInput) ui.formValues.title = titleInput.value;
    if (descInput) ui.formValues.description = descInput.value;
    if (statusInput) ui.formValues.status = statusInput.value;

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

  const defaultAgentMemoryUi = () => ({
    open: false,
    agentId: 'pm',
    loading: false,
    saving: false,
    consolidating: false,
    recentNoteBusy: false,
    contradictionBusy: false,
    deprecateBusy: false,
    compactBusy: false,
    error: '',
    content: '',
    appendNote: '',
    appendSection: 'recent',
    maintenanceRecentNote: '',
    maintenanceSessionId: '',
    contradictionText: '',
    contradictionSource: '',
    deprecateText: '',
    deprecateReason: '',
  });

  const ensureAgentMemoryUi = (state) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.agentContextMemory = { ...defaultAgentMemoryUi(), ...(ui.agentContextMemory || {}) };
    return ui.agentContextMemory;
  };

  registerAction('open-agent-context-memory', async () => {
    const state = window.DecisionArena.store.state;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    am.open = true;
    am.error = '';
    am.loading = true;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(selectedId, am.agentId);
      am.content = String(data?.content ?? '');
    } catch (err) {
      am.error = String(err?.message || err);
      am.content = '';
    }
    am.loading = false;
    window.DecisionArena.render?.();
  });

  registerAction('add-agent-context-recent-note', async () => {
    const state = window.DecisionArena.store.state;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    const note = String(am.maintenanceRecentNote || '').trim();
    if (!note) {
      am.error = window.i18n?.t('contexts.agentMemory.emptyRecentNote') ?? 'Note required.';
      window.DecisionArena.render?.();
      return;
    }
    const sid = String(am.maintenanceSessionId || '').trim();
    am.error = '';
    am.recentNoteBusy = true;
    window.DecisionArena.render?.();
    try {
      const payload = { note };
      if (sid) payload.session_id = sid;
      const data = await window.DecisionArena.services.StrategicContextService.postAgentContextMemoryRecentNote(
        selectedId,
        am.agentId,
        payload,
      );
      am.maintenanceRecentNote = '';
      am.maintenanceSessionId = '';
      if (typeof data?.memory === 'string') {
        am.content = data.memory;
      } else if (state.uiMode === 'expert') {
        const refreshed = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(selectedId, am.agentId);
        am.content = String(refreshed?.content ?? '');
      }
      const w = Array.isArray(data?.warnings) ? data.warnings : [];
      state.toast = window.i18n?.t('contexts.agentMemory.recentNoteDone') ?? 'Note enregistrée.';
      if (w.length) {
        am.error = w.join(' · ');
      }
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.recentNoteBusy = false;
    window.DecisionArena.render?.();
  });

  registerAction('add-agent-context-contradiction', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    const text = String(am.contradictionText || '').trim();
    if (!text) {
      am.error = window.i18n?.t('contexts.agentMemory.emptyContradiction') ?? 'Text required.';
      window.DecisionArena.render?.();
      return;
    }
    am.error = '';
    am.contradictionBusy = true;
    window.DecisionArena.render?.();
    try {
      const payload = { contradiction: text };
      const src = String(am.contradictionSource || '').trim();
      if (src) payload.source = src;
      const data = await window.DecisionArena.services.StrategicContextService.postAgentContextMemoryContradiction(
        selectedId,
        am.agentId,
        payload,
      );
      am.contradictionText = '';
      am.contradictionSource = '';
      if (typeof data?.memory === 'string') {
        am.content = data.memory;
      }
      const w = Array.isArray(data?.warnings) ? data.warnings : [];
      state.toast = window.i18n?.t('contexts.agentMemory.contradictionDone') ?? 'Contradiction enregistrée.';
      if (w.length) am.error = w.join(' · ');
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.contradictionBusy = false;
    window.DecisionArena.render?.();
  });

  registerAction('deprecate-agent-context-memory-text', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    const text = String(am.deprecateText || '').trim();
    if (!text) {
      am.error = window.i18n?.t('contexts.agentMemory.emptyDeprecate') ?? 'Text required.';
      window.DecisionArena.render?.();
      return;
    }
    am.error = '';
    am.deprecateBusy = true;
    window.DecisionArena.render?.();
    try {
      const payload = { text };
      const reason = String(am.deprecateReason || '').trim();
      if (reason) payload.reason = reason;
      const data = await window.DecisionArena.services.StrategicContextService.postAgentContextMemoryDeprecate(
        selectedId,
        am.agentId,
        payload,
      );
      am.deprecateText = '';
      am.deprecateReason = '';
      if (typeof data?.memory === 'string') {
        am.content = data.memory;
      }
      const w = Array.isArray(data?.warnings) ? data.warnings : [];
      state.toast = window.i18n?.t('contexts.agentMemory.deprecateDone') ?? 'Entrée marquée obsolète.';
      if (w.length) am.error = w.join(' · ');
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.deprecateBusy = false;
    window.DecisionArena.render?.();
  });

  registerAction('compact-agent-context-memory', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    am.error = '';
    am.compactBusy = true;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.postAgentContextMemoryCompact(selectedId, am.agentId);
      if (typeof data?.memory === 'string') {
        am.content = data.memory;
      }
      const w = Array.isArray(data?.warnings) ? data.warnings : [];
      state.toast = window.i18n?.t('contexts.agentMemory.compactDone') ?? 'Mémoire compactée.';
      if (w.length) am.error = w.join(' · ');
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.compactBusy = false;
    window.DecisionArena.render?.();
  });

  registerAction('close-agent-context-memory', () => {
    const state = window.DecisionArena.store.state;
    const am = ensureAgentMemoryUi(state);
    am.open = false;
    am.error = '';
    window.DecisionArena.render?.();
  });

  registerAction('set-agent-context-memory-field', ({ element }) => {
    const field = element?.dataset?.field;
    if (!field) return;
    const state = window.DecisionArena.store.state;
    const am = ensureAgentMemoryUi(state);
    if (field === 'content') {
      am.content = element.value;
    } else if (field === 'appendNote') {
      am.appendNote = element.value;
    } else if (field === 'appendSection') {
      am.appendSection = element.value === 'pending' ? 'pending' : 'recent';
    } else if (field === 'maintenanceRecentNote') {
      am.maintenanceRecentNote = element.value;
    } else if (field === 'maintenanceSessionId') {
      am.maintenanceSessionId = element.value;
    } else if (field === 'contradictionText') {
      am.contradictionText = element.value;
    } else if (field === 'contradictionSource') {
      am.contradictionSource = element.value;
    } else if (field === 'deprecateText') {
      am.deprecateText = element.value;
    } else if (field === 'deprecateReason') {
      am.deprecateReason = element.value;
    }
    window.DecisionArena.render?.();
  });

  registerAction('agent-context-memory-select-agent', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    am.agentId = String(element?.value || 'pm').trim() || 'pm';
    am.error = '';
    am.loading = true;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(selectedId, am.agentId);
      am.content = String(data?.content ?? '');
    } catch (err) {
      am.error = String(err?.message || err);
      am.content = '';
    }
    am.loading = false;
    window.DecisionArena.render?.();
  });

  registerAction('save-agent-context-memory', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    am.saving = true;
    am.error = '';
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.putAgentContextMemory(selectedId, am.agentId, am.content);
      state.toast = window.i18n?.t('contexts.agentMemory.saved') ?? 'Saved.';
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.saving = false;
    window.DecisionArena.render?.();
  });

  registerAction('append-agent-context-memory-note', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    const note = String(am.appendNote || '').trim();
    if (!note) {
      am.error = window.i18n?.t('contexts.agentMemory.emptyNote') ?? 'Note required.';
      window.DecisionArena.render?.();
      return;
    }
    am.error = '';
    am.loading = true;
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.appendAgentContextMemoryNote(selectedId, am.agentId, {
        note,
        section: am.appendSection === 'pending' ? 'pending' : 'recent',
      });
      am.appendNote = '';
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(selectedId, am.agentId);
      am.content = String(data?.content ?? '');
      state.toast = window.i18n?.t('contexts.agentMemory.appended') ?? 'Note added.';
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.loading = false;
    window.DecisionArena.render?.();
  });

  const defaultDeepCompareUi = () => ({
    panelOpen: false,
    leftId: null,
    rightId: null,
    includeSessions: true,
    includeDecisions: true,
    includeAgentMemories: false,
    includeSocialDynamics: true,
    includeTimeline: true,
    loading: false,
    error: '',
    result: null,
  });

  const defaultMemoryGovernanceUi = () => ({
    panelOpen: false,
    loading: false,
    error: '',
    data: null,
  });

  const ensureMemoryGovernanceUi = (state) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.memoryGovernance = { ...defaultMemoryGovernanceUi(), ...(ui.memoryGovernance || {}) };
    return ui.memoryGovernance;
  };

  const ensureDeepCompareUi = (state) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.contextDeepCompare = { ...defaultDeepCompareUi(), ...(ui.contextDeepCompare || {}) };
    return ui.contextDeepCompare;
  };

  registerAction('toggle-context-deep-compare-panel', () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const dc = ensureDeepCompareUi(state);
    dc.panelOpen = !dc.panelOpen;
    if (dc.panelOpen && !dc.leftId && !dc.rightId) {
      const list = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
      if (list.length >= 1) dc.leftId = String(list[0].context_id || '').trim() || null;
      if (list.length >= 2) dc.rightId = String(list[1].context_id || '').trim() || null;
    }
    if (!dc.panelOpen) {
      dc.error = '';
    }
    window.DecisionArena.render?.();
  });

  registerAction('select-compare-left-context', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    const v = String(element?.value || '').trim();
    dc.leftId = v || null;
    dc.result = null;
    window.DecisionArena.render?.();
  });

  registerAction('select-compare-right-context', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    const v = String(element?.value || '').trim();
    dc.rightId = v || null;
    dc.result = null;
    window.DecisionArena.render?.();
  });

  registerAction('toggle-context-compare-option', ({ element }) => {
    const opt = String(element?.dataset?.option || '').trim();
    if (!opt) return;
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    const on = !!element?.checked;
    if (opt === 'sessions') dc.includeSessions = on;
    else if (opt === 'decisions') dc.includeDecisions = on;
    else if (opt === 'agent_memories') dc.includeAgentMemories = on;
    else if (opt === 'social') dc.includeSocialDynamics = on;
    else if (opt === 'timeline') dc.includeTimeline = on;
    dc.result = null;
    window.DecisionArena.render?.();
  });

  registerAction('run-strategic-context-compare', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const dc = ensureDeepCompareUi(state);
    const left = String(dc.leftId || '').trim();
    const right = String(dc.rightId || '').trim();
    if (!left || !right) {
      dc.error = window.i18n?.t('contexts.deepCompare.needTwo') ?? 'Select two contexts.';
      window.DecisionArena.render?.();
      return;
    }
    if (left === right) {
      dc.error = window.i18n?.t('contexts.deepCompare.sameId') ?? 'Contexts must differ.';
      window.DecisionArena.render?.();
      return;
    }
    dc.loading = true;
    dc.error = '';
    dc.result = null;
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.compareContexts({
        left_context_id: left,
        right_context_id: right,
        include_sessions: dc.includeSessions,
        include_decisions: dc.includeDecisions,
        include_agent_memories: dc.includeAgentMemories,
        include_social_dynamics: dc.includeSocialDynamics,
        include_timeline: dc.includeTimeline,
      });
      dc.result = data;
      state.toast = window.i18n?.t('contexts.deepCompare.done') ?? 'Comparison ready.';
    } catch (err) {
      dc.error = String(err?.message || err);
    }
    dc.loading = false;
    window.DecisionArena.render?.();
  });

  registerAction('copy-strategic-context-compare-markdown', async () => {
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    const text = String(dc.result?.markdown || '');
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
    state.toast = window.i18n?.t('contexts.deepCompare.copied') ?? 'Markdown copied.';
    window.DecisionArena.render?.();
  });

  registerAction('download-strategic-context-compare-markdown', () => {
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    const text = String(dc.result?.markdown || '');
    if (!text) return;
    const blob = new Blob([text], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `strategic-context-compare-${String(dc.leftId || '').slice(0, 8)}-${String(dc.rightId || '').slice(0, 8)}.md`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    state.toast = window.i18n?.t('contexts.deepCompare.downloaded') ?? 'Download started.';
    window.DecisionArena.render?.();
  });

  registerAction('download-strategic-context-compare-json', () => {
    const state = window.DecisionArena.store.state;
    const dc = ensureDeepCompareUi(state);
    if (!dc.result) return;
    const text = JSON.stringify(dc.result, null, 2);
    const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `strategic-context-compare-${String(dc.leftId || '').slice(0, 8)}-${String(dc.rightId || '').slice(0, 8)}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    state.toast = window.i18n?.t('contexts.deepCompare.downloaded') ?? 'Download started.';
    window.DecisionArena.render?.();
  });

  registerAction('toggle-memory-governance-panel', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const contextId = resolveSelectedContextId(state);
    if (!contextId) return;
    const mg = ensureMemoryGovernanceUi(state);
    mg.panelOpen = !mg.panelOpen;
    if (mg.panelOpen && !mg.data && !mg.loading) {
      mg.loading = true;
      mg.error = '';
      window.DecisionArena.render?.();
      try {
        mg.data = await window.DecisionArena.services.StrategicContextService.getMemoryGovernance(contextId, { limit: 180 });
      } catch (err) {
        mg.error = String(err?.message || err);
      }
      mg.loading = false;
    }
    window.DecisionArena.render?.();
  });

  registerAction('refresh-memory-governance', async () => {
    const state = window.DecisionArena.store.state;
    const contextId = resolveSelectedContextId(state);
    if (!contextId) return;
    const mg = ensureMemoryGovernanceUi(state);
    mg.loading = true;
    mg.error = '';
    window.DecisionArena.render?.();
    try {
      mg.data = await window.DecisionArena.services.StrategicContextService.getMemoryGovernance(contextId, { limit: 220 });
      state.toast = window.i18n?.t('contexts.memoryGovernance.refreshed') ?? 'Memory governance updated.';
    } catch (err) {
      mg.error = String(err?.message || err);
    }
    mg.loading = false;
    window.DecisionArena.render?.();
  });

  const defaultSituatedChatUi = () => ({
    open: false,
    conversationId: null,
    agentId: 'pm',
    messages: [],
    input: '',
    loading: false,
    includeMemory: true,
    includeRecentDecisions: true,
    includeSocialContext: true,
    lastWarnings: [],
    lastCognitiveRuntime: null,
    lastPromptTrace: null,
    error: '',
  });

  const ensureSituatedChatUi = (state) => {
    const ui = state.strategicContextUi || (state.strategicContextUi = {});
    ui.situatedAgentChat = { ...defaultSituatedChatUi(), ...(ui.situatedAgentChat || {}) };
    return ui.situatedAgentChat;
  };

  registerAction('open-context-agent-chat', () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = window.i18n?.t('ui.expertModeRequired') ?? 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const sc = ensureSituatedChatUi(state);
    sc.open = true;
    sc.error = '';
    window.DecisionArena.render?.();
  });

  registerAction('close-context-agent-chat', () => {
    const state = window.DecisionArena.store.state;
    const sc = ensureSituatedChatUi(state);
    sc.open = false;
    sc.error = '';
    window.DecisionArena.render?.();
  });

  registerAction('reset-situated-agent-chat', () => {
    const state = window.DecisionArena.store.state;
    const sc = ensureSituatedChatUi(state);
    sc.conversationId = null;
    sc.messages = [];
    sc.lastWarnings = [];
    sc.lastCognitiveRuntime = null;
    sc.lastPromptTrace = null;
    sc.error = '';
    window.DecisionArena.render?.();
  });

  registerAction('set-situated-agent-chat-field', ({ element }) => {
    const field = element?.dataset?.field;
    if (!field) return;
    const state = window.DecisionArena.store.state;
    const sc = ensureSituatedChatUi(state);
    if (field === 'input') {
      sc.input = element.value;
    } else if (field === 'includeMemory') {
      sc.includeMemory = !!element.checked;
    } else if (field === 'includeRecentDecisions') {
      sc.includeRecentDecisions = !!element.checked;
    } else if (field === 'includeSocialContext') {
      sc.includeSocialContext = !!element.checked;
    }
    window.DecisionArena.render?.();
  });

  registerAction('select-context-agent-chat-agent', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const sc = ensureSituatedChatUi(state);
    sc.agentId = String(element?.value || 'pm').trim() || 'pm';
    sc.conversationId = null;
    sc.messages = [];
    sc.lastWarnings = [];
    sc.lastCognitiveRuntime = null;
    sc.lastPromptTrace = null;
    sc.error = '';
    window.DecisionArena.render?.();
  });

  registerAction('send-context-agent-message', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const cid = resolveSelectedContextId(state);
    if (!cid) return;
    const sc = ensureSituatedChatUi(state);
    const text = String(sc.input || '').trim();
    if (!text) {
      sc.error = window.i18n?.t('contexts.situatedChat.emptyMessage') ?? 'Message required.';
      window.DecisionArena.render?.();
      return;
    }
    sc.loading = true;
    sc.error = '';
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.StrategicContextService.chatWithAgent(cid, sc.agentId, {
        message: text,
        conversation_id: sc.conversationId || undefined,
        include_memory: sc.includeMemory,
        include_recent_decisions: sc.includeRecentDecisions,
        include_social_context: sc.includeSocialContext,
        language: (typeof window.i18n?.getLanguage === 'function' ? window.i18n.getLanguage() : null) || 'fr',
      });
      sc.conversationId = data.conversation_id || sc.conversationId;
      sc.messages = [...sc.messages, { role: 'user', content: text }, { role: 'assistant', content: String(data.answer || '') }];
      sc.input = '';
      sc.lastWarnings = Array.isArray(data.warnings) ? data.warnings : [];
      sc.lastCognitiveRuntime = data.cognitive_runtime && typeof data.cognitive_runtime === 'object'
        ? data.cognitive_runtime
        : null;
      sc.lastPromptTrace = data.prompt_injection_trace && typeof data.prompt_injection_trace === 'object'
        ? data.prompt_injection_trace
        : null;
      state.toast = window.i18n?.t('contexts.situatedChat.sent') ?? 'Réponse reçue.';
    } catch (err) {
      sc.error = String(err?.message || err);
    }
    sc.loading = false;
    window.DecisionArena.render?.();
  });

  registerAction('consolidate-agent-context-memory', async () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    const selectedId = resolveSelectedContextId(state);
    if (!selectedId) return;
    const am = ensureAgentMemoryUi(state);
    am.consolidating = true;
    am.error = '';
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.StrategicContextService.consolidateAgentContextMemory(selectedId, am.agentId);
      const data = await window.DecisionArena.services.StrategicContextService.getAgentContextMemory(selectedId, am.agentId);
      am.content = String(data?.content ?? '');
      state.toast = window.i18n?.t('contexts.agentMemory.consolidated') ?? 'Consolidated.';
    } catch (err) {
      am.error = String(err?.message || err);
    }
    am.consolidating = false;
    window.DecisionArena.render?.();
  });
}

export { registerStrategicContextsHandlers };


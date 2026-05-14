import { registerAction } from '../../core/events.js';

async function loadRoomsForExplorer(state, contextId) {
  state.decisionMemoryNav = state.decisionMemoryNav || {};
  state.decisionMemoryNav.roomsLoading = true;
  state.decisionMemoryNav.roomsError = null;
  window.DecisionArena.render?.();
  try {
    const data = await window.DecisionArena.services.StrategicContextService.listRooms(contextId);
    state.decisionMemoryNav.roomsByContextId = state.decisionMemoryNav.roomsByContextId || {};
    state.decisionMemoryNav.roomsByContextId[contextId] = Array.isArray(data.rooms) ? data.rooms : [];
  } catch (err) {
    state.decisionMemoryNav.roomsError = String(err.message || err);
    state.decisionMemoryNav.roomsByContextId = state.decisionMemoryNav.roomsByContextId || {};
    state.decisionMemoryNav.roomsByContextId[contextId] = [];
  } finally {
    state.decisionMemoryNav.roomsLoading = false;
  }
}

async function refreshDecisionMemoryExplorerNav(state) {
  state.decisionMemoryNav = state.decisionMemoryNav || {};
  state.decisionMemoryNav.contextsLoading = true;
  state.decisionMemoryNav.contextsError = null;
  try {
    const data = await window.DecisionArena.services.StrategicContextService.list({}, 120);
    state.decisionMemoryNav.contexts = Array.isArray(data.contexts) ? data.contexts : [];
    state.decisionMemoryNav.contextsError = null;
    const cid = state.decisionMemoryUi?.navStrategicContextId;
    if (cid) {
      await loadRoomsForExplorer(state, cid);
    }
  } catch (err) {
    state.decisionMemoryNav.contextsError = String(err.message || err);
  } finally {
    state.decisionMemoryNav.contextsLoading = false;
  }
}

function registerDecisionMemoryHandlers() {
  registerAction('load-decision-memories', async () => {
    const state = window.DecisionArena.store.state;
    state.decisionMemory = { loading: true, error: null, memories: state.decisionMemory?.memories ?? null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250, state.uiMode === 'expert' ? { include_archived: '1' } : {});
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      await refreshDecisionMemoryExplorerNav(state);
    } catch (err) {
      state.decisionMemory = { loading: false, error: String(err.message || err), memories: state.decisionMemory?.memories ?? null };
    }
    window.DecisionArena.render?.();
  });

  const runDecisionMemorySearch = async (state, qRaw) => {
    const q = String(qRaw || '').trim();
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.searchQ = q;
    if (!q) {
      state.decisionMemorySearch = null;
      window.DecisionArena.render?.();
      return;
    }

    const ui = state.decisionMemoryUi || {};
    const filters = ui.filters || {};
    const contextId = ui.navStrategicContextId || '';
    const roomId = ui.navDecisionChainId || '';
    const expert = state.uiMode === 'expert';

    state.decisionMemorySearch = { loading: true, error: null, results: [], search_mode: null, scope: null, warnings: null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.DecisionMemoryService.search({
        q,
        context_id: contextId || '',
        room_id: roomId || '',
        playbook_id: filters.playbook_id || '',
        decision_status: filters.decision_status || '',
        confidence: filters.confidence || '',
        memory_state: filters.memory_state || '',
        include_stale: expert && ui.includeStale ? '1' : (ui.includeStale ? '1' : '0'),
        expert_override: expert && ui.expertOverride ? '1' : '0',
        limit: 80,
        offset: 0,
      });
      state.decisionMemorySearch = {
        loading: false,
        error: null,
        results: Array.isArray(data.results) ? data.results : [],
        search_mode: data.search_mode || null,
        scope: data.scope || null,
        warnings: Array.isArray(data.warnings) ? data.warnings : null,
      };
    } catch (err) {
      state.decisionMemorySearch = { loading: false, error: String(err.message || err), results: [], search_mode: null, scope: null, warnings: null };
    }
    window.DecisionArena.render?.();
  };

  registerAction('set-decision-memory-search', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    const q = element?.value ?? '';
    await runDecisionMemorySearch(state, q);
  });

  registerAction('clear-decision-memory-search', async () => {
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.searchQ = '';
    state.decisionMemorySearch = null;
    window.DecisionArena.render?.();
  });

  registerAction('toggle-decision-memory-search-stale', () => {
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.includeStale = !state.decisionMemoryUi.includeStale;
    window.DecisionArena.render?.();
  });

  registerAction('toggle-decision-memory-search-expert-override', () => {
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.expertOverride = !state.decisionMemoryUi.expertOverride;
    window.DecisionArena.render?.();
  });

  const loadSimilarFor = async (state, memoryId) => {
    if (!memoryId) return;
    state.decisionMemorySimilar = state.decisionMemorySimilar || {};
    const cur = state.decisionMemorySimilar[memoryId] || {};
    const nextOpen = cur.open === true ? false : true;
    state.decisionMemorySimilar[memoryId] = { ...(cur || {}), open: nextOpen };
    window.DecisionArena.render?.();
    if (!nextOpen) return;

    const ui = state.decisionMemoryUi || {};
    const expert = state.uiMode === 'expert';
    const contextId = ui.navStrategicContextId || '';
    const roomId = ui.navDecisionChainId || '';
    const includeStale = expert && ui.includeStale ? '1' : '0';
    const expertOverride = expert && ui.expertOverride ? '1' : '0';

    state.decisionMemorySimilar[memoryId] = { open: true, loading: true, error: null, enabled: null, results: [], meta: null };
    window.DecisionArena.render?.();
    try {
      const data = await window.DecisionArena.services.DecisionMemoryService.similar({
        memory_id: memoryId,
        context_id: contextId || '',
        room_id: roomId || '',
        limit: 8,
        include_stale: includeStale,
        expert_override: expertOverride,
      });
      // Cache enabled flag so UI can hide/disable affordance.
      if (data && data.enabled === false) {
        state.semanticMemoryEnabled = false;
      } else if (data && data.enabled === true) {
        state.semanticMemoryEnabled = true;
      }
      state.decisionMemorySimilar[memoryId] = {
        open: true,
        loading: false,
        error: null,
        enabled: data?.enabled ?? true,
        results: Array.isArray(data?.results) ? data.results : [],
        meta: { provider: data?.provider || '', model: data?.model || '', warnings: data?.warnings || [] },
      };
    } catch (err) {
      state.decisionMemorySimilar[memoryId] = { open: true, loading: false, error: String(err.message || err), enabled: null, results: [], meta: null };
    }
    window.DecisionArena.render?.();
  };

  registerAction('toggle-similar-decisions', async ({ element }) => {
    const memoryId = element?.dataset?.memoryId;
    const state = window.DecisionArena.store.state;
    await loadSimilarFor(state, String(memoryId || ''));
  });

  registerAction('set-memory-explorer-context', async ({ element }) => {
    const v = String(element?.value ?? '');
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.navStrategicContextId = v === '' ? null : v;
    state.decisionMemoryUi.navDecisionChainId = null;
    window.DecisionArena.render?.();
    if (v) {
      await loadRoomsForExplorer(state, v);
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-memory-explorer-chain', ({ element }) => {
    const rid = element?.dataset?.roomId;
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.navDecisionChainId = rid === undefined || rid === '' ? null : String(rid);
    state.decisionMemoryUi.roomMemoryMdOpen = false;
    state.decisionMemoryUi.roomMemoryMdError = '';
    window.DecisionArena.render?.();
  });

  const loadRoomMemoryMd = async (state, roomId) => {
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.roomMemoryMdLoading = true;
    state.decisionMemoryUi.roomMemoryMdError = '';
    window.DecisionArena.render?.();
    try {
      const md = await window.DecisionArena.services.MemorySnapshotService.getRoomMarkdown(roomId, {
        expert: state.uiMode === 'expert' ? '1' : '',
      });
      state.decisionMemoryUi.roomMemoryMdContent = md;
    } catch (err) {
      state.decisionMemoryUi.roomMemoryMdError = String(err?.message || err);
      state.decisionMemoryUi.roomMemoryMdContent = '';
    } finally {
      state.decisionMemoryUi.roomMemoryMdLoading = false;
    }
  };

  registerAction('toggle-room-memory-md', async () => {
    const state = window.DecisionArena.store.state;
    const roomId = state.decisionMemoryUi?.navDecisionChainId;
    if (!roomId) return;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.roomMemoryMdOpen = !state.decisionMemoryUi.roomMemoryMdOpen;
    state.decisionMemoryUi.roomMemoryMdError = '';
    if (state.decisionMemoryUi.roomMemoryMdOpen && !state.decisionMemoryUi.roomMemoryMdContent) {
      await loadRoomMemoryMd(state, roomId);
    }
    window.DecisionArena.render?.();
  });

  registerAction('close-room-memory-md', () => {
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.roomMemoryMdOpen = false;
    window.DecisionArena.render?.();
  });

  registerAction('copy-room-memory-md', async () => {
    const state = window.DecisionArena.store.state;
    const ui = state.decisionMemoryUi || {};
    const text = String(ui.roomMemoryMdContent || '');
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
    state.toast = 'Copié: room memory.md';
    setTimeout(() => { try { state.toast = null; window.DecisionArena.render?.(); } catch (_) {} }, 2000);
    window.DecisionArena.render?.();
  });

  registerAction('open-decision-memory-for-context', async ({ element }) => {
    const cid = element?.dataset?.contextId;
    const mid = element?.dataset?.memoryId ? String(element.dataset.memoryId) : '';
    if (!cid) return;
    const state = window.DecisionArena.store.state;
    state.view = 'decision-memory';
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.navStrategicContextId = cid;
    state.decisionMemoryUi.navDecisionChainId = null;
    state.decisionMemoryUi.selectedMemoryId = mid || null;
    window.DecisionArena.render?.();
    try {
      if (!Array.isArray(state.decisionMemory?.memories)) {
        state.decisionMemory = { loading: true, error: null, memories: null };
        window.DecisionArena.render?.();
        const data = await window.DecisionArena.services.DecisionMemoryService.list(250, state.uiMode === 'expert' ? { include_archived: '1' } : {});
        state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      }
      await refreshDecisionMemoryExplorerNav(state);
    } catch (err) {
      state.decisionMemory = {
        loading: false,
        error: String(err.message || err),
        memories: state.decisionMemory?.memories ?? null,
        links: state.decisionMemory?.links ?? [],
      };
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-decision-memory-mode', ({ element }) => {
    const m = element?.dataset?.mode;
    if (!m) return;
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    if (!['timeline', 'chain'].includes(m)) return;
    state.decisionMemoryUi.mode = m;
    window.DecisionArena.render?.();
  });

  registerAction('set-decision-memory-filter', ({ element }) => {
    const key = element?.dataset?.filterKey;
    if (!key) return;
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || { filters: {} };
    state.decisionMemoryUi.filters = state.decisionMemoryUi.filters || {};
    state.decisionMemoryUi.filters[key] = element.value;
    window.DecisionArena.render?.();
  });

  registerAction('select-decision-memory-chain', ({ element }) => {
    const id = element?.dataset?.chainId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    state.decisionMemoryUi.selectedChainId = id;
    window.DecisionArena.render?.();
  });

  registerAction('select-decision-memory-detail', ({ element }) => {
    const id = element?.dataset?.memoryId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    // Toggle: click same card -> collapse.
    state.decisionMemoryUi.selectedMemoryId =
      String(state.decisionMemoryUi.selectedMemoryId || '') === String(id) ? null : id;
    window.DecisionArena.render?.();
  });

  registerAction('toggle-memory-selection', ({ element }) => {
    const id = element?.dataset?.memoryId;
    if (!id) return;
    const state = window.DecisionArena.store.state;
    if (!Array.isArray(state.selectedMemoryIds)) state.selectedMemoryIds = [];
    const set = new Set(state.selectedMemoryIds);
    if (set.has(id)) set.delete(id); else set.add(id);
    state.selectedMemoryIds = [...set];
    window.DecisionArena.render?.();
  });

  registerAction('clear-selected-memories', () => {
    const state = window.DecisionArena.store.state;
    state.selectedMemoryIds = [];
    window.DecisionArena.render?.();
  });

  registerAction('copy-selected-memories', async () => {
    const state = window.DecisionArena.store.state;
    const pkg = state.decisionMemory;
    const memories = Array.isArray(pkg?.memories) ? pkg.memories : [];
    const picked = memories.filter((m) => (state.selectedMemoryIds || []).includes(m.memory_id));
    const compact = picked.slice(0, 5).map((m) => `- [${m.playbook_id}] ${m.decision_status} (${m.confidence}): ${m.decision_summary}`).join('\n');
    const text = `Selected Decision Memories:\n${compact}`;
    try {
      await navigator.clipboard.writeText(text);
    } catch (_) {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
    state.toast = 'Copié dans le presse-papiers.';
    setTimeout(() => { try { state.toast = null; window.DecisionArena.render?.(); } catch (_) {} }, 2000);
    window.DecisionArena.render?.();
  });

  registerAction('confirm-decision-memory', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;
    const state = window.DecisionArena.store.state;
    state.memoryConfirmingSessionId = sessionId;
    window.DecisionArena.render?.();
    try {
      await window.DecisionArena.services.DecisionMemoryService.confirmForSession(sessionId);
      // Refresh memory list if loaded
      if (state.decisionMemory?.memories) {
        const data = await window.DecisionArena.services.DecisionMemoryService.list(250);
        state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
        await refreshDecisionMemoryExplorerNav(state);
      }
      state.toast = 'Mémoire confirmée et persistée.';
    } catch (err) {
      state.error = String(err.message || err);
    } finally {
      state.memoryConfirmingSessionId = null;
      window.DecisionArena.render?.();
    }
  });

  registerAction('create-decision-memory-link', async ({ element }) => {
    const fromId = element?.dataset?.fromMemoryId;
    if (!fromId) return;
    const toId = prompt('ID mémoire cible (memory_id):', '');
    if (!toId) return;
    const linkType = prompt('Type de lien: continuation | pivot | experiment_followup | related', 'related') || 'related';
    const state = window.DecisionArena.store.state;
    try {
      await window.DecisionArena.services.DecisionMemoryService.link(fromId, toId, linkType);
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250);
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      state.toast = 'Lien créé.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('decision-memory-lifecycle', async ({ element }) => {
    const memoryId = element?.dataset?.memoryId;
    const action = element?.dataset?.lifecycleAction;
    if (!memoryId || !action) return;
    const state = window.DecisionArena.store.state;
    try {
      if (action === 'invalidate') {
        const reason = prompt('Raison invalidation (obligatoire):', '') || '';
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'invalidate', { reason });
      } else if (action === 'archive') {
        const reason = prompt('Raison archive (optionnel):', '') || '';
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'archive', { reason });
      } else if (action === 'restore') {
        const reason = prompt('Raison restore (optionnel):', '') || '';
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'restore', { reason });
      } else if (action === 'review') {
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'review', {});
      } else if (action === 'supersede') {
        const toId = prompt('Supersedé par (memory_id):', '') || '';
        const reason = prompt('Raison (optionnel):', '') || '';
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'supersede', { to_memory_id: toId, reason });
      } else {
        return;
      }
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250, { include_archived: '1' });
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      await refreshDecisionMemoryExplorerNav(state);
      state.toast = 'Mise à jour mémoire effectuée.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('link-memory-to-strategic-context', async ({ element }) => {
    const memoryId = element?.dataset?.memoryId;
    if (!memoryId) return;
    const ctxId = prompt('context_id:', '') || '';
    if (!ctxId.trim()) return;
    const state = window.DecisionArena.store.state;
    try {
      await window.DecisionArena.services.StrategicContextService.linkMemory(ctxId.trim(), memoryId);
      // refresh contexts snapshot (best effort)
      try {
        const data = await window.DecisionArena.services.StrategicContextService.list({ status: state.strategicContextUi?.statusFilter || 'active' }, 120);
        state.strategicContexts = { loading: false, error: null, items: data.contexts || [] };
        await refreshDecisionMemoryExplorerNav(state);
      } catch (_) {}
      state.toast = 'Mémoire liée au contexte.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });
}

export { registerDecisionMemoryHandlers };

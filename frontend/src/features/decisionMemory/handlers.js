import { registerAction } from '../../core/events.js';
import { confirmationPayload, isConfirmationConfirmed, requestConfirmation, uiCopy } from '../../utils/confirmationUi.js';

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
    const active = data.active_context ?? null;
    state.activeStrategicContext = active;
    state.activeStrategicContextId = active?.context_id ? String(active.context_id) : null;
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
        include_stale: expert && ui.includeStale ? '1' : '0',
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
      const perspective = window.DecisionArena.services.MemorySnapshotService.normalizePerspective(
        state.decisionMemoryUi.roomMemoryMdPerspective || 'default'
      );
      const md = await window.DecisionArena.services.MemorySnapshotService.getRoomMarkdown(roomId, {
        expert: state.uiMode === 'expert' ? '1' : '',
        perspective,
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
    state.decisionMemoryUi.roomMemoryMdRoomId = roomId;
    if (state.decisionMemoryUi.roomMemoryMdOpen && !state.decisionMemoryUi.roomMemoryMdContent) {
      await loadRoomMemoryMd(state, roomId);
    }
    window.DecisionArena.render?.();
  });

  registerAction('set-room-memory-perspective', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    state.decisionMemoryUi = state.decisionMemoryUi || {};
    const requested = String(
      element?.dataset?.perspective
        ?? element?.value
        ?? ''
    ).trim();
    const perspective = window.DecisionArena.services.MemorySnapshotService.normalizePerspective(requested) || 'default';
    if (state.decisionMemoryUi.roomMemoryMdPerspective === perspective) return;

    let preservedScroll = 0;
    try {
      const sc = document.querySelector('[data-snapshot-scroll="decision-room"]');
      if (sc) preservedScroll = sc.scrollTop || 0;
    } catch (_) { preservedScroll = 0; }

    state.decisionMemoryUi.roomMemoryMdPerspective = perspective;
    state.decisionMemoryUi.roomMemoryMdContent = '';
    state.decisionMemoryUi.roomMemoryMdError = '';
    if (state.decisionMemoryUi.roomMemoryMdOpen) {
      const roomId = String(
        state.decisionMemoryUi.roomMemoryMdRoomId
        || state.decisionMemoryUi.navDecisionChainId
        || ''
      );
      if (roomId) await loadRoomMemoryMd(state, roomId);
    }
    window.DecisionArena.render?.();

    if (preservedScroll > 0) {
      try {
        requestAnimationFrame(() => {
          const sc2 = document.querySelector('[data-snapshot-scroll="decision-room"]');
          if (sc2) sc2.scrollTop = preservedScroll;
        });
      } catch (_) { /* noop */ }
    }
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
    state.toast = 'Copied: memory.md';
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

  registerAction('select-all-visible-memories', () => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    // Select exactly what is currently rendered in the DOM (view-truth).
    const nodes = Array.from(document.querySelectorAll('input[type="checkbox"][data-action="toggle-memory-selection"][data-memory-id]'));
    const ids = nodes.map((n) => String(n.dataset.memoryId || '')).filter(Boolean);
    state.selectedMemoryIds = Array.from(new Set(ids));
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

  registerAction('request-delete-decision-memory', (ctx = {}) => {
    const { element } = ctx;
    const memoryId = element?.dataset?.memoryId;
    if (!memoryId) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    requestConfirmation(state, {
      id: `delete-decision-memory:${memoryId}`,
      mode: 'modal',
      tone: 'danger',
      title: uiCopy('Supprimer cette décision ?', 'Delete this decision memory?'),
      body: uiCopy('Cette action supprime définitivement la décision et ses liens (contextes/chaînes).', 'This permanently deletes the memory and its links (contexts/chains).'),
      expertBody: uiCopy(`memory_id: ${String(memoryId).slice(0, 12)}…`, `memory_id: ${String(memoryId).slice(0, 12)}…`),
      confirmLabel: uiCopy('Supprimer', 'Delete'),
      action: 'confirm-delete-decision-memory',
      payload: { memoryId },
    });
    window.DecisionArena.render?.();
  });

  registerAction('confirm-delete-decision-memory', async (ctx = {}) => {
    const payload = confirmationPayload(ctx, ctx.element);
    const memoryId = payload.memoryId;
    if (!memoryId) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    if (!isConfirmationConfirmed(ctx)) return;
    try {
      await window.DecisionArena.services.DecisionMemoryService.delete(memoryId);
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250, { include_archived: '1' });
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      await refreshDecisionMemoryExplorerNav(state);
      state.selectedMemoryIds = Array.isArray(state.selectedMemoryIds) ? state.selectedMemoryIds.filter((x) => String(x) !== String(memoryId)) : [];
      state.toast = 'Décision supprimée.';
    } catch (err) {
      state.error = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('request-delete-selected-memories', (ctx = {}) => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    const ids = Array.isArray(state.selectedMemoryIds) ? state.selectedMemoryIds.map(String).filter(Boolean) : [];
    if (!ids.length) return;
    requestConfirmation(state, {
      id: `delete-selected-decision-memories:${ids.length}`,
      mode: 'modal',
      tone: 'danger',
      title: uiCopy('Supprimer plusieurs décisions ?', 'Delete multiple decision memories?'),
      body: uiCopy(`Cette action supprime définitivement ${ids.length} décision(s) et leurs liens.`, `This permanently deletes ${ids.length} memory record(s) and their links.`),
      expertBody: uiCopy(ids.slice(0, 8).join(', ') + (ids.length > 8 ? '…' : ''), ids.slice(0, 8).join(', ') + (ids.length > 8 ? '…' : '')),
      confirmLabel: uiCopy('Supprimer la sélection', 'Delete selected'),
      action: 'confirm-delete-selected-memories',
      payload: { ids },
    });
    window.DecisionArena.render?.();
  });

  registerAction('confirm-delete-selected-memories', async (ctx = {}) => {
    const payload = confirmationPayload(ctx, ctx.element);
    const ids = Array.isArray(payload.ids) ? payload.ids.map(String).filter(Boolean) : [];
    if (!ids.length) return;
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') {
      state.error = 'Expert mode required.';
      window.DecisionArena.render?.();
      return;
    }
    if (!isConfirmationConfirmed(ctx)) return;

    const failures = [];
    for (const id of ids) {
      try {
        // eslint-disable-next-line no-await-in-loop
        await window.DecisionArena.services.DecisionMemoryService.delete(id);
      } catch (err) {
        failures.push({ id, err: String(err?.message || err) });
      }
    }

    try {
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250, { include_archived: '1' });
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      await refreshDecisionMemoryExplorerNav(state);
    } catch (_) {}

    if (failures.length) {
      state.error = `Suppression partielle: ${failures.length} échec(s).`;
    } else {
      state.toast = 'Décisions supprimées.';
      state.selectedMemoryIds = [];
    }
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
    const state = window.DecisionArena.store.state;
    const toId = String(element?.dataset?.toMemoryId || '').trim();
    const linkType = String(element?.dataset?.linkType || 'related').trim() || 'related';
    if (!toId) {
      state.error = 'Target memory_id is required to create a link.';
      window.DecisionArena.render?.();
      return;
    }
    try {
      await window.DecisionArena.services.DecisionMemoryService.link(fromId, toId, linkType);
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250);
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      state.toast = 'Lien cree.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('decision-memory-lifecycle', async (ctx = {}) => {
    const { element } = ctx;
    const payload = confirmationPayload(ctx, element);
    const memoryId = payload.memoryId;
    const action = payload.lifecycleAction;
    if (!memoryId || !action) return;
    const state = window.DecisionArena.store.state;
    if (!isConfirmationConfirmed(ctx)) {
      const labels = {
        review: {
          title: uiCopy('Marquer cette mémoire comme revue ?', 'Mark this memory as reviewed?'),
          body: uiCopy('Cela ajoute une trace de revue dans l’historique de cette mémoire.', 'This adds a review trace to this memory history.'),
          confirmLabel: uiCopy('Marquer comme revue', 'Mark reviewed'),
        },
        archive: {
          title: uiCopy('Archiver cette mémoire ?', 'Archive this memory?'),
          body: uiCopy('Elle sera retirée des suggestions de réutilisation par défaut.', 'It will be removed from reuse suggestions by default.'),
          confirmLabel: uiCopy('Archiver', 'Archive'),
        },
        restore: {
          title: uiCopy('Restaurer cette mémoire ?', 'Restore this memory?'),
          body: uiCopy('Elle pourra de nouveau apparaître dans les vues actives.', 'It may appear again in active views.'),
          confirmLabel: uiCopy('Restaurer', 'Restore'),
        },
        supersede: {
          title: uiCopy('Remplacer cette mémoire par une autre ?', 'Supersede this memory with another?'),
          body: uiCopy('La mémoire restera auditée mais ne sera plus réutilisée par défaut.', 'The memory remains audited but is no longer reused by default.'),
          confirmLabel: uiCopy('Remplacer', 'Supersede'),
        },
        invalidate: {
          title: uiCopy('Invalider cette mémoire ?', 'Invalidate this memory?'),
          body: uiCopy('Elle sera bloquée par défaut pour éviter une réutilisation trompeuse.', 'It will be blocked by default to avoid misleading reuse.'),
          confirmLabel: uiCopy('Invalider', 'Invalidate'),
        },
      };
      const fields = [];
      if (action === 'invalidate') {
        fields.push({
          name: 'reason',
          type: 'textarea',
          required: true,
          label: uiCopy('Motif', 'Reason'),
          placeholder: uiCopy('Pourquoi cette mémoire ne doit plus être utilisée ?', 'Why should this memory no longer be used?'),
        });
      } else if (action === 'supersede') {
        fields.push({
          name: 'toMemoryId',
          required: true,
          label: uiCopy('Mémoire de remplacement', 'Replacement memory'),
          placeholder: 'memory_id',
        });
        fields.push({
          name: 'reason',
          type: 'textarea',
          label: uiCopy('Note optionnelle', 'Optional note'),
          placeholder: uiCopy('Pourquoi ce remplacement ?', 'Why this replacement?'),
        });
      } else if (action === 'archive') {
        fields.push({
          name: 'reason',
          type: 'textarea',
          label: uiCopy('Note optionnelle', 'Optional note'),
          placeholder: uiCopy('Pourquoi archiver cette mémoire ?', 'Why archive this memory?'),
        });
      }
      const copy = labels[action] || labels.review;
      requestConfirmation(state, {
        id: `decision-memory-lifecycle:${memoryId}:${action}`,
        mode: 'modal',
        tone: action === 'invalidate' ? 'danger' : 'warning',
        title: copy.title,
        body: copy.body,
        expertBody: uiCopy('Impact lifecycle: la décision reste en SQLite et l’action est journalisée.', 'Lifecycle impact: the decision stays in SQLite and the action is audited.'),
        confirmLabel: copy.confirmLabel,
        action: 'decision-memory-lifecycle',
        payload: { memoryId, lifecycleAction: action },
        fields,
        anchor: { kind: 'decision-memory', id: memoryId },
      });
      window.DecisionArena.render?.();
      return;
    }
    try {
      if (action === 'invalidate') {
        const reason = String(payload.reason || '').trim();
        if (!reason) {
          state.error = 'Invalidation reason is required.';
          window.DecisionArena.render?.();
          return;
        }
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'invalidate', { reason });
      } else if (action === 'archive') {
        const reason = String(payload.reason || '').trim();
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'archive', { reason });
      } else if (action === 'restore') {
        const reason = String(payload.reason || '').trim();
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'restore', { reason });
      } else if (action === 'review') {
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'review', {});
      } else if (action === 'supersede') {
        const toId = String(payload.toMemoryId || payload.to_memory_id || '').trim();
        const reason = String(payload.reason || '').trim();
        if (!toId) {
          state.error = 'Replacement memory_id is required to supersede a memory.';
          window.DecisionArena.render?.();
          return;
        }
        await window.DecisionArena.services.DecisionMemoryService.lifecycle(memoryId, 'supersede', { to_memory_id: toId, reason });
      } else {
        return;
      }
      const data = await window.DecisionArena.services.DecisionMemoryService.list(250, { include_archived: '1' });
      state.decisionMemory = { loading: false, error: null, memories: data.memories || [], links: data.links || [] };
      await refreshDecisionMemoryExplorerNav(state);
      state.toast = 'Mise a jour memoire effectuee.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('create-decision-room-chain', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    if (state.uiMode !== 'expert') return;
    let cid = String(element?.dataset?.contextId || '').trim();
    if (!cid) {
      const ui = state.decisionMemoryUi || {};
      cid = String(
        ui.navStrategicContextId
        || state.activeStrategicContextId
        || state.activeStrategicContext?.context_id
        || '',
      ).trim();
    }
    const tFn = window.i18n?.t?.bind(window.i18n) || ((k) => k);
    if (!cid) {
      state.error = tFn('decisionMemory.nav.newChainNeedContext');
      window.DecisionArena.render?.();
      return;
    }
    const title = window.prompt(tFn('decisionMemory.nav.newChainPrompt'), '');
    if (!title || !String(title).trim()) return;
    try {
      await window.DecisionArena.services.StrategicContextService.createRoom(cid, {
        title: String(title).trim(),
        description: '',
        status: 'active',
      });
      state.decisionMemoryUi = state.decisionMemoryUi || {};
      state.decisionMemoryUi.navStrategicContextId = cid;
      state.decisionMemoryUi.navDecisionChainId = null;
      await loadRoomsForExplorer(state, cid);
      state.toast = tFn('decisionMemory.nav.newChainDone');
    } catch (err) {
      state.error = String(err?.message || err);
    }
    window.DecisionArena.render?.();
  });

  registerAction('link-memory-to-strategic-context', async ({ element }) => {
    const memoryId = element?.dataset?.memoryId;
    if (!memoryId) return;
    const state = window.DecisionArena.store.state;
    const ctxId = String(
      state.activeStrategicContextId
      || state.decisionMemoryUi?.navStrategicContextId
      || state.strategicContextUi?.selectedContextId
      || '',
    ).trim();
    if (!ctxId) {
      state.error = 'Select a strategic context before linking a memory.';
      window.DecisionArena.render?.();
      return;
    }
    try {
      await window.DecisionArena.services.StrategicContextService.linkMemory(ctxId, memoryId);
      try {
        const data = await window.DecisionArena.services.StrategicContextService.list({ status: state.strategicContextUi?.statusFilter || 'active' }, 120);
        state.strategicContexts = { loading: false, error: null, items: data.contexts || [] };
        const active = data.active_context ?? null;
        state.activeStrategicContext = active;
        state.activeStrategicContextId = active?.context_id ? String(active.context_id) : null;
        await refreshDecisionMemoryExplorerNav(state);
      } catch (_) {}
      state.toast = 'Memoire liee au contexte.';
    } catch (err) {
      state.error = String(err.message || err);
    }
    window.DecisionArena.render?.();
  });
}

export { registerDecisionMemoryHandlers };

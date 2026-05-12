const activeRunProgressPollers = new Map();

function nowIso() {
  return new Date().toISOString();
}

function getRunProgressEntry(state, sessionId) {
  return state.runProgressBySessionId?.[sessionId] || null;
}

function ensureRunProgressEntry(state, sessionId, seedData = null) {
  if (!state.runProgressBySessionId || typeof state.runProgressBySessionId !== 'object') {
    state.runProgressBySessionId = {};
  }
  const existing = state.runProgressBySessionId[sessionId];
  if (existing) {
    return existing;
  }
  const entry = {
    loading: false,
    lastFetchedAt: null,
    data: seedData || null,
    error: null,
    pollStartedAt: null,
    pollActive: false,
  };
  state.runProgressBySessionId[sessionId] = entry;
  return entry;
}

function updateRunProgressEntry(state, sessionId, patch = {}) {
  const prev = ensureRunProgressEntry(state, sessionId);
  const next = { ...prev, ...patch };
  state.runProgressBySessionId = {
    ...(state.runProgressBySessionId || {}),
    [sessionId]: next,
  };
  return next;
}

function stopRunProgressPolling(sessionId) {
  const key = String(sessionId || '');
  if (!key) return;
  const active = activeRunProgressPollers.get(key);
  if (!active) return;
  active.stopped = true;
  if (active.timer) clearTimeout(active.timer);
  if (active.controller) active.controller.abort();
  activeRunProgressPollers.delete(key);
}

function startRunProgressPolling({
  sessionId,
  state,
  fetchRunStatus,
  shouldContinue,
  onTick,
  intervalMs = 1500,
}) {
  const key = String(sessionId || '');
  if (!key || typeof fetchRunStatus !== 'function') {
    return () => {};
  }

  stopRunProgressPolling(key);
  updateRunProgressEntry(state, key, {
    pollStartedAt: nowIso(),
    pollActive: true,
    loading: true,
    error: null,
  });

  const context = {
    timer: null,
    controller: null,
    stopped: false,
  };
  activeRunProgressPollers.set(key, context);

  const schedule = (delayMs) => {
    if (context.stopped) return;
    context.timer = setTimeout(loop, delayMs);
  };

  const loop = async () => {
    if (context.stopped) return;
    if (typeof shouldContinue === 'function' && !shouldContinue()) {
      stopRunProgressPolling(key);
      updateRunProgressEntry(state, key, { pollActive: false, loading: false });
      if (typeof onTick === 'function') onTick(null, null, { stopped: true });
      return;
    }

    context.controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    updateRunProgressEntry(state, key, { loading: true });

    try {
      const payload = await fetchRunStatus(key, context.controller?.signal);
      const terminal = ['completed', 'failed', 'blocked'].includes(String(payload?.status || '').toLowerCase());
      updateRunProgressEntry(state, key, {
        loading: false,
        lastFetchedAt: nowIso(),
        data: payload || null,
        error: null,
        pollActive: !terminal,
      });
      if (typeof onTick === 'function') onTick(payload, null, { terminal });
      if (terminal) {
        stopRunProgressPolling(key);
        return;
      }
    } catch (error) {
      if (context.stopped) return;
      const isAbort = String(error?.name || '').toLowerCase() === 'aborterror';
      if (!isAbort) {
        updateRunProgressEntry(state, key, {
          loading: false,
          lastFetchedAt: nowIso(),
          error: error?.message || String(error),
          pollActive: true,
        });
        if (typeof onTick === 'function') onTick(null, error, { terminal: false });
      }
    } finally {
      context.controller = null;
      if (!context.stopped) schedule(intervalMs);
    }
  };

  schedule(200);
  return () => {
    stopRunProgressPolling(key);
    updateRunProgressEntry(state, key, { pollActive: false, loading: false });
  };
}

export {
  ensureRunProgressEntry,
  getRunProgressEntry,
  updateRunProgressEntry,
  startRunProgressPolling,
  stopRunProgressPolling,
};

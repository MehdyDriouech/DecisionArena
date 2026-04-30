import { registerAction } from '../../core/events.js';

let _replayTimer = null;

function clearReplayTimer() {
  if (_replayTimer) { clearInterval(_replayTimer); _replayTimer = null; }
}

function startReplayTimer(sessionId) {
  clearReplayTimer();
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const speed = state.replaySpeed ?? 1;
  const intervalMs = Math.round(1000 / speed);
  _replayTimer = setInterval(() => {
    const s = arena.store.state;
    const events = s.replayEvents ?? [];
    if (s.replayIndex >= events.length - 1) {
      clearReplayTimer();
      s.replayPlaying = false;
      arena.render();
      return;
    }
    s.replayIndex = (s.replayIndex ?? 0) + 1;
    arena.render();
    setTimeout(() => {
      const el = document.querySelector('.replay-dot-active');
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, 50);
  }, intervalMs);
}

function registerDebateReplayHandlers() {
  registerAction('load-replay-events', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;
    clearReplayTimer();
    const arena    = window.DecisionArena;
    const state    = arena.store.state;
    const apiFetch = arena.services.apiFetch;
    const render   = () => arena.render();
    state.replayEvents  = null;
    state.replayLoading = true;
    state.replayError   = null;
    state.replayIndex   = 0;
    state.replayPlaying = false;
    render();
    try {
      const json = await apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/replay`);
      state.replayEvents = json.events ?? [];
    } catch (err) {
      console.error('[replay] error', err);
      state.replayEvents = null;
      state.replayError  = err?.message || String(err);
    } finally {
      state.replayLoading = false;
      render();
    }
  });

  registerAction('replay-start', ({ element }) => {
    const arena = window.DecisionArena;
    arena.store.state.replayPlaying = true;
    arena.render();
    startReplayTimer(element?.dataset?.sessionId);
  });

  registerAction('replay-pause', () => {
    clearReplayTimer();
    window.DecisionArena.store.state.replayPlaying = false;
    window.DecisionArena.render();
  });

  registerAction('replay-reset', () => {
    clearReplayTimer();
    const state = window.DecisionArena.store.state;
    state.replayIndex   = 0;
    state.replayPlaying = false;
    window.DecisionArena.render();
  });

  registerAction('replay-next', () => {
    const arena  = window.DecisionArena;
    const state  = arena.store.state;
    const events = state.replayEvents ?? [];
    if ((state.replayIndex ?? 0) < events.length - 1) {
      state.replayIndex = (state.replayIndex ?? 0) + 1;
      arena.render();
    }
  });

  registerAction('replay-prev', () => {
    const arena = window.DecisionArena;
    const state = arena.store.state;
    if ((state.replayIndex ?? 0) > 0) {
      state.replayIndex = (state.replayIndex ?? 0) - 1;
      arena.render();
    }
  });

  registerAction('replay-goto', ({ element }) => {
    const idx = parseInt(element?.dataset?.index ?? '0', 10);
    const arena = window.DecisionArena;
    arena.store.state.replayIndex = idx;
    arena.render();
  });

  registerAction('replay-speed', ({ element }) => {
    clearReplayTimer();
    const arena = window.DecisionArena;
    arena.store.state.replaySpeed   = parseFloat(element?.dataset?.speed ?? '1');
    arena.store.state.replayPlaying = false;
    arena.render();
  });
}

export { registerDebateReplayHandlers };

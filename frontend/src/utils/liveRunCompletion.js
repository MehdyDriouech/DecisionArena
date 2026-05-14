import { buildQuickDecisionResultsFromSession } from './quickDecisionHydrate.js';
import { buildLiveResultsFromSessionShow } from './liveRunSessionHydrate.js';

/** @type {Map<string, Promise<void>>} */
const liveRunFinalizeInFlight = new Map();

function tOr(key, fallback) {
  try {
    return window.i18n?.t(key) ?? fallback;
  } catch (_) {
    return fallback;
  }
}

function clearRunningForMode(state, mode) {
  const m = String(mode || '').toLowerCase();
  if (m === 'jury') state.juryRunning = false;
  else if (m === 'decision-room') state.drRunning = false;
  else if (m === 'confrontation') state.confrontationRunning = false;
  else if (m === 'stress-test') state.stRunning = false;
  else if (m === 'quick-decision') state.qdRunning = false;
}

function syncSessionInSessionsList(state, sess) {
  if (!sess || !sess.id) return;
  const id = String(sess.id);
  const li = (state.sessions || []).findIndex((s) => String(s.id) === id);
  if (li >= 0) {
    state.sessions[li] = { ...state.sessions[li], ...sess };
  }
}

function applyRunProgressAfterFinalize(state, payload, sid) {
  const p = payload && typeof payload === 'object' ? payload : {};
  const looksFull =
    p.progress != null
    || p.started_at != null
    || Array.isArray(p.events)
    || (p.elapsed_seconds != null && p.updated_at != null);
  if (looksFull) {
    state.runProgress = p;
  } else if (String(p.status || '').toLowerCase() === 'completed') {
    state.runProgress = {
      ...(state.runProgress && typeof state.runProgress === 'object' ? state.runProgress : {}),
      status: 'completed',
      session_id: p.session_id || sid,
    };
  }
}

/**
 * Après poll /run-status terminal (completed) : recharge la session, hydrate les résultats, toast.
 * Les appels concurrents pour la même session partagent une seule promesse (dédup).
 */
async function finalizeLiveRunAfterTerminalPoll({
  state,
  sessionId,
  mode,
  SessionService,
  render,
  payload,
}) {
  const sid = String(sessionId || '');
  if (!sid) return;
  const st = String(payload?.status || '').toLowerCase();
  if (st !== 'completed') return;

  const existing = liveRunFinalizeInFlight.get(sid);
  if (existing) {
    await existing;
    return;
  }

  const runPromise = (async () => {
    let data;
    try {
      data = await SessionService.get(sid);
    } catch (e) {
      state.liveRunReloadError = { sessionId: sid, message: String(e?.message || e), mode };
      clearRunningForMode(state, mode);
      state.toast = tOr('runProgress.toastCompletedReloadFailed', 'Analyse terminée, mais le résultat final n’a pas pu être rechargé.');
      try {
        render?.();
      } catch (_) {}
      return;
    }

    state.liveRunReloadError = null;
    const sess = data.session || data;
    state.currentSession = { ...(state.currentSession || {}), ...sess };
    syncSessionInSessionsList(state, sess);

    const m = String(mode || '').toLowerCase();
    if (m === 'quick-decision') {
      let verdict = null;
      try {
        const vd = await SessionService.getVerdict(sid);
        verdict = vd?.verdict ?? null;
      } catch (_) {}
      state.qdResults = buildQuickDecisionResultsFromSession(data, verdict);
    } else {
      const built = buildLiveResultsFromSessionShow(m, data);
      if (built) {
        if (m === 'jury') state.juryResults = built;
        else if (m === 'decision-room') state.drResults = built;
        else if (m === 'confrontation') state.confrontationResults = built;
        else if (m === 'stress-test') state.stResults = built;
      }
    }

    applyRunProgressAfterFinalize(state, payload, sid);
    clearRunningForMode(state, mode);
    state.toast = tOr('runProgress.toastAnalysisDone', 'Analyse terminée.');
    try {
      render?.();
    } catch (_) {}
  })();

  liveRunFinalizeInFlight.set(sid, runPromise);
  try {
    await runPromise;
  } finally {
    if (liveRunFinalizeInFlight.get(sid) === runPromise) {
      liveRunFinalizeInFlight.delete(sid);
    }
  }
}

/** Réhydrate la session courante depuis GET /api/sessions/{id} (run terminal completed). */
function hydrateLiveRunSession({ state, sessionId, mode, SessionService, render }) {
  const sid = String(sessionId || '');
  if (!sid) return Promise.resolve();
  return finalizeLiveRunAfterTerminalPoll({
    state,
    sessionId: sid,
    mode,
    SessionService,
    render,
    payload: { status: 'completed', session_id: sid },
  });
}

/** Garde DA-SMOKE-001 : run-status « completed » mais session pas encore synchronisée. */
function ensureLiveRunSessionHydratedIfMismatch({
  state,
  sessionId,
  mode,
  SessionService,
  render,
}) {
  const sid = String(sessionId || '');
  if (!sid || String(state.currentSession?.id || '') !== sid) return;
  if (String(state.currentSession?.status || '').toLowerCase() === 'completed') return;
  const rp = state.runProgressBySessionId?.[sid]?.data || state.runProgress;
  if (String(rp?.status || '').toLowerCase() !== 'completed') return;
  void hydrateLiveRunSession({ state, sessionId: sid, mode, SessionService, render });
}

export {
  finalizeLiveRunAfterTerminalPoll,
  hydrateLiveRunSession,
  ensureLiveRunSessionHydratedIfMismatch,
};

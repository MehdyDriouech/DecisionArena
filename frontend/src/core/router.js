/* Router — SPA navigation with scroll helpers */

import { dispatchAction } from './events.js';

const HASH_VIEW_ALIASES = Object.freeze({
  '': 'dashboard',
  '/': 'dashboard',
  contexts: 'strategic-contexts',
  context: 'strategic-contexts',
  analyses: 'analyses',
  sessions: 'analyses',
  admin: 'administration',
  openspace: 'openspace-orchestrator',
  'open-space': 'openspace-orchestrator',
});

const VIEW_TO_HASH = Object.freeze({
  dashboard: '#/dashboard',
  about: '#/about',
  analyses: '#/analyses',
  sessions: '#/analyses',
  'new-session': '#/new-session',
  'session-comparisons': '#/session-comparisons',
  'session-comparison': '#/session-comparison',
  'strategic-contexts': '#/strategic-contexts',
  'decision-memory': '#/decision-memory',
  administration: '#/administration',
  providers: '#/providers',
  templates: '#/templates',
  'template-maker': '#/template-maker',
  'persona-maker': '#/persona-maker',
  'persona-builder': '#/persona-builder',
  'launch-assistant': '#/launch-assistant',
  retrospective: '#/retrospective',
  'cognitive-governance': '#/cognitive-governance',
  'openspace-orchestrator': '#/openspace/orchestrator',
  'openspace-kanban': '#/openspace/kanban',
  'openspace-agent-chat': '#/openspace/agent-chat',
  'experimental-gate': '#/experimental/disabled',
});

const OPENSPACE_GATED_VIEWS = new Set(['openspace-orchestrator', 'openspace-kanban', 'openspace-agent-chat']);

const UUID_LIKE_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

let suppressNextHashChange = false;
let suppressedHashValue = '';
let hashRoutingInitialized = false;

function isLikelyUuid(value) {
  return UUID_LIKE_RE.test(String(value || '').trim());
}

function navigate(view, extra = {}) {
  const state = window.DecisionArena.store.state;
  const prev  = state.view;
  const opts = {
    skipHashSync: false,
    replaceHash: false,
  };
  let extraState = extra;
  if (extra && typeof extra === 'object') {
    opts.skipHashSync = !!extra.__skipHashSync;
    opts.replaceHash = !!extra.__replaceHash;
    const { __skipHashSync: _skip, __replaceHash: _replace, ...rest } = extra;
    extraState = rest;
  }
  const canExp = window.DecisionArena.store.canShowExperimentalFeatures?.() === true;
  let resolvedView = view;
  if (OPENSPACE_GATED_VIEWS.has(view) && !canExp) {
    state.experimentalGateRequestedView = view;
    resolvedView = 'experimental-gate';
  } else if (view === 'experimental-gate' && canExp) {
    state.experimentalGateRequestedView = null;
    resolvedView = 'dashboard';
    opts.skipHashSync = false;
  } else if (view !== 'experimental-gate' && !OPENSPACE_GATED_VIEWS.has(view)) {
    state.experimentalGateRequestedView = null;
  }
  state.view  = resolvedView;
  state.error = null;
  if (extraState && Object.keys(extraState).length) Object.assign(state, extraState);
  if (view === 'retrospective') {
    state.postmortemStatsError = null;
    state.postmortemStatsAwaiting = true;
    state.postmortemStatsLoading = false;
  }
  if (view === 'decision-memory') {
    const nav = state.decisionMemoryNav || (state.decisionMemoryNav = {});
    const ui = state.decisionMemoryUi || (state.decisionMemoryUi = {});
    if (!nav.initialWorkspaceNavHydrated) {
      const cur = ui.navStrategicContextId;
      const empty = cur === null || cur === undefined || String(cur).trim() === '';
      if (empty) {
        const aid = String(state.activeStrategicContextId || state.activeStrategicContext?.context_id || '').trim();
        if (aid) {
          ui.navStrategicContextId = aid;
        }
      }
      nav.initialWorkspaceNavHydrated = true;
    }
  }
  window.DecisionArena.render?.();
  scrollMainToTop();
  if (!opts.skipHashSync) {
    syncHashWithView(resolvedView, state, { replace: opts.replaceHash });
  }
  try {
    window.DecisionArena.services?.LogService?.logNavigation?.(view, prev);
  } catch (_) {}
  if (view === 'retrospective') {
    queueMicrotask(() => {
      dispatchAction('load-postmortem-stats').catch(() => {});
    });
  }
  if (view === 'cognitive-governance') {
    queueMicrotask(() => {
      dispatchAction('load-cognitive-governance').catch(() => {});
    });
  }
  if (view === 'dashboard') {
    queueMicrotask(() => {
      dispatchAction('load-dashboard-summary').catch(() => {});
    });
  }
  if (OPENSPACE_GATED_VIEWS.has(resolvedView)) {
    queueMicrotask(() => {
      dispatchAction('open-space-enter').catch(() => {});
    });
  }
}

function normalizeHashPath(rawHash) {
  const hash = String(rawHash || '').trim();
  if (!hash) return '/';
  const withoutHash = hash.startsWith('#') ? hash.slice(1) : hash;
  if (!withoutHash) return '/';
  return withoutHash.startsWith('/') ? withoutHash : `/${withoutHash}`;
}

function parseHashRoute(rawHash) {
  const normalized = normalizeHashPath(rawHash);
  const [pathPart, queryPart = ''] = normalized.split('?');
  const segments = pathPart.split('/').filter(Boolean);
  const qs = new URLSearchParams(queryPart);
  const head = String(segments[0] || '').toLowerCase();

  if ((head === 'sessions' || head === 'analyses' || head === 'session-history') && segments[1]) {
    const id = decodeURIComponent(String(segments[1] || '').trim());
    return id ? { type: 'session', sessionId: id, route: head } : { type: 'unknown' };
  }
  if (qs.get('id')) {
    const id = decodeURIComponent(String(qs.get('id') || '').trim());
    return id ? { type: 'session', sessionId: id, route: 'sessions' } : { type: 'unknown' };
  }

  if (head === 'experimental' && String(segments[1] || '').toLowerCase() === 'disabled') {
    return { type: 'view', view: 'experimental-gate' };
  }

  if (head === 'openspace' || head === 'open-space') {
    const sub = String(segments[1] || 'orchestrator').toLowerCase();
    if (sub === 'kanban') return { type: 'view', view: 'openspace-kanban' };
    if (sub === 'agent-chat' || sub === 'agentchat') return { type: 'view', view: 'openspace-agent-chat' };
    return { type: 'view', view: 'openspace-orchestrator' };
  }

  const rawView = String(segments[0] || '').trim();
  const mapped = HASH_VIEW_ALIASES[rawView] || HASH_VIEW_ALIASES[rawView.toLowerCase()] || rawView || 'dashboard';
  if (mapped && Object.prototype.hasOwnProperty.call(VIEW_TO_HASH, mapped)) {
    return { type: 'view', view: mapped };
  }
  return { type: 'unknown' };
}

function buildHashForView(view, state) {
  if (view === 'session-history' && state?.sessionHistory?.session?.id) {
    return `#/sessions/${encodeURIComponent(String(state.sessionHistory.session.id))}`;
  }
  if (view === 'chat' && state?.currentSession?.id) {
    return `#/sessions/${encodeURIComponent(String(state.currentSession.id))}`;
  }
  return VIEW_TO_HASH[view] || `#/${encodeURIComponent(String(view || 'dashboard'))}`;
}

function syncHashWithView(view, state, { replace = false } = {}) {
  if (typeof window === 'undefined' || typeof window.location === 'undefined') return;
  const targetHash = buildHashForView(view, state);
  if (!targetHash || window.location.hash === targetHash) return;
  suppressNextHashChange = true;
  suppressedHashValue = targetHash;
  if (replace && window.history?.replaceState) {
    const base = `${window.location.pathname}${window.location.search}`;
    window.history.replaceState(null, '', `${base}${targetHash}`);
    return;
  }
  window.location.hash = targetHash;
}

async function openSessionFromHash(sessionId) {
  const state = window.DecisionArena.store.state;
  if (!isLikelyUuid(sessionId)) {
    state.currentSession = null;
    state.currentSessionId = null;
    state.selectedSessionId = null;
    state.currentMessages = [];
    state.sessionHistory = null;
    navigate('session-not-found', {
      __skipHashSync: true,
      sessionRouteError: {
        type: 'invalid-id',
        sessionId,
        message: `Identifiant de session invalide: ${sessionId}`,
      },
    });
    return;
  }
  const handled = await dispatchAction('open-session', {
    element: {
      dataset: {
        sessionId,
        mode: 'auto',
        routeSource: 'hash',
      },
    },
  });
  if (handled) return;
  const fallback = window.DecisionArena?.sessionFlows?.openSessionById;
  if (typeof fallback === 'function') {
    await fallback({ sessionId, requestedMode: 'auto', routeSource: 'hash' });
    return;
  }
  navigate('session-not-found', {
    __skipHashSync: true,
    sessionRouteError: {
      type: 'network',
      sessionId,
      message: `Impossible d ouvrir la session ${sessionId} (handler indisponible).`,
    },
  });
}

async function applyHashRoute() {
  const route = parseHashRoute(window.location.hash);
  if (route.type === 'session') {
    await openSessionFromHash(route.sessionId);
    return;
  }
  if (route.type === 'view') {
    navigate(route.view, { __skipHashSync: true });
    return;
  }
  navigate('dashboard', { __skipHashSync: true });
}

function initHashRouting() {
  if (hashRoutingInitialized) return;
  hashRoutingInitialized = true;
  window.addEventListener('hashchange', () => {
    if (suppressNextHashChange) {
      const currentHash = String(window.location.hash || '');
      const expectedHash = String(suppressedHashValue || '');
      suppressNextHashChange = false;
      suppressedHashValue = '';
      if (!expectedHash || currentHash === expectedHash) return;
    }
    applyHashRoute().catch(() => {});
  });
  applyHashRoute().catch(() => {});
}

function scrollMainToTop() {
  const main = document.getElementById('main-content');
  if (main) main.scrollTop = 0;
}

function scrollMessagesToBottom() {
  const el = document.getElementById('messages-timeline');
  if (el) el.scrollTop = el.scrollHeight;
}

function scrollFollowUpToBottom() {
  const el = document.getElementById('followup-messages');
  if (el) el.scrollTop = el.scrollHeight;
}

export { navigate, initHashRouting, scrollMainToTop, scrollMessagesToBottom, scrollFollowUpToBottom };

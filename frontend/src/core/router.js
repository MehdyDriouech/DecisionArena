/* Router — SPA navigation with scroll helpers */

import { dispatchAction } from './events.js';

function navigate(view, extra = {}) {
  const state = window.DecisionArena.store.state;
  const prev  = state.view;
  state.view  = view;
  state.error = null;
  if (extra && Object.keys(extra).length) Object.assign(state, extra);
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

export { navigate, scrollMainToTop, scrollMessagesToBottom, scrollFollowUpToBottom };

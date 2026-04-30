/* Router — SPA navigation with scroll helpers */

function navigate(view, extra = {}) {
  const state = window.DecisionArena.store.state;
  const prev  = state.view;
  state.view  = view;
  state.error = null;
  if (extra && Object.keys(extra).length) Object.assign(state, extra);
  window.DecisionArena.render?.();
  scrollMainToTop();
  try {
    window.DecisionArena.services?.LogService?.logNavigation?.(view, prev);
  } catch (_) {}
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

/* Events — global action dispatcher with async support and multi-event delegation */

const actionHandlers  = new Map();
const changeListeners = [];
const inputListeners  = [];
const keydownListeners = [];
const submitHandlers  = new Map();

function registerAction(actionName, handler) {
  actionHandlers.set(actionName, handler);
}

function registerChangeListener(fn) { changeListeners.push(fn); }
function registerInputListener(fn)  { inputListeners.push(fn);  }
function registerKeydownListener(fn){ keydownListeners.push(fn);}
function registerSubmit(formId, fn) { submitHandlers.set(formId, fn); }

async function dispatchAction(actionName, context = {}) {
  const handler = actionHandlers.get(actionName);
  if (!handler) return false;
  await handler(context);
  return true;
}

function bindGlobalEventDelegation(root = document) {
  /* ── keyboard: data-nav on non-button cards (div[tabindex], etc.) ───────── */
  root.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const navEl = e.target.closest('[data-nav]');
    if (!navEl || !root.contains(navEl)) return;
    if (navEl.matches('button, a[href]')) return;
    const tag = (e.target.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    e.preventDefault();
    try { window.DecisionArena.services?.LogService?.logUiAction?.('nav', { to: navEl.dataset.nav }); } catch (_) {}
    window.DecisionArena.router.navigate(navEl.dataset.nav);
  });

  /* ── click: data-nav then data-action ─────────────────────────────────── */
  root.addEventListener('click', async (e) => {
    const navEl = e.target.closest('[data-nav]');
    if (navEl) {
      try { window.DecisionArena.services?.LogService?.logUiAction?.('nav', { to: navEl.dataset.nav }); } catch (_) {}
      window.DecisionArena.router.navigate(navEl.dataset.nav);
      return;
    }
    const el = e.target.closest('[data-action]');
    if (!el) return;
    try {
      try { window.DecisionArena.services?.LogService?.logUiAction?.('action', { name: el.dataset.action }); } catch (_) {}
      await dispatchAction(el.dataset.action, { event: e, element: el });
    } catch (err) {
      console.error('[events] action error:', el.dataset.action, err);
      try { window.DecisionArena.services?.LogService?.logFrontendEvent?.('error', 'frontend', { action: 'action_error', metadata: { name: el.dataset.action }, error_message: err?.message || String(err) }); } catch (_) {}
    }
  });

  /* ── change ────────────────────────────────────────────────────────────── */
  root.addEventListener('change', async (e) => {
    for (const fn of changeListeners) {
      try {
        const handled = await fn(e);
        if (handled) return;
      } catch (err) {
        console.error('[events] change listener error:', err);
      }
    }
    const el = e.target.closest('[data-action]');
    if (el) {
      try { await dispatchAction(el.dataset.action, { event: e, element: el }); } catch (_) {}
    }
  });

  /* ── input ─────────────────────────────────────────────────────────────── */
  root.addEventListener('input', async (e) => {
    for (const fn of inputListeners) {
      try {
        const handled = await fn(e);
        if (handled) return;
      } catch (err) {
        console.error('[events] input listener error:', err);
      }
    }
  });

  /* ── keydown ───────────────────────────────────────────────────────────── */
  root.addEventListener('keydown', (e) => {
    for (const fn of keydownListeners) {
      try { fn(e); } catch (err) { console.error('[events] keydown error:', err); }
    }
  });

  /* ── submit ────────────────────────────────────────────────────────────── */
  root.addEventListener('submit', async (e) => {
    const id = e.target.id;
    if (id && submitHandlers.has(id)) {
      e.preventDefault();
      try { await submitHandlers.get(id)({ event: e }); } catch (err) { console.error('[events] submit error:', err); }
    }
  });
}

export {
  registerAction,
  registerChangeListener,
  registerInputListener,
  registerKeydownListener,
  registerSubmit,
  dispatchAction,
  bindGlobalEventDelegation,
};

/* Global action handlers — language switch, error clear */
import { dispatchAction, registerAction } from './events.js';

function registerGlobalHandlers() {
  registerAction('cancel-pending-confirmation', ({ element }) => {
    const state = window.DecisionArena.store.state;
    const id = element?.dataset?.confirmId || '';
    if (!state.pendingConfirmation || (id && state.pendingConfirmation.id !== id)) return;
    state.pendingConfirmation = null;
    window.DecisionArena.render?.();
  });

  registerAction('confirm-pending-confirmation', async ({ element }) => {
    const state = window.DecisionArena.store.state;
    const pending = state.pendingConfirmation;
    const id = element?.dataset?.confirmId || '';
    if (!pending || (id && pending.id !== id)) return;

    const card = element?.closest?.('[data-confirm-card]');
    const fieldValues = {};
    let missingLabel = '';
    card?.querySelectorAll?.('[data-confirm-field]')?.forEach((field) => {
      const name = field.dataset.confirmField;
      if (!name) return;
      const value = String(field.value || '').trim();
      fieldValues[name] = value;
      if (!value && field.dataset.confirmRequired === '1' && !missingLabel) {
        const label = field.closest('.form-group')?.querySelector('label')?.textContent || name;
        missingLabel = label.replace('*', '').trim();
      }
    });

    if (missingLabel) {
      pending.fields = (pending.fields || []).map((field) => ({
        ...field,
        value: Object.prototype.hasOwnProperty.call(fieldValues, field.name) ? fieldValues[field.name] : field.value,
      }));
      pending.fieldError = window.i18n?.getLanguage?.() === 'en'
        ? `${missingLabel} is required before confirming.`
        : `${missingLabel} est requis avant de confirmer.`;
      window.DecisionArena.render?.();
      return;
    }

    state.pendingConfirmation = null;
    await dispatchAction(pending.action, {
      confirmed: true,
      confirmationPayload: { ...(pending.payload || {}), ...fieldValues },
      element: {
        dataset: {
          ...(pending.payload || {}),
          ...fieldValues,
          confirmed: '1',
        },
      },
    });
  });

  registerAction('clear-error', () => {
    const state = window.DecisionArena.store.state;
    state.error = null;
    window.DecisionArena.render?.();
  });
  registerAction('clear-toast', () => {
    const state = window.DecisionArena.store.state;
    state.toast = null;
    window.DecisionArena.render?.();
  });

  registerAction('set-language', ({ element }) => {
    const lang = element.dataset.lang;
    if (!lang) return;
    window.i18n?.setLanguage?.(lang);
    window.DecisionArena.render?.();
  });

  registerAction('set-ui-mode', ({ element }) => {
    const m = element?.dataset?.uiMode;
    // Product UX: only "basic" | "expert". Legacy compatibility: "simple" -> "basic".
    const next = m === 'simple' ? 'basic' : m;
    if (next !== 'basic' && next !== 'expert') return;
    const normalized = window.DecisionArena.store.setUiMode?.(next);
    if (!normalized) return;
    window.DecisionArena.applyUiModeVisibility?.(normalized);
    window.DecisionArena.render?.();
  });

  registerAction('set-ui-complexity', ({ element }) => {
    const c = element?.dataset?.complexity;
    // Legacy-only: if any old UI still emits this action, map advanced->expert and avoid tri-mode UX.
    const next = c === 'advanced' ? 'expert' : c;
    if (!['basic', 'expert'].includes(next)) return;
    const normalized = window.DecisionArena.store.setUiComplexity?.(next);
    if (!normalized) return;
    window.DecisionArena.applyUiModeVisibility?.(normalized);
    window.DecisionArena.render?.();
    document.getElementById('complexity-dropdown')?.style?.setProperty('display', 'none');
  });

  registerAction('toggle-complexity-dropdown', () => {
    const dd = document.getElementById('complexity-dropdown');
    if (dd) dd.style.display = dd.style.display === 'none' ? '' : 'none';
  });

  registerAction('toggle-panel-collapse', ({ element }) => {
    const key = element?.dataset?.panelKey;
    if (!key) return;
    const state = window.DecisionArena.store.state;
    if (!state.collapsedPanels) state.collapsedPanels = new Set();
    if (state.collapsedPanels.has(key)) {
      state.collapsedPanels.delete(key);
    } else {
      state.collapsedPanels.add(key);
    }
    try {
      localStorage.setItem('da_collapsed_panels', JSON.stringify([...state.collapsedPanels]));
    } catch (_) {}
    window.DecisionArena.render?.();
  });

  registerAction('toggle-analyses-submenu', () => {
    const state = window.DecisionArena.store.state;
    const current = typeof state.analysesSidebarOpen === 'boolean'
      ? state.analysesSidebarOpen
      : (() => {
        try {
          return localStorage.getItem('da_nav_analyses_open') !== '0';
        } catch (_) {
          return true;
        }
      })();
    const next = !current;
    state.analysesSidebarOpen = next;
    try {
      localStorage.setItem('da_nav_analyses_open', next ? '1' : '0');
    } catch (_) {}
    window.DecisionArena.render?.();
  });
}

export { registerGlobalHandlers };

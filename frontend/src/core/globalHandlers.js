/* Global action handlers — language switch, error clear */
import { registerAction } from './events.js';

function registerGlobalHandlers() {
  registerAction('clear-error', () => {
    const state = window.DecisionArena.store.state;
    state.error = null;
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
    if (m !== 'simple' && m !== 'expert') return;
    window.DecisionArena.store.state.uiMode = m;
    document.body.classList.toggle('ui-expert', m === 'expert');
    document.body.classList.toggle('ui-simple', m !== 'expert');
    window.DecisionArena.render?.();
  });

  registerAction('set-ui-complexity', ({ element }) => {
    const c = element?.dataset?.complexity;
    if (!['basic', 'advanced', 'expert'].includes(c)) return;
    window.DecisionArena.setUiComplexity?.(c);
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
}

export { registerGlobalHandlers };

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
}

export { registerGlobalHandlers };

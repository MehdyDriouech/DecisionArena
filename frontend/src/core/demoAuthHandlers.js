import { patchState, state } from './store.js';
import { fetchDemoMe, fetchDemoAuthConfig, demoLogin, demoLogout } from '../services/demoAuthService.js';
import { registerAction, registerSubmit } from './events.js';

function t(key) {
  return window.i18n?.t(key) ?? key;
}

function patchDemoAuth(partial) {
  patchState({ demoAuth: { ...state.demoAuth, ...partial } });
}

export async function bootstrapDemoAuth() {
  patchDemoAuth({ loading: true, error: null });
  try {
    const config = await fetchDemoAuthConfig();
    const authRequired = !!config.auth_required;
    if (!authRequired) {
      patchDemoAuth({
        loading: false,
        authRequired: false,
        authenticated: false,
        user: null,
      });
      return;
    }
    const me = await fetchDemoMe();
    patchDemoAuth({
      loading: false,
      authRequired: true,
      authenticated: !!me.authenticated,
      user: me.user || null,
    });
  } catch (_) {
    patchDemoAuth({
      loading: false,
      authRequired: false,
      authenticated: false,
      user: null,
    });
  }
}

export function isDemoLoginGateActive() {
  const da = state.demoAuth;
  if (!da?.authRequired) return false;
  if (da.loading) return true;
  return !da.authenticated;
}

export function registerDemoAuthHandlers() {
  registerSubmit('demo-login-form', async ({ event }) => {
    event.preventDefault();
    const form = event.target;
    const login = String(form.login?.value || '').trim();
    const password = String(form.password?.value || '');
    if (!login || !password) return;

    patchDemoAuth({ loading: true, error: null });
    window.DecisionArena?.render?.();

    try {
      await demoLogin(login, password);
      const me = await fetchDemoMe();
      patchDemoAuth({
        loading: false,
        authenticated: !!me.authenticated,
        user: me.user || null,
        error: null,
      });
      if (me.authenticated) {
        await window.DecisionArena?.initAppAfterLogin?.();
      }
    } catch (err) {
      patchDemoAuth({
        loading: false,
        error: t('demo.loginError'),
      });
    }
    window.DecisionArena?.render?.();
  });

  registerAction('demo-fill-credential', ({ element }) => {
    const login = String(element?.dataset?.demoLogin || '').trim();
    const password = String(element?.dataset?.demoPassword || '');
    const loginInput = document.getElementById('demo-login-input');
    const passwordInput = document.getElementById('demo-password-input');
    if (loginInput) loginInput.value = login;
    if (passwordInput) passwordInput.value = password;
    if (loginInput) loginInput.focus();
    patchDemoAuth({ error: null });
  });

  registerAction('toggle-demo-account-menu', () => {
    patchDemoAuth({ accountMenuOpen: !state.demoAuth.accountMenuOpen });
    window.DecisionArena?.render?.();
  });

  registerAction('demo-logout', async () => {
    try {
      await demoLogout();
    } catch (_) {}
    patchDemoAuth({
      authenticated: false,
      user: null,
      accountMenuOpen: false,
      error: null,
    });
    window.DecisionArena?.render?.();
  });
}

/** Identifiants publics affichés sur l’écran de connexion (compte démo uniquement). */
const PUBLIC_DEMO_LOGIN = 'demo';
const PUBLIC_DEMO_PASSWORD = 'demo';

function t(key, vars) {
  if (window.i18n?.t) {
    return vars ? window.i18n.t(key, vars) : window.i18n.t(key);
  }
  return key;
}

function escHtml(s) {
  return window.DecisionArena?.utils?.escHtml ? window.DecisionArena.utils.escHtml(s) : String(s);
}

function loginIconSvg() {
  return `<svg class="demo-login-icon" width="44" height="44" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <rect width="40" height="40" rx="12" fill="url(#demo-login-icon-grad)"/>
    <path d="M20 11c-2.76 0-5 2.24-5 5v2h-1.5a1.5 1.5 0 0 0-1.5 1.5v8A1.5 1.5 0 0 0 13.5 29h13a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 26.5 18H25v-2c0-2.76-2.24-5-5-5zm0 2.5a2.5 2.5 0 0 1 2.5 2.5v2h-5v-2A2.5 2.5 0 0 1 20 13.5z" fill="currentColor"/>
    <defs>
      <linearGradient id="demo-login-icon-grad" x1="8" y1="4" x2="34" y2="36" gradientUnits="userSpaceOnUse">
        <stop stop-color="#4f46e5"/>
        <stop offset="1" stop-color="#7c3aed"/>
      </linearGradient>
    </defs>
  </svg>`;
}

function publicCredentialChip() {
  return `<button
    type="button"
    class="demo-login-credential-chip"
    data-action="demo-fill-credential"
    data-demo-login="${escHtml(PUBLIC_DEMO_LOGIN)}"
    data-demo-password="${escHtml(PUBLIC_DEMO_PASSWORD)}"
    aria-label="${escHtml(t('demo.fillCredential', { login: PUBLIC_DEMO_LOGIN }))}"
  >
    <code class="demo-login-credential-chip__code">${escHtml(PUBLIC_DEMO_LOGIN)}</code>
    <span class="demo-login-credential-chip__sep" aria-hidden="true">/</span>
    <code class="demo-login-credential-chip__code">${escHtml(PUBLIC_DEMO_PASSWORD)}</code>
  </button>`;
}

export function renderDemoLoginLoading() {
  return `
    <div class="demo-login-screen view-container" role="status" aria-live="polite">
      <div class="demo-login-card demo-login-card--loading">
        <div class="demo-login-spinner" aria-hidden="true"></div>
        <p class="demo-login-loading-text">${escHtml(t('demo.loading'))}</p>
      </div>
    </div>`;
}

export function renderDemoLoginView() {
  const state = window.DecisionArena?.store?.state;
  const loading = !!state?.demoAuth?.loading;
  const err = state?.demoAuth?.error ? escHtml(state.demoAuth.error) : '';

  return `
    <div class="demo-login-screen view-container">
      <section class="demo-login-card card" aria-labelledby="demo-login-title">
        <header class="demo-login-header">
          <span class="demo-login-badge">${escHtml(t('demo.instanceBadge'))}</span>
          ${loginIconSvg()}
          <h1 id="demo-login-title" class="demo-login-title">${escHtml(t('demo.loginTitle'))}</h1>
          <p class="demo-login-subtitle">${escHtml(t('demo.loginSubtitle'))}</p>
          <p class="demo-login-help">${escHtml(t('demo.loginHelp'))}</p>
        </header>

        <aside class="demo-login-panel demo-login-panel--quota-public" aria-label="${escHtml(t('demo.quotaPublic'))}">
          <p class="demo-login-quota-public">${escHtml(t('demo.quotaPublic'))}</p>
        </aside>

        ${err ? `<div class="demo-login-error error-banner" role="alert">${err}</div>` : ''}

        <form id="demo-login-form" class="demo-login-form" novalidate>
          <div class="form-group demo-login-field">
            <label for="demo-login-input">${escHtml(t('demo.loginField'))}</label>
            <input
              class="input demo-login-input"
              id="demo-login-input"
              name="login"
              type="text"
              autocomplete="username"
              required
              ${loading ? 'disabled' : ''}
            />
          </div>
          <div class="form-group demo-login-field">
            <label for="demo-password-input">${escHtml(t('demo.passwordField'))}</label>
            <input
              class="input demo-login-input"
              id="demo-password-input"
              name="password"
              type="password"
              autocomplete="current-password"
              required
              ${loading ? 'disabled' : ''}
            />
          </div>
          <button
            type="submit"
            class="btn btn-primary demo-login-submit${loading ? ' is-loading' : ''}"
            ${loading ? 'disabled aria-busy="true"' : ''}
          >
            <span class="demo-login-submit__label">${escHtml(loading ? t('demo.loginSubmitting') : t('demo.loginSubmit'))}</span>
          </button>
        </form>

        <footer class="demo-login-panel demo-login-panel--accounts">
          <h2 class="demo-login-panel__title">${escHtml(t('demo.accountTitle'))}</h2>
          <p class="demo-login-panel__hint">${escHtml(t('demo.accountsHint'))}</p>
          <div class="demo-login-credentials">
            ${publicCredentialChip()}
          </div>
        </footer>
      </section>
    </div>`;
}

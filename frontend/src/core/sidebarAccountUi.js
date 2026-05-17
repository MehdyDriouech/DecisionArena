/**
 * Bloc « Mon compte » sidenav (au-dessus du toggle Basic/Expert).
 */

function translate(key, vars = {}) {
  let s = window.i18n?.t(key) ?? key;
  for (const [k, v] of Object.entries(vars)) {
    s = s.split(`{${k}}`).join(String(v));
  }
  return s;
}

export function renderSidebarAccountBlock(state, escHtml) {
  if (!state.demoAuth?.authRequired) {
    return '';
  }
  const open = !!state.demoAuth.accountMenuOpen;
  const user = state.demoAuth.user;
  const login = user?.login ? escHtml(user.login) : '—';
  const role = user?.role ? escHtml(user.role) : '—';
  const quotaN = Number(user?.daily_llm_quota);
  const quotaLine = Number.isFinite(quotaN) && quotaN > 0
    ? escHtml(translate('demo.quotaDaily', { n: quotaN }))
    : '';

  return `
    <div class="sidebar-account" data-sidebar-account>
      <button
        type="button"
        class="btn btn-secondary btn-sm sidebar-account-toggle"
        style="width:100%;"
        data-action="toggle-demo-account-menu"
        aria-expanded="${open ? 'true' : 'false'}"
      >
        ${escHtml(translate('demo.myAccount'))}
      </button>
      <div class="sidebar-account-panel card" ${open ? '' : 'hidden'}>
        <p class="card-description" style="margin:0 0 6px;font-size:12px;">
          ${escHtml(translate('demo.connectedAs', { login }))}
        </p>
        <p class="card-description" style="margin:0 0 6px;font-size:12px;">
          ${escHtml(translate('demo.roleLabel', { role }))}
        </p>
        ${quotaLine ? `<p class="card-description sidebar-account-quota" style="margin:0 0 10px;font-size:12px;">${quotaLine}</p>` : ''}
        <button type="button" class="btn btn-secondary btn-sm" style="width:100%;" data-action="demo-logout">
          ${escHtml(translate('demo.logout'))}
        </button>
      </div>
    </div>`;
}

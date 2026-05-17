import { fetchDemoConfig, fetchDemoMe, demoLogin, demoLogout } from '../services/demoAuthService.js';

let mounted = false;

function t(key, vars = {}) {
  const i18n = window.i18n;
  let s = i18n?.t ? i18n.t(key) : key;
  for (const [k, v] of Object.entries(vars)) {
    s = s.replace(`{${k}}`, String(v));
  }
  return s;
}

async function refreshBanner(el) {
  const me = await fetchDemoMe();
  const status = document.getElementById('demo-auth-status');
  const form = document.getElementById('demo-auth-form');
  if (!status || !form) return;

  if (me.authenticated) {
    const rem = me.quota?.remaining ?? 0;
    status.textContent = `${me.user} — ${t('demo.quotaRemaining', { n: rem })}`;
    form.hidden = true;
    document.getElementById('demo-logout-btn').hidden = false;
  } else {
    status.textContent = t('demo.authRequired');
    form.hidden = false;
    document.getElementById('demo-logout-btn').hidden = true;
  }
}

export async function mountDemoBanner() {
  if (mounted) return;
  const cfg = await fetchDemoConfig();
  if (!cfg.demo_mode) return;

  const host = document.querySelector('.app-main-wrap') || document.getElementById('app');
  if (!host) return;

  const bar = document.createElement('div');
  bar.id = 'demo-auth-banner';
  bar.className = 'demo-auth-banner card';
  bar.innerHTML = `
    <strong>${t('demo.bannerTitle')}</strong>
    <span id="demo-auth-status" class="demo-auth-status"></span>
    <form id="demo-auth-form" class="demo-auth-form">
      <input type="text" id="demo-username" name="username" autocomplete="username" placeholder="${t('demo.username')}" />
      <input type="password" id="demo-password" name="password" autocomplete="current-password" placeholder="${t('demo.password')}" />
      <button type="submit" class="btn btn-primary btn-sm">${t('demo.login')}</button>
    </form>
    <button type="button" id="demo-logout-btn" class="btn btn-secondary btn-sm" hidden>${t('demo.logout')}</button>
  `;
  host.prepend(bar);

  bar.querySelector('#demo-auth-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const user = document.getElementById('demo-username')?.value || '';
    const pass = document.getElementById('demo-password')?.value || '';
    try {
      await demoLogin(user, pass);
      await refreshBanner(bar);
    } catch (err) {
      const st = document.getElementById('demo-auth-status');
      if (st) st.textContent = String(err.message || err);
    }
  });

  bar.querySelector('#demo-logout-btn')?.addEventListener('click', async () => {
    await demoLogout();
    await refreshBanner(bar);
  });

  await refreshBanner(bar);
  mounted = true;
}

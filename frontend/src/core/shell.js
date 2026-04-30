import { state } from './store.js';
import { mountHtml } from '../ui/components.js';

function renderSidebarShell(i18n) {
  const t = (key) => i18n?.t ? i18n.t(key) : key;
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  const lang = i18n?.getLanguage ? i18n.getLanguage() : 'fr';
  mountHtml(sidebar, `
    <div class="sidebar-logo">
      <span class="sidebar-logo-icon">🧠</span>
      <div class="sidebar-logo-title">${t('app.title')}</div>
      <div class="sidebar-logo-sub">${t('app.subtitle')}</div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-item ${state.view === 'dashboard' ? 'active' : ''}" data-nav="dashboard">
        <span class="nav-item-icon">🏠</span><span>${t('nav.dashboard')}</span>
      </div>
    </nav>
    <div class="sidebar-ui-mode">
      <div class="sidebar-ui-mode-label">${t('ui.mode.label')}</div>
      <div class="sidebar-ui-mode-buttons">
        <button type="button" class="language-option ${state.uiMode !== 'expert' ? 'active' : ''}" data-action="set-ui-mode" data-ui-mode="simple">${t('ui.mode.simple')}</button>
        <button type="button" class="language-option ${state.uiMode === 'expert' ? 'active' : ''}" data-action="set-ui-mode" data-ui-mode="expert">${t('ui.mode.expert')}</button>
      </div>
    </div>
    <div class="sidebar-lang">
      <button class="language-option ${lang === 'fr' ? 'active' : ''}" data-action="set-language" data-lang="fr">🇫🇷 FR</button>
      <button class="language-option ${lang === 'en' ? 'active' : ''}" data-action="set-language" data-lang="en">🇬🇧 EN</button>
    </div>
  `);
}

export { renderSidebarShell };

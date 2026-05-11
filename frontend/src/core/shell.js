import { state } from './store.js';
import { mountHtml } from '../ui/components.js';

function readAnalysesNavOpen() {
  if (typeof state.analysesSidebarOpen === 'boolean') return state.analysesSidebarOpen;
  try {
    const saved = localStorage.getItem('da_nav_analyses_open');
    if (saved === '0') return false;
    if (saved === '1') return true;
  } catch (_) {}
  return true;
}

function renderSidebarShell(i18n) {
  const t = (key) => i18n?.t ? i18n.t(key) : key;
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  const lang = i18n?.getLanguage ? i18n.getLanguage() : 'fr';
  const analysesOpen = readAnalysesNavOpen();
  mountHtml(sidebar, `
    <div class="sidebar-logo">
      <span class="sidebar-logo-icon">🧠</span>
      <div class="sidebar-logo-title">${t('app.title')}</div>
      <div class="sidebar-logo-sub">${t('app.subtitle')}</div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-item ${state.view === 'launch-assistant' ? 'active' : ''}" data-nav="launch-assistant">
        <span class="nav-item-icon">🚀</span><span>${t('dashboard.launchAssistant')}</span>
      </div>
      <div class="nav-group nav-group-analyses ${analysesOpen ? 'open' : 'closed'}">
        <div class="nav-group-header">
          <div class="nav-item nav-item-group-main ${state.view === 'analyses' || state.view === 'sessions' || state.view === 'new-session' || state.view === 'session-comparisons' || state.view === 'session-comparison' ? 'active' : ''}" data-nav="analyses">
            <span class="nav-item-icon">🗂️</span><span>${t('nav.sessions')}</span>
          </div>
          <button
            type="button"
            class="nav-group-toggle"
            data-action="toggle-analyses-submenu"
            aria-expanded="${analysesOpen ? 'true' : 'false'}"
            aria-label="${t('nav.sessions')}"
            title="${t('nav.sessions')}"
          >
            <span class="nav-group-chevron">▾</span>
          </button>
        </div>
        <div class="nav-submenu ${analysesOpen ? '' : 'is-collapsed'}">
          <div class="nav-item nav-item-sub ${state.view === 'new-session' ? 'active' : ''}" data-nav="new-session">
            <span class="nav-item-icon">＋</span><span>${t('nav.newSession')}</span>
          </div>
          <div class="nav-item nav-item-sub ${(state.view === 'analyses' || state.view === 'sessions') ? 'active' : ''}" data-nav="analyses">
            <span class="nav-item-icon">🕘</span><span>${t('nav.analysisHistory')}</span>
          </div>
          <div class="nav-item nav-item-sub ${(state.view === 'session-comparisons' || state.view === 'session-comparison') ? 'active' : ''}" data-nav="session-comparisons">
            <span class="nav-item-icon">⚖️</span><span>${t('dashboard.compareSessions')}</span>
          </div>
        </div>
      </div>
      <div class="nav-item ${state.view === 'dashboard' ? 'active' : ''}" data-nav="dashboard">
        <span class="nav-item-icon">🏠</span><span>${t('nav.dashboard')}</span>
      </div>
      <div class="nav-item ${state.view === 'strategic-contexts' ? 'active' : ''}" data-nav="strategic-contexts">
        <span class="nav-item-icon">🧭</span><span>${t('nav.contexts')}</span>
      </div>
      <div class="nav-item ${state.view === 'administration' ? 'active' : ''}" data-nav="administration">
        <span class="nav-item-icon">⚙️</span><span>${t('nav.admin')}</span>
      </div>
    </nav>
    <div class="sidebar-ui-mode">
      <div class="sidebar-ui-mode-label">${t('ui.mode.label')}</div>
      <div class="sidebar-ui-mode-buttons">
        <button type="button" class="language-option ${state.uiMode !== 'expert' ? 'active' : ''}" data-action="set-ui-mode" data-ui-mode="basic">${t('ui.mode.basic')}</button>
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

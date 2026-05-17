import { state, canShowExperimentalFeatures } from './store.js';
import { mountHtml } from '../ui/components.js';
import { renderSidebarAccountBlock } from './sidebarAccountUi.js';

function escHtml(s) {
  return window.DecisionArena?.utils?.escHtml ? window.DecisionArena.utils.escHtml(s) : String(s);
}

function readAnalysesNavOpen() {
  if (typeof state.analysesSidebarOpen === 'boolean') return state.analysesSidebarOpen;
  try {
    const saved = localStorage.getItem('da_nav_analyses_open');
    if (saved === '0') return false;
    if (saved === '1') return true;
  } catch (_) {}
  return true;
}

function readOpenSpaceNavOpen() {
  if (typeof state.openSpaceSidebarOpen === 'boolean') return state.openSpaceSidebarOpen;
  try {
    const saved = localStorage.getItem('da_nav_openspace_open');
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
  const openSpaceOpen = readOpenSpaceNavOpen();
  const analysesGroupActive = state.view === 'analyses'
    || state.view === 'sessions'
    || state.view === 'new-session'
    || state.view === 'session-comparisons'
    || state.view === 'session-comparison';
  const openSpaceGroupActive = state.view === 'openspace-orchestrator'
    || state.view === 'openspace-kanban'
    || state.view === 'openspace-agent-chat';
  const adminViews = ['personas', 'persona-builder', 'persona-maker', 'providers', 'souls', 'templates', 'template-maker', 'scenario-packs', 'logs', 'retrospective', 'learning', 'prompt-policies', 'cognitive-governance'];
  const isAdminSubView = adminViews.includes(state.view);
  const showOpenSpaceNav = canShowExperimentalFeatures(state);
  mountHtml(sidebar, `
    <div class="sidebar-logo">
      <span class="sidebar-logo-icon">🧠</span>
      <div class="sidebar-logo-title">${t('app.title')}</div>
      <div class="sidebar-logo-sub">${t('app.subtitle')}</div>
    </div>
    <nav class="sidebar-nav">
      <button type="button" class="nav-item ${state.view === 'launch-assistant' ? 'active' : ''}" data-nav="launch-assistant" ${state.view === 'launch-assistant' ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">🚀</span><span>${t('dashboard.launchAssistant')}</span>
      </button>
      <div class="nav-group nav-group-analyses ${analysesOpen ? 'open' : 'closed'}">
        <div class="nav-group-header">
          <button type="button" class="nav-item nav-item-group-main ${analysesGroupActive ? 'active' : ''}" data-nav="analyses" ${analysesGroupActive ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🗂️</span><span>${t('nav.sessions')}</span>
          </button>
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
          <button type="button" class="nav-item nav-item-sub ${state.view === 'new-session' ? 'active' : ''}" data-nav="new-session" ${state.view === 'new-session' ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">＋</span><span>${t('nav.newSession')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${(state.view === 'analyses' || state.view === 'sessions') ? 'active' : ''}" data-nav="analyses" ${(state.view === 'analyses' || state.view === 'sessions') ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🕘</span><span>${t('nav.analysisHistory')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${(state.view === 'session-comparisons' || state.view === 'session-comparison') ? 'active' : ''}" data-nav="session-comparisons" ${(state.view === 'session-comparisons' || state.view === 'session-comparison') ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">⚖️</span><span>${t('dashboard.compareSessions')}</span>
          </button>
        </div>
      </div>
      ${showOpenSpaceNav ? `
      <div class="nav-group nav-group-openspace ${openSpaceOpen ? 'open' : 'closed'}">
        <div class="nav-group-header">
          <button type="button" class="nav-item nav-item-group-main ${openSpaceGroupActive ? 'active' : ''}" data-nav="openspace-orchestrator" ${openSpaceGroupActive ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🛰️</span><span>${t('openspace.title')}</span>
            <span class="badge badge-warning" style="margin-left:6px;font-size:10px;">${t('openspace.alphaBadge')}</span>
          </button>
          <button
            type="button"
            class="nav-group-toggle"
            data-action="toggle-openspace-submenu"
            aria-expanded="${openSpaceOpen ? 'true' : 'false'}"
            aria-label="${t('openspace.title')}"
            title="${t('openspace.title')}"
          >
            <span class="nav-group-chevron">▾</span>
          </button>
        </div>
        <div class="nav-submenu ${openSpaceOpen ? '' : 'is-collapsed'}">
          <button type="button" class="nav-item nav-item-sub ${state.view === 'openspace-orchestrator' ? 'active' : ''}" data-nav="openspace-orchestrator" ${state.view === 'openspace-orchestrator' ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🎯</span><span>${t('openspace.orchestrator')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${state.view === 'openspace-kanban' ? 'active' : ''}" data-nav="openspace-kanban" ${state.view === 'openspace-kanban' ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">📌</span><span>${t('openspace.kanban')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${state.view === 'openspace-agent-chat' ? 'active' : ''}" data-nav="openspace-agent-chat" ${state.view === 'openspace-agent-chat' ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">💬</span><span>${t('openspace.agentChat')}</span>
          </button>
        </div>
      </div>
      ` : ''}
      <button type="button" class="nav-item ${state.view === 'dashboard' ? 'active' : ''}" data-nav="dashboard" ${state.view === 'dashboard' ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">🏠</span><span>${t('nav.dashboard')}</span>
      </button>
      <button type="button" class="nav-item ${state.view === 'strategic-contexts' ? 'active' : ''}" data-nav="strategic-contexts" ${state.view === 'strategic-contexts' ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">🧭</span><span>${t('nav.contexts')}</span>
      </button>
      <button type="button" class="nav-item ${state.view === 'administration' || isAdminSubView ? 'active' : ''}" data-nav="administration" ${state.view === 'administration' || isAdminSubView ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">⚙️</span><span>${t('nav.admin')}</span>
      </button>
    </nav>
    ${renderSidebarAccountBlock(state, escHtml)}
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

/* Renderer — manages DOM updates for sidebar and main content */
import { normalizeUiMode } from './store.js';
import { renderPendingConfirmation } from '../ui/components.js';

function t(key) {
  return window.i18n?.t(key) ?? key;
}

function escHtml(s) {
  return window.DecisionArena.utils.escHtml(s);
}

function readAnalysesNavOpen(state) {
  if (typeof state.analysesSidebarOpen === 'boolean') return state.analysesSidebarOpen;
  try {
    const saved = localStorage.getItem('da_nav_analyses_open');
    if (saved === '0') return false;
    if (saved === '1') return true;
  } catch (_) {}
  return true;
}

function renderSidebar() {
  const state = window.DecisionArena.store.state;
  const lang = window.i18n?.getLanguage() || 'fr';

  const nav = [
    { id: 'launch-assistant', icon: '🚀', label: t('dashboard.launchAssistant') },
    { id: 'dashboard',        icon: '🏠', label: t('nav.dashboard') },
    { id: 'strategic-contexts', icon: '🧭', label: t('nav.contexts') },
    { id: 'administration',   icon: '⚙️', label: t('nav.admin') },
  ];

  const adminViews   = ['personas', 'persona-builder', 'persona-maker', 'providers', 'souls', 'templates', 'template-maker', 'scenario-packs', 'logs', 'retrospective', 'learning', 'prompt-policies', 'cognitive-governance'];
  const isAdminSubView = adminViews.includes(state.view);
  const analysesGroupActive = ['analyses', 'sessions', 'new-session', 'session-comparisons', 'session-comparison'].includes(state.view);
  const analysesOpen = readAnalysesNavOpen(state);

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = `
    <div class="sidebar-logo">
      <span class="sidebar-logo-icon">🧠</span>
      <div class="sidebar-logo-title">${t('app.title')}</div>
      <div class="sidebar-logo-sub">${t('app.subtitle')}</div>
    </div>
    <nav class="sidebar-nav">
      <button type="button" class="nav-item ${state.view === 'launch-assistant' ? 'active' : ''}" data-nav="launch-assistant" ${state.view === 'launch-assistant' ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">🚀</span>
        <span>${t('dashboard.launchAssistant')}</span>
      </button>
      <button type="button" class="nav-item ${state.view === 'dashboard' ? 'active' : ''}" data-nav="dashboard" ${state.view === 'dashboard' ? 'aria-current="page"' : ''}>
        <span class="nav-item-icon">🏠</span>
        <span>${t('nav.dashboard')}</span>
      </button>
      <div class="nav-group nav-group-analyses ${analysesOpen ? 'open' : 'closed'}">
        <div class="nav-group-header">
          <button type="button" class="nav-item nav-item-group-main ${analysesGroupActive ? 'active' : ''}" data-nav="analyses" ${analysesGroupActive ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🗂️</span>
            <span>${t('nav.sessions')}</span>
          </button>
          <button
            type="button"
            class="nav-group-toggle"
            data-action="toggle-analyses-submenu"
            aria-expanded="${analysesOpen ? 'true' : 'false'}"
            aria-label="${escHtml(t('nav.sessions'))}"
            title="${escHtml(t('nav.sessions'))}"
          >
            <span class="nav-group-chevron">▾</span>
          </button>
        </div>
        <div class="nav-submenu ${analysesOpen ? '' : 'is-collapsed'}">
          <button type="button" class="nav-item nav-item-sub ${state.view === 'new-session' ? 'active' : ''}" data-nav="new-session" ${state.view === 'new-session' ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">＋</span>
            <span>${t('nav.newSession')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${(state.view === 'analyses' || state.view === 'sessions') ? 'active' : ''}" data-nav="analyses" ${(state.view === 'analyses' || state.view === 'sessions') ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">🕘</span>
            <span>${t('nav.analysisHistory')}</span>
          </button>
          <button type="button" class="nav-item nav-item-sub ${(state.view === 'session-comparisons' || state.view === 'session-comparison') ? 'active' : ''}" data-nav="session-comparisons" ${(state.view === 'session-comparisons' || state.view === 'session-comparison') ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">⚖️</span>
            <span>${t('dashboard.compareSessions')}</span>
          </button>
        </div>
      </div>
      ${nav.map((item) => {
        if (item.id === 'launch-assistant' || item.id === 'dashboard') return '';
        const isActive = state.view === item.id
          || (item.id === 'administration' && isAdminSubView);
        return `
          <button type="button" class="nav-item ${isActive ? 'active' : ''}" data-nav="${escHtml(item.id)}" ${isActive ? 'aria-current="page"' : ''}>
            <span class="nav-item-icon">${item.icon}</span>
            <span>${item.label}</span>
          </button>
        `;
      }).join('')}
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
  `;
}

function renderMain() {
  const state = window.DecisionArena.store.state;
  const main  = document.getElementById('main-content');
  if (!main) return;

  // Preserve scroll position when re-rendering the same view.
  // On view navigation we intentionally reset to the top.
  const prevView      = main.dataset.renderedView || '';
  const sameView      = prevView === state.view;
  const prevScroll    = sameView ? main.scrollTop : 0;
  // Full-height views (confrontation, decision-room, …) scroll inside .dr-content
  const innerEl       = sameView ? main.querySelector('.dr-content, .chat-messages') : null;
  const innerScroll   = innerEl ? innerEl.scrollTop : 0;

  const isNoProviderError = state.error && (
    state.error.toLowerCase().includes('no provider') ||
    state.error.toLowerCase().includes('aucun provider') ||
    state.error.toLowerCase().includes('please add a provider')
  );
  const errorBanner = state.error ? `
    <div class="error-banner" style="${isNoProviderError ? 'background:var(--warning,#f59e0b);color:#fff;' : ''}">
      ⚠️ ${isNoProviderError ? escHtml(t('error.noProvider')) : escHtml(state.error)}
      ${isNoProviderError ? `<button data-nav="providers" style="margin-left:12px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:4px;padding:4px 10px;cursor:pointer;">${t('error.goToProviders')}</button>` : ''}
      <button data-action="clear-error">${t('error.clear')}</button>
    </div>
  ` : '';
  const toastBanner = state.toast ? `
    <div class="success-banner">
      ✅ ${escHtml(String(state.toast))}
      <button data-action="clear-toast">${t('error.clear')}</button>
    </div>
  ` : '';

  const views  = window.DecisionArena.views || {};
  const viewFn = views[state.view] || views.dashboard;
  const confirmationOverlay = renderPendingConfirmation(state.pendingConfirmation, {
    modalOnly: true,
    uiMode: state.uiMode,
  });

  if (!viewFn) {
    main.innerHTML = errorBanner + toastBanner + `<div class="view-container"><p>View "${escHtml(state.view)}" not found.</p></div>`;
    return;
  }

  const fullHeightViews = ['chat', 'decision-room', 'confrontation', 'quick-decision', 'stress-test'];
  const plainViews      = ['persona-builder', 'persona-maker', 'session-history', 'template-maker', 'launch-assistant', 'session-comparisons', 'session-comparison'];

  if (fullHeightViews.includes(state.view) || plainViews.includes(state.view)) {
    main.innerHTML = errorBanner + toastBanner + viewFn() + confirmationOverlay;
  } else {
    main.innerHTML = `<div class="view-container">${errorBanner}${toastBanner}${viewFn()}</div>${confirmationOverlay}`;
  }

  main.dataset.renderedView = state.view;
  if (sameView && (prevScroll > 0 || innerScroll > 0)) {
    requestAnimationFrame(() => {
      if (prevScroll > 0) main.scrollTop = prevScroll;
      if (innerScroll > 0) {
        const newInner = main.querySelector('.dr-content, .chat-messages');
        if (newInner) newInner.scrollTop = innerScroll;
      }
    });
  }
}

function applyUiModeVisibility(mode) {
  const normalized = normalizeUiMode(mode);

  document.body.classList.toggle('ui-basic', normalized === 'basic');
  // Back-compat: older builds used "ui-simple". Ensure it never remains stuck.
  document.body.classList.toggle('ui-simple', false);
  document.body.classList.toggle('ui-expert', normalized === 'expert');

  document.querySelectorAll('[data-complexity]').forEach((el) => {
    el.style.display = normalized === 'expert' ? '' : 'none';
  });

  document.querySelectorAll('[data-ui-min]').forEach((el) => {
    const min = el.dataset.uiMin;
    // Legacy: some markup may still use data-ui-min="simple".
    el.style.display = (normalized === 'expert' || min === 'basic' || min === 'simple') ? '' : 'none';
  });
}

function applyComplexityVisibility(level) {
  applyUiModeVisibility(level);
}

function render() {
  renderSidebar();
  renderMain();
  try {
    const mode = window.DecisionArena.store.state.uiMode || 'basic';
    applyUiModeVisibility(mode);
  } catch (_) {}
}

export { render, renderSidebar, renderMain, applyUiModeVisibility, applyComplexityVisibility };

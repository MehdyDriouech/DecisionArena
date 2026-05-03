/* Renderer — manages DOM updates for sidebar and main content */

function t(key) {
  return window.i18n?.t(key) ?? key;
}

function escHtml(s) {
  return window.DecisionArena.utils.escHtml(s);
}

function renderSidebar() {
  const state = window.DecisionArena.store.state;
  const lang = window.i18n?.getLanguage() || 'fr';

  const nav = [
    { id: 'launch-assistant', icon: '🚀', label: t('dashboard.launchAssistant') },
    { id: 'dashboard',        icon: '🏠', label: t('nav.dashboard') },
    { id: 'new-session',      icon: '✨', label: t('nav.newSession') },
    { id: 'sessions',         icon: '📁', label: t('nav.sessions') },
    { id: 'administration',   icon: '⚙️', label: t('nav.admin') },
  ];

  const adminViews   = ['personas', 'persona-builder', 'persona-maker', 'providers', 'souls', 'templates', 'template-maker', 'scenario-packs', 'logs', 'retrospective'];
  const featureViews = ['launch-assistant', 'session-comparisons', 'session-comparison'];
  const isAdminSubView = adminViews.includes(state.view);

  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.innerHTML = `
    <div class="sidebar-logo">
      <span class="sidebar-logo-icon">🧠</span>
      <div class="sidebar-logo-title">${t('app.title')}</div>
      <div class="sidebar-logo-sub">${t('app.subtitle')}</div>
    </div>
    <nav class="sidebar-nav">
      ${nav.map((item) => {
        const isFeatureSubView = featureViews.includes(state.view);
        const isActive = state.view === item.id
          || (item.id === 'administration' && isAdminSubView)
          || (item.id === 'dashboard' && isFeatureSubView);
        return `
          <div class="nav-item ${isActive ? 'active' : ''}" data-nav="${escHtml(item.id)}">
            <span class="nav-item-icon">${item.icon}</span>
            <span>${item.label}</span>
          </div>
        `;
      }).join('')}
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

  const views  = window.DecisionArena.views || {};
  const viewFn = views[state.view] || views.dashboard;

  if (!viewFn) {
    main.innerHTML = errorBanner + `<div class="view-container"><p>View "${escHtml(state.view)}" not found.</p></div>`;
    return;
  }

  const fullHeightViews = ['chat', 'decision-room', 'confrontation', 'quick-decision', 'stress-test'];
  const plainViews      = ['persona-builder', 'persona-maker', 'session-history', 'template-maker', 'launch-assistant', 'session-comparisons', 'session-comparison'];

  if (fullHeightViews.includes(state.view) || plainViews.includes(state.view)) {
    main.innerHTML = errorBanner + viewFn();
  } else {
    main.innerHTML = `<div class="view-container">${errorBanner}${viewFn()}</div>`;
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

function applyComplexityVisibility(level) {
  const levels = { basic: 1, advanced: 2, expert: 3 };
  const current = levels[level] || 1;
  document.querySelectorAll('[data-complexity]').forEach((el) => {
    const required = levels[el.dataset.complexity] || 2;
    el.style.display = current >= required ? '' : 'none';
  });
}

function render() {
  renderSidebar();
  renderMain();
  try {
    const mode = window.DecisionArena.store.state.uiMode || 'simple';
    document.body.classList.toggle('ui-expert', mode === 'expert');
    document.body.classList.toggle('ui-simple', mode !== 'expert');
  } catch (_) {}
  try {
    const complexity = window.DecisionArena.store.state.uiComplexity || 'advanced';
    document.body.setAttribute('data-ui-complexity', complexity);
    applyComplexityVisibility(complexity);
  } catch (_) {}
}

export { render, renderSidebar, renderMain, applyComplexityVisibility };

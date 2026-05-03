/**
 * Sessions feature – view functions.
 * Covers: Dashboard, Session card (compact + full), Sessions list.
 */

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, formatDate, agentIcon, agentName, t };
}

function extractSessionOutcome(session) {
  let result = session?.result ?? null;
  if (typeof result === 'string' && result.trim()) {
    try { result = JSON.parse(result); } catch (_) { result = null; }
  }
  const adjusted = result?.adjusted_decision ?? null;
  const raw = result?.raw_decision ?? null;
  const decision = adjusted?.decision || raw?.decision || null;
  const confidenceRaw = adjusted?.confidence ?? raw?.confidence ?? null;
  const confidencePct = typeof confidenceRaw === 'number'
    ? Math.max(0, Math.min(100, Math.round(confidenceRaw * 100)))
    : null;
  return { decision, confidencePct };
}

function renderSessionCard(session, fullActions = false) {
  const { state, escHtml, formatDate, agentIcon, agentName, t } = getCtx();
  const outcome = extractSessionOutcome(session);
  const decisionBadge = outcome.decision
    ? `<span class="badge badge-info">Décision: ${escHtml(String(outcome.decision).replace(/_/g, ' '))}</span>`
    : '';
  const confidenceBadge = outcome.confidencePct !== null
    ? `<span class="badge badge-muted">Confiance: ${outcome.confidencePct}%</span>`
    : '';
  const statusInline = session.status
    ? `<span class="badge ${session.status === 'completed' ? 'badge-success' : session.status === 'error' ? 'badge-danger' : 'badge-muted'}">Statut: ${escHtml(session.status)}</span>`
    : '';
  const insightsRow = (decisionBadge || confidenceBadge || statusInline)
    ? `<div class="session-card-insights" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">${decisionBadge}${confidenceBadge}${statusInline}</div>`
    : '';

  const modeIcons  = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥' };
  const modeLabels = {
    chat:              t('mode.chat').replace('💬 ', ''),
    'decision-room':   t('mode.decisionRoom').replace('🏛️ ', ''),
    confrontation:     t('mode.confrontation').replace('⚔️ ', ''),
    'quick-decision':  t('mode.quickDecision').replace('⚡ ', ''),
    'stress-test':     t('mode.stressTest').replace('🔥 ', ''),
  };
  const icon       = modeIcons[session.mode] || '💬';
  const label      = modeLabels[session.mode] || session.mode;
  const badgeClass = session.mode === 'decision-room' ? 'badge-info'
                   : session.mode === 'confrontation'  ? 'badge-warning'
                   : 'badge-default';

  const statusBadge = (() => {
    const s = session.status || 'draft';
    const cls = s === 'completed' ? 'badge-success' : s === 'error' ? 'badge-danger' : 'badge-muted';
    return `<span class="badge ${cls}">${escHtml(s)}</span>`;
  })();

  const agents = (session.selected_agents || []).slice(0, 5);

  if (!fullActions) {
    return `
      <div class="session-card session-card-compact" data-action="open-session" data-session-id="${escHtml(session.id)}" data-mode="${escHtml(session.mode)}">
        <div class="session-card-compact-top">
          <div class="session-card-compact-main">
            <span class="session-card-icon">${icon}</span>
            <div class="session-card-info">
              <div class="session-card-title">${escHtml(session.title)}</div>
              <div class="session-card-meta">
                <span>${formatDate(session.created_at)}</span>
                <span class="badge ${badgeClass}">${label}</span>
                ${statusBadge}
              </div>
            </div>
          </div>
          <span class="session-card-compact-open">↗</span>
        </div>
        ${agents.length > 0 ? `
          <div class="session-agents session-agents-compact">
            ${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}
          </div>
        ` : ''}
        ${insightsRow}
      </div>
    `;
  }

  return `
    <div class="session-card-full">
      <div class="session-card-full-header">
        <span class="session-icon" style="font-size:24px;">${icon}</span>
        <div class="session-info" style="flex:1;min-width:0;">
          <div class="session-title">${session.is_favorite ? '⭐ ' : ''}${session.is_reference ? '📌 ' : ''}${escHtml(session.title)}</div>
          <div class="session-meta" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;">
            <span>${formatDate(session.created_at)}</span>
            <span class="badge ${badgeClass}">${label}</span>
            <span style="margin-left:4px;">${statusBadge}</span>
            ${session.force_disagreement ? `<span class="badge badge-warning" style="font-size:11px;">${t('newSession.forceDisagreementActive')}</span>` : ''}
          </div>
        </div>
      </div>
      ${agents.length > 0 ? `
        <div class="session-agents" style="margin:8px 0;">
          ${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}
          ${(session.selected_agents || []).length > 5 ? `<span class="agent-badge">+${(session.selected_agents.length - 5)}</span>` : ''}
        </div>
      ` : ''}
      ${insightsRow}
      <div class="session-card-full-actions">
        <button class="btn btn-primary btn-sm" data-action="open-session" data-session-id="${escHtml(session.id)}" data-mode="${escHtml(session.mode)}">
          ${t('sessions.open')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="open-rerun-modal" data-session-id="${escHtml(session.id)}">
          🔁 ${t('sessions.rerun')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="fork-session" data-session-id="${escHtml(session.id)}" title="${escHtml(t('hitl.forkVariant'))}">
          🔀 ${t('hitl.forkVariant')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="markdown">
          ${t('sessions.exportMd')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="json">
          ${t('sessions.exportJson')}
        </button>
        <button class="btn btn-danger btn-sm" data-action="delete-session" data-session-id="${escHtml(session.id)}" data-session-title="${escHtml(session.title)}">
          ${t('sessions.delete')}
        </button>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);cursor:pointer;margin-left:auto;">
          <input type="checkbox" data-action="toggle-compare-session" data-session-id="${escHtml(session.id)}" ${(state.compareSelectedIds || []).includes(session.id) ? 'checked' : ''} style="accent-color:var(--accent);">
          ${t('sessions.selectForCompare')}
        </label>
      </div>
    </div>
  `;
}

function renderDashboard() {
  const { state, t } = getCtx();
  const recent = state.sessions.slice(0, 5);

  return `
    <div class="page-header">
      <div class="page-title">${t('dashboard.title')}</div>
      <div class="page-subtitle">${t('dashboard.subtitle')}</div>
    </div>

    <div class="hero-block" style="margin:0 0 20px;padding:24px;border:1px solid var(--border);border-radius:12px;background:linear-gradient(135deg,rgba(99,102,241,0.12),rgba(139,92,246,0.08));">
      <div style="font-size:28px;font-weight:800;line-height:1.2;margin-bottom:8px;">
        Que voulez-vous décider aujourd’hui ?
      </div>
      <div style="font-size:14px;color:var(--text-secondary);margin-bottom:16px;max-width:680px;">
        Simulez plusieurs experts IA pour obtenir une décision argumentée.
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-primary btn-lg" data-action="launch-quick-analysis">
          🔥 Analyser en 1 clic
        </button>
        <button class="btn btn-secondary" data-action="dashboard-intent-explore">
          Explorer une idée
        </button>
        <button class="btn btn-secondary" data-action="dashboard-intent-decide">
          Prendre une décision
        </button>
        <button class="btn btn-secondary" data-action="dashboard-intent-test">
          Tester une idée
        </button>
      </div>
    </div>

    <div class="action-buttons" data-ui="expert-only">
      <button class="btn btn-secondary btn-sm" data-action="goto-new-session" data-mode="chat">
        <span class="btn-icon">💬</span> Nouveau Chat Multi-Agent
      </button>
      <button class="btn btn-secondary btn-sm" data-action="goto-new-session" data-mode="decision-room">
        <span class="btn-icon">🏛️</span> Nouvelle Decision Room
      </button>
      <button class="btn btn-secondary btn-sm" data-action="goto-new-session" data-mode="confrontation">
        <span class="btn-icon">⚔️</span> Nouvelle Confrontation
      </button>
      <button class="btn btn-secondary btn-sm" data-nav="session-comparisons">
        <span class="btn-icon">⚖️</span> ${t('dashboard.compareSessions')}
      </button>
    </div>

    <div class="section">
      <div class="section-header">
        <span class="section-label">${t('dashboard.recentSessions')}</span>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('dashboard.viewAll')}</button>
      </div>
      ${recent.length === 0 ? `
        <div class="empty-state">
          <div class="empty-state-icon">📂</div>
          <div class="empty-state-text">Vous n’avez pas encore lancé d’analyse.<br>Commencez en cliquant sur ‘Analyser en 1 clic’.</div>
        </div>
      ` : recent.map((s) => renderSessionCard(s, false)).join('')}
    </div>
  `;
}

function renderSessions() {
  const { state, t } = getCtx();
  const filter   = state.sessionFilter || 'all';
  const filtered = state.sessions.filter((s) => {
    if (filter === 'favorites')   return s.is_favorite;
    if (filter === 'references')  return s.is_reference;
    return true;
  });

  return `
    <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;">
      <div>
        <div class="page-title">${t('sessions.title')}</div>
        <div class="page-subtitle">${t('sessions.subtitle')}</div>
      </div>
      ${state.sessions.length > 0 ? `
        <button class="btn btn-danger btn-sm" data-action="delete-all-sessions" style="flex-shrink:0;margin-top:4px;">
          🗑️ ${t('sessions.deleteAll')}
        </button>
      ` : ''}
    </div>

    <div class="filter-tabs" style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;">
      <button class="btn btn-sm ${filter === 'all'        ? 'btn-primary' : 'btn-secondary'}" data-action="set-session-filter" data-filter="all">${t('sessions.filterAll')}</button>
      <button class="btn btn-sm ${filter === 'favorites'  ? 'btn-primary' : 'btn-secondary'}" data-action="set-session-filter" data-filter="favorites">⭐ ${t('sessions.filterFavorites')}</button>
      <button class="btn btn-sm ${filter === 'references' ? 'btn-primary' : 'btn-secondary'}" data-action="set-session-filter" data-filter="references">📌 ${t('sessions.filterReferences')}</button>
      ${state.compareSelectedIds.length >= 2 ? `
        <button class="btn btn-primary btn-sm" data-action="goto-compare-sessions">⚖️ ${t('sessions.compareSelected')} (${state.compareSelectedIds.length})</button>
      ` : `
        <span style="font-size:12px;color:var(--text-muted);align-self:center;margin-left:8px;">${t('sessions.compareHint')}</span>
      `}
    </div>

    ${filtered.length === 0 ? `
      <div class="empty-state">
        <div class="empty-state-icon">📁</div>
        <div class="empty-state-text">${t('sessions.empty')}</div>
      </div>
    ` : filtered.map((s) => renderSessionCard(s, true)).join('')}
  `;
}

export { renderDashboard, renderSessions, renderSessionCard };

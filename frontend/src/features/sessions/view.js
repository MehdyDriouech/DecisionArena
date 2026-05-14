/**
 * Sessions feature – view functions.
 * Covers: Dashboard, Session card (compact + full), Sessions list.
 */

import { renderEmptyState } from '../../ui/components.js';
import { getPlaybookIntentGroups } from '../../core/playbooks.js';
import { renderPlaybookCard } from '../../ui/components.js';
import { mapAnalysisLifecycle } from '../../core/store.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, formatDate, agentIcon, agentName, t };
}

function strategicContextBadgeHtml(session, state, escHtml, t) {
  const scid = session.strategic_context_id;
  if (!scid) {
    return `<span class="badge badge-muted" style="font-size:11px;">${escHtml(t('sessions.badgeLegacyNoContext'))}</span>`;
  }
  const items = Array.isArray(state.strategicContexts?.items) ? state.strategicContexts.items : [];
  const ctx = items.find((c) => String(c.context_id) === String(scid));
  const label = ctx?.title ? String(ctx.title) : `${String(scid).slice(0, 8)}…`;
  return `<span class="badge badge-info" style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(String(scid))}">${escHtml(t('sessions.badgeContext'))}: ${escHtml(label)}</span>`;
}

/** Aligné sur la vue Contextes stratégiques : cycle de vie ≠ espace workspace. */
function dashboardContextLifecycleBadgeClass(status) {
  switch (String(status || 'active')) {
    case 'active': return 'badge-info';
    case 'paused': return 'badge-warning';
    case 'completed': return 'badge-muted';
    case 'abandoned': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function dashboardContextLifecycleLabel(t, status) {
  const k = String(status || 'active').trim() || 'active';
  const key = `contexts.status.${k}`;
  const lab = t(key);
  return lab === key ? k : lab;
}

function extractSessionOutcome(session) {
  let result = session?.result ?? null;
  if (typeof result === 'string' && result.trim()) {
    try { result = JSON.parse(result); } catch (_) { result = null; }
  }
  const adjusted = session?.adjusted_decision ?? result?.adjusted_decision ?? null;
  const raw = session?.raw_decision ?? session?.automatic_decision ?? result?.raw_decision ?? result?.automatic_decision ?? null;
  const decision =
    adjusted?.ui_decision_label ||
    adjusted?.legacy_decision_label ||
    adjusted?.decision_label ||
    adjusted?.vote_label ||
    adjusted?.final_outcome ||
    adjusted?.decision ||
    raw?.ui_decision_label ||
    raw?.legacy_decision_label ||
    raw?.decision_label ||
    raw?.vote_label ||
    raw?.final_outcome ||
    raw?.decision ||
    null;
  const confidenceRaw =
    adjusted?.confidence ??
    adjusted?.decision_score ??
    raw?.confidence ??
    raw?.decision_score ??
    null;
  const confidencePct = typeof confidenceRaw === 'number'
    ? Math.max(0, Math.min(100, Math.round(confidenceRaw <= 1 ? confidenceRaw * 100 : confidenceRaw)))
    : null;
  return { decision, confidencePct };
}

function lifecycleLabel(status, t) {
  const key = `analysis.lifecycle.${status}`;
  const translated = t(key);
  return translated === key ? status : translated;
}

function renderSessionCard(session, fullActions = false) {
  const { state, escHtml, formatDate, agentIcon, agentName, t } = getCtx();
  const isExpert = state.uiMode === 'expert';
  const lifecycle = mapAnalysisLifecycle(session);
  const outcome = extractSessionOutcome(session);
  const decisionBadge = outcome.decision
    ? `<span class="badge badge-info">Décision: ${escHtml(String(outcome.decision).replace(/_/g, ' '))}</span>`
    : '';
  const confidenceBadge = outcome.confidencePct !== null
    ? `<span class="badge badge-muted">Confiance: ${outcome.confidencePct}%</span>`
    : '';
  const statusInline = `<span class="badge ${lifecycle.primaryStatus === 'completed' ? 'badge-success' : lifecycle.primaryStatus === 'running' ? 'badge-info' : lifecycle.primaryStatus === 'archived' ? 'badge-muted' : 'badge-default'}">Statut: ${escHtml(lifecycleLabel(lifecycle.primaryStatus, t))}</span>`;
  const statusOverlayInline = lifecycle.overlays
    .map((overlay) => `<span class="badge ${overlay === 'blocked' ? 'badge-danger' : overlay === 'fragile' ? 'badge-warning' : 'badge-primary'}">${escHtml(lifecycleLabel(overlay, t))}</span>`)
    .join('');
  const insightsRow = (decisionBadge || confidenceBadge || statusInline)
    ? `<div class="session-card-insights">${decisionBadge}${confidenceBadge}${statusInline}${statusOverlayInline}</div>`
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
    const s = lifecycle.primaryStatus;
    const cls = s === 'completed' ? 'badge-success' : s === 'running' ? 'badge-info' : s === 'archived' ? 'badge-muted' : 'badge-default';
    const overlays = lifecycle.overlays
      .map((ov) => `<span class="badge ${ov === 'blocked' ? 'badge-danger' : ov === 'fragile' ? 'badge-warning' : 'badge-primary'}">${escHtml(lifecycleLabel(ov, t))}</span>`)
      .join('');
    return `<span class="badge ${cls}">${escHtml(lifecycleLabel(s, t))}</span>${overlays}`;
  })();

  const agents = (session.selected_agents || []).slice(0, 5);
  const canRerunOrFork = lifecycle.primaryStatus === 'completed' || lifecycle.primaryStatus === 'archived';

  if (!fullActions) {
    return `
      <div class="session-card session-card-compact">
        <div class="session-card-compact-top">
          <div class="session-card-compact-main">
            <span class="session-card-icon">${icon}</span>
            <div class="session-card-info">
              <div class="session-card-title">${escHtml(session.title)}</div>
              <div class="session-card-meta">
                <span>${formatDate(session.created_at)}</span>
                <span class="badge ${badgeClass}">${label}</span>
                ${strategicContextBadgeHtml(session, state, escHtml, t)}
                ${statusBadge}
              </div>
            </div>
          </div>
        </div>
        ${agents.length > 0 ? `
          <div class="session-agents session-agents-compact">
            ${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}
          </div>
        ` : ''}
        ${insightsRow}
        <div class="session-card-actions">
          <button class="btn btn-primary btn-sm" data-action="open-session" data-session-id="${escHtml(session.id)}" data-mode="${escHtml(session.mode)}">
            Voir la d&eacute;cision
          </button>
        </div>
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
            ${strategicContextBadgeHtml(session, state, escHtml, t)}
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
        <button class="btn btn-secondary btn-sm" data-action="open-rerun-modal" data-session-id="${escHtml(session.id)}" ${canRerunOrFork ? '' : 'disabled'}>
          🔁 ${t('sessions.rerun')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="fork-session" data-session-id="${escHtml(session.id)}" title="${escHtml(t('hitl.forkVariant'))}" ${canRerunOrFork ? '' : 'disabled'}>
          🔀 ${t('hitl.forkVariant')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="markdown">
          ${t('sessions.exportMd')}
        </button>
        ${isExpert ? `
          <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="export-session" data-session-id="${escHtml(session.id)}" data-format="json">
            ${t('sessions.exportJson')}
          </button>
          <button class="btn btn-danger btn-sm" data-ui="expert-only" data-action="delete-session" data-session-id="${escHtml(session.id)}" data-session-title="${escHtml(session.title)}">
            ${t('sessions.delete')}
          </button>
        ` : ''}
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);cursor:pointer;margin-left:auto;">
          <input type="checkbox" data-action="toggle-compare-session" data-session-id="${escHtml(session.id)}" ${(state.compareSelectedIds || []).includes(session.id) ? 'checked' : ''} style="accent-color:var(--accent);">
          ${t('sessions.selectForCompare')}
        </label>
      </div>
    </div>
  `;
}

function renderDashboard() {
  const { state, escHtml, t } = getCtx();
  const recent = state.sessions.slice(0, 5);
  const isExpert = state.uiMode === 'expert';
  const lang = window.i18n?.getLanguage?.() || 'fr';
  const contextsPkg = state.strategicContexts || { items: [] };
  const contexts = Array.isArray(contextsPkg.items) ? contextsPkg.items : [];
  const activeWorkspaceId = String(state.activeStrategicContextId || state.activeStrategicContext?.context_id || '').trim();
  const activeWorkspaceContext = contexts.find(
    (c) => Number(c.is_workspace_active) === 1 || String(c.context_id) === activeWorkspaceId,
  ) || null;
  const activeContexts = activeWorkspaceContext ? [activeWorkspaceContext] : [];
  const playbookGroups = getPlaybookIntentGroups(lang).map((group) => `
    <section class="playbook-intent-group">
      <div class="playbook-intent-group-head">
        <div class="playbook-intent-label">${escHtml(group.label)}</div>
        <div class="playbook-intent-question">${escHtml(group.question)}</div>
        <div class="playbook-intent-description">${escHtml(group.description)}</div>
      </div>
      <div class="playbook-intent-cards">
        ${group.playbooks.map((playbook) => renderPlaybookCard(playbook, {
          escHtml,
          compact: !isExpert,
          language: lang,
        })).join('')}
      </div>
    </section>
  `).join('');

  const heroSimple = `
    <div class="hero-block">
      <h1 class="hero-title">${t('dashboard.simple.heroTitle')}</h1>
      <p class="hero-copy">${t('dashboard.simple.heroSubtitle')}</p>
      <div class="dashboard-simple-ctas" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
        <button type="button" class="btn btn-primary btn-lg" data-action="select-playbook" data-playbook-id="founder-sprint">
          ${t('dashboard.simple.primaryCta')}
        </button>
        <button type="button" class="btn btn-secondary" data-nav="new-session">
          ${t('dashboard.configureAnalysis')}
        </button>
      </div>
    </div>
  `;

  const heroExpert = `
    <div class="hero-block">
      <h1 class="hero-title">${t('dashboard.heroTitle')}</h1>
      <p class="hero-copy">${t('dashboard.heroSubtitle')}</p>
      <div class="intent-grid">
        <button type="button" class="btn btn-secondary" data-nav="new-session">
          ${t('dashboard.configureAnalysis')}
        </button>
      </div>
    </div>
  `;

  return `
    ${isExpert ? heroExpert : heroSimple}

    <div class="section" style="margin-bottom:22px;">
      <div class="section-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <span class="section-label">${escHtml(t('nav.contexts'))}</span>
        <div style="display:flex;gap:8px;align-items:center;">
          <button class="btn btn-secondary btn-sm" data-nav="strategic-contexts">${escHtml(t('dashboard.viewAll'))}</button>
          <button class="btn btn-secondary btn-sm" data-action="load-strategic-contexts">↺</button>
        </div>
      </div>
      ${activeContexts.length === 0 ? `
        <div class="empty-state" style="padding:16px 0;">
          <div class="empty-state-text">${escHtml(lang === 'en' ? 'No in-progress strategic context yet. Create one to group related decisions.' : 'Aucun contexte stratégique « en cours ». Créez-en un pour regrouper les décisions liées.')}</div>
          <button class="btn btn-primary btn-sm" data-nav="strategic-contexts">＋ ${escHtml(lang === 'en' ? 'Create context' : 'Créer un contexte')}</button>
        </div>
      ` : `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
          ${activeContexts.map((c) => {
            const cs = c.current_state || {};
            const risks = Array.isArray(cs.active_risks) ? cs.active_risks : [];
            const isWorkspace = Number(c.is_workspace_active) === 1 || String(c.context_id) === activeWorkspaceId;
            const st = String(c.status || 'active');
            const workspaceBadge = isWorkspace
              ? `<span class="badge badge-success">${escHtml(t('contexts.workspaceBadge'))}</span>`
              : '';
            const lifecycleBadge = `<span class="badge ${dashboardContextLifecycleBadgeClass(st)}">${escHtml(dashboardContextLifecycleLabel(t, st))}</span>`;
            return `
              <div class="card" style="padding:14px 16px;cursor:pointer;" data-action="goto-context" data-context-id="${escHtml(c.context_id)}">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  ${workspaceBadge}${lifecycleBadge}
                  <div style="font-weight:800;">${escHtml(String(c.title || ''))}</div>
                  <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(String(c.updated_at || ''))}</span>
                </div>
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                  ${cs.current_decision_status ? `<span class="badge badge-info">${escHtml(cs.current_decision_status)}</span>` : `<span class="badge badge-muted">—</span>`}
                  ${cs.current_confidence ? `<span class="badge badge-muted">${escHtml(t('contexts.confidence'))}: ${escHtml(cs.current_confidence)}</span>` : ''}
                  <span class="badge ${risks.length ? 'badge-warning' : 'badge-muted'}">${escHtml(t('contexts.risks'))}: ${risks.length}</span>
                </div>
                ${cs.latest_next_step ? `<div style="margin-top:8px;font-size:12px;color:var(--text-secondary);"><strong>${escHtml(t('contexts.next'))}:</strong> ${escHtml(cs.latest_next_step)}</div>` : ''}
              </div>
            `;
          }).join('')}
        </div>
      `}
    </div>

    ${!isExpert ? `
      <div class="section dashboard-playbooks" style="margin-bottom:24px;">
        <div class="section-header">
          <span class="section-label">${lang === 'en' ? 'Choose by decision intent' : 'Choisir par intention de décision'}</span>
        </div>
        <div class="playbook-intent-groups">
          ${playbookGroups}
        </div>
      </div>
    ` : ''}

    <div class="sessions-list">
      <div class="section-header">
        <span class="section-label">${t('dashboard.recentSessions')}</span>
        <button class="btn btn-secondary btn-sm" data-nav="analyses">${t('dashboard.viewAll')}</button>
      </div>
      ${recent.length === 0 ? `
        <div class="empty-state">
          <p>${t('dashboard.simple.emptyHint')}</p>
          <button class="btn btn-primary btn-sm" data-action="launch-quick-analysis">
            ${t('dashboard.simple.emptyCta')}
          </button>
        </div>
      ` : recent.map((s) => renderSessionCard(s, false)).join('')}
    </div>

    <div class="dashboard-technical-shortcuts" data-ui="expert-only">
      <button type="button" class="btn btn-secondary btn-sm" data-nav="new-session">
        ${t('nav.newSession')}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" data-nav="analyses">
        ${t('nav.sessions')}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" data-action="goto-compare-sessions">
        ${t('dashboard.compareSessions')}
      </button>
      <button type="button" class="btn btn-secondary btn-sm" data-nav="launch-assistant">
        ${t('dashboard.launchAssistant')}
      </button>
    </div>
  `;
}

export { renderDashboard, renderSessionCard };

/**
 * Graph View + Argument Memory — view module.
 *
 * Exposes:
 *  - renderGraphViewPanel(sessionId) — embeddable panel for session detail views
 *  - renderGraphView()               — standalone view (registered as 'graph-view')
 *
 * State used:
 *   state.graphData    — { nodes, edges, arguments, positions } | null
 *   state.graphLoading — boolean
 */

import { renderPanelRecommendBadge, renderTooltip } from '../../ui/components.js';

function panelHl(state) {
  return state.sessionHistory?.panelHighlights || state.auditData?.highlights || [];
}

function graphTitleRow(t, state) {
  return `📊 ${t('graph.title')} ${renderTooltip(t('tooltip.graph'))} ${renderPanelRecommendBadge('graph', panelHl(state), t)}`;
}

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, t, agentIcon, agentName };
}

// ── Interaction list ──────────────────────────────────────────────────────

function renderEdgeList(edges) {
  const { escHtml, t } = getCtx();
  if (!edges || edges.length === 0) return '';

  const byRound = edges.reduce((acc, e) => {
    const r = e.round ?? '?';
    if (!acc[r]) acc[r] = [];
    acc[r].push(e);
    return acc;
  }, {});

  const rows = Object.keys(byRound).sort((a, b) => Number(a) - Number(b)).map((round) => {
    const items = byRound[round].map((e) => `
      <div class="graph-edge-item">
        <span class="graph-edge-agents">${escHtml(e.source_agent_id)} → ${escHtml(e.target_agent_id)}</span>
        <span class="badge ${e.edge_type === 'challenge' || e.edge_type === 'contradict' ? 'badge-danger' : 'badge-info'}">
          ${escHtml(e.edge_type || 'neutral')}
        </span>
        ${e.weight ? `<span class="badge badge-muted">w=${Number(e.weight)}</span>` : ''}
      </div>
    `).join('');
    return `
      <div class="graph-round-group">
        <div class="graph-round-label">Round ${escHtml(String(round))}</div>
        ${items}
      </div>
    `;
  }).join('');

  return `
    <div class="graph-section">
      <div class="graph-section-title">🔗 ${t('graph.interactions')}</div>
      ${rows}
    </div>
  `;
}

// ── Argument Memory ───────────────────────────────────────────────────────

function renderArgMemory(args) {
  const { escHtml, t } = getCtx();
  if (!args || args.length === 0) return '';

  const challengeCountById = {};
  const reuseCountByText   = {};
  args.forEach((arg) => {
    if (arg.target_argument_id) {
      challengeCountById[arg.target_argument_id] = (challengeCountById[arg.target_argument_id] || 0) + 1;
    }
    const sig = (arg.argument_text || '').trim().toLowerCase();
    if (sig) reuseCountByText[sig] = (reuseCountByText[sig] || 0) + 1;
  });

  const typeOrder = ['claim', 'risk', 'assumption', 'counter_argument', 'question'];
  const typeLabel = (type) => ({
    claim: t('debate.argumentTypeClaim'),
    risk: t('debate.argumentTypeRisk'),
    assumption: t('debate.argumentTypeAssumption'),
    counter_argument: t('debate.argumentTypeCounter'),
    question: t('debate.argumentTypeQuestion'),
  }[type] || type);

  const grouped = args.reduce((acc, arg) => {
    const key = arg.argument_type || 'claim';
    if (!acc[key]) acc[key] = [];
    acc[key].push(arg);
    return acc;
  }, {});

  const sectionsHtml = typeOrder.filter((type) => grouped[type]?.length).map((type) => {
    const items = grouped[type].slice(0, 8).map((arg) => {
      const sig       = (arg.argument_text || '').trim().toLowerCase();
      const reused    = Math.max(0, (reuseCountByText[sig] || 1) - 1);
      const challenged = challengeCountById[arg.id] || 0;
      return `
        <div class="debate-argument-item">
          <div class="debate-argument-text">${escHtml(arg.argument_text || '')}</div>
          <div class="debate-argument-meta">
            <span class="badge badge-muted">${escHtml(arg.agent_id || 'agent')}</span>
            <span class="badge ${challenged > 0 ? 'badge-danger' : 'badge-info'}">${t('debate.challenged')}: ${challenged}</span>
            <span class="badge badge-neutral">${t('debate.reused')}: ${reused}</span>
          </div>
        </div>
      `;
    }).join('');
    return `<div class="debate-group"><div class="debate-group-title">${typeLabel(type)}</div>${items}</div>`;
  }).join('');

  return `
    <div class="graph-section">
      <div class="graph-section-title">🧠 ${t('graph.argumentMemory')}</div>
      ${sectionsHtml}
    </div>
  `;
}

// ── Agent Positions ───────────────────────────────────────────────────────

function renderAgentPositions(positions) {
  const { escHtml, agentIcon, agentName, t } = getCtx();
  if (!positions || positions.length === 0) return '';

  // Latest position per agent
  const latestByAgent = {};
  positions.forEach((pos) => {
    const aid = pos.agent_id || 'agent';
    if (!latestByAgent[aid] || Number(pos.round || 0) >= Number(latestByAgent[aid].round || 0)) {
      latestByAgent[aid] = pos;
    }
  });

  const cards = Object.values(latestByAgent).map((pos) => {
    const icon = agentIcon(pos.agent_id);
    const name = agentName(pos.agent_id);
    const stanceColor = {
      go: '#34d399', 'no-go': '#f87171', risky: '#fbbf24',
      'needs-more-info': '#60a5fa', 'reduce-scope': '#a78bfa',
    }[pos.stance] || 'var(--text-secondary)';

    return `
      <div class="graph-position-card">
        <div class="graph-position-header">
          <span style="font-size:20px;">${icon}</span>
          <div>
            <div class="graph-position-name">${escHtml(name)}</div>
            <span class="badge" style="background:${stanceColor}20;color:${stanceColor};font-size:11px;">${escHtml(pos.stance || 'unknown')}</span>
          </div>
          <div class="graph-position-scores">
            <span class="badge badge-muted" title="Confidence">C: ${Number(pos.confidence || 0)}</span>
            <span class="badge badge-muted" title="Weight">W: ${Number(pos.weight_score || 0).toFixed(1)}</span>
          </div>
        </div>
        ${pos.main_argument ? `<div class="graph-position-row"><span class="graph-position-key">${t('graph.mainArgument')}:</span> ${escHtml(pos.main_argument)}</div>` : ''}
        ${pos.biggest_risk  ? `<div class="graph-position-row"><span class="graph-position-key">${t('graph.biggestRisk')}:</span> ${escHtml(pos.biggest_risk)}</div>` : ''}
        ${pos.change_since_last_round ? `<div class="graph-position-row" style="color:var(--text-warning,#fbbf24);font-size:11px;">↗ ${escHtml(pos.change_since_last_round)}</div>` : ''}
      </div>
    `;
  }).join('');

  return `
    <div class="graph-section">
      <div class="graph-section-title">👤 ${t('graph.agentPositions')}</div>
      <div class="graph-positions-grid">${cards}</div>
    </div>
  `;
}

// ── Lightweight visual graph (SVG flex layout) ────────────────────────────

function renderVisualGraph(nodes, edges) {
  if (!nodes || nodes.length === 0) return '';

  const W = 480;
  const H = 240;
  const cx = W / 2;
  const cy = H / 2;
  const R  = Math.min(cx, cy) - 40;

  const angle = (i) => (2 * Math.PI * i) / nodes.length - Math.PI / 2;
  const coords = nodes.map((_, i) => ({
    x: cx + R * Math.cos(angle(i)),
    y: cy + R * Math.sin(angle(i)),
  }));

  const nodeIndex = {};
  nodes.forEach((n, i) => { nodeIndex[n] = i; });

  const edgeLines = (edges || []).map((e) => {
    const si = nodeIndex[e.source_agent_id];
    const ti = nodeIndex[e.target_agent_id];
    if (si === undefined || ti === undefined) return '';
    const sc = coords[si];
    const tc = coords[ti];
    const color = (e.edge_type === 'challenge' || e.edge_type === 'contradict') ? '#f87171' : '#60a5fa';
    return `<line x1="${sc.x}" y1="${sc.y}" x2="${tc.x}" y2="${tc.y}" stroke="${color}" stroke-width="1.5" stroke-opacity="0.6" marker-end="url(#arrow)"/>`;
  }).join('');

  const nodeCircles = nodes.map((n, i) => {
    const { x, y } = coords[i];
    const label = n.length > 8 ? n.slice(0, 7) + '…' : n;
    return `
      <circle cx="${x}" cy="${y}" r="18" fill="var(--accent)" fill-opacity="0.15" stroke="var(--accent)" stroke-width="1.5"/>
      <text x="${x}" y="${y + 4}" text-anchor="middle" fill="var(--text-primary)" font-size="9" font-family="system-ui">${label}</text>
    `;
  }).join('');

  return `
    <div class="graph-visual-wrap" style="overflow:auto;text-align:center;margin-bottom:12px;">
      <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" style="max-width:100%;">
        <defs>
          <marker id="arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
            <path d="M0,0 L0,6 L6,3 z" fill="#60a5fa"/>
          </marker>
        </defs>
        ${edgeLines}
        ${nodeCircles}
      </svg>
    </div>
  `;
}

// ── Composite graph panel ─────────────────────────────────────────────────

/**
 * Embeddable panel that reads from state.graphData / state.graphLoading.
 * @param {string} sessionId
 */
function renderGraphViewPanel(sessionId) {
  const { state, escHtml, t } = getCtx();

  const data       = state.graphData    ?? null;
  const loading    = state.graphLoading ?? false;
  const error      = state.graphError   ?? null;
  const sessionMode = state.sessionHistory?.session?.mode ?? state.currentSession?.mode ?? null;

  if (loading) {
    return `
      <div class="card debate-card" style="margin:16px 0;">
        <div class="debate-card-title">${graphTitleRow(t, state)}</div>
        <div class="loading-state" style="padding:24px 0;">
          <span class="spinner"></span> ${t('graph.loading')}
        </div>
      </div>
    `;
  }

  if (error) {
    return `
      <div class="card debate-card" style="margin:16px 0;">
        <div class="debate-card-title">${graphTitleRow(t, state)}</div>
        <div style="padding:12px 0;color:var(--danger);font-size:13px;">
          ⚠️ ${t('graph.error')} <span style="color:var(--text-muted);font-size:11px;">${escHtml(error)}</span>
          <div style="margin-top:10px;">
            <button class="btn btn-secondary btn-sm" data-action="load-graph-data" data-session-id="${escHtml(sessionId)}">⟳ ${t('graph.load')}</button>
          </div>
        </div>
      </div>
    `;
  }

  if (!data) {
    const isChatMode = sessionMode === 'chat';
    return `
      <div class="card debate-card" style="margin:16px 0;">
        <div class="debate-card-title">${graphTitleRow(t, state)}</div>
        <div class="card-description">${t('panel.graph.desc')}</div>
        <div class="card-usage">${t('panel.graph.usage')}</div>
        <div style="padding:12px 0;color:var(--text-secondary);font-size:13px;">
          ${isChatMode
            ? `<p style="margin:0;font-style:italic;">ℹ️ ${t('graph.chatModeNotice')}</p>`
            : `<p style="margin:0 0 12px;">${t('graph.noData')}</p>
               <button class="btn btn-secondary btn-sm" data-action="load-graph-data" data-session-id="${escHtml(sessionId)}">${t('graph.load')}</button>`
          }
        </div>
      </div>
    `;
  }

  const nodes = data.nodes     ?? [];
  const edges = data.edges     ?? [];
  const args  = data.arguments ?? [];
  const pos   = data.positions ?? [];

  return `
    <div class="card debate-card graph-view-card" style="margin:16px 0;">
      <div class="debate-card-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span>${graphTitleRow(t, state)}</span>
        <button class="btn btn-secondary btn-sm" data-action="load-graph-data" data-session-id="${escHtml(sessionId)}"
          style="font-size:11px;padding:3px 8px;">⟳</button>
      </div>
      <div class="card-description">${t('panel.graph.desc')}</div>

      ${nodes.length > 1 ? renderVisualGraph(nodes, edges) : ''}

      ${renderEdgeList(edges)}
      ${renderArgMemory(args)}
      ${renderAgentPositions(pos)}
    </div>
  `;
}

/**
 * Standalone view — navigated to from session detail views.
 */
function renderGraphView() {
  const { state, escHtml, t } = getCtx();
  const session = state.currentSession;
  if (!session) {
    return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  }

  return `
    <div class="view-container">
      <div class="view-header" style="margin-bottom:24px;">
        <div>
          <h2 style="margin:0 0 4px;">📊 ${t('graph.title')}</h2>
          <div style="color:var(--text-secondary);font-size:13px;">${escHtml(session.title || '')}</div>
        </div>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderGraphViewPanel(session.id)}
    </div>
  `;
}

function registerGraphViewFeature() {
  window.DecisionArena.views['graph-view'] = renderGraphView;
  if (!window.DecisionArena.views.shared) window.DecisionArena.views.shared = {};
  window.DecisionArena.views.shared.renderGraphViewPanel = renderGraphViewPanel;
}

export { registerGraphViewFeature, renderGraphView, renderGraphViewPanel };

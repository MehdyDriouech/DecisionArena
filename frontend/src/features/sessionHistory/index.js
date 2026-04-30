/**
 * Session History feature – view registration.
 * Also exports renderTemplateCard (used by newSession) and renderActionPlanPanel.
 */

import { renderConfrontationAgentCard, renderDebateInsightsPanels, renderWeightedVotePanel, renderVerdictCard, renderSessionMemoryPanel, renderSessionContextDocPanel } from '../confrontation/index.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, formatDate, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, t };
}

/* ── Template card (shared with newSession) ── */

function renderTemplateCard(template) {
  const { escHtml, agentIcon, t } = getCtx();
  const modeIcons = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️', 'quick-decision': '⚡', 'stress-test': '🔥' };
  const icon = modeIcons[template.mode] || '📋';
  const modeLabels = {
    chat: t('mode.chat').replace('💬 ', ''),
    'decision-room': t('mode.decisionRoom').replace('🏛️ ', ''),
    confrontation: t('mode.confrontation').replace('⚔️ ', ''),
    'quick-decision': t('mode.quickDecision').replace('⚡ ', ''),
  };
  const modeLabel = modeLabels[template.mode] || template.mode;
  const agents = (template.selected_agents || []).slice(0, 4);
  return `
    <div class="template-card">
      <div class="template-card-header">
        <span class="template-card-icon">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="template-card-name">${escHtml(template.name)}</div>
          ${template.description ? `<div class="template-card-desc">${escHtml(template.description)}</div>` : ''}
          ${template.force_disagreement ? `<div class="template-card-flag">⚠ ${t('template.forceDisagreement')}</div>` : ''}
        </div>
      </div>
      <div class="template-card-meta">
        <span class="badge badge-default">${escHtml(modeLabel)}</span>
        <span style="font-size:12px;color:var(--text-muted);">${t('template.rounds')}: ${template.rounds}</span>
      </div>
      ${agents.length > 0 ? `<div class="template-card-agents">${agents.map((id) => `<span class="agent-badge" style="font-size:11px;">${agentIcon(id)}</span>`).join('')}</div>` : ''}
      <button class="btn btn-secondary btn-sm" style="margin-top:10px;width:100%;" data-action="use-template" data-template-id="${escHtml(template.id)}">
        ${t('template.use')}
      </button>
    </div>
  `;
}

/* ── Action Plan panel ── */

function renderActionPlanPanel(sessionId, actionPlan) {
  const { state, escHtml, t } = getCtx();
  if (!actionPlan) {
    return `
      <div class="card" style="padding:20px;margin-top:16px;">
        <div style="font-weight:600;font-size:14px;margin-bottom:4px;">🎯 ${t('actionPlan.title')}</div>
        <div class="card-description">${t('panel.actionPlan.desc')}</div>
        <div class="card-usage" style="margin-bottom:12px;">${t('panel.actionPlan.usage')}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">${t('actionPlan.notGenerated')}</div>
        <button class="btn btn-secondary btn-sm" data-action="generate-action-plan" data-session-id="${escHtml(sessionId)}" ${state.actionPlanLoading ? 'disabled' : ''}>
          ${state.actionPlanLoading ? '<span class="spinner"></span>' : '🎯'} ${t('actionPlan.generate')}
        </button>
        ${state.actionPlanStatus ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(state.actionPlanStatus)}</div>` : ''}
      </div>
    `;
  }
  const { summary, immediate_actions, short_term_actions, experiments, risks_to_monitor, owner_notes } = actionPlan;
  return `
    <div class="card" style="padding:20px;margin-top:16px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:2px;">🎯 ${t('actionPlan.title')}</div>
        <div class="card-description" style="margin-bottom:10px;">${t('panel.actionPlan.desc')}</div>
        <button class="btn btn-secondary btn-sm" data-action="generate-action-plan" data-session-id="${escHtml(sessionId)}" ${state.actionPlanLoading ? 'disabled' : ''}>
          🔄 ${t('actionPlan.regenerate')}
        </button>
      </div>
      ${summary ? `<div style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;padding:10px;background:var(--bg-secondary);border-radius:6px;">${escHtml(summary)}</div>` : ''}
      ${immediate_actions?.length > 0 ? `
        <div style="margin-bottom:14px;">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px;">⚡ ${t('actionPlan.immediateActions')}</div>
          ${immediate_actions.map((a) => `<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;padding:8px;background:var(--bg-secondary);border-radius:6px;"><span class="badge ${a.priority === 'high' ? 'badge-danger' : a.priority === 'medium' ? 'badge-warning' : 'badge-muted'}" style="flex-shrink:0;">${escHtml(a.priority||'')}</span><div><strong>${escHtml(a.title||'')}</strong><br><span style="font-size:12px;color:var(--text-muted);">${escHtml(a.description||'')}</span></div></div>`).join('')}
        </div>
      ` : ''}
      ${short_term_actions?.length > 0 ? `
        <div style="margin-bottom:14px;">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px;">📅 ${t('actionPlan.shortTermActions')}</div>
          ${short_term_actions.map((a) => `<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;padding:8px;background:var(--bg-secondary);border-radius:6px;"><span class="badge ${a.priority === 'high' ? 'badge-danger' : a.priority === 'medium' ? 'badge-warning' : 'badge-muted'}" style="flex-shrink:0;">${escHtml(a.priority||'')}</span><div><strong>${escHtml(a.title||'')}</strong><br><span style="font-size:12px;color:var(--text-muted);">${escHtml(a.description||'')}</span></div></div>`).join('')}
        </div>
      ` : ''}
      ${experiments?.length > 0 ? `
        <div style="margin-bottom:14px;">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px;">🧪 ${t('actionPlan.experiments')}</div>
          ${experiments.map((e) => `<div style="margin-bottom:8px;padding:8px;background:var(--bg-secondary);border-radius:6px;"><strong>${escHtml(e.title||'')}</strong><br><span style="font-size:12px;color:var(--text-muted);">${t('actionPlan.hypothesis')}: ${escHtml(e.hypothesis||'')}</span><br><span style="font-size:12px;color:var(--text-muted);">${t('actionPlan.successMetric')}: ${escHtml(e.success_metric||'')}</span></div>`).join('')}
        </div>
      ` : ''}
      ${risks_to_monitor?.length > 0 ? `
        <div style="margin-bottom:14px;">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px;">⚠️ ${t('actionPlan.risksToMonitor')}</div>
          ${risks_to_monitor.map((r) => `<div style="margin-bottom:8px;padding:8px;background:rgba(239,68,68,0.07);border-radius:6px;border:1px solid rgba(239,68,68,0.15);"><strong>${escHtml(r.risk||'')}</strong><br><span style="font-size:12px;color:var(--text-muted);">${t('actionPlan.mitigation')}: ${escHtml(r.mitigation||'')}</span></div>`).join('')}
        </div>
      ` : ''}
      <div style="margin-top:12px;">
        <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">${t('actionPlan.ownerNotes')}</label>
        <textarea class="textarea" id="ap-owner-notes" style="min-height:80px;" data-session-id="${escHtml(sessionId)}">${escHtml(owner_notes||'')}</textarea>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="save-action-plan-notes" data-session-id="${escHtml(sessionId)}">${t('actionPlan.saveNotes')}</button>
        <span id="ap-notes-status" style="font-size:12px;margin-left:8px;color:var(--text-muted);"></span>
      </div>
    </div>
  `;
}

/* ── Threshold panel (adjust decision_threshold after the fact) ── */

function renderThresholdPanel(session) {
  const { escHtml, t } = getCtx();
  const current = parseFloat(session.decision_threshold || 0.55);
  const pct     = Math.round(current * 100);
  return `
    <div class="card debate-card" data-ui="expert-only" style="margin:0 0 16px;" id="threshold-panel-${escHtml(session.id)}">
      <div class="debate-card-title" style="font-size:13px;">⚖️ ${t('vote.consensusThreshold')}</div>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px;">
        <div style="flex:1;min-width:200px;">
          <label for="hist-threshold-${escHtml(session.id)}" style="font-size:12px;color:var(--text-secondary);">
            ${t('vote.thresholdUsed')}: <strong id="hist-threshold-val-${escHtml(session.id)}">${pct}%</strong>
          </label>
          <input class="input" id="hist-threshold-${escHtml(session.id)}" type="range"
            min="0.50" max="0.80" step="0.01" value="${current}"
            data-session-id="${escHtml(session.id)}"
            data-action="preview-session-threshold"
            style="padding:6px 0;width:100%;">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:2px;">
            <span>50%</span><span>55%</span><span>65%</span><span>70%</span><span>80%</span>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${t('vote.consensusThresholdDesc')}</div>
          <div style="font-size:12px;color:var(--warning,#f59e0b);margin-top:6px;">ℹ️ ${t('vote.thresholdChangeHint')}</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
          <button class="btn btn-secondary btn-sm" data-action="save-decision-threshold"
            data-session-id="${escHtml(session.id)}" style="white-space:nowrap;">
            ${t('vote.updateThreshold')}
          </button>
          <button class="btn btn-secondary btn-sm" data-action="recompute-decision"
            data-session-id="${escHtml(session.id)}" style="white-space:nowrap;">
            ↺ ${t('vote.recompute')}
          </button>
          <span id="hist-threshold-status-${escHtml(session.id)}" style="font-size:12px;color:var(--text-muted);"></span>
        </div>
      </div>
    </div>
  `;
}

/* ── Decision summary (UX intelligence) ── */

function renderDecisionSummaryCard(sh) {
  const { escHtml, t } = getCtx();
  const ds = sh?.decisionSummary;
  if (!ds) {
    return `
    <div class="card decision-summary-card" style="margin-bottom:20px;padding:18px 20px;">
      <div style="font-weight:700;font-size:14px;margin-bottom:6px;">📋 ${t('decision.summary.title')}</div>
      <div style="font-size:13px;color:var(--text-muted);">${t('decision.unavailable')}</div>
    </div>`;
  }
  const tri = ds.decision || 'ITERATE';
  const badgeCls = tri === 'GO' ? 'badge-success' : tri === 'NO-GO' ? 'badge-danger' : 'badge-warning';
  const badgeText = tri === 'GO' ? t('decision.badge.go') : tri === 'NO-GO' ? t('decision.badge.noGo') : t('decision.badge.iterate');
  const pct = Math.max(0, Math.min(100, Math.round(Number(ds.confidence ?? 0) * 100)));
  const confKey = ds.confidence_label || 'medium';
  const confTr = t(`decision.confidenceLevel.${confKey}`);
  const confHuman = confTr !== `decision.confidenceLevel.${confKey}` ? confTr : confKey;
  const factors = Array.isArray(ds.key_factors) ? ds.key_factors : [];
  const risks = Array.isArray(ds.risks) ? ds.risks : [];
  const disag = Array.isArray(ds.disagreements) ? ds.disagreements : [];

  const listHtml = (title, items) => (items.length ? `<details style="margin-top:10px;"><summary style="cursor:pointer;font-weight:600;font-size:13px;">${escHtml(title)}</summary><ul style="margin:8px 0 0 18px;padding:0;font-size:13px;color:var(--text-secondary);line-height:1.5;">${items.map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul></details>` : '');

  const extra = factors.length || risks.length || disag.length
    ? `<div style="margin-top:14px;border-top:1px solid var(--border);padding-top:10px;">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">${t('decision.expandHint')}</div>
        ${listHtml(t('decision.keyFactors'), factors)}
        ${listHtml(t('decision.risks'), risks)}
        ${listHtml(t('decision.disagreements'), disag)}
      </div>`
    : '';

  return `
    <div class="card decision-summary-card" style="margin-bottom:20px;padding:18px 20px;background:var(--bg-secondary);">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div style="font-weight:700;font-size:15px;">📋 ${t('decision.summary.title')}</div>
        <span class="badge ${badgeCls}" style="font-size:12px;">${escHtml(badgeText)}</span>
      </div>
      <div style="margin-top:12px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">
          <span>${t('decision.confidence')} (${escHtml(confHuman)})</span>
          <span>${pct}%</span>
        </div>
        <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
          <div style="height:100%;width:${pct}%;background:var(--accent);border-radius:4px;"></div>
        </div>
      </div>
      <div style="margin-top:12px;font-size:13px;color:var(--text-secondary);line-height:1.55;white-space:pre-line;">${escHtml(ds.summary || '')}</div>
      ${extra}
    </div>
  `;
}

/* ── Main session history view ── */

function renderSessionHistory() {
  const { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, t } = getCtx();
  const data = state.sessionHistory;
  if (!data) {
    return `<div class="view-container"><div class="empty-state"><div class="empty-state-icon">📂</div><div class="empty-state-text">${t('sessions.noneSelected')}</div></div></div>`;
  }

  const session  = data.session || data;
  const messages = data.messages || [];
  const mode     = session.mode || 'chat';
  const modeIcons = { chat: '💬', 'decision-room': '🏛️', confrontation: '⚔️' };

  const routingMode = state.providerRoutingSettings?.routing_mode || null;
  const routingBadge = routingMode
    ? `<span class="badge badge-muted" data-ui="expert-only" style="font-size:11px;">${escHtml(t('routing.badge'))}: ${escHtml(routingMode)}</span>`
    : '';

  const grouped = {};
  const flat    = [];
  let hasRounds = false;
  messages.forEach((msg) => {
    if (msg.round !== null && msg.round !== undefined) {
      hasRounds = true;
      const r = msg.round;
      if (!grouped[r]) grouped[r] = [];
      grouped[r].push(msg);
    } else {
      flat.push(msg);
    }
  });

  const renderHistoryMessage = (msg) => {
    if (msg.role === 'user') {
      return `<div class="message-user" style="margin-bottom:12px;"><div>${escHtml(msg.content)}</div><div class="message-user-meta">${formatDate(msg.created_at)}</div></div>`;
    }
    const icon       = agentIcon(msg.agent_id);
    const name       = agentName(msg.agent_id);
    const targetBadge = msg.target_agent_id ? `<span class="badge badge-info" style="font-size:11px;">→ ${escHtml(msg.target_agent_id)}</span>` : '';
    const typeBadge  = msg.message_type === 'synthesis' ? `<span class="badge badge-success" style="font-size:11px;">✨ ${t('dr.synthesis')}</span>` : '';
    return `<div class="agent-card" style="margin-bottom:12px;"><div class="agent-card-header"><span class="agent-icon">${icon}</span><div style="flex:1;min-width:0;"><div class="agent-name">${escHtml(name)}</div></div>${targetBadge}${typeBadge}</div><div class="agent-content md-content">${renderMarkdown(msg.content)}</div><div class="agent-card-footer" style="font-size:11px;color:var(--text-muted);">${msg.provider_id ? `<span>${escHtml(msg.provider_id)}</span>` : ''}${msg.model ? `<span>${escHtml(msg.model)}</span>` : ''}${msg.created_at ? `<span style="margin-left:auto;">${formatDate(msg.created_at)}</span>` : ''}</div></div>`;
  };

  const bodyHtml = (() => {
    if (!hasRounds || mode === 'chat') {
      return flat.map(renderHistoryMessage).join('') ||
        messages.map(renderHistoryMessage).join('') ||
        `<div class="empty-state"><div class="empty-state-text">${t('sessions.noMessages')}</div></div>`;
    }
    const roundNums = Object.keys(grouped).map(Number).sort((a, b) => a - b);
    return roundNums.map((r) => {
      const msgs    = grouped[r] || [];
      if (msgs.length === 0) return '';
      const isSynth = msgs.some((m) => m.message_type === 'synthesis' || m.phase === 'synthesis');
      const label   = isSynth ? `✨ ${t('confrontation.phaseFinal')}` : mode === 'confrontation' ? `${t('confrontation.round')} ${r}` : `${t('dr.round')} ${r}`;
      return `<div class="phase-section" style="margin-bottom:24px;"><div class="phase-header ${isSynth ? 'synthesis' : 'blue'}" style="margin-bottom:12px;"><span>${label}</span></div><div class="phase-agents-grid">${msgs.map((m) => renderConfrontationAgentCard(m, isSynth)).join('')}</div></div>`;
    }).join('');
  })();

  const agents = session.selected_agents || [];

  return `
    <div style="max-width:960px;margin:0 auto;padding:24px 20px;">
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:24px;">
        <button class="btn btn-secondary btn-sm" data-nav="sessions">← ${t('nav.back')}</button>
      </div>

      ${mode !== 'chat' ? renderDecisionSummaryCard(data) : ''}

      <div class="card" style="margin-bottom:24px;padding:20px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <span style="font-size:28px;">${modeIcons[mode] || '💬'}</span>
          <div style="flex:1;min-width:0;">
            <div style="font-size:20px;font-weight:700;color:var(--text-primary);">${escHtml(session.title)}</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">
              ${formatDate(session.created_at)} · ${session.mode}
              ${session.status ? ` · <span class="badge ${session.status === 'completed' ? 'badge-success' : 'badge-muted'}">${session.status}</span>` : ''}
              ${routingBadge ? ` · ${routingBadge}` : ''}
            </div>
          </div>
        </div>
        ${agents.length > 0 ? `<div class="session-agents" style="margin-top:12px;">${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}</div>` : ''}
        ${session.initial_prompt || session.idea ? `<div style="margin-top:12px;padding:12px;background:var(--bg-secondary);border-radius:6px;font-size:13px;color:var(--text-secondary);">${escHtml(session.initial_prompt || session.idea)}</div>` : ''}
        <div class="export-actions" style="margin-top:16px;">
          ${(window.DecisionArena.views.shared.renderExportButtons || (() => ''))(session.id)}
        </div>
      </div>

      ${renderSessionMemoryPanel(session)}
      ${renderSessionContextDocPanel(session)}

      ${mode !== 'chat' ? renderDebateInsightsPanels({
        arguments: data.arguments || [],
        positions: data.positions || [],
        interaction_edges: data.interaction_edges || [],
        weighted_analysis: data.weighted_analysis || {},
        dominance_indicator: data.dominance_indicator || '',
      }) : ''}

      ${mode !== 'chat' ? renderWeightedVotePanel({ votes: data.votes || [], automatic_decision: data.automatic_decision || null }, session.id) : ''}

      ${mode !== 'chat' ? renderThresholdPanel(session) : ''}

      <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:16px;text-transform:uppercase;letter-spacing:.05em;">
        ${t('sessions.history')} (${messages.length} ${t('sessions.messages')})
      </div>

      ${bodyHtml}

      ${data.verdict ? renderVerdictCard(data.verdict) : ''}

      ${renderActionPlanPanel(session.id, data.actionPlan || null)}

      ${mode !== 'chat' ? `
      <div class="session-history-analytics-panels">
        ${(window.DecisionArena.views.shared.renderGraphViewPanel || (() => ''))(session.id)}
        ${(window.DecisionArena.views.shared.renderArgumentHeatmapPanel || (() => ''))(session.id)}
        ${(window.DecisionArena.views.shared.renderDebateAuditPanel || (() => ''))(session.id)}
        ${(window.DecisionArena.views.shared.renderDebateReplayPanel || (() => ''))(session.id)}
      </div>
      ` : ''}

      <div class="card" style="padding:16px;margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-secondary btn-sm" data-action="open-rerun-modal" data-session-id="${escHtml(session.id)}">
          🔁 ${t('rerun.title')}
        </button>
        <button class="btn btn-secondary btn-sm" data-action="toggle-compare-session" data-session-id="${escHtml(session.id)}">
          ⚖️ ${(state.compareSelectedIds||[]).includes(session.id) ? t('sessions.removeFromCompare') : t('sessions.addToCompare')}
        </button>
      </div>
    </div>
  `;
}

function registerSessionHistoryFeature() {
  window.DecisionArena.views['session-history'] = renderSessionHistory;
  window.DecisionArena.views.shared.renderTemplateCard    = renderTemplateCard;
  window.DecisionArena.views.shared.renderActionPlanPanel = renderActionPlanPanel;
}

export { registerSessionHistoryFeature, renderSessionHistory, renderTemplateCard, renderActionPlanPanel };

/**
 * Session History feature – view registration.
 * Also exports renderTemplateCard (used by newSession) and renderActionPlanPanel.
 */

import { renderConfrontationAgentCard, renderDebateInsightsPanels, renderWeightedVotePanel, renderDecisionReliabilityCard, renderVerdictCard, renderSessionMemoryPanel, renderSessionContextDocPanel } from '../confrontation/index.js';

function renderDecisionBrief(brief) {
  if (!brief) return '';
  const t = (key) => window.i18n?.t(key) ?? key;
  const escHtml = window.DecisionArena.utils.escHtml;
  const colorMap = {
    GO_CONFIDENT: '#15803d', GO_FRAGILE: '#854d0e',
    NO_GO_CONFIDENT: '#991b1b', NO_GO_FRAGILE: '#92400e',
    ITERATE_CONFIDENT: '#92400e', ITERATE_FRAGILE: '#78350f',
    NO_CONSENSUS: '#7f1d1d', NO_CONSENSUS_FRAGILE: '#7f1d1d',
    INSUFFICIENT_CONTEXT: '#374151',
  };
  const outcome = `${brief.decision}_${brief.reliability}`;
  const bgColor = colorMap[outcome] || colorMap[brief.decision] || '#374151';
  const whyHtml  = (brief.why || []).map(w => `<span>${escHtml(w)}</span>`).join(' ');
  const riskHtml = (brief.risks || []).map(r => `<span>${escHtml(r)}</span>`).join(' ');
  const warning  = brief.primary_warning
    ? `<div class="brief-warning">⚠ ${escHtml(brief.primary_warning)}</div>` : '';
  return `
<div class="decision-brief-card">
  <div class="brief-header" style="background:${bgColor}">
    <span class="brief-decision">${escHtml(brief.decision || '')}</span>
    <span class="brief-meta">${escHtml(brief.reliability || '')} · ${escHtml(brief.confidence || '')} · ${t('brief.score')}: ${brief.quality_score}/100</span>
  </div>
  <div class="brief-body">
    ${whyHtml ? `<p><strong>${t('brief.why')}:</strong> ${whyHtml}</p>` : ''}
    ${riskHtml ? `<p><strong>${t('brief.risks')}:</strong> ${riskHtml}</p>` : ''}
    ${brief.next_step ? `<p><strong>${t('brief.next_step')}:</strong> ${escHtml(brief.next_step)}</p>` : ''}
    ${warning}
  </div>
</div>`;
}

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
  const aria = `${template.name} — ${t('template.use')}`;
  return `
    <div class="template-card"
         data-action="use-template"
         data-template-id="${escHtml(template.id)}"
         role="button"
         tabindex="0"
         aria-label="${escHtml(aria)}">
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
      <div class="template-card-hint" aria-hidden="true">▶ ${t('template.use')}</div>
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

/* ═══════════════════════════════════════════════════════════════════════
   Feature 1 — Persona Score Panel
════════════════════════════════════════════════════════════════════════ */

function renderPersonaScorePanel(sessionId, scores) {
  const { escHtml, t } = getCtx();
  const loaded = Array.isArray(scores);
  const badgeClass = (d) => d === 'active' ? 'badge-success' : d === 'moderate' ? 'badge-warning' : 'badge-muted';

  const tooltipHtml = `<span class="info-tooltip" data-tooltip="${t('persona.score.tooltip').replace(/"/g, '&quot;')}" aria-label="${t('persona.score.tooltip').replace(/"/g, '&quot;')}">?</span>`;

  if (!loaded) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="persona-score-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">🎭 ${t('persona.score.title')} ${tooltipHtml}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">
          <button class="btn btn-secondary btn-sm" data-action="load-persona-scores" data-session-id="${escHtml(sessionId)}">
            📊 Charger les scores
          </button>
        </div>
      </div>`;
  }

  if (scores.length === 0) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="persona-score-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">🎭 ${t('persona.score.title')} ${tooltipHtml}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">Aucun message d'agent trouvé.</div>
      </div>`;
  }

  const rows = scores.map((s) => {
    const pct = Math.round((s.influence_score || 0) * 100);
    const dom = s.dominance || 'passive';
    return `
      <div style="padding:12px;background:var(--bg-secondary);border-radius:8px;margin-bottom:10px;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
          <span style="font-weight:600;font-size:13px;">${escHtml(s.persona_name || s.agent_id)}</span>
          <span class="badge ${badgeClass(dom)}">${t('persona.score.' + dom)}</span>
          <span style="font-size:11px;color:var(--text-muted);">${s.message_count} ${t('persona.score.messages')} · ${s.citation_count} ${t('persona.score.citations')}</span>
          <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="open-persona-editor" data-agent-id="${escHtml(s.agent_id)}" style="margin-left:auto;font-size:11px;padding:3px 8px;">
            ✏️ ${t('persona.score.label.improve')}
          </button>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:12px;color:var(--text-secondary);white-space:nowrap;">${t('persona.score.influence')}</span>
          <div style="flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
            <div style="height:100%;width:${pct}%;background:var(--accent);border-radius:4px;transition:width .3s;"></div>
          </div>
          <span style="font-size:12px;font-weight:600;width:36px;text-align:right;">${pct}%</span>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:6px;font-style:italic;">${escHtml(s.label || '')}</div>
      </div>`;
  }).join('');

  return `
    <div class="card debate-card" style="margin-top:16px;" id="persona-score-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <div class="debate-card-title" style="margin:0;">🎭 ${t('persona.score.title')}</div>
        ${tooltipHtml}
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="load-persona-scores" data-session-id="${escHtml(sessionId)}">↺</button>
      </div>
      ${rows}
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════
   Feature 2 — Confidence Timeline Panel (SVG chart)
════════════════════════════════════════════════════════════════════════ */

function renderConfidenceTimelinePanel(sessionId, timeline) {
  const { escHtml, t } = getCtx();
  const tooltipHtml = `<span class="info-tooltip" data-tooltip="${t('timeline.tooltip').replace(/"/g, '&quot;')}" aria-label="${t('timeline.tooltip').replace(/"/g, '&quot;')}">?</span>`;

  if (!timeline) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="timeline-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">📈 ${t('timeline.title')} ${tooltipHtml}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="load-confidence-timeline" data-session-id="${escHtml(sessionId)}">
          📈 Charger la timeline
        </button>
      </div>`;
  }

  const rounds = timeline.rounds || [];
  if (rounds.length === 0) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="timeline-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">📈 ${t('timeline.title')} ${tooltipHtml}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">${t('timeline.no_data')}</div>
      </div>`;
  }

  // SVG chart — 400×120 viewport
  const W = 400, H = 100, padX = 30, padY = 12;
  const innerW = W - padX * 2, innerH = H - padY * 2;
  const n = rounds.length;
  const xStep = n <= 1 ? innerW : innerW / (n - 1);

  const pts = rounds.map((r, i) => ({
    x: padX + (n <= 1 ? innerW / 2 : i * xStep),
    y: padY + innerH - (r.confidence * innerH),
    r: r,
  }));

  const polyline = pts.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
  const area = `${padX},${(padY + innerH).toFixed(1)} ` + pts.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ') + ` ${pts[pts.length - 1].x.toFixed(1)},${(padY + innerH).toFixed(1)}`;

  const circles = pts.map((p) => {
    const col = p.r.consensus_forming ? 'var(--accent)' : '#94a3b8';
    const title = `${t('timeline.round')} ${p.r.round}: ${Math.round(p.r.confidence * 100)}% — ${p.r.dominant_position}`;
    return `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="5" fill="${col}" stroke="white" stroke-width="1.5"><title>${escHtml(title)}</title></circle>`;
  }).join('');

  const consensusAnnotation = (() => {
    if (!timeline.consensus_reached_at_round) return '';
    const idx = rounds.findIndex((r) => r.round === timeline.consensus_reached_at_round);
    if (idx < 0) return '';
    const p = pts[idx];
    return `<line x1="${p.x.toFixed(1)}" y1="${padY}" x2="${p.x.toFixed(1)}" y2="${(padY + innerH).toFixed(1)}" stroke="var(--accent)" stroke-width="1" stroke-dasharray="3,3" opacity="0.6"/>
      <text x="${(p.x + 4).toFixed(1)}" y="${(padY + 9).toFixed(1)}" font-size="9" fill="var(--accent)">${escHtml(t('timeline.consensus_at'))} ${timeline.consensus_reached_at_round}</text>`;
  })();

  const xLabels = pts.map((p) => `<text x="${p.x.toFixed(1)}" y="${(H - 1).toFixed(1)}" text-anchor="middle" font-size="9" fill="var(--text-muted)">${t('timeline.round')} ${p.r.round}</text>`).join('');
  const yLabels = ['0%', '50%', '100%'].map((lbl, i) => {
    const y = padY + innerH - (i * 0.5 * innerH);
    return `<text x="${(padX - 3).toFixed(1)}" y="${y.toFixed(1)}" text-anchor="end" font-size="9" fill="var(--text-muted)">${lbl}</text>`;
  }).join('');

  const lateBadge = timeline.late_consensus ? `<span class="badge badge-warning" style="margin-left:8px;font-size:11px;">⚠ ${t('timeline.late_consensus')}</span>` : '';

  return `
    <div class="card debate-card" style="margin-top:16px;" id="timeline-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <div class="debate-card-title" style="margin:0;">📈 ${t('timeline.title')}</div>
        ${tooltipHtml}
        ${lateBadge}
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="load-confidence-timeline" data-session-id="${escHtml(sessionId)}">↺</button>
      </div>
      <svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:${W}px;display:block;overflow:visible;" aria-label="${t('timeline.title')}">
        <polygon points="${area}" fill="var(--accent)" opacity="0.1"/>
        <polyline points="${polyline}" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linejoin="round"/>
        ${consensusAnnotation}
        ${circles}
        ${xLabels}
        ${yLabels}
      </svg>
      <div style="font-size:11px;color:var(--text-muted);margin-top:6px;display:flex;gap:16px;flex-wrap:wrap;" data-ui="expert-only">
        ${rounds.map((r) => `<span>${t('timeline.round')} ${r.round}: <strong>${Math.round(r.confidence * 100)}%</strong> ${r.dominant_position}${r.consensus_forming ? ' ✓' : ''}</span>`).join('')}
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════
   Feature 5 — Post-mortem Banner + Form
════════════════════════════════════════════════════════════════════════ */

function renderPostmortemBanner(session, postmortem) {
  const { escHtml, t } = getCtx();
  const sid = session.id;

  // If postmortem exists, show it compactly
  if (postmortem) {
    const outcomeKey = `postmortem.outcome.badge.${postmortem.outcome}`;
    const badgeCls = postmortem.outcome === 'correct' ? 'badge-success' : postmortem.outcome === 'incorrect' ? 'badge-danger' : 'badge-warning';
    const pct = Math.round((postmortem.confidence_in_retrospect || 0.5) * 100);
    return `
      <div class="card" style="padding:14px 18px;margin-top:16px;background:var(--bg-secondary);">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <span style="font-weight:600;font-size:13px;">🔮 ${t('postmortem.title')}</span>
          <span class="badge ${badgeCls}">${t(outcomeKey)}</span>
          <span style="font-size:12px;color:var(--text-muted);">${t('postmortem.confidence')}: ${pct}%</span>
          <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="open-postmortem-form" data-session-id="${escHtml(sid)}">✏️</button>
        </div>
        ${postmortem.notes ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:8px;font-style:italic;">${escHtml(postmortem.notes)}</div>` : ''}
      </div>`;
  }

  // Show banner if session is older than 30 days
  const createdAt = new Date(session.created_at || 0);
  const ageMs = Date.now() - createdAt.getTime();
  const thirtyDaysMs = 30 * 24 * 3600 * 1000;
  if (ageMs < thirtyDaysMs) return '';

  return `
    <div class="card" style="padding:14px 18px;margin-top:16px;border:1px solid var(--accent);background:rgba(99,102,241,0.04);">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:18px;">🔮</span>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;font-size:13px;">${t('postmortem.banner')}</div>
        </div>
        <button class="btn btn-primary btn-sm" data-action="open-postmortem-form" data-session-id="${escHtml(sid)}">
          ${t('postmortem.bannerCta')}
        </button>
      </div>
    </div>`;
}

function renderPostmortemForm(sessionId, existing) {
  const { escHtml, t } = getCtx();
  const pm = existing || {};
  const opts = ['correct', 'partial', 'incorrect'];
  const pct = Math.round(((pm.confidence_in_retrospect ?? 0.5)) * 100);
  return `
    <div class="card" style="padding:18px;margin-top:8px;" id="postmortem-form-${escHtml(sessionId)}">
      <div style="font-weight:700;font-size:14px;margin-bottom:14px;">🔮 ${t('postmortem.title')}</div>
      <div class="form-group">
        <label>${t('postmortem.outcome.correct')} / ${t('postmortem.outcome.partial')} / ${t('postmortem.outcome.incorrect')}</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          ${opts.map((o) => `
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
              <input type="radio" name="pm-outcome-${escHtml(sessionId)}" value="${o}" ${pm.outcome === o ? 'checked' : ''} data-action="select-postmortem-outcome" data-session-id="${escHtml(sessionId)}" style="accent-color:var(--accent);">
              ${t('postmortem.outcome.' + o)}
            </label>`).join('')}
        </div>
      </div>
      <div class="form-group">
        <label for="pm-confidence-${escHtml(sessionId)}">${t('postmortem.confidence')}: <strong id="pm-conf-val-${escHtml(sessionId)}">${pct}%</strong></label>
        <input class="input" id="pm-confidence-${escHtml(sessionId)}" type="range" min="0" max="1" step="0.05" value="${pm.confidence_in_retrospect ?? 0.5}" data-action="preview-postmortem-confidence" data-session-id="${escHtml(sessionId)}" style="padding:6px 0;width:100%;">
      </div>
      <div class="form-group">
        <label for="pm-notes-${escHtml(sessionId)}">${t('postmortem.notes')}</label>
        <textarea class="textarea" id="pm-notes-${escHtml(sessionId)}" placeholder="${escHtml(t('postmortem.notesPlaceholder'))}" style="min-height:70px;">${escHtml(pm.notes || '')}</textarea>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-primary btn-sm" data-action="submit-postmortem" data-session-id="${escHtml(sessionId)}">${t('postmortem.submit')}</button>
        <button class="btn btn-secondary btn-sm" data-action="close-postmortem-form" data-session-id="${escHtml(sessionId)}">✕</button>
        <span id="pm-status-${escHtml(sessionId)}" style="font-size:12px;color:var(--text-muted);"></span>
      </div>
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════
   Feature 6 — Bias Detection Panel
════════════════════════════════════════════════════════════════════════ */

function renderBiasDetectionPanel(sessionId, biasReport) {
  const { escHtml, t } = getCtx();
  const tooltipHtml = `<span class="info-tooltip" data-tooltip="${t('bias.tooltip').replace(/"/g, '&quot;')}" aria-label="${t('bias.tooltip').replace(/"/g, '&quot;')}">?</span>`;

  if (!biasReport) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="bias-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">🧠 ${t('bias.title')} ${tooltipHtml}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="load-bias-report" data-session-id="${escHtml(sessionId)}">
          🔍 Analyser les biais
        </button>
      </div>`;
  }

  if (biasReport.clean || (biasReport.detected || []).length === 0) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="bias-panel-${escHtml(sessionId)}">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
          <div class="debate-card-title" style="margin:0;">🧠 ${t('bias.title')}</div>
          ${tooltipHtml}
          <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="load-bias-report" data-session-id="${escHtml(sessionId)}">↺</button>
        </div>
        <div style="margin-top:8px;"><span class="badge badge-success">✓ ${t('bias.clean')}</span></div>
      </div>`;
  }

  const detected = biasReport.detected || [];
  const badgeCls = (sev) => sev === 'high' ? 'badge-danger' : sev === 'medium' ? 'badge-warning' : 'badge-muted';
  const biasNameKey = (b) => {
    const map = { groupthink: 'bias.groupthink', anchoring: 'bias.anchoring', confirmation_bias: 'bias.confirmation', availability_bias: 'bias.availability', authority_bias: 'bias.authority' };
    return map[b] || b;
  };

  const rows = detected.map((d) => `
    <div style="padding:10px;background:rgba(239,68,68,0.05);border-radius:8px;border:1px solid rgba(239,68,68,0.15);margin-bottom:10px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <strong style="font-size:13px;">${escHtml(t(biasNameKey(d.bias)))}</strong>
        <span class="badge ${badgeCls(d.severity)}">${escHtml(t('bias.severity.' + d.severity))}</span>
      </div>
      <details data-ui="expert-only">
        <summary style="font-size:12px;color:var(--text-muted);cursor:pointer;">${t('bias.evidence')}</summary>
        <div style="font-size:12px;color:var(--text-secondary);margin-top:6px;padding:8px;background:var(--bg-secondary);border-radius:4px;">${escHtml(d.evidence)}</div>
      </details>
      <div style="font-size:12px;color:var(--accent);margin-top:6px;padding:8px;background:rgba(99,102,241,0.06);border-radius:4px;">💡 ${escHtml(d.recommendation)}</div>
    </div>`).join('');

  return `
    <div class="card debate-card" style="margin-top:16px;" id="bias-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <div class="debate-card-title" style="margin:0;">🧠 ${t('bias.title')}</div>
        ${tooltipHtml}
        <span class="badge badge-danger" style="font-size:11px;">${detected.length} ${t('bias.detected')}</span>
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="load-bias-report" data-session-id="${escHtml(sessionId)}">↺</button>
      </div>
      ${rows}
    </div>`;
}

/* ═══════════════════════════════════════════════════════════════════════
   Social dynamics (alliances / conflicts)
════════════════════════════════════════════════════════════════════════ */

function renderSocialDynamicsPanel(sessionId, payload) {
  const { escHtml, t } = getCtx();
  const tip = `<span class="info-tooltip" data-tooltip="${escHtml(t('socialDynamics.tooltip'))}" aria-label="${escHtml(t('socialDynamics.tooltip'))}">?</span>`;

  if (payload == null) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="social-dynamics-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">🤝 ${t('socialDynamics.title')} ${tip}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="load-social-dynamics" data-session-id="${escHtml(sessionId)}">
          📊 ${t('socialDynamics.load')}
        </button>
      </div>`;
  }

  const h = payload.highlights || {};
  const alliances = Array.isArray(h.alliances) ? h.alliances : [];
  const conflicts = Array.isArray(h.conflicts) ? h.conflicts : [];
  const ch = h.most_challenged || null;
  const sp = h.most_supported || null;
  const ul = (items) => (items.length
    ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:13px;color:var(--text-secondary);line-height:1.45;">${items.map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>`
    : `<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${escHtml(t('socialDynamics.none'))}</div>`);

  return `
    <div class="card debate-card" style="margin-top:16px;" id="social-dynamics-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
        <div class="debate-card-title" style="margin:0;">🤝 ${t('socialDynamics.title')}</div>
        ${tip}
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="load-social-dynamics" data-session-id="${escHtml(sessionId)}">↺</button>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" class="social-dyn-grid">
        <div>
          <div style="font-weight:600;font-size:12px;margin-bottom:4px;">${escHtml(t('socialDynamics.alliances'))}</div>
          ${ul(alliances)}
        </div>
        <div>
          <div style="font-weight:600;font-size:12px;margin-bottom:4px;">${escHtml(t('socialDynamics.conflicts'))}</div>
          ${ul(conflicts)}
        </div>
      </div>
      <div style="margin-top:12px;font-size:13px;color:var(--text-secondary);line-height:1.5;">
        <div><strong>${escHtml(t('socialDynamics.challengedMost'))}:</strong> ${ch ? escHtml(ch) : escHtml(t('socialDynamics.none'))}</div>
        <div style="margin-top:4px;"><strong>${escHtml(t('socialDynamics.supportedMost'))}:</strong> ${sp ? escHtml(sp) : escHtml(t('socialDynamics.none'))}</div>
      </div>
    </div>`;
}

/* ── Evidence panel ── */

function renderEvidencePanel(sessionId, report) {
  const { escHtml, t } = getCtx();
  const tip = `<span class="info-tooltip" data-tooltip="${escHtml(t('evidence.tooltip'))}" aria-label="${escHtml(t('evidence.tooltip'))}">?</span>`;

  if (report == null) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="evidence-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">🔎 ${t('evidence.title')} ${tip}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="load-evidence" data-session-id="${escHtml(sessionId)}">
          📋 ${t('evidence.load')}
        </button>
      </div>`;
  }

  const score         = typeof report.evidence_score === 'number' ? Math.round(report.evidence_score * 100) : '–';
  const unsupported   = report.unsupported_claims_count ?? 0;
  const contradicted  = report.contradicted_claims_count ?? 0;
  const impact        = report.decision_impact ?? 'low';
  const rec           = report.recommendation ?? '';
  const unknowns      = Array.isArray(report.critical_unknowns) ? report.critical_unknowns : [];

  const impactColor = impact === 'high' ? 'var(--danger)' : impact === 'medium' ? 'var(--warning)' : 'var(--success)';
  const scoreColor  = score < 40 ? 'var(--danger)' : score < 70 ? 'var(--warning)' : 'var(--success)';

  const unknownsHtml = unknowns.length
    ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);">${
        unknowns.map((u) => `<li>${escHtml(String(u))}</li>`).join('')
      }</ul>`
    : '';

  return `
    <div class="card debate-card" style="margin-top:16px;" id="evidence-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <div class="debate-card-title" style="margin:0;">🔎 ${t('evidence.title')}</div>
        ${tip}
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="recompute-evidence" data-session-id="${escHtml(sessionId)}"
          title="${escHtml(t('evidence.recompute'))}">↺ ${t('evidence.recompute')}</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('evidence.score'))}</div>
          <div class="evidence-metric-value" style="color:${scoreColor};">${score}%</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('evidence.unsupported'))}</div>
          <div class="evidence-metric-value" style="color:${unsupported > 0 ? 'var(--warning)' : 'var(--text-secondary)'};">${unsupported}</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('evidence.contradicted'))}</div>
          <div class="evidence-metric-value" style="color:${contradicted > 0 ? 'var(--danger)' : 'var(--text-secondary)'};">${contradicted}</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('evidence.impact'))}</div>
          <div class="evidence-metric-value" style="color:${impactColor};text-transform:uppercase;font-size:12px;">${escHtml(impact)}</div>
        </div>
      </div>
      ${unknowns.length ? `<div style="margin-bottom:10px;"><div style="font-weight:600;font-size:12px;margin-bottom:2px;">${escHtml(t('evidence.criticalUnknowns'))}</div>${unknownsHtml}</div>` : ''}
      ${rec ? `<div style="font-size:13px;color:var(--text-secondary);line-height:1.45;border-top:1px solid var(--border);padding-top:10px;">${escHtml(rec)}</div>` : ''}
    </div>`;
}

/* ── Risk & Reversibility panel ── */

function renderLlmUsedPanel(messages) {
  const { escHtml, t } = getCtx();

  // Collect unique agents with provider info
  const agentMap = {};
  (messages || []).forEach((msg) => {
    if (!msg.agent_id || msg.role !== 'assistant') return;
    const aid = msg.agent_id;
    if (!agentMap[aid]) {
      agentMap[aid] = {
        provider_id:           msg.provider_id           || null,
        provider_name:         msg.provider_name         || null,
        model:                 msg.model                 || null,
        requested_provider_id: msg.requested_provider_id || null,
        requested_model:       msg.requested_model       || null,
        fallback_used:         msg.provider_fallback_used == 1 || msg.provider_fallback_used === true,
        fallback_reason:       msg.provider_fallback_reason || null,
      };
    }
  });

  const agents = Object.keys(agentMap);
  if (agents.length === 0) return '';

  const hasAnyProviderInfo = agents.some((a) => agentMap[a].provider_id || agentMap[a].model);
  if (!hasAnyProviderInfo) return '';

  const rows = agents.map((aid) => {
    const info = agentMap[aid];
    const usedLabel   = [info.model, info.provider_name || info.provider_id].filter(Boolean).join(' via ') || t('message.llm.routingGlobal');
    const reqLabel    = info.requested_provider_id ? [info.requested_model, info.requested_provider_id].filter(Boolean).join(' / ') : '—';
    const fallbackCell= info.fallback_used
      ? `<span style="color:#f59e0b;font-size:11px;" title="${escHtml(info.fallback_reason || '')}">⚠ ${t('message.llm.fallback')}</span>`
      : `<span style="color:#22c55e;font-size:11px;">✓</span>`;
    return `
      <tr>
        <td style="padding:4px 8px;font-size:12px;font-weight:600;">${escHtml(aid)}</td>
        <td style="padding:4px 8px;font-size:12px;color:var(--text-muted);">${escHtml(reqLabel)}</td>
        <td style="padding:4px 8px;font-size:12px;">${escHtml(usedLabel)}</td>
        <td style="padding:4px 8px;text-align:center;">${fallbackCell}</td>
      </tr>`;
  }).join('');

  return `
    <div class="card session-llm-used-card debate-card" style="margin-top:16px;">
      <div class="debate-card-title">🤖 ${t('session.llmUsed.title')}</div>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">${t('session.llmUsed.desc')}</div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);">
            <th style="padding:4px 8px;font-size:11px;text-align:left;color:var(--text-muted);text-transform:uppercase;">${t('session.llmUsed.agent')}</th>
            <th style="padding:4px 8px;font-size:11px;text-align:left;color:var(--text-muted);text-transform:uppercase;">${t('session.llmUsed.requestedProvider')}</th>
            <th style="padding:4px 8px;font-size:11px;text-align:left;color:var(--text-muted);text-transform:uppercase;">${t('session.llmUsed.usedProvider')}</th>
            <th style="padding:4px 8px;font-size:11px;text-align:center;color:var(--text-muted);text-transform:uppercase;">${t('session.llmUsed.fallback')}</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function renderRiskPanel(sessionId, profile, thresholdInfo) {
  const { escHtml, t } = getCtx();
  const tip = `<span class="info-tooltip" data-tooltip="${escHtml(t('risk.tooltip'))}" aria-label="${escHtml(t('risk.tooltip'))}">?</span>`;

  if (profile == null) {
    return `
      <div class="card debate-card" style="margin-top:16px;" id="risk-panel-${escHtml(sessionId)}">
        <div class="debate-card-title">⚠️ ${t('risk.title')} ${tip}</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:8px;" data-action="load-risk-profile" data-session-id="${escHtml(sessionId)}">
          🔍 ${t('risk.load')}
        </button>
      </div>`;
  }

  const riskLevel    = profile.risk_level ?? 'medium';
  const reversibility= profile.reversibility ?? 'moderate';
  const categories   = Array.isArray(profile.risk_categories) ? profile.risk_categories : [];
  const process      = profile.required_process ?? 'standard';
  const recs         = Array.isArray(profile.recommendations) ? profile.recommendations : [];
  const recThr       = typeof profile.recommended_threshold === 'number'
    ? Math.round(profile.recommended_threshold * 100) + '%'
    : '–';

  const riskColors = {
    low: 'var(--success)',
    medium: 'var(--warning)',
    high: 'var(--danger)',
    critical: '#b91c1c',
  };
  const revColors = {
    easy: 'var(--success)',
    moderate: 'var(--warning)',
    hard: 'var(--danger)',
    irreversible: '#b91c1c',
  };

  const riskColor = riskColors[riskLevel] || 'var(--text-secondary)';
  const revColor  = revColors[reversibility] || 'var(--text-secondary)';

  // Threshold comparison block
  let thrHtml = '';
  const ti = thresholdInfo || {};
  if (ti.configured_threshold != null && ti.risk_adjusted_threshold != null) {
    const cfg = Math.round(ti.configured_threshold * 100);
    const adj = Math.round(ti.risk_adjusted_threshold * 100);
    const adjColor = ti.was_adjusted ? 'var(--warning)' : 'var(--text-secondary)';
    thrHtml = `
      <div style="display:flex;gap:16px;margin-top:12px;flex-wrap:wrap;">
        <div class="evidence-metric-cell" style="flex:1;min-width:120px;">
          <div class="evidence-metric-label">${escHtml(t('risk.configuredThreshold'))}</div>
          <div class="evidence-metric-value" style="color:var(--text-secondary);font-size:18px;">${cfg}%</div>
        </div>
        <div class="evidence-metric-cell" style="flex:1;min-width:120px;">
          <div class="evidence-metric-label">${escHtml(t('risk.adjustedThreshold'))}</div>
          <div class="evidence-metric-value" style="color:${adjColor};font-size:18px;">${adj}%</div>
        </div>
      </div>`;
  }

  const recsHtml = recs.length
    ? `<ul style="margin:8px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);line-height:1.5;">${
        recs.slice(0,3).map((r) => `<li>${escHtml(String(r))}</li>`).join('')
      }</ul>`
    : '';

  return `
    <div class="card debate-card" style="margin-top:16px;" id="risk-panel-${escHtml(sessionId)}">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <div class="debate-card-title" style="margin:0;">⚠️ ${t('risk.title')}</div>
        ${tip}
        <button class="btn btn-secondary btn-sm" style="margin-left:auto;font-size:11px;" data-action="recompute-risk-profile" data-session-id="${escHtml(sessionId)}"
          title="${escHtml(t('risk.recompute'))}">↺ ${t('risk.recompute')}</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:8px;">
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('risk.level'))}</div>
          <div class="evidence-metric-value" style="color:${riskColor};font-size:16px;text-transform:uppercase;">${escHtml(riskLevel)}</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('risk.reversibility'))}</div>
          <div class="evidence-metric-value" style="color:${revColor};font-size:14px;text-transform:capitalize;">${escHtml(reversibility)}</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('risk.process'))}</div>
          <div class="evidence-metric-value" style="font-size:14px;color:var(--text-secondary);text-transform:capitalize;">${escHtml(process)}</div>
        </div>
        <div class="evidence-metric-cell">
          <div class="evidence-metric-label">${escHtml(t('risk.recommendedThreshold'))}</div>
          <div class="evidence-metric-value" style="font-size:16px;color:var(--text-secondary);">${escHtml(recThr)}</div>
        </div>
      </div>
      ${categories.length ? `<div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">${escHtml(t('risk.categories'))}: ${categories.map((c)=>escHtml(c)).join(' · ')}</div>` : ''}
      ${thrHtml}
      ${recsHtml ? `<div style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px;">${recsHtml}</div>` : ''}
    </div>`;
}

/* ── Collapsible panel wrapper ── */

function renderCollapsiblePanel(key, title, innerHtml, state) {
  if (!innerHtml || innerHtml.trim() === '') return '';
  const collapsed  = state?.collapsedPanels instanceof Set
    ? state.collapsedPanels.has(key)
    : false;
  const { t } = getCtx();
  return `
    <div class="collapsible-panel" data-panel-key="${key}">
      <div class="collapsible-panel-header" data-action="toggle-panel-collapse" data-panel-key="${key}" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 0;border-bottom:1px solid var(--border);margin-bottom:${collapsed ? '0' : '8px'};">
        <span style="font-size:13px;font-weight:600;color:var(--text-secondary);flex:1;">${title}</span>
        <span style="font-size:11px;color:var(--text-muted);">${collapsed ? t('session.section.expand') : t('session.section.collapse')}</span>
        <span style="color:var(--text-muted);font-size:12px;">${collapsed ? '▶' : '▼'}</span>
      </div>
      ${collapsed ? '' : `<div class="collapsible-panel-body">${innerHtml}</div>`}
    </div>`;
}

/* ── Main session history view ── */

/* ── Jury adversarial card (session history, read-only) ── */

function renderSessionJuryAdversarialCard(ja) {
  if (!ja || !ja.enabled) return '';
  const { t, escHtml } = getCtx();

  const score = ja.debate_quality_score ?? null;
  const scoreColor = score === null ? 'var(--text-muted)'
    : score >= 70 ? 'var(--color-success,#10b981)'
    : score >= 40 ? 'var(--color-warning,#f59e0b)'
    : 'var(--color-error,#ef4444)';

  const warnings = Array.isArray(ja.warnings) ? ja.warnings : [];
  const warningLabels = {
    weak_debate_quality:     t('jury.adversarial.warningWeak'),
    insufficient_challenge:  t('jury.adversarial.warningParallel'),
    parallel_answers_detected: t('jury.adversarial.warningParallel'),
    false_consensus_risk_high: '⚠️ Faux consensus élevé',
    no_consensus_reached:    t('jury.adversarial.warningNoConsensus'),
    synthesis_constrained_by_vote: t('jury.adversarial.synthesisConstrained'),
  };

  const statItem = (label, value) =>
    `<div class="stat-item"><div class="stat-label">${label}</div><div class="stat-value">${value}</div></div>`;

  return `
    <div class="card adversarial-card" style="margin-bottom:16px;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
      <div style="padding:12px 16px;background:var(--surface-2,#f8f9fa);border-bottom:1px solid var(--border-color);
                  display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:16px;">⚔️</span>
        <strong>${t('jury.adversarial.qualityTitle')}</strong>
        ${score !== null ? `<span style="margin-left:auto;font-size:22px;font-weight:700;color:${scoreColor};">${score}<span style="font-size:13px;font-weight:400;color:var(--text-muted);">/100</span></span>` : ''}
      </div>
      <div style="padding:12px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">
        ${statItem(t('jury.adversarial.challengeCount'),     ja.challenge_count ?? 0)}
        ${statItem(t('jury.adversarial.mostChallenged'),     ja.most_challenged_agent ? escHtml(ja.most_challenged_agent) : '—')}
        ${statItem(t('jury.adversarial.positionChanges'),    ja.position_changes ?? 0)}
        ${statItem(t('jury.adversarial.minorityDetected'),   ja.minority_report_present ? '✅' : '❌')}
        ${statItem(t('jury.adversarial.complianceRetries'),  ja.compliance_retries ?? 0)}
        ${ja.planned_rounds  != null ? statItem(t('jury.adversarial.plannedRounds'),  ja.planned_rounds)  : ''}
        ${ja.executed_rounds != null ? statItem(t('jury.adversarial.executedRounds'), ja.executed_rounds) : ''}
      </div>
      ${warnings.length > 0 ? `
        <div style="padding:0 16px 12px;display:flex;flex-wrap:wrap;gap:6px;">
          ${warnings.map((w) => `<span class="badge badge-warning" style="font-size:11px;">${escHtml(warningLabels[w] ?? w)}</span>`).join('')}
        </div>` : ''}
    </div>
  `;
}

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
    const isDA      = msg.agent_id === 'devil_advocate' || msg.message_type === 'devil_advocate';
    const daClass   = isDA ? ' devil-advocate-card' : '';
    const daBadge   = isDA ? `<span class="badge" style="background:rgba(220,38,38,0.12);color:#dc2626;font-size:10px;">😈 ${t('devil.advocate.badge')}</span>` : '';
    const providerLabel = msg.provider_name || msg.provider_id || null;
    const modelLabel    = msg.model || null;
    const hasFallback   = msg.provider_fallback_used == 1 || msg.provider_fallback_used === true;
    const provBadge = (providerLabel || modelLabel) ? `
      <span class="message-llm-meta provider-badge" data-ui="expert-only">
        ${modelLabel ? escHtml(modelLabel) : ''}${providerLabel ? ` via ${escHtml(providerLabel)}` : ''}
        ${hasFallback ? `<span class="message-llm-fallback" title="${escHtml(msg.provider_fallback_reason || '')}">⚠ ${t('message.llm.fallback')}</span>` : ''}
      </span>` : '';
    return `<div class="agent-card${daClass}" style="margin-bottom:12px;"><div class="agent-card-header"><span class="agent-icon">${icon}</span><div style="flex:1;min-width:0;"><div class="agent-name">${escHtml(name)}</div></div>${daBadge}${targetBadge}${typeBadge}</div><div class="agent-content md-content">${renderMarkdown(msg.content)}</div><div class="agent-card-footer" style="font-size:11px;color:var(--text-muted);">${provBadge}${msg.created_at ? `<span style="margin-left:auto;">${formatDate(msg.created_at)}</span>` : ''}</div></div>`;
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

  // Deliberation Intelligence v2 state (loaded on demand)
  const personaScores    = state.personaScores?.[session.id];
  const confidenceTimeline = state.confidenceTimeline?.[session.id];
  const biasReport       = state.biasReport?.[session.id];
  const socialDynamics   = state.socialDynamics?.[session.id];
  const evidenceReport   = state.evidenceReport?.[session.id] ?? (data.evidence_report || null);
  const riskProfile      = state.riskProfile?.[session.id]   ?? (data.risk_profile || null);
  const riskThresholdInfo = data.risk_threshold_info || null;
  const postmortem       = state.postmortem?.[session.id];
  const showPostmortemForm = state.postmortemFormOpen?.[session.id];

  // Devil's advocate interventions count
  const daMessages = messages.filter((m) => m.message_type === 'devil_advocate' || m.agent_id === 'devil_advocate');

  // Postmortem badge for session card
  const pmBadge = (() => {
    if (!postmortem?.outcome) return '';
    const cls = postmortem.outcome === 'correct' ? 'badge-success' : postmortem.outcome === 'incorrect' ? 'badge-danger' : 'badge-warning';
    return `<span class="badge ${cls}" style="font-size:11px;">${t('postmortem.outcome.badge.' + postmortem.outcome)}</span>`;
  })();

  return `
    <div style="max-width:960px;margin:0 auto;padding:24px 20px;">
      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:24px;">
        <button class="btn btn-secondary btn-sm" data-nav="sessions">← ${t('nav.back')}</button>
      </div>

      ${mode !== 'chat' ? renderDecisionSummaryCard(data) : ''}

      ${mode !== 'chat' ? renderConfidenceTimelinePanel(session.id, confidenceTimeline || null) : ''}

      <div class="card" style="margin-bottom:24px;padding:20px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <span style="font-size:28px;">${modeIcons[mode] || '💬'}</span>
          <div style="flex:1;min-width:0;">
            <div style="font-size:20px;font-weight:700;color:var(--text-primary);">${escHtml(session.title)} ${pmBadge}</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">
              ${formatDate(session.created_at)} · ${session.mode}
              ${session.status ? ` · <span class="badge ${session.status === 'completed' ? 'badge-success' : 'badge-muted'}">${session.status}</span>` : ''}
              ${routingBadge ? ` · ${routingBadge}` : ''}
              ${daMessages.length > 0 ? ` · <span class="badge" style="background:rgba(239,68,68,0.12);color:#dc2626;font-size:11px;">😈 ${daMessages.length} ${t('devil.advocate.count')}</span>` : ''}
            </div>
          </div>
        </div>
        ${agents.length > 0 ? `<div class="session-agents" style="margin-top:12px;">${agents.map((id) => `<span class="agent-badge">${agentIcon(id)} ${escHtml(agentName(id))}</span>`).join('')}</div>` : ''}
        ${session.initial_prompt || session.idea ? `<div id="session-objective-preview" style="margin-top:12px;padding:12px;background:var(--bg-secondary);border-radius:6px;font-size:13px;color:var(--text-secondary);">${escHtml(session.initial_prompt || session.idea)}</div>` : ''}
        <div class="export-actions" style="margin-top:16px;">
          ${(window.DecisionArena.views.shared.renderExportButtons || (() => ''))(session.id)}
        </div>
      </div>

      ${renderSessionMemoryPanel(session)}
      ${renderSessionContextDocPanel(session)}

      ${mode === 'jury' && data.jury_adversarial ? renderSessionJuryAdversarialCard(data.jury_adversarial) : ''}

      ${mode !== 'chat' ? renderDebateInsightsPanels({
        arguments: data.arguments || [],
        positions: data.positions || [],
        interaction_edges: data.interaction_edges || [],
        weighted_analysis: data.weighted_analysis || {},
        dominance_indicator: data.dominance_indicator || '',
      }) : ''}

      ${mode !== 'chat' ? renderDecisionBrief(data.decision_brief || null) : ''}
      ${mode !== 'chat' ? renderWeightedVotePanel({
        votes: data.votes || [],
        automatic_decision: data.automatic_decision || null,
        raw_decision: data.raw_decision || null,
        adjusted_decision: data.adjusted_decision || null,
        decision_reliability_summary: data.decision_reliability_summary || null,
        context_clarification: data.context_clarification || null,
      }, session.id) : ''}
      ${mode !== 'chat' ? renderDecisionReliabilityCard({
        context_quality: data.context_quality || null,
        reliability_cap: data.reliability_cap || null,
        raw_decision: data.raw_decision || data.automatic_decision || null,
        adjusted_decision: data.adjusted_decision || null,
        false_consensus_risk: data.false_consensus_risk || null,
        false_consensus: data.false_consensus || null,
        reliability_warnings: data.reliability_warnings || [],
        decision_reliability_summary: data.decision_reliability_summary || null,
      }) : ''}

      ${mode !== 'chat' ? renderThresholdPanel(session) : ''}

      <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:16px;text-transform:uppercase;letter-spacing:.05em;">
        ${t('sessions.history')} (${messages.length} ${t('sessions.messages')})
      </div>

      ${bodyHtml}

      ${data.verdict ? renderVerdictCard(data.verdict) : ''}

      ${renderActionPlanPanel(session.id, data.actionPlan || null)}

      ${mode !== 'chat' ? `
      <div class="session-history-analytics-panels">
        ${renderCollapsiblePanel('debate-audit', t('session.section.debateAudit'), (window.DecisionArena.views.shared.renderDebateAuditPanel || (() => ''))(session.id), state)}
        ${renderCollapsiblePanel('persona-scores', t('session.section.personaScores'), renderPersonaScorePanel(session.id, personaScores || null), state)}
        ${renderCollapsiblePanel('social-dynamics', t('session.section.socialDynamics'), renderSocialDynamicsPanel(session.id, socialDynamics ?? null), state)}
        ${renderCollapsiblePanel('llm-used', t('session.section.llmUsed'), renderLlmUsedPanel(messages), state)}
        ${renderCollapsiblePanel('evidence', t('session.section.evidence'), renderEvidencePanel(session.id, evidenceReport), state)}
        ${renderCollapsiblePanel('risk', t('session.section.risk'), renderRiskPanel(session.id, riskProfile, riskThresholdInfo), state)}
        ${(window.DecisionArena.views.shared.renderGraphViewPanel || (() => ''))(session.id)}
        ${(window.DecisionArena.views.shared.renderArgumentHeatmapPanel || (() => ''))(session.id)}
        ${renderCollapsiblePanel('bias-detection', t('session.section.biasDetection'), renderBiasDetectionPanel(session.id, biasReport || null), state)}
        ${(window.DecisionArena.views.shared.renderDebateReplayPanel || (() => ''))(session.id)}
      </div>
      ` : ''}

      ${renderPostmortemBanner(session, postmortem || null)}
      ${showPostmortemForm ? renderPostmortemForm(session.id, postmortem || null) : ''}

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
  window.DecisionArena.views.shared.renderTemplateCard       = renderTemplateCard;
  window.DecisionArena.views.shared.renderActionPlanPanel    = renderActionPlanPanel;
  window.DecisionArena.views.shared.renderPersonaScorePanel  = renderPersonaScorePanel;
  window.DecisionArena.views.shared.renderConfidenceTimeline = renderConfidenceTimelinePanel;
  window.DecisionArena.views.shared.renderBiasDetectionPanel = renderBiasDetectionPanel;
  window.DecisionArena.views.shared.renderSocialDynamicsPanel = renderSocialDynamicsPanel;
  window.DecisionArena.views.shared.renderPostmortemBanner   = renderPostmortemBanner;
  window.DecisionArena.views.shared.renderPostmortemForm     = renderPostmortemForm;
}

export {
  registerSessionHistoryFeature,
  renderSessionHistory,
  renderTemplateCard,
  renderActionPlanPanel,
  renderPersonaScorePanel,
  renderSocialDynamicsPanel,
  renderLlmUsedPanel,
  renderEvidencePanel,
  renderRiskPanel,
  renderConfidenceTimelinePanel,
  renderBiasDetectionPanel,
  renderPostmortemBanner,
  renderPostmortemForm,
};

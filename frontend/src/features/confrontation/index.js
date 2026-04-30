/**
 * Confrontation feature – view registration.
 * Also exports shared sub-renders used by decisionRoom, quickDecision,
 * sessionHistory, and stressTest.
 */

import { renderTooltip, renderPanelRecommendBadge } from '../../ui/components.js';
import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, formatDate, agentIcon: _ai, agentName: _an, agentTitleText: _at } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon      = (id) => _ai(state.personas, id);
  const agentName      = (id) => _an(state.personas, id);
  const agentTitleText = (id) => _at(state.personas, id);
  return { state, escHtml, renderMarkdown, formatDate, agentIcon, agentName, agentTitleText, t };
}

function panelHighlightsFromState(state) {
  return state.sessionHistory?.panelHighlights || state.auditData?.highlights || [];
}

/* ── Shared sub-renderers ── */

function renderConfrontationAgentCard(msg, isSynthesis) {
  const { escHtml, renderMarkdown, formatDate, agentIcon, agentName, agentTitleText, t } = getCtx();
  const icon     = agentIcon(msg.agent_id);
  const name     = agentName(msg.agent_id);
  const titleTxt = agentTitleText(msg.agent_id);
  const targetBadge = msg.target_agent_id
    ? `<span class="badge badge-info" style="margin-left:auto;font-size:11px;">→ ${escHtml(msg.target_agent_id)}</span>`
    : '';
  return `
    <div class="agent-card ${isSynthesis ? 'synthesis-full' : ''}">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div style="flex:1;min-width:0;">
          <div class="agent-name">${escHtml(name)}</div>
          ${titleTxt ? `<div class="agent-title">${escHtml(titleTxt)}</div>` : ''}
        </div>
        ${isSynthesis ? `<span class="badge badge-success" style="margin-left:auto;">${t('dr.synthesis')}</span>` : targetBadge}
      </div>
      <div class="agent-content md-content">${renderMarkdown(msg.content)}</div>
      <div class="agent-card-footer">
        ${msg.provider_id ? `<span>${escHtml(msg.provider_id)}</span>` : ''}
        ${msg.model ? `<span>${escHtml(msg.model)}</span>` : ''}
        ${msg.created_at ? `<span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${formatDate(msg.created_at)}</span>` : ''}
      </div>
    </div>
  `;
}

function renderArgumentMemoryCard(argumentsList) {
  const { escHtml, t } = getCtx();
  if (!argumentsList.length) return '';
  const grouped = argumentsList.reduce((acc, arg) => {
    const key = arg.argument_type || 'claim';
    if (!acc[key]) acc[key] = [];
    acc[key].push(arg);
    return acc;
  }, {});
  const challengeCountById = {};
  const reuseCountByText = {};
  argumentsList.forEach((arg) => {
    if (arg.target_argument_id) challengeCountById[arg.target_argument_id] = (challengeCountById[arg.target_argument_id] || 0) + 1;
    const sig = (arg.argument_text || '').trim().toLowerCase();
    if (sig) reuseCountByText[sig] = (reuseCountByText[sig] || 0) + 1;
  });
  const typeOrder = ['claim', 'risk', 'assumption', 'counter_argument', 'question'];
  const typeLabel = (type) => ({ claim: t('debate.argumentTypeClaim'), risk: t('debate.argumentTypeRisk'), assumption: t('debate.argumentTypeAssumption'), counter_argument: t('debate.argumentTypeCounter'), question: t('debate.argumentTypeQuestion') }[type] || type);
  const sections = typeOrder.filter((type) => grouped[type]?.length).map((type) => {
    const items = grouped[type].slice(0, 8).map((arg) => {
      const sig = (arg.argument_text || '').trim().toLowerCase();
      const reused = Math.max(0, (reuseCountByText[sig] || 1) - 1);
      const challenged = challengeCountById[arg.id] || 0;
      const edgeCls = challenged > 0 ? 'badge-danger' : 'badge-info';
      return `<div class="debate-argument-item"><div class="debate-argument-text">${escHtml(arg.argument_text || '')}</div><div class="debate-argument-meta"><span class="badge badge-muted">${escHtml(arg.agent_id || 'agent')}</span><span class="badge ${edgeCls}">${t('debate.challenged')}: ${challenged}</span><span class="badge badge-neutral">${t('debate.reused')}: ${reused}</span></div></div>`;
    }).join('');
    return `<div class="debate-group"><div class="debate-group-title">${typeLabel(type)}</div>${items}</div>`;
  }).join('');
  return `<div class="card debate-card"><div class="debate-card-title">🧠 ${t('debate.argumentMemory')}</div><div class="card-description">${t('panel.argMemory.desc')}</div>${sections}</div>`;
}

function renderWeightedPositionsCard(positions, weighted, dominance) {
  const { escHtml, agentName, t } = getCtx();
  if (!positions.length && !dominance) return '';
  const latestByAgent = {};
  positions.forEach((pos) => {
    const aid = pos.agent_id || 'agent';
    if (!latestByAgent[aid] || Number(pos.round || 0) >= Number(latestByAgent[aid].round || 0)) latestByAgent[aid] = pos;
  });
  const rows = Object.values(latestByAgent).sort((a, b) => Number(b.weight_score || 0) - Number(a.weight_score || 0)).map((pos) => `<tr class="${Number(pos.weight_score || 0) >= 7 ? 'debate-strong-row' : ''}"><td>${escHtml(agentName(pos.agent_id))}</td><td>${escHtml(pos.stance || 'needs-more-info')}</td><td>${Number(pos.confidence || 0)}</td><td>${Number(pos.impact || 0)}</td><td>${Number(pos.domain_weight || 0)}</td><td><strong>${Number(pos.weight_score || 0).toFixed(2)}</strong></td></tr>`).join('');
  const strongest = (weighted.strongest_arguments || []).slice(0, 3).map((arg) => `<li>${escHtml(arg.argument || '')}</li>`).join('');
  const conflicts = (weighted.conflicting_high_weight_opinions || []).slice(0, 3).map((c) => `<li>${escHtml(c.agent_a)} (${escHtml(c.stance_a)}) ↔ ${escHtml(c.agent_b)} (${escHtml(c.stance_b)})</li>`).join('');
  return `<div class="card debate-card"><div class="debate-card-title">⚖️ ${t('debate.weightedPositions')}</div><div class="card-description">${t('panel.positions.desc')}</div>${dominance ? `<div class="debate-dominance">${escHtml(dominance)}</div>` : ''}<div style="overflow:auto;"><table class="debate-table"><thead><tr><th>${t('debate.agent')}</th><th>${t('debate.stance')}</th><th>${t('debate.confidence')}</th><th>${t('debate.impact')}</th><th>${t('debate.domainWeight')}</th><th>${t('debate.weightScore')}</th></tr></thead><tbody>${rows}</tbody></table></div><div class="debate-subtitle">${t('debate.strongestArguments')}</div><ul class="debate-list">${strongest || `<li>${t('debate.none')}</li>`}</ul><div class="debate-subtitle">${t('debate.conflictingHighWeight')}</div><ul class="debate-list">${conflicts || `<li>${t('debate.none')}</li>`}</ul></div>`;
}

function renderInteractionGraphCard(edges) {
  const { escHtml, t } = getCtx();
  if (!edges.length) return '';
  const rows = edges.slice().sort((a, b) => Number(b.weight || 0) - Number(a.weight || 0)).slice(0, 10).map((edge) => `<div class="debate-edge-row ${Number(edge.weight || 0) >= 7 ? 'debate-edge-strong' : ''}"><span>${escHtml(edge.source_agent_id)} → ${escHtml(edge.target_agent_id)}</span><span class="badge ${edge.edge_type === 'challenge' ? 'badge-danger' : 'badge-info'}">${escHtml(edge.edge_type || 'neutral')}</span><span class="badge badge-muted">w=${Number(edge.weight || 1)}</span></div>`).join('');
  return `<div class="card debate-card"><div class="debate-card-title">🕸️ ${t('debate.interactionGraph')}</div><div class="card-description">${t('panel.interactionGraph.desc')}</div>${rows}</div>`;
}

function renderDebateInsightsPanels(source) {
  const argumentsList = source?.arguments || [];
  const positions = source?.positions || [];
  const edges = source?.interaction_edges || [];
  const weighted = source?.weighted_analysis || {};
  const dominance = source?.dominance_indicator || '';
  if (!argumentsList.length && !positions.length && !edges.length) return '';
  return `<div class="debate-insights-grid">${renderArgumentMemoryCard(argumentsList)}${renderWeightedPositionsCard(positions, weighted, dominance)}${renderInteractionGraphCard(edges)}</div>`;
}

function renderVoteExplanationInline(sessionId) {
  const { state, escHtml, t } = getCtx();
  const data    = state.voteExplanation        ?? null;
  const loading = state.voteExplanationLoading ?? false;
  const err     = state.voteExplanationError   ?? null;
  if (loading) {
    return `<div class="loading-state" style="padding:10px 0;"><span class="spinner"></span> ${t('vote.explanation')}…</div>`;
  }
  if (err) {
    return `<div style="padding:8px 0;color:var(--danger);font-size:13px;">⚠️ ${t('vote.explanationError')} <span style="color:var(--text-muted);font-size:11px;">${escHtml(err)}</span></div>`;
  }
  if (!data) return '';
  const pct      = Math.round(Number(data.score || 0) * 100);
  const overrides = data.overrides ?? [];
  const voteRows = (data.votes ?? []).map((v) => `
    <tr>
      <td>${escHtml(v.agent_id)}</td>
      <td>${escHtml(v.vote)}</td>
      <td>${Number(v.weight_score).toFixed(2)}</td>
      <td style="font-size:12px;color:var(--text-secondary);">${escHtml(v.rationale || '')}</td>
    </tr>
  `).join('');
  return `
    <div class="audit-summary" style="margin-top:10px;">
      <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">
        💡 ${t('vote.explanation')} — ${t('vote.finalDecision')}: ${escHtml(data.decision || '')}
        (${pct}%, ${t('vote.confidenceLevel')}: ${escHtml(data.confidence_level || '')})
      </div>
      ${overrides.length > 0 ? `<div class="audit-warnings" style="margin-bottom:8px;">${overrides.map((o) => `<div class="audit-warning-item">⚠️ ${escHtml(o.replace(/_/g, ' '))}</div>`).join('')}</div>` : ''}
      ${data.explanation ? `<div style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">${escHtml(data.explanation)}</div>` : ''}
      ${voteRows ? `<div style="overflow:auto;"><table class="debate-table"><thead><tr><th>${t('debate.agent')}</th><th>${t('vote.vote')}</th><th>${t('debate.weightScore')}</th><th>${t('vote.rationale')}</th></tr></thead><tbody>${voteRows}</tbody></table></div>` : ''}
      <button class="btn btn-secondary btn-sm" style="font-size:11px;padding:3px 8px;margin-top:6px;"
        data-action="load-vote-explanation" data-session-id="${escHtml(sessionId)}">⟳</button>
    </div>
  `;
}

function renderWeightedVotePanel(source, sessionId) {
  const { state, escHtml, agentName, t } = getCtx();
  const votes = source?.votes || [];
  const decision = source?.automatic_decision || null;
  if (!votes.length && !decision) return '';
  const voteRows = votes.map((v) => `<tr><td>${escHtml(agentName(v.agent_id))}</td><td>${escHtml(v.vote || '')}</td><td>${Number(v.confidence || 0)}</td><td>${Number(v.impact || 0)}</td><td>${Number(v.domain_weight || 0)}</td><td><strong>${Number(v.weight_score || 0).toFixed(2)}</strong></td><td>${escHtml(v.rationale || '')}</td></tr>`).join('');
  let distributionHtml = '';
  const summary = decision?.vote_summary && typeof decision.vote_summary === 'object' ? decision.vote_summary : null;
  if (summary?.decision_scores) distributionHtml = Object.entries(summary.decision_scores).map(([label, score]) => `<li>${escHtml(label)}: ${Math.round(Number(score) * 100)}%</li>`).join('');
  const scorePercent = decision ? Math.round(Number(decision.decision_score || 0) * 100) : 0;
  const thresholdPercent = decision ? Math.round(Number(decision.threshold_used || 0.55) * 100) : 55;
  const label = decision?.decision_label ? decision.decision_label : t('vote.noDecision');
  const confidence = decision?.confidence_level ? decision.confidence_level : '-';

  // Show confirmation badge only when the last recompute targets THIS session
  const lastRecompute = state.lastRecomputeThreshold ?? null;
  const recomputeForThis = lastRecompute?.sessionId === sessionId ? lastRecompute.threshold : null;
  const recomputeBadge = recomputeForThis !== null
    ? `<div style="margin-top:6px;font-size:12px;color:var(--accent);display:flex;align-items:center;gap:6px;">
        ↺ ${t('vote.recomputedWith')} <strong>${Math.round(Number(recomputeForThis) * 100)}%</strong>
       </div>`
    : '';

  const actionsHtml = sessionId ? `
    <div data-ui="expert-only">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
      <button class="btn btn-secondary btn-sm" data-action="recompute-decision" data-session-id="${escHtml(sessionId)}">${t('vote.recompute')}</button>
      <button class="btn btn-secondary btn-sm" data-action="load-vote-explanation" data-session-id="${escHtml(sessionId)}">💡 ${t('vote.explanationWhy')}</button>
    </div>
    ${recomputeBadge}
    ${renderVoteExplanationInline(sessionId)}
    </div>
  ` : '';
  const hl = panelHighlightsFromState(state);
  const titleRow = `🗳️ ${t('vote.weightedFinalVote')} ${renderTooltip(t('tooltip.votes'))} ${renderPanelRecommendBadge('votes', hl, t)}`;
  return `<div class="card debate-card" style="margin:16px 0 24px;"><div class="debate-card-title" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;">${titleRow}</div><div class="card-description">${t('panel.votes.desc')}</div><div class="debate-dominance" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;"><span><strong>${t('vote.finalDecision')}:</strong> ${escHtml(label)}</span><span>${t('vote.decisionScore')}: ${scorePercent}%</span><span>${t('vote.confidenceLevel')}: ${escHtml(confidence)}</span><span>${t('vote.thresholdUsed')}: <strong>${thresholdPercent}%</strong></span></div><div style="overflow:auto;"><table class="debate-table"><thead><tr><th>${t('debate.agent')}</th><th>${t('vote.vote')}</th><th>${t('debate.confidence')}</th><th>${t('debate.impact')}</th><th>${t('debate.domainWeight')}</th><th>${t('debate.weightScore')}</th><th>${t('vote.rationale')}</th></tr></thead><tbody>${voteRows || `<tr><td colspan="7">${t('vote.noVotes')}</td></tr>`}</tbody></table></div>${distributionHtml ? `<div class="debate-subtitle">${t('vote.distribution')}</div><ul class="debate-list">${distributionHtml}</ul>` : ''}${actionsHtml}</div>`;
}

function renderVerdictCard(verdict) {
  const { escHtml, t } = getCtx();
  if (!verdict) return '';
  const labelConfig = { 'go': { icon: '✅', cls: 'badge-success', label: t('verdict.go') }, 'no-go': { icon: '❌', cls: 'badge-danger', label: t('verdict.noGo') }, 'risky': { icon: '⚠️', cls: 'badge-warning', label: t('verdict.risky') }, 'needs-more-info': { icon: '❓', cls: 'badge-info', label: t('verdict.needsMoreInfo') }, 'reduce-scope': { icon: '✂️', cls: 'badge-muted', label: t('verdict.reduceScope') } };
  const cfg = labelConfig[verdict.verdict_label] || { icon: '📊', cls: 'badge-default', label: verdict.verdict_label };
  const scoreBar = (label, score, inverted = false) => {
    if (score === null || score === undefined) return '';
    const pct = (score / 10) * 100;
    const color = inverted ? (score <= 3 ? '#34d399' : score <= 6 ? '#fbbf24' : '#f87171') : (score >= 7 ? '#34d399' : score >= 4 ? '#fbbf24' : '#f87171');
    return `<div style="margin-bottom:8px;"><div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:3px;"><span>${label}</span><span>${score}/10</span></div><div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden;"><div style="height:100%;width:${pct}%;background:${color};border-radius:3px;"></div></div></div>`;
  };
  return `<div class="verdict-card"><div class="verdict-card-header"><span style="font-size:28px;">${cfg.icon}</span><div style="flex:1;"><div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;">${t('verdict.title')}</div><span class="badge ${cfg.cls}" style="font-size:14px;padding:4px 12px;margin-top:4px;">${cfg.label}</span></div></div>${verdict.verdict_summary ? `<div style="margin:12px 0;font-size:14px;color:var(--text-secondary);line-height:1.6;">${escHtml(verdict.verdict_summary)}</div>` : ''}<div style="margin:16px 0;">${scoreBar(t('verdict.feasibility'), verdict.feasibility_score)}${scoreBar(t('verdict.productValue'), verdict.product_value_score)}${scoreBar(t('verdict.ux'), verdict.ux_score)}${scoreBar(t('verdict.risk'), verdict.risk_score, true)}${scoreBar(t('verdict.confidence'), verdict.confidence_score)}</div>${verdict.recommended_action ? `<div style="padding:12px;background:var(--bg-secondary);border-radius:6px;border-left:3px solid var(--accent);"><div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;">${t('verdict.recommendedAction')}</div><div style="font-size:13px;color:var(--text-primary);">${escHtml(verdict.recommended_action)}</div></div>` : ''}</div>`;
}

function renderSessionMemoryPanel(session) {
  const { escHtml, t } = getCtx();
  return `<div class="card" style="margin-bottom:24px;padding:20px;" id="session-memory-panel"><div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:14px;text-transform:uppercase;letter-spacing:.05em;">${t('memory.title')}</div><div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;"><input type="checkbox" id="mem-favorite" ${session.is_favorite ? 'checked' : ''} style="width:16px;height:16px;accent-color:var(--accent);">⭐ ${t('memory.favorite')}</label><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;"><input type="checkbox" id="mem-reference" ${session.is_reference ? 'checked' : ''} style="width:16px;height:16px;accent-color:var(--accent);">📌 ${t('memory.reference')}</label></div><div class="form-group"><label>${t('memory.decisionTaken')}</label><textarea class="textarea" id="mem-decision" rows="2" placeholder="${t('memory.decisionTakenPlaceholder')}">${escHtml(session.decision_taken || '')}</textarea></div><div class="form-group"><label>${t('memory.userLearnings')}</label><textarea class="textarea" id="mem-learnings" rows="2" placeholder="${t('memory.userLearningsPlaceholder')}">${escHtml(session.user_learnings || '')}</textarea></div><div class="form-group"><label>${t('memory.followUpNotes')}</label><textarea class="textarea" id="mem-followup" rows="2" placeholder="${t('memory.followUpNotesPlaceholder')}">${escHtml(session.follow_up_notes || '')}</textarea></div><button class="btn btn-primary btn-sm" data-action="save-memory" data-session-id="${escHtml(session.id)}">💾 ${t('memory.save')}</button><span id="mem-save-status" style="margin-left:10px;font-size:13px;"></span></div>`;
}

function renderSessionContextDocPanel(session) {
  const { state, escHtml, t } = getCtx();
  const doc   = state.currentContextDoc;
  const open  = state.ctxDocPanelOpen;
  const isLarge = doc && doc.character_count > 30000;
  const renderInlineContextDocEditor = (sid) => (window.DecisionArena.views.shared.renderInlineContextDocEditor || (() => ''))(sid);
  return `<div class="card ctx-doc-history-panel" style="margin-bottom:24px;padding:0;"><div class="ctx-doc-history-header" data-action="toggle-ctx-doc-panel" style="padding:16px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;"><span style="font-weight:600;font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;">${t('contextDoc.sectionTitle')} ${doc ? `<span class="badge badge-success" style="margin-left:8px;font-size:11px;text-transform:none;">${t('contextDoc.attachedBadge')}</span>` : `<span class="badge" style="margin-left:8px;font-size:11px;text-transform:none;background:var(--bg-tertiary);color:var(--text-muted);">${t('contextDoc.noneBadge')}</span>`}</span><span style="font-size:12px;color:var(--text-muted);">${open ? '▲' : '▼'}</span></div>${open ? `<div style="padding:0 20px 20px 20px;border-top:1px solid var(--border);">${doc ? `<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px;margin-bottom:10px;">${doc.title ? `<strong style="font-size:14px;">${escHtml(doc.title)}</strong>` : ''}<span class="badge" style="background:var(--bg-secondary);color:var(--text-secondary);">${escHtml(doc.source_type)}</span>${doc.original_filename ? `<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);">📎 ${escHtml(doc.original_filename)}</span>` : ''}<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);">${doc.character_count.toLocaleString()} car.</span>${isLarge ? `<span class="badge badge-warning">⚠️ ${t('contextDoc.largeWarning')}</span>` : ''}</div><div class="ctx-doc-panel-content" style="margin-bottom:14px;">${escHtml(doc.content)}</div><div style="display:flex;gap:8px;"><button class="btn btn-secondary btn-sm" data-action="open-ctx-doc-editor" data-session-id="${escHtml(session.id)}">${t('contextDoc.replace')}</button><button class="btn btn-danger btn-sm" data-action="delete-ctx-doc" data-session-id="${escHtml(session.id)}">${t('contextDoc.delete')}</button></div>` : `<div style="padding-top:14px;"><div style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">${t('contextDoc.noneAttached')}</div></div>`}${renderInlineContextDocEditor(session.id)}<span id="ctx-doc-hist-status" style="font-size:12px;color:var(--accent);margin-top:8px;display:block;"></span></div>` : ''}</div>`;
}

/* ── Main confrontation view ── */

function renderConfrontationResults(results) {
  const { state, t } = getCtx();
  if (results.rounds && Object.keys(results.rounds).length > 0) {
    const roundNums = Object.keys(results.rounds).map(Number).sort((a, b) => a - b);
    const total     = results.total_rounds || roundNums.length;
    const style     = results.interaction_style || 'sequential';
    const synthesis = results.synthesis || [];
    const roundIcons = (r) => r === 1 ? '🎯' : r === total ? '🏁' : (style === 'agent-to-agent' ? '⚡' : '🔄');
    const roundLabel = (r) => r === 1 ? t('confrontation.round1') + ` (${t('confrontation.initialPosition')})` : r === total ? t('confrontation.roundFinal') : `${t('confrontation.round')} ${r} — ${style === 'agent-to-agent' ? t('confrontation.agentToAgentChallenge') : t('confrontation.challenge')}`;
    let html = roundNums.map((r) => {
      const msgs = results.rounds[r] || [];
      if (msgs.length === 0) return '';
      return `<div class="phase-section"><div class="phase-header blue"><span>${roundIcons(r)}</span><span>${roundLabel(r)}</span></div><div class="phase-agents-grid">${msgs.map((msg) => renderConfrontationAgentCard(msg, false)).join('')}</div></div>`;
    }).join('');
    if (synthesis.length > 0) html += `<div class="phase-section"><div class="phase-header synthesis"><span>✨</span><span>${t('confrontation.phaseFinal')}</span></div><div class="phase-agents-grid">${synthesis.map((msg) => renderConfrontationAgentCard(msg, true)).join('')}</div></div>`;
    const sid = state.currentSession?.id ?? '';
    html += renderDebateInsightsPanels(results);
    html += renderWeightedVotePanel(results, sid);
    html += renderGraphViewPanel(sid);
    html += renderDebateAuditPanel(sid);
    html += renderArgumentHeatmapPanel(sid);
    html += renderDebateReplayPanel(sid);
    return html;
  }
  const phases = results.phases || {};
  const phaseDefs = [
    { key: 'blue_opening',  label: t('confrontation.phase1'),     colorClass: 'blue',      icon: '🔵' },
    { key: 'red_attack',    label: t('confrontation.phase2'),     colorClass: 'red',       icon: '🔴' },
    { key: 'blue_rebuttal', label: t('confrontation.phase3'),     colorClass: 'blue',      icon: '🔵' },
    { key: 'synthesis',     label: t('confrontation.phaseFinal'), colorClass: 'synthesis', icon: '✨' },
  ];
  const legacyHtml = phaseDefs.map(({ key, label, colorClass, icon }) => {
    const messages = phases[key] || [];
    if (messages.length === 0) return '';
    const isSynthesis = key === 'synthesis';
    return `<div class="phase-section"><div class="phase-header ${colorClass}"><span>${icon}</span><span>${label}</span></div><div class="phase-agents-grid">${messages.map((msg) => renderConfrontationAgentCard(msg, isSynthesis)).join('')}</div></div>`;
  }).join('');
  const sid2 = state.currentSession?.id ?? '';
  return legacyHtml
    + renderWeightedVotePanel(results, sid2)
    + renderGraphViewPanel(sid2)
    + renderDebateAuditPanel(sid2)
    + renderArgumentHeatmapPanel(sid2)
    + renderDebateReplayPanel(sid2);
}

function renderConfrontationView() {
  const { state, escHtml, t } = getCtx();
  const session = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  const results = state.confrontationResults;
  const blueTeam = session._blueTeam || ['pm', 'architect', 'po', 'ux-expert'];
  const redTeam  = session._redTeam || ['analyst', 'critic'];
  const includeSynthesis = session._includeSynthesis !== false;
  return `
    <div class="full-height-view confrontation-view">
      <div class="dr-header">
        <div class="dr-header-info">
          <div class="dr-title">⚔️ ${escHtml(session.title || t('confrontation.title'))}</div>
          <div class="dr-objective">${escHtml(session.initial_prompt || session.idea || session.objective || '')}</div>
          ${renderContextDocBadge()}
        </div>
        ${!state.confrontationRunning ? `<button class="btn btn-primary" data-action="run-confrontation">${t('confrontation.run')}</button>` : ''}
        <div class="export-actions">${renderExportButtons(session.id)}</div>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderContextDocPanel()}
      <div class="dr-content">
        ${state.confrontationRunning ? `<div class="loading-state"><span class="spinner spinner-lg"></span> ${t('confrontation.running')}</div>` : ''}
        ${!results && !state.confrontationRunning ? `
          <div class="confrontation-setup">
            <div class="team-selector">
              <div class="team-selector-blue">
                <div class="team-label">🔵 ${t('newSession.blueTeam').replace('🔵 ', '')}</div>
                <div class="agents-select-grid">
                  ${state.personas.map((p) => { const sel = blueTeam.includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="toggle-blue-team" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} style="pointer-events:none;accent-color:#3b82f6;"><span style="font-size:20px;">${escHtml(p.icon || '🤖')}</span><div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(p.name)}</div></label>`; }).join('')}
                </div>
              </div>
              <div class="team-selector-red">
                <div class="team-label">🔴 ${t('newSession.redTeam').replace('🔴 ', '')}</div>
                <div class="agents-select-grid">
                  ${state.personas.map((p) => { const sel = redTeam.includes(p.id); return `<label class="agent-select-card ${sel ? 'selected' : ''}" data-action="toggle-red-team" data-agent-id="${escHtml(p.id)}"><input type="checkbox" ${sel ? 'checked' : ''} style="pointer-events:none;accent-color:#ef4444;"><span style="font-size:20px;">${escHtml(p.icon || '🤖')}</span><div style="font-size:13px;font-weight:600;color:var(--text-primary);">${escHtml(p.name)}</div></label>`; }).join('')}
                </div>
              </div>
            </div>
            <div style="margin-top:16px;display:flex;align-items:center;gap:10px;">
              <input type="checkbox" id="cf-synthesis" ${includeSynthesis ? 'checked' : ''} data-field="includeSynthesis" style="width:16px;height:16px;accent-color:var(--accent);">
              <label for="cf-synthesis" style="text-transform:none;font-size:13px;font-weight:500;margin:0;cursor:pointer;color:var(--text-secondary);">${t('newSession.includeSynthesis')}</label>
            </div>
            <div class="empty-state" style="padding:32px 0;"><div class="empty-state-icon">⚔️</div><div class="empty-state-text">${t('confrontation.emptyState')}</div></div>
          </div>
        ` : ''}
        ${results ? renderConfrontationResults(results) : ''}
        ${!state.confrontationRunning ? renderAgentChatPanel('confrontation') : ''}
      </div>
    </div>
  `;
}

function registerConfrontationFeature() {
  window.DecisionArena.views.confrontation = renderConfrontationView;
  /* Update shared helpers – these supersede the legacy-app.js placeholders */
  Object.assign(window.DecisionArena.views.shared, {
    renderConfrontationAgentCard,
    renderDebateInsightsPanels,
    renderWeightedVotePanel,
    renderVerdictCard,
    renderSessionMemoryPanel,
    renderSessionContextDocPanel,
  });
}

export {
  registerConfrontationFeature,
  renderConfrontationView,
  renderConfrontationResults,
  renderConfrontationAgentCard,
  renderDebateInsightsPanels,
  renderArgumentMemoryCard,
  renderWeightedPositionsCard,
  renderInteractionGraphCard,
  renderWeightedVotePanel,
  renderVerdictCard,
  renderSessionMemoryPanel,
  renderSessionContextDocPanel,
};
/**
 * Decision Room feature – view registration.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderDebateInsightsPanels, renderWeightedVotePanel, renderDecisionReliabilityCard } from '../confrontation/index.js';

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
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, agentIcon: _ai, agentName: _an, agentTitleText: _at } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon      = (id) => _ai(state.personas, id);
  const agentName      = (id) => _an(state.personas, id);
  const agentTitleText = (id) => _at(state.personas, id);
  return { state, escHtml, renderMarkdown, agentIcon, agentName, agentTitleText, t };
}

function renderDRAgentCard(msg, isFinal) {
  const { escHtml, renderMarkdown, agentIcon, agentName, agentTitleText, t } = getCtx();
  const icon     = agentIcon(msg.agent_id);
  const name     = agentName(msg.agent_id);
  const titleTxt = agentTitleText(msg.agent_id);

  if (isFinal) {
    return `
      <div class="synthesis-card">
        <div class="agent-card-header" style="padding:14px 18px;background:var(--accent-light);border-bottom:1px solid rgba(99,102,241,0.3);">
          <span style="font-size:24px;">${icon}</span>
          <div>
            <div class="agent-name">${escHtml(name)}</div>
            ${titleTxt ? `<div class="agent-title">${escHtml(titleTxt)}</div>` : ''}
          </div>
          <span class="badge badge-success" style="margin-left:auto;">${t('dr.synthesis')}</span>
        </div>
        <div class="agent-content md-content" style="padding:18px;">${renderMarkdown(msg.content)}</div>
        ${msg.model ? `<div class="agent-card-footer">${msg.provider_id ? `<span>${escHtml(msg.provider_id)}</span>` : ''}<span>${escHtml(msg.model)}</span></div>` : ''}
      </div>
    `;
  }

  return `
    <div class="agent-card">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div>
          <div class="agent-name">${escHtml(name)}</div>
          ${titleTxt ? `<div class="agent-title">${escHtml(titleTxt)}</div>` : ''}
        </div>
      </div>
      <div class="agent-content md-content">${renderMarkdown(msg.content)}</div>
      ${msg.model ? `<div class="agent-card-footer">${msg.provider_id ? `<span>${escHtml(msg.provider_id)}</span>` : ''}<span>${escHtml(msg.model)}</span></div>` : ''}
    </div>
  `;
}

function renderDRResults(results) {
  const { state, t } = getCtx();
  const rounds      = results.rounds || {};
  const totalRounds = results.total_rounds || Object.keys(rounds).length;
  const roundNums   = Object.keys(rounds).map(Number).sort((a, b) => a - b);
  const roundTitles = ['Independent Analysis', 'Critical Review', 'Synthesis & Recommendations', 'Decision & Action Plan', 'Final Consensus'];

  const roundsHtml = roundNums.map((rNum) => {
    const messages = rounds[rNum] || [];
    const isFinal  = rNum === totalRounds;
    const title    = roundTitles[rNum - 1] || `Round ${rNum}`;
    return `
      <div class="round-section">
        <div class="round-header">
          <div class="round-number">${rNum}</div>
          <div class="round-title">${t('dr.round')} ${rNum} — ${title}</div>
          ${isFinal ? `<span class="badge badge-success">${t('dr.final')}</span>` : ''}
        </div>
        <div class="round-agents-grid">
          ${messages.map((msg) => renderDRAgentCard(msg, isFinal)).join('')}
        </div>
      </div>
    `;
  }).join('');

  const sessionId = state.currentSession?.id ?? '';
  return renderDecisionBrief(results.decision_brief || null)
    + roundsHtml
    + renderDebateInsightsPanels(results)
    + renderWeightedVotePanel(results, sessionId)
    + renderDecisionReliabilityCard(results)
    + renderGraphViewPanel(sessionId)
    + renderDebateAuditPanel(sessionId)
    + renderArgumentHeatmapPanel(sessionId)
    + renderDebateReplayPanel(sessionId);
}

function renderDecisionRoom() {
  const { state, escHtml, t } = getCtx();
  const session = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  const results = state.drResults;

  return `
    <div class="full-height-view">
      <div class="dr-header">
        <div class="dr-header-info">
          <div class="dr-title">🏛️ ${escHtml(session.title || t('dr.title'))}</div>
          <div class="dr-objective">${escHtml(session.initial_prompt || session.idea || session.objective || '')}</div>
          ${renderContextDocBadge()}
        </div>
        ${!state.drRunning ? `<button class="btn btn-primary" data-action="run-decision-room">${t('dr.run')}</button>` : ''}
        <div class="export-actions">${renderExportButtons(session.id)}</div>
        <button class="btn btn-secondary btn-sm" data-action="goto-chat" data-session-id="${escHtml(session.id)}">${t('dr.chat')}</button>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderContextDocPanel()}

      <div class="dr-content">
        ${state.drRunning ? `<div class="loading-state"><span class="spinner spinner-lg"></span> ${t('dr.running')}</div>` : ''}
        ${state.drAutoRetryBanner === 'running' ? `<div class="alert alert-warning" style="margin:8px 0;padding:10px 14px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.4);border-radius:6px;font-size:13px;">⚡ ${t('autoretry.banner.running')}</div>` : ''}
        ${state.drAutoRetryBanner === 'complete' ? `<div class="alert alert-info" style="margin:8px 0;padding:10px 14px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.3);border-radius:6px;font-size:13px;">✅ ${t('autoretry.banner.complete')}</div>` : ''}
        ${!results && !state.drRunning ? `<div class="empty-state"><div class="empty-state-icon">🏛️</div><div class="empty-state-text">${t('dr.emptyState')}</div></div>` : ''}
        ${results ? renderDRResults(results) : ''}
        ${!state.drRunning ? renderAgentChatPanel('decision-room') : ''}
      </div>
    </div>
  `;
}

function registerDecisionRoomFeature() {
  window.DecisionArena.views['decision-room'] = renderDecisionRoom;
}

export { registerDecisionRoomFeature, renderDecisionRoom, renderDRResults, renderDRAgentCard };

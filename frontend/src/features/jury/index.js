/**
 * Jury / Committee Mode — view registration.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderWeightedVotePanel, renderDecisionReliabilityCard } from '../confrontation/index.js';
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, renderMarkdown, agentIcon: _ai, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentIcon = (id) => _ai(state.personas, id);
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, renderMarkdown, t, agentIcon, agentName };
}

function renderJuryAgentCard(msg) {
  const { escHtml, renderMarkdown, agentIcon, agentName } = getCtx();
  const icon = agentIcon(msg.agent_id);
  const name = agentName(msg.agent_id);
  return `
    <div class="agent-card">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div>
          <div class="agent-name">${escHtml(name)}</div>
          ${msg.phase ? `<div class="agent-title" style="font-size:11px;">${escHtml(msg.phase)}</div>` : ''}
        </div>
        ${msg.target_agent_id ? `<span class="badge badge-info" style="margin-left:auto;font-size:10px;">→ ${escHtml(msg.target_agent_id)}</span>` : ''}
      </div>
      <div class="agent-content md-content">${renderMarkdown(msg.content)}</div>
      ${msg.model ? `<div class="agent-card-footer"><span>${escHtml(msg.provider_id ?? '')}</span><span>${escHtml(msg.model)}</span></div>` : ''}
    </div>
  `;
}

function renderLiveVotePanel(results, sessionId) {
  return renderWeightedVotePanel(results, sessionId);
}

function renderJuryResults(results) {
  const { state, t } = getCtx();
  const sessionId   = state.currentSession?.id ?? '';
  const rounds      = results.rounds ?? {};
  const roundKeys   = Object.keys(rounds).map(Number).sort((a, b) => a - b);

  const phaseLabels = {
    1: t('jury.openingStatements'),
    2: t('jury.crossExamination'),
  };
  const phaseLabel  = (r) => phaseLabels[r] ?? `${t('jury.deliberation')} ${r}`;
  const phaseIcon   = (r) => r === 1 ? '📋' : r === 2 ? '⚔️' : '⚖️';

  const roundsHtml = roundKeys.map((r) => {
    const msgs = rounds[r] ?? [];
    return `
      <div class="phase-section">
        <div class="phase-header blue">
          <span>${phaseIcon(r)}</span>
          <span>${phaseLabel(r)}</span>
        </div>
        <div class="phase-agents-grid">
          ${msgs.map((msg) => renderJuryAgentCard(msg)).join('')}
        </div>
      </div>
    `;
  }).join('');

  const synthHtml = (results.synthesis ?? []).length > 0 ? `
    <div class="phase-section">
      <div class="phase-header synthesis"><span>⚖️</span><span>${t('jury.verdict')}</span></div>
      <div class="phase-agents-grid">
        ${(results.synthesis ?? []).map((msg) => renderJuryAgentCard(msg)).join('')}
      </div>
    </div>
  ` : '';

  return roundsHtml
    + synthHtml
    + renderLiveVotePanel(results, sessionId)
    + renderDecisionReliabilityCard(results)
    + renderGraphViewPanel(sessionId)
    + renderArgumentHeatmapPanel(sessionId)
    + renderDebateAuditPanel(sessionId)
    + renderDebateReplayPanel(sessionId);
}

function renderJury() {
  const { state, escHtml, t } = getCtx();
  const session = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  const results = state.juryResults;

  return `
    <div class="full-height-view">
      <div class="dr-header">
        <div class="dr-header-info">
          <div class="dr-title">⚖️ ${escHtml(session.title || t('jury.title'))}</div>
          <div class="dr-objective">${escHtml(session.initial_prompt || session.idea || '')}</div>
          ${renderContextDocBadge()}
        </div>
        ${!state.juryRunning ? `<button class="btn btn-primary" data-action="run-jury">${t('jury.run')}</button>` : ''}
        <div class="export-actions">${renderExportButtons(session.id)}</div>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderContextDocPanel()}

      <div class="dr-content">
        ${state.juryRunning ? `<div class="loading-state"><span class="spinner spinner-lg"></span> ${t('jury.running')}</div>` : ''}

        ${!results && !state.juryRunning ? `
          <div class="empty-state" style="padding:48px 0;">
            <div class="empty-state-icon">⚖️</div>
            <div class="empty-state-text">${t('jury.emptyState')}</div>
          </div>
        ` : ''}

        ${results ? renderJuryResults(results) : ''}
        ${!state.juryRunning ? renderAgentChatPanel('jury') : ''}
      </div>
    </div>
  `;
}

function registerJuryFeature() {
  window.DecisionArena.views.jury = renderJury;
}

export { registerJuryFeature, renderJury };

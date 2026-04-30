/**
 * Stress Test feature – view registration.
 *
 * renderStressTestResults delegates to confrontation shared helpers
 * (renderDebateInsightsPanels, renderWeightedVotePanel, renderVerdictCard)
 * registered by registerConfrontationFeature() via window.DecisionArena.views.shared.
 *
 * renderDRAgentMessage is imported from the chat feature module.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderDRAgentMessage, renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

function renderStressTestResults(results) {
  const { t } = getCtx();
  const shared   = window.DecisionArena.views?.shared || {};
  const rounds   = results.rounds || {};
  const roundKeys = Object.keys(rounds).map(Number).sort((a, b) => a - b);

  const roundsHtml = roundKeys.map((round) => {
    const msgs       = rounds[round] || [];
    const isSynthRound = msgs.some((m) => m.agent_id === 'synthesizer');

    return `
      <div class="dr-round-section">
        <div class="dr-round-header">
          <span>${round === 1
            ? `🔍 ${t('st.round1')}`
            : isSynthRound
              ? `📋 ${t('st.synthesis')}`
              : `🛡️ ${t('st.round2')}`
          }</span>
        </div>
        <div class="dr-round-messages">
          ${msgs.map((msg) => renderDRAgentMessage(msg, msg.agent_id === 'synthesizer')).join('')}
        </div>
      </div>
    `;
  }).join('');

  const sessionId      = window.DecisionArena.store.state.currentSession?.id ?? '';
  const insightsHtml   = shared.renderDebateInsightsPanels ? shared.renderDebateInsightsPanels(results) : '';
  const voteHtml       = shared.renderWeightedVotePanel    ? shared.renderWeightedVotePanel(results, sessionId) : '';
  const reliabilityHtml = shared.renderDecisionReliabilityCard ? shared.renderDecisionReliabilityCard(results) : '';
  const verdictHtml    = results.verdict && shared.renderVerdictCard ? shared.renderVerdictCard(results.verdict) : '';

  return roundsHtml + insightsHtml + voteHtml + reliabilityHtml + verdictHtml
    + renderGraphViewPanel(sessionId)
    + renderDebateAuditPanel(sessionId)
    + renderArgumentHeatmapPanel(sessionId)
    + renderDebateReplayPanel(sessionId);
}

function renderStressTest() {
  const { state, escHtml, t } = getCtx();
  const session = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  const results = state.stResults;

  return `
    <div class="full-height-view">
      <div class="dr-header">
        <div class="dr-header-info">
          <div class="dr-title">🔥 ${escHtml(session.title || t('mode.stressTest'))}</div>
          <div class="dr-objective">${escHtml(session.initial_prompt || '')}</div>
          ${renderContextDocBadge()}
        </div>
        ${!state.stRunning ? `
          <button class="btn btn-danger" data-action="run-stress-test">
            ${t('st.run')}
          </button>
        ` : ''}
        <div class="export-actions">${renderExportButtons(session.id)}</div>
        <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
      </div>
      ${renderContextDocPanel()}

      <div class="dr-content">
        ${state.stRunning ? `
          <div class="loading-state"><span class="spinner spinner-lg"></span> ${t('st.running')}</div>
        ` : ''}

        ${!results && !state.stRunning ? `
          <div class="empty-state" style="padding:48px 0;">
            <div class="empty-state-icon">🔥</div>
            <div class="empty-state-text">${t('st.emptyState')}</div>
            ${session.force_disagreement ? `<div class="badge badge-warning" style="margin-top:12px;">${t('newSession.forceDisagreementActive')}</div>` : ''}
          </div>
        ` : ''}

        ${results ? renderStressTestResults(results) : ''}

        ${!state.stRunning ? renderAgentChatPanel('stress-test') : ''}
      </div>
    </div>
  `;
}

function registerStressTestFeature() {
  window.DecisionArena.views['stress-test'] = renderStressTest;
}

export { registerStressTestFeature, renderStressTest, renderStressTestResults };

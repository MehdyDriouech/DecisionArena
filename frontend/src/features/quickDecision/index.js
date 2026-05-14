/**
 * Quick Decision feature – view registration.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderDecisionBrief, renderDecisionDynamicsSummary, renderDecisionOutcomeCard, renderPremortemStructuredCard, renderTradeoffSection } from '../../ui/components.js';
import { renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderConfrontationAgentCard, renderWeightedVotePanel, renderDecisionReliabilityCard, renderVerdictCard } from '../confrontation/index.js';
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';
import { renderSessionPresetUsedBanner } from '../../utils/sessionDynamicsPresetUi.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, agentName: _an } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  const agentName = (id) => _an(state.personas, id);
  return { state, escHtml, agentName, t };
}

function renderQuickDecision() {
  const { state, escHtml, agentName, t } = getCtx();
  const session = state.currentSession;
  if (!session) return `<div class="view-container"><p>${t('chat.noSession')}</p></div>`;
  const results = state.qdResults;

  return `
    <div class="full-height-view">
      <div class="dr-header">
        <div class="session-result-toolbar">
          <div class="session-result-context">
            <div class="dr-header-info">
              <div class="dr-title">⚡ ${escHtml(session.title || t('mode.quickDecision'))}</div>
              <div class="dr-objective">${escHtml(session.initial_prompt || session.idea || '')}</div>
              ${renderSessionPresetUsedBanner(session, escHtml, t)}
              ${renderContextDocBadge()}
            </div>
          </div>
          <div class="session-result-actions">
            ${!state.qdRunning ? `<button class="btn btn-primary" data-action="run-quick-decision">${t('qd.run')}</button>` : ''}
            <div class="export-actions">${renderExportButtons(session.id)}</div>
            <button class="btn btn-secondary btn-sm" data-nav="sessions">${t('nav.back')}</button>
          </div>
        </div>
      </div>
      ${renderContextDocPanel()}

      <div class="dr-content">
        ${state.qdRunning ? `<div class="loading-state"><span class="spinner spinner-lg"></span> ${t('qd.running')}</div>` : ''}

        ${!results && !state.qdRunning ? `
          <div class="empty-state" style="padding:48px 0;">
            <div class="empty-state-icon">⚡</div>
            <div class="empty-state-text">${t('qd.emptyState')}</div>
            ${session.force_disagreement ? `<div class="badge badge-warning" style="margin-top:12px;">${t('newSession.forceDisagreementActive')}</div>` : ''}
          </div>
        ` : ''}

        ${results ? `
          ${renderDecisionOutcomeCard(results.decision_outcome || results.decision_brief?.decision_outcome || null, { uiMode: state.uiMode, sessionId: session.id })}
          ${renderDecisionBrief(results.decision_brief || null, {
            sessionId: session.id,
            agentDecisionDynamics: results.agent_decision_dynamics,
            uiMode: state.uiMode,
          })}
          ${renderTradeoffSection(results.decision_brief || null, { uiMode: state.uiMode, tradeoffUid: session.id })}
          ${renderPremortemStructuredCard(results.premortem_summary || null, t, escHtml)}
          ${renderDecisionDynamicsSummary(results.agent_decision_dynamics || [], {
            escHtml, agentName, t, session, votes: results.votes || [],
          })}
          ${results.warning ? `<div class="error-banner" style="margin-bottom:16px;">⚠️ ${escHtml(results.warning)}</div>` : ''}

          <details id="debate-section-${escHtml(session.id)}" data-section="debate-details" ${state.showDebateDetails ? 'open' : ''} style="margin:0 0 16px;">
            <summary class="btn btn-secondary btn-sm">Voir le debat complet</summary>
            <div style="margin-top:12px;">
              ${results.round && results.round.length > 0 ? `
              <div class="phase-section">
                <div class="phase-header blue"><span>🎯</span><span>${t('qd.analysisRound')}</span></div>
                <div class="phase-agents-grid">
                  ${results.round.map((msg, idx) => renderConfrontationAgentCard(msg, false, `${session.id}-qd-r-${idx}`)).join('')}
                </div>
              </div>
              ` : ''}

              ${results.synthesis && results.synthesis.length > 0 ? `
              <div class="phase-section">
                <div class="phase-header synthesis"><span>✨</span><span>${t('dr.synthesis')}</span></div>
                <div class="phase-agents-grid">
                  ${results.synthesis.map((msg, idx) => renderConfrontationAgentCard(msg, true, `${session.id}-qd-s-${idx}`)).join('')}
                </div>
              </div>
              ` : ''}
            </div>
          </details>

          ${renderWeightedVotePanel(results, session.id)}
          ${renderDecisionReliabilityCard(results)}
          ${results.verdict ? renderVerdictCard(results.verdict) : ''}
          ${renderGraphViewPanel(session.id)}
          ${renderDebateAuditPanel(session.id)}
          ${renderArgumentHeatmapPanel(session.id)}
          ${renderDebateReplayPanel(session.id)}
        ` : ''}

        ${!state.qdRunning ? renderAgentChatPanel('quick-decision') : ''}
      </div>
    </div>
  `;
}

function registerQuickDecisionFeature() {
  window.DecisionArena.views['quick-decision'] = renderQuickDecision;
}

export { registerQuickDecisionFeature, renderQuickDecision };

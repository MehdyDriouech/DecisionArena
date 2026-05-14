/**
 * Decision Room feature – view registration.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderDecisionBrief, renderDecisionDynamicsSummary, renderDecisionOutcomeCard, renderPremortemInvertedBanner, renderPremortemStructuredCard, renderTradeoffSection } from '../../ui/components.js';
import { renderExportButtons, renderAgentChatPanel } from '../chat/view.js';
import { renderDebateInsightsPanels, renderWeightedVotePanel, renderDecisionReliabilityCard } from '../confrontation/index.js';
import { renderDebateAuditPanel } from '../debateAudit/index.js';
import { renderGraphViewPanel } from '../graphView/index.js';
import { renderArgumentHeatmapPanel } from '../argumentHeatmap/index.js';
import { renderDebateReplayPanel } from '../debateReplay/index.js';
import { renderSessionPresetUsedBanner } from '../../utils/sessionDynamicsPresetUi.js';
import { isSixThinkingHatsSession, renderSixThinkingHatsGroupedDebate, renderSixThinkingMethodBanner } from '../../utils/sixThinkingHats.js';
import { renderLlmRoutingCompact } from '../../utils/llmRoutingUi.js';
import { renderRunProgressPanel } from '../../ui/runProgressPanel.js';
import { ensureLiveRunSessionHydratedIfMismatch } from '../../utils/liveRunCompletion.js';

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

function renderDRAgentCard(msg, isFinal, messageKey = '') {
  const { state, escHtml, renderMarkdown, agentIcon, agentName, agentTitleText, t } = getCtx();
  const icon     = agentIcon(msg.agent_id);
  const name     = agentName(msg.agent_id);
  const titleTxt = agentTitleText(msg.agent_id);

  const messageId = String(msg.id || messageKey || `${msg.agent_id || 'agent'}-${msg.created_at || Date.now()}`);
  const isLong = String(msg.content || '').length > 650;
  const collapsed = !!state.collapsedMessages?.[messageId];
  const preview = escHtml(String(msg.content || '').slice(0, 320));
  const contentHtml = isLong && collapsed
    ? `<div class="agent-content md-content"><p>${preview}…</p></div>`
    : `<div class="agent-content md-content">${renderMarkdown(msg.content)}</div>`;
  const llmMeta = renderLlmRoutingCompact(msg, { escHtml, t, expert: state.uiMode === 'expert' });

  if (isFinal) {
    const chBtn = window.DecisionArena?.utils?.canChallengeMessage?.(msg)
      ? `<div style="padding:0 18px 14px;"><button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;" data-action="challenge-claim" data-message-id="${escHtml(String(msg.id))}">${escHtml(t('hitl.challenge'))}</button></div>`
      : '';
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
        <div style="padding:18px;">${window.DecisionArena?.utils?.formatHitlMessageBadges?.(msg, t, escHtml) || ''}${contentHtml}</div>
        ${isLong ? `<div style="padding:0 18px 14px;"><button class="btn btn-secondary btn-sm" data-action="toggle-agent-message" data-message-id="${escHtml(messageId)}">${collapsed ? 'Voir' : 'Masquer'}</button></div>` : ''}
        ${chBtn}
        ${llmMeta ? `<div class="agent-card-footer">${llmMeta}</div>` : ''}
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
      ${window.DecisionArena?.utils?.formatHitlMessageBadges?.(msg, t, escHtml) || ''}
      ${contentHtml}
      ${isLong ? `<button class="btn btn-secondary btn-sm" data-action="toggle-agent-message" data-message-id="${escHtml(messageId)}">${collapsed ? 'Voir' : 'Masquer'}</button>` : ''}
      ${window.DecisionArena?.utils?.canChallengeMessage?.(msg)
    ? `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;font-size:11px;" data-action="challenge-claim" data-message-id="${escHtml(String(msg.id))}">${escHtml(t('hitl.challenge'))}</button>`
    : ''}
      ${llmMeta ? `<div class="agent-card-footer">${llmMeta}</div>` : ''}
    </div>
  `;
}

function renderDRResults(results) {
  const { state, t, escHtml, agentName, renderMarkdown, agentIcon, agentTitleText } = getCtx();
  const rounds      = results.rounds || {};
  const totalRounds = results.total_rounds || Object.keys(rounds).length;
  const roundNums   = Object.keys(rounds).map(Number).sort((a, b) => a - b);
  const sessionId = state.currentSession?.id ?? '';
  const isPm = state.currentSession?.session_variant === 'premortem';
  const sess = state.currentSession;
  const sixHats = sess && isSixThinkingHatsSession(sess);
  const dynamicsHtml = renderDecisionDynamicsSummary(results.agent_decision_dynamics || [], {
    escHtml, agentName, t, session: state.currentSession, votes: results.votes || [],
  });
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
          ${messages.map((msg, idx) => renderDRAgentCard(msg, isFinal, `${sessionId}-r${rNum}-m${idx}`)).join('')}
        </div>
      </div>
    `;
  }).join('');

  const uiMode = state.uiMode || 'basic';
  const rp = sessionId ? (state.runProgressBySessionId?.[sessionId]?.data || state.runProgress) : null;
  const terminalRunCompleted = String(rp?.status || '').toLowerCase() === 'completed';
  const groupedHatsHtml = sixHats && uiMode === 'expert'
    ? renderSixThinkingHatsGroupedDebate(rounds, {
      escHtml,
      renderMarkdown,
      agentIcon,
      agentName,
      agentTitleText,
      t,
    })
    : '';

  return (sixHats ? renderSixThinkingMethodBanner(sess, t, escHtml) : '')
    + (isPm ? renderPremortemInvertedBanner(t, escHtml) : '')
    + renderDecisionOutcomeCard(results.decision_outcome || results.decision_brief?.decision_outcome || null, {
      uiMode,
      sessionId,
      verdict: results.verdict || null,
      sessionCompleted: String(sess?.status || '').toLowerCase() === 'completed',
      terminalRunCompleted,
    })
    + renderDecisionBrief(results.decision_brief || null, {
      sessionId,
      agentDecisionDynamics: results.agent_decision_dynamics,
      uiMode,
    })
    + renderTradeoffSection(results.decision_brief || null, { uiMode, tradeoffUid: sessionId })
    + renderPremortemStructuredCard(results.premortem_summary || null, t, escHtml)
    + dynamicsHtml
    + groupedHatsHtml
    + `<details id="debate-section-${sessionId}" data-section="debate-details" ${state.showDebateDetails ? 'open' : ''} style="margin:0 0 16px;"><summary class="btn btn-secondary btn-sm">Voir le debat complet</summary><div style="margin-top:12px;">${roundsHtml}</div></details>`
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
  const arena = window.DecisionArena;
  queueMicrotask(() => {
    ensureLiveRunSessionHydratedIfMismatch({
      state: arena.store.state,
      sessionId: session.id,
      mode: 'decision-room',
      SessionService: arena.services.SessionService,
      render: () => arena.render?.(),
    });
  });
  const results = state.drResults;
  const pmHeaderBadge = session.session_variant === 'premortem'
    ? ` <span class="badge badge-info" style="font-size:11px;vertical-align:middle;">${escHtml(t('premortem.badge'))}</span>`
    : '';
  const runProgressEntry = session?.id ? state.runProgressBySessionId?.[session.id] : null;
  const liveRunProgress = runProgressEntry?.data || state.runProgress;

  return `
    <div class="full-height-view">
      <div class="dr-header">
        <div class="session-result-toolbar">
          <div class="session-result-context">
            <div class="dr-header-info">
              <div class="dr-title">🏛️ ${escHtml(session.title || t('dr.title'))}${pmHeaderBadge}</div>
              <div class="dr-objective">${escHtml(session.initial_prompt || session.idea || session.objective || '')}</div>
              ${renderSessionPresetUsedBanner(session, escHtml, t)}
              ${renderContextDocBadge()}
            </div>
          </div>
          <div class="session-result-actions">
            ${!state.drRunning ? `<button class="btn btn-primary" data-action="run-decision-room">${t('dr.run')}</button>` : ''}
            <div class="export-actions">${renderExportButtons(session.id)}</div>
            <button class="btn btn-secondary btn-sm" data-action="goto-chat" data-session-id="${escHtml(session.id)}">${t('dr.chat')}</button>
            <button class="btn btn-secondary btn-sm" data-nav="analyses">${t('nav.back')}</button>
          </div>
        </div>
      </div>
      ${renderContextDocPanel()}

      <div class="dr-content">
        ${state.drRunning ? renderRunProgressPanel(liveRunProgress, {
    t,
    escHtml,
    uiMode: state.uiMode,
    mode: 'decision-room',
    polling: state.runProgressPolling || null,
  }) : ''}
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

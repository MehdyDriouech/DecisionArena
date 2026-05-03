/**
 * Jury / Committee Mode — view registration.
 */

import { renderContextDocBadge, renderContextDocPanel } from '../../ui/contextDoc.js';
import { renderDecisionBrief } from '../../ui/components.js';
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

/* ── Phase label helpers ── */

const PHASE_LABELS_MAP = {
  'jury-opening': '📋',
  'jury-cross-examination': '⚔️',
  'jury-defense': '🛡️',
  'jury-deliberation': '💬',
  'jury-mini-challenge': '⚡',
  'jury-minority-report': '📣',
  'jury-verdict': '⚖️',
};

function phaseIcon(phase) {
  return PHASE_LABELS_MAP[phase] || '💬';
}

function renderJuryAgentCard(msg, messageKey = '') {
  const { state, escHtml, renderMarkdown, agentIcon, agentName } = getCtx();
  const icon = agentIcon(msg.agent_id);
  const name = agentName(msg.agent_id);
  const phaseBadge = msg.phase === 'jury-minority-report'
    ? `<span class="badge" style="background:var(--color-warning,#f59e0b);color:#fff;margin-left:auto;font-size:10px;">📣 Minority</span>`
    : (msg.phase === 'jury-mini-challenge'
      ? `<span class="badge badge-warning" style="margin-left:auto;font-size:10px;">⚡ Extra challenge</span>`
      : '');
  const messageId = String(msg.id || messageKey || `${msg.agent_id || 'agent'}-${msg.created_at || Date.now()}`);
  const isLong = String(msg.content || '').length > 650;
  const collapsed = !!state.collapsedMessages?.[messageId];
  const preview = escHtml(String(msg.content || '').slice(0, 320));
  const contentHtml = isLong && collapsed
    ? `<div class="agent-content md-content"><p>${preview}…</p></div>`
    : `<div class="agent-content md-content">${renderMarkdown(msg.content)}</div>`;
  return `
    <div class="agent-card${msg.phase === 'jury-minority-report' ? ' agent-card--minority' : ''}">
      <div class="agent-card-header">
        <span class="agent-icon">${icon}</span>
        <div>
          <div class="agent-name">${escHtml(name)}</div>
          ${msg.phase ? `<div class="agent-title" style="font-size:11px;">${phaseIcon(msg.phase)} ${escHtml(msg.phase)}</div>` : ''}
        </div>
        ${msg.target_agent_id ? `<span class="badge badge-info" style="margin-left:auto;font-size:10px;">→ ${escHtml(msg.target_agent_id)}</span>` : phaseBadge}
      </div>
      ${contentHtml}
      ${isLong ? `<button class="btn btn-secondary btn-sm" data-action="toggle-agent-message" data-message-id="${escHtml(messageId)}">${collapsed ? 'Voir' : 'Masquer'}</button>` : ''}
      ${msg.model ? `<div class="agent-card-footer"><span>${escHtml(msg.provider_id ?? '')}</span><span>${escHtml(msg.model)}</span></div>` : ''}
    </div>
  `;
}

/* ── Adversarial quality card ── */

function renderAdversarialCard(juryAdversarial) {
  if (!juryAdversarial || !juryAdversarial.enabled) return '';
  const { t, escHtml } = getCtx();

  const score = juryAdversarial.debate_quality_score ?? 0;
  const warnings = juryAdversarial.warnings ?? [];
  const isWeak = score < 50;

  const scoreColor = score >= 70 ? 'var(--color-success,#10b981)'
    : score >= 40 ? 'var(--color-warning,#f59e0b)'
    : 'var(--color-error,#ef4444)';

  const warningLabels = {
    weak_debate_quality: t('jury.adversarial.warningWeak'),
    insufficient_challenge: t('jury.adversarial.warningParallel'),
    parallel_answers_detected: t('jury.adversarial.warningParallel'),
    false_consensus_risk_high: '⚠️ Risque de faux consensus élevé',
    no_consensus_reached: t('jury.adversarial.warningNoConsensus'),
    synthesis_constrained_by_vote: t('jury.adversarial.synthesisConstrained'),
  };

  const positionChangers = juryAdversarial.position_changers ?? {};
  const positionChangerList = Object.entries(positionChangers)
    .map(([agent, change]) => `<li><strong>${escHtml(agent)}</strong>: ${escHtml(change.from)} → ${escHtml(change.to)}</li>`)
    .join('');

  return `
    <div class="adversarial-card" style="margin:16px 0;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
      <div class="adversarial-card-header" style="padding:12px 16px;background:var(--surface-2,#f8f9fa);border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:16px;">⚔️</span>
        <strong>${t('jury.adversarial.qualityTitle')}</strong>
        <span style="margin-left:auto;font-size:22px;font-weight:700;color:${scoreColor};">${score}<span style="font-size:13px;font-weight:400;color:var(--text-muted);">/100</span></span>
      </div>

      ${isWeak ? `
        <div style="padding:12px 16px;background:rgba(239,68,68,0.08);border-bottom:1px solid rgba(239,68,68,0.2);">
          <div style="font-weight:600;color:var(--color-error,#ef4444);margin-bottom:4px;">⚠️ ${t('jury.adversarial.title')} — ${t('jury.adversarial.warningWeak')}</div>
          <div style="font-size:13px;color:var(--text-muted);">${t('jury.adversarial.desc')}</div>
        </div>
      ` : ''}

      <div style="padding:12px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.challengeCount')}</div>
          <div class="stat-value">${juryAdversarial.challenge_count ?? 0}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.mostChallenged')}</div>
          <div class="stat-value">${juryAdversarial.most_challenged_agent ? escHtml(juryAdversarial.most_challenged_agent) : '—'}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.positionChanges')}</div>
          <div class="stat-value">${juryAdversarial.position_changes ?? 0}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.minorityDetected')}</div>
          <div class="stat-value">${juryAdversarial.minority_report_present ? '✅ Oui' : '❌ Non'}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Density</div>
          <div class="stat-value">${Math.round((juryAdversarial.interaction_density ?? 0) * 100)}%</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.complianceRetries')}</div>
          <div class="stat-value">${juryAdversarial.compliance_retries ?? 0}</div>
        </div>
        ${juryAdversarial.planned_rounds != null ? `
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.plannedRounds')}</div>
          <div class="stat-value">${juryAdversarial.planned_rounds}</div>
        </div>` : ''}
        ${juryAdversarial.executed_rounds != null ? `
        <div class="stat-item">
          <div class="stat-label">${t('jury.adversarial.executedRounds')}</div>
          <div class="stat-value">${juryAdversarial.executed_rounds}</div>
        </div>` : ''}
      </div>

      ${positionChangerList ? `
        <div style="padding:0 16px 12px;">
          <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;">${t('jury.adversarial.positionChanges')}</div>
          <ul style="margin:0;padding-left:18px;font-size:13px;">${positionChangerList}</ul>
        </div>
      ` : ''}

      ${warnings.length > 0 ? `
        <div style="padding:0 16px 12px;">
          <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:6px;">Avertissements</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            ${warnings.map(w => `<span class="badge badge-warning" style="font-size:11px;">${escHtml(warningLabels[w] ?? w)}</span>`).join('')}
          </div>
        </div>
      ` : ''}
    </div>
  `;
}

/* ── Adversarial options panel (expert mode) ── */

function renderAdversarialOptionsPanel() {
  const { state, t } = getCtx();
  const complexity = state.uiComplexity ?? 'advanced';
  if (complexity !== 'expert') return '';

  const cfg     = state.juryAdversarialConfig ?? {};
  const session = state.currentSession;
  const agents  = (session?.selected_agents ?? []).filter((a) => a !== 'synthesizer');
  const currentReporter = cfg.minority_reporter_agent_id || '';

  const reporterOptions = [
    `<option value="" ${currentReporter === '' ? 'selected' : ''}>${t('jury.adversarial.minorityReporterAuto')}</option>`,
    ...agents.map((a) => `<option value="${a}" ${currentReporter === a ? 'selected' : ''}>${a}</option>`),
  ].join('');

  return `
    <div class="collapsible-panel" data-ui-min="expert" style="margin:12px 0;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
      <div class="collapsible-panel-header" style="padding:10px 16px;background:var(--surface-2,#f8f9fa);cursor:pointer;display:flex;align-items:center;gap:8px;"
           data-action="toggle-panel-collapse" data-panel-key="jury-adversarial-options">
        <span>⚔️</span>
        <strong>${t('jury.adversarial.title')}</strong>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${t('jury.adversarial.desc')}</span>
      </div>
      <div class="collapsible-panel-body" style="padding:14px 16px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
          <label class="form-field-row" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" data-action="toggle-jury-adversarial-opt" data-key="jury_adversarial_enabled"
                   ${(cfg.jury_adversarial_enabled !== false) ? 'checked' : ''}>
            ${t('jury.adversarial.enabled')}
          </label>
          <label class="form-field-row" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" data-action="toggle-jury-adversarial-opt" data-key="require_minority_report"
                   ${(cfg.require_minority_report !== false) ? 'checked' : ''}>
            Rapport minoritaire obligatoire
          </label>
          <label class="form-field-row" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" data-action="toggle-jury-adversarial-opt" data-key="block_weak_debate_decision"
                   ${(cfg.block_weak_debate_decision !== false) ? 'checked' : ''}>
            ${t('jury.adversarial.blockWeakDecision')}
          </label>
          <label class="form-field-row" style="display:flex;align-items:center;gap:8px;">
            <span>${t('jury.adversarial.minChallenges')}</span>
            <input type="number" min="1" max="5" value="${cfg.min_challenges_per_round ?? 2}"
                   data-action="set-jury-adversarial-num" data-key="min_challenges_per_round"
                   style="width:60px;margin-left:auto;">
          </label>
          <label class="form-field-row" style="display:flex;align-items:center;gap:8px;">
            <span>Score qualité min</span>
            <input type="number" min="0" max="100" value="${cfg.debate_quality_min_score ?? 50}"
                   data-action="set-jury-adversarial-num" data-key="debate_quality_min_score"
                   style="width:60px;margin-left:auto;">
          </label>
          ${agents.length > 0 ? `
          <div class="form-group" style="grid-column:1/-1;">
            <label for="jury-minority-reporter-select" style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
              ${t('jury.adversarial.minorityReporter')}
              <span style="font-weight:400;color:var(--text-muted);margin-left:6px;">${t('jury.adversarial.minorityReporterDesc')}</span>
            </label>
            <select id="jury-minority-reporter-select" class="input"
                    data-action="set-jury-adversarial-str" data-key="minority_reporter_agent_id"
                    style="max-width:280px;">
              ${reporterOptions}
            </select>
          </div>` : ''}
        </div>
      </div>
    </div>
  `;
}

/* ── Rerun button ── */

function renderRerunButton(results) {
  const { t } = getCtx();
  if (!results) return '';

  const juryAdv = results.jury_adversarial ?? {};
  const score = juryAdv.debate_quality_score ?? 100;
  const warnings = juryAdv.warnings ?? [];
  const showRerun = score < 60 || warnings.includes('no_consensus_reached') || warnings.includes('weak_debate_quality');

  if (!showRerun) return '';

  return `
    <div style="margin:12px 0;text-align:center;">
      <button class="btn btn-warning" data-action="rerun-jury-strong" style="font-size:14px;">
        🔁 ${t('jury.adversarial.rerunStrong')}
      </button>
      <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">
        ${t('jury.adversarial.desc')}
      </div>
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

  // Separate special rounds from numeric rounds
  const numericKeys = Object.keys(rounds)
    .filter(k => !isNaN(Number(k)))
    .map(Number)
    .sort((a, b) => a - b);

  const phaseLabelsMap = {
    1: t('jury.openingStatements'),
    2: t('jury.crossExamination'),
    3: t('jury.defense'),
  };

  const roundLabel = (r) => phaseLabelsMap[r] ?? `${t('jury.deliberation')} ${r}`;
  const roundIcon  = (r) => r === 1 ? '📋' : r === 2 ? '⚔️' : r === 3 ? '🛡️' : '💬';

  const roundsHtml = numericKeys.map((r) => {
    const msgs = rounds[r] ?? [];
    return `
      <div class="phase-section">
        <div class="phase-header blue">
          <span>${roundIcon(r)}</span>
          <span>${roundLabel(r)}</span>
        </div>
        <div class="phase-agents-grid">
          ${msgs.map((msg, idx) => renderJuryAgentCard(msg, `${sessionId}-jury-r${r}-m${idx}`)).join('')}
        </div>
      </div>
    `;
  }).join('');

  // Mini-challenge round (if present)
  const miniChallengeHtml = (rounds['mini-challenge'] ?? []).length > 0 ? `
    <div class="phase-section">
      <div class="phase-header" style="background:var(--color-warning,#f59e0b);color:#fff;">
        <span>⚡</span><span>Round de challenge supplémentaire</span>
      </div>
      <div class="phase-agents-grid">
        ${(rounds['mini-challenge'] ?? []).map((msg, idx) => renderJuryAgentCard(msg, `${sessionId}-jury-mini-${idx}`)).join('')}
      </div>
    </div>
  ` : '';

  // Minority reports (if present)
  const minorityHtml = (rounds['minority'] ?? []).length > 0 ? `
    <div class="phase-section">
      <div class="phase-header" style="background:var(--color-warning,#f59e0b);color:#fff;">
        <span>📣</span><span>${t('jury.adversarial.minorityDetected')} — Rapport minoritaire</span>
      </div>
      <div class="phase-agents-grid">
        ${(rounds['minority'] ?? []).map((msg, idx) => renderJuryAgentCard(msg, `${sessionId}-jury-minority-${idx}`)).join('')}
      </div>
    </div>
  ` : '';

  const synthHtml = (results.synthesis ?? []).length > 0 ? `
    <div class="phase-section">
      <div class="phase-header synthesis"><span>⚖️</span><span>${t('jury.verdict')}</span></div>
      <div class="phase-agents-grid">
        ${(results.synthesis ?? []).map((msg, idx) => renderJuryAgentCard(msg, `${sessionId}-jury-s-${idx}`)).join('')}
      </div>
    </div>
  ` : '';

  return renderDecisionBrief(results.decision_brief || null, { sessionId })
    + `<details id="debate-section-${sessionId}" data-section="debate-details" ${state.showDebateDetails ? 'open' : ''} style="margin:0 0 16px;"><summary class="btn btn-secondary btn-sm">Voir le debat complet</summary><div style="margin-top:12px;">${roundsHtml}${miniChallengeHtml}${minorityHtml}${synthHtml}</div></details>`
    + renderAdversarialCard(results.jury_adversarial)
    + renderRerunButton(results)
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
        ${(() => { try { return renderAdversarialOptionsPanel(); } catch (_) { return ''; } })()}

        ${state.error ? `<div class="alert alert-danger" style="margin:12px 0;padding:12px 16px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:var(--color-error,#ef4444);font-size:13px;">⚠️ ${escHtml(state.error)}</div>` : ''}

        ${state.juryRunning ? `<div class="loading-state"><span class="spinner spinner-lg"></span> ${t('jury.running')}</div>` : ''}
        ${state.juryAutoRetryBanner === 'running' ? `<div class="alert alert-warning" style="margin:8px 0;padding:10px 14px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.4);border-radius:6px;font-size:13px;">⚡ ${t('autoretry.banner.running')}</div>` : ''}
        ${state.juryAutoRetryBanner === 'complete' ? `<div class="alert alert-info" style="margin:8px 0;padding:10px 14px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.3);border-radius:6px;font-size:13px;">✅ ${t('autoretry.banner.complete')}</div>` : ''}

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

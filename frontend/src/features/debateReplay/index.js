/**
 * Debate Replay — view module.
 */

import { renderPanelRecommendBadge, renderTooltip } from '../../ui/components.js';

function panelHl(state) {
  return state.sessionHistory?.panelHighlights || state.auditData?.highlights || [];
}

function replayTitleRow(t, state) {
  return `⏯ ${t('replay.title')} ${renderTooltip(t('tooltip.replay'))} ${renderPanelRecommendBadge('replay', panelHl(state), t)}`;
}

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

const EVENT_ICONS = {
  message: '💬', interaction: '🔗', vote: '🗳️', decision: '⚖️', action_plan: '📋',
};

function renderEventCard(event, isCurrent) {
  const { escHtml, t } = getCtx();
  if (!event) return '';
  const icon  = EVENT_ICONS[event.type] || '•';
  const meta  = event.metadata ?? {};
  const voteBadge = meta.vote ? `<span class="badge badge-info" style="font-size:10px;">${escHtml(meta.vote)}</span>` : '';
  const targetBadge = event.target_agent_id ? `<span class="badge badge-muted" style="font-size:10px;">→ ${escHtml(event.target_agent_id)}</span>` : '';

  return `
    <div class="replay-event-card ${isCurrent ? 'replay-event-active' : ''}" id="replay-event-${event.id ?? ''}">
      <div class="replay-event-header">
        <span class="replay-event-icon">${icon}</span>
        <div class="replay-event-meta">
          <span class="replay-event-title">${escHtml(event.title ?? '')}</span>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:3px;">
            ${event.round ? `<span class="badge badge-muted" style="font-size:10px;">R${event.round}</span>` : ''}
            ${event.phase ? `<span class="badge badge-neutral" style="font-size:10px;">${escHtml(event.phase)}</span>` : ''}
            ${voteBadge}${targetBadge}
          </div>
        </div>
        <div class="replay-event-timestamp" style="font-size:10px;color:var(--text-muted);margin-left:auto;">
          ${event.timestamp ? event.timestamp.slice(0, 16).replace('T', ' ') : ''}
        </div>
      </div>
      ${event.content && isCurrent ? `<div class="replay-event-content">${escHtml(event.content)}</div>` : ''}
    </div>
  `;
}

function renderDebateReplayPanel(sessionId) {
  const { state, escHtml, t } = getCtx();
  const events      = state.replayEvents  ?? null;
  const loading     = state.replayLoading ?? false;
  const error       = state.replayError   ?? null;
  const idx         = state.replayIndex   ?? 0;
  const playing     = state.replayPlaying ?? false;
  const speed       = state.replaySpeed   ?? 1;
  const sessionMode = state.sessionHistory?.session?.mode ?? state.currentSession?.mode ?? null;

  if (loading) {
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${replayTitleRow(t, state)}</div>
      <div class="loading-state" style="padding:24px 0;"><span class="spinner"></span> ${t('replay.loading')}</div>
    </div>`;
  }

  if (error) {
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${replayTitleRow(t, state)}</div>
      <div style="padding:12px 0;color:var(--danger);font-size:13px;">
        ⚠️ ${t('replay.error')} <span style="color:var(--text-muted);font-size:11px;">${escHtml(error)}</span>
        <div style="margin-top:10px;"><button class="btn btn-secondary btn-sm" data-action="load-replay-events" data-session-id="${escHtml(sessionId)}">⟳ ${t('replay.load')}</button></div>
      </div>
    </div>`;
  }

  if (!events) {
    const isChatMode = sessionMode === 'chat';
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${replayTitleRow(t, state)}</div>
      <div class="card-description">${t('panel.replay.desc')}</div>
      <div class="card-usage">${t('panel.replay.usage')}</div>
      <div style="padding:12px 0;">
        ${isChatMode
          ? `<p style="color:var(--text-secondary);font-size:13px;margin:0;font-style:italic;">ℹ️ ${t('replay.chatModeNotice')}</p>`
          : `<p style="color:var(--text-secondary);font-size:13px;margin:0 0 12px;">${t('replay.noData')}</p>
             <button class="btn btn-secondary btn-sm" data-action="load-replay-events" data-session-id="${escHtml(sessionId)}">${t('replay.load')}</button>`
        }
      </div>
    </div>`;
  }

  if (events.length === 0) {
    return `<div class="card debate-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title">${replayTitleRow(t, state)}</div>
      <p style="color:var(--text-muted);font-size:13px;">${t('replay.noData')}</p>
    </div>`;
  }

  const currentEvent = events[idx] ?? null;
  const total = events.length;

  const timelineHtml = `
    <div class="replay-timeline">
      ${events.map((e, i) => `
        <div class="replay-dot ${i === idx ? 'replay-dot-active' : i < idx ? 'replay-dot-past' : ''}"
          data-action="replay-goto" data-index="${i}" data-session-id="${escHtml(sessionId)}"
          title="${escHtml(e.title ?? '')}"></div>
      `).join('')}
    </div>
  `;

  const speedOptions = [0.5, 1, 1.5, 2].map((s) => `
    <button class="btn btn-sm ${speed === s ? 'btn-primary' : 'btn-secondary'}" 
      data-action="replay-speed" data-speed="${s}" data-session-id="${escHtml(sessionId)}"
      style="font-size:11px;padding:3px 8px;">${s}x</button>
  `).join('');

  return `
    <div class="card debate-card replay-card" style="margin:16px 0;padding:16px 20px;">
      <div class="debate-card-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <span>${replayTitleRow(t, state)}</span>
        <button class="btn btn-secondary btn-sm" data-action="load-replay-events" data-session-id="${escHtml(sessionId)}"
          style="font-size:11px;padding:3px 8px;">⟳</button>
      </div>

      ${timelineHtml}

      <div class="replay-progress" style="font-size:12px;color:var(--text-muted);margin:8px 0;">
        ${t('replay.event')} ${idx + 1} ${t('replay.of')} ${total}
      </div>

      <div class="replay-controls">
        <button class="btn btn-secondary btn-sm" data-action="replay-reset" data-session-id="${escHtml(sessionId)}">${t('replay.reset')}</button>
        <button class="btn btn-secondary btn-sm" data-action="replay-prev" data-session-id="${escHtml(sessionId)}" ${idx === 0 ? 'disabled' : ''}>${t('replay.prev')}</button>
        ${playing
          ? `<button class="btn btn-primary btn-sm" data-action="replay-pause" data-session-id="${escHtml(sessionId)}">${t('replay.pause')}</button>`
          : `<button class="btn btn-primary btn-sm" data-action="replay-start" data-session-id="${escHtml(sessionId)}">${t('replay.play')}</button>`
        }
        <button class="btn btn-secondary btn-sm" data-action="replay-next" data-session-id="${escHtml(sessionId)}" ${idx >= total - 1 ? 'disabled' : ''}>${t('replay.next')}</button>
      </div>

      <div class="replay-speed-row" style="display:flex;align-items:center;gap:8px;margin-top:10px;">
        <span style="font-size:12px;color:var(--text-muted);">${t('replay.speed')}:</span>
        ${speedOptions}
      </div>

      <div class="replay-event-area" style="margin-top:16px;">
        ${currentEvent ? renderEventCard(currentEvent, true) : ''}
      </div>

      <div class="replay-event-list" style="margin-top:12px;max-height:280px;overflow-y:auto;">
        ${events.map((e, i) => i !== idx ? renderEventCard(e, false) : '').join('')}
      </div>
    </div>
  `;
}

function registerDebateReplayFeature() {
  if (!window.DecisionArena.views.shared) window.DecisionArena.views.shared = {};
  window.DecisionArena.views.shared.renderDebateReplayPanel = renderDebateReplayPanel;
}

export { registerDebateReplayFeature, renderDebateReplayPanel };

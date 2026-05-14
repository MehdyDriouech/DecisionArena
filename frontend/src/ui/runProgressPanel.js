function formatElapsed(seconds) {
  const safe = Math.max(0, Number(seconds || 0));
  const mm = String(Math.floor(safe / 60)).padStart(2, '0');
  const ss = String(Math.floor(safe % 60)).padStart(2, '0');
  return `${mm}:${ss}`;
}

function relativeSecondsSince(isoTs) {
  if (!isoTs) return null;
  const ts = new Date(isoTs).getTime();
  if (!Number.isFinite(ts)) return null;
  return Math.max(0, Math.floor((Date.now() - ts) / 1000));
}

function eventTimeDelta(startedAt, eventTs) {
  if (!startedAt || !eventTs) return '00:00';
  const startMs = new Date(startedAt).getTime();
  const eventMs = new Date(eventTs).getTime();
  if (!Number.isFinite(startMs) || !Number.isFinite(eventMs) || eventMs < startMs) return '00:00';
  return formatElapsed(Math.floor((eventMs - startMs) / 1000));
}

function modeLabel(mode, t) {
  const byMode = {
    confrontation: t('mode.confrontation'),
    'decision-room': t('mode.decisionRoom'),
    jury: t('jury.title'),
    'stress-test': t('mode.stressTest'),
    'quick-decision': t('mode.quickDecision'),
  };
  return byMode[String(mode || '')] || String(mode || '');
}

function teamLabel(team, t) {
  if (!team) return '—';
  if (String(team).toLowerCase() === 'blue') return t('runProgress.teamBlue');
  if (String(team).toLowerCase() === 'red') return t('runProgress.teamRed');
  return String(team);
}

function pollingLabel(meta, t) {
  if (!meta || !meta.active) return t('runProgress.pollingStopped');
  if (meta.error) return `${t('runProgress.pollingError')}: ${meta.error}`;
  return `${t('runProgress.pollingOk')} (${meta.intervalMs || 1500}ms)`;
}

function computeEstimatedPercent(status, progress) {
  const s = String(status || '').toLowerCase();
  if (s === 'completed') return 100;
  if (s === 'failed' || s === 'error') return 0;
  // Hors run actif : ne pas afficher ~100 % sur un pourcentage persistant ou erroné côté API.
  if (s !== 'running') {
    const cr = Number(progress?.current_round || 0);
    const tr = Number(progress?.total_rounds || 0);
    if (cr <= 0) return 0;
    return Math.min(99, Math.max(0, Math.floor((cr / Math.max(1, tr)) * 100)));
  }
  const raw = Number(progress?.percent);
  if (Number.isFinite(raw)) return Math.max(0, Math.min(99, Math.floor(raw)));
  return Math.max(5, Math.min(90, Number(progress?.current_round) > 0 ? 40 : 5));
}

function normalizeArgs(payloadOrArgs, opts) {
  if (payloadOrArgs && payloadOrArgs.runStatus) {
    return { runStatus: payloadOrArgs.runStatus, opts: { ...opts, ...payloadOrArgs } };
  }
  return { runStatus: payloadOrArgs, opts };
}

function renderRunProgressPanel(payloadOrRunStatus, rawOpts = {}) {
  const normalized = normalizeArgs(payloadOrRunStatus, rawOpts);
  const runStatus = normalized.runStatus;
  const opts = normalized.opts || {};
  if (!runStatus) return '';

  const {
    t = (k) => k,
    escHtml = (s) => String(s ?? ''),
    uiMode = 'basic',
    polling = null,
  } = opts;

  const progress = runStatus.progress || {};
  const statusLower = String(runStatus.status || '').toLowerCase();
  const mode = String(runStatus.mode || opts.mode || '');
  const events = Array.isArray(runStatus.events) ? runStatus.events : [];
  const lastEvent = events.length ? events[events.length - 1] : null;
  const staleness = runStatus.staleness && typeof runStatus.staleness === 'object' ? runStatus.staleness : {};
  const lastEventTs = staleness.last_event_at || lastEvent?.ts || runStatus.updated_at || null;
  const noEventSinceSec = relativeSecondsSince(lastEventTs);
  const secSinceEvt = staleness.seconds_since_last_event != null
    ? Number(staleness.seconds_since_last_event)
    : (noEventSinceSec != null ? noEventSinceSec : 0);
  const stalenessLevel = String(staleness.level || 'normal');
  const stalenessMsg = (staleness.message && statusLower === 'running') ? String(staleness.message) : '';
  const stalenessLlmMsg = (staleness.llm_message && statusLower === 'running') ? String(staleness.llm_message) : '';
  const currentLlm = runStatus.current_llm_call && typeof runStatus.current_llm_call === 'object'
    ? runStatus.current_llm_call
    : { active: false };
  const fin = runStatus.run_finalization && typeof runStatus.run_finalization === 'object'
    ? runStatus.run_finalization
    : { lines: [] };
  const finLines = Array.isArray(fin.lines) ? fin.lines : [];
  const diag = runStatus.run_timeout_diagnostics && typeof runStatus.run_timeout_diagnostics === 'object'
    ? runStatus.run_timeout_diagnostics
    : null;
  const wallElapsed = Number(runStatus.elapsed_wall_seconds ?? runStatus.elapsed_seconds ?? 0);
  const pollSec = polling?.lastUpdateAt != null
    ? Math.max(0, Math.floor((Date.now() - Number(polling.lastUpdateAt)) / 1000))
    : null;

  const panelTitle = statusLower === 'completed'
    ? t('runProgress.titleCompleted')
    : (statusLower === 'failed' || statusLower === 'blocked')
      ? t('runProgress.titleTerminalIssue')
      : t('runProgress.running');
  const currentRound = Number(progress.current_round || 0);
  const totalRounds = Number(progress.total_rounds || 0);
  const phaseHuman = progress.current_phase_label || progress.current_phase || t('runProgress.unknownPhase');
  const currentAgent = progress.current_agent_name || progress.current_agent_id || '—';
  const currentTeam = progress.current_team || null;
  const elapsedWallDisplay = formatElapsed(wallElapsed);
  const estimatedPercent = computeEstimatedPercent(String(runStatus.status || '').toLowerCase(), progress);
  const modeLine = [
    modeLabel(mode, t),
    totalRounds > 0 ? `${t('runProgress.round')} ${currentRound}/${totalRounds}` : '',
    String(phaseHuman || ''),
  ].filter(Boolean).join(' · ');
  const basicLastEvent = lastEvent?.label || lastEvent?.phase || t('runProgress.noEventsYet');
  const stalenessClass = (() => {
    switch (stalenessLevel) {
      case 'quiet': return 'run-progress-stale-quiet';
      case 'long': return 'run-progress-stale-long';
      case 'possibly_stuck': return 'run-progress-stale-stuck';
      case 'timeout': return 'run-progress-stale-timeout';
      default: return '';
    }
  })();
  const stalenessHtml = [
    stalenessMsg ? `<div class="run-progress-stale-msg ${stalenessClass}">${escHtml(stalenessMsg)}</div>` : '',
    stalenessLlmMsg ? `<div class="run-progress-info">${escHtml(stalenessLlmMsg)}</div>` : '',
  ].filter(Boolean).join('');
  const finBlock = finLines.length && statusLower === 'running'
    ? `<div class="run-progress-finalization">${finLines.map((ln) => `<div class="run-progress-info">${escHtml(ln)}</div>`).join('')}</div>`
    : '';
  const llmActiveLine = currentLlm.active
    ? `<div>${t('runProgress.activeLlm')}: <strong>${formatElapsed(Number(currentLlm.seconds_active || 0))}</strong></div>`
    : '';
  const journalLine = `<div>${t('runProgress.lastJournalAgo')}: <strong>${secSinceEvt}s</strong></div>`;
  const pollLine = `<div>${t('runProgress.lastPollOk')}: <strong>${pollSec != null ? `${pollSec}s` : '—'}</strong></div>`;
  const expertDiag = (diag && uiMode === 'expert')
    ? `
          <div class="run-progress-row">
            <span>${t('runProgress.expertProvider')}: <strong>${escHtml(diag.provider_id || '—')}</strong></span>
            <span>${t('runProgress.expertModel')}: <strong>${escHtml(diag.model || '—')}</strong></span>
          </div>
          <div class="run-progress-row">
            <span>${t('runProgress.expertWall')}: <strong>${Number(diag.wall_seconds || 0)}s</strong></span>
            <span>${t('runProgress.expertRemaining')}: <strong>${formatElapsed(Number(diag.remaining_wall_seconds || 0))}</strong></span>
          </div>
        `
    : '';

  const expertSection = uiMode === 'expert'
    ? `
      <details class="run-progress-expert-details" open>
        <summary>${t('runProgress.runtimeLog')}</summary>
        <div class="run-progress-expert">
          <div class="run-progress-row">
            <span>${t('runProgress.sessionId')}: <strong>${escHtml(runStatus.session_id || opts.sessionId || '—')}</strong></span>
            <span>${t('runProgress.polling')}: <strong>${escHtml(pollingLabel(polling, t))}</strong></span>
            <span>${t('runProgress.eventsCount')}: <strong>${events.length}</strong></span>
          </div>
          <div class="run-progress-row">
            <span>${t('runProgress.lastUpdate')}: <strong>${runStatus.updated_at ? escHtml(runStatus.updated_at) : '—'}</strong></span>
            <span>${t('runProgress.rawPhase')}: <strong>${escHtml(progress.current_phase || '—')}</strong></span>
            <span>${t('runProgress.rawStep')}: <strong>${escHtml(progress.current_step || '—')}</strong></span>
          </div>
          <div class="run-progress-row">
            <span>${t('runProgress.currentTeam')}: <strong>${escHtml(teamLabel(currentTeam, t))}</strong></span>
            <span>${t('runProgress.currentAgent')}: <strong>${escHtml(String(currentAgent))}</strong></span>
            <span>${t('runProgress.lastMessageAt')}: <strong>${escHtml(runStatus.last_message_at || '—')}</strong></span>
          </div>
          ${expertDiag}
          ${runStatus.last_error ? `<div class="run-progress-warning">⚠️ ${escHtml(String(runStatus.last_error))}</div>` : ''}
          <div class="run-progress-log">
            ${(events.map((evt) => `
              <div class="run-progress-log-line">
                <span class="run-progress-log-ts">${eventTimeDelta(runStatus.started_at, evt.ts)}</span>
                <span class="run-progress-log-msg">${escHtml(evt.label || evt.phase || t('runProgress.eventObserved'))}</span>
              </div>
            `).join('')) || `<div class="run-progress-log-line"><span class="run-progress-log-msg">${t('runProgress.noEventsYet')}</span></div>`}
          </div>
        </div>
      </details>
    `
    : '';

  return `
    <div class="run-progress-panel">
      <div class="run-progress-title">${escHtml(panelTitle)}</div>
      <div class="run-progress-subtitle">${escHtml(modeLine || t('runProgress.running'))}</div>
      <div class="run-progress-kpis">
        <div>${t('runProgress.currentAgent')}: <strong>${escHtml(String(currentAgent))}</strong></div>
        ${mode === 'confrontation' ? `<div>${t('runProgress.currentTeam')}: <strong>${escHtml(teamLabel(currentTeam, t))}</strong></div>` : ''}
        <div>${t('runProgress.elapsedWall')}: <strong>${elapsedWallDisplay}</strong></div>
        ${journalLine}
        ${pollLine}
        ${llmActiveLine}
      </div>
      <div class="run-progress-bar-wrap">
        <div class="run-progress-bar" style="width:${estimatedPercent}%;"></div>
      </div>
      <div class="run-progress-percent">${t('runProgress.estimatedProgress')}: ${estimatedPercent}%</div>
      <div class="run-progress-last-event">${t('runProgress.lastEvent')}: ${escHtml(String(basicLastEvent))}</div>
      ${stalenessHtml}
      ${finBlock}
      ${runStatus.last_error ? `<div class="run-progress-warning">⚠️ ${escHtml(String(runStatus.last_error))}</div>` : ''}
      ${expertSection}
    </div>
  `;
}

export { renderRunProgressPanel, formatElapsed, eventTimeDelta, teamLabel };

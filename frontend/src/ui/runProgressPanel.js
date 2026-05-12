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
  const mode = String(runStatus.mode || opts.mode || '');
  const events = Array.isArray(runStatus.events) ? runStatus.events : [];
  const lastEvent = events.length ? events[events.length - 1] : null;
  const currentRound = Number(progress.current_round || 0);
  const totalRounds = Number(progress.total_rounds || 0);
  const phaseHuman = progress.current_phase_label || progress.current_phase || t('runProgress.unknownPhase');
  const currentAgent = progress.current_agent_name || progress.current_agent_id || '—';
  const currentTeam = progress.current_team || null;
  const elapsed = formatElapsed(runStatus.elapsed_seconds || 0);
  const estimatedPercent = computeEstimatedPercent(String(runStatus.status || '').toLowerCase(), progress);
  const lastEventTs = lastEvent?.ts || runStatus.updated_at || null;
  const noEventSinceSec = relativeSecondsSince(lastEventTs);
  const modeLine = [
    modeLabel(mode, t),
    totalRounds > 0 ? `${t('runProgress.round')} ${currentRound}/${totalRounds}` : '',
    String(phaseHuman || ''),
  ].filter(Boolean).join(' · ');
  const basicLastEvent = lastEvent?.label || lastEvent?.phase || t('runProgress.noEventsYet');
  const basicSlowProvider = noEventSinceSec != null && noEventSinceSec > 60
    ? `<div class="run-progress-info">⏳ ${t('runProgress.providerSlow')}</div>`
    : '';
  const expertStaleWarning = noEventSinceSec != null && noEventSinceSec > 180
    ? `<div class="run-progress-warning">⚠️ ${t('runProgress.stale180')}</div>`
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
          ${runStatus.last_error ? `<div class="run-progress-warning">⚠️ ${escHtml(String(runStatus.last_error))}</div>` : ''}
          ${expertStaleWarning}
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
      <div class="run-progress-title">${t('runProgress.running')}</div>
      <div class="run-progress-subtitle">${escHtml(modeLine || t('runProgress.running'))}</div>
      <div class="run-progress-kpis">
        <div>${t('runProgress.currentAgent')}: <strong>${escHtml(String(currentAgent))}</strong></div>
        ${mode === 'confrontation' ? `<div>${t('runProgress.currentTeam')}: <strong>${escHtml(teamLabel(currentTeam, t))}</strong></div>` : ''}
        <div>${t('runProgress.elapsed')}: <strong>${elapsed}</strong></div>
      </div>
      <div class="run-progress-bar-wrap">
        <div class="run-progress-bar" style="width:${estimatedPercent}%;"></div>
      </div>
      <div class="run-progress-percent">${t('runProgress.estimatedProgress')}: ${estimatedPercent}%</div>
      <div class="run-progress-last-event">${t('runProgress.lastEvent')}: ${escHtml(String(basicLastEvent))}</div>
      ${basicSlowProvider}
      ${runStatus.last_error ? `<div class="run-progress-warning">⚠️ ${escHtml(String(runStatus.last_error))}</div>` : ''}
      ${expertSection}
    </div>
  `;
}

export { renderRunProgressPanel, formatElapsed, eventTimeDelta, teamLabel };

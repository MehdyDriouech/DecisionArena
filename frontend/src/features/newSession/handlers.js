/* New Session feature — action handlers, change listeners */
import { registerAction, registerChangeListener, registerInputListener } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:             a.store.state,
    render:            () => a.render?.(),
    navigate:          (v) => a.router.navigate(v),
    SessionService:    a.services.SessionService,
    ContextDocService: a.services.ContextDocService,
    t:                 (key) => window.i18n?.t(key) ?? key,
  };
}

function resetNewSessionState() {
  return {
    title: '', idea: '', mode: 'chat', selectedAgents: [], rounds: 3,
    language: 'fr',
    blueTeam: ['pm', 'architect', 'po', 'ux-expert'],
    redTeam: ['analyst', 'critic'],
    includeSynthesis: true,
    cfRounds: 3, cfStyle: 'sequential', cfReplyPolicy: 'all-agents-reply',
    forceDisagreement: false,
    ctxDocEnabled: false, ctxDocTab: 'manual', ctxDocTitle: '', ctxDocContent: '',
    ctxDocDraftSaved: false, ctxDocDraftSummary: null,
    devilAdvocateEnabled: false,
    devilAdvocateThreshold: 0.65,
    agentProviders: {},
    fastDecisionEnabled: true,
    // LLM Assignment
    llmAssignmentMode: 'global',
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
  };
}

let _contextCheckTimer = null;

function _debouncedContextCheck(text, state) {
  clearTimeout(_contextCheckTimer);
  const trimmed = text.trim();
  if (trimmed.length < 20) {
    state.newSession.contextHintQuestions = null;
    const container = document.getElementById('context-hint-banner-container');
    if (container) container.innerHTML = '';
    return;
  }
  _contextCheckTimer = setTimeout(async () => {
    try {
      const res = await fetch('/api/context/check', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ objective: trimmed }),
      });
      if (!res.ok) return;
      const data = await res.json();
      const questions = data.questions || [];
      const level = data.analysis?.level || 'weak';
      state.newSession.contextHintQuestions = questions.length > 0 ? questions : null;
      const container = document.getElementById('context-hint-banner-container');
      if (!container) return;
      if (!questions.length || ['strong', 'medium'].includes(level)) {
        container.innerHTML = '';
        return;
      }
      const t = (key) => window.i18n?.t(key) ?? key;
      const items = questions.slice(0, 3).map((q) => `<li style="margin-bottom:4px;">${q.fallback || ''}</li>`).join('');
      container.innerHTML = `
        <div style="margin-top:8px;padding:12px 14px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);border-radius:6px;font-size:12px;color:var(--text-secondary);">
          <div style="font-weight:600;color:#d97706;margin-bottom:6px;">⚠ ${t('context.hint.weak')}</div>
          <div style="color:var(--text-muted);margin-bottom:6px;">${t('context.hint.expand')}</div>
          <ul style="margin:0 0 8px;padding-left:18px;">${items}</ul>
        </div>
      `;
    } catch (_) {}
  }, 800);
}

function registerNewSessionHandlers() {
  registerAction('goto-new-session', () => getCtx().navigate('new-session'));

  registerAction('toggle-agent', ({ element }) => {
    const { state, render } = getCtx();
    const agentId = element.dataset.agentId;
    const idx = state.newSession.selectedAgents.indexOf(agentId);
    if (idx >= 0) state.newSession.selectedAgents.splice(idx, 1);
    else state.newSession.selectedAgents.push(agentId);
    render();
  });

  registerAction('toggle-blue-team', ({ element }) => {
    const { state, render } = getCtx();
    const agentId = element.dataset.agentId;
    if (state.view === 'confrontation' && state.currentSession) {
      const team = state.currentSession._blueTeam || [];
      const idx  = team.indexOf(agentId);
      if (idx >= 0) team.splice(idx, 1); else team.push(agentId);
      state.currentSession._blueTeam = team;
    } else {
      const idx = state.newSession.blueTeam.indexOf(agentId);
      if (idx >= 0) state.newSession.blueTeam.splice(idx, 1);
      else state.newSession.blueTeam.push(agentId);
    }
    render();
  });

  registerAction('toggle-red-team', ({ element }) => {
    const { state, render } = getCtx();
    const agentId = element.dataset.agentId;
    if (state.view === 'confrontation' && state.currentSession) {
      const team = state.currentSession._redTeam || [];
      const idx  = team.indexOf(agentId);
      if (idx >= 0) team.splice(idx, 1); else team.push(agentId);
      state.currentSession._redTeam = team;
    } else {
      const idx = state.newSession.redTeam.indexOf(agentId);
      if (idx >= 0) state.newSession.redTeam.splice(idx, 1);
      else state.newSession.redTeam.push(agentId);
    }
    render();
  });

  registerAction('select-language', ({ element }) => {
    const { state, render } = getCtx();
    state.newSession.language = element.dataset.lang;
    render();
  });

  registerAction('launch-session', async () => {
    const { state, render, navigate, SessionService, ContextDocService, t } = getCtx();
    const ns = state.newSession;

    const isFastMode = ns.mode === 'decision-room' && ns.fastDecisionEnabled !== false;
    if (!ns.title.trim()) {
      state.error = 'Veuillez saisir un titre de session.';
      render(); return;
    }
    if (ns.mode === 'confrontation') {
      if (ns.blueTeam.length === 0 || ns.redTeam.length === 0) {
        state.error = 'Veuillez sélectionner au moins un agent dans chaque équipe.';
        render(); return;
      }
    } else if (!isFastMode && ns.selectedAgents.length === 0) {
      state.error = 'Veuillez sélectionner au moins un agent.';
      render(); return;
    }

    try {
      state.isLoading = true;
      state.error     = null;
      state.followUpMessages = [];
      render();

      const allAgents = ns.mode === 'confrontation'
        ? [...new Set([...ns.blueTeam, ...ns.redTeam])]
        : isFastMode ? ['pm', 'architect', 'ux-expert', 'critic']
        : ns.selectedAgents;

      const body = {
        title:           ns.title.trim(),
        initial_prompt:  ns.idea.trim(),
        mode:            ns.mode,
        selected_agents: allAgents,
        rounds: ns.mode === 'decision-room' ? ns.rounds
              : ns.mode === 'quick-decision' ? 1
              : ns.mode === 'stress-test'    ? ns.rounds
              : ns.mode === 'jury'           ? ns.rounds
              : undefined,
        language:              ns.language,
        cf_rounds:             ns.mode === 'confrontation' ? ns.cfRounds      : undefined,
        cf_interaction_style:  ns.mode === 'confrontation' ? ns.cfStyle       : undefined,
        cf_reply_policy:       ns.mode === 'confrontation' ? ns.cfReplyPolicy : undefined,
        force_disagreement:    ['decision-room', 'confrontation', 'quick-decision', 'stress-test', 'jury'].includes(ns.mode)
                               ? (ns.forceDisagreement ? 1 : 0)
                               : (ns.mode === 'stress-test' ? 1 : 0),
        decision_threshold:    ['decision-room', 'confrontation', 'quick-decision', 'stress-test', 'jury'].includes(ns.mode)
                               ? (ns.juryThreshold || 0.55)
                               : undefined,
        // Feature 3 — Devil's Advocate
        devil_advocate_enabled:   ns.devilAdvocateEnabled ? 1 : 0,
        devil_advocate_threshold: ns.devilAdvocateThreshold || 0.65,
        // LLM Assignment — build agent_providers based on current mode
        ..._buildLlmPayload(ns),
        ...(isFastMode ? {
          rounds: 2, force_disagreement: 1,
          auto_retry_on_weak_debate: 1, auto_block_low_quality: 1,
          debate_intensity: 'high',
        } : {}),
      };

      const session = await SessionService.create(body);

      if (ns.mode === 'confrontation') {
        session._blueTeam        = ns.blueTeam.slice();
        session._redTeam         = ns.redTeam.slice();
        session._includeSynthesis = ns.includeSynthesis;
      }

      state.currentSession    = session;
      state.currentMessages   = [];
      state.drResults         = null;
      state.confrontationResults = null;
      state.qdResults         = null;
      state.currentContextDoc = null;
      state.ctxDocPanelOpen   = false;
      state.ctxDocEditor      = null;
      state.sessions.unshift(session);

      if (ns.ctxDocEnabled) {
        if (ns.ctxDocTab === 'manual' && ns.ctxDocContent.trim()) {
          try {
            const res = await ContextDocService.saveManual(session.id, ns.ctxDocTitle, ns.ctxDocContent);
            state.currentContextDoc = res.context_document || null;
            if (res.warning) state.error = res.warning;
          } catch (err) { state.error = err.message; }
        } else if (ns.ctxDocTab === 'upload') {
          const fileInput = document.getElementById('ctx-doc-file');
          if (fileInput?.files[0]) {
            try {
              const res = await ContextDocService.upload(session.id, ns.ctxDocTitle, fileInput.files[0]);
              state.currentContextDoc = res.context_document || null;
              if (res.warning) state.error = res.warning;
            } catch (err) { state.error = err.message; }
          } else {
            state.error = t('contextDoc.selectFile');
          }
        }
      }

      state.newSession = resetNewSessionState();

      if (ns.mode === 'decision-room') {
        navigate('decision-room');
        const { registerDecisionRoomHandlers: _, runDecisionRoom } = window.DecisionArena._handlers?.decisionRoom || {};
        if (runDecisionRoom) await runDecisionRoom();
        else await window.DecisionArena._runDecisionRoom?.();
      } else if (ns.mode === 'confrontation') {
        navigate('confrontation');
      } else if (ns.mode === 'quick-decision') {
        navigate('quick-decision');
      } else if (ns.mode === 'stress-test') {
        state.stResults = null; state.stRunning = false;
        navigate('stress-test');
      } else if (ns.mode === 'jury') {
        state.juryResults  = null;
        state.juryRunning  = false;
        state.heatmapData  = null;
        state.replayEvents = null;
        state.auditData    = null;
        navigate('jury');
      } else {
        navigate('chat');
      }
    } catch (err) {
      state.error = 'Failed to create session: ' + err.message;
      render();
    } finally {
      state.isLoading = false;
    }
  });

  registerAction('use-template', ({ element }) => {
    const { state, navigate } = getCtx();
    const templateId = element.dataset.templateId;
    const template   = state.templates.find((tmpl) => tmpl.id === templateId);
    if (!template) return;
    _applyTemplate(state, template);
    navigate('new-session');
  });

  /* ── change listeners for new-session fields ─────────────────────────── */
  registerChangeListener((e) => {
    const { state, render } = getCtx();
    const field = e.target.dataset.field;
    if (!field) return false;
    if (field === 'mode') {
      state.newSession.mode = e.target.value;
      render();
    } else if (field === 'rounds') {
      state.newSession.rounds = parseInt(e.target.value, 10);
      const label = document.querySelector('label[for="ns-rounds"]');
      if (label) label.textContent = `${window.i18n?.t('newSession.rounds') ?? 'Rounds'} (${state.newSession.rounds})`;
    } else if (field === 'includeSynthesis') {
      if (state.view === 'confrontation' && state.currentSession) {
        state.currentSession._includeSynthesis = e.target.checked;
      } else {
        state.newSession.includeSynthesis = e.target.checked;
      }
    } else if (field === 'forceDisagreement') {
      state.newSession.forceDisagreement = e.target.checked;
    } else if (field === 'title') {
      state.newSession.title = e.target.value;
    } else if (field === 'idea') {
      state.newSession.idea = e.target.value;
    }
    return true;
  });

  /* cfField change/input */
  registerChangeListener((e) => {
    const cfField = e.target.dataset.cfField;
    if (!cfField) return false;
    const { state, render } = getCtx();
    if (e.target.type === 'radio') { state.newSession[cfField] = e.target.value; render(); }
    else if (e.target.tagName === 'SELECT') { state.newSession[cfField] = e.target.value; }
    return true;
  });

  registerInputListener((e) => {
    const cfField = e.target.dataset.cfField;
    if (!cfField) return false;
    const { state } = getCtx();
    if (e.target.type === 'range') {
      state.newSession[cfField] = parseInt(e.target.value, 10);
      const label = document.querySelector('label[for="cf-rounds"]');
      if (label) label.textContent = `${window.i18n?.t('confrontation.rounds') ?? 'Rounds'} (${state.newSession.cfRounds})`;
    }
    return true;
  });

  /* data-field input (title/idea/rounds slider in new-session) */
  registerInputListener((e) => {
    const field = e.target.dataset.field;
    if (!field) return false;
    const { state, render } = getCtx();
    if (field === 'title') { state.newSession.title = e.target.value; return true; }
    if (field === 'idea')  {
      state.newSession.idea = e.target.value;
      _debouncedContextCheck(e.target.value, state);
      return true;
    }
    if (field === 'rounds') {
      state.newSession.rounds = parseInt(e.target.value, 10);
      const label = document.querySelector('label[for="ns-rounds"]');
      if (label) label.textContent = `${window.i18n?.t('newSession.rounds') ?? 'Rounds'} (${state.newSession.rounds})`;
      return true;
    }
    if (field === 'juryThreshold') {
      state.newSession.juryThreshold = parseFloat(e.target.value);
      const val = document.getElementById('jury-threshold-val');
      if (val) val.textContent = state.newSession.juryThreshold.toFixed(2);
      return true;
    }
    return false;
  });
}

function _applyTemplate(state, template) {
  const ns = state.newSession;
  ns.mode             = template.mode || 'decision-room';
  ns.selectedAgents   = [...(template.selected_agents || [])];
  ns.rounds           = template.rounds || 2;
  ns.forceDisagreement = !!template.force_disagreement;
  ns.cfStyle          = template.interaction_style || 'sequential';
  ns.cfReplyPolicy    = template.reply_policy || 'all-agents-reply';
  ns.includeSynthesis = template.final_synthesis !== false;
  ns.cfRounds         = template.rounds || 3;
  if (template.mode === 'confrontation') {
    const agents    = template.selected_agents || [];
    const nonSynth  = agents.filter((a) => a !== 'synthesizer');
    const half      = Math.ceil(nonSynth.length / 2);
    ns.blueTeam     = nonSynth.slice(0, half);
    ns.redTeam      = nonSynth.slice(half);
  }
  if (!ns.idea && template.prompt_starter) ns.idea = template.prompt_starter;
}

/** Apply a scenario pack's prefill to newSession state — does NOT create a session. */
function _applyScenarioPack(state, pack) {
  const ns = state.newSession;
  ns.mode              = pack.recommended_mode || 'decision-room';
  ns.selectedAgents    = [...(pack.persona_ids || [])];
  ns.rounds            = pack.rounds || 2;
  ns.juryThreshold     = typeof pack.decision_threshold === 'number' ? pack.decision_threshold : 0.55;
  ns.forceDisagreement = !!pack.force_disagreement;
  ns.cfRounds          = pack.rounds || 3;
  if (ns.mode === 'confrontation') {
    const agents   = pack.persona_ids || [];
    const nonSynth = agents.filter((a) => a !== 'synthesizer');
    const half     = Math.ceil(nonSynth.length / 2);
    ns.blueTeam    = nonSynth.slice(0, half);
    ns.redTeam     = nonSynth.slice(half);
  }
  if (!ns.idea && pack.prompt_starter) ns.idea = pack.prompt_starter;
  ns.selectedScenarioId = pack.id;
}

function registerScenarioHandlers() {
  registerAction('select-template', ({ element }) => {
    const { state, render } = getCtx();
    const tplId = element?.dataset?.templateId;
    state.newSession.selectedTemplateId = tplId || null;
    if (!tplId) {
      state.newSession.fastDecisionEnabled = false;
      render();
      return;
    }
    const pack = (state.scenarioPacks || []).find((p) => p.id === tplId);
    if (!pack) { render(); return; }
    _applyScenarioPack(state, pack);
    render();
    requestAnimationFrame(() => {
      const card = document.querySelector('.card[style*="max-width:1100px"]');
      card?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  registerAction('apply-scenario', ({ element }) => {
    const { state, render } = getCtx();
    const packId = element?.dataset?.scenarioId;
    if (!packId) return;

    // Toggle off if already selected
    if (state.newSession.selectedScenarioId === packId) {
      state.newSession.selectedScenarioId = null;
      render();
      return;
    }

    const pack = (state.scenarioPacks || []).find((p) => p.id === packId);
    if (!pack) return;
    _applyScenarioPack(state, pack);
    render();

    // Smooth-scroll down to the config form so user sees the prefilled values
    requestAnimationFrame(() => {
      const card = document.querySelector('.card[style*="max-width:1100px"]');
      card?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  registerAction('clear-scenario', ({ }) => {
    const { state, render } = getCtx();
    state.newSession.selectedScenarioId = null;
    render();
  });

  /* ══════════════════════════════════════════════════════════════════════
     Feature 3 — Devil's Advocate toggle + threshold
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('toggle-devil-advocate', ({ element }) => {
    const { state, render } = getCtx();
    state.newSession.devilAdvocateEnabled = !!element.checked;
    render();
  });

  registerAction('change-da-threshold', ({ element }) => {
    const { state } = getCtx();
    const val = parseFloat(element.value || 0.65);
    state.newSession.devilAdvocateThreshold = val;
    const label = document.getElementById('ns-da-threshold-val');
    if (label) label.textContent = Math.round(val * 100) + '%';
  });

  registerAction('ns-fast-customize', () => {
    const { state, render } = getCtx();
    state.newSession.fastDecisionEnabled = false;
    render();
  });

  /* ══════════════════════════════════════════════════════════════════════
     LLM Assignment — mode toggle + team + per-agent
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('set-llm-assignment-mode', ({ element }) => {
    const { state, render } = getCtx();
    const mode = element.dataset.mode;
    if (!mode) return;
    state.newSession.llmAssignmentMode = mode;
    render();
  });

  registerAction('set-team-provider', ({ element }) => {
    const { state } = getCtx();
    const team = element.dataset.team;
    if (!team) return;
    state.newSession.teamProviderAssignments = state.newSession.teamProviderAssignments || {};
    state.newSession.teamProviderAssignments[team] = state.newSession.teamProviderAssignments[team] || {};
    state.newSession.teamProviderAssignments[team].provider_id = element.value;
  });

  registerAction('set-team-model', ({ element }) => {
    const { state } = getCtx();
    const team = element.dataset.team;
    if (!team) return;
    state.newSession.teamProviderAssignments = state.newSession.teamProviderAssignments || {};
    state.newSession.teamProviderAssignments[team] = state.newSession.teamProviderAssignments[team] || {};
    state.newSession.teamProviderAssignments[team].model = element.value;
  });

  registerAction('set-agent-provider', ({ element }) => {
    const { state } = getCtx();
    const agentId = element.dataset.agentId;
    if (!agentId) return;
    state.newSession.agentProviders = state.newSession.agentProviders || {};
    state.newSession.agentProviders[agentId] = state.newSession.agentProviders[agentId] || {};
    state.newSession.agentProviders[agentId].provider_id = element.value;
  });

  registerAction('set-agent-model', ({ element }) => {
    const { state } = getCtx();
    const agentId = element.dataset.agentId;
    if (!agentId) return;
    state.newSession.agentProviders = state.newSession.agentProviders || {};
    state.newSession.agentProviders[agentId] = state.newSession.agentProviders[agentId] || {};
    state.newSession.agentProviders[agentId].model = element.value;
  });
}

/**
 * Build the LLM-related fields for the POST /api/sessions payload.
 * Returns partial body object: { agent_providers, team_provider_assignments, blue_team_agents, red_team_agents }
 */
function _buildLlmPayload(ns) {
  const mode = ns.llmAssignmentMode || 'global';

  if (mode === 'global') {
    return {};
  }

  if (mode === 'team') {
    const tpa = ns.teamProviderAssignments || {};
    const hasBlue = tpa.blue?.provider_id;
    const hasRed  = tpa.red?.provider_id;
    if (!hasBlue && !hasRed) return {};
    return {
      team_provider_assignments: {
        blue: { provider_id: tpa.blue?.provider_id || '', model: tpa.blue?.model || '' },
        red:  { provider_id: tpa.red?.provider_id  || '', model: tpa.red?.model  || '' },
      },
      blue_team_agents: ns.blueTeam || [],
      red_team_agents:  ns.redTeam  || [],
    };
  }

  if (mode === 'agent') {
    const ap = ns.agentProviders || {};
    const filtered = {};
    Object.entries(ap).forEach(([agId, ov]) => {
      if (ov.provider_id) filtered[agId] = ov;
    });
    return Object.keys(filtered).length > 0 ? { agent_providers: filtered } : {};
  }

  return {};
}

export { registerNewSessionHandlers, registerScenarioHandlers, _applyTemplate, _applyScenarioPack };

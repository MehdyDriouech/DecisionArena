/* New Session feature — action handlers, change listeners */
import { registerAction, registerChangeListener, registerInputListener, dispatchAction } from '../../core/events.js';
import { API_BASE } from '../../services/apiClient.js';
import { getAvailableProviders } from '../../core/providerRouting.js';
import {
  applyIntentPreset,
  getAvailablePersonaIdsForMode,
  resolveDefaultPresetPersonas,
  resolvePresetPersonas,
} from '../../utils/intentPresets.js';
import { ANALYSIS_CATALOG, applyAnalysisFamily } from './analysisCatalog.js';
import { applyProductPreset } from './productPresets.js';
import { getPlaybookById, isModeBackedPlaybook, isProductPlaybook } from '../../core/playbooks.js';

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

function _starterLocksConfig(ns) {
  return Boolean(
    ns.selectedScenarioId
    || (ns.selectedStarter && (ns.selectedStarter.type === 'template' || ns.selectedStarter.type === 'scenario')),
  );
}

/** Aligne titre + idée avec le texte réellement envoyé comme objectif (prompt initial). */
function _composeSessionObjectivePrompt(ns) {
  const q = String(ns.title || '').trim();
  const ctx = String(ns.idea || '').trim();
  if (q && ctx) return `Question:\n${q}\n\nContext:\n${ctx}`;
  if (ctx) return ctx;
  if (q) return `Question:\n${q}`;
  return '';
}

function _runtimePlaybookMarker(playbookId) {
  return getPlaybookById(playbookId)
    ? `## Runtime Playbook\nplaybook_id: ${playbookId}`
    : '';
}

function _defaultRoundsForMode(mode) {
  if (mode === 'quick-decision' || mode === 'chat') return 1;
  return 3;
}

/** Réinitialise agents / tours pour le mode courant (sans template ou pack verrouillé). */
function _applyCoherentDefaultsForMode(ns) {
  const mode = ns.mode;
  const available = getAvailablePersonaIdsForMode(mode);
  const defaultAgents = resolveDefaultPresetPersonas(available);

  ns.selectedStarter = null;
  ns.selectedScenarioId = null;
  ns.selectedTemplateId = null;
  ns.facilitationFramework = null;

  if (mode === 'confrontation') {
    const stress = ANALYSIS_CATALOG.stress || {};
    const blue = Array.isArray(stress.blueTeam) ? stress.blueTeam : ['pm', 'architect', 'po', 'ux-expert'];
    const red = Array.isArray(stress.redTeam) ? stress.redTeam : ['analyst', 'critic'];
    ns.blueTeam = [...blue];
    ns.redTeam = [...red];
    ns.selectedAgents = Array.from(new Set([...ns.blueTeam, ...ns.redTeam]));
  } else {
    ns.selectedAgents = defaultAgents;
  }
  ns.rounds = _defaultRoundsForMode(mode);
}

function resetNewSessionState() {
  const expert = window.DecisionArena?.store?.state?.uiMode === 'expert';
  return {
    title: '',
    idea: '',
    mode: expert ? 'chat' : 'stress-test',
    selectedPlaybookId: expert ? null : 'stress-test',
    productFamily: expert ? null : 'validate',
    productPreset: null,
    founderInterrogation: null,
    simpleIntent: expert ? 'decide' : 'test',
    selectedIntent: expert ? 'decide' : 'validate',
    selectedAgents: [],
    rounds: 3,
    language: 'fr',
    blueTeam: ['pm', 'architect', 'po', 'ux-expert'],
    redTeam: ['analyst', 'critic'],
    includeSynthesis: true,
    cfRounds: 3, cfStyle: 'sequential', cfReplyPolicy: 'all-agents-reply',
    forceDisagreement: expert ? false : true,
    ctxDocEnabled: false, ctxDocTab: 'manual', ctxDocTitle: '', ctxDocContent: '',
    ctxDocDraftSaved: false, ctxDocDraftSummary: null,
    devilAdvocateEnabled: false,
    devilAdvocateThreshold: 0.65,
    agentProviders: {},
    fastDecisionEnabled: expert,
    facilitationFramework: null,
    presetRationale: null,
    // LLM Assignment
    llmAssignmentMode: 'global',
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
    selectedStarter: null,
    // Collapsed by default to reduce “fourre-tout” on first view.
    starterModelsCollapsed: true,
    isFork: false,
    source_session_id: null,
    forkDraftSessionId: null,
    decisionDynamicsPreset: 'balanced',
    // Decision Memory reuse (manual selection only; no auto injection)
    selectedMemoryIds: [],
    memoryPicker: {
      open: false,
      loading: false,
      error: null,
      filters: { playbook_id: '', decision_status: '', confidence: '', from: '', to: '', link_type: '', q: '' },
      memories: null,
      compactPreview: null, // { allowed, blocked }
      allowStaleConfirmed: false,
      expertOverride: false,
    },
  };
}

let _contextCheckTimer = null;

function _availableSimpleAgents(mode) {
  return resolveDefaultPresetPersonas(getAvailablePersonaIdsForMode(mode));
}

function _applySimpleLaunchDefaults(ns) {
  if (ns.selectedPlaybookId && getPlaybookById(ns.selectedPlaybookId)) {
    applyDecisionPlaybook(ns.selectedPlaybookId);
    return;
  }
  if (ns.productPreset) {
    applyProductPreset(ns.productPreset);
    return;
  }
  const family = ns.productFamily && ANALYSIS_CATALOG[ns.productFamily]
    ? ns.productFamily
    : 'validate';
  applyAnalysisFamily(family);
}

function applyDecisionPlaybook(playbookId) {
  const DA = window.DecisionArena;
  if (!DA || !getPlaybookById(playbookId)) return false;
  if (isProductPlaybook(playbookId)) {
    return applyProductPreset(playbookId);
  }

  if (!isModeBackedPlaybook(playbookId)) return false;

  const state = DA.store.state;
  const ns = state.newSession || {};
  const mode = playbookId;
  const available = getAvailablePersonaIdsForMode(mode);
  const defaultAgents = resolveDefaultPresetPersonas(available);
  const setIfEmpty = (arr, fallback) => (Array.isArray(arr) && arr.length > 0 ? arr : fallback);
  const next = {
    ...ns,
    selectedPlaybookId: playbookId,
    productPreset: null,
    founderInterrogation: null,
    mode,
    productFamily: null,
    selectedIntent: null,
    simpleIntent: mode === 'quick-decision' || mode === 'jury' ? 'decide' : 'test',
    selectedStarter: null,
    selectedScenarioId: null,
    selectedTemplateId: null,
    facilitationFramework: null,
    presetRationale: null,
    fastDecisionEnabled: false,
    forceDisagreement: mode === 'quick-decision' ? !!ns.forceDisagreement : true,
    rounds: mode === 'quick-decision' ? 1 : (Number.isFinite(Number(ns.rounds)) ? Number(ns.rounds) : 3),
    llmAssignmentMode: 'global',
    agentProviders: {},
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
  };

  if (mode === 'confrontation') {
    const stress = ANALYSIS_CATALOG.stress || {};
    next.blueTeam = setIfEmpty(ns.blueTeam, Array.isArray(stress.blueTeam) ? stress.blueTeam : ['pm', 'architect', 'po', 'ux-expert']);
    next.redTeam = setIfEmpty(ns.redTeam, Array.isArray(stress.redTeam) ? stress.redTeam : ['analyst', 'critic']);
    next.selectedAgents = Array.from(new Set([...next.blueTeam, ...next.redTeam]));
    next.cfRounds = ns.cfRounds ?? 3;
  } else {
    next.selectedAgents = setIfEmpty(ns.selectedAgents, defaultAgents);
  }

  state.newSession = next;
  return true;
}

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
      const res = await fetch(API_BASE + '/api/context/check', {
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
  registerAction('toggle-starter-models', () => {
    const { state, render } = getCtx();
    state.newSession.starterModelsCollapsed = !state.newSession.starterModelsCollapsed;
    render();
  });

  /* ══════════════════════════════════════════════════════════════════════
     Decision Memory reuse (manual selection + compact preview)
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('toggle-memory-picker', () => {
    const { state, render } = getCtx();
    const mp = state.newSession.memoryPicker || (state.newSession.memoryPicker = {});
    mp.open = !mp.open;
    render();
  });

  registerAction('set-memory-filter', ({ element }) => {
    const { state, render } = getCtx();
    const key = element?.dataset?.filterKey;
    if (!key) return;
    const mp = state.newSession.memoryPicker;
    if (!mp || !mp.filters) return;
    mp.filters[key] = element.value;
    render();
  });

  registerAction('load-memory-picker', async () => {
    const { state, render } = getCtx();
    const mp = state.newSession.memoryPicker;
    if (!mp) return;
    mp.loading = true;
    mp.error = null;
    render();
    try {
      const data = await window.DecisionArena.services.DecisionMemoryService.list(150, mp.filters || {});
      mp.memories = data.memories || [];
    } catch (err) {
      mp.error = String(err.message || err);
    } finally {
      mp.loading = false;
      render();
    }
  });

  registerAction('toggle-select-memory-for-new-session', async ({ element }) => {
    const { state, render } = getCtx();
    const id = element?.dataset?.memoryId;
    if (!id) return;
    const ns = state.newSession;
    if (!Array.isArray(ns.selectedMemoryIds)) ns.selectedMemoryIds = [];
    const set = new Set(ns.selectedMemoryIds);
    if (set.has(id)) set.delete(id); else set.add(id);
    ns.selectedMemoryIds = [...set].slice(0, 5);

    // Refresh compact preview from server-truth contract
    try {
      const mp = ns.memoryPicker || {};
      const preview = await window.DecisionArena.services.DecisionMemoryService.compactWithOptions(
        ns.selectedMemoryIds,
        { allow_stale: !!mp.allowStaleConfirmed, expert_override: !!mp.expertOverride },
      );
      ns.memoryPicker.compactPreview = preview;
    } catch (_) {}
    render();
  });

  registerAction('clear-selected-memories-new-session', async () => {
    const { state, render } = getCtx();
    state.newSession.selectedMemoryIds = [];
    try {
      state.newSession.memoryPicker.compactPreview = { allowed: [], blocked: [] };
    } catch (_) {}
    render();
  });

  registerAction('confirm-allow-stale-memories', async () => {
    const { state, render } = getCtx();
    const ns = state.newSession;
    ns.memoryPicker = ns.memoryPicker || {};
    ns.memoryPicker.allowStaleConfirmed = true;
    try {
      const preview = await window.DecisionArena.services.DecisionMemoryService.compactWithOptions(
        ns.selectedMemoryIds || [],
        { allow_stale: true, expert_override: !!ns.memoryPicker.expertOverride },
      );
      ns.memoryPicker.compactPreview = preview;
    } catch (_) {}
    render();
  });

  registerAction('toggle-expert-memory-override', async () => {
    const { state, render } = getCtx();
    const ns = state.newSession;
    ns.memoryPicker = ns.memoryPicker || {};
    ns.memoryPicker.expertOverride = !ns.memoryPicker.expertOverride;
    try {
      const preview = await window.DecisionArena.services.DecisionMemoryService.compactWithOptions(
        ns.selectedMemoryIds || [],
        { allow_stale: !!ns.memoryPicker.allowStaleConfirmed, expert_override: !!ns.memoryPicker.expertOverride },
      );
      ns.memoryPicker.compactPreview = preview;
    } catch (_) {}
    render();
  });

  registerAction('set-simple-intent', ({ event, element }) => {
    const intent = event?.target?.closest('[data-intent]')?.dataset?.intent || element?.dataset?.intent;
    if (!intent) return;
    const DA = window.DecisionArena;
    applyIntentPreset(intent);
    DA.store.state.newSession.founderInterrogation = null;
    DA.render?.();
  });

  registerAction('set-analysis-family', ({ event, element }) => {
    const family = event?.target?.closest('[data-family]')?.dataset?.family || element?.dataset?.family;
    if (!family || !ANALYSIS_CATALOG[family]) return;
    applyAnalysisFamily(family);
    window.DecisionArena.store.state.newSession.founderInterrogation = null;
    window.DecisionArena.render?.();
  });

  registerAction('apply-product-preset', ({ event, element }) => {
    const presetId = event?.target?.closest('[data-product-preset]')?.dataset?.productPreset
      || element?.dataset?.productPreset;
    if (!presetId) return;
    applyProductPreset(presetId);
    window.DecisionArena.render?.();
  });

  registerAction('select-playbook', ({ event, element }) => {
    const { state, navigate } = getCtx();
    const playbookId = event?.target?.closest('[data-playbook-id]')?.dataset?.playbookId
      || element?.dataset?.playbookId;
    if (!playbookId) return;
    if (!applyDecisionPlaybook(playbookId)) return;
    if (state.view !== 'new-session') navigate('new-session');
    window.DecisionArena.render?.();
  });

  registerAction('toggle-founder-interrogation', () => {
    const { state, render } = getCtx();
    const fi = state.newSession.founderInterrogation;
    if (!fi || typeof fi !== 'object') return;
    fi.open = !fi.open;
    render();
  });

  // Backward-compatible aliases for already-rendered templates.
  registerAction('select-session-intent', ({ event, element }) => {
    const intent = event?.target?.closest('[data-intent]')?.dataset?.intent || element?.dataset?.intent;
    if (!intent) return;
    applyIntentPreset(intent);
    window.DecisionArena.render?.();
  });

  registerAction('goto-new-session', ({ element }) => {
    const { state, navigate, render } = getCtx();
    const mode = element?.dataset?.mode;
    if (mode) {
      const available = getAvailablePersonaIdsForMode(mode);
      const defaultAgents = resolveDefaultPresetPersonas(available);
      const setIfEmpty = (arr, fallback) => (Array.isArray(arr) && arr.length > 0 ? arr : fallback);

      state.newSession = {
        ...(state.newSession || {}),
        mode,
        selectedPlaybookId: isModeBackedPlaybook(mode) ? mode : null,
        productFamily: null,
        productPreset: null,
        founderInterrogation: null,
        selectedStarter: null,
        selectedScenarioId: null,
        selectedTemplateId: null,
        // Ensure shortcuts are runnable (avoid "0 agent selected" dead-end)
        selectedAgents: setIfEmpty(state.newSession?.selectedAgents, defaultAgents),
      };

      if (mode === 'confrontation') {
        const stress = ANALYSIS_CATALOG.stress || {};
        const blue = Array.isArray(stress.blueTeam) ? stress.blueTeam : ['pm', 'architect', 'po', 'ux-expert'];
        const red = Array.isArray(stress.redTeam) ? stress.redTeam : ['analyst', 'critic'];
        state.newSession.blueTeam = setIfEmpty(state.newSession.blueTeam, blue);
        state.newSession.redTeam = setIfEmpty(state.newSession.redTeam, red);
        state.newSession.selectedAgents = Array.from(new Set([...(state.newSession.blueTeam || []), ...(state.newSession.redTeam || [])]));
      }
      if (mode === 'quick-decision') {
        state.newSession.rounds = 1;
      }
    } else {
      // Expert dashboard "Nouvelle session" should start clean (no leaked scenario/template/preset UI state).
      state.newSession = resetNewSessionState();
    }
    navigate('new-session');
    render();
  });

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
    let ns = state.newSession;

    /** Variante (rerun) : session déjà créée côté API — ouvrir sans dupliquer */
    if (ns.forkDraftSessionId) {
      try {
        state.isLoading = true;
        state.error = null;
        render();
        const fake = document.createElement('button');
        fake.dataset.sessionId = ns.forkDraftSessionId;
        fake.dataset.mode = ns.mode || 'chat';
        await dispatchAction('open-session', { element: fake });
        state.newSession = resetNewSessionState();
      } catch (err) {
        state.error = err?.message || String(err);
        render();
      } finally {
        state.isLoading = false;
        render();
      }
      return;
    }

    // Mode Simple: prefill hidden advanced fields so launch never depends on expert controls.
    const isSimpleDisplay = state.uiMode === 'basic';
    const starterLocksConfig = Boolean(
      ns.selectedScenarioId
      || (ns.selectedStarter && (ns.selectedStarter.type === 'template' || ns.selectedStarter.type === 'scenario')),
    );
    if (isSimpleDisplay && !starterLocksConfig) {
      _applySimpleLaunchDefaults(ns);
      ns = state.newSession;
    } else if (isSimpleDisplay && ns.selectedAgents.length === 0 && ns.mode !== 'confrontation') {
      ns.selectedAgents = _availableSimpleAgents(ns.mode);
    }

    const isFastMode = ns.mode === 'decision-room' && ns.fastDecisionEnabled !== false;
    if (!ns.title.trim()) {
      state.error = 'Veuillez saisir un titre de session.';
      render(); return;
    }
    if (getAvailableProviders(state).length === 0) {
      state.error =
        'Aucun fournisseur LLM disponible. Configurez un serveur local (URL renseignée) ou un fournisseur cloud avec clé API dans Administration → Providers.';
      render();
      return;
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

      const fastModeAgents = isFastMode
        ? resolvePresetPersonas(['pm', 'architect', 'ux-expert', 'critic'], getAvailablePersonaIdsForMode('decision-room'))
        : [];
      const allAgents = ns.mode === 'confrontation'
        ? [...new Set([...ns.blueTeam, ...ns.redTeam])]
        : isFastMode ? fastModeAgents
        : ns.selectedAgents;

      let initialPrompt = _composeSessionObjectivePrompt(ns);
      const runtimePlaybookId = ns.productPreset || ns.selectedPlaybookId || null;
      const runtimeMarker = _runtimePlaybookMarker(runtimePlaybookId);
      if (runtimeMarker) {
        initialPrompt = `${initialPrompt}\n\n---\n\n${runtimeMarker}`;
      }
      if (ns.productPreset === 'founder-sprint') {
        const fi = ns.founderInterrogation && typeof ns.founderInterrogation === 'object' ? ns.founderInterrogation : null;
        const filled = fi ? [
          fi.pain,
          fi.icp,
          fi.statusQuo,
          fi.criticalAssumption,
          fi.wedge,
          fi.validationSignal,
        ].some((v) => String(v || '').trim() !== '') : false;

        if (filled) {
          const lines = [];
          lines.push('## Founder Interrogation Context');
          const pushIf = (labelKey, val) => {
            const v = String(val || '').trim();
            if (!v) return;
            lines.push(`${t(labelKey)}: ${v}`);
          };
          pushIf('founderInterrogation.q1.label', fi.pain);
          pushIf('founderInterrogation.q2.label', fi.icp);
          pushIf('founderInterrogation.q3.label', fi.statusQuo);
          pushIf('founderInterrogation.q4.label', fi.criticalAssumption);
          pushIf('founderInterrogation.q5.label', fi.wedge);
          pushIf('founderInterrogation.q6.label', fi.validationSignal);
          initialPrompt = `${initialPrompt}\n\n---\n\n${lines.join('\n')}`;
        }

        // Prompt overlays are runtime instructions only. UX/business playbook truth stays in core/playbooks.js.
        const overlay = t('productPreset.founderSprint.promptOverlay');
        if (overlay && !String(overlay).startsWith('productPreset.')) {
          initialPrompt = `${initialPrompt}\n\n---\n\n${overlay}`;
        }
      }
      if (ns.productPreset === 'ceo-challenge') {
        // Prompt overlays are runtime instructions only. UX/business playbook truth stays in core/playbooks.js.
        const overlay = t('productPreset.ceoChallenge.promptOverlay');
        if (overlay && !String(overlay).startsWith('productPreset.')) {
          initialPrompt = `${initialPrompt}\n\n---\n\n${overlay}`;
        }
      }

      const body = {
        title:           ns.title.trim(),
        initial_prompt:  initialPrompt,
        playbook_id:      runtimePlaybookId,
        mode:            ns.mode,
        selected_agents: allAgents,
        rounds: ns.mode === 'quick-decision'
          ? 1
          : (Number.isFinite(Number(ns.rounds)) ? Number(ns.rounds) : 2),
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
        decision_dynamics_preset: ns.decisionDynamicsPreset || 'balanced',
        ...(ns.facilitationFramework || (ns.selectedStarter?.type === 'template' && ns.selectedStarter?.id === 'six-thinking-hats')
          ? { facilitation_framework: ns.facilitationFramework || 'six-thinking-hats' }
          : {}),
        ...(ns.selectedStarter?.type === 'template' && ns.selectedStarter?.id === 'pre-mortem'
          ? { session_variant: 'premortem' }
          : {}),
        ...(isFastMode ? {
          rounds: 2, force_disagreement: 1,
          auto_retry_on_weak_debate: 1, auto_block_low_quality: 1,
          debate_intensity: 'high',
        } : {}),
        selected_memory_ids: Array.isArray(ns.selectedMemoryIds) ? ns.selectedMemoryIds : [],
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
    state.newSession.selectedStarter = { type: 'template', id: template.id };
    state.newSession.selectedScenarioId = null;
    navigate('new-session');
  });

  registerAction('select-starter', ({ event }) => {
    const { state, render } = getCtx();
    const el = event?.target?.closest?.('[data-starter-type]');
    if (!el) return;
    const type = el.dataset.starterType;
    const id = el.dataset.starterId || '';
    const ns = state.newSession;

    if (type === 'template') {
      const template = state.templates.find((tmpl) => tmpl.id === id);
      if (!template) return;
      ns.selectedStarter = { type: 'template', id };
      ns.selectedScenarioId = null;
      ns.selectedTemplateId = null;
      _applyTemplate(state, template);
      render();
      requestAnimationFrame(() => {
        document.getElementById('new-session-form-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
      return;
    }

    if (type === 'scenario') {
      const pack = (state.scenarioPacks || []).find((p) => p.id === id);
      if (!pack) return;
      ns.selectedStarter = { type: 'scenario', id };
      ns.selectedTemplateId = null;
      _applyScenarioPack(state, pack);
      render();
      requestAnimationFrame(() => {
        document.getElementById('new-session-form-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    }
  });

  /* ── change listeners for new-session fields ─────────────────────────── */
  registerChangeListener((e) => {
    const { state, render } = getCtx();
    const field = e.target.dataset.field;
    if (!field) return false;
    if (field === 'mode') {
      const ns = state.newSession;
      const locks = _starterLocksConfig(ns);
      ns.mode = e.target.value;
      ns.productFamily = null;
      ns.productPreset = null;
      ns.selectedPlaybookId = isModeBackedPlaybook(ns.mode) ? ns.mode : null;
      ns.founderInterrogation = null;
      if (!locks) {
        _applyCoherentDefaultsForMode(ns);
      }
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
    const fiField = e.target.dataset.fiField;
    if (fiField) {
      const { state } = getCtx();
      if (!state.newSession.founderInterrogation || typeof state.newSession.founderInterrogation !== 'object') {
        state.newSession.founderInterrogation = { open: true };
      }
      state.newSession.founderInterrogation[fiField] = e.target.value;
      return true;
    }
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

  registerChangeListener((e) => {
    const cid = e.target.id || '';
    if (!cid.startsWith('ns-dd-preset-select') || e.target.tagName !== 'SELECT') return false;
    const { state, render } = getCtx();
    state.newSession.decisionDynamicsPreset = e.target.value;
    render();
    return true;
  });
}

function _applyTemplate(state, template) {
  const ns = state.newSession;
  ns.productFamily = null;
  ns.productPreset = null;
  ns.selectedPlaybookId = isModeBackedPlaybook(template.mode || 'decision-room') ? (template.mode || 'decision-room') : null;
  ns.founderInterrogation = null;
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
  ns.selectedScenarioId = null;
  if (ns.mode === 'decision-room') ns.fastDecisionEnabled = false;
  ns.facilitationFramework = template.id === 'six-thinking-hats' ? 'six-thinking-hats' : null;
}

/** Apply a scenario pack's prefill to newSession state — does NOT create a session. */
function _applyScenarioPack(state, pack) {
  const ns = state.newSession;
  ns.productFamily = null;
  ns.productPreset = null;
  ns.selectedPlaybookId = isModeBackedPlaybook(pack.recommended_mode || 'decision-room') ? (pack.recommended_mode || 'decision-room') : null;
  ns.founderInterrogation = null;
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
  ns.selectedTemplateId = null;
  ns.facilitationFramework = null;
  if (ns.mode === 'decision-room') ns.fastDecisionEnabled = false;
}

function registerScenarioHandlers() {
  registerAction('select-template', ({ element }) => {
    const { state, render } = getCtx();
    const tplId = element?.dataset?.templateId;
    state.newSession.selectedTemplateId = tplId || null;
    if (!tplId) {
      state.newSession.fastDecisionEnabled = false;
      state.newSession.selectedScenarioId = null;
      state.newSession.selectedStarter = null;
      render();
      return;
    }
    const pack = (state.scenarioPacks || []).find((p) => p.id === tplId);
    if (!pack) { render(); return; }
    state.newSession.selectedStarter = { type: 'scenario', id: tplId };
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
      state.newSession.selectedStarter = null;
      render();
      return;
    }

    const pack = (state.scenarioPacks || []).find((p) => p.id === packId);
    if (!pack) return;
    state.newSession.selectedStarter = { type: 'scenario', id: packId };
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
    state.newSession.selectedStarter = null;
    state.newSession.selectedTemplateId = null;
    state.newSession.facilitationFramework = null;
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

export { registerNewSessionHandlers, registerScenarioHandlers, applyDecisionPlaybook, _applyTemplate, _applyScenarioPack, resetNewSessionState };

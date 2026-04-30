/* Launch Assistant feature — action handlers */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:                 a.store.state,
    render:                () => a.render?.(),
    navigate:              (v) => a.router.navigate(v),
    LaunchAssistantService: a.services.LaunchAssistantService,
  };
}

function registerLaunchAssistantHandlers() {
  registerAction('la-select-intent', ({ element }) => {
    const { state, render } = getCtx();
    state.launchAssistant.intent = element.dataset.intent;
    render();
  });

  registerAction('la-next-step', () => {
    const { state, render } = getCtx();
    if (state.launchAssistant.intent) { state.launchAssistant.step = 2; render(); }
  });

  registerAction('la-prev-step', () => {
    const { state, render } = getCtx();
    state.launchAssistant.step = Math.max(1, state.launchAssistant.step - 1);
    render();
  });

  registerAction('la-back-to-rec', () => {
    const { state, render } = getCtx();
    state.launchAssistant.step = 3;
    render();
  });

  registerAction('la-get-recommendation', async () => {
    const { state, render, LaunchAssistantService } = getCtx();
    const la    = state.launchAssistant;
    const descEl = document.getElementById('la-description');
    if (descEl) la.description = descEl.value;
    la.loading = true;
    render();
    try {
      const lang   = window.i18n?.getLanguage() || 'fr';
      const result = await LaunchAssistantService.recommend({
        intent:      la.intent,
        description: la.description,
        language:    lang,
      });
      la.recommendation = result;
      la.step           = 3;
    } catch (err) {
      state.error = err.message;
    } finally {
      la.loading = false;
      render();
    }
  });

  registerAction('la-launch-session',     () => _launchFromAssistant());
  registerAction('la-launch-from-edit',   () => _launchFromAssistant());

  registerAction('la-edit-recommendation', () => {
    const { state, render } = getCtx();
    const la = state.launchAssistant;
    la.editTitle = la.description.slice(0, 60) || 'Session';
    la.step      = 4;
    render();
  });
}

function _launchFromAssistant() {
  const { state, navigate } = getCtx();
  const la  = state.launchAssistant;
  const rec = la.recommendation;
  if (!rec) return;

  const titleEl = document.getElementById('la-title');
  const ideaEl  = document.getElementById('la-idea');

  state.newSession = {
    title:            titleEl?.value.trim() || la.description.slice(0, 60) || 'Session',
    idea:             ideaEl?.value.trim()  || la.description || '',
    mode:             rec.mode || 'decision-room',
    selectedAgents:   rec.selected_agents || [],
    rounds:           rec.rounds || 2,
    language:         window.i18n?.getLanguage() || 'fr',
    blueTeam:         ['pm', 'architect', 'po', 'ux-expert'],
    redTeam:          ['analyst', 'critic'],
    includeSynthesis: true,
    cfRounds:         3,
    cfStyle:          rec.interaction_style || 'sequential',
    cfReplyPolicy:    rec.reply_policy || 'all-agents-reply',
    forceDisagreement: !!rec.force_disagreement,
    ctxDocEnabled: false, ctxDocTab: 'manual', ctxDocTitle: '', ctxDocContent: '',
    ctxDocDraftSaved: false, ctxDocDraftSummary: null,
  };

  state.launchAssistant = {
    step: 1, intent: null, description: '', recommendation: null,
    loading: false, editTitle: '', editMode: null, editRounds: 2, editAgents: [],
    editForceDisagreement: true, editMode2: '',
  };

  navigate('new-session');
}

export { registerLaunchAssistantHandlers };

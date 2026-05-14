/* Confrontation feature — action handlers */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:    a.store.state,
    render:   () => a.render?.(),
    apiFetch: a.services.apiFetch,
  };
}

function registerConfrontationHandlers() {
  registerAction('run-confrontation', async () => {
    const { state, render, apiFetch } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.confrontationRunning  = true;
    state.confrontationResults  = null;
    state.error                 = null;
    render();

    try {
      const blueTeam         = session._blueTeam    || [];
      const redTeam          = session._redTeam     || [];
      const selectedAgents   = (session.selected_agents || []).filter((a) => a !== 'synthesizer');
      const includeSynthesis = session._includeSynthesis !== false;

      const result = await apiFetch('/api/confrontation/run', {
        method: 'POST',
        body: JSON.stringify({
          session_id:        session.id,
          objective:         session.idea || session.initial_prompt || session.title || '',
          selected_agents:   selectedAgents.length > 0 ? selectedAgents : [...blueTeam, ...redTeam],
          blue_team:         blueTeam,
          red_team:          redTeam,
          include_synthesis: includeSynthesis,
          final_synthesis:   includeSynthesis,
          rounds:            session.cf_rounds            || 3,
          interaction_style: session.cf_interaction_style || 'sequential',
          reply_policy:      session.cf_reply_policy      || 'all-agents-reply',
        }),
      });
      state.confrontationResults = result;
    } catch (err) {
      state.error = 'Confrontation failed: ' + err.message;
    } finally {
      state.confrontationRunning = false;
      render();
    }
  });
}

export { registerConfrontationHandlers };

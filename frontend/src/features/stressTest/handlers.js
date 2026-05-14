/* Stress Test feature — action handler */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:       a.store.state,
    render:      () => a.render?.(),
    ChatService: a.services.ChatService,
  };
}

function registerStressTestHandlers() {
  registerAction('run-stress-test', async () => {
    const { state, render, ChatService } = getCtx();
    const session = state.currentSession;
    if (!session) return;

    state.stRunning = true;
    state.stResults = null;
    state.error     = null;
    render();

    try {
      const result = await ChatService.runStressTest({
        session_id:        session.id,
        objective:         session.initial_prompt || session.title || '',
        selected_agents:   session.selected_agents || ['critic', 'architect', 'pm', 'ux-expert', 'synthesizer'],
        rounds:            session.rounds || 2,
        force_disagreement: true,
      });
      state.stResults = result;
    } catch (err) {
      state.error = 'Stress Test failed: ' + err.message;
    } finally {
      state.stRunning = false;
      render();
    }
  });
}

export { registerStressTestHandlers };

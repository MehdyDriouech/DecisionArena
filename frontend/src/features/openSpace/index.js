import { renderOpenSpaceOrchestrator } from './orchestrator.js';
import { renderOpenSpaceKanban } from './kanban.js';
import { renderOpenSpaceAgentChat } from './agentChat.js';
import { renderOpenSpaceContextSwitcher } from './shared.js';

function registerOpenSpaceFeature() {
  window.DecisionArena.views['openspace-orchestrator'] = renderOpenSpaceOrchestrator;
  window.DecisionArena.views['openspace-kanban'] = renderOpenSpaceKanban;
  window.DecisionArena.views['openspace-agent-chat'] = renderOpenSpaceAgentChat;
}

export { registerOpenSpaceFeature, renderOpenSpaceContextSwitcher };


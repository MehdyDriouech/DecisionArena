import * as store from './core/store.js';
import * as services from './services/index.js';
import * as htmlUtils from './utils/html.js';
import * as markdownUtils from './utils/markdown.js';
import * as dateUtils from './utils/date.js';
import * as agentsUtils from './utils/agents.js';
import { renderSidebarShell } from './core/shell.js';

/* ── Core: renderer + router + events ── */
import { render, renderSidebar, renderMain } from './core/renderer.js';
import { navigate, scrollMainToTop, scrollMessagesToBottom, scrollFollowUpToBottom } from './core/router.js';
import { bindGlobalEventDelegation } from './core/events.js';

/* ── Feature view modules ── */
import { renderDashboard, renderSessions, renderSessionCard } from './features/sessions/view.js';
import { renderChat, renderMessage, renderDRAgentMessage, renderExportButtons, renderAgentChatPanel } from './features/chat/view.js';
import { renderContextDocBadge, renderContextDocPanel, renderInlineContextDocEditor } from './ui/contextDoc.js';
import { registerLaunchAssistantFeature } from './features/launchAssistant/index.js';
import { registerComparisonsFeature } from './features/comparisons/index.js';
import { registerStressTestFeature } from './features/stressTest/index.js';
import { registerNewSessionFeature } from './features/newSession/index.js';
import { registerDecisionRoomFeature } from './features/decisionRoom/index.js';
import { registerConfrontationFeature } from './features/confrontation/index.js';
import { registerQuickDecisionFeature } from './features/quickDecision/index.js';
import { registerAdminFeature } from './features/admin/index.js';
import { registerSessionHistoryFeature } from './features/sessionHistory/index.js';
import { registerDebateAuditFeature } from './features/debateAudit/index.js';
import { registerGraphViewFeature } from './features/graphView/index.js';
import { registerJuryFeature } from './features/jury/index.js';
import { registerArgumentHeatmapFeature } from './features/argumentHeatmap/index.js';
import { registerDebateReplayFeature } from './features/debateReplay/index.js';

/* ── Feature handler modules ── */
import { registerGlobalHandlers } from './core/globalHandlers.js';
import { registerSessionsHandlers } from './features/sessions/handlers.js';
import { registerChatHandlers } from './features/chat/handlers.js';
import { registerContextDocHandlers } from './features/contextDoc/handlers.js';
import { registerNewSessionHandlers, registerScenarioHandlers } from './features/newSession/handlers.js';
import { registerDecisionRoomHandlers } from './features/decisionRoom/handlers.js';
import { registerConfrontationHandlers } from './features/confrontation/handlers.js';
import { registerQuickDecisionHandlers } from './features/quickDecision/handlers.js';
import { registerStressTestHandlers } from './features/stressTest/handlers.js';
import { registerSessionHistoryHandlers } from './features/sessionHistory/handlers.js';
import { registerComparisonsHandlers } from './features/comparisons/handlers.js';
import { registerLaunchAssistantHandlers } from './features/launchAssistant/handlers.js';
import { registerAdminHandlers, registerScenarioPackAdminHandlers } from './features/admin/handlers.js';
import { registerDebateAuditHandlers } from './features/debateAudit/handlers.js';
import { registerGraphViewHandlers } from './features/graphView/handlers.js';
import { registerJuryHandlers } from './features/jury/handlers.js';
import { registerArgumentHeatmapHandlers } from './features/argumentHeatmap/handlers.js';
import { registerDebateReplayHandlers } from './features/debateReplay/handlers.js';

function bootstrapModuleArchitecture() {
  /* Core namespace */
  window.DecisionArena = {
    store,
    services,
    utils: {
      ...htmlUtils,
      ...markdownUtils,
      ...dateUtils,
      ...agentsUtils,
    },
    router: {
      navigate,
      scrollMainToTop,
      scrollMessagesToBottom,
      scrollFollowUpToBottom,
    },
    render,
    /* Views registry: populated by feature modules below. */
    views: {
      dashboard: renderDashboard,
      sessions:  renderSessions,
      chat:      renderChat,

      shared: {
        renderSessionCard,
        renderMessage,
        renderDRAgentMessage,
        renderExportButtons,
        renderAgentChatPanel,
        renderContextDocBadge,
        renderContextDocPanel,
        renderInlineContextDocEditor,
      },
    },
  };

  /* ── Register view modules (self-register into window.DecisionArena.views) ── */
  registerLaunchAssistantFeature();
  registerComparisonsFeature();
  registerStressTestFeature();
  registerNewSessionFeature();
  registerDecisionRoomFeature();
  registerConfrontationFeature();
  registerQuickDecisionFeature();
  registerAdminFeature();
  registerSessionHistoryFeature();
  registerDebateAuditFeature();
  registerGraphViewFeature();
  registerJuryFeature();
  registerArgumentHeatmapFeature();
  registerDebateReplayFeature();

  /* ── Register all action/event handlers ── */
  registerGlobalHandlers();
  registerSessionsHandlers();
  registerChatHandlers();
  registerContextDocHandlers();
  registerNewSessionHandlers();
  registerScenarioHandlers();
  registerDecisionRoomHandlers();
  registerConfrontationHandlers();
  registerQuickDecisionHandlers();
  registerStressTestHandlers();
  registerSessionHistoryHandlers();
  registerComparisonsHandlers();
  registerLaunchAssistantHandlers();
  registerAdminHandlers();
  registerScenarioPackAdminHandlers();
  registerDebateAuditHandlers();
  registerGraphViewHandlers();
  registerJuryHandlers();
  registerArgumentHeatmapHandlers();
  registerDebateReplayHandlers();

  /* ── Wire global event delegation (replaces legacy-app.js listeners) ── */
  bindGlobalEventDelegation();
}

async function init() {
  const { LoaderService } = services;
  window.DecisionArena.render();
  await LoaderService.loadInitialData();
  window.DecisionArena.render();
}

async function startApp() {
  bootstrapModuleArchitecture();
  renderSidebarShell(window.i18n);
  await init();
}

document.addEventListener('DOMContentLoaded', () => {
  startApp().catch((err) => {
    console.error(err);
    const main = document.getElementById('main-content');
    if (main) {
      main.innerHTML = `<div class="error-banner">⚠️ Frontend bootstrap failed: ${String(err.message || err)}</div>`;
    }
  });
});

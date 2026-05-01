/* Chat feature — action handlers and keyboard shortcut */
import { registerAction, registerKeydownListener } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:       a.store.state,
    render:      () => a.render?.(),
    navigate:    (v) => a.router.navigate(v),
    ChatService: a.services.ChatService,
    scroll:      () => a.router.scrollMessagesToBottom?.(),
    scrollFU:    () => a.router.scrollFollowUpToBottom?.(),
    t:           (key) => window.i18n?.t(key) ?? key,
  };
}

async function doSendMessage() {
  const { state, render, ChatService, scroll } = getCtx();
  const input = document.getElementById('chat-input');
  if (!input) return;
  const text = input.value.trim();
  if (!text || state.isLoading) return;

  const session = state.currentSession;
  if (!session) return;

  input.value = '';
  state.isLoading = true;

  const controller = new AbortController();
  state.chatAbortController = controller;

  const tempUserMsg = {
    id: 'temp-' + Date.now(), role: 'user',
    content: text, created_at: new Date().toISOString(),
  };
  state.currentMessages.push(tempUserMsg);
  render();
  scroll();

  try {
    const result = await ChatService.send({
      session_id:      session.id,
      message:         text,
      selected_agents: session.selected_agents || [],
    }, controller.signal);

    const idx = state.currentMessages.findIndex((m) => m.id === tempUserMsg.id);
    if (idx >= 0 && result.user_message) state.currentMessages[idx] = result.user_message;
    if (result.agent_messages) state.currentMessages.push(...result.agent_messages);
  } catch (err) {
    if (err.name === 'AbortError') {
      state.currentMessages = state.currentMessages.filter((m) => m.id !== tempUserMsg.id);
    } else {
      state.error = 'Failed to send message: ' + err.message;
      state.currentMessages = state.currentMessages.filter((m) => m.id !== tempUserMsg.id);
    }
  } finally {
    state.isLoading = false;
    state.chatAbortController = null;
    render();
    scroll();
  }
}

async function doSendFollowUp() {
  const { state, render, ChatService, scrollFU } = getCtx();
  const input = document.getElementById('followup-input');
  if (!input) return;
  const text = input.value.trim();
  if (!text || state.followUpLoading) return;

  const session = state.currentSession;
  if (!session) return;

  const contextMode = input.dataset.contextMode || state.view;
  input.value = '';
  state.followUpLoading = true;

  const tempMsg = {
    id: 'temp-fu-' + Date.now(), role: 'user',
    content: text, created_at: new Date().toISOString(),
  };
  state.followUpMessages.push(tempMsg);
  render();
  scrollFU();

  try {
    const result = await ChatService.send({
      session_id:      session.id,
      message:         text,
      selected_agents: session.selected_agents || [],
      context_mode:    contextMode,
    });

    const idx = state.followUpMessages.findIndex((m) => m.id === tempMsg.id);
    if (idx >= 0 && result.user_message) state.followUpMessages[idx] = result.user_message;
    if (result.agent_messages) state.followUpMessages.push(...result.agent_messages);
  } catch (err) {
    state.error = 'Failed to send follow-up: ' + err.message;
    state.followUpMessages = state.followUpMessages.filter((m) => m.id !== tempMsg.id);
  } finally {
    state.followUpLoading = false;
    render();
    scrollFU();
  }
}

// ── Reactive Chat default state ──────────────────────────────────────────
function defaultReactiveChat() {
  return {
    enabled:                      false,
    primaryAgentId:               '',
    reactorAgentIds:              [],
    turnsMin:                     2,
    turnsMax:                     4,
    earlyStopEnabled:             true,
    earlyStopConfidenceThreshold: 0.85,
    noNewArgumentsThreshold:      2,
    reactorMode:                  'independent',
    debateIntensity:              'medium',
    reactionStyle:                'critical',
    includeFinalSynthesis:        true,
    running:                      false,
    error:                        null,
  };
}

// ── Reactive Chat send ────────────────────────────────────────────────────
async function doSendReactiveMessage() {
  const { state, render, ChatService, scroll } = getCtx();
  const input = document.getElementById('chat-input');
  if (!input) return;
  const question = input.value.trim();
  if (!question) return;

  const session = state.currentSession;
  if (!session) return;

  const rc = state.reactiveChat || {};
  if (!rc.primaryAgentId) {
    state.reactiveChat.error = window.i18n?.t('chat.reactive.errorNoPrimary') ?? 'Select a primary agent.';
    render(); return;
  }
  if (!rc.reactorAgentIds || rc.reactorAgentIds.length === 0) {
    state.reactiveChat.error = window.i18n?.t('chat.reactive.errorNoReactor') ?? 'Select at least one reactor.';
    render(); return;
  }

  input.value = '';
  state.reactiveChat.running = true;
  state.reactiveChat.error   = null;
  state.reactiveChatResults  = null;
  render();
  scroll();

  try {
    const result = await ChatService.sendReactive({
      session_id:                      session.id,
      question,
      primary_agent_id:                rc.primaryAgentId,
      reactor_agent_ids:               rc.reactorAgentIds,
      turns_min:                       rc.turnsMin || 2,
      turns_max:                       rc.turnsMax || 4,
      early_stop_enabled:              rc.earlyStopEnabled !== false,
      early_stop_confidence_threshold: rc.earlyStopConfidenceThreshold || 0.85,
      no_new_arguments_threshold:      rc.noNewArgumentsThreshold || 2,
      reactor_mode:                    rc.reactorMode || 'independent',
      debate_intensity:                rc.debateIntensity || 'medium',
      reaction_style:                  rc.reactionStyle || 'critical',
      include_final_synthesis:         rc.includeFinalSynthesis !== false,
    });
    state.reactiveChatResults = result.reactive_thread || null;
    // Also push messages to currentMessages for session history continuity
    if (result.reactive_thread?.messages) {
      state.currentMessages.push(...result.reactive_thread.messages);
    }
  } catch (err) {
    state.reactiveChat.error = 'Reactive Chat error: ' + err.message;
  } finally {
    state.reactiveChat.running = false;
    render();
    scroll();
  }
}

function registerChatHandlers() {
  registerAction('send-message',   () => doSendMessage());
  registerAction('send-followup',  () => doSendFollowUp());

  registerAction('stop-generation', () => {
    const { state, render } = getCtx();
    if (state.chatAbortController) {
      state.chatAbortController.abort();
      state.chatAbortController = null;
    }
    state.isLoading = false;
    render();
  });

  registerAction('goto-chat', () => getCtx().navigate('chat'));

  // ── Reactive Chat actions ─────────────────────────────────────────────
  registerAction('toggle-reactive-chat', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.enabled = !!element.checked;
    state.reactiveChatResults  = null;
    render();
  });

  registerAction('set-reactive-primary-agent', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.primaryAgentId = element.value;
    // Remove from reactors if present
    state.reactiveChat.reactorAgentIds = (state.reactiveChat.reactorAgentIds || []).filter((id) => id !== element.value);
    render();
  });

  registerAction('toggle-reactive-reactor-agent', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    const agentId = element.dataset.agentId;
    if (!agentId) return;
    const list = state.reactiveChat.reactorAgentIds || [];
    const idx  = list.indexOf(agentId);
    if (element.checked && idx < 0) list.push(agentId);
    else if (!element.checked && idx >= 0) list.splice(idx, 1);
    state.reactiveChat.reactorAgentIds = list;
    render();
  });

  registerAction('set-reactive-turns-min', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    const v = Math.max(1, Math.min(10, parseInt(element.value, 10)));
    state.reactiveChat.turnsMin = v;
    if (state.reactiveChat.turnsMax < v) state.reactiveChat.turnsMax = v;
    const lbl = element.previousElementSibling;
    if (lbl) lbl.lastChild.textContent = ` (${v})`;
    render();
  });

  registerAction('set-reactive-turns-max', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    const v = Math.max(1, Math.min(10, parseInt(element.value, 10)));
    state.reactiveChat.turnsMax = v;
    if (state.reactiveChat.turnsMin > v) state.reactiveChat.turnsMin = v;
    render();
  });

  registerAction('toggle-reactive-early-stop', ({ element }) => {
    const { state, render } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.earlyStopEnabled = !!element.checked;
    render();
  });

  registerAction('set-reactive-confidence-threshold', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.earlyStopConfidenceThreshold = parseFloat(element.value || 0.85);
  });

  registerAction('set-reactive-no-new-arguments-threshold', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.noNewArgumentsThreshold = parseInt(element.value, 10) || 2;
  });

  registerAction('set-reactive-reactor-mode', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.reactorMode = element.value;
  });

  registerAction('set-reactive-debate-intensity', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.debateIntensity = element.value;
  });

  registerAction('set-reactive-reaction-style', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.reactionStyle = element.value;
  });

  registerAction('toggle-reactive-synthesis', ({ element }) => {
    const { state } = getCtx();
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    state.reactiveChat.includeFinalSynthesis = !!element.checked;
  });

  registerAction('send-reactive-message', () => doSendReactiveMessage());

  const RC_PRESETS = {
    minimal: { turnsMin: 1, turnsMax: 2, earlyStopEnabled: true, earlyStopConfidenceThreshold: 0.80, noNewArgumentsThreshold: 1, debateIntensity: 'low', reactionStyle: 'complementary', includeFinalSynthesis: true },
    standard: { turnsMin: 2, turnsMax: 4, earlyStopEnabled: true, earlyStopConfidenceThreshold: 0.85, noNewArgumentsThreshold: 2, debateIntensity: 'medium', reactionStyle: 'critical', includeFinalSynthesis: true },
    intense:  { turnsMin: 3, turnsMax: 8, earlyStopEnabled: true, earlyStopConfidenceThreshold: 0.90, noNewArgumentsThreshold: 3, debateIntensity: 'high', reactionStyle: 'contradictory', includeFinalSynthesis: true },
  };

  registerAction('apply-reactive-preset', ({ element }) => {
    const { state, render } = getCtx();
    const preset = element?.dataset?.preset;
    if (!preset || !RC_PRESETS[preset]) return;
    if (!state.reactiveChat) state.reactiveChat = defaultReactiveChat();
    Object.assign(state.reactiveChat, RC_PRESETS[preset]);
    render();
  });

  registerKeydownListener((e) => {
    if (e.key !== 'Enter' || e.shiftKey) return;
    const chatInput    = document.getElementById('chat-input');
    const followInput  = document.getElementById('followup-input');
    if (document.activeElement === chatInput) {
      e.preventDefault();
      const rc = window.DecisionArena?.store?.state?.reactiveChat;
      if (rc?.enabled) doSendReactiveMessage();
      else doSendMessage();
    } else if (document.activeElement === followInput) {
      e.preventDefault();
      doSendFollowUp();
    }
  });
}

export { registerChatHandlers, defaultReactiveChat };

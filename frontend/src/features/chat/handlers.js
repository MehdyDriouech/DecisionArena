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

  registerKeydownListener((e) => {
    if (e.key !== 'Enter' || e.shiftKey) return;
    const chatInput    = document.getElementById('chat-input');
    const followInput  = document.getElementById('followup-input');
    if (document.activeElement === chatInput) {
      e.preventDefault();
      doSendMessage();
    } else if (document.activeElement === followInput) {
      e.preventDefault();
      doSendFollowUp();
    }
  });
}

export { registerChatHandlers };

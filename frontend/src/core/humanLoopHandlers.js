/* Human-in-the-loop MVP — challenge + fork (rerun wrapper) */
import { registerAction, dispatchAction } from './events.js';
import {
  findMessageById,
  resolveChallengeTargetAgent,
  canChallengeMessage,
} from '../utils/messageLookup.js';

function getCtx() {
  const DA = window.DecisionArena;
  return {
    store:        DA.store,
    state:        DA.store.state,
    render:       () => DA.render?.(),
    navigate:     (v) => DA.router.navigate(v),
    ChatService:  DA.services.ChatService,
    SessionService: DA.services.SessionService,
    t:            (key) => window.i18n?.t(key) ?? key,
  };
}

function registerHumanLoopHandlers() {
  registerAction('challenge-claim', async ({ event, element }) => {
    const btn = event?.target?.closest?.('[data-action="challenge-claim"]') || element;
    const messageId = btn?.dataset?.messageId;
    if (!messageId) return;

    const { state, render, ChatService, t } = getCtx();
    const session = state.currentSession;

    if (!session?.id) {
      state.error = t('hitl.noSession');
      render();
      return;
    }

    const message = findMessageById(state, messageId);
    if (!message || !canChallengeMessage(message)) {
      state.error = t('hitl.messageNotFound');
      render();
      return;
    }

    const DA = window.DecisionArena;
    const { agentName } = DA.utils;
    const agentLabel = agentName(state.personas, message.agent_id) || message.agent_id || 'agent';

    const challengePrompt =
      `${t('hitl.challengeIntro').replace(/\{agent\}/g, agentLabel)}\n\n`
      + `${t('hitl.challengeClaimLabel')}\n"${(message.content || message.text || '').trim()}"\n\n`
      + t('hitl.challengeInstructions');

    const targetAgent = resolveChallengeTargetAgent(message, session);
    const payload = {
      session_id:      session.id,
      message:         challengePrompt,
      context_mode:    'challenge',
      challenge_origin:        messageId,
      challenge_target_agent: targetAgent || undefined,
      challenge_level:        'soft',
      selected_agents: targetAgent ? [targetAgent] : normalizeAgentsFallback(session),
    };

    try {
      state.isLoading = true;
      render();

      await ChatService.send(payload);

      const fresh = await window.DecisionArena.services.SessionService.get(session.id);
      const msgs = fresh.messages || [];
      if (state.view === 'chat') {
        state.currentMessages = msgs;
      }
      if (state.sessionHistory?.session?.id === session.id) {
        state.sessionHistory.messages = msgs;
      }
    } catch (e) {
      state.error = e?.message || String(e);
    } finally {
      state.isLoading = false;
      render();
      if (state.view === 'chat') {
        setTimeout(() => window.DecisionArena.router.scrollMessagesToBottom?.(), 50);
      }
    }
  });

  registerAction('fork-session', async ({ element, event }) => {
    const el = event?.target?.closest?.('[data-session-id]') || element?.closest?.('[data-session-id]') || element;
    const sessionId = el?.dataset?.sessionId;
    if (!sessionId) return;

    const { state, render, navigate, SessionService, t } = getCtx();

    try {
      state.isLoading = true;
      state.error    = null;
      render();

      const result = await SessionService.rerun(sessionId, {
        variations:            [],
        keep_context_document: true,
      });

      const child = result?.session;
      if (!child?.id) {
        state.error = t('hitl.forkFailed');
        return;
      }

      const full = await SessionService.get(child.id);
      const sess = full.session || full;
      if (!state.sessions.some((s) => s.id === sess.id)) {
        state.sessions.unshift(sess);
      }
      let agents = sess.selected_agents;
      if (typeof agents === 'string') {
        try {
          agents = JSON.parse(agents);
        } catch {
          agents = [];
        }
      }
      if (!Array.isArray(agents)) agents = [];

      state.newSession = {
        ...state.newSession,
        title:              sess.title || '',
        idea:               sess.initial_prompt || '',
        mode:               sess.mode || 'chat',
        selectedAgents:     agents,
        rounds:             sess.rounds != null ? Number(sess.rounds) : state.newSession.rounds,
        language:           sess.language || state.newSession.language,
        source_session_id:  result.parent_session_id || sessionId,
        isFork:             true,
        forkDraftSessionId: sess.id,
      };

      navigate('new-session');
    } catch (e) {
      state.error = e?.message || String(e);
    } finally {
      state.isLoading = false;
      render();
    }
  });

  registerAction('rerun-with-challenge', async ({ element }) => {
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;

    const { state, render, navigate, SessionService, t } = getCtx();

    try {
      state.isLoading = true;
      state.error = null;
      render();

      const result = await SessionService.rerun(sessionId, {
        variations:               [],
        keep_context_document:   true,
        include_challenge_context: true,
      });

      const child = result?.session;
      if (!child?.id) {
        state.error = t('hitl.forkFailed');
        return;
      }

      const full = await SessionService.get(child.id);
      const sess = full.session || full;
      if (!state.sessions.some((s) => s.id === sess.id)) {
        state.sessions.unshift(sess);
      }
      let agents = sess.selected_agents;
      if (typeof agents === 'string') {
        try {
          agents = JSON.parse(agents);
        } catch {
          agents = [];
        }
      }
      if (!Array.isArray(agents)) agents = [];

      state.newSession = {
        ...state.newSession,
        title:              sess.title || '',
        idea:               sess.initial_prompt || '',
        mode:               sess.mode || 'chat',
        selectedAgents:     agents,
        rounds:             sess.rounds != null ? Number(sess.rounds) : state.newSession.rounds,
        language:           sess.language || state.newSession.language,
        source_session_id:  result.parent_session_id || sessionId,
        isFork:             true,
        forkDraftSessionId: sess.id,
      };

      navigate('new-session');
    } catch (e) {
      state.error = e?.message || String(e);
    } finally {
      state.isLoading = false;
      render();
    }
  });
}

function normalizeAgentsFallback(session) {
  let raw = session?.selected_agents;
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch {
      raw = [];
    }
  }
  if (!Array.isArray(raw) || raw.length === 0) return ['critic'];
  return raw;
}

export { registerHumanLoopHandlers };

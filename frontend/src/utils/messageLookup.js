/**
 * Locate a message across DecisionArena state (chat, follow-up, session history, structured results).
 * @param {object} state DecisionArena store state
 * @param {string} messageId
 * @returns {object | null}
 */
function findMessageById(state, messageId) {
  if (!messageId || !state) return null;

  const lists = [];

  if (Array.isArray(state.currentMessages)) lists.push(state.currentMessages);
  if (Array.isArray(state.followUpMessages)) lists.push(state.followUpMessages);
  if (Array.isArray(state.sessionHistory?.messages)) lists.push(state.sessionHistory.messages);

  const pushFromResults = (results, paths) => {
    if (!results) return;
    for (const path of paths) {
      let cur = results;
      for (const key of path) {
        cur = cur?.[key];
      }
      if (Array.isArray(cur)) lists.push(cur);
    }
  };

  pushFromResults(state.drResults, [
    ['messages'],
  ]);
  if (state.drResults?.rounds && typeof state.drResults.rounds === 'object') {
    Object.values(state.drResults.rounds).forEach((arr) => {
      if (Array.isArray(arr)) lists.push(arr);
    });
  }
  pushFromResults(state.confrontationResults, [
    ['messages'],
    ['phases', 'blue_opening'],
    ['phases', 'red_attack'],
    ['phases', 'blue_rebuttal'],
    ['phases', 'synthesis'],
  ]);
  pushFromResults(state.qdResults, [['messages']]);
  pushFromResults(state.stResults, [['messages']]);
  pushFromResults(state.reactiveChatResults, [['messages']]);

  for (const list of lists) {
    const found = list.find((m) => String(m?.id ?? '') === String(messageId));
    if (found) return found;
  }

  return null;
}

/** @param {object} session */
function normalizeSessionAgents(session) {
  if (!session) return [];
  let raw = session.selected_agents;
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch {
      raw = [];
    }
  }
  return Array.isArray(raw) ? raw : [];
}

/**
 * Pick exactly one agent for a challenge reply.
 * @returns {string | null} agent id, or null to fallback (full chat selection)
 */
function resolveChallengeTargetAgent(message, session) {
  const agents = normalizeSessionAgents(session);
  const aid = message?.agent_id;
  if (aid && aid !== 'user' && !['devil_advocate'].includes(aid) && agents.includes(aid)) {
    return aid;
  }
  for (const candidate of ['critic', 'qa']) {
    if (agents.includes(candidate)) return candidate;
  }
  return null;
}

function canChallengeMessage(msg) {
  if (!msg || msg.role === 'user') return false;
  if (!msg.agent_id) return false;
  const id = msg.id;
  if (id === undefined || id === null || id === '') return false;
  const sid = String(id);
  if (sid.startsWith('temp-')) return false;
  return true;
}

/**
 * Parse persisted message metadata (Human-in-the-loop v2).
 * @param {object | null | undefined} msg
 * @returns {Record<string, unknown>}
 */
function parseMessageMeta(msg) {
  if (!msg) return {};
  let raw = msg.meta_json ?? msg.meta;
  if (typeof raw === 'string') {
    try {
      raw = JSON.parse(raw);
    } catch {
      return {};
    }
  }
  return raw && typeof raw === 'object' ? raw : {};
}

/**
 * Minimal HITL indicators (no layout overhaul).
 * @param {object} msg
 * @param {(k: string) => string} t
 * @param {(s: string) => string} escHtml
 */
function formatHitlMessageBadges(msg, t, escHtml) {
  const meta = parseMessageMeta(msg);
  const parts = [];
  if (msg.role === 'user') {
    if (meta.context_mode === 'challenge') {
      parts.push(`<span class="badge badge-warning" style="font-size:10px;">⚠️ ${escHtml(t('hitl.challengeThreadBadge'))}</span>`);
    }
    return parts.length ? `<div style="margin:6px 0 0;display:flex;flex-wrap:wrap;gap:4px;align-items:center;">${parts.join('')}</div>` : '';
  }
  if (meta.challenge_status === 'challenged') {
    parts.push(`<span class="badge badge-warning" style="font-size:10px;">⚠️ ${escHtml(t('hitl.challengedBadge'))}</span>`);
  }
  if (meta.challenge_response) {
    parts.push(`<span class="badge badge-muted" style="font-size:10px;">${escHtml(t('hitl.challengeResponseBadge'))}</span>`);
  }
  return parts.length ? `<div style="margin:6px 0 0;display:flex;flex-wrap:wrap;gap:4px;align-items:center;">${parts.join('')}</div>` : '';
}

/**
 * @param {string | undefined} sessionId
 * @param {object} msg
 * @param {(k: string) => string} t
 * @param {(s: string) => string} escHtml
 */
function formatRerunWithChallengeButton(sessionId, msg, t, escHtml) {
  if (!sessionId) return '';
  const meta = parseMessageMeta(msg);
  if (meta.context_mode !== 'challenge') return '';
  return `<button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;font-size:11px;display:inline-block;" data-action="rerun-with-challenge" data-session-id="${escHtml(String(sessionId))}">${escHtml(t('hitl.rerunWithChallenge'))}</button>`;
}

export {
  findMessageById,
  normalizeSessionAgents,
  resolveChallengeTargetAgent,
  canChallengeMessage,
  parseMessageMeta,
  formatHitlMessageBadges,
  formatRerunWithChallengeButton,
};

/**
 * Reconstitue l’objet `qdResults` (comme après POST /api/quick-decision/run)
 * à partir de GET /api/sessions/:id, pour afficher une session terminée sans relancer le run.
 */

function sortByCreatedAt(a, b) {
  const ta = a.created_at ? Date.parse(a.created_at) : 0;
  const tb = b.created_at ? Date.parse(b.created_at) : 0;
  return ta - tb;
}

function hasNonEmptyBrief(brief) {
  if (brief == null) return false;
  if (typeof brief !== 'object' || Array.isArray(brief)) return Boolean(brief);
  return Object.keys(brief).length > 0;
}

/**
 * @param {object} session — ligne session API
 * @param {object} sessionPayload — corps complet de GET session (session, messages, votes, …)
 */
function shouldHydrateQuickDecisionResults(session, sessionPayload) {
  if (!session || session.mode !== 'quick-decision') return false;
  const messages = sessionPayload.messages || [];
  const hasQdCore = messages.some(
    (m) =>
      m.mode_context === 'quick-decision' &&
      (m.phase === 'analysis' || m.phase === 'synthesis')
  );
  const votes = sessionPayload.votes || [];
  const hasBrief = hasNonEmptyBrief(sessionPayload.decision_brief);
  return hasQdCore || hasBrief || votes.length > 0;
}

/**
 * @param {object} sessionPayload — GET /api/sessions/:id
 * @param {object | null} verdict — ligne verdict ou null
 */
function buildQuickDecisionResultsFromSession(sessionPayload, verdict) {
  const msgs = sessionPayload.messages || [];
  const round = msgs
    .filter((m) => m.mode_context === 'quick-decision' && m.phase === 'analysis')
    .sort(sortByCreatedAt);
  const synthesis = msgs
    .filter((m) => m.mode_context === 'quick-decision' && m.phase === 'synthesis')
    .sort(sortByCreatedAt);

  return {
    round,
    synthesis,
    verdict: verdict || null,
    warning: null,
    votes: sessionPayload.votes || [],
    automatic_decision: sessionPayload.automatic_decision ?? null,
    raw_decision: sessionPayload.raw_decision ?? null,
    adjusted_decision: sessionPayload.adjusted_decision ?? null,
    context_quality: sessionPayload.context_quality ?? null,
    reliability_cap: sessionPayload.reliability_cap ?? null,
    false_consensus_risk: sessionPayload.false_consensus_risk ?? 'low',
    false_consensus: sessionPayload.false_consensus ?? null,
    reliability_warnings: sessionPayload.reliability_warnings || [],
    decision_reliability_summary: sessionPayload.decision_reliability_summary ?? null,
    context_clarification: sessionPayload.context_clarification ?? null,
    decision_quality_score: sessionPayload.decision_quality_score ?? null,
    decision_brief: sessionPayload.decision_brief ?? null,
    agent_decision_dynamics: sessionPayload.agent_decision_dynamics || [],
    premortem_summary: sessionPayload.premortem_summary ?? null,
    guardrails: sessionPayload.guardrails ?? null,
    auto_retry: sessionPayload.auto_retry ?? null,
  };
}

export { shouldHydrateQuickDecisionResults, buildQuickDecisionResultsFromSession };

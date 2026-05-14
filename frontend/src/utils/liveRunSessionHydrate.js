/**
 * Reconstitue les objets *Results (comme après POST /run) depuis GET /api/sessions/:id.
 * Utilisé quand /run-status passe en terminal avant la fin du POST long.
 */

function parseSessionResultBlob(session) {
  if (!session || session.result == null) return {};
  if (typeof session.result === 'object' && !Array.isArray(session.result)) {
    return session.result;
  }
  if (typeof session.result === 'string' && session.result.trim()) {
    try {
      const o = JSON.parse(session.result);
      return typeof o === 'object' && o !== null ? o : {};
    } catch (_) {
      return {};
    }
  }
  return {};
}

function groupJuryMessages(messages) {
  const rounds = {};
  const synthesis = [];
  for (const m of messages || []) {
    if (String(m.mode_context || '') !== 'jury') continue;
    const phase = String(m.phase || '');
    const agent = String(m.agent_id || '');
    if (phase === 'jury-verdict' && agent === 'synthesizer') {
      synthesis.push(m);
      continue;
    }
    if (phase === 'jury-mini-challenge' || String(m.message_type || '') === 'jury-mini-challenge') {
      if (!rounds['mini-challenge']) rounds['mini-challenge'] = [];
      rounds['mini-challenge'].push(m);
      continue;
    }
    if (phase === 'jury-minority-report') {
      if (!rounds.minority) rounds.minority = [];
      rounds.minority.push(m);
      continue;
    }
    const r = Number(m.round);
    if (!Number.isFinite(r) || r < 1) continue;
    const key = String(r);
    if (!rounds[key]) rounds[key] = [];
    rounds[key].push(m);
  }
  return { rounds, synthesis };
}

function groupNumericRoundsByModeContext(messages, modeContext) {
  const rounds = {};
  for (const m of messages || []) {
    if (String(m.mode_context || '') !== modeContext) continue;
    const r = Number(m.round);
    if (!Number.isFinite(r) || r < 1) continue;
    if (!rounds[r]) rounds[r] = [];
    rounds[r].push(m);
  }
  return rounds;
}

function groupConfrontationMessages(messages) {
  const rounds = {};
  const synthesis = [];
  for (const m of messages || []) {
    if (String(m.mode_context || '') !== 'confrontation') continue;
    const phase = String(m.phase || '');
    if (phase.includes('synthesis') || phase === 'synthesis_started' || phase === 'synthesis') {
      synthesis.push(m);
      continue;
    }
    const r = Number(m.round);
    if (!Number.isFinite(r) || r < 1) {
      if (!rounds.misc) rounds.misc = [];
      rounds.misc.push(m);
      continue;
    }
    if (!rounds[r]) rounds[r] = [];
    rounds[r].push(m);
  }
  return { rounds, synthesis };
}

function buildJuryResultsFromSessionShow(data) {
  const session = data.session || data;
  const persisted = parseSessionResultBlob(session);
  const { rounds, synthesis } = groupJuryMessages(data.messages || []);
  return {
    session_id: session.id,
    ...persisted,
    rounds,
    synthesis,
    votes: data.votes || data.vote_timeline || persisted.vote_timeline || [],
    decision_brief: data.decision_brief ?? null,
    decision_outcome: data.decision_outcome ?? data.decision_brief?.decision_outcome ?? null,
    jury_adversarial: data.jury_adversarial ?? null,
    agent_decision_dynamics: data.agent_decision_dynamics || [],
    premortem_summary: data.premortem_summary ?? persisted.premortem_summary ?? null,
    context_quality: data.context_quality ?? null,
    reliability_cap: data.reliability_cap ?? null,
    false_consensus_risk: data.false_consensus_risk ?? 'low',
    false_consensus: data.false_consensus ?? null,
    reliability_warnings: data.reliability_warnings || [],
    automatic_decision: data.automatic_decision ?? null,
    raw_decision: data.raw_decision ?? null,
    adjusted_decision: data.adjusted_decision ?? null,
  };
}

function buildDecisionRoomResultsFromSessionShow(data) {
  const session = data.session || data;
  const persisted = parseSessionResultBlob(session);
  const rounds = groupNumericRoundsByModeContext(data.messages || [], 'decision-room');
  const keys = Object.keys(rounds).map(Number).filter((n) => Number.isFinite(n));
  return {
    session_id: session.id,
    rounds,
    total_rounds: keys.length,
    arguments: data.arguments || [],
    positions: data.positions || [],
    interaction_edges: data.interaction_edges || [],
    weighted_analysis: data.weighted_analysis || {},
    dominance_indicator: data.dominance_indicator || '',
    votes: data.votes || [],
    vote_timeline: data.vote_timeline || data.votes || [],
    final_votes: data.final_votes ?? null,
    memory_summary: data.memory_summary ?? null,
    automatic_decision: data.automatic_decision ?? null,
    raw_decision: data.raw_decision ?? persisted.raw_decision ?? null,
    adjusted_decision: data.adjusted_decision ?? persisted.adjusted_decision ?? null,
    context_quality: data.context_quality ?? null,
    reliability_cap: data.reliability_cap ?? null,
    false_consensus_risk: data.false_consensus_risk ?? 'low',
    false_consensus: data.false_consensus ?? null,
    reliability_warnings: data.reliability_warnings || [],
    guardrails: data.guardrails ?? persisted.guardrails ?? null,
    decision_quality_score: data.decision_quality_score ?? persisted.decision_quality_score ?? null,
    canonical_synthesis: data.canonical_synthesis ?? persisted.canonical_synthesis ?? null,
    decision_outcome: data.decision_outcome ?? data.decision_brief?.decision_outcome ?? null,
    playbook_runtime: data.playbook_runtime ?? persisted.playbook_runtime ?? null,
    agent_decision_dynamics: data.agent_decision_dynamics || [],
    decision_brief: data.decision_brief ?? null,
    premortem_summary: data.premortem_summary ?? persisted.premortem_summary ?? null,
    auto_retry: data.auto_retry ?? persisted.auto_retry ?? null,
  };
}

function buildStressResultsFromSessionShow(data) {
  const session = data.session || data;
  const persisted = parseSessionResultBlob(session);
  const rounds = groupNumericRoundsByModeContext(data.messages || [], 'stress-test');
  const keys = Object.keys(rounds).map(Number).filter((n) => Number.isFinite(n));
  return {
    session_id: session.id,
    rounds,
    total_rounds: keys.length,
    arguments: data.arguments || [],
    positions: data.positions || [],
    interaction_edges: data.interaction_edges || [],
    weighted_analysis: data.weighted_analysis || {},
    dominance_indicator: data.dominance_indicator || '',
    votes: data.votes || [],
    vote_timeline: data.vote_timeline || data.votes || [],
    final_votes: data.final_votes ?? null,
    memory_summary: data.memory_summary ?? null,
    automatic_decision: data.automatic_decision ?? null,
    raw_decision: data.raw_decision ?? persisted.raw_decision ?? null,
    adjusted_decision: data.adjusted_decision ?? persisted.adjusted_decision ?? null,
    context_quality: data.context_quality ?? null,
    reliability_cap: data.reliability_cap ?? null,
    false_consensus_risk: data.false_consensus_risk ?? 'low',
    false_consensus: data.false_consensus ?? null,
    reliability_warnings: data.reliability_warnings || [],
    guardrails: data.guardrails ?? persisted.guardrails ?? null,
    decision_quality_score: data.decision_quality_score ?? persisted.decision_quality_score ?? null,
    decision_brief: data.decision_brief ?? null,
    canonical_synthesis: data.canonical_synthesis ?? persisted.canonical_synthesis ?? null,
    decision_outcome: data.decision_outcome ?? data.decision_brief?.decision_outcome ?? null,
    playbook_runtime: data.playbook_runtime ?? persisted.playbook_runtime ?? null,
    agent_decision_dynamics: data.agent_decision_dynamics || [],
    premortem_summary: data.premortem_summary ?? persisted.premortem_summary ?? null,
    verdict: data.verdict ?? null,
    auto_retry: data.auto_retry ?? persisted.auto_retry ?? null,
  };
}

function buildConfrontationResultsFromSessionShow(data) {
  const session = data.session || data;
  const persisted = parseSessionResultBlob(session);
  const { rounds, synthesis } = groupConfrontationMessages(data.messages || []);
  return {
    session_id: session.id,
    rounds,
    synthesis,
    verdict: data.verdict ?? null,
    total_rounds: session.cf_rounds || Object.keys(rounds).length || 3,
    interaction_style: session.cf_interaction_style || 'sequential',
    reply_policy: session.cf_reply_policy || 'all-agents-reply',
    arguments: data.arguments || [],
    positions: data.positions || [],
    interaction_edges: data.interaction_edges || [],
    weighted_analysis: data.weighted_analysis || {},
    dominance_indicator: data.dominance_indicator || '',
    votes: data.votes || [],
    vote_timeline: data.vote_timeline || data.votes || [],
    final_votes: data.final_votes ?? null,
    memory_summary: data.memory_summary ?? null,
    automatic_decision: data.automatic_decision ?? null,
    raw_decision: data.raw_decision ?? persisted.raw_decision ?? null,
    adjusted_decision: data.adjusted_decision ?? persisted.adjusted_decision ?? null,
    context_quality: data.context_quality ?? null,
    reliability_cap: data.reliability_cap ?? null,
    false_consensus_risk: data.false_consensus_risk ?? 'low',
    false_consensus: data.false_consensus ?? null,
    reliability_warnings: data.reliability_warnings || [],
    guardrails: data.guardrails ?? persisted.guardrails ?? null,
    decision_quality_score: data.decision_quality_score ?? persisted.decision_quality_score ?? null,
    decision_brief: data.decision_brief ?? null,
    canonical_synthesis: data.canonical_synthesis ?? persisted.canonical_synthesis ?? null,
    decision_outcome: data.decision_outcome ?? data.decision_brief?.decision_outcome ?? null,
    playbook_runtime: data.playbook_runtime ?? persisted.playbook_runtime ?? null,
    agent_decision_dynamics: data.agent_decision_dynamics || [],
    auto_retry: data.auto_retry ?? persisted.auto_retry ?? null,
  };
}

function buildLiveResultsFromSessionShow(mode, data) {
  const m = String(mode || '').toLowerCase();
  if (m === 'jury') return buildJuryResultsFromSessionShow(data);
  if (m === 'decision-room') return buildDecisionRoomResultsFromSessionShow(data);
  if (m === 'stress-test') return buildStressResultsFromSessionShow(data);
  if (m === 'confrontation') return buildConfrontationResultsFromSessionShow(data);
  return null;
}

export {
  parseSessionResultBlob,
  buildJuryResultsFromSessionShow,
  buildDecisionRoomResultsFromSessionShow,
  buildStressResultsFromSessionShow,
  buildConfrontationResultsFromSessionShow,
  buildLiveResultsFromSessionShow,
};

/* Decision Memory timeline derivation — deterministic (no LLM) */

function safeStr(v) { return String(v == null ? '' : v); }

function toDateKey(iso) {
  const s = safeStr(iso);
  return s.length >= 10 ? s.slice(0, 10) : s;
}

function toMonthKey(iso) {
  const s = safeStr(iso);
  return s.length >= 7 ? s.slice(0, 7) : s;
}

function cmpIso(a, b) {
  const sa = safeStr(a);
  const sb = safeStr(b);
  return sa.localeCompare(sb);
}

function buildMemoryIndex(memories) {
  const map = new Map();
  (Array.isArray(memories) ? memories : []).forEach((m) => {
    if (m && m.memory_id) map.set(String(m.memory_id), m);
  });
  return map;
}

function buildGraph(memories, links) {
  const idx = buildMemoryIndex(memories);
  const nodes = [...idx.keys()];
  const undirected = new Map(nodes.map((id) => [id, new Set()]));
  const outgoing = new Map(nodes.map((id) => [id, []]));
  const incoming = new Map(nodes.map((id) => [id, []]));

  (Array.isArray(links) ? links : []).forEach((l) => {
    const from = String(l?.from_memory_id || '');
    const to = String(l?.to_memory_id || '');
    const type = String(l?.link_type || 'related');
    if (!idx.has(from) || !idx.has(to) || !from || !to) return;
    undirected.get(from)?.add(to);
    undirected.get(to)?.add(from);
    outgoing.get(from)?.push({ to, type, created_at: l?.created_at || '' });
    incoming.get(to)?.push({ from, type, created_at: l?.created_at || '' });
  });

  return { idx, nodes, undirected, outgoing, incoming };
}

function connectedComponents(graph) {
  const seen = new Set();
  const comps = [];
  for (const id of graph.nodes) {
    if (seen.has(id)) continue;
    const stack = [id];
    const comp = [];
    seen.add(id);
    while (stack.length) {
      const cur = stack.pop();
      comp.push(cur);
      for (const nb of graph.undirected.get(cur) || []) {
        if (seen.has(nb)) continue;
        seen.add(nb);
        stack.push(nb);
      }
    }
    comps.push(comp);
  }
  return comps;
}

function chainIdForComponent(componentIds) {
  return [...componentIds].sort()[0] || '';
}

function sortChainIdsByTime(componentIds, index) {
  return [...componentIds].sort((a, b) => cmpIso(index.get(a)?.created_at, index.get(b)?.created_at));
}

function deriveChains(memories, links) {
  const graph = buildGraph(memories, links);
  const comps = connectedComponents(graph);
  const chains = comps.map((ids) => {
    const orderedIds = sortChainIdsByTime(ids, graph.idx);
    const chainId = chainIdForComponent(ids);
    const items = orderedIds.map((id) => graph.idx.get(id)).filter(Boolean);
    const latest = items[items.length - 1] || null;
    return {
      chain_id: chainId,
      memory_ids: orderedIds,
      playbooks: [...new Set(items.map((m) => String(m.playbook_id || '')).filter(Boolean))],
      first_at: items[0]?.created_at || '',
      last_at: latest?.created_at || '',
      latest_status: String(latest?.decision_status || ''),
      latest_confidence: String(latest?.confidence || ''),
      items,
    };
  });

  chains.sort((a, b) => cmpIso(b.last_at, a.last_at));
  return { chains, graph };
}

function deriveEventsForChain(chain, graph) {
  const items = Array.isArray(chain?.items) ? chain.items : [];
  const events = [];
  if (items.length === 0) return events;

  const add = (type, at, memory_id, meta = {}) => {
    events.push({ type, at: at || '', memory_id: String(memory_id || ''), meta });
  };

  for (let i = 0; i < items.length; i++) {
    const cur = items[i];
    const id = String(cur.memory_id || '');
    add('decision_created', cur.created_at, id, { playbook_id: cur.playbook_id });

    const out = graph.outgoing.get(id) || [];
    if (out.some((l) => l.type === 'pivot') || String(cur.decision_status || '') === 'pivot') {
      add('pivot_detected', cur.created_at, id, { via: out.filter((l) => l.type === 'pivot').map((l) => l.to) });
    }
    if ((Array.isArray(cur.failed_assumptions) ? cur.failed_assumptions : []).length) {
      add('assumption_failed', cur.created_at, id, { count: cur.failed_assumptions.length });
    }
    if ((Array.isArray(cur.validated_hypotheses) ? cur.validated_hypotheses : []).length) {
      add('hypothesis_validated', cur.created_at, id, { count: cur.validated_hypotheses.length });
    }
    if (out.some((l) => l.type === 'continuation' || l.type === 'experiment_followup')) {
      add('followup_created', cur.created_at, id, { links: out.map((l) => ({ type: l.type, to: l.to })) });
    }

    const prev = i > 0 ? items[i - 1] : null;
    if (prev) {
      const prevConf = String(prev.confidence || '');
      const curConf = String(cur.confidence || '');
      if (prevConf && curConf && prevConf !== curConf) {
        add('confidence_changed', cur.created_at, id, { from: prevConf, to: curConf });
      }
      const prevRisks = new Set((Array.isArray(prev.unresolved_risks) ? prev.unresolved_risks : []).map(safeStr));
      const curRisks = (Array.isArray(cur.unresolved_risks) ? cur.unresolved_risks : []).map(safeStr);
      const carried = curRisks.filter((r) => prevRisks.has(r) && r.trim() !== '');
      if (carried.length) {
        add('risk_carried_forward', cur.created_at, id, { count: carried.length, examples: carried.slice(0, 3) });
      }
    }
  }

  events.sort((a, b) => cmpIso(a.at, b.at));
  return events;
}

function summarizeChainChange(chain) {
  const items = Array.isArray(chain?.items) ? chain.items : [];
  if (items.length === 0) return null;
  const first = items[0];
  const last = items[items.length - 1];

  const setOf = (arr) => new Set((Array.isArray(arr) ? arr : []).map(safeStr).filter((x) => x.trim() !== ''));

  const firstRisks = setOf(first.unresolved_risks);
  const lastRisks = setOf(last.unresolved_risks);
  const resolvedRisks = [...firstRisks].filter((r) => !lastRisks.has(r));
  const newRisks = [...lastRisks].filter((r) => !firstRisks.has(r));
  const carriedRisks = [...lastRisks].filter((r) => firstRisks.has(r));

  const allValidated = new Set();
  const allFailed = new Set();
  items.forEach((m) => {
    (Array.isArray(m.validated_hypotheses) ? m.validated_hypotheses : []).forEach((x) => allValidated.add(safeStr(x)));
    (Array.isArray(m.failed_assumptions) ? m.failed_assumptions : []).forEach((x) => allFailed.add(safeStr(x)));
  });

  const confidenceScore = (c) => ({ weak: 1, moderate: 2, strong: 3 }[String(c || '')] || 0);
  const confDelta = confidenceScore(last.confidence) - confidenceScore(first.confidence);

  const latestNext = Array.isArray(last.recommended_next_steps) ? last.recommended_next_steps.filter(Boolean)[0] : '';

  return {
    chain_id: chain.chain_id,
    from: { status: String(first.decision_status || ''), confidence: String(first.confidence || ''), at: first.created_at },
    to: { status: String(last.decision_status || ''), confidence: String(last.confidence || ''), at: last.created_at },
    improved: resolvedRisks.length || confDelta > 0 ? { resolved_risks: resolvedRisks.slice(0, 8), confidence_delta: confDelta } : null,
    worse: newRisks.length || confDelta < 0 ? { new_risks: newRisks.slice(0, 8), confidence_delta: confDelta } : null,
    remains_unresolved: { carried_risks: carriedRisks.slice(0, 10) },
    validated: [...allValidated].filter((x) => x.trim() !== '').slice(0, 10),
    failed: [...allFailed].filter((x) => x.trim() !== '').slice(0, 10),
    latest_recommended_next_step: safeStr(latestNext),
  };
}

function groupTimeline(memories) {
  const list = Array.isArray(memories) ? memories : [];
  const byMonth = new Map();
  list.forEach((m) => {
    const month = toMonthKey(m.created_at);
    if (!byMonth.has(month)) byMonth.set(month, []);
    byMonth.get(month).push(m);
  });
  const months = [...byMonth.keys()].sort().reverse();
  return months.map((month) => {
    const items = byMonth.get(month) || [];
    items.sort((a, b) => cmpIso(b.created_at, a.created_at));
    return { month, items };
  });
}

export {
  deriveChains,
  deriveEventsForChain,
  summarizeChainChange,
  groupTimeline,
  toDateKey,
};


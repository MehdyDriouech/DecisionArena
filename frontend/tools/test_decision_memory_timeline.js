import assert from 'node:assert/strict';
import { deriveChains, deriveEventsForChain, summarizeChainChange } from '../src/features/decisionMemory/timeline.js';

function mkMem(id, at, pb, st, conf, risks = [], next = []) {
  return {
    memory_id: id,
    session_id: 's-' + id,
    playbook_id: pb,
    decision_status: st,
    confidence: conf,
    decision_summary: `summary ${id}`,
    unresolved_risks: risks,
    recommended_next_steps: next,
    validated_hypotheses: [],
    failed_assumptions: [],
    created_at: at,
    contract_version: 'decision_outcome.v1',
    taxonomy_version: 'taxonomy.v1',
    user_confirmed: 1,
  };
}

const memories = [
  mkMem('a', '2026-01-01T10:00:00Z', 'founder-sprint', 'validate_first', 'weak', ['risk1'], ['test1']),
  mkMem('b', '2026-01-10T10:00:00Z', 'founder-sprint', 'pivot', 'moderate', ['risk1', 'risk2'], ['test2']),
  mkMem('c', '2026-02-01T10:00:00Z', 'founder-sprint', 'proceed', 'strong', ['risk2'], ['ship']),
];
memories[1].failed_assumptions = ['assumpX'];
memories[2].validated_hypotheses = ['hypY'];

const links = [
  { from_memory_id: 'a', to_memory_id: 'b', link_type: 'experiment_followup', created_at: '2026-01-10T10:00:00Z' },
  { from_memory_id: 'b', to_memory_id: 'c', link_type: 'pivot', created_at: '2026-02-01T10:00:00Z' },
];

const pkg = deriveChains(memories, links);
assert.equal(pkg.chains.length, 1, 'one chain');
assert.deepEqual(pkg.chains[0].memory_ids, ['a', 'b', 'c'], 'ordered by time');

const events = deriveEventsForChain(pkg.chains[0], pkg.graph);
assert(events.some((e) => e.type === 'pivot_detected'), 'pivot_detected derived');
assert(events.some((e) => e.type === 'confidence_changed'), 'confidence_changed derived');
assert(events.some((e) => e.type === 'risk_carried_forward'), 'risk_carried_forward derived');
assert(events.some((e) => e.type === 'assumption_failed'), 'assumption_failed derived');
assert(events.some((e) => e.type === 'hypothesis_validated'), 'hypothesis_validated derived');

const sum = summarizeChainChange(pkg.chains[0]);
assert.equal(sum.to.status, 'proceed');
assert(sum.validated.includes('hypY'));
assert(sum.failed.includes('assumpX'));
assert.equal(sum.latest_recommended_next_step, 'ship');

console.log('OK: decision memory timeline tests passed');


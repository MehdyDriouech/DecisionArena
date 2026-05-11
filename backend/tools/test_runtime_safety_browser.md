# Runtime Safety Browser Test Plan (Decision Arena)

Preconditions:

- `COGNITIVE_RUNTIME_QA_MODE=qa`
- `COGNITIVE_RUNTIME_WRITE_GUARDS=1`
- backend running locally

## Scenario A — Decision Room

1. Create a Decision Room session with long objective and context doc.
2. Run one round and open session history.
3. Verify `meta_json.prompt_injection_trace` exists with `cognitive_budget`, `runtime_warnings`, `runtime_hash`.
4. Check no UI regression in replay/graph/heatmap.

Expected:

- No crash.
- Prompt trace shape remains valid.
- Budget/pruning visible in runtime payload.

## Scenario B — Situated Chat

1. Open strategic context A and chat with agent PM.
2. Repeat in strategic context B with distinct markers.
3. Compare prompts/answers and session history payloads.

Expected:

- No marker leakage A -> B.
- Runtime trace and warnings present.
- No unexpected write side effects in unrelated context.

## Scenario C — Beliefs + Narrative

1. Create contradictory beliefs in same context.
2. Recompute narrative.
3. Reload beliefs and check no implicit fact promotion.

Expected:

- Narrative updates without mutating unrelated belief fields.
- Provenance fields stay present and coherent.

## Scenario D — Snapshots + Comparison

1. Create snapshot S1.
2. Mutate live context and create S2.
3. Compare S1/S2 from UI.

Expected:

- S1 immutable.
- Compare returns integrity block (`snapshot_a`, `snapshot_b`, branch/restore verification).
- No silent restore or overwrite.

## Scenario E — Governance View

1. Open cognitive governance panel.
2. Verify registry/policy export loads.
3. Verify prompt runtime traces are readable for recent messages.

Expected:

- No UI/runtime mismatch.
- No missing keys for runtime safety fields.


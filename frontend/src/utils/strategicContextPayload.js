/**
 * Payload JSON aligné sur SessionStrategicContextGuard / Rerun / fork.
 * Une seule source pour éviter la dérive entre relance manuelle et HITL.
 */
function activeStrategicContextIdFromState(state) {
  return String(state.activeStrategicContextId || state.activeStrategicContext?.context_id || '').trim();
}

/** Corps optionnel pour POST rerun / fork quand un workspace actif est connu. */
export function strategicContextPayloadForRerun(state) {
  const id = activeStrategicContextIdFromState(state);
  return id ? { strategic_context_id: id } : {};
}

/** Alias explicite pour challenge / fork (même sémantique que rerun). */
export function strategicContextPayloadForApi(state) {
  return strategicContextPayloadForRerun(state);
}

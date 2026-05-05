/**
 * Injecte provider_runtime (BYOK navigateur) dans le corps des requêtes LLM.
 * Ne jamais logger ce payload ni l’afficher dans le DOM.
 */

function _state() {
  return typeof window !== 'undefined' ? window.DecisionArena?.store?.state : null;
}

/**
 * @returns {Record<string, { api_key: string, base_url: string, enabled: boolean, priority: number, default_model: string }>}
 */
export function buildProviderRuntimePayload() {
  const state = _state();
  const ps = state?.providerSettings;
  if (!ps || typeof ps !== 'object') return {};

  const out = {};
  for (const id of ['openai', 'anthropic', 'mistral', 'openrouter']) {
    const row = ps[id];
    if (!row || !row.enabled) continue;
    const key = typeof row.apiKey === 'string' ? row.apiKey.trim() : '';
    if (!key) continue;
    let base = typeof row.baseUrl === 'string' ? row.baseUrl.trim() : '';
    const pr = row.priority;
    const priority =
      typeof pr === 'number' && Number.isFinite(pr) ? pr : parseInt(String(pr ?? '100'), 10);
    const defaultModel = typeof row.defaultModel === 'string' ? row.defaultModel.trim() : '';
    out[id] = {
      api_key: key,
      base_url: base,
      enabled: true,
      priority: Number.isFinite(priority) ? priority : 100,
      default_model: defaultModel,
    };
  }
  return out;
}

/** Fusionne provider_runtime dans un corps d’API déjà objet (jamais de clés dans les query params). */
export function withProviderRuntime(body) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) return body;
  const rt = buildProviderRuntimePayload();
  if (!rt || Object.keys(rt).length === 0) return body;
  return { ...body, provider_runtime: rt };
}

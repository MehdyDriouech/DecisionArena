/**
 * Cache + fetch for POST /api/providers/models (Nouvelle session — routage LLM).
 */

export function providerSupportsModelDiscovery(providerRow) {
  if (!providerRow || typeof providerRow !== 'object') return false;
  const t = String(providerRow.type || '').toLowerCase();
  return t === 'ollama' || t === 'lmstudio' || t === 'openai-compatible';
}

/**
 * @param {string} providerId
 * @param {{ force?: boolean }} [opts]
 */
export async function fetchNewSessionProviderModels(providerId, opts = {}) {
  const DA = typeof window !== 'undefined' ? window.DecisionArena : null;
  if (!DA?.store?.state) return;
  const { force = false } = opts;
  const state = DA.store.state;
  state.providerModelsCache = state.providerModelsCache || {};
  const pid = String(providerId || '').trim();
  if (!pid) return;

  if (force) {
    delete state.providerModelsCache[pid];
  }

  const existing = state.providerModelsCache[pid];
  if (existing?.status === 'loading') return;
  if (
    !force
    && existing?.status === 'ok'
    && Array.isArray(existing.models)
    && existing.models.length > 0
  ) {
    return;
  }

  const prov = (state.providers || []).find((x) => String(x.id) === pid);
  if (!prov || !providerSupportsModelDiscovery(prov)) {
    const dm = String(prov?.default_model || '').trim();
    const models = dm ? [{ id: dm, name: dm, details: '' }] : [];
    state.providerModelsCache[pid] = {
      status: models.length > 0 ? 'ok' : 'unsupported',
      models,
      error: '',
    };
    DA.render?.();
    return;
  }

  state.providerModelsCache[pid] = {
    status: 'loading',
    models: Array.isArray(existing?.models) ? existing.models : [],
    error: '',
  };
  DA.render?.();

  try {
    const result = await DA.services.ProviderService.fetchModels({ provider_id: pid });
    const models = Array.isArray(result?.models) ? result.models : [];
    state.providerModelsCache[pid] = { status: 'ok', models, error: '' };
  } catch (e) {
    const msg = String(e?.message || e || 'Error');
    state.providerModelsCache[pid] = { status: 'error', models: [], error: msg };
  }
  DA.render?.();
}

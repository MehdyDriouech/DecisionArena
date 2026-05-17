/**
 * Liste unifiée providers locaux (API) + commerciaux (BYOK) pour l’UI et les garde-fous.
 * Ne pas exposer les clés API dans les objets retournés.
 */

const LOCAL_TYPES = new Set(['ollama', 'lmstudio', 'openai-compatible']);

const COMMERCIAL_IDS = ['openai', 'anthropic', 'mistral', 'openrouter', 'gemini'];

const COMMERCIAL_LABELS = {
  openai: 'OpenAI',
  anthropic: 'Anthropic',
  mistral: 'Mistral AI',
  openrouter: 'OpenRouter',
  gemini: 'Google Gemini',
};

function validPriority(p) {
  const n = typeof p === 'number' && Number.isFinite(p) ? p : parseInt(String(p ?? ''), 10);
  if (!Number.isFinite(n) || n < 0 || n > 1000000) return null;
  return n;
}

/**
 * @typedef {{ id: string, name: string, type: string, enabled: boolean, priority: number, source: 'local'|'commercial' }} RoutingCandidatePublic
 */

/** @param {any} p row API */
export function normalizeLocalServerCandidate(p) {
  if (!p || !LOCAL_TYPES.has(p.type)) return null;
  if (p.enabled === false) return null;
  const base = String(p.base_url || '').trim();
  if (!base) return null;
  const type = p.type;
  const key = String(p.api_key || '').trim();
  if (type === 'openai-compatible' && !key) return null;
  const pr = validPriority(p.priority);
  if (pr === null) return null;
  return {
    id: String(p.id),
    name: String(p.name || p.id),
    type,
    enabled: true,
    priority: pr,
    source: /** @type {'local'} */ ('local'),
  };
}

/** @param {string} id @param {any} row store */
export function normalizeCommercialCandidate(id, row) {
  if (!row || !row.enabled) return null;
  const key = typeof row.apiKey === 'string' ? row.apiKey.trim() : '';
  if (!key) return null;
  const pr = validPriority(row.priority);
  if (pr === null) return null;
  return {
    id,
    name: COMMERCIAL_LABELS[id] || id,
    type: 'openai-compatible',
    enabled: true,
    priority: pr,
    source: /** @type {'commercial'} */ ('commercial'),
  };
}

/**
 * @param {any} state DecisionArena store.state
 * @returns {RoutingCandidatePublic[]}
 */
export function getAvailableProviders(state) {
  const providers = Array.isArray(state?.providers) ? state.providers : [];
  const ps = state?.providerSettings && typeof state.providerSettings === 'object' ? state.providerSettings : {};

  const byId = new Map();

  for (const p of providers) {
    const c = normalizeLocalServerCandidate(p);
    if (c) byId.set(c.id, c);
  }

  for (const id of COMMERCIAL_IDS) {
    const c = normalizeCommercialCandidate(id, ps[id]);
    if (c && !byId.has(c.id)) {
      byId.set(c.id, c);
    }
  }

  const list = Array.from(byId.values());
  list.sort((a, b) => {
    if (a.priority !== b.priority) return a.priority - b.priority;
    return String(a.id).localeCompare(String(b.id));
  });
  return list;
}

/** @param {any} state */
export function selectPrimaryProvider(state) {
  const all = getAvailableProviders(state);
  return all[0] || null;
}

/** @param {RoutingCandidatePublic} p */
export function formatRoutingOptionLabel(p) {
  if (!p) return '';
  const src = p.source === 'local' ? 'local' : 'cloud';
  return `${p.name} (${src}) — priorité ${p.priority}`;
}

export { COMMERCIAL_IDS, COMMERCIAL_LABELS, LOCAL_TYPES };

import { apiFetch } from './apiClient.js';

const ProviderService = {
  list() {
    return apiFetch('/api/providers');
  },
  create(payload) {
    return apiFetch('/api/providers', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  getRouting() {
    return apiFetch('/api/providers/routing');
  },
  updateRouting(payload) {
    return apiFetch('/api/providers/routing', {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
  remove(providerId) {
    return apiFetch(`/api/providers/${providerId}`, { method: 'DELETE' });
  },
  test(payload) {
    return apiFetch('/api/providers/test', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  fetchModels(payload) {
    return apiFetch('/api/providers/models', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

/**
 * Test BYOK credentials via the existing backend models discovery (server-side HTTP, no CORS issues).
 * Never throws; never embeds the API key in returned messages.
 *
 * @param {string} provider
 * @param {{ apiKey?: string, baseUrl?: string }} settings
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
async function testProviderConnection(provider, settings) {
  const apiKey = typeof settings?.apiKey === 'string' ? settings.apiKey.trim() : '';
  const baseUrl = typeof settings?.baseUrl === 'string' ? settings.baseUrl.trim() : '';
  const allowed = new Set(['openai', 'anthropic', 'mistral', 'openrouter']);

  if (!allowed.has(provider)) {
    return { ok: false, message: 'Fournisseur non pris en charge.' };
  }
  if (!apiKey) {
    return { ok: false, message: 'Aucune clé disponible pour ce test.' };
  }
  if (!baseUrl) {
    return { ok: false, message: 'Base URL manquante (réglage visible en mode expert).' };
  }

  const stripSensitive = (msg) => {
    let s = String(msg || 'Erreur inconnue');
    s = s.replace(/sk-[a-zA-Z0-9_-]{8,}/g, '[clé masquée]');
    s = s.replace(/Bearer\s+[a-zA-Z0-9._-]{12,}/gi, 'Bearer [masqué]');
    return s.slice(0, 500);
  };

  try {
    const result = await apiFetch('/api/providers/models', {
      method: 'POST',
      body: JSON.stringify({
        type: 'openai-compatible',
        base_url: baseUrl,
        api_key: apiKey,
      }),
    });
    const models = Array.isArray(result?.models) ? result.models : [];
    if (models.length === 0) {
      return { ok: false, message: 'Réponse fournisseur vide (aucun modèle listé).' };
    }
    return { ok: true, message: `Connexion OK — ${models.length} modèle(s) listé(s).` };
  } catch (err) {
    const name = err && typeof err.name === 'string' ? err.name : '';
    const offlineish = typeof navigator !== 'undefined' && navigator.onLine === false;
    if (
      offlineish ||
      name === 'TypeError' ||
      /failed to fetch|networkerror|load failed/i.test(String(err && err.message))
    ) {
      return {
        ok: false,
        message:
          'Clé sauvegardée. Test réseau indisponible dans ce contexte.',
      };
    }
    return { ok: false, message: stripSensitive(err.message) };
  }
}

export { ProviderService, testProviderConnection };

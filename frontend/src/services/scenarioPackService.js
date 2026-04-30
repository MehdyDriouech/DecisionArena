import { apiFetch } from './apiClient.js';

const ScenarioPackService = {
  list(adminMode = false) {
    return apiFetch(`/api/scenario-packs${adminMode ? '?admin=1' : ''}`);
  },
  get(id) {
    return apiFetch(`/api/scenario-packs/${id}`);
  },
  create(payload) {
    return apiFetch('/api/scenario-packs', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  update(id, payload) {
    return apiFetch(`/api/scenario-packs/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
  remove(id) {
    return apiFetch(`/api/scenario-packs/${id}`, { method: 'DELETE' });
  },
  duplicate(id, payload) {
    return apiFetch(`/api/scenario-packs/${id}/duplicate`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

export { ScenarioPackService };

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

export { ProviderService };

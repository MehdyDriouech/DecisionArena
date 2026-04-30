import { apiFetch } from './apiClient.js';

const ComparisonService = {
  list() {
    return apiFetch('/api/session-comparisons');
  },
  create(payload) {
    return apiFetch('/api/session-comparisons', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  get(comparisonId) {
    return apiFetch(`/api/session-comparisons/${comparisonId}`);
  },
  remove(comparisonId) {
    return apiFetch(`/api/session-comparisons/${comparisonId}`, {
      method: 'DELETE',
    });
  },
};

export { ComparisonService };

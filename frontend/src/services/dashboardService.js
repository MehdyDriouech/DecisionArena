import { apiFetch } from './apiClient.js';

const DashboardService = {
  getCognitiveSummary(options = {}) {
    const contextId = typeof options.contextId === 'string' ? options.contextId.trim() : '';
    if (!contextId || contextId === 'auto') {
      return apiFetch('/api/dashboard/cognitive-summary');
    }
    return apiFetch(`/api/dashboard/cognitive-summary?context_id=${encodeURIComponent(contextId)}`);
  },
};

export { DashboardService };

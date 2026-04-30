import { apiFetch } from './apiClient.js';

const SoulService = {
  list() {
    return apiFetch('/api/souls');
  },
};

export { SoulService };

import { apiFetch } from './apiClient.js';

const LaunchAssistantService = {
  recommend(payload) {
    return apiFetch('/api/launch-assistant/recommend', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

export { LaunchAssistantService };

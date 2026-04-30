import { apiFetch } from './apiClient.js';

const PersonaService = {
  list() {
    return apiFetch('/api/personas');
  },
  saveCustom(payload) {
    return apiFetch('/api/personas/save-custom', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  make(payload) {
    return apiFetch('/api/personas/make', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  buildDraft(payload) {
    return apiFetch('/api/personas/build-draft', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  updateModes(payload) {
    return apiFetch('/api/personas/modes', {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
};

export { PersonaService };

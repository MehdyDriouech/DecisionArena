import { apiFetch } from './apiClient.js';
import { withProviderRuntime } from '../core/providerRuntime.js';

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
      body: JSON.stringify(withProviderRuntime(payload)),
    });
  },
  buildDraft(payload) {
    return apiFetch('/api/personas/build-draft', {
      method: 'POST',
      body: JSON.stringify(withProviderRuntime(payload)),
    });
  },
  updateModes(payload) {
    return apiFetch('/api/personas/modes', {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
  updateDefaultLlm(payload) {
    return apiFetch('/api/personas/default-llm', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

export { PersonaService };

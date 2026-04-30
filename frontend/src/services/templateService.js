import { apiFetch } from './apiClient.js';

const TemplateService = {
  list() {
    return apiFetch('/api/templates');
  },
  create(payload) {
    return apiFetch('/api/templates', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  update(templateId, payload) {
    return apiFetch(`/api/templates/${templateId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },
  remove(templateId) {
    return apiFetch(`/api/templates/${templateId}`, {
      method: 'DELETE',
    });
  },
  duplicate(templateId, payload) {
    return apiFetch(`/api/templates/${templateId}/duplicate`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
  make(payload) {
    return apiFetch('/api/templates/make', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

export { TemplateService };

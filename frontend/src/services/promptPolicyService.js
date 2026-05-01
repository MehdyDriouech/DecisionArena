import { apiFetch } from './apiClient.js';

const PromptPolicyService = {
  /** GET /api/prompt-policies → { items: [...] } */
  list() {
    return apiFetch('/api/prompt-policies');
  },

  /** GET /api/prompt-policies/{id} → { id, title, filename, description, content } */
  get(id) {
    return apiFetch(`/api/prompt-policies/${encodeURIComponent(id)}`);
  },

  /** PUT /api/prompt-policies/{id} with { content } → { ok, id } */
  update(id, content) {
    return apiFetch(`/api/prompt-policies/${encodeURIComponent(id)}`, {
      method: 'PUT',
      body: JSON.stringify({ content }),
    });
  },
};

export { PromptPolicyService };

import { apiFetch } from './apiClient.js';

const RiskProfileService = {
  /** GET /api/sessions/{id}/risk-profile → { risk_profile, generated } */
  getProfile(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/risk-profile`);
  },

  /** POST /api/sessions/{id}/risk-profile/recompute → { risk_profile, recomputed } */
  recompute(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/risk-profile/recompute`, {
      method: 'POST',
    });
  },
};

export { RiskProfileService };

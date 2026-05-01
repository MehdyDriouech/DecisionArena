import { apiFetch } from './apiClient.js';

const EvidenceService = {
  /** GET /api/sessions/{id}/evidence-report → { evidence_report, generated } */
  getReport(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/evidence-report`);
  },

  /** GET /api/sessions/{id}/evidence-claims → { claims: [...] } */
  getClaims(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/evidence-claims`);
  },

  /** POST /api/sessions/{id}/evidence/recompute → { evidence_report, recomputed } */
  recompute(sessionId) {
    return apiFetch(`/api/sessions/${encodeURIComponent(sessionId)}/evidence/recompute`, {
      method: 'POST',
    });
  },
};

export { EvidenceService };

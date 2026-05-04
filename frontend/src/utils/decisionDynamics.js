/**
 * Normalisation et affichage de la dynamique de décision (personas / sessions).
 * Aucune inférence : uniquement bornage et enum stricts.
 */

export const DECISION_DYNAMICS_REPUTATION_LEVELS = [0.8, 0.9, 1.0, 1.1, 1.2, 1.3];

const CONSENSUS = ['low', 'normal', 'strong'];
const EVIDENCE = ['low', 'normal', 'high'];
const RISK = ['cautious', 'balanced', 'bold'];

/**
 * @param {unknown} input
 * @param {{ snapReputation?: boolean }} [options] — snap sur les paliers admin (défaut true)
 * @returns {{ reputation: number, consensus_resistance: string, evidence_sensitivity: string, risk_tolerance: string }}
 */
export function normalizeDecisionDynamics(input, options = {}) {
  const snap = options.snapReputation !== false;
  const d = input && typeof input === 'object' ? input : {};
  let rep = parseFloat(d.reputation);
  if (!Number.isFinite(rep)) rep = 1.0;
  rep = Math.min(1.3, Math.max(0.8, rep));
  if (snap) {
    rep = DECISION_DYNAMICS_REPUTATION_LEVELS.reduce(
      (best, v) => (Math.abs(v - rep) < Math.abs(best - rep) ? v : best),
      1.0
    );
  } else {
    rep = Math.round(rep * 1000) / 1000;
  }

  const cr = typeof d.consensus_resistance === 'string' && CONSENSUS.includes(d.consensus_resistance)
    ? d.consensus_resistance
    : 'normal';
  const ev = typeof d.evidence_sensitivity === 'string' && EVIDENCE.includes(d.evidence_sensitivity)
    ? d.evidence_sensitivity
    : 'normal';
  const rt = typeof d.risk_tolerance === 'string' && RISK.includes(d.risk_tolerance)
    ? d.risk_tolerance
    : 'balanced';

  return {
    reputation: rep,
    consensus_resistance: cr,
    evidence_sensitivity: ev,
    risk_tolerance: rt,
  };
}

/**
 * Réputation appliquée au vote : priorité per_vote_weighting, sinon agent_decision_dynamics.
 * @returns {number|null} null si aucune donnée fiable
 */
export function getVoteAppliedReputation(vote, source) {
  const aid = String(vote?.agent_id ?? '');
  if (!aid) return null;

  if (vote?.applied_reputation != null) {
    const vn = Number(vote.applied_reputation);
    if (Number.isFinite(vn)) return vn;
  }

  const pickList = (x) => (Array.isArray(x) ? x : []);
  const d1 = source?.adjusted_decision?.vote_summary?.per_vote_weighting;
  const d2 = source?.automatic_decision?.vote_summary?.per_vote_weighting;
  const d3 = source?.raw_decision?.vote_summary?.per_vote_weighting;
  const list = pickList(d1).length ? pickList(d1) : pickList(d2).length ? pickList(d2) : pickList(d3);

  const hit = list.find((x) => String(x?.agent_id ?? '') === aid);
  if (hit && hit.applied_reputation != null) {
    const n = Number(hit.applied_reputation);
    if (Number.isFinite(n)) return n;
  }

  const rows = source?.agent_decision_dynamics;
  if (Array.isArray(rows)) {
    const row = rows.find((r) => String(r?.agent ?? '') === aid);
    if (row?.reputation != null) {
      const n = Number(row.reputation);
      if (Number.isFinite(n)) return n;
    }
    const dyn = row?.dynamics;
    if (dyn && dyn.reputation != null) {
      const n = Number(dyn.reputation);
      if (Number.isFinite(n)) return n;
    }
  }
  return null;
}

/** @param {number} rep */
export function reputationBadgeVariant(rep) {
  if (!Number.isFinite(rep)) return 'neutral';
  if (Math.abs(rep - 1.0) < 0.001) return 'neutral';
  if (rep > 1.0) return 'boost';
  return 'reduce';
}

export function nearestAdminReputationStep(rep) {
  if (!Number.isFinite(rep)) return 1.0;
  const r = Math.min(1.3, Math.max(0.8, rep));
  return DECISION_DYNAMICS_REPUTATION_LEVELS.reduce(
    (best, v) => (Math.abs(v - r) < Math.abs(best - r) ? v : best),
    1.0
  );
}

/** Delta max pour réutiliser les libellés « palier admin » (sinon message factuel). */
const ADMIN_REPUTATION_LABEL_MATCH_EPS = 0.03;

export function reputationTier(rep) {
  if (!Number.isFinite(rep)) return 'neutral';
  if (rep >= 1.25) return 'max';
  if (rep >= 1.15) return 'very_high';
  if (rep >= 1.05) return 'high';
  if (rep <= 0.85) return 'low';
  if (rep <= 0.95) return 'slightly_low';
  return 'neutral';
}

/**
 * Légende courte pour la colonne votes (donnée observée uniquement).
 * @param {number} rep
 * @param {(k: string) => string} t
 */
export function formatVoteReputationCaption(rep, t) {
  if (!Number.isFinite(rep)) return '';
  const r = Math.round(rep * 1000) / 1000;
  const nearest = nearestAdminReputationStep(r);
  const onAdminTierForLabel = Math.abs(r - nearest) <= ADMIN_REPUTATION_LABEL_MATCH_EPS;

  let base;
  if (onAdminTierForLabel) {
    const tier = reputationTier(nearest);
    const key = `session.dynamics.reputationTier.${tier}`;
    const msg = t(key);
    base = msg !== key ? msg : t('session.dynamics.weightConfiguredGeneric');
  } else {
    const eff = t('session.dynamics.reputationExplainEffective');
    base = eff !== 'session.dynamics.reputationExplainEffective' ? eff : t('session.dynamics.weightConfiguredGeneric');
  }

  let fmtDecimals = 2;
  if (Math.abs(r - Math.round(r)) < 1e-6) {
    fmtDecimals = 1;
  } else if (Math.abs(r * 100 - Math.round(r * 100)) < 1e-4) {
    fmtDecimals = 2;
  } else {
    fmtDecimals = 3;
  }

  return `${t('session.dynamics.weight')}: ×${r.toFixed(fmtDecimals)} — ${base}`;
}

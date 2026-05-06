import {
  DECISION_DYNAMICS_REPUTATION_LEVELS,
  normalizeDecisionDynamics,
  reputationBadgeVariant,
} from '../utils/decisionDynamics.js';
import { dynamicsPresetDisplayLabel } from '../utils/sessionDynamicsPresetUi.js';

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function attrsToHtml(attrs = {}) {
  return Object.entries(attrs)
    .filter(([, v]) => v !== null && v !== undefined && v !== false)
    .map(([k, v]) => v === true ? escapeHtml(k) : `${escapeHtml(k)}="${escapeHtml(String(v))}"`)
    .join(' ');
}

function renderCard({ bodyHtml = '', footerHtml = '', className = '', id = '', attrs = {} } = {}) {
  const idAttr = id ? ` id="${escapeHtml(id)}"` : '';
  const extra = attrsToHtml(attrs);
  const cls = ['card', className].filter(Boolean).join(' ');
  return `<div class="${escapeHtml(cls)}"${idAttr}${extra ? ' ' + extra : ''}>${bodyHtml}${footerHtml}</div>`;
}

function renderSectionHeader({ title = '', subtitle = '', className = '', id = '' } = {}) {
  const idAttr = id ? ` id="${escapeHtml(id)}"` : '';
  const cls = ['section-header', className].filter(Boolean).join(' ');
  const sub = subtitle ? `<p class="card-description">${escapeHtml(subtitle)}</p>` : '';
  return `<div class="${escapeHtml(cls)}"${idAttr}><h3>${escapeHtml(title)}</h3>${sub}</div>`;
}

function renderEmptyState({ icon = '', text = '', actionsHtml = '' } = {}) {
  const iconHtml = icon ? `<div class="empty-state-icon">${escapeHtml(icon)}</div>` : '';
  return `<div class="empty-state">${iconHtml}<div class="empty-state-text">${escapeHtml(text)}</div>${actionsHtml}</div>`;
}

function renderBadge({ text = '', variant = 'default', attrs = {} } = {}) {
  const extra = attrsToHtml(attrs);
  return `<span class="badge badge-${escapeHtml(variant)}"${extra ? ' ' + extra : ''}>${escapeHtml(text)}</span>`;
}

function renderAlert({ text = '', bodyHtml = '', variant = 'info' } = {}) {
  const content = bodyHtml || escapeHtml(text);
  return `<div class="alert alert-${escapeHtml(variant)}">${content}</div>`;
}

function renderActionBar({ actionsHtml = '', className = '' } = {}) {
  const cls = ['action-bar', className].filter(Boolean).join(' ');
  return `<div class="${escapeHtml(cls)}">${actionsHtml}</div>`;
}

function renderAdvancedPanel({ bodyHtml = '', title = '', className = '' } = {}) {
  const cls = ['advanced-panel', className].filter(Boolean).join(' ');
  const titleHtml = title ? `<div class="advanced-panel-title">${escapeHtml(title)}</div>` : '';
  return `<div class="${escapeHtml(cls)}" data-ui="expert-only">${titleHtml}${bodyHtml}</div>`;
}

/**
 * Modal minimal pour Connecter / Modifier la clé BYOK (clés locales navigateur uniquement).
 */
function renderByokProviderConnectModal() {
  return `
<dialog id="provider-byok-modal" class="provider-byok-modal" aria-labelledby="provider-byok-modal-title">
  <div class="provider-byok-modal-panel card">
    <div class="provider-byok-modal-head">
      <h3 id="provider-byok-modal-title" class="provider-byok-modal-title"></h3>
      <button type="button" class="provider-byok-modal-close" data-action="close-provider-modal" aria-label="Fermer">×</button>
    </div>
    <p class="card-description provider-byok-modal-lead">La clé est enregistrée dans ce navigateur. Une vérification silencieuse est lancée à l’enregistrement.</p>
    <div class="form-group">
      <label for="byok-modal-api-key">Clé API</label>
      <input id="byok-modal-api-key" type="password" class="input"
        autocomplete="off" autocapitalize="off" spellcheck="false"
        data-action="provider-key-input" data-provider="" />
    </div>
    <details class="byok-modal-advanced" data-ui="expert-only">
      <summary class="byok-modal-advanced-summary">Détails</summary>
      <div data-ui="expert-only" style="margin-top:10px;">
        <div class="form-group">
          <label for="byok-modal-priority">Priorité</label>
          <input id="byok-modal-priority" type="number" step="1" min="0" class="input" value="100" autocomplete="off" data-provider="" />
        </div>
        <div class="form-group">
          <label for="byok-modal-default-model">Modèle par défaut</label>
          <input id="byok-modal-default-model" type="text" class="input" placeholder="" autocomplete="off" data-provider="" />
        </div>
        <div class="form-group">
          <label for="byok-modal-base-url">Base URL</label>
          <input id="byok-modal-base-url" type="text" class="input byok-base-url-input" autocomplete="off" data-provider="" />
        </div>
      </div>
    </details>
    <div class="byok-modal-feedback provider-test-result" hidden role="status" aria-live="polite" data-feedback-slot="modal"></div>
    <div class="provider-byok-modal-actions">
      <button type="button" class="btn btn-danger btn-sm" id="byok-modal-disconnect" data-action="delete-provider-key" data-provider="" hidden>Déconnecter</button>
      <button type="button" class="btn btn-secondary btn-sm" data-action="close-provider-modal">Annuler</button>
      <button type="button" class="btn btn-primary btn-sm" data-action="save-provider-key">Connecter</button>
    </div>
  </div>
</dialog>`;
}

function createErrorBanner(message, clearLabel = 'Clear') {
  if (!message) return '';
  return `
    <div class="error-banner">
      ⚠️ ${message}
      <button data-action="clear-error">${clearLabel}</button>
    </div>
  `;
}

function mountHtml(target, html) {
  if (!target) return;
  target.innerHTML = html;
}

/**
 * Renders a contextual description + optional "when to use" line below a card title.
 * @param {{ text: string, usage?: string }} opts
 */
function renderCardDescription({ text, usage = '' }) {
  if (!text) return '';
  return `<div class="card-description">${text}</div>${usage ? `<div class="card-usage">👉 ${usage}</div>` : ''}`;
}

/**
 * Renders a pure-CSS tooltip badge (shows on hover, no JS).
 * @param {string} tooltip — tooltip text
 */
function renderTooltip(tooltip) {
  if (!tooltip) return '';
  // Escape quotes for attribute safety
  const safe = tooltip.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  return `<span class="info-tooltip" data-tooltip="${safe}" aria-label="${safe}">?</span>`;
}

function normalizeComponentUiMode(value) {
  if (value === 'expert' || value === 'advanced') return 'expert';
  if (value === 'simple' || value === 'basic') return 'simple';
  return 'simple';
}

function resolveUiMode(opts = {}) {
  const state = window.DecisionArena?.store?.state || {};
  return normalizeComponentUiMode(opts.uiMode ?? state.uiMode);
}

function renderPanelRecommendBadge(panelType, highlights, t) {
  const list = Array.isArray(highlights) ? highlights : [];
  const h = list.find((x) => x && x.type === panelType);
  if (!h) return '';
  const key = `highlight.reason.${h.reason_key}`;
  const reason = t(key);
  const text = reason !== key ? reason : String(h.reason_key || '').replace(/_/g, ' ');
  return `<span class="panel-rec-badge"><span class="badge badge-info" style="font-size:10px;">${t('highlight.recommended')}</span>${renderTooltip(text)}</span>`;
}

/**
 * Bouton Pre-Mortem : GO + confiance/qualité élevées.
 */
function shouldShowPremortemPrimaryCTA(data) {
  if (!data || typeof data !== 'object') return false;
  const dec = String(data.decision || '').toUpperCase();
  const conf = String(data.confidence || '').toUpperCase();
  const rel = String(data.reliability || '').toUpperCase();
  const score = Number(data.quality_score);
  if (dec === 'GO') {
    if (conf === 'HIGH' || rel === 'CONFIDENT') return true;
    if (Number.isFinite(score) && score >= 72) return true;
  }
  return false;
}

/**
 * Bandeau scénario inversé pour les sessions Pre-Mortem.
 */
function renderPremortemInvertedBanner(t, escHtml) {
  return `
    <div class="premortem-inverted-banner info-banner" style="margin-bottom:16px;padding:12px 16px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.35);border-radius:8px;font-size:13px;">
      <div style="font-weight:700;margin-bottom:4px;">🔮 ${escHtml(t('premortem.invertedTitle'))}</div>
      <div style="color:var(--text-secondary);line-height:1.45;">${escHtml(t('premortem.invertedLead'))}</div>
    </div>`;
}

/**
 * Synthèse structurée pré-mortem parsée depuis le markdown / JSON synthétiseur.
 * @param {object|null} summary
 */
function renderPremortemStructuredCard(summary, t, escHtml) {
  if (!summary || typeof summary !== 'object') return '';

  const listSection = (key, items) => {
    const arr = Array.isArray(items) ? items.filter(Boolean) : [];
    if (!arr.length) return '';
    return `
      <div style="margin-top:12px;">
        <div style="font-weight:600;font-size:12px;color:var(--text-secondary);margin-bottom:6px;">${escHtml(t(key))}</div>
        <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.45;color:var(--text-primary);">${arr.map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>
      </div>`;
  };

  const fs = summary.failure_scenario ? `<p style="margin:0 0 8px;line-height:1.5;color:var(--text-primary);font-size:13px;"><strong>${escHtml(t('premortem.failureScenario'))}</strong> — ${escHtml(String(summary.failure_scenario))}</p>` : '';
  const risk = summary.residual_risk_level
    ? `<div style="margin-top:12px;font-size:12px;"><span style="font-weight:600;">${escHtml(t('premortem.residualRisk'))}:</span> ${escHtml(String(summary.residual_risk_level))}</div>`
    : '';

  return `
    <div class="card premortem-summary-card" style="margin-bottom:16px;padding:16px 18px;border:1px solid rgba(99,102,241,0.25);background:rgba(99,102,241,0.04);border-radius:8px;">
      <div style="font-weight:700;font-size:15px;margin-bottom:8px;color:var(--text-primary);">📋 ${escHtml(t('premortem.summaryTitle'))}</div>
      ${fs}
      ${listSection('premortem.rootCauses', summary.root_causes)}
      ${listSection('premortem.invalidAssumptions', summary.invalid_assumptions)}
      ${listSection('premortem.ignoredSignals', summary.ignored_signals)}
      ${listSection('premortem.preventiveActions', summary.preventive_actions)}
      ${risk}
    </div>`;
}

function formatDecisionBriefConfidence(value) {
  if (value === null || value === undefined || value === '') return 'N/A';
  if (typeof value === 'number' && Number.isFinite(value)) {
    const pct = value >= 0 && value <= 1 ? Math.round(value * 100) : Math.round(value);
    return `${pct}%`;
  }
  const raw = String(value).trim();
  if (!raw) return 'N/A';
  if (raw.includes('%')) return raw;
  const numeric = Number(raw);
  if (Number.isFinite(numeric)) {
    const pct = numeric >= 0 && numeric <= 1 ? Math.round(numeric * 100) : Math.round(numeric);
    return `${pct}%`;
  }
  return raw;
}

function cleanDecisionBriefText(value) {
  return String(value ?? '')
    .replace(/```[\s\S]*?```/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Decision-first brief, safe for missing fields.
 * @param {{ decision?: string, confidence?: string|number, why?: string[]|string, risks?: string[]|string, nextStep?: string, next_step?: string }} data
 */
function renderDecisionBrief(data, opts = {}) {
  const d = data && typeof data === 'object' ? data : {};
  const escHtml = window.DecisionArena?.utils?.escHtml || ((v) => String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;'));
  const uiMode = resolveUiMode(opts);
  const isExpert = uiMode === 'expert';

  const decision = d.decision ? cleanDecisionBriefText(d.decision) : 'Non d\u00e9termin\u00e9e';
  const confidence = formatDecisionBriefConfidence(d.confidence);
  const whyList = (Array.isArray(d.why) ? d.why : (d.why ? [d.why] : []))
    .map(cleanDecisionBriefText)
    .filter(Boolean);
  const riskList = (Array.isArray(d.risks) ? d.risks : (d.risks ? [d.risks] : []))
    .map(cleanDecisionBriefText)
    .filter(Boolean);
  const why = whyList.length ? whyList.join(' ') : 'Aucune synth\u00e8se disponible.';
  const nextStep = cleanDecisionBriefText(d.nextStep || d.next_step) || 'Approfondir l\u2019analyse';
  const risksHtml = riskList.length
    ? riskList.map((risk) => `<li>${escHtml(risk)}</li>`).join('')
    : '<li>Aucun risque disponible dans les donn&eacute;es actuelles.</li>';

  const v = d.validation_logic && typeof d.validation_logic === 'object' ? d.validation_logic : null;
  const successSignal = v?.success_signal ? cleanDecisionBriefText(v.success_signal) : '';
  const validationThreshold = v?.validation_threshold ? cleanDecisionBriefText(v.validation_threshold) : '';
  const failureSignal = v?.failure_signal ? cleanDecisionBriefText(v.failure_signal) : '';
  const killCriteria = v?.kill_criteria ? cleanDecisionBriefText(v.kill_criteria) : '';
  const contradictions = Array.isArray(v?.contradictions) ? v.contradictions.map(cleanDecisionBriefText).filter(Boolean) : [];

  const hasValidation = !!(successSignal || validationThreshold || failureSignal || killCriteria || contradictions.length);
  const validationHtml = !hasValidation ? '' : `
  <div class="decision-validation" style="margin-top:12px;">
    <strong>Validation :</strong>
    <div style="margin-top:6px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
      ${successSignal ? `<div><div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Success signal</div><div>${escHtml(successSignal)}</div></div>` : ''}
      ${killCriteria ? `<div><div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Kill criteria</div><div>${escHtml(killCriteria)}</div></div>` : ''}
      ${isExpert && validationThreshold ? `<div><div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Validation threshold</div><div>${escHtml(validationThreshold)}</div></div>` : ''}
      ${isExpert && failureSignal ? `<div><div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Failure signal</div><div>${escHtml(failureSignal)}</div></div>` : ''}
    </div>
    ${isExpert && contradictions.length ? `
      <div style="margin-top:10px;">
        <div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Contradictions / objections</div>
        <ul style="margin:0;padding-left:18px;">${contradictions.slice(0, 5).map((x) => `<li>${escHtml(x)}</li>`).join('')}</ul>
      </div>
    ` : ''}
  </div>`;

  return `
<div class="decision-brief">
  <div class="decision-header">
    <div class="decision-label">D&eacute;cision</div>
    <div class="decision-value">${escHtml(decision)}</div>
  </div>

  <div class="decision-meta">
    <span class="confidence">Confiance : ${escHtml(confidence)}</span>
  </div>

  <div class="decision-why">
    <strong>Pourquoi :</strong>
    <p>${escHtml(why)}</p>
  </div>

  <div class="decision-risks">
    <strong>Risques :</strong>
    <ul>${risksHtml}</ul>
  </div>

  ${validationHtml}

  <div class="decision-next">
    <strong>Prochaine &eacute;tape :</strong>
    <p>${escHtml(nextStep)}</p>
  </div>
</div>`;
}

function pickTradeoffs(decisionBrief) {
  let b = decisionBrief;
  if (typeof b === 'string') {
    try {
      b = JSON.parse(b);
    } catch (_) {
      return null;
    }
  }
  if (!b || typeof b !== 'object') return null;
  const tr = b.tradeoffs;
  if (!tr || typeof tr !== 'object') return null;
  if (tr.enabled === false) return null;
  const criteria = Array.isArray(tr.criteria)
    ? tr.criteria.filter((row) => row && typeof row === 'object' && row.justification)
    : [];
  if (criteria.length === 0) return null;
  const summary = tr.summary != null ? String(tr.summary).trim() : '';
  const opts = Array.isArray(tr.options)
    ? tr.options.filter((o) => o && typeof o === 'object' && Array.isArray(o.criteria) && o.criteria.some((row) => row && row.justification))
    : null;
  return {
    enabled: tr.enabled !== false,
    criteria,
    summary,
    options: opts && opts.length > 0 ? opts : null,
  };
}

function tradeoffCriterionLabel(row, t) {
  const id = String(row?.id || '').trim();
  if (row.label) return String(row.label);
  const key = `tradeoff.crit.${id}`;
  const lbl = t(key);
  return lbl !== key ? lbl : id || '—';
}

function tradeoffShortLabel(text, max = 22) {
  const x = String(text || '').trim();
  if (x.length <= max) return x;
  return `${x.slice(0, max - 1)}…`;
}

/**
 * @param {object[]} criteria
 */
function renderTradeoffMatrix(criteria, t, escHtml) {
  const rows = criteria.map((row) => {
    const lbl = tradeoffCriterionLabel(row, t);
    const cf = row.confidence ? ` <span class="tradeoff-muted">(${escHtml(String(row.confidence))})</span>` : '';
    const sc = Number(row.score);
    const scLabel = Number.isFinite(sc) ? `${sc}/5` : '—';
    return `<tr><td>${escHtml(lbl)}</td><td class="tradeoff-score">${escHtml(scLabel)}</td><td>${escHtml(String(row.justification || ''))}${cf}</td></tr>`;
  }).join('');
  return `
    <div class="tradeoff-table-wrap">
      <table class="tradeoff-table">
        <thead><tr><th scope="col">${escHtml(t('tradeoff.criteria'))}</th><th scope="col">${escHtml(t('tradeoff.score'))}</th><th scope="col">${escHtml(t('tradeoff.justification'))}</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

/**
 * Radar SVG (3–8 axes). Returns empty string if fewer than 3 criteria.
 * @param {object[]} criteria
 */
function renderRadarChart(criteria, t, escHtml) {
  const n = criteria.length;
  if (n < 3) return '';

  const W = 240;
  const cx = W / 2;
  const cy = W / 2;
  const pad = 54;
  const maxR = W / 2 - pad;
  const levels = 5;

  let rings = '';
  for (let L = 1; L <= levels; L += 1) {
    const r = (maxR * L) / levels;
    rings += `<circle cx="${cx}" cy="${cy}" r="${r.toFixed(3)}" fill="none" stroke="var(--border, rgba(148,163,184,0.4))" stroke-width="1"/>`;
  }

  let spokes = '';
  let labels = '';
  for (let i = 0; i < n; i += 1) {
    const ang = (-Math.PI / 2) + (i * 2 * Math.PI) / n;
    const x2 = cx + maxR * Math.cos(ang);
    const y2 = cy + maxR * Math.sin(ang);
    spokes += `<line x1="${cx}" y1="${cy}" x2="${x2.toFixed(2)}" y2="${y2.toFixed(2)}" stroke="var(--border, rgba(148,163,184,0.5))" stroke-width="1"/>`;
    const lr = maxR + 24;
    const lx = cx + lr * Math.cos(ang);
    const ly = cy + lr * Math.sin(ang);
    const lbl = tradeoffShortLabel(tradeoffCriterionLabel(criteria[i], t), 26);
    let anchor = 'middle';
    if (lx < cx - 12) anchor = 'end';
    else if (lx > cx + 12) anchor = 'start';
    labels += `<text x="${lx.toFixed(2)}" y="${ly.toFixed(2)}" text-anchor="${anchor}" dominant-baseline="middle" font-size="10" fill="var(--text-secondary, #94a3b8)">${escHtml(lbl)}</text>`;
  }

  const pts = [];
  for (let i = 0; i < n; i += 1) {
    const ang = (-Math.PI / 2) + (i * 2 * Math.PI) / n;
    const raw = Number(criteria[i].score);
    const sc = Math.max(1, Math.min(5, Number.isFinite(raw) ? raw : 3));
    const r = (sc / 5) * maxR;
    const x = cx + r * Math.cos(ang);
    const y = cy + r * Math.sin(ang);
    pts.push(`${x.toFixed(2)},${y.toFixed(2)}`);
  }
  const poly = `<polygon fill="rgba(99,102,241,0.2)" stroke="var(--accent, #818cf8)" stroke-width="2" stroke-linejoin="round" points="${pts.join(' ')}" />`;

  return `<svg class="tradeoff-radar-svg" viewBox="0 0 ${W} ${W}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="${escHtml(t('tradeoff.radar'))}">${rings}${spokes}${poly}${labels}</svg>`;
}

/**
 * Rend table + radar pour un jeu de critères (une « vue » ou une option comparée).
 * @param {object[]} criteria
 */
function renderTradeoffPaneInner(criteria, isAdv, t, escHtml) {
  const C = Array.isArray(criteria) ? criteria.filter((row) => row && typeof row === 'object') : [];
  const radarSlice = C.length > 8 ? C.slice(0, 8) : C;
  const matrix = renderTradeoffMatrix(C, t, escHtml);
  const radar = isAdv && radarSlice.length >= 3 ? renderRadarChart(radarSlice, t, escHtml) : '';
  return `
    <div class="tradeoff-table-note tradeoff-muted">${escHtml(t('tradeoff.table'))}</div>
    ${matrix}
    ${radar ? `<div class="tradeoff-radar"><div class="tradeoff-muted tradeoff-radar-caption">${escHtml(t('tradeoff.radar'))}</div>${radar}</div>` : ''}`;
}

/**
 * Matrice de compromis + radar ; comparaison d’options si `tradeoffs.options` présent.
 * @param {object|null} decisionBrief
 * @param {{ uiMode?: string, tradeoffUid?: string }} opts
 */
function renderTradeoffSection(decisionBrief, opts = {}) {
  const bundle = pickTradeoffs(decisionBrief);
  if (!bundle) return '';

  const escHtml = window.DecisionArena?.utils?.escHtml || ((v) => String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;'));
  const t = (key) => window.i18n?.t(key) ?? key;
  const uiMode = resolveUiMode(opts);
  const isAdv = uiMode === 'expert';
  const expanded = uiMode === 'expert';

  let uid = opts.tradeoffUid ?? (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID().slice(0, 12) : `to_${Date.now().toString(36)}`);
  uid = String(uid).replace(/[^a-zA-Z0-9_-]/g, '') || `to_${Date.now().toString(36)}`;

  const extras = Array.isArray(bundle.options) ? bundle.options : [];
  const panes = [
    {
      labelHtml: escHtml(t('tradeoff.primaryRecommendation')),
      criteria: bundle.criteria,
    },
  ];
  extras.forEach((o, ix) => {
    if (!o || typeof o !== 'object') return;
    const c = Array.isArray(o.criteria) ? o.criteria : [];
    if (c.length === 0) return;
    panes.push({
      labelHtml: escHtml(String(o.label || `${t('tradeoff.optionFallback')} ${ix + 1}`)),
      criteria: c,
    });
  });

  let compareBar = '';
  if (panes.length > 1) {
    const optsHtml = panes
      .map((p, idx) => `<option value="${idx}">${idx === 0 ? escHtml(t('tradeoff.primaryRecommendation')) : p.labelHtml}</option>`)
      .join('');
    compareBar = `
    <div class="tradeoff-options-bar">
      <label class="tradeoff-muted tradeoff-compare-label" for="tradeoff-sel-${escHtml(uid)}">${escHtml(t('tradeoff.compareOptions'))}</label>
      <select id="tradeoff-sel-${escHtml(uid)}" class="select tradeoff-option-select" data-action="tradeoff-select-option" data-tradeoff-scope="${escHtml(uid)}" aria-label="${escHtml(t('tradeoff.compareOptions'))}">
        ${optsHtml}
      </select>
    </div>`;
  }

  const panesHtml = panes
    .map((p, idx) => {
      const hiddenInner = idx > 0 ? ' hidden' : '';
      return `<div class="tradeoff-option-pane"${hiddenInner} data-tradeoff-scope="${escHtml(uid)}" data-tradeoff-pane-index="${idx}">
      ${idx > 0 ? `<div class="tradeoff-pane-label tradeoff-muted">${p.labelHtml}</div>` : ''}
      ${renderTradeoffPaneInner(p.criteria, isAdv, t, escHtml)}
    </div>`;
    })
    .join('');

  const summaryBlock = bundle.summary
    ? `<p class="tradeoff-summary-line">${escHtml(bundle.summary)}</p>`
    : '';

  const showLabel = expanded ? t('tradeoff.hide') : t('tradeoff.show');
  const ariaEx = expanded ? 'true' : 'false';

  return `
<div class="tradeoff-card" data-tradeoff-scope="${escHtml(uid)}">
  <button type="button" class="tradeoff-summary tradeoff-toggle-btn btn btn-secondary btn-sm" data-action="toggle-tradeoffs" data-tradeoff-scope="${escHtml(uid)}" aria-expanded="${ariaEx}" aria-controls="tradeoff-panel-${escHtml(uid)}">${escHtml(showLabel)}</button>
  <div id="tradeoff-panel-${escHtml(uid)}" class="tradeoff-panel"${expanded ? '' : ' hidden'}>
    <div class="tradeoff-inner">
      <div class="tradeoff-head">
        <h4 class="tradeoff-title">${escHtml(t('tradeoff.title'))}</h4>
        <p class="tradeoff-muted tradeoff-desc">${escHtml(t('tradeoff.desc'))}</p>
      </div>
      ${summaryBlock}
      ${compareBar}
      ${panesHtml}
    </div>
  </div>
</div>`;
}

/**
 * Panneau admin : édition des dynamiques (ouvert en Expert ; résumé simple sinon).
 * @param {{ id: string, decision_dynamics?: object }} persona
 * @param {{ escHtml?: function, t?: function, uiMode?: string }} opts
 */
function renderDecisionDynamicsEditor(persona, opts = {}) {
  const escHtml = opts.escHtml ?? window.DecisionArena?.utils?.escHtml ?? ((s) => String(s ?? ''));
  const t = opts.t ?? ((k) => window.i18n?.t(k) ?? k);
  const uiMode = resolveUiMode(opts);
  if (!persona?.id) return '';
  const pidEsc = escHtml(persona.id);
  const norm = normalizeDecisionDynamics(persona.decision_dynamics);

  const repOptions = DECISION_DYNAMICS_REPUTATION_LEVELS.map((v) => {
    const selected = Math.abs(norm.reputation - v) < 0.0001 ? ' selected' : '';
    const lk = `admin.dynamics.reputationOpt.${String(v).replace('.', '_')}`;
    const lbl = t(lk);
    const label = lbl !== lk ? lbl : `×${v}`;
    return `<option value="${v}"${selected}>${escHtml(label)}</option>`;
  }).join('');

  const selectEnum = (field, values, current) => values.map((val) => {
    const selected = current === val ? ' selected' : '';
    const lk = `admin.dynamics.${field}.${val}`;
    const lbl = t(lk);
    const label = lbl !== lk ? lbl : val;
    return `<option value="${val}"${selected}>${escHtml(label)}</option>`;
  }).join('');

  if (uiMode !== 'expert') {
    const line = [
      `${t('admin.dynamics.reputation')}: ×${norm.reputation.toFixed(1)}`,
      `${t('admin.dynamics.consensusResistance')}: ${t(`admin.dynamics.consensus.${norm.consensus_resistance}`)}`,
      `${t('admin.dynamics.evidenceSensitivity')}: ${t(`admin.dynamics.evidence.${norm.evidence_sensitivity}`)}`,
      `${t('admin.dynamics.riskTolerance')}: ${t(`admin.dynamics.risk.${norm.risk_tolerance}`)}`,
    ].join(' · ');
    return `<div class="persona-dynamics-basic-readonly dynamics-muted" style="font-size:12px;margin-top:10px;line-height:1.5;">${escHtml(t('admin.dynamics.title'))}: ${escHtml(line)}</div>`;
  }

  return `
<section class="decision-dynamics-panel" data-ui="expert-only">
  <div class="section-header">
    <h3>${escHtml(t('admin.dynamics.title'))}</h3>
    <p class="card-description">${escHtml(t('admin.dynamics.desc'))}</p>
  </div>
  <div class="dynamics-grid">
    <div class="dynamics-field">
      <label class="dynamics-label" for="dd-rep-${pidEsc}">${escHtml(t('admin.dynamics.reputation'))}</label>
      <p class="dynamics-muted dynamics-option-desc">${escHtml(t('admin.dynamics.reputation.desc'))}</p>
      <select id="dd-rep-${pidEsc}" class="select dd-reputation-select" data-action="update-persona-dynamics" data-field="reputation" data-persona-id="${pidEsc}" aria-label="${escHtml(t('admin.dynamics.reputation'))}">
        ${repOptions}
      </select>
    </div>
    <div class="dynamics-field">
      <label class="dynamics-label" for="dd-consensus-${pidEsc}">${escHtml(t('admin.dynamics.consensusResistance'))}</label>
      <p class="dynamics-muted dynamics-option-desc">${escHtml(t('admin.dynamics.consensusResistance.desc'))}</p>
      <p class="dynamics-muted" style="font-size:11px;margin:0 0 6px;">${escHtml(t('admin.dynamics.consensusHint.low'))}</p>
      <select id="dd-consensus-${pidEsc}" class="select dd-consensus-select" data-action="update-persona-dynamics" data-field="consensus_resistance" data-persona-id="${pidEsc}">
        ${selectEnum('consensus', ['low', 'normal', 'strong'], norm.consensus_resistance)}
      </select>
    </div>
    <div class="dynamics-field">
      <label class="dynamics-label" for="dd-evidence-${pidEsc}">${escHtml(t('admin.dynamics.evidenceSensitivity'))}</label>
      <p class="dynamics-muted dynamics-option-desc">${escHtml(t('admin.dynamics.evidenceSensitivity.desc'))}</p>
      <p class="dynamics-muted" style="font-size:11px;margin:0 0 6px;">${escHtml(t('admin.dynamics.evidenceHint.low'))} / ${escHtml(t('admin.dynamics.evidenceHint.normal'))} / ${escHtml(t('admin.dynamics.evidenceHint.high'))}</p>
      <select id="dd-evidence-${pidEsc}" class="select dd-evidence-select" data-action="update-persona-dynamics" data-field="evidence_sensitivity" data-persona-id="${pidEsc}">
        ${selectEnum('evidence', ['low', 'normal', 'high'], norm.evidence_sensitivity)}
      </select>
    </div>
    <div class="dynamics-field">
      <label class="dynamics-label" for="dd-risk-${pidEsc}">${escHtml(t('admin.dynamics.riskTolerance'))}</label>
      <p class="dynamics-muted dynamics-option-desc">${escHtml(t('admin.dynamics.riskTolerance.desc'))}</p>
      <p class="dynamics-muted" style="font-size:11px;margin:0 0 6px;">${escHtml(t('admin.dynamics.riskHint.cautious'))} / ${escHtml(t('admin.dynamics.riskHint.balanced'))} / ${escHtml(t('admin.dynamics.riskHint.bold'))}</p>
      <select id="dd-risk-${pidEsc}" class="select dd-risk-select" data-action="update-persona-dynamics" data-field="risk_tolerance" data-persona-id="${pidEsc}">
        ${selectEnum('risk', ['cautious', 'balanced', 'bold'], norm.risk_tolerance)}
      </select>
    </div>
  </div>
  <div class="dynamics-warning">${escHtml(t('admin.dynamics.disclaimer'))}</div>
  <div class="dynamics-actions">
    <button type="button" class="btn btn-secondary btn-sm" data-action="save-persona-dynamics" data-persona-id="${pidEsc}">${escHtml(t('admin.dynamics.save'))}</button>
    <span class="mode-save-status" id="dd-dyn-status-${pidEsc}"></span>
  </div>
</section>`;
}

/** Liste d’agents issus de `session.selected_agents` (tableau ou JSON). */
function parseSessionSelectedAgents(session) {
  if (!session || session.selected_agents == null || session.selected_agents === '') return [];
  const raw = session.selected_agents;
  if (Array.isArray(raw)) {
    return raw.map((x) => String(x).trim()).filter((x) => x.length > 0);
  }
  if (typeof raw === 'string') {
    try {
      const decoded = JSON.parse(raw);
      if (Array.isArray(decoded)) {
        return decoded.map((x) => String(x).trim()).filter((x) => x.length > 0);
      }
    } catch (_) {}
    const single = raw.trim();
    return single.length > 0 ? [single] : [];
  }
  return [];
}

/**
 * Résumé session : une carte par agent (données API `agent_decision_dynamics`).
 * Si `opts.session` et `opts.votes` sont fournis : sans votes ni liste d’agents, affiche « non applicable ».
 * @param {object[]} agentRows
 * @param {{ escHtml?: function, agentName?: function, t?: function, session?: object, votes?: object[] }} opts
 */
function renderDecisionDynamicsSummary(agentRows, opts = {}) {
  const escHtml = opts.escHtml ?? window.DecisionArena?.utils?.escHtml ?? ((s) => String(s ?? ''));
  const agentName = opts.agentName ?? ((id) => id);
  const t = opts.t ?? ((k) => window.i18n?.t(k) ?? k);
  const rows = Array.isArray(agentRows) ? agentRows : [];
  const votes = opts.votes;
  const hasVotes = Array.isArray(votes) && votes.length > 0;
  const hasRoster = parseSessionSelectedAgents(opts.session ?? null).length > 0;

  const headerHtml = `
  <div class="section-header" style="padding:16px 18px 0;">
    <h3 style="margin:0 0 6px;font-size:16px;">${escHtml(t('session.dynamics.title'))}</h3>
    <p class="card-description" style="margin:0;">${escHtml(t('session.dynamics.desc'))}</p>
  </div>`;

  const wrapDynamicsSection = (bodyInner) => `
<section class="decision-dynamics-summary card debate-card" data-ui="expert-only" style="margin:16px 0 20px;">
  ${headerHtml}
  <div class="dynamics-summary-list" style="padding:12px 18px 18px;">${bodyInner}</div>
</section>`;

  if (rows.length === 0) {
    if (!hasVotes && !hasRoster) {
      return wrapDynamicsSection(
        `<p class="dynamics-summary-not-applicable dynamics-muted">${escHtml(t('session.dynamics.notApplicable'))}</p>`,
      );
    }
    return '';
  }

  const cards = rows.map((row) => {
    const id = String(row?.agent ?? '');
    if (!id) return '';
    const dyn = normalizeDecisionDynamics(row?.dynamics || row, { snapReputation: false });
    const repEff = row?.reputation != null && Number.isFinite(Number(row.reputation))
      ? Number(row.reputation)
      : dyn.reputation;
    const display = agentName(id);
    const variant = reputationBadgeVariant(repEff);
    const badgeClass = variant === 'boost' ? 'dynamics-badge dynamics-badge--boost' : variant === 'reduce' ? 'dynamics-badge dynamics-badge--reduce' : 'dynamics-badge dynamics-badge--neutral';
    const presetRaw = row?.session_decision_dynamics_preset;
    const preset = presetRaw
      ? `<div class="dynamics-muted" style="font-size:11px;margin-top:6px;">${escHtml(t('session.dynamics.sessionPreset'))}: <strong>${escHtml(dynamicsPresetDisplayLabel(presetRaw, t))}</strong></div>`
      : '';
    return `
    <article class="dynamics-agent-card">
      <div class="dynamics-agent-card-head">
        <div>
          <div class="dynamics-agent-title">${escHtml(display)} <span class="dynamics-muted">(${escHtml(id)})</span></div>
        </div>
        <span class="${badgeClass}" title="${escHtml(t('session.dynamics.reputationBadgeTitle'))}">×${repEff.toFixed(2)}</span>
      </div>
      ${preset}
      <ul class="dynamics-agent-meta">
        <li><span class="dynamics-meta-k">${escHtml(t('session.dynamics.reputation'))}</span> ×${repEff.toFixed(2)}</li>
        <li><span class="dynamics-meta-k">${escHtml(t('session.dynamics.consensusResistance'))}</span> ${escHtml(t(`admin.dynamics.consensus.${dyn.consensus_resistance}`))}</li>
        <li><span class="dynamics-meta-k">${escHtml(t('session.dynamics.evidenceSensitivity'))}</span> ${escHtml(t(`admin.dynamics.evidence.${dyn.evidence_sensitivity}`))}</li>
        <li><span class="dynamics-meta-k">${escHtml(t('session.dynamics.riskTolerance'))}</span> ${escHtml(t(`admin.dynamics.risk.${dyn.risk_tolerance}`))}</li>
      </ul>
    </article>`;
  }).filter(Boolean).join('');

  if (!cards) return '';

  return wrapDynamicsSection(cards);
}

function getInteractionSourceLabel(source) {
  const t = typeof window !== 'undefined' && window.i18n?.t
    ? window.i18n.t.bind(window.i18n)
    : (k) => k;
  switch (source) {
    case 'explicit_target': return t('interaction.provenance.explicit_target');
    case 'assigned_fallback': return t('interaction.provenance.assigned_fallback');
    case 'inferred_mention': return t('interaction.provenance.inferred_mention');
    default: return t('interaction.provenance.unknown');
  }
}

function getInteractionSourceClass(source) {
  switch (source) {
    case 'explicit_target':   return 'interaction-explicit';
    case 'assigned_fallback': return 'interaction-fallback';
    case 'inferred_mention':  return 'interaction-inferred';
    default:                  return 'interaction-unknown';
  }
}

/**
 * @param {boolean|null|undefined} verified
 * @param {(key: string) => string} [t]
 */
function getInteractionVerificationLabel(verified, t) {
  if (verified === null || verified === undefined) return '';
  const tr = typeof t === 'function'
    ? t
    : (typeof window !== 'undefined' && window.i18n?.t ? window.i18n.t.bind(window.i18n) : (k) => k);
  return verified ? tr('interaction.verification.verified') : tr('interaction.verification.unverified');
}

/** @param {boolean|null|undefined} verified */
function getInteractionVerificationClass(verified) {
  if (verified === null || verified === undefined) return '';
  return verified ? 'interaction-verified' : 'interaction-unverified';
}

/** @param {Record<string, unknown>} meta */
function isInteractionContractVerified(meta) {
  const v = meta?.verified_interaction;
  return v === 1 || v === true || v === '1';
}

/**
 * Visible in simple and expert UI (honest contract verification).
 * @param {Record<string, unknown>|null|undefined} meta
 * @param {(key: string) => string} t
 */
function renderInteractionContractVerifiedBadge(meta, t) {
  if (meta == null || meta.verified_interaction == null) return '';
  const verified = isInteractionContractVerified(meta);
  const label = getInteractionVerificationLabel(verified, t);
  const vClass = getInteractionVerificationClass(verified);
  return `<span class="interaction-verification-badge ${vClass}">${escapeHtml(label)}</span>`;
}

/**
 * Expert-only detail lines for parsed interaction contract fields.
 * @param {Record<string, unknown>|null|undefined} meta
 * @param {(key: string) => string} t
 */
function renderInteractionContractExpertDetail(meta, t) {
  if (!meta) return '';
  /** @type {string[]} */
  const parts = [];
  if (meta.claim_challenged) {
    parts.push(`<div><strong>${escapeHtml(t('interaction.claimChallenged'))}:</strong> ${escapeHtml(String(meta.claim_challenged))}</div>`);
  }
  if (meta.objection) {
    parts.push(`<div><strong>${escapeHtml(t('interaction.objection'))}:</strong> ${escapeHtml(String(meta.objection))}</div>`);
  }
  if (meta.concession) {
    parts.push(`<div><strong>${escapeHtml(t('interaction.concession'))}:</strong> ${escapeHtml(String(meta.concession))}</div>`);
  }
  if (meta.position_change) {
    parts.push(`<div><strong>${escapeHtml(t('interaction.positionChange'))}:</strong> ${escapeHtml(String(meta.position_change))}</div>`);
  }
  if (meta.target_mismatch === 1 || meta.target_mismatch === true) {
    parts.push(`<div class="text-warning"><strong>${escapeHtml(t('interaction.targetMismatch'))}</strong></div>`);
  }
  if (!parts.length) return '';
  return `<div data-ui="expert-only" style="margin-top:4px;font-size:11px;line-height:1.35;color:var(--text-secondary);">${parts.join('')}</div>`;
}

export {
  escapeHtml,
  attrsToHtml,
  renderCard,
  renderSectionHeader,
  renderEmptyState,
  renderBadge,
  renderAlert,
  renderActionBar,
  renderAdvancedPanel,
  renderByokProviderConnectModal,
  createErrorBanner,
  mountHtml,
  renderCardDescription,
  renderTooltip,
  renderPanelRecommendBadge,
  renderDecisionBrief,
  pickTradeoffs,
  renderTradeoffMatrix,
  renderRadarChart,
  renderTradeoffSection,
  renderPremortemInvertedBanner,
  renderPremortemStructuredCard,
  renderDecisionDynamicsEditor,
  renderDecisionDynamicsSummary,
  getInteractionSourceLabel,
  getInteractionSourceClass,
  getInteractionVerificationLabel,
  getInteractionVerificationClass,
  isInteractionContractVerified,
  renderInteractionContractVerifiedBadge,
  renderInteractionContractExpertDetail,
};

/**
 * Session-level decision dynamics preset label (admin config is not modified; overlay is session-only).
 */

/** @returns {'balanced'|'conservative'|'aggressive'|'critical'} */
export function normalizeDynamicsPresetId(raw) {
  const id = String(raw || 'balanced').toLowerCase().trim();
  if (['balanced', 'conservative', 'aggressive', 'critical'].includes(id)) return id;
  return 'balanced';
}

export function dynamicsPresetDisplayLabel(id, t) {
  const n = normalizeDynamicsPresetId(id);
  const key = `dynamicsPreset.${n}`;
  const lbl = t(key);
  return lbl !== key ? lbl : n;
}

/**
 * Visible line “Preset utilisé : …”
 */
export function renderSessionPresetUsedBanner(session, escHtml, t) {
  if (!session) return '';
  const id = normalizeDynamicsPresetId(session.decision_dynamics_preset);
  const label = dynamicsPresetDisplayLabel(id, t);
  const hintKey = id === 'balanced' ? 'dynamicsPreset.bannerHintBalanced' : 'dynamicsPreset.bannerHintOverlay';
  let hint = t(hintKey);
  if (hint === hintKey) hint = '';
  return `
    <div class="session-dynamics-preset-banner" style="margin-top:8px;font-size:12px;color:var(--text-secondary);padding:8px 10px;background:rgba(99,102,241,0.06);border-radius:6px;border:1px solid rgba(99,102,241,0.22);max-width:100%;">
      <span>${escHtml(t('dynamicsPreset.usedLinePrefix'))}</span> <strong>${escHtml(label)}</strong>${hint ? ` <span style="color:var(--text-muted);font-size:11px;">${escHtml(hint)}</span>` : ''}
    </div>`;
}

export function decisionDynamicsPresetOptionsHtml(ns, escHtml, t, selectId = 'ns-dd-preset-select') {
  const v = normalizeDynamicsPresetId(ns.decisionDynamicsPreset);
  const opts = ['balanced', 'conservative', 'aggressive', 'critical'].map((pid) =>
    `<option value="${pid}" ${v === pid ? 'selected' : ''}>${escHtml(dynamicsPresetDisplayLabel(pid, t))}</option>`,
  ).join('');
  const hint = escHtml(t('dynamicsPreset.fieldHint'));
  return `
    <div class="form-group">
      <label for="${escHtml(selectId)}">${escHtml(t('dynamicsPreset.fieldLabel'))}</label>
      <p class="card-description" style="font-size:11px;margin:0 0 8px;line-height:1.4;color:var(--text-muted);">${hint}</p>
      <select class="input" id="${escHtml(selectId)}">
        ${opts}
      </select>
    </div>`;
}

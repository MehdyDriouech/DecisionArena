/**
 * Cognitive Governance — panneau expert (invariants, ownership, mutations interdites).
 */

import { renderAlert } from '../../ui/components.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

function boolLabel(t, v) {
  return v ? t('governance.yes') : t('governance.no');
}

function renderLayers(catalog, escHtml, t) {
  const layers = Array.isArray(catalog?.layers) ? catalog.layers : [];
  if (!layers.length) return `<p style="color:var(--text-muted);">${escHtml(t('governance.empty'))}</p>`;
  return layers.map((L) => `
    <div class="card" style="padding:14px 16px;margin-bottom:10px;">
      <div style="font-weight:800;font-size:14px;">${escHtml(String(L.label || L.id || ''))}</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:4px;"><code>${escHtml(String(L.id || ''))}</code></div>
      <dl style="margin:10px 0 0;font-size:12px;line-height:1.5;color:var(--text-secondary);">
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.role'))}</dt><dd style="margin:0 0 6px 0;">${escHtml(String(L.role || '—'))}</dd>
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.persistence'))}</dt><dd style="margin:0 0 6px 0;">${escHtml(String(L.persistence || '—'))}</dd>
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.mutability'))}</dt><dd style="margin:0 0 6px 0;">${escHtml(String(L.mutability || '—'))}</dd>
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.provenance'))}</dt><dd style="margin:0 0 6px 0;">${escHtml(String(L.provenance || '—'))}</dd>
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.lifetime'))}</dt><dd style="margin:0 0 6px 0;">${escHtml(String(L.lifetime || '—'))}</dd>
        <dt style="font-weight:700;color:var(--text-muted);">${escHtml(t('governance.confidence'))}</dt><dd style="margin:0;">${escHtml(String(L.confidence || '—'))}</dd>
      </dl>
    </div>
  `).join('');
}

function renderOwnership(rows, escHtml, t) {
  const r = Array.isArray(rows) ? rows : [];
  if (!r.length) return '';
  const head = `<tr style="text-align:left;font-size:11px;color:var(--text-muted);">
    <th style="padding:6px 8px;border-bottom:1px solid var(--border);">${escHtml(t('governance.col.system'))}</th>
    <th style="padding:6px 8px;border-bottom:1px solid var(--border);">${escHtml(t('governance.col.source'))}</th>
    <th style="padding:6px 8px;border-bottom:1px solid var(--border);">${escHtml(t('governance.col.writable'))}</th>
    <th style="padding:6px 8px;border-bottom:1px solid var(--border);">${escHtml(t('governance.col.derived'))}</th>
    <th style="padding:6px 8px;border-bottom:1px solid var(--border);">${escHtml(t('governance.col.immutable'))}</th>
  </tr>`;
  const body = r.map((row) => `
    <tr style="font-size:12px;color:var(--text-secondary);vertical-align:top;">
      <td style="padding:8px;border-bottom:1px solid var(--border);"><strong>${escHtml(String(row.system || ''))}</strong></td>
      <td style="padding:8px;border-bottom:1px solid var(--border);">${escHtml(String(row.source_of_truth || '—'))}</td>
      <td style="padding:8px;border-bottom:1px solid var(--border);">${escHtml(boolLabel(t, !!row.writable))}</td>
      <td style="padding:8px;border-bottom:1px solid var(--border);">${escHtml(boolLabel(t, !!row.derived))}</td>
      <td style="padding:8px;border-bottom:1px solid var(--border);">${escHtml(boolLabel(t, !!row.immutable))}</td>
    </tr>
    ${row.notes ? `<tr><td colspan="5" style="padding:0 8px 10px;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border);">${escHtml(String(row.notes))}</td></tr>` : ''}
  `).join('');
  return `<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">${head}${body}</table></div>`;
}

function renderForbidden(rows, escHtml) {
  const r = Array.isArray(rows) ? rows : [];
  if (!r.length) return '';
  return `<ul style="margin:8px 0 0 18px;font-size:12px;line-height:1.55;color:var(--text-secondary);">
    ${r.map((x) => `<li><strong>${escHtml(String(x.from || ''))}</strong> → <strong>${escHtml(String(x.to || ''))}</strong> : ${escHtml(String(x.rule || ''))}</li>`).join('')}
  </ul>`;
}

function renderListStrings(items, escHtml) {
  const r = Array.isArray(items) ? items : [];
  if (!r.length) return '';
  return `<ul style="margin:8px 0 0 18px;font-size:12px;line-height:1.55;color:var(--text-secondary);">
    ${r.map((s) => `<li>${escHtml(String(s))}</li>`).join('')}
  </ul>`;
}

function renderDeps(rows, escHtml) {
  const r = Array.isArray(rows) ? rows : [];
  if (!r.length) return '';
  return `<table style="width:100%;border-collapse:collapse;font-size:12px;">
    ${r.map((d) => `<tr>
      <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-secondary);">${escHtml(String(d.upstream || ''))}</td>
      <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);">→</td>
      <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-secondary);">${escHtml(String(d.downstream || ''))}</td>
      <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text-muted);"><code>${escHtml(String(d.kind || ''))}</code></td>
    </tr>`).join('')}
  </table>`;
}

/** @param {Record<string, unknown>|null|undefined} pm */
function renderProvenanceModel(pm, escHtml, t) {
  if (!pm || typeof pm !== 'object') return '';
  let json = '';
  try {
    json = JSON.stringify(pm, null, 2);
  } catch {
    json = '{}';
  }
  return `
    <div class="card" style="padding:16px 18px;margin-bottom:14px;" data-ui="expert-only">
      <div style="font-weight:800;margin-bottom:6px;">${escHtml(t('governance.provenanceModelTitle'))}</div>
      <p style="font-size:12px;color:var(--text-muted);line-height:1.45;margin:0 0 10px;">${escHtml(t('governance.provenanceModelHint'))}</p>
      <details>
        <summary style="cursor:pointer;font-size:12px;color:var(--text-secondary);">JSON</summary>
        <pre style="white-space:pre-wrap;max-height:320px;overflow:auto;font-size:11px;margin-top:8px;background:var(--bg-tertiary);padding:10px;border-radius:6px;">${escHtml(json)}</pre>
      </details>
    </div>`;
}

/** @param {Record<string, unknown>|null|undefined} reg @param {Record<string, unknown>|null|undefined} pol */
/** @param {Record<string, unknown>|null|undefined} cfg */
function renderCognitiveBudgetRuntime(cfg, escHtml, t) {
  if (!cfg || typeof cfg !== 'object') return '';
  let json = '';
  try {
    json = JSON.stringify(cfg, null, 2);
  } catch {
    json = '';
  }
  return `
    <div class="card" style="padding:16px 18px;margin-bottom:14px;" data-ui="expert-only">
      <div style="font-weight:800;margin-bottom:6px;">${escHtml(t('governance.cognitiveBudgetRuntimeTitle'))}</div>
      <p style="font-size:12px;color:var(--text-muted);line-height:1.45;margin:0 0 10px;">${escHtml(t('governance.cognitiveBudgetRuntimeHint'))}</p>
      <details>
        <summary style="cursor:pointer;font-size:12px;color:var(--text-secondary);">${escHtml(t('governance.cognitiveBudgetEngineJson'))}</summary>
        <pre style="white-space:pre-wrap;max-height:260px;overflow:auto;font-size:11px;margin-top:8px;background:var(--bg-tertiary);padding:10px;border-radius:6px;">${escHtml(json)}</pre>
      </details>
    </div>`;
}

function renderPromptInjectionRuntime(reg, pol, escHtml, t) {
  if ((!reg || typeof reg !== 'object') && (!pol || typeof pol !== 'object')) return '';
  let jsonReg = '';
  let jsonPol = '';
  try {
    jsonReg = reg && typeof reg === 'object' ? JSON.stringify(reg, null, 2) : '';
  } catch {
    jsonReg = '';
  }
  try {
    jsonPol = pol && typeof pol === 'object' ? JSON.stringify(pol, null, 2) : '';
  } catch {
    jsonPol = '';
  }
  return `
    <div class="card" style="padding:16px 18px;margin-bottom:14px;" data-ui="expert-only">
      <div style="font-weight:800;margin-bottom:6px;">${escHtml(t('governance.promptInjectionRuntimeTitle'))}</div>
      <p style="font-size:12px;color:var(--text-muted);line-height:1.45;margin:0 0 12px;">${escHtml(t('governance.promptInjectionRuntimeHint'))}</p>
      ${jsonReg ? `<details style="margin-bottom:10px;"><summary style="cursor:pointer;font-size:12px;color:var(--text-secondary);">${escHtml(t('governance.promptInjectionRegistryJson'))}</summary>
        <pre style="white-space:pre-wrap;max-height:280px;overflow:auto;font-size:11px;margin-top:8px;background:var(--bg-tertiary);padding:10px;border-radius:6px;">${escHtml(jsonReg)}</pre>
      </details>` : ''}
      ${jsonPol ? `<details><summary style="cursor:pointer;font-size:12px;color:var(--text-secondary);">${escHtml(t('governance.promptRuntimePolicyJson'))}</summary>
        <pre style="white-space:pre-wrap;max-height:220px;overflow:auto;font-size:11px;margin-top:8px;background:var(--bg-tertiary);padding:10px;border-radius:6px;">${escHtml(jsonPol)}</pre>
      </details>` : ''}
      <p style="font-size:11px;color:var(--text-muted);margin:12px 0 0;line-height:1.45;">${escHtml(t('governance.promptInjectionRuntimeFooter'))}</p>
    </div>`;
}

function renderCognitiveGovernance() {
  const { state, escHtml, t } = getCtx();
  const cg = state.cognitiveGovernance || { loading: false, error: null, catalog: null };
  const catalog = cg.catalog;
  const loadBtn = `<button type="button" class="btn btn-primary btn-sm" data-action="load-cognitive-governance" ${cg.loading ? 'disabled' : ''}>
    ${cg.loading ? '…' : escHtml(t('governance.reload'))}
  </button>`;

  const errBlock = cg.error
    ? renderAlert({ variant: 'danger', text: `${t('governance.loadError')}: ${cg.error}` })
    : '';

  const version = catalog?.schema_version ? `<span style="font-size:11px;color:var(--text-muted);margin-left:8px;">schema ${escHtml(String(catalog.schema_version))}</span>` : '';

  const body = !catalog && !cg.error && !cg.loading
    ? `<div class="card" style="padding:20px;text-align:center;">${loadBtn}</div>`
    : cg.loading && !catalog
      ? `<div class="card" style="padding:20px;text-align:center;"><span class="spinner"></span> ${escHtml(t('governance.loading'))}</div>`
      : !catalog
        ? `<div class="card" style="padding:16px;">${errBlock}${loadBtn}</div>`
        : `
    ${errBlock}
    <div class="card" style="padding:16px 18px;margin-bottom:14px;" data-ui="expert-only">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.layersTitle'))}</div>
      <div style="font-size:12px;color:var(--text-muted);line-height:1.45;margin-bottom:12px;">${escHtml(t('governance.layersHint'))}</div>
      ${renderLayers(catalog, escHtml, t)}
    </div>
    ${renderProvenanceModel(catalog.provenance_model, escHtml, t)}
    ${renderPromptInjectionRuntime(catalog.prompt_injection_registry, catalog.prompt_runtime_policy, escHtml, t)}
    ${renderCognitiveBudgetRuntime(catalog.cognitive_budget_engine, escHtml, t)}
    <div class="card" style="padding:16px 18px;margin-bottom:14px;">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.ownershipTitle'))}</div>
      <p style="font-size:12px;color:var(--text-muted);line-height:1.45;margin:0 0 10px;">${escHtml(t('governance.ownershipHint'))}</p>
      ${renderOwnership(catalog.ownership_matrix, escHtml, t)}
    </div>
    <div class="card" style="padding:16px 18px;margin-bottom:14px;">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.mutationTitle'))}</div>
      <p style="font-size:12px;color:var(--text-muted);line-height:1.45;margin:0 0 6px;">${escHtml(t('governance.mutationHint'))}</p>
      ${renderForbidden(catalog.mutation_forbidden, escHtml)}
    </div>
    <div class="card" style="padding:16px 18px;margin-bottom:14px;">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.isolationTitle'))}</div>
      ${renderListStrings(catalog.isolation_rules, escHtml)}
    </div>
    <div class="card" style="padding:16px 18px;margin-bottom:14px;">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.depsTitle'))}</div>
      ${renderDeps(catalog.dependencies, escHtml)}
    </div>
    <div class="card" style="padding:16px 18px;margin-bottom:14px;">
      <div style="font-weight:800;margin-bottom:8px;">${escHtml(t('governance.risksTitle'))}</div>
      ${renderListStrings(catalog.systemic_risks, escHtml)}
    </div>
    <div style="margin-top:12px;">${loadBtn}</div>
  `;

  return `
    <div class="page-header" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
      <div>
        <button type="button" class="btn btn-secondary btn-sm" data-nav="administration">← ${escHtml(t('nav.back'))}</button>
        <div class="page-title" style="margin-top:10px;">${escHtml(t('governance.pageTitle'))}${version}</div>
        <div class="page-subtitle">${escHtml(t('governance.subtitle'))}</div>
      </div>
      <span class="badge badge-muted" data-ui="expert-only">${escHtml(t('governance.expertBadge'))}</span>
    </div>
    ${body}
  `;
}

function registerCognitiveGovernanceFeature() {
  window.DecisionArena.views['cognitive-governance'] = renderCognitiveGovernance;
}

export { registerCognitiveGovernanceFeature, renderCognitiveGovernance };

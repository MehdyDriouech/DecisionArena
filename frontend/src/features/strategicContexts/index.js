/**
 * Strategic Contexts feature – lightweight organization layer.
 */

import {
  renderPerspectiveSnapshot,
  renderPerspectiveSegmentedControl,
} from '../../utils/perspectiveSnapshotRenderer.js';
import { renderEmptyState, renderBadge, renderAlert } from '../../ui/components.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, formatDate, t };
}

/** Statut de cycle de vie du contexte (≠ espace workspace global — voir badge « Espace actif »). */
function badgeForStatus(s) {
  switch (s) {
    case 'active': return 'badge-info';
    case 'paused': return 'badge-warning';
    case 'completed': return 'badge-muted';
    case 'abandoned': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function lifecycleStatusLabel(t, status) {
  const k = String(status || 'active').trim() || 'active';
  const key = `contexts.status.${k}`;
  const lab = t(key);
  return lab === key ? k : lab;
}

function badgeForConfidenceUx(raw) {
  const k = String(raw || '').trim().toLowerCase();
  switch (k) {
    case 'strong': return 'badge-success';
    case 'moderate': return 'badge-warning';
    case 'weak': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function truncateContextSummary(s, max) {
  const x = String(s || '').replace(/\s+/g, ' ').trim();
  if (x.length <= max) return x;
  return `${x.slice(0, max - 1)}…`;
}

/** Variante badge pour les types d’items timeline (aligné sur l’API). */
function timelineBadgeVariant(type) {
  const m = {
    session: 'info',
    room: 'warning',
    memory: 'success',
    relationship_event: 'muted',
    decision: 'info',
    evidence: 'success',
    risk: 'danger',
  };
  return m[String(type || '')] || 'muted';
}

function readableDecisionStatusForContext(t, raw) {
  const key = String(raw || '').trim();
  const map = {
    proceed: 'decisionMemory.status.proceed',
    proceed_with_constraints: 'decisionMemory.status.proceedWithConstraints',
    validate_first: 'decisionMemory.status.validateFirst',
    pivot: 'decisionMemory.status.pivot',
    kill: 'decisionMemory.status.kill',
  };
  const trKey = map[key];
  return trKey ? t(trKey) : key || '—';
}

function readableConfidenceShortForContext(t, raw) {
  const k = String(raw || '').trim().toLowerCase();
  if (k === 'strong') return t('decisionMemory.confidence.strong');
  if (k === 'moderate') return t('decisionMemory.confidence.moderate');
  if (k === 'weak') return t('decisionMemory.confidence.weak');
  const s = String(raw || '').trim();
  return s || '—';
}

function parseSelectedAgents(raw) {
  if (raw == null || raw === '') return [];
  if (Array.isArray(raw)) {
    return raw.map((x) => String(x).trim()).filter(Boolean);
  }
  if (typeof raw === 'string') {
    try {
      const decoded = JSON.parse(raw);
      if (Array.isArray(decoded)) {
        return decoded.map((x) => String(x).trim()).filter(Boolean);
      }
    } catch (_) { /* noop */ }
    const single = raw.trim();
    return single ? [single] : [];
  }
  return [];
}

/** Sélecteur principal « Mémoire agents » : participants ou memory.md ; exclut synthesizer / devil_advocate. */
function resolvePrimaryWorkspaceAgentIds(selected) {
  const wa = Array.isArray(selected?.workspace_agents) ? selected.workspace_agents : [];
  const out = [];
  for (const a of wa) {
    const id = String(a.agent_id || '').trim().toLowerCase();
    if (!id || id === 'synthesizer' || id === 'devil_advocate') continue;
    if (!a.participated && !a.memory_md_exists) continue;
    out.push(id);
  }
  return Array.from(new Set(out));
}

/** @deprecated Préférer resolvePrimaryWorkspaceAgentIds pour l’UI mémoire ; conservé pour compat. */
function resolveWorkspaceAgentIds(selected, state) {
  const primary = resolvePrimaryWorkspaceAgentIds(selected);
  if (primary.length) return primary;
  const wa = Array.isArray(selected?.workspace_agents) ? selected.workspace_agents : [];
  const ids = wa.map((a) => String(a.agent_id || '').trim().toLowerCase()).filter(Boolean);
  if (ids.length) return Array.from(new Set(ids));
  return [];
}

function workspaceAgentLabelForSelect(agentId, selected, t) {
  const wa = Array.isArray(selected?.workspace_agents) ? selected.workspace_agents : [];
  const expert = Array.isArray(selected?.workspace_agents_expert_personas)
    ? selected.workspace_agents_expert_personas
    : [];
  const idl = String(agentId).trim().toLowerCase();
  const row = wa.find((a) => String(a.agent_id || '').trim().toLowerCase() === idl)
    || expert.find((a) => String(a.agent_id || '').trim().toLowerCase() === idl);
  const name = row ? String(row.agent_name || row.display_name || row.agent_id || '').trim() : String(agentId);
  const badges = Array.isArray(row?.badges) ? row.badges : [];
  const needsSync = !!row?.needs_memory_sync;
  const hasMem = !!row?.memory_md_exists;
  const partSync = !!row?.participation_memory_synced;
  const dmSync = !!row?.decision_memory_synced;
  const shellOnly = !!row?.memory_md_empty_or_template_only;
  const parts = [];
  if (badges.includes('participated')) parts.push(t('contexts.workspaceAgentBadge.participated'));
  if (needsSync) {
    if (shellOnly && hasMem) {
      parts.push(t('contexts.workspaceAgentBadge.memoryEmpty'));
    } else {
      parts.push(t('contexts.workspaceAgentBadge.memoryNeedsSync'));
    }
  } else if (hasMem) {
    parts.push(t('contexts.workspaceAgentBadge.memoryFile'));
  }
  if (badges.includes('participant_memory_needs_repair')) parts.push(t('contexts.workspaceAgentBadge.needsRepair'));
  if (partSync) parts.push(t('contexts.workspaceAgentBadge.participationSync'));
  if (dmSync) parts.push(t('contexts.workspaceAgentBadge.dmSync'));
  if (badges.includes('no_confirmed_decision_memory')) parts.push(t('contexts.workspaceAgentBadge.noConfirmedDm'));
  if (badges.includes('no_context_memory_file')) parts.push(t('contexts.agentBadges.no_context_memory_file'));
  if (badges.includes('persona_fallback_no_memory')) parts.push(t('contexts.workspaceAgentBadge.personaNoMemory'));
  if (badges.includes('not_participant')) parts.push(t('contexts.workspaceAgentBadge.notParticipant'));
  if (badges.includes('agent_memory_updated') && !dmSync) parts.push(t('contexts.workspaceAgentBadge.updated'));
  const suf = parts.length ? ` — ${parts.join(', ')}` : '';
  return `${name} (${agentId})${suf}`;
}

function buildRunPersonaPerspectiveItems(selected, state) {
  const sessions = Array.isArray(state.sessions) ? state.sessions : [];
  const personas = Array.isArray(state.personas) ? state.personas : [];
  const linkedSessionIds = new Set(
    (Array.isArray(selected?.linked_session_ids) ? selected.linked_session_ids : [])
      .map((id) => String(id).trim())
      .filter(Boolean),
  );
  const contextId = String(selected?.context_id || '').trim();
  const runSessions = sessions.filter((s) => {
    const sid = String(s?.id || '').trim();
    if (sid && linkedSessionIds.has(sid)) return true;
    return contextId && String(s?.strategic_context_id || '').trim() === contextId;
  });
  const seen = new Set();
  const agentIds = [];
  for (const s of runSessions) {
    for (const aid of parseSelectedAgents(s?.selected_agents)) {
      if (!aid || seen.has(aid)) continue;
      seen.add(aid);
      agentIds.push(aid);
    }
  }
  if (!agentIds.length) return [];
  const nameById = new Map(personas.map((p) => [String(p?.id || ''), String(p?.name || p?.id || '')]));
  const perspectiveKeys = ['default', 'ceo', 'cto', 'cfo', 'product', 'growth', 'legal'];
  return agentIds.slice(0, perspectiveKeys.length).map((aid, idx) => ({
    key: perspectiveKeys[idx],
    label: String(nameById.get(aid) || aid),
  }));
}

function renderStrategicContexts() {
  const { state, escHtml, formatDate, t } = getCtx();
  const pkg = state.strategicContexts || { loading: false, error: null, items: [] };
  const ui = state.strategicContextUi || { statusFilter: 'active', selectedContextId: null };
  const items = Array.isArray(pkg.items) ? pkg.items : [];
  const selectedId = ui.selectedContextId || (items[0]?.context_id ?? null);
  const selected = items.find((c) => c.context_id === selectedId) || null;
  const activeWorkspaceId = String(state.activeStrategicContextId || state.activeStrategicContext?.context_id || '').trim();
  const isExpert = state.uiMode === 'expert';
  const canShowExperimental = isExpert && state.experimentalFeaturesEnabled === true;
  const bulkSelectedIds = Array.isArray(ui.bulkSelectedIds) ? ui.bulkSelectedIds : [];
  const bulkSet = new Set(bulkSelectedIds);
  const bulkCount = bulkSelectedIds.length;

  const renderForm = () => {
    if (!ui.formOpen) return '';
    const v = ui.formValues || { title: '', description: '', status: 'active' };
    const title = String(v.title || '');
    const description = String(v.description || '');
    const status = String(v.status || 'active');
    const modeLabel = ui.formMode === 'edit' ? t('contexts.form.editTitle') : t('contexts.form.createTitle');
    return `
      <div class="card sc-form-card" style="padding:14px 16px;margin:10px 0 14px;">
        <div style="font-weight:800;margin-bottom:10px;">${escHtml(modeLabel)}</div>
        ${ui.formError ? `<div class="error-banner" style="margin-bottom:10px;">⚠️ ${escHtml(ui.formError)}</div>` : ''}
        <div style="display:grid;grid-template-columns:1fr;gap:10px;">
          <div class="form-group" style="margin:0;">
            <label>${escHtml(t('contexts.form.title'))} <span style="color:var(--danger);">*</span></label>
            <input class="input" value="${escHtml(title)}" data-action="set-context-form-field" data-field="title" placeholder="${escHtml(t('contexts.form.titlePh'))}">
          </div>
          <div class="form-group" style="margin:0;">
            <label>${escHtml(t('contexts.form.description'))}</label>
            <textarea class="textarea" style="min-height:80px;resize:vertical;" data-action="set-context-form-field" data-field="description"
              placeholder="${escHtml(t('contexts.form.descriptionPh'))}">${escHtml(description)}</textarea>
          </div>
          <div class="form-group" style="margin:0;max-width:260px;">
            <label>${escHtml(t('contexts.form.status'))}</label>
            <select class="input" data-action="set-context-form-field" data-field="status">
              ${['active','paused','completed','abandoned'].map((s) => `<option value="${escHtml(s)}" ${s === status ? 'selected' : ''}>${escHtml(t('contexts.status.' + s))}</option>`).join('')}
            </select>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-primary btn-sm" data-action="submit-context-form">💾 ${escHtml(t('contexts.form.save'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="cancel-context-form">${escHtml(t('contexts.form.cancel'))}</button>
          </div>
        </div>
      </div>
    `;
  };

  const renderLinkForm = () => {
    if (!ui.linkFormOpen || !selected) return '';
    const type = ui.linkType === 'memory' ? 'memory' : 'session';
    const linkId = String(ui.linkId || '');
    const sessions = Array.isArray(state.sessions) ? state.sessions : [];
    const memories = Array.isArray(state.decisionMemory?.memories) ? state.decisionMemory.memories : [];
    const options = type === 'session'
      ? sessions.slice(0, 30).map((s) => `<option value="${escHtml(String(s.id || ''))}">${escHtml(String(s.title || s.id || ''))}</option>`).join('')
      : memories.slice(0, 30).map((m) => `<option value="${escHtml(String(m.memory_id || ''))}">${escHtml(String(m.decision_summary || m.memory_id || ''))}</option>`).join('');

    return `
      <div class="card sc-form-card" style="padding:14px 16px;margin:10px 0 14px;">
        <div style="font-weight:800;margin-bottom:10px;">${escHtml(t('contexts.linkForm.title'))}</div>
        ${ui.linkError ? `<div class="error-banner" style="margin-bottom:10px;">⚠️ ${escHtml(ui.linkError)}</div>` : ''}
        ${ui.linkSuccess ? `<div class="success-banner" style="margin-bottom:10px;">✅ ${escHtml(ui.linkSuccess)}</div>` : ''}
        <div style="display:grid;grid-template-columns:1fr;gap:10px;">
          <div class="form-group" style="margin:0;max-width:260px;">
            <label>${escHtml(t('contexts.linkForm.type'))}</label>
            <select class="input" data-action="set-link-type">
              <option value="session" ${type === 'session' ? 'selected' : ''}>${escHtml(t('contexts.linkForm.session'))}</option>
              <option value="memory" ${type === 'memory' ? 'selected' : ''}>${escHtml(t('contexts.linkForm.memory'))}</option>
            </select>
          </div>

          <div class="form-group" style="margin:0;">
            <label>${escHtml(type === 'memory' ? t('contexts.linkForm.memoryId') : t('contexts.linkForm.sessionId'))}</label>
            <input class="input" value="${escHtml(linkId)}" data-action="set-link-id" placeholder="${escHtml(type === 'memory' ? t('contexts.linkForm.memoryIdPh') : t('contexts.linkForm.sessionIdPh'))}">
            ${options ? `
              <div style="margin-top:6px;">
                <select class="input" data-action="set-link-id" style="font-size:12px;padding:6px 8px;">
                  <option value="">${escHtml(t('contexts.linkForm.pickRecent'))}</option>
                  ${options}
                </select>
              </div>
            ` : ''}
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-primary btn-sm" data-action="submit-link-form">🔗 ${escHtml(t('contexts.linkForm.link'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="cancel-link-form">${escHtml(t('contexts.form.cancel'))}</button>
          </div>
        </div>
      </div>
    `;
  };

  const top = `
    <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;gap:12px;">
      <div>
        <div class="page-title">🧭 ${escHtml(t('contexts.title'))}</div>
        <div class="page-subtitle">${escHtml(t('contexts.subtitle'))}</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-secondary btn-sm" data-action="load-strategic-contexts">↺ ${escHtml(t('contexts.refresh'))}</button>
        <button class="btn btn-primary btn-sm" data-action="open-create-strategic-context">＋ ${escHtml(t('contexts.create'))}</button>
      </div>
    </div>
  `;

  if (pkg.loading) {
    return top + `<div class="loading-state"><span class="spinner spinner-lg"></span> ${escHtml(t('contexts.loading'))}</div>`;
  }
  if (pkg.error) {
    return top + `<div class="error-banner">⚠️ ${escHtml(pkg.error)}</div>`;
  }

  const filterRow = `
    <div class="card" style="padding:12px 14px;margin:10px 0 14px;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('contexts.filter'))}</span>
        <select class="input" style="padding:6px 8px;font-size:12px;min-width:180px;" data-action="set-context-status-filter">
          ${['active','paused','completed','abandoned',''].map((s) => {
            const label = s ? t('contexts.status.' + s) : t('contexts.status.all');
            return `<option value="${escHtml(s)}" ${String(ui.statusFilter || '') === s ? 'selected' : ''}>${escHtml(label)}</option>`;
          }).join('')}
        </select>
        </div>
        ${isExpert ? `
          <div data-ui="expert-only" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            ${bulkCount ? `<span class="badge badge-muted">${escHtml(t('contexts.bulk.selected'))}: ${bulkCount}</span>` : ''}
            <button class="btn btn-secondary btn-sm" data-action="select-all-visible-contexts">${escHtml(t('contexts.bulk.selectAllVisible'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="clear-bulk-context-selection" ${bulkCount ? '' : 'disabled'}>${escHtml(t('contexts.bulk.clear'))}</button>
            <button class="btn btn-danger btn-sm" data-action="request-bulk-delete-contexts" ${bulkCount ? '' : 'disabled'}>🗑️ ${escHtml(t('contexts.bulk.deleteSelected'))}</button>
          </div>
        ` : ''}
      </div>
      ${isExpert && ui.bulkDeleteConfirm ? `
        <div class="card" style="margin-top:10px;padding:12px 14px;border-color:rgba(239,68,68,0.45);background:rgba(239,68,68,0.06);" data-ui="expert-only">
          <div style="font-weight:800;color:var(--text-primary);">${escHtml(t('contexts.bulk.deleteConfirm.title'))}</div>
          <div style="margin-top:6px;font-size:12px;color:var(--text-secondary);line-height:1.45;">
            ${escHtml(t('contexts.bulk.deleteConfirm.body')).replace('{count}', String(bulkCount))}
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <button class="btn btn-danger btn-sm" data-action="confirm-bulk-delete-contexts">${escHtml(t('contexts.bulk.deleteConfirm.confirm'))}</button>
            <button class="btn btn-secondary btn-sm" data-action="cancel-bulk-delete-contexts">${escHtml(t('contexts.form.cancel'))}</button>
          </div>
        </div>
      ` : ''}
    </div>
  `;

  const dc = {
    panelOpen: false,
    leftId: null,
    rightId: null,
    includeSessions: true,
    includeDecisions: true,
    includeAgentMemories: false,
    includeSocialDynamics: true,
    includeTimeline: true,
    loading: false,
    error: '',
    result: null,
    ...(ui.contextDeepCompare && typeof ui.contextDeepCompare === 'object' ? ui.contextDeepCompare : {}),
  };

  const ctxOptionRows = (selectedIdForSide) => items.map((c) => {
    const id = String(c.context_id || '');
    const sel = String(selectedIdForSide || '') === id ? 'selected' : '';
    return `<option value="${escHtml(id)}" ${sel}>${escHtml(String(c.title || id).slice(0, 80))}</option>`;
  }).join('');

  const deepComparePanel = (!isExpert || !canShowExperimental) ? '' : (() => {
    const diffJson = dc.result?.diff != null ? JSON.stringify(dc.result.diff, null, 2) : '';
    const diffJsonClipped = diffJson.length > 14000 ? `${diffJson.slice(0, 13950)}\n…` : diffJson;
    const mdRaw = String(dc.result?.markdown || '');
    const mdClipped = mdRaw.length > 16000 ? `${mdRaw.slice(0, 15950)}…` : mdRaw;
    const inner = dc.panelOpen ? `
      <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group" style="margin:0;">
          <label>${escHtml(t('contexts.deepCompare.left'))}</label>
          <select class="input" style="font-size:12px;padding:6px 8px;" data-action="select-compare-left-context">
            <option value="">${escHtml(t('contexts.deepCompare.pick'))}</option>
            ${ctxOptionRows(dc.leftId)}
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label>${escHtml(t('contexts.deepCompare.right'))}</label>
          <select class="input" style="font-size:12px;padding:6px 8px;" data-action="select-compare-right-context">
            <option value="">${escHtml(t('contexts.deepCompare.pick'))}</option>
            ${ctxOptionRows(dc.rightId)}
          </select>
        </div>
      </div>
      <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:12px;font-size:12px;color:var(--text-secondary);">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-context-compare-option" data-option="sessions" ${dc.includeSessions ? 'checked' : ''}/> ${escHtml(t('contexts.deepCompare.optSessions'))}
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-context-compare-option" data-option="decisions" ${dc.includeDecisions ? 'checked' : ''}/> ${escHtml(t('contexts.deepCompare.optDecisions'))}
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-context-compare-option" data-option="agent_memories" ${dc.includeAgentMemories ? 'checked' : ''}/> ${escHtml(t('contexts.deepCompare.optAgentMem'))}
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-context-compare-option" data-option="social" ${dc.includeSocialDynamics ? 'checked' : ''}/> ${escHtml(t('contexts.deepCompare.optSocial'))}
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" data-action="toggle-context-compare-option" data-option="timeline" ${dc.includeTimeline ? 'checked' : ''}/> ${escHtml(t('contexts.deepCompare.optTimeline'))}
        </label>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary btn-sm" data-action="run-strategic-context-compare" ${dc.loading ? 'disabled' : ''}>${escHtml(t('contexts.deepCompare.run'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="copy-strategic-context-compare-markdown" ${dc.result?.markdown ? '' : 'disabled'}>${escHtml(t('contexts.deepCompare.copyMd'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="download-strategic-context-compare-markdown" ${dc.result?.markdown ? '' : 'disabled'}>${escHtml(t('contexts.deepCompare.downloadMd'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="download-strategic-context-compare-json" ${dc.result ? '' : 'disabled'}>${escHtml(t('contexts.deepCompare.downloadJson'))}</button>
      </div>
      ${dc.error ? renderAlert({ variant: 'danger', text: dc.error }) : ''}
      ${dc.loading ? `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.loading'))}</div>` : ''}
      ${dc.result && !dc.loading ? `
        <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <div style="font-weight:700;font-size:12px;margin-bottom:4px;">${escHtml(t('contexts.deepCompare.left'))}</div>
            ${renderBadge({ text: String(dc.result.left?.context_id || '').slice(0, 8) + '…', variant: 'info' })}
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">${escHtml(String(dc.result.left?.title || ''))}</div>
            ${Array.isArray(dc.result.left?.warnings) && dc.result.left.warnings.length
    ? dc.result.left.warnings.map((w) => renderAlert({ variant: 'warning', text: String(w) })).join('')
    : ''}
          </div>
          <div>
            <div style="font-weight:700;font-size:12px;margin-bottom:4px;">${escHtml(t('contexts.deepCompare.right'))}</div>
            ${renderBadge({ text: String(dc.result.right?.context_id || '').slice(0, 8) + '…', variant: 'warning' })}
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">${escHtml(String(dc.result.right?.title || ''))}</div>
            ${Array.isArray(dc.result.right?.warnings) && dc.result.right.warnings.length
    ? dc.result.right.warnings.map((w) => renderAlert({ variant: 'warning', text: String(w) })).join('')
    : ''}
          </div>
        </div>
        ${(() => {
      const beliefs = dc.result?.diff?.beliefs || {};
      const narrative = dc.result?.diff?.narrative_drift || {};
      const snapshots = dc.result?.diff?.snapshots || {};
      const social = dc.result?.diff?.social_dynamics_differences || {};
      const bDelta = Number(beliefs?.contested_divergence?.delta || 0);
      const cDelta = Number(beliefs?.consensus_divergence?.delta || 0);
      const nSummary = String(narrative?.narrative_drift_summary || '—');
      const relDelta = (Array.isArray(social?.relationship_pairs_only_left) ? social.relationship_pairs_only_left.length : 0)
        - (Array.isArray(social?.relationship_pairs_only_right) ? social.relationship_pairs_only_right.length : 0);
      return `
        <div style="margin-top:12px;border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg-secondary);">
          <div style="font-weight:700;font-size:12px;margin-bottom:8px;">${escHtml(t('contexts.deepCompare.cognitiveExplorer'))}</div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;font-size:12px;">
            <div class="card" style="padding:8px;">
              <div style="font-weight:700;">${escHtml(t('contexts.deepCompare.beliefDrift'))}</div>
              <div style="margin-top:4px;color:var(--text-secondary);">
                ${escHtml(t('contexts.deepCompare.contestedDelta'))}: ${escHtml(String(bDelta))}
                <br/>
                ${escHtml(t('contexts.deepCompare.consensusDelta'))}: ${escHtml(String(cDelta.toFixed ? cDelta.toFixed(3) : cDelta))}
              </div>
            </div>
            <div class="card" style="padding:8px;">
              <div style="font-weight:700;">${escHtml(t('contexts.deepCompare.narrativeDrift'))}</div>
              <div style="margin-top:4px;color:var(--text-secondary);">${escHtml(nSummary)}</div>
            </div>
            <div class="card" style="padding:8px;">
              <div style="font-weight:700;">${escHtml(t('contexts.deepCompare.snapshotDiff'))}</div>
              <div style="margin-top:4px;color:var(--text-secondary);">
                ${escHtml(t('contexts.deepCompare.snapshotCounts'))}: ${escHtml(String(snapshots?.snapshot_count_left || 0))} / ${escHtml(String(snapshots?.snapshot_count_right || 0))}
              </div>
            </div>
            <div class="card" style="padding:8px;">
              <div style="font-weight:700;">${escHtml(t('contexts.deepCompare.socialDivergence'))}</div>
              <div style="margin-top:4px;color:var(--text-secondary);">
                ${escHtml(t('contexts.deepCompare.relationshipDelta'))}: ${escHtml(String(relDelta))}
              </div>
            </div>
          </div>
        </div>
      `;
    })()}
        <div style="margin-top:12px;">
          <div style="font-weight:700;font-size:12px;margin-bottom:4px;">${escHtml(t('contexts.deepCompare.diffJson'))}</div>
          <pre style="max-height:220px;overflow:auto;font-size:11px;background:var(--bg-secondary);padding:8px;border-radius:6px;border:1px solid var(--border);white-space:pre-wrap;">${diffJsonClipped ? escHtml(diffJsonClipped) : escHtml('{}')}</pre>
        </div>
        <div style="margin-top:12px;">
          <div style="font-weight:700;font-size:12px;margin-bottom:4px;">${escHtml(t('contexts.deepCompare.markdown'))}</div>
          <pre style="max-height:200px;overflow:auto;font-size:11px;background:var(--bg-secondary);padding:8px;border-radius:6px;border:1px solid var(--border);white-space:pre-wrap;">${mdClipped ? escHtml(mdClipped) : escHtml('—')}</pre>
        </div>
      ` : ''}
    ` : '';
    return `
      <div class="card" data-ui="expert-only" style="padding:12px 14px;margin:0 0 14px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:800;">${escHtml(t('contexts.deepCompare.title'))}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;line-height:1.45;">${escHtml(t('contexts.deepCompare.subtitle'))}</div>
          </div>
          <button type="button" class="btn btn-secondary btn-sm" data-action="toggle-context-deep-compare-panel">${escHtml(dc.panelOpen ? t('contexts.deepCompare.hide') : t('contexts.deepCompare.show'))}</button>
        </div>
        ${inner}
      </div>
    `;
  })();

  const list = items.map((c) => {
    const cs = c.current_state || {};
    const risks = Array.isArray(cs.active_risks) ? cs.active_risks : [];
    const checked = isExpert && bulkSet.has(c.context_id);
    const isWorkspace = Number(c.is_workspace_active) === 1 || String(c.context_id) === activeWorkspaceId;
    const canCompareRow = selectedId && String(c.context_id) !== String(selectedId);
    const status = String(c.status || '');
    const canActivateRow = ['active', 'paused'].includes(status) && !isWorkspace;
    return `
      <div class="card" style="padding:12px 14px;margin-bottom:10px;cursor:pointer;${c.context_id === selectedId ? 'border-color:rgba(99,102,241,0.55);background:rgba(99,102,241,0.06);' : ''}"
        data-action="select-strategic-context" data-context-id="${escHtml(c.context_id)}">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          ${isExpert ? `
            <label data-ui="expert-only" style="display:flex;align-items:center;gap:6px;margin-right:2px;cursor:pointer;">
              <input type="checkbox"
                data-action="toggle-bulk-context-selection"
                data-context-id="${escHtml(c.context_id)}"
                ${checked ? 'checked' : ''}
              />
              <span style="font-size:11px;color:var(--text-muted);font-weight:700;">${escHtml(t('contexts.bulk.select'))}</span>
            </label>
          ` : ''}
          ${isWorkspace ? `<span class="badge badge-success">${escHtml(t('contexts.workspaceBadge'))}</span>` : ''}
          <span class="badge ${badgeForStatus(c.status)}">${escHtml(lifecycleStatusLabel(t, c.status))}</span>
          <div style="font-weight:700;">${escHtml(String(c.title || ''))}</div>
          <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(c.updated_at))}</span>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          ${cs.current_decision_status ? `<span class="badge badge-info">${escHtml(cs.current_decision_status)}</span>` : `<span class="badge badge-muted">${escHtml(t('contexts.noDecision'))}</span>`}
          ${cs.current_confidence ? `<span class="badge badge-muted">${escHtml(t('contexts.confidence'))}: ${escHtml(cs.current_confidence)}</span>` : ''}
          <span class="badge ${risks.length ? 'badge-warning' : 'badge-muted'}">${escHtml(t('contexts.risks'))}: ${risks.length}</span>
        </div>
        ${cs.latest_next_step ? `<div style="margin-top:8px;font-size:12px;color:var(--text-secondary);"><strong>${escHtml(t('contexts.next'))}:</strong> ${escHtml(cs.latest_next_step)}</div>` : ''}
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          ${canActivateRow ? `<button type="button" class="btn btn-primary btn-sm" data-action="activate-strategic-context" data-context-id="${escHtml(c.context_id)}">${escHtml(t('contexts.activateThis'))}</button>` : ''}
          ${canCompareRow ? `<button type="button" class="btn btn-secondary btn-sm" data-action="compare-strategic-contexts" data-other-context-id="${escHtml(c.context_id)}">⇄ ${escHtml(t('contexts.compare'))}</button>` : ''}
        </div>
      </div>
    `;
  }).join('') || `<div class="empty-state"><div class="empty-state-text">${escHtml(t('contexts.empty'))}</div></div>`;

  const detail = (() => {
    if (!selected) return '';
    const cs = selected.current_state || {};
    const memIds = Array.isArray(selected.linked_memory_ids) ? selected.linked_memory_ids : [];
    const sesIds = Array.isArray(selected.linked_session_ids) ? selected.linked_session_ids : [];
    const risks = Array.isArray(cs.active_risks) ? cs.active_risks : [];
    const isDeleteConfirm = ui.deleteConfirmContextId && ui.deleteConfirmContextId === selected.context_id;

    const riskPreview = risks.slice(0, 2)
      .map((r) => `<div style="font-size:11px;color:var(--text-secondary);line-height:1.35;">• ${escHtml(String(r))}</div>`)
      .join('');

    const mo = ui.memoryOverview || { loading: false, error: '', data: null };
    const amf = ui.agentMemoryForceSync || { loading: false, error: '', report: null };
    const ovPayload = mo.data && typeof mo.data === 'object' ? mo.data : null;
    const ov = ovPayload?.overview && typeof ovPayload.overview === 'object' ? ovPayload.overview : null;
    const decPreview = Array.isArray(ovPayload?.decisions_preview) ? ovPayload.decisions_preview : [];
    const diagList = Array.isArray(ovPayload?.diagnostics) ? ovPayload.diagnostics : [];
    const expertAutoNotes = Array.isArray(ovPayload?.expert_automation_notes) ? ovPayload.expert_automation_notes : [];
    const diagCritical = diagList.some((d) => {
      const s = String(d.severity || '').toLowerCase();
      return s === 'error' || s === 'warning';
    });
    const bc = ui.basicCompare || { targetId: null, loading: false, error: '', result: null };
    const otherContexts = items.filter((c) => String(c.context_id) !== String(selected.context_id));
    const basicComparePick = String(bc.targetId || '').trim();
    const mh = ov?.memory_health && typeof ov.memory_health === 'object' ? ov.memory_health : { level: 'ok', warnings: [] };
    const healthLevel = String(mh.level || 'ok');
    const healthWarns = Array.isArray(mh.warnings) ? mh.warnings : [];
    const shortId = (s) => {
      const x = String(s || '').trim();
      if (!x) return '—';
      return x.length <= 14 ? x : `${x.slice(0, 10)}…`;
    };
    const healthBadgeVariant = healthLevel === 'ok' ? 'success' : healthLevel === 'incomplete' ? 'warning' : 'warning';
    const healthLabelKey = healthLevel === 'ok'
      ? 'contexts.memoryOverview.healthOk'
      : (healthLevel === 'incomplete' ? 'contexts.memoryOverview.healthIncomplete' : 'contexts.memoryOverview.healthWarning');

    const workspaceAgents = Array.isArray(selected.workspace_agents) ? selected.workspace_agents : [];

    const expressCard = '';

    const memoryOverviewBlock = `
        <div class="card sc-memory-overview" style="margin-top:14px;padding:12px 14px;border:1px solid var(--border-subtle);background:var(--bg-secondary);">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.memoryOverview.title'))}</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">${escHtml(t('contexts.memoryOverview.subtitle'))}</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-context-memory-overview" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.memoryOverview.refresh'))}</button>
              ${mo.loading ? `<span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.loading'))}</span>` : ''}
            </div>
          </div>
          ${mo.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(mo.error)}</div>` : ''}
          ${ov ? `
            <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
              ${renderBadge({ text: t(healthLabelKey), variant: healthBadgeVariant })}
              <span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.sessions'))}: ${escHtml(String(ov.sessions_count ?? 0))}</span>
              <span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.decisions'))}: ${escHtml(String(ov.decision_memories_count ?? 0))}</span>
              <span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.participants'))}: ${escHtml(String(ov.participants_count ?? 0))}</span>
              <span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.agentFiles'))}: ${escHtml(String(ov.agent_memories_count ?? 0))}</span>
              ${ov.last_decision_at ? `<span class="badge badge-info">${escHtml(t('contexts.memoryOverview.lastDecision'))}: ${escHtml(formatDate(ov.last_decision_at))}</span>` : ''}
            </div>
            ${healthWarns.length ? `<div style="margin-top:10px;font-size:12px;color:var(--text-secondary);line-height:1.45;">
              <strong>${escHtml(t('contexts.memoryOverview.warningsTitle'))}</strong>
              <ul style="margin:6px 0 0 18px;">${healthWarns.map((w) => `<li>${escHtml(String(w))}</li>`).join('')}</ul>
            </div>` : ''}
            <div style="margin-top:10px;font-size:12px;color:var(--text-muted);line-height:1.5;">${escHtml(t('contexts.memoryOverview.canonNote'))}</div>
            ${isExpert && canShowExperimental ? (() => {
      const rep = amf.report && typeof amf.report === 'object' ? amf.report : null;
      const sum = rep && typeof rep.summary === 'object' ? rep.summary : null;
      const sumLines = sum ? `
              <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineSessions'))}</strong> : ${escHtml(String(sum.sessions_scanned ?? 0))}</div>
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineMemories'))}</strong> : ${escHtml(String(sum.decision_memories_scanned ?? 0))}</div>
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineCreated'))}</strong> : ${escHtml(String(sum.files_created ?? 0))}</div>
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineUpdated'))}</strong> : ${escHtml(String(sum.files_updated ?? 0))}</div>
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineDup'))}</strong> : ${escHtml(String(sum.duplicates_skipped ?? 0))}</div>
                <div><strong>${escHtml(t('contexts.agentMemorySync.lineWarn'))}</strong> : ${escHtml(String(sum.warnings_count ?? 0))}</div>
                ${rep?.dry_run ? `<div style="margin-top:6px;"><span class="badge badge-info">${escHtml(t('contexts.agentMemorySync.preview'))}</span></div>` : ''}
              </div>` : '';
      return `
            <div style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-color);" data-ui="expert-only">
              <div style="font-size:12px;color:var(--text-secondary);line-height:1.45;margin-bottom:8px;">${escHtml(t('contexts.agentMemorySync.blurb'))}</div>
              <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="button" class="btn btn-secondary btn-sm" data-action="preview-agent-context-memories-sync" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.agentMemorySync.preview'))}</button>
                <button type="button" class="btn btn-primary btn-sm" data-action="apply-agent-context-memories-sync" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.agentMemorySync.apply'))}</button>
                ${amf.loading ? `<span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.loading'))}</span>` : ''}
              </div>
              ${amf.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(amf.error)}</div>` : ''}
              ${rep ? `<div style="margin-top:10px;padding:10px;background:var(--bg-secondary);border:1px solid var(--border-subtle);border-radius:8px;">
                <div style="font-weight:700;font-size:13px;margin-bottom:4px;">${escHtml(t('contexts.agentMemorySync.summaryTitle'))}</div>
                ${sumLines}
              </div>` : ''}
            </div>`;
    })() : ''}
          ` : (!mo.loading ? `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.memoryOverview.empty'))}</div>` : '')}
        </div>`;

    const basicActionsBar = `
        <div class="sc-basic-actions" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
          <button type="button" class="btn btn-primary btn-sm" data-action="toggle-context-memory-md" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.basicActions.memoryMd'))}</button>
          ${(!isExpert || canShowExperimental) ? `<button type="button" class="btn btn-secondary btn-sm" data-action="load-workspace-timeline" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.basicActions.timeline'))}</button>` : ''}
        </div>
        ${!isExpert ? `
        <div class="sc-basic-compare-row" data-sc-basic-compare="1" style="margin-top:12px;padding:12px 14px;border:1px solid var(--border-subtle);border-radius:8px;background:var(--bg-secondary);">
          <div style="font-weight:800;font-size:13px;margin-bottom:8px;">${escHtml(t('contexts.basicCompare.sectionTitle'))}</div>
          ${otherContexts.length === 0
      ? `<div style="font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.basicCompare.noOther'))}</div>`
      : `<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
              <label style="font-size:12px;color:var(--text-secondary);margin:0;">${escHtml(t('contexts.basicCompare.withLabel'))}</label>
              <select class="input" style="min-width:220px;" data-action="set-basic-compare-target">
                <option value="">${escHtml(t('contexts.basicCompare.pick'))}</option>
                ${otherContexts.map((c) => {
      const id = String(c.context_id || '');
      const sel = basicComparePick === id ? ' selected' : '';
      return `<option value="${escHtml(id)}"${sel}>${escHtml(String(c.title || id).slice(0, 56))}</option>`;
    }).join('')}
              </select>
              <button type="button" class="btn btn-primary btn-sm" data-action="run-basic-strategic-context-compare-basic" data-context-id="${escHtml(selected.context_id)}"${(!basicComparePick || bc.loading) ? ' disabled' : ''}>${escHtml(t('contexts.basicCompare.run'))}</button>
              ${bc.loading ? `<span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.loading'))}</span>` : ''}
            </div>`}
          ${bc.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(bc.error)}</div>` : ''}
        </div>` : ''}
        <div style="margin-top:8px;font-size:11px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.memoryMdDisclaimers.contextView'))}</div>`;

    const decisionsMemorizedSection = (() => {
      const rows = decPreview.map((d) => {
        const mid = String(d.memory_id || '');
        const sid = String(d.session_id || '');
        const sum = String(d.summary || '').trim();
        const pb = String(d.playbook_id || '—');
        const st = String(d.decision_status || d.memory_state || '—');
        const na = String(d.next_action || '').trim();
        const when = d.created_at ? formatDate(d.created_at) : '—';
        const unconfirmed = d.user_confirmed === false ? `<span class="badge badge-warning" style="margin-left:6px;">${escHtml(t('contexts.decisionsMemorized.unconfirmed'))}</span>` : '';
        return `<div class="sc-decision-row" style="padding:10px 0;border-bottom:1px solid var(--border-subtle);">
          <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
            ${renderBadge({ text: escHtml(st), variant: 'info' })}
            ${unconfirmed}
            <span style="font-size:11px;color:var(--text-muted);">${escHtml(when)}</span>
          </div>
          <div style="margin-top:6px;font-size:13px;color:var(--text-primary);line-height:1.45;">${sum ? escHtml(sum) : escHtml(t('contexts.decisionsMemorized.noSummary'))}</div>
          <div style="margin-top:6px;font-size:11px;color:var(--text-muted);line-height:1.5;">
            <span><strong>session</strong> <code>${escHtml(shortId(sid))}</code></span>
            · <span><strong>memory</strong> <code>${escHtml(shortId(mid))}</code></span>
            · <span><strong>${escHtml(t('contexts.decisionsMemorized.playbook'))}</strong> ${escHtml(pb)}</span>
          </div>
          ${na ? `<div style="margin-top:6px;font-size:12px;color:var(--text-secondary);"><strong>${escHtml(t('contexts.next'))}:</strong> ${escHtml(na)}</div>` : ''}
          <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn btn-secondary btn-sm" data-action="open-decision-memory-for-context" data-context-id="${escHtml(selected.context_id)}" data-memory-id="${escHtml(mid)}">${escHtml(t('contexts.decisionsMemorized.viewSource'))}</button>
            ${sid ? `<button type="button" class="btn btn-secondary btn-sm" data-action="open-session" data-session-id="${escHtml(sid)}" data-mode="session-history">${escHtml(t('contexts.decisionsMemorized.openAnalysis'))}</button>` : ''}
          </div>
        </div>`;
      }).join('');
      const empty = `<div style="font-size:13px;color:var(--text-secondary);line-height:1.5;">${escHtml(t('contexts.decisionsMemorized.empty'))}</div>
        <div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.decisionsMemorized.hint'))}</div>`;
      return `
        <div class="card sc-decisions-memorized" style="margin-top:12px;padding:12px 14px;">
          <div style="font-weight:800;font-size:14px;margin-bottom:4px;">${escHtml(t('contexts.decisionsMemorized.title'))}</div>
          <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-bottom:10px;">${escHtml(t('contexts.decisionsMemorized.subtitle'))}</div>
          ${decPreview.length ? rows : empty}
        </div>`;
    })();

    const agentsInvolvedSection = (() => {
      const coreRows = workspaceAgents.filter((ag) => {
        const aid = String(ag.agent_id || '').trim().toLowerCase();
        return aid && aid !== 'synthesizer' && aid !== 'devil_advocate';
      });
      const expertPersonas = isExpert && Array.isArray(selected.workspace_agents_expert_personas)
        ? selected.workspace_agents_expert_personas
        : [];
      if (!coreRows.length) {
        return `<div class="card sc-agents-involved" style="margin-top:12px;padding:12px 14px;">
          <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.agentsInvolved.title'))}</div>
          <div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.workspaceAgents.noParticipantsYet'))}</div>
        </div>`;
      }
      const badgeForCatalog = (bid) => {
        const k = `contexts.agentBadges.${bid}`;
        const tr = t(k);
        return tr === k ? bid : tr;
      };
      const showNoLinkedDmHint = memIds.length === 0 && (
        (ov && Number(ov.sessions_count ?? 0) > 0 && Number(ov.decision_memories_count ?? 0) === 0)
        || (!ov && (sesIds.length > 0 || coreRows.some((x) => x.participated)))
      );
      const anyNeedsSync = isExpert && coreRows.some((a) => !!a.needs_memory_sync);
      const rows = coreRows.map((ag) => {
        const aid = String(ag.agent_id || '').trim().toLowerCase();
        const name = String(ag.agent_name || aid);
        const badges = Array.isArray(ag.badges) ? ag.badges : [];
        const bHtml = badges.map((b) => renderBadge({ text: escHtml(badgeForCatalog(String(b))), variant: 'muted' })).join(' ');
        const isSynthDa = aid === 'synthesizer' || aid === 'devil_advocate';
        const excludedNote = isSynthDa ? `<span class="badge badge-warning" style="margin-left:6px;">${escHtml(t('contexts.agentBadges.synthesizer_devil_excluded'))}</span>` : '';
        const chatBtn = isExpert
          ? `<button type="button" class="btn btn-secondary btn-sm" data-action="open-context-agent-chat">${escHtml(t('contexts.agentsInvolved.chat'))}</button>`
          : '';
        return `<div class="sc-agent-row" style="padding:10px 0;border-bottom:1px solid var(--border-subtle);">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <strong style="font-size:13px;">${escHtml(name)}</strong>
            <code style="font-size:11px;">${escHtml(aid)}</code>
            ${excludedNote}
          </div>
          <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">${bHtml}</div>
          <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn btn-secondary btn-sm" data-action="open-agent-context-memory" data-agent-id="${escHtml(aid)}">${escHtml(t('contexts.agentsInvolved.viewMemoryMd'))}</button>
            ${chatBtn}
          </div>
        </div>`;
      }).join('');
      return `
        <div class="card sc-agents-involved" style="margin-top:12px;padding:12px 14px;">
          <div style="font-weight:800;font-size:14px;margin-bottom:4px;">${escHtml(t('contexts.agentsInvolved.title'))}</div>
          <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-bottom:10px;">${escHtml(t('contexts.agentsInvolved.subtitle'))}</div>
          ${anyNeedsSync && isExpert && canShowExperimental ? (() => {
      const rep = amf.report && typeof amf.report === 'object' ? amf.report : null;
      const sum = rep && typeof rep.summary === 'object' ? rep.summary : null;
      const sumLines = sum ? `
            <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineSessions'))}</strong> : ${escHtml(String(sum.sessions_scanned ?? 0))}</div>
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineMemories'))}</strong> : ${escHtml(String(sum.decision_memories_scanned ?? 0))}</div>
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineCreated'))}</strong> : ${escHtml(String(sum.files_created ?? 0))}</div>
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineUpdated'))}</strong> : ${escHtml(String(sum.files_updated ?? 0))}</div>
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineDup'))}</strong> : ${escHtml(String(sum.duplicates_skipped ?? 0))}</div>
              <div><strong>${escHtml(t('contexts.agentMemorySync.lineWarn'))}</strong> : ${escHtml(String(sum.warnings_count ?? 0))}</div>
              ${rep?.dry_run ? `<div style="margin-top:6px;"><span class="badge badge-info">${escHtml(t('contexts.agentMemorySync.preview'))}</span></div>` : ''}
            </div>` : '';
      return `
          <div class="sc-agents-involved-sync" data-ui="expert-only" style="margin-bottom:12px;padding:10px 12px;border:1px dashed var(--border-color);border-radius:8px;background:var(--bg-secondary);">
            <div style="font-size:12px;color:var(--text-secondary);line-height:1.45;margin-bottom:8px;">${escHtml(t('contexts.agentMemorySync.blurbAgentsPanel'))}</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="preview-agent-context-memories-sync" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.agentMemorySync.preview'))}</button>
              <button type="button" class="btn btn-primary btn-sm" data-action="apply-agent-context-memories-sync" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.agentMemorySync.apply'))}</button>
              ${amf.loading ? `<span class="badge badge-muted">${escHtml(t('contexts.memoryOverview.loading'))}</span>` : ''}
            </div>
            ${amf.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(amf.error)}</div>` : ''}
            ${rep ? `<div style="margin-top:10px;padding:10px;background:var(--bg-primary);border:1px solid var(--border-subtle);border-radius:8px;">
              <div style="font-weight:700;font-size:13px;margin-bottom:4px;">${escHtml(t('contexts.agentMemorySync.summaryTitle'))}</div>
              ${sumLines}
            </div>` : ''}
          </div>`;
    })() : ''}
          ${showNoLinkedDmHint
      ? `<div class="sc-agents-dm-hint" style="margin-bottom:10px;padding:8px 10px;background:var(--bg-secondary);border:1px solid var(--border-subtle);border-radius:8px;font-size:12px;color:var(--text-secondary);line-height:1.45;">${escHtml(t('contexts.agentsInvolved.noLinkedDmHint'))}</div>`
      : ''}
          ${rows}
          ${expertPersonas.length ? `
          <details class="sc-expert-details" data-ui="expert-only" style="margin-top:12px;">
            <summary style="cursor:pointer;font-weight:700;font-size:13px;color:var(--text-secondary);">${escHtml(t('contexts.agentsInvolved.expertOtherPersonas'))}</summary>
            <div style="margin-top:10px;font-size:12px;color:var(--text-muted);line-height:1.45;">
              ${expertPersonas.map((ag) => {
    const aid = String(ag.agent_id || '').trim().toLowerCase();
    const name = String(ag.agent_name || aid);
    return `<div style="padding:6px 0;border-bottom:1px solid var(--border-subtle);"><code>${escHtml(aid)}</code> — ${escHtml(name)}</div>`;
  }).join('')}
            </div>
          </details>` : ''}
          <div style="margin-top:10px;font-size:11px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.memoryMdDisclaimers.agentView'))}</div>
        </div>`;
    })();

    const expertMemoryDiagnostics = (isExpert && canShowExperimental) ? `
        <details class="sc-expert-details sc-expert-drawer" data-ui="expert-only" style="margin-top:12px;"${diagCritical ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);">${escHtml(t('contexts.expertLayers.memoryDiagnostic'))}</summary>
          <div style="margin-top:10px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;padding:8px 10px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border-subtle);line-height:1.45;">
              <div style="font-weight:700;margin-bottom:4px;color:var(--text-secondary);">${escHtml(t('contexts.memoryDiagnostics.limitsTitle'))}</div>
              <ul style="margin:4px 0 0 16px;padding:0;">
                <li>${escHtml(t('contexts.memoryDiagnostics.limitsMdDefault'))}</li>
                <li>${escHtml(t('contexts.memoryDiagnostics.limitsOverview'))}</li>
              </ul>
              <div style="margin-top:6px;">${escHtml(t('contexts.memoryDiagnostics.limitsFootnote'))}</div>
            </div>
            ${diagList.length ? `<ul style="margin:0 0 0 18px;">${diagList.map((d) => {
    const sev = String(d.severity || 'info');
    const sevLabel = t(`contexts.memoryDiagnostics.severity.${sev}`) || sev;
    const code = d.code ? ` <code>${escHtml(String(d.code))}</code>` : '';
    const recKey = d.recommended_action ? String(d.recommended_action) : '';
    const rec = recKey ? `<div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${escHtml(t(recKey))}</div>` : '';
    return `<li><strong>${escHtml(sevLabel)}</strong>${code} — ${escHtml(String(d.message || ''))}${d.memory_id ? ` <code>${escHtml(shortId(String(d.memory_id)))}</code>` : ''}${d.agent_id ? ` · <code>${escHtml(String(d.agent_id))}</code>` : ''}${rec}</li>`;
  }).join('')}</ul>` : `<div>${escHtml(t('contexts.memoryDiagnostics.noIssues'))}</div>`}
            ${expertAutoNotes.length ? `<div style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-color);">
              <div style="font-weight:700;margin-bottom:6px;">${escHtml(t('contexts.memoryDiagnostics.automationChecklist'))}</div>
              <ul style="margin:0 0 0 18px;">${expertAutoNotes.map((n) => `<li>${escHtml(String(n))}</li>`).join('')}</ul>
            </div>` : ''}
          </div>
        </details>` : '';

    const basicCompareSummary = (!isExpert && bc.result && typeof bc.result === 'object') ? (() => {
      const r = bc.result;
      const diff = r.diff && typeof r.diff === 'object' ? r.diff : {};
      const leftT = escHtml(String(r.left?.title || r.left?.context_id || '').slice(0, 72));
      const rightT = escHtml(String(r.right?.title || r.right?.context_id || '').slice(0, 72));
      const bullets = [];
      const objs = Array.isArray(diff.objectives) ? diff.objectives : [];
      objs.slice(0, 4).forEach((o) => {
        const ax = String(o.axis || '');
        if (ax === 'title') {
          bullets.push(`${escHtml(t('contexts.basicCompare.axisTitle'))}: « ${escHtml(String(o.left || '').slice(0, 120))} » / « ${escHtml(String(o.right || '').slice(0, 120))} »`);
        } else if (ax === 'status') {
          bullets.push(`${escHtml(t('contexts.basicCompare.axisStatus'))}: ${escHtml(String(o.left || ''))} → ${escHtml(String(o.right || ''))}`);
        } else if (ax === 'description') {
          bullets.push(escHtml(t('contexts.basicCompare.axisDesc')));
        }
      });
      const dec = diff.decisions;
      if (Array.isArray(dec) && dec.length) {
        bullets.push(`${escHtml(t('contexts.basicCompare.decisionDiffs'))}: ${dec.length}`);
      }
      const sess = diff.sessions_block;
      if (sess && typeof sess === 'object') {
        const onlyL = Array.isArray(sess.only_left) ? sess.only_left.length : 0;
        const onlyR = Array.isArray(sess.only_right) ? sess.only_right.length : 0;
        if (onlyL + onlyR > 0) {
          bullets.push(`${escHtml(t('contexts.basicCompare.sessionsDelta'))}: ${onlyL + onlyR}`);
        }
      }
      const listHtml = bullets.length
        ? `<ul style="margin:8px 0 0 18px;font-size:12px;color:var(--text-secondary);line-height:1.45;">${bullets.map((b) => `<li>${b}</li>`).join('')}</ul>`
        : `<div style="font-size:12px;color:var(--text-muted);margin-top:6px;">${escHtml(t('contexts.basicCompare.noStructuralDiff'))}</div>`;
      return `
        <div class="card sc-basic-compare-summary" style="margin-top:12px;padding:12px 14px;">
          <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.basicCompare.summaryTitle'))}</div>
          <div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.basicCompare.pairLine'))}: <strong>${leftT}</strong> ↔ <strong>${rightT}</strong></div>
          ${listHtml}
          <div style="margin-top:12px;">
            ${state.experimentalFeaturesEnabled ? `<button type="button" class="btn btn-secondary btn-sm" data-action="open-expert-deep-compare-from-basic">${escHtml(t('contexts.basicCompare.openExpert'))}</button>` : ''}
          </div>
        </div>`;
    })() : '';

    const agentMemUi = ui.agentContextMemory || {};
    const agentMemOpen = !!agentMemUi.open;
    const agentMemIds = resolvePrimaryWorkspaceAgentIds(selected);
    const agentMemExpertIds = isExpert && Array.isArray(selected.workspace_agents_expert_personas)
      ? selected.workspace_agents_expert_personas
        .map((a) => String(a.agent_id || '').trim().toLowerCase())
        .filter((id) => id && id !== 'synthesizer' && id !== 'devil_advocate')
      : [];
    const agentMemPickCandidates = [...agentMemIds, ...agentMemExpertIds.filter((id) => !agentMemIds.includes(id))];
    const agentMemUiId = String(agentMemUi.agentId || '').trim().toLowerCase();
    const agentMemPick = agentMemPickCandidates.includes(agentMemUiId)
      ? agentMemUiId
      : (agentMemPickCandidates[0] || '');
    let agentMemSelectInner = '';
    if (agentMemIds.length) {
      agentMemSelectInner += agentMemIds.map((id) => `<option value="${escHtml(id)}" ${agentMemPick === id ? 'selected' : ''}>${escHtml(workspaceAgentLabelForSelect(id, selected, t))}</option>`).join('');
    }
    if (isExpert && agentMemExpertIds.length) {
      if (agentMemIds.length) {
        agentMemSelectInner += `<optgroup label="${escHtml(t('contexts.agentsInvolved.expertOtherPersonas'))}">`;
      }
      agentMemSelectInner += agentMemExpertIds.map((id) => `<option value="${escHtml(id)}" ${agentMemPick === id ? 'selected' : ''}>${escHtml(workspaceAgentLabelForSelect(id, selected, t))}</option>`).join('');
      if (agentMemIds.length) {
        agentMemSelectInner += '</optgroup>';
      }
    }
    if (agentMemSelectInner === '') {
      agentMemSelectInner = `<option value="">${escHtml(t('contexts.workspaceAgents.noParticipantsYet'))}</option>`;
    }
    const agentMemoryPanel = `
      <div class="card" style="padding:14px 16px;margin-bottom:14px;border:1px solid rgba(99,102,241,0.25);">
        <div style="font-weight:800;margin-bottom:6px;">${escHtml(t('contexts.agentMemory.title'))}</div>
        <div style="font-size:12px;color:var(--text-secondary);line-height:1.45;margin-bottom:10px;">${escHtml(t('contexts.agentMemory.subtitle'))}</div>
        ${agentMemUi.error ? `<div class="error-banner" style="margin-bottom:10px;">⚠️ ${escHtml(agentMemUi.error)}</div>` : ''}
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
          ${agentMemOpen
      ? `<button type="button" class="btn btn-secondary btn-sm" data-action="close-agent-context-memory">${escHtml(t('contexts.agentMemory.closePanel'))}</button>`
      : `<button type="button" class="btn btn-secondary btn-sm" data-action="open-agent-context-memory">${escHtml(t('contexts.agentMemory.openPanel'))}</button>`}
          ${agentMemUi.loading ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
        </div>
        ${agentMemOpen ? `
          <div style="display:grid;gap:10px;">
            <div class="form-group" style="margin:0;max-width:280px;">
              <label>${escHtml(t('contexts.agentMemory.agent'))}</label>
              <select class="input" data-action="agent-context-memory-select-agent">
                ${agentMemSelectInner}
              </select>
            </div>
            <div style="border-top:1px solid var(--border-color);padding-top:10px;margin-top:2px;">
              <div class="form-group" style="margin:0;">
                <label>${escHtml(t('contexts.agentMemory.recentNoteField'))}</label>
                <textarea class="textarea" style="min-height:56px;" data-action="set-agent-context-memory-field" data-field="maintenanceRecentNote"
                  placeholder="${escHtml(t('contexts.agentMemory.recentNotePh'))}">${escHtml(String(agentMemUi.maintenanceRecentNote || ''))}</textarea>
              </div>
              <div class="form-group" style="margin:0;max-width:320px;">
                <label>${escHtml(t('contexts.agentMemory.sessionIdOptional'))}</label>
                <input type="text" class="input" data-action="set-agent-context-memory-field" data-field="maintenanceSessionId"
                  value="${escHtml(String(agentMemUi.maintenanceSessionId || ''))}" placeholder="${escHtml(t('contexts.agentMemory.sessionIdPh'))}" maxlength="80" />
              </div>
              <button type="button" class="btn btn-primary btn-sm" data-action="add-agent-context-recent-note" ${agentMemUi.recentNoteBusy ? 'disabled' : ''}>
                ${escHtml(t('contexts.agentMemory.recentNoteSubmit'))}
              </button>
              ${agentMemUi.recentNoteBusy ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
            </div>
            ${isExpert ? `
            <div class="form-group" style="margin:0;">
              <label>${escHtml(t('contexts.agentMemory.editor'))}</label>
              <textarea class="textarea" style="min-height:220px;font-family:ui-monospace,monospace;font-size:12px;"
                data-action="set-agent-context-memory-field" data-field="content">${escHtml(String(agentMemUi.content || ''))}</textarea>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button type="button" class="btn btn-primary btn-sm" data-action="save-agent-context-memory" ${agentMemUi.saving ? 'disabled' : ''}>
                ${escHtml(t('contexts.agentMemory.save'))}
              </button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="consolidate-agent-context-memory" ${agentMemUi.consolidating ? 'disabled' : ''}>
                ${escHtml(t('contexts.agentMemory.consolidate'))}
              </button>
            </div>
            <div style="border-top:1px solid var(--border-color);padding-top:10px;margin-top:4px;">
              <div class="form-group" style="margin:0;">
                <label>${escHtml(t('contexts.agentMemory.quickAppend'))}</label>
                <textarea class="textarea" style="min-height:56px;" data-action="set-agent-context-memory-field" data-field="appendNote"
                  placeholder="${escHtml(t('contexts.agentMemory.appendPh'))}">${escHtml(String(agentMemUi.appendNote || ''))}</textarea>
              </div>
              <div class="form-group" style="margin:0;max-width:260px;">
                <label>${escHtml(t('contexts.agentMemory.appendTarget'))}</label>
                <select class="input" data-action="set-agent-context-memory-field" data-field="appendSection">
                  <option value="recent" ${String(agentMemUi.appendSection || 'recent') !== 'pending' ? 'selected' : ''}>${escHtml(t('contexts.agentMemory.sectionRecent'))}</option>
                  <option value="pending" ${String(agentMemUi.appendSection || '') === 'pending' ? 'selected' : ''}>${escHtml(t('contexts.agentMemory.sectionPending'))}</option>
                </select>
              </div>
              <button type="button" class="btn btn-secondary btn-sm" data-action="append-agent-context-memory-note">${escHtml(t('contexts.agentMemory.appendBtn'))}</button>
            </div>
            <div class="card" data-ui="expert-only" style="padding:12px;margin-top:8px;background:var(--bg-secondary);border:1px dashed var(--border-color);">
              <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">${escHtml(t('contexts.agentMemory.maintenanceExpert'))}</div>
              <div class="form-group" style="margin:0;">
                <label>${escHtml(t('contexts.agentMemory.contradictionField'))}</label>
                <textarea class="textarea" style="min-height:48px;" data-action="set-agent-context-memory-field" data-field="contradictionText"
                  placeholder="${escHtml(t('contexts.agentMemory.contradictionPh'))}">${escHtml(String(agentMemUi.contradictionText || ''))}</textarea>
              </div>
              <div class="form-group" style="margin:0;max-width:320px;">
                <label>${escHtml(t('contexts.agentMemory.contradictionSource'))}</label>
                <input type="text" class="input" data-action="set-agent-context-memory-field" data-field="contradictionSource" value="${escHtml(String(agentMemUi.contradictionSource || ''))}" maxlength="200" />
              </div>
              <button type="button" class="btn btn-secondary btn-sm" data-action="add-agent-context-contradiction" ${agentMemUi.contradictionBusy ? 'disabled' : ''}>${escHtml(t('contexts.agentMemory.contradictionBtn'))}</button>
              <div class="form-group" style="margin-top:10px;margin-bottom:0;">
                <label>${escHtml(t('contexts.agentMemory.deprecateField'))}</label>
                <textarea class="textarea" style="min-height:44px;" data-action="set-agent-context-memory-field" data-field="deprecateText"
                  placeholder="${escHtml(t('contexts.agentMemory.deprecatePh'))}">${escHtml(String(agentMemUi.deprecateText || ''))}</textarea>
              </div>
              <div class="form-group" style="margin:0;max-width:320px;">
                <label>${escHtml(t('contexts.agentMemory.deprecateReason'))}</label>
                <input type="text" class="input" data-action="set-agent-context-memory-field" data-field="deprecateReason" value="${escHtml(String(agentMemUi.deprecateReason || ''))}" maxlength="400" />
              </div>
              <button type="button" class="btn btn-secondary btn-sm" data-action="deprecate-agent-context-memory-text" ${agentMemUi.deprecateBusy ? 'disabled' : ''}>${escHtml(t('contexts.agentMemory.deprecateBtn'))}</button>
              <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <button type="button" class="btn btn-secondary btn-sm" data-action="compact-agent-context-memory" ${agentMemUi.compactBusy ? 'disabled' : ''}>${escHtml(t('contexts.agentMemory.compactBtn'))}</button>
                ${agentMemUi.compactBusy ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
              </div>
            </div>
            ` : `<div style="font-size:12px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.agentMemory.expertEditorHint'))}</div>`}
          </div>
        ` : ''}
      </div>
    `;

    const mg = ui.memoryGovernance || {};
    const mgData = (mg.data && typeof mg.data === 'object') ? mg.data : null;
    const mgCounts = (mgData?.counts && typeof mgData.counts === 'object') ? mgData.counts : {};
    const mgItems = Array.isArray(mgData?.items) ? mgData.items : [];
    const mgEvents = Array.isArray(mgData?.recent_events) ? mgData.recent_events : [];
    const memoryGovernancePanel = (isExpert && canShowExperimental) ? `
      <div class="card" data-ui="expert-only" style="padding:14px 16px;margin-bottom:14px;border:1px solid rgba(251,191,36,0.38);">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-weight:800;">${escHtml(t('contexts.memoryGovernance.title'))}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${escHtml(t('contexts.memoryGovernance.subtitle'))}</div>
          </div>
          ${renderBadge({ text: `${String(selected.context_id || '').slice(0, 8)}…`, variant: 'warning' })}
        </div>
        ${mg.error ? renderAlert({ variant: 'danger', text: mg.error }) : ''}
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
          <button type="button" class="btn btn-secondary btn-sm" data-action="toggle-memory-governance-panel">${escHtml(mg.panelOpen ? t('contexts.memoryGovernance.hide') : t('contexts.memoryGovernance.show'))}</button>
          <button type="button" class="btn btn-secondary btn-sm" data-action="refresh-memory-governance" ${mg.loading || !mg.panelOpen ? 'disabled' : ''}>${escHtml(t('contexts.memoryGovernance.refresh'))}</button>
          ${mg.loading ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
        </div>
        ${mg.panelOpen ? `
          <div style="margin-top:10px;border:1px solid var(--border-color);border-radius:8px;padding:10px;background:var(--bg-secondary);">
            <div style="font-size:12px;color:var(--text-secondary);display:flex;gap:8px;flex-wrap:wrap;">
              ${['pending', 'stable', 'contested', 'archived', 'deprecated', 'invalidated']
      .map((st) => `<span class="badge badge-muted">${escHtml(st)}: ${escHtml(String(mgCounts?.[st] ?? 0))}</span>`).join('')}
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
              <div style="font-weight:700;margin-bottom:4px;">${escHtml(t('contexts.memoryGovernance.items'))}</div>
              <div style="max-height:180px;overflow:auto;border:1px dashed var(--border-color);border-radius:6px;padding:8px;background:var(--bg-primary);">
                ${mgItems.length === 0
      ? `<div>${escHtml(t('contexts.memoryGovernance.emptyItems'))}</div>`
      : mgItems.slice(0, 60).map((it) => `
                    <div style="margin-bottom:6px;">
                      <strong>${escHtml(String(it.title || it.entity_id || 'item'))}</strong>
                      <span class="badge badge-muted" style="margin-left:6px;">${escHtml(String(it.governance_status || 'pending'))}</span>
                      <span style="margin-left:6px;">${escHtml(String(it.entity_type || ''))}</span>
                      <span style="margin-left:6px;color:var(--text-muted);">trust ${escHtml(String(it.trust_level ?? '0.5'))}</span>
                    </div>
                  `).join('')}
              </div>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
              <div style="font-weight:700;margin-bottom:4px;">${escHtml(t('contexts.memoryGovernance.events'))}</div>
              <div style="max-height:180px;overflow:auto;border:1px dashed var(--border-color);border-radius:6px;padding:8px;background:var(--bg-primary);">
                ${mgEvents.length === 0
      ? `<div>${escHtml(t('contexts.memoryGovernance.emptyEvents'))}</div>`
      : mgEvents.slice(0, 80).map((ev) => `
                    <div style="margin-bottom:6px;">
                      <strong>${escHtml(String(ev.event_type || 'event'))}</strong>
                      <span class="badge badge-muted" style="margin-left:6px;">${escHtml(String(ev.governance_status || 'pending'))}</span>
                      <span style="margin-left:6px;">${escHtml(String(ev.entity_type || ''))}</span>
                      <span style="margin-left:6px;color:var(--text-muted);">${escHtml(String(ev.occurred_at || ''))}</span>
                    </div>
                  `).join('')}
              </div>
            </div>
          </div>
        ` : ''}
      </div>
    ` : '';

    const scChat = ui.situatedAgentChat || {};
    const chatAgentIds = resolvePrimaryWorkspaceAgentIds(selected);
    const chatExpertIds = isExpert && Array.isArray(selected.workspace_agents_expert_personas)
      ? selected.workspace_agents_expert_personas
        .map((a) => String(a.agent_id || '').trim().toLowerCase())
        .filter((id) => id && id !== 'synthesizer' && id !== 'devil_advocate')
      : [];
    const chatPickCandidates = [...chatAgentIds, ...chatExpertIds.filter((id) => !chatAgentIds.includes(id))];
    const chatUiId = String(scChat.agentId || '').trim().toLowerCase();
    const chatPick = chatPickCandidates.includes(chatUiId) ? chatUiId : (chatPickCandidates[0] || '');
    let chatSelectInner = '';
    if (chatAgentIds.length) {
      chatSelectInner += chatAgentIds.map((id) => `<option value="${escHtml(id)}" ${chatPick === id ? 'selected' : ''}>${escHtml(workspaceAgentLabelForSelect(id, selected, t))}</option>`).join('');
    }
    if (isExpert && chatExpertIds.length) {
      if (chatAgentIds.length) {
        chatSelectInner += `<optgroup label="${escHtml(t('contexts.agentsInvolved.expertOtherPersonas'))}">`;
      }
      chatSelectInner += chatExpertIds.map((id) => `<option value="${escHtml(id)}" ${chatPick === id ? 'selected' : ''}>${escHtml(workspaceAgentLabelForSelect(id, selected, t))}</option>`).join('');
      if (chatAgentIds.length) {
        chatSelectInner += '</optgroup>';
      }
    }
    if (chatSelectInner === '') {
      chatSelectInner = `<option value="">${escHtml(t('contexts.workspaceAgents.noParticipantsYet'))}</option>`;
    }
    const cognitiveRuntime = (scChat.lastCognitiveRuntime && typeof scChat.lastCognitiveRuntime === 'object')
      ? scChat.lastCognitiveRuntime
      : null;
    const promptTrace = (scChat.lastPromptTrace && typeof scChat.lastPromptTrace === 'object')
      ? scChat.lastPromptTrace
      : null;
    const situatedChatPanel = isExpert ? `
      <div class="card" data-ui="expert-only" style="padding:14px 16px;margin-bottom:14px;border:1px solid rgba(16,185,129,0.28);">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-weight:800;">${escHtml(t('contexts.situatedChat.title'))}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${escHtml(t('contexts.situatedChat.subtitle'))}</div>
          </div>
          ${renderBadge({ text: `${String(selected.context_id || '').slice(0, 8)}…`, variant: 'info' })}
        </div>
        ${scChat.error ? renderAlert({ variant: 'danger', text: scChat.error }) : ''}
        ${Array.isArray(scChat.lastWarnings) && scChat.lastWarnings.length
      ? renderAlert({
        variant: 'info',
        bodyHtml: scChat.lastWarnings.map((w) => `<div style="margin-top:4px;">${escHtml(String(w))}</div>`).join(''),
      })
      : ''}
        ${cognitiveRuntime ? `
          <div style="margin-top:10px;border:1px solid var(--border-color);border-radius:8px;padding:10px;background:var(--bg-secondary);">
            <div style="font-weight:700;margin-bottom:6px;">${escHtml(t('contexts.situatedChat.cognitiveRuntimeTitle'))}</div>
            <div style="font-size:12px;color:var(--text-secondary);line-height:1.45;">
              <div><strong>${escHtml(t('contexts.situatedChat.sourcesUsed'))}:</strong> ${escHtml(Array.isArray(cognitiveRuntime.sources_used) && cognitiveRuntime.sources_used.length ? cognitiveRuntime.sources_used.join(', ') : '—')}</div>
              <div style="margin-top:4px;"><strong>${escHtml(t('contexts.situatedChat.contestedBeliefs'))}:</strong></div>
              <ul style="margin:4px 0 0 18px;">
                ${(Array.isArray(cognitiveRuntime.contested_beliefs) && cognitiveRuntime.contested_beliefs.length
                  ? cognitiveRuntime.contested_beliefs
                  : ['—']).map((line) => `<li>${escHtml(String(line))}</li>`).join('')}
              </ul>
              <div style="margin-top:6px;"><strong>${escHtml(t('contexts.situatedChat.disclaimers'))}:</strong></div>
              <ul style="margin:4px 0 0 18px;">
                ${(Array.isArray(cognitiveRuntime.disclaimers) && cognitiveRuntime.disclaimers.length
                  ? cognitiveRuntime.disclaimers
                  : ['—']).map((line) => `<li>${escHtml(String(line))}</li>`).join('')}
              </ul>
            </div>
          </div>
        ` : ''}
        ${promptTrace ? `
          <div style="margin-top:10px;border:1px dashed var(--border-color);border-radius:8px;padding:10px;background:var(--bg-secondary);">
            <div style="font-weight:700;margin-bottom:6px;">${escHtml(t('contexts.situatedChat.traceTitle'))}</div>
            <div style="font-size:12px;color:var(--text-secondary);line-height:1.45;">
              <div><strong>${escHtml(t('contexts.situatedChat.traceMode'))}:</strong> ${escHtml(String(promptTrace.mode || '—'))}</div>
              <div><strong>${escHtml(t('contexts.situatedChat.traceSteps'))}:</strong> ${escHtml(String(Array.isArray(promptTrace.steps) ? promptTrace.steps.length : 0))}</div>
              <div><strong>${escHtml(t('contexts.situatedChat.traceChars'))}:</strong> ${escHtml(String(promptTrace.total_injected_chars_user_message || 0))}</div>
            </div>
          </div>
        ` : ''}
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;">
          ${scChat.open
      ? `<button type="button" class="btn btn-secondary btn-sm" data-action="close-context-agent-chat">${escHtml(t('contexts.situatedChat.close'))}</button>`
      : `<button type="button" class="btn btn-secondary btn-sm" data-action="open-context-agent-chat">${escHtml(t('contexts.situatedChat.open'))}</button>`}
          ${scChat.open ? `<button type="button" class="btn btn-secondary btn-sm" data-action="reset-situated-agent-chat">${escHtml(t('contexts.situatedChat.newThread'))}</button>` : ''}
        </div>
        ${scChat.open ? `
          <div style="display:grid;gap:10px;margin-top:8px;">
            <div class="form-group" style="margin:0;max-width:280px;">
              <label>${escHtml(t('contexts.agentMemory.agent'))}</label>
              <select class="input" data-action="select-context-agent-chat-agent">
                ${chatSelectInner}
              </select>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:12px;">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" data-action="set-situated-agent-chat-field" data-field="includeMemory" ${scChat.includeMemory !== false ? 'checked' : ''}/>
                ${escHtml(t('contexts.situatedChat.optMemory'))}
              </label>
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" data-action="set-situated-agent-chat-field" data-field="includeRecentDecisions" ${scChat.includeRecentDecisions !== false ? 'checked' : ''}/>
                ${escHtml(t('contexts.situatedChat.optDecisions'))}
              </label>
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" data-action="set-situated-agent-chat-field" data-field="includeSocialContext" ${scChat.includeSocialContext !== false ? 'checked' : ''}/>
                ${escHtml(t('contexts.situatedChat.optSocial'))}
              </label>
            </div>
            <div class="sc-situated-thread" style="max-height:280px;overflow:auto;border:1px solid var(--border-color);border-radius:8px;padding:10px;background:var(--bg-secondary);">
              ${(!scChat.messages || scChat.messages.length === 0)
      ? renderEmptyState({ icon: '💬', text: t('contexts.situatedChat.emptyThread') })
      : (scChat.messages || []).map((m) => {
        const isUser = m.role === 'user';
        return `<div style="margin-bottom:10px;text-align:${isUser ? 'right' : 'left'};">
            <span class="badge ${isUser ? 'badge-muted' : 'badge-success'}" style="margin-bottom:4px;">${isUser ? escHtml(t('contexts.situatedChat.you')) : escHtml(String(scChat.agentId || ''))}</span>
            <div style="font-size:13px;line-height:1.45;color:var(--text-primary);white-space:pre-wrap;">${escHtml(String(m.content || ''))}</div>
          </div>`;
      }).join('')}
            </div>
            <div class="form-group" style="margin:0;">
              <label>${escHtml(t('contexts.situatedChat.message'))}</label>
              <textarea class="textarea" style="min-height:72px;" data-action="set-situated-agent-chat-field" data-field="input"
                placeholder="${escHtml(t('contexts.situatedChat.placeholder'))}">${escHtml(String(scChat.input || ''))}</textarea>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
              <button type="button" class="btn btn-primary btn-sm" data-action="send-context-agent-message" ${scChat.loading ? 'disabled' : ''}>${escHtml(t('contexts.situatedChat.send'))}</button>
              ${scChat.loading ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
            </div>
          </div>
        ` : ''}
      </div>
    ` : '';

    return `
      ${expressCard}
      ${agentMemoryPanel}
      ${memoryGovernancePanel}
      ${situatedChatPanel}
      ${ui.memoryMdOpen ? (() => {
        const perspectiveItems = buildRunPersonaPerspectiveItems(selected, state);
        const perspectiveLabels = perspectiveItems.reduce((acc, it) => {
          acc[it.key] = it.label;
          return acc;
        }, {});
        const availablePerspectiveKeys = perspectiveItems.map((it) => it.key);
        const requestedPerspective = String(ui.memoryMdPerspective || 'default');
        const activePerspective = (availablePerspectiveKeys.length && !availablePerspectiveKeys.includes(requestedPerspective))
          ? availablePerspectiveKeys[0]
          : requestedPerspective;
        const isExpertUi = (state.uiComplexity === 'expert');
        const segmented = renderPerspectiveSegmentedControl({
          action: 'set-context-memory-perspective',
          selected: activePerspective,
          items: perspectiveItems,
          escHtml,
          t,
          ariaLabel: t('snapshots.perspective'),
        });
        const md = String(ui.memoryMdContent || '');
        const docHtml = md
          ? renderPerspectiveSnapshot(md, {
              escHtml,
              t,
              isExpert: isExpertUi,
              perspective: activePerspective,
              perspectiveLabels,
              raw: isExpertUi ? md : '',
            })
          : `<div class="ps-empty">${escHtml(t('snapshots.loading'))}</div>`;
        return `
        <div class="card ps-panel" data-snapshot-panel="strategic-context" style="padding:14px 16px;margin-bottom:14px;">
          <div class="ps-panel-toolbar">
            <div class="ps-panel-toolbar-title">${escHtml(t('snapshots.memoryMdTitle'))}
              ${ui.memoryMdLoading ? `<span class="ps-panel-status">${escHtml(t('snapshots.loading'))}</span>` : ''}
            </div>
            <div class="ps-panel-toolbar-actions">
              ${segmented}
              <button type="button" class="btn btn-secondary btn-sm" data-action="copy-context-memory-md">${escHtml(t('snapshots.copy'))}</button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="close-context-memory-md">${escHtml(t('snapshots.close'))}</button>
            </div>
          </div>
          ${ui.memoryMdError ? `<div class="error-banner" style="margin-top:6px;">⚠️ ${escHtml(ui.memoryMdError)}</div>` : ''}
          <div class="ps-note" style="margin-top:8px;font-size:12px;color:var(--text-muted);line-height:1.5;padding:8px 10px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border-subtle);">${escHtml(t('contexts.memoryMdDisclaimers.contextView'))}</div>
          <div class="ps-note" style="margin-top:6px;font-size:11px;color:var(--text-muted);line-height:1.45;padding:6px 10px;background:var(--bg-secondary);border-radius:8px;border:1px dashed var(--border-subtle);">
            <div>${escHtml(t('contexts.memoryMdPanel.decisionsWindowCount'))}</div>
            <div style="margin-top:4px;">${escHtml(t('contexts.memoryMdPanel.decisionsWindowHint'))}</div>
          </div>
          <div class="ps-scroll" data-snapshot-scroll="strategic-context" tabindex="0">
            ${docHtml}
          </div>
        </div>
      `; })() : ''}
      <div class="card" style="padding:16px 18px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
          <div style="flex:1;min-width:240px;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <div style="font-weight:800;font-size:16px;">${escHtml(selected.title)}</div>
              ${Number(selected.is_workspace_active) === 1 || String(selected.context_id) === activeWorkspaceId
      ? `<span class="badge badge-success">${escHtml(t('contexts.workspaceBadge'))}</span>`
      : ''}
              <span class="badge ${badgeForStatus(selected.status)}">${escHtml(lifecycleStatusLabel(t, selected.status))}</span>
            </div>
            ${selected.updated_at ? `<div style="margin-top:8px;font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.updatedAt'))}: ${escHtml(formatDate(selected.updated_at))}</div>` : ''}
            ${(() => {
      const st = String(selected.status || '');
      const isAlreadyWorkspace = Number(selected.is_workspace_active) === 1
        || String(selected.context_id || '') === activeWorkspaceId;
      const canActivate = ['active', 'paused'].includes(st) && !isAlreadyWorkspace;
      if (!canActivate) return '';
      return `
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              <button type="button" class="btn btn-primary btn-sm" data-action="activate-strategic-context" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.activateThis'))}</button>
            </div>`;
    })()}
            ${selected.description ? `<div style="margin-top:8px;font-size:13px;color:var(--text-secondary);line-height:1.5;">${escHtml(selected.description)}</div>` : ''}
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              ${cs.current_decision_status ? `<span class="badge badge-info">${escHtml(cs.current_decision_status)}</span>` : ''}
              ${cs.current_confidence ? `<span class="badge badge-muted">${escHtml(t('contexts.confidence'))}: ${escHtml(cs.current_confidence)}</span>` : ''}
              <span class="badge ${risks.length ? 'badge-warning' : 'badge-muted'}">${escHtml(t('contexts.risks'))}: ${risks.length}</span>
            </div>
            ${cs.latest_next_step ? `<div style="margin-top:10px;font-size:13px;color:var(--text-primary);"><strong>${escHtml(t('contexts.next'))}:</strong> ${escHtml(cs.latest_next_step)}</div>` : ''}
            ${risks.length ? `<div style="margin-top:10px;font-size:12px;color:var(--text-secondary);"><strong>${escHtml(t('contexts.activeRisks'))}</strong><ul style="margin:6px 0 0 18px;">${risks.slice(0, 8).map((r) => `<li>${escHtml(String(r))}</li>`).join('')}</ul></div>` : ''}
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
            <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="open-edit-strategic-context" data-context-id="${escHtml(selected.context_id)}">✏️ ${escHtml(t('contexts.edit'))}</button>
            <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="open-link-to-context" data-context-id="${escHtml(selected.context_id)}">🔗 ${escHtml(t('contexts.link'))}</button>
            <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="archive-strategic-context" data-context-id="${escHtml(selected.context_id)}">📦 ${escHtml(t('contexts.archive'))}</button>
            <button class="btn btn-danger btn-sm" data-ui="expert-only" data-action="request-delete-strategic-context" data-context-id="${escHtml(selected.context_id)}">🗑️ ${escHtml(t('contexts.delete'))}</button>
          </div>
        </div>

        ${memoryOverviewBlock}
        ${decisionsMemorizedSection}
        ${agentsInvolvedSection}
        ${basicActionsBar}
        ${basicCompareSummary}

        ${ui.archiveError ? `<div class="error-banner" style="margin-top:12px;">⚠️ ${escHtml(ui.archiveError)}</div>` : ''}
        ${ui.deleteError ? `<div class="error-banner" style="margin-top:12px;">⚠️ ${escHtml(ui.deleteError)}</div>` : ''}

        ${isExpert && canShowExperimental ? (() => {
          const sn = ui.strategicNarrative || { loading: false, error: '', data: null };
          const api = sn.data && typeof sn.data === 'object' ? sn.data : null;
          const nar = api && api.narrative && typeof api.narrative === 'object' ? api.narrative : null;
          const warns = Array.isArray(api?.warnings) ? api.warnings.filter(Boolean) : [];
          const narrOpen = !!(sn.error || warns.length);
          const inner = (() => {
            if (sn.loading) {
              return `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.strategicNarrative.loading'))}</div>`;
            }
            if (sn.error) {
              return `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(sn.error)}</div>`;
            }
            if (!nar) {
              return `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.strategicNarrative.hint'))}</div>`;
            }
            const dir = String(nar.current_direction || '').trim();
            const risks = Array.isArray(nar.major_risks) ? nar.major_risks : [];
            const confs = Array.isArray(nar.unresolved_conflicts) ? nar.unresolved_conflicts : [];
            const ct = String(nar.confidence_trend || '').trim();
            const hyp = Array.isArray(nar.key_assumptions) ? nar.key_assumptions : [];
            const shifts = Array.isArray(nar.recent_shifts) ? nar.recent_shifts : [];
            const when = nar.computed_at ? formatDate(nar.computed_at) : '—';
            const warnBlock = warns.length
              ? `<div style="margin-bottom:10px;">${renderAlert({
                variant: 'warning',
                bodyHtml: warns.map((w) => `<div style="margin-top:4px;">${escHtml(String(w))}</div>`).join(''),
              })}</div>`
              : '';
            const notComputed = !nar.computed_at && warns.includes('narrative_not_computed');
            const hintEmpty = notComputed
              ? `<div style="margin-bottom:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.strategicNarrative.notComputed'))}</div>`
              : '';
            const listOrEmpty = (arr) => {
              if (!arr.length) return renderEmptyState({ icon: '📭', text: t('contexts.strategicNarrative.none') });
              return `<ul style="margin:6px 0 0 18px;font-size:12px;color:var(--text-secondary);line-height:1.45;">${arr.map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>`;
            };
            return `
              ${hintEmpty}
              ${warnBlock}
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;"><strong>${escHtml(t('contexts.strategicNarrative.computedAt'))}:</strong> ${escHtml(when)}</div>
              <div style="margin-top:10px;font-size:13px;color:var(--text-primary);line-height:1.5;">
                <strong>${escHtml(t('contexts.strategicNarrative.direction'))}</strong>
                <div style="margin-top:4px;white-space:pre-wrap;">${dir ? escHtml(dir) : renderEmptyState({ icon: '—', text: t('contexts.strategicNarrative.none') })}</div>
              </div>
              <div style="margin-top:12px;"><strong style="font-size:13px;">${escHtml(t('contexts.strategicNarrative.risks'))}</strong>${listOrEmpty(risks)}</div>
              <div style="margin-top:12px;"><strong style="font-size:13px;">${escHtml(t('contexts.strategicNarrative.conflicts'))}</strong>${listOrEmpty(confs)}</div>
              <div style="margin-top:12px;font-size:13px;color:var(--text-primary);">
                <strong>${escHtml(t('contexts.strategicNarrative.confidence'))}</strong>
                <div style="margin-top:4px;">${ct ? escHtml(ct) : '—'}</div>
              </div>
              <div style="margin-top:12px;"><strong style="font-size:13px;">${escHtml(t('contexts.strategicNarrative.assumptions'))}</strong>${listOrEmpty(hyp)}</div>
              <div style="margin-top:12px;"><strong style="font-size:13px;">${escHtml(t('contexts.strategicNarrative.shifts'))}</strong>${listOrEmpty(shifts)}</div>
            `;
          })();
          return `
        <div data-ui="expert-only" style="margin-top:14px;font-weight:800;font-size:13px;color:var(--text-muted);">${escHtml(t('contexts.expertLayers.sectionTitle'))} <span class="badge badge-warning" style="margin-left:6px;font-size:10px;">${escHtml(t('experimental.badge'))}</span></div>
        <details class="sc-expert-details" style="margin-top:12px;" data-panel="strategic-narrative"${narrOpen ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);padding:4px 2px 8px;">${escHtml(t('contexts.expertLayers.strategicNarrative'))}</summary>
        <div class="card" style="margin-top:0;padding:12px 14px;" data-panel="strategic-narrative-inner">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.strategicNarrative.title'))}</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">${escHtml(t('contexts.strategicNarrative.subtitle'))}</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-strategic-narrative" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.strategicNarrative.load'))}</button>
              ${isExpert ? `<button type="button" class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="recompute-strategic-narrative" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.strategicNarrative.recompute'))}</button>` : ''}
            </div>
          </div>
          ${inner}
        </div></details>`;
        })() : ''}

        ${expertMemoryDiagnostics}

        ${isExpert && canShowExperimental ? (() => {
          const be = ui.beliefsEngine || {
            loading: false, error: '', items: [], mode: 'context', filterAgent: '', filterType: '', filterStatus: '',
            disputedOnly: false, byAgentId: '', saving: false, formError: '',
          };
          const raw = Array.isArray(be.items) ? be.items : [];
          const fil = raw.filter((b) => {
            if (be.filterAgent && String(b.agent_id || '') !== be.filterAgent) return false;
            if (be.filterType && String(b.belief_type || '') !== be.filterType) return false;
            if (be.filterStatus && String(b.status || '') !== be.filterStatus) return false;
            if (be.disputedOnly) {
              const dg = Array.isArray(b.disagreeing_agents) ? b.disagreeing_agents : [];
              if (String(b.status || '') !== 'disputed' && dg.length === 0) return false;
            }
            return true;
          });
          const agents = [...new Set(raw.map((b) => b.agent_id).filter(Boolean))].sort();
          const tension = raw.filter((b) => {
            const dg = Array.isArray(b.disagreeing_agents) ? b.disagreeing_agents : [];
            return String(b.status || '') === 'disputed' || dg.length > 0;
          });
          const confBadge = (c) => renderBadge({
            text: `${Math.round((Number(c) || 0) * 100)}%`,
            variant: (Number(c) || 0) >= 0.66 ? 'success' : (Number(c) || 0) >= 0.4 ? 'warning' : 'danger',
          });
          const stBadge = (s) => {
            const v = String(s || '');
            const variant = v === 'disputed' ? 'danger' : v === 'deprecated' || v === 'archived' ? 'muted' : v === 'active' ? 'success' : 'warning';
            return renderBadge({ text: v || '—', variant });
          };
          const rows = fil.map((b) => {
            const sup = Array.isArray(b.supporting_agents) ? b.supporting_agents.join(', ') : '';
            const dis = Array.isArray(b.disagreeing_agents) ? b.disagreeing_agents.join(', ') : '';
            const src = [b.source_type, b.source_reference_id].filter(Boolean).join(' · ');
            return `<tr style="font-size:12px;color:var(--text-secondary);vertical-align:top;">
              <td style="padding:6px 8px;">${stBadge(b.status)} ${confBadge(b.confidence)}</td>
              <td style="padding:6px 8px;">${renderBadge({ text: escHtml(String(b.belief_type || '')), variant: 'info' })}</td>
              <td style="padding:6px 8px;">${escHtml(String(b.agent_id || '—'))}</td>
              <td style="padding:6px 8px;color:var(--text-primary);">${escHtml(String(b.belief_text || ''))}</td>
              <td style="padding:6px 8px;font-size:11px;">${escHtml(sup || '—')}</td>
              <td style="padding:6px 8px;font-size:11px;">${escHtml(dis || '—')}</td>
              <td style="padding:6px 8px;font-size:11px;">${escHtml(src || '—')}</td>
              <td style="padding:6px 8px;white-space:nowrap;">
                ${String(b.status) !== 'archived' ? `<button type="button" class="btn btn-secondary btn-sm" data-action="archive-belief" data-context-id="${escHtml(selected.context_id)}" data-belief-id="${escHtml(String(b.id))}">${escHtml(t('contexts.beliefsEngine.archive'))}</button>` : ''}
                ${String(b.status) !== 'deprecated' && String(b.status) !== 'archived' ? `<button type="button" class="btn btn-secondary btn-sm" data-action="deprecate-belief" data-context-id="${escHtml(selected.context_id)}" data-belief-id="${escHtml(String(b.id))}" style="margin-left:4px;">${escHtml(t('contexts.beliefsEngine.deprecate'))}</button>` : ''}
                ${String(b.status) === 'proposed' ? `<button type="button" class="btn btn-primary btn-sm" data-action="update-belief" data-context-id="${escHtml(selected.context_id)}" data-belief-id="${escHtml(String(b.id))}" data-next-status="active" style="margin-left:4px;">${escHtml(t('contexts.beliefsEngine.activate'))}</button>` : ''}
              </td>
            </tr>`;
          }).join('');
          const tensionBlock = tension.length
            ? `${renderAlert({
              variant: 'warning',
              bodyHtml: `<div style="font-weight:800;margin-bottom:6px;">${escHtml(t('contexts.beliefsEngine.tensionsTitle'))}</div><ul style="margin:6px 0 0 18px;">${tension.slice(0, 12).map((b) => `<li><strong>${escHtml(String(b.agent_id || '—'))}</strong> — ${escHtml(String(b.belief_text || '').slice(0, 140))}</li>`).join('')}</ul>`,
            })}`
            : '';
          const beliefsOpen = !!(be.error || be.formError || tension.length);
          return `
        <details class="sc-expert-details" style="margin-top:12px;" data-ui="expert-only" data-panel="beliefs-engine-wrap"${beliefsOpen ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);padding:4px 2px 8px;">${escHtml(t('contexts.expertLayers.beliefs'))}</summary>
        <div class="card" style="margin-top:0;padding:12px 14px;" data-ui="expert-only" data-panel="beliefs-engine">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.beliefsEngine.title'))}</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">${escHtml(t('contexts.beliefsEngine.subtitle'))}</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">${escHtml(t('contexts.beliefsEngine.mode'))}: <strong>${escHtml(be.mode === 'agent' ? t('contexts.beliefsEngine.modeAgent') : t('contexts.beliefsEngine.modeContext'))}</strong>${be.mode === 'agent' && be.byAgentId ? ` · <code>${escHtml(be.byAgentId)}</code>` : ''}</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-context-beliefs" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.beliefsEngine.loadAll'))}</button>
            </div>
          </div>
          ${be.loading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.beliefsEngine.loading'))}</div>` : ''}
          ${be.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(be.error)}</div>` : ''}
          ${be.formError ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(be.formError)}</div>` : ''}
          ${tensionBlock}
          <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.beliefsEngine.filterAgent'))}</label><br/>
              <select class="input" style="min-width:120px;" data-action="filter-beliefs-by-agent">
                <option value="">${escHtml(t('contexts.beliefsEngine.all'))}</option>
                ${agents.map((a) => `<option value="${escHtml(String(a))}" ${be.filterAgent === a ? 'selected' : ''}>${escHtml(String(a))}</option>`).join('')}
              </select>
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.beliefsEngine.filterType'))}</label><br/>
              <select class="input" style="min-width:140px;" data-action="filter-beliefs-by-type">
                <option value="">${escHtml(t('contexts.beliefsEngine.all'))}</option>
                ${['fact', 'belief', 'hypothesis', 'interpretation', 'social_perception'].map((tp) => `<option value="${tp}" ${be.filterType === tp ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.beliefsEngine.filterStatus'))}</label><br/>
              <select class="input" style="min-width:120px;" data-action="filter-beliefs-by-status">
                <option value="">${escHtml(t('contexts.beliefsEngine.all'))}</option>
                ${['proposed', 'active', 'disputed', 'deprecated', 'archived'].map((tp) => `<option value="${tp}" ${be.filterStatus === tp ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <label style="display:flex;gap:6px;align-items:center;font-size:12px;color:var(--text-secondary);cursor:pointer;">
              <input type="checkbox" data-action="toggle-beliefs-disputed-only" ${be.disputedOnly ? 'checked' : ''} />
              ${escHtml(t('contexts.beliefsEngine.disputedOnly'))}
            </label>
          </div>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;margin-bottom:6px;">${escHtml(t('contexts.beliefsEngine.byAgentTitle'))}</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
              <select class="input" id="da-beliefs-agent-${escHtml(selected.context_id)}" style="min-width:160px;">
                <option value="">${escHtml(t('contexts.beliefsEngine.pickAgent'))}</option>
                ${agents.map((a) => `<option value="${escHtml(String(a))}">${escHtml(String(a))}</option>`).join('')}
              </select>
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-agent-beliefs" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.beliefsEngine.loadAgentBeliefs'))}</button>
            </div>
          </div>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;margin-bottom:8px;">${escHtml(t('contexts.beliefsEngine.createTitle'))}</div>
            <form data-beliefs-form-root="${escHtml(selected.context_id)}" style="display:grid;gap:8px;max-width:720px;" onsubmit="return false;">
              <textarea class="input" name="belief_text" rows="2" placeholder="${escHtml(t('contexts.beliefsEngine.phText'))}"></textarea>
              <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <select class="input" name="belief_type">${['hypothesis', 'belief', 'interpretation', 'social_perception', 'fact'].map((tp) => `<option value="${tp}">${escHtml(tp)}</option>`).join('')}</select>
                <input class="input" name="agent_id" placeholder="${escHtml(t('contexts.beliefsEngine.phAgent'))}" style="width:140px;" />
                <input class="input" name="confidence" type="number" min="0" max="1" step="0.05" value="0.6" style="width:100px;" />
                <select class="input" name="status">${['proposed', 'active', 'disputed'].map((tp) => `<option value="${tp}" ${tp === 'proposed' ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}</select>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <input class="input" name="supporting_agents" placeholder="${escHtml(t('contexts.beliefsEngine.phSup'))}" style="flex:1;min-width:200px;" />
                <input class="input" name="disagreeing_agents" placeholder="${escHtml(t('contexts.beliefsEngine.phDis'))}" style="flex:1;min-width:200px;" />
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <select class="input" name="source_type"><option value="">${escHtml(t('contexts.beliefsEngine.sourceNone'))}</option>${['session', 'evidence', 'relationship_event', 'memory', 'user', 'manual'].map((s) => `<option value="${s}">${escHtml(s)}</option>`).join('')}</select>
                <input class="input" name="source_reference_id" placeholder="${escHtml(t('contexts.beliefsEngine.phSourceId'))}" style="flex:1;min-width:200px;" />
                <input class="input" name="created_by" value="user" style="width:120px;" />
              </div>
              <button type="button" class="btn btn-primary btn-sm" data-action="create-belief" data-context-id="${escHtml(selected.context_id)}" ${be.saving ? 'disabled' : ''}>${escHtml(t('contexts.beliefsEngine.create'))}</button>
            </form>
          </div>
          <div style="margin-top:12px;overflow:auto;">
            ${fil.length ? `<table style="width:100%;border-collapse:collapse;"><thead><tr style="text-align:left;font-size:11px;color:var(--text-muted);">
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colState'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colType'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colAgent'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colText'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colSup'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colDis'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colProv'))}</th>
              <th style="padding:6px 8px;">${escHtml(t('contexts.beliefsEngine.colActions'))}</th>
            </tr></thead><tbody>${rows}</tbody></table>` : (!be.loading ? renderEmptyState({ icon: '🧠', text: t('contexts.beliefsEngine.empty') }) : '')}
          </div>
        </div></details>`;
        })() : ''}

        ${isExpert && canShowExperimental ? (() => {
          const br = ui.beliefsRuntime || {
            loading: false,
            error: '',
            data: null,
            selectedBeliefId: '',
            timelineLoading: false,
            timeline: [],
            relationsLoading: false,
            relations: [],
          };
          const data = br.data && typeof br.data === 'object' ? br.data : null;
          const beliefs = Array.isArray(data?.beliefs) ? data.beliefs : [];
          const counts = data?.counts && typeof data.counts === 'object' ? data.counts : {};
          const selectedId = String(br.selectedBeliefId || '');
          const selectedBelief = beliefs.find((b) => String(b.id) === selectedId) || beliefs[0] || null;
          const tl = Array.isArray(br.timeline) ? br.timeline : [];
          const rels = Array.isArray(br.relations) ? br.relations : [];
          const rtOpen = !!br.error;
          return `
        <details class="sc-expert-details" style="margin-top:12px;" data-ui="expert-only" data-panel="beliefs-runtime-wrap"${rtOpen ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);padding:4px 2px 8px;">${escHtml(t('contexts.expertLayers.beliefsRuntime'))}</summary>
        <div class="card" style="margin-top:0;padding:12px 14px;" data-ui="expert-only" data-panel="beliefs-runtime">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">Beliefs Runtime</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">Graph déterministe, contradictions, timeline, confiance et provenance.</div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-action="load-beliefs-runtime" data-context-id="${escHtml(selected.context_id)}">↺ Charger runtime</button>
          </div>
          ${br.loading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">Chargement runtime beliefs…</div>` : ''}
          ${br.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(br.error)}</div>` : ''}
          ${data ? `
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
              ${Object.entries(counts).map(([k, v]) => renderBadge({ text: `${escHtml(k)}: ${Number(v) || 0}`, variant: k === 'contested' || k === 'unstable' ? 'warning' : (k === 'invalidated' ? 'danger' : 'muted') })).join(' ')}
            </div>
            <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">Belief graph (liste)</div>
                <div style="max-height:180px;overflow:auto;border:1px solid var(--border-subtle);border-radius:8px;padding:8px;">
                  ${beliefs.length ? beliefs.map((b) => {
                    const active = String(b.id) === String(selectedBelief?.id || '');
                    const badges = Array.isArray(b.badges) ? b.badges : [];
                    return `<div style="padding:6px;border-radius:6px;margin-bottom:6px;${active ? 'background:rgba(59,130,246,0.12);' : 'background:var(--bg-secondary);'}">
                      <button type="button" class="btn btn-secondary btn-sm" style="margin-bottom:4px;" data-action="select-runtime-belief" data-context-id="${escHtml(selected.context_id)}" data-belief-id="${escHtml(String(b.id))}">
                        ${escHtml(String(b.agent_id || 'group'))}
                      </button>
                      <div style="font-size:12px;color:var(--text-primary);line-height:1.4;">${escHtml(String(b.belief_text || '').slice(0, 180))}</div>
                      <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
                        ${renderBadge({ text: escHtml(String(b.contestation_state || 'weak')), variant: ['contested', 'unstable'].includes(String(b.contestation_state)) ? 'warning' : (String(b.contestation_state) === 'invalidated' ? 'danger' : 'muted') })}
                        ${renderBadge({ text: `conf ${(Number(b.confidence) || 0).toFixed(2)}`, variant: 'info' })}
                        ${renderBadge({ text: `drift ${(Number(b.drift_score) || 0).toFixed(2)}`, variant: 'muted' })}
                        ${renderBadge({ text: `cons ${(Number(b.consensus_score) || 0).toFixed(2)}`, variant: 'success' })}
                        ${badges.filter(Boolean).map((bg) => renderBadge({ text: escHtml(String(bg)), variant: bg === 'invalidated' ? 'danger' : 'muted' })).join(' ')}
                      </div>
                    </div>`;
                  }).join('') : renderEmptyState({ icon: '🧠', text: 'Aucune belief runtime.' })}
                </div>
              </div>
              <div>
                <div style="font-weight:700;font-size:13px;margin-bottom:6px;">Timeline & relations</div>
                ${selectedBelief ? `<div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px;"><strong>${escHtml(String(selectedBelief.id).slice(0, 8))}…</strong> ${escHtml(String(selectedBelief.belief_text || '').slice(0, 120))}</div>` : ''}
                <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:8px;max-height:180px;overflow:auto;background:var(--bg-secondary);">
                  <div style="font-size:12px;font-weight:700;margin-bottom:4px;">Timeline</div>
                  ${br.timelineLoading ? `<div style="font-size:12px;color:var(--text-muted);">Chargement timeline…</div>` : (tl.length ? `<ul style="margin:0 0 0 16px;padding:0;font-size:12px;">${tl.slice(-20).map((e) => `<li>${escHtml(String(e.occurred_at || ''))} · ${escHtml(String(e.event_type || ''))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);">Aucun événement.</div>`)}
                  <div style="font-size:12px;font-weight:700;margin:8px 0 4px;">Relations</div>
                  ${br.relationsLoading ? `<div style="font-size:12px;color:var(--text-muted);">Chargement relations…</div>` : (rels.length ? `<ul style="margin:0 0 0 16px;padding:0;font-size:12px;">${rels.slice(0, 25).map((r) => `<li>${escHtml(String(r.relation_type || ''))} → ${escHtml(String(r.to_entity_type || ''))}:${escHtml(String(r.to_entity_id || ''))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);">Aucune relation.</div>`)}
                </div>
              </div>
            </div>
          ` : ''}
        </div></details>`;
        })() : ''}

        ${isExpert && canShowExperimental ? (() => {
          const mc = ui.memoryCompiler || {
            loading: false, error: '', compileSaving: false, items: [], filterType: '', filterStatus: '',
            selectedCompilationId: null, detailLoading: false, detail: null,
          };
          const compTypes = ['working', 'strategic', 'social', 'risk', 'belief', 'postmortem', 'longitudinal'];
          const stati = ['active', 'archived', 'superseded', 'deprecated'];
          const items = Array.isArray(mc.items) ? mc.items : [];
          const detail = mc.detail && typeof mc.detail === 'object' ? mc.detail : null;
          const meta = detail?.compilation_metadata && typeof detail.compilation_metadata === 'object'
            ? detail.compilation_metadata
            : {};
          const shifts = Array.isArray(meta.key_shifts) ? meta.key_shifts : [];
          const tens = Array.isArray(meta.unresolved_tensions) ? meta.unresolved_tensions : [];
          const src = detail?.sources && typeof detail.sources === 'object' ? detail.sources : {};
          const listRows = items.length
            ? items.map((c) => {
              const activeSel = String(mc.selectedCompilationId || '') === String(c.id || '') ? 'font-weight:800;background:rgba(59,130,246,0.08);' : '';
              return `<tr style="font-size:12px;color:var(--text-secondary);cursor:pointer;${activeSel}" data-action="load-memory-compilation-detail" data-context-id="${escHtml(selected.context_id)}" data-compilation-id="${escHtml(String(c.id || ''))}">
                <td style="padding:6px 8px;">${renderBadge({ text: escHtml(String(c.compilation_type || '')), variant: 'info' })}</td>
                <td style="padding:6px 8px;">${escHtml(String(Number(c.stability_score ?? 0).toFixed(2)))}</td>
                <td style="padding:6px 8px;">${escHtml(String(Number(c.confidence ?? 0).toFixed(2)))}</td>
                <td style="padding:6px 8px;">${renderBadge({ text: escHtml(String(c.status || '')), variant: c.status === 'active' ? 'success' : 'muted' })}</td>
                <td style="padding:6px 8px;font-size:11px;">${escHtml(formatDate(c.created_at))}</td>
                <td style="padding:6px 8px;white-space:nowrap;">
                  <button type="button" class="btn btn-secondary btn-sm" data-action="archive-compilation" data-context-id="${escHtml(selected.context_id)}" data-compilation-id="${escHtml(String(c.id || ''))}" ${String(c.status) === 'archived' ? 'disabled' : ''}>${escHtml(t('contexts.memoryCompiler.archive'))}</button>
                  <button type="button" class="btn btn-secondary btn-sm" style="margin-left:4px;" data-action="supersede-compilation" data-context-id="${escHtml(selected.context_id)}" data-compilation-id="${escHtml(String(c.id || ''))}" ${String(c.status) !== 'active' ? 'disabled' : ''}>${escHtml(t('contexts.memoryCompiler.supersede'))}</button>
                </td>
              </tr>`;
            }).join('')
            : '';
          const mdBlock = detail && !mc.detailLoading
            ? `<pre style="white-space:pre-wrap;font-size:12px;line-height:1.45;padding:10px;background:var(--bg-secondary);border-radius:8px;max-height:320px;overflow:auto;margin-top:8px;">${escHtml(String(detail.compiled_memory_markdown || ''))}</pre>`
            : (mc.detailLoading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.detailLoading'))}</div>` : `<div style="margin-top:8px;">${renderEmptyState({ icon: '📎', text: t('contexts.memoryCompiler.noDetail') })}</div>`);
          const driftBlock = shifts.length || tens.length
            ? `${renderAlert({
              variant: 'warning',
              bodyHtml: `<div style="font-weight:800;margin-bottom:6px;">${escHtml(t('contexts.memoryCompiler.driftTitle'))}</div>
                ${shifts.length ? `<div style="font-size:12px;margin-bottom:8px;"><strong>${escHtml(t('contexts.memoryCompiler.shifts'))}</strong><ul style="margin:4px 0 0 18px;">${shifts.slice(0, 10).map((s) => `<li>${escHtml(String(s))}</li>`).join('')}</ul></div>` : ''}
                ${tens.length ? `<div style="font-size:12px;"><strong>${escHtml(t('contexts.memoryCompiler.unresolved'))}</strong><ul style="margin:4px 0 0 18px;">${tens.slice(0, 10).map((s) => `<li>${escHtml(String(s))}</li>`).join('')}</ul></div>` : ''}`,
            })}`
            : '';
          const srcLines = detail
            ? Object.entries(src).map(([k, v]) => `<li><code>${escHtml(k)}</code> : <strong>${escHtml(String(v))}</strong></li>`).join('')
            : '';
          const mcOpen = !!(mc.error || shifts.length || tens.length);
          return `
        <details class="sc-expert-details" style="margin-top:12px;" data-ui="expert-only" data-panel="memory-compiler-wrap"${mcOpen ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);padding:4px 2px 8px;">${escHtml(t('contexts.expertLayers.memoryCompiler'))}</summary>
        <div class="card" style="margin-top:0;padding:12px 14px;" data-ui="expert-only" data-panel="memory-compiler">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.memoryCompiler.title'))}</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">${escHtml(t('contexts.memoryCompiler.subtitle'))}</div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-action="load-memory-compilations" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.memoryCompiler.loadList'))}</button>
          </div>
          ${mc.loading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.loading'))}</div>` : ''}
          ${mc.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(mc.error)}</div>` : ''}
          <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.filterType'))}</label><br/>
              <select class="input" style="min-width:140px;" data-action="filter-memory-compilations-type">
                <option value="">${escHtml(t('contexts.beliefsEngine.all'))}</option>
                ${compTypes.map((tp) => `<option value="${tp}" ${mc.filterType === tp ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.filterStatus'))}</label><br/>
              <select class="input" style="min-width:120px;" data-action="filter-memory-compilations-status">
                <option value="">${escHtml(t('contexts.beliefsEngine.all'))}</option>
                ${stati.map((tp) => `<option value="${tp}" ${mc.filterStatus === tp ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.compileType'))}</label><br/>
              <select class="input" data-mc-compile-type="${escHtml(selected.context_id)}" style="min-width:160px;">
                ${compTypes.map((tp) => `<option value="${tp}" ${tp === 'strategic' ? 'selected' : ''}>${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-action="compile-memory" data-context-id="${escHtml(selected.context_id)}" ${mc.compileSaving ? 'disabled' : ''}>${escHtml(t('contexts.memoryCompiler.compile'))}</button>
          </div>
          <div style="margin-top:14px;font-weight:700;font-size:13px;">${escHtml(t('contexts.memoryCompiler.listTitle'))}</div>
          ${items.length ? `<div style="margin-top:8px;overflow:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="text-align:left;font-size:11px;color:var(--text-muted);">
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colType'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colStability'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colConfidence'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colStatus'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colDate'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.memoryCompiler.colActions'))}</th>
          </tr></thead><tbody>${listRows}</tbody></table></div>` : renderEmptyState({ icon: '🧩', text: t('contexts.memoryCompiler.emptyList') })}
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;">${escHtml(t('contexts.memoryCompiler.compiledTitle'))}</div>
            ${detail ? `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${escHtml(String(detail.title || ''))}</div>` : ''}
            ${mdBlock}
          </div>
          ${driftBlock}
          <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;">${escHtml(t('contexts.memoryCompiler.sourcesTitle'))}</div>
            ${srcLines ? `<ul style="margin:8px 0 0 18px;font-size:12px;color:var(--text-secondary);">${srcLines}</ul>` : `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.memoryCompiler.sourcesHint'))}</div>`}
          </div>
        </div></details>`;
        })() : ''}

        ${isExpert && canShowExperimental ? (() => {
          const cs = ui.contextSnapshots || {
            loading: false, error: '', createSaving: false, items: [], selectedSnapshotId: null,
            detailLoading: false, detail: null, compareLeft: '', compareRight: '', compareLoading: false,
            compareResult: null, longLoading: false, longError: '', longViewMarkdown: '',
          };
          const snapTypes = ['manual', 'scheduled', 'milestone', 'pre-rerun', 'postmortem', 'before-major-decision', 'longitudinal-anchor'];
          const items = Array.isArray(cs.items) ? cs.items : [];
          const detail = cs.detail && typeof cs.detail === 'object' ? cs.detail : null;
          const mkOpts = (sel) => items.map((s) => {
            const id = String(s.id || '');
            const selAttr = String(sel || '') === id ? ' selected' : '';
            return `<option value="${escHtml(id)}"${selAttr}>${escHtml(String(s.created_at || '').slice(0, 16))} · ${escHtml(String(s.snapshot_type || ''))}</option>`;
          }).join('');
          const optLeft = mkOpts(cs.compareLeft);
          const optRight = mkOpts(cs.compareRight);
          const listRows = items.length
            ? items.map((s) => {
              const sel = String(cs.selectedSnapshotId || '') === String(s.id || '') ? 'font-weight:800;background:rgba(59,130,246,0.07);' : '';
              const tags = Array.isArray(s.metadata?.tags) && s.metadata.tags.length
                ? s.metadata.tags.map((x) => renderBadge({ text: escHtml(String(x)), variant: 'muted' })).join(' ')
                : '';
              return `<tr style="font-size:12px;color:var(--text-secondary);cursor:pointer;${sel}" data-action="load-context-snapshot-detail" data-context-id="${escHtml(selected.context_id)}" data-snapshot-id="${escHtml(String(s.id || ''))}">
                <td style="padding:6px 8px;">${renderBadge({ text: escHtml(String(s.snapshot_type || '')), variant: 'info' })}</td>
                <td style="padding:6px 8px;">${escHtml(formatDate(s.created_at))}</td>
                <td style="padding:6px 8px;">${escHtml(String(s.title || '').slice(0, 80))}</td>
                <td style="padding:6px 8px;">${tags || '—'}</td>
              </tr>`;
            }).join('')
            : '';
          const mdSnap = detail && !cs.detailLoading
            ? `<pre style="white-space:pre-wrap;font-size:12px;line-height:1.45;padding:10px;background:var(--bg-secondary);border-radius:8px;max-height:280px;overflow:auto;margin-top:8px;">${escHtml(String(detail.snapshot_markdown || ''))}</pre>`
            : (cs.detailLoading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.detailLoading'))}</div>` : renderEmptyState({ icon: '📸', text: t('contexts.contextSnapshots.noDetail') }));
          const diffMd = cs.compareResult?.markdown
            ? `<pre style="white-space:pre-wrap;font-size:12px;line-height:1.45;padding:10px;background:var(--bg-secondary);border-radius:8px;max-height:240px;overflow:auto;margin-top:8px;">${escHtml(String(cs.compareResult.markdown))}</pre>`
            : (cs.compareLoading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.compareLoading'))}</div>` : '');
          const longMd = cs.longViewMarkdown
            ? `<pre style="white-space:pre-wrap;font-size:12px;line-height:1.45;padding:10px;background:var(--bg-secondary);border-radius:8px;max-height:260px;overflow:auto;margin-top:8px;">${escHtml(String(cs.longViewMarkdown))}</pre>`
            : (cs.longLoading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.longLoading'))}</div>` : '');
          const snapOpen = !!(cs.error || cs.longError);
          return `
        <details class="sc-expert-details" style="margin-top:12px;" data-ui="expert-only" data-panel="context-snapshots-wrap"${snapOpen ? ' open' : ''}>
          <summary style="cursor:pointer;font-weight:800;font-size:14px;color:var(--text-primary);padding:4px 2px 8px;">${escHtml(t('contexts.expertLayers.snapshots'))}</summary>
        <div class="card" style="margin-top:0;padding:12px 14px;" data-ui="expert-only" data-panel="context-snapshots">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;justify-content:space-between;">
            <div>
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.contextSnapshots.title'))}</div>
              <div style="font-size:11px;color:var(--text-muted);line-height:1.45;margin-top:4px;">${escHtml(t('contexts.contextSnapshots.subtitle'))}</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-context-snapshots" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.contextSnapshots.loadList'))}</button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-context-longitudinal-view" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.contextSnapshots.longitudinal'))}</button>
            </div>
          </div>
          ${cs.loading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.loading'))}</div>` : ''}
          ${cs.error ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(cs.error)}</div>` : ''}
          ${cs.longError ? `<div class="error-banner" style="margin-top:8px;">⚠️ ${escHtml(cs.longError)}</div>` : ''}
          <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.type'))}</label><br/>
              <select class="input" data-cs-snapshot-type="${escHtml(selected.context_id)}" style="min-width:180px;">
                ${snapTypes.map((tp) => `<option value="${escHtml(tp)}">${escHtml(tp)}</option>`).join('')}
              </select>
            </div>
            <div style="flex:1;min-width:200px;">
              <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.titleOptional'))}</label><br/>
              <input class="input" data-cs-snapshot-title="${escHtml(selected.context_id)}" placeholder="${escHtml(t('contexts.contextSnapshots.titlePh'))}" />
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-action="create-context-snapshot" data-context-id="${escHtml(selected.context_id)}" ${cs.createSaving ? 'disabled' : ''}>${escHtml(t('contexts.contextSnapshots.create'))}</button>
          </div>
          <div style="margin-top:14px;font-weight:700;font-size:13px;">${escHtml(t('contexts.contextSnapshots.timelineTitle'))}</div>
          ${items.length ? `<div style="margin-top:8px;overflow:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="text-align:left;font-size:11px;color:var(--text-muted);">
            <th style="padding:6px 8px;">${escHtml(t('contexts.contextSnapshots.colType'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.contextSnapshots.colDate'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.contextSnapshots.colTitle'))}</th>
            <th style="padding:6px 8px;">${escHtml(t('contexts.contextSnapshots.colTags'))}</th>
          </tr></thead><tbody>${listRows}</tbody></table></div>` : renderEmptyState({ icon: '📷', text: t('contexts.contextSnapshots.emptyList') })}
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;">${escHtml(t('contexts.contextSnapshots.viewerTitle'))}</div>
            ${mdSnap}
          </div>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;">${escHtml(t('contexts.contextSnapshots.diffTitle'))}</div>
            <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
              <div>
                <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.compareA'))}</label><br/>
                <select class="input" data-action="set-context-snapshot-compare-left" style="min-width:220px;">
                  <option value="">${escHtml(t('contexts.contextSnapshots.pick'))}</option>
                  ${optLeft}
                </select>
              </div>
              <div>
                <label style="font-size:11px;color:var(--text-muted);">${escHtml(t('contexts.contextSnapshots.compareB'))}</label><br/>
                <select class="input" data-action="set-context-snapshot-compare-right" style="min-width:220px;">
                  <option value="">${escHtml(t('contexts.contextSnapshots.pick'))}</option>
                  ${optRight}
                </select>
              </div>
              <button type="button" class="btn btn-secondary btn-sm" data-action="compare-context-snapshots" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.contextSnapshots.compareBtn'))}</button>
            </div>
            ${diffMd}
          </div>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-subtle);">
            <div style="font-weight:700;font-size:13px;">${escHtml(t('contexts.contextSnapshots.longitudinalTitle'))}</div>
            ${longMd}
          </div>
        </div></details>`;
        })() : ''}

        ${(!isExpert || canShowExperimental) ? (() => {
          const wt = ui.workspaceTimeline || { loading: false, error: '', data: null };
          const typeLabel = (typ) => {
            const k = `contexts.workspaceTimeline.type.${String(typ || '').trim()}`;
            const tr = t(k);
            return tr === k ? String(typ || '') : tr;
          };
          const timelineInner = (() => {
            if (wt.loading) {
              return `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.workspaceTimeline.loading'))}</div>`;
            }
            if (wt.error) {
              return `<div class="error-banner" style="margin-top:10px;">⚠️ ${escHtml(wt.error)}</div>`;
            }
            if (!wt.data) {
              return `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.workspaceTimeline.hint'))}</div>`;
            }
            const items2 = Array.isArray(wt.data.items) ? wt.data.items : [];
            const legacyN = typeof wt.data.legacy_count === 'number' ? wt.data.legacy_count : 0;
            const legacyBlock = legacyN > 0
              ? `<div style="margin-bottom:8px;">${renderAlert({
                variant: 'info',
                text: `${t('contexts.workspaceTimeline.legacyCount')}: ${legacyN}`,
              })}</div>`
              : '';
            const warnLines = (Array.isArray(wt.data.warnings) ? wt.data.warnings : [])
              .filter(Boolean)
              .map((w) => `<div style="margin-top:4px;">${escHtml(String(w))}</div>`)
              .join('');
            const warnBlock = warnLines
              ? `<div style="margin-bottom:10px;">${renderAlert({ variant: 'warning', bodyHtml: warnLines })}</div>`
              : '';
            if (!items2.length) {
              return `${legacyBlock}${warnBlock}${renderEmptyState({ icon: '📭', text: t('contexts.workspaceTimeline.empty') })}`;
            }
            const rows = items2.slice(0, 150).map((it) => {
              const when = escHtml(String(it.created_at || '—'));
              const tit = escHtml(String(it.title || it.type || ''));
              const sum = escHtml(String(it.summary || '').slice(0, 160));
              const typRaw = String(it.type || '');
              const typDisp = escHtml(typeLabel(typRaw));
              const badge = renderBadge({ text: typDisp, variant: timelineBadgeVariant(typRaw) });
              return `<li style="margin-bottom:10px;font-size:12px;color:var(--text-secondary);line-height:1.4;">
                ${badge} <strong style="color:var(--text-primary);">${when}</strong> — ${tit}
                ${sum ? `<div style="margin-top:2px;color:var(--text-muted);">${sum}</div>` : ''}
              </li>`;
            }).join('');
            return `${legacyBlock}${warnBlock}<ol style="margin:12px 0 0 18px;padding:0;list-style:decimal;">${rows}</ol>`;
          })();
          return `
        <div class="card" style="margin-top:12px;padding:12px 14px;" data-panel="strategic-context-timeline">
          <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <div style="font-weight:800;font-size:14px;">${escHtml(t('contexts.workspaceTimeline.title'))}</div>
              <button type="button" class="btn btn-secondary btn-sm" data-action="load-workspace-timeline" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.workspaceTimeline.load'))}</button>
              ${isExpert ? `<button type="button" class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="load-workspace-timeline-legacy" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.workspaceTimeline.loadLegacy'))}</button>` : ''}
            </div>
            <div style="font-size:11px;color:var(--text-muted);line-height:1.45;">${escHtml(t('contexts.workspaceTimeline.subtitle'))}</div>
          </div>
          ${timelineInner}
        </div>`;
        })() : ''}

        ${isExpert && isDeleteConfirm ? `
          <div class="card" data-ui="expert-only" style="margin-top:12px;padding:12px 14px;border-color:rgba(239,68,68,0.45);background:rgba(239,68,68,0.06);">
            <div style="font-weight:800;color:var(--text-primary);">${escHtml(t('contexts.deleteConfirm.title'))}</div>
            <div style="margin-top:6px;font-size:12px;color:var(--text-secondary);line-height:1.45;">
              ${escHtml(t('contexts.deleteConfirm.body'))}
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
              <button class="btn btn-danger btn-sm" data-action="confirm-delete-strategic-context" data-context-id="${escHtml(selected.context_id)}">${escHtml(t('contexts.deleteConfirm.confirm'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="cancel-delete-strategic-context">${escHtml(t('contexts.form.cancel'))}</button>
            </div>
          </div>
        ` : ''}
        <details style="margin-top:12px;" data-ui="expert-only">
          <summary style="cursor:pointer;color:var(--text-muted);">${escHtml(t('contexts.links'))}</summary>
          <div style="margin-top:10px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
            <div><strong>${escHtml(t('contexts.linkedMemories'))}</strong>: <code>${escHtml(JSON.stringify(memIds))}</code></div>
            <div style="margin-top:6px;"><strong>${escHtml(t('contexts.linkedSessions'))}</strong>: <code>${escHtml(JSON.stringify(sesIds))}</code></div>
          </div>
        </details>
      </div>
    `;
  })();

  const noWorkspaceBanner = !activeWorkspaceId ? `
    <div class="card" style="padding:12px 14px;margin:0 0 12px;border-color:rgba(234,179,8,0.45);background:rgba(234,179,8,0.07);">
      <div style="font-weight:800;">${escHtml(t('contexts.noWorkspaceActiveTitle'))}</div>
      <div style="margin-top:6px;font-size:12px;color:var(--text-secondary);line-height:1.45;">${escHtml(t('contexts.noWorkspaceActiveBody'))}</div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary btn-sm" data-action="open-create-strategic-context">＋ ${escHtml(t('contexts.create'))}</button>
        <button type="button" class="btn btn-secondary btn-sm" data-action="load-strategic-contexts">↺ ${escHtml(t('contexts.refresh'))}</button>
      </div>
    </div>
  ` : '';

  const compareBlock = (() => {
    if (!ui.compareOpen || !ui.compareLeftId || !ui.compareRightId) return '';
    const left = items.find((x) => String(x.context_id) === String(ui.compareLeftId));
    const right = items.find((x) => String(x.context_id) === String(ui.compareRightId));
    if (!left || !right) return '';
    const lcs = left.current_state || {};
    const rcs = right.current_state || {};
    const mini = (c, cs) => `
      <div class="card" style="padding:12px;background:var(--bg-secondary);">
        <div style="font-weight:700;font-size:14px;">${escHtml(String(c.title || ''))}</div>
        <div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.express.currentDecision'))}: ${escHtml(readableDecisionStatusForContext(t, cs.current_decision_status))}</div>
        <div style="margin-top:4px;font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.confidence'))}: ${escHtml(readableConfidenceShortForContext(t, cs.current_confidence))}</div>
        <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);">${escHtml(truncateContextSummary(cs.decision_summary || '', 180))}</div>
      </div>`;
    return `
    <div class="card" style="grid-column:1/-1;margin-top:4px;padding:14px 16px;border-style:dashed;border-width:1px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
        <div>
          <div style="font-weight:800;">${escHtml(t('contexts.compareTitle'))}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${escHtml(t('contexts.compareHint'))}</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-action="close-strategic-context-compare">${escHtml(t('contexts.compareClose'))}</button>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
        ${mini(left, lcs)}
        ${mini(right, rcs)}
      </div>
    </div>`;
  })();

  return top + filterRow + deepComparePanel + noWorkspaceBanner + renderForm() + renderLinkForm() + `
    <div class="sc-layout-grid">
      <div class="sc-layout-list-column">${list}</div>
      <div class="sc-layout-detail-column">${detail || ''}</div>
      ${compareBlock}
    </div>
  `;
}

function registerStrategicContextsFeature() {
  window.DecisionArena.views['strategic-contexts'] = renderStrategicContexts;
}

export { registerStrategicContextsFeature };


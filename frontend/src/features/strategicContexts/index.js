/**
 * Strategic Contexts feature – lightweight organization layer.
 */

import {
  renderPerspectiveSnapshot,
  renderPerspectiveSegmentedControl,
} from '../../utils/perspectiveSnapshotRenderer.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, formatDate, t };
}

function badgeForStatus(s) {
  switch (s) {
    case 'active': return 'badge-success';
    case 'paused': return 'badge-warning';
    case 'completed': return 'badge-muted';
    case 'abandoned': return 'badge-danger';
    default: return 'badge-muted';
  }
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

function renderStrategicContexts() {
  const { state, escHtml, formatDate, t } = getCtx();
  const pkg = state.strategicContexts || { loading: false, error: null, items: [] };
  const ui = state.strategicContextUi || { statusFilter: 'active', selectedContextId: null };
  const items = Array.isArray(pkg.items) ? pkg.items : [];
  const selectedId = ui.selectedContextId || (items[0]?.context_id ?? null);
  const selected = items.find((c) => c.context_id === selectedId) || null;
  const isExpert = state.uiMode === 'expert';
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

  const list = items.map((c) => {
    const cs = c.current_state || {};
    const risks = Array.isArray(cs.active_risks) ? cs.active_risks : [];
    const checked = isExpert && bulkSet.has(c.context_id);
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
          <span class="badge ${badgeForStatus(c.status)}">${escHtml(String(c.status || 'active'))}</span>
          <div style="font-weight:700;">${escHtml(String(c.title || ''))}</div>
          <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(c.updated_at))}</span>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          ${cs.current_decision_status ? `<span class="badge badge-info">${escHtml(cs.current_decision_status)}</span>` : `<span class="badge badge-muted">${escHtml(t('contexts.noDecision'))}</span>`}
          ${cs.current_confidence ? `<span class="badge badge-muted">${escHtml(t('contexts.confidence'))}: ${escHtml(cs.current_confidence)}</span>` : ''}
          <span class="badge ${risks.length ? 'badge-warning' : 'badge-muted'}">${escHtml(t('contexts.risks'))}: ${risks.length}</span>
        </div>
        ${cs.latest_next_step ? `<div style="margin-top:8px;font-size:12px;color:var(--text-secondary);"><strong>${escHtml(t('contexts.next'))}:</strong> ${escHtml(cs.latest_next_step)}</div>` : ''}
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

    const shortMid = cs.latest_memory_id ? String(cs.latest_memory_id).slice(0, 10) + (String(cs.latest_memory_id).length > 10 ? '…' : '') : '';
    const lastWhen = cs.latest_memory_at ? formatDate(cs.latest_memory_at) : '—';

    const decLabel = readableDecisionStatusForContext(t, cs.current_decision_status);
    const confLabelShort = readableConfidenceShortForContext(t, cs.current_confidence);

    const summarySnippet = truncateContextSummary(cs.decision_summary, 220);
    const riskPreview = risks.slice(0, 2)
      .map((r) => `<div style="font-size:11px;color:var(--text-secondary);line-height:1.35;">• ${escHtml(String(r))}</div>`)
      .join('');

    const expressCard = `
      <div class="card sc-express-summary" style="padding:14px 16px;margin-bottom:14px;background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.22);">
        <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
          ${escHtml(t('contexts.express.whereWeAre'))}
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
          <button type="button" class="btn btn-secondary btn-sm" data-action="toggle-context-memory-md" data-context-id="${escHtml(selected.context_id)}">
            ${escHtml(t('snapshots.viewMemoryMd'))}
          </button>
          ${ui.memoryMdLoading ? `<span class="badge badge-muted">${escHtml(t('snapshots.loading'))}</span>` : ''}
          ${ui.memoryMdError ? `<span class="badge badge-danger">${escHtml(t('snapshots.error'))}</span>` : ''}
        </div>
        <div style="display:grid;gap:10px;">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <span style="font-size:12px;color:var(--text-muted);font-weight:700;">${escHtml(t('contexts.express.currentDecision'))}</span>
            ${cs.current_decision_status ? `<span class="badge badge-info">${escHtml(decLabel)}</span>` : `<span class="badge badge-muted">${escHtml(t('contexts.noDecision'))}</span>`}
            ${cs.current_confidence ? `<span class="badge ${badgeForConfidenceUx(cs.current_confidence)}">${escHtml(confLabelShort)}</span>` : ''}
          </div>
          ${summarySnippet ? `<div style="font-size:13px;line-height:1.5;color:var(--text-primary);">${escHtml(summarySnippet)}</div>` : `<div style="font-size:12px;color:var(--text-muted);">${escHtml(t('contexts.express.noSummaryYet'))}</div>`}
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${escHtml(t('contexts.next'))}</div>
              <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">${cs.latest_next_step ? escHtml(truncateContextSummary(cs.latest_next_step, 140)) : '—'}</div>
            </div>
            <div>
              <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${escHtml(t('contexts.express.openRisks'))}</div>
              <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;"><strong>${risks.length}</strong>${riskPreview ? ` ${riskPreview}` : ''}</div>
            </div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);">
            ${escHtml(t('contexts.express.lastMemory'))}: ${lastWhen}${isExpert && shortMid ? ` · <code>${escHtml(shortMid)}</code>` : ''}
          </div>
          ${isExpert && (cs.contract_version || cs.taxonomy_version) ? `
            <div data-ui="expert-only" style="font-size:11px;color:var(--text-secondary);line-height:1.45;">
              <div><strong>contract_version</strong> ${escHtml(String(cs.contract_version || '—'))}</div>
              <div><strong>taxonomy_version</strong> ${escHtml(String(cs.taxonomy_version || '—'))}</div>
            </div>
          ` : ''}
          ${isExpert && cs.latest_memory_id ? `
            <div data-ui="expert-only">
              <button type="button" class="btn btn-secondary btn-sm" data-action="open-decision-memory-for-context" data-context-id="${escHtml(selected.context_id)}" data-memory-id="${escHtml(String(cs.latest_memory_id))}">
                → ${escHtml(t('contexts.express.viewSourceMemory'))}
              </button>
            </div>
          ` : ''}
        </div>
      </div>
    `;

    return `
      ${expressCard}
      ${ui.memoryMdOpen ? (() => {
        const activePerspective = String(ui.memoryMdPerspective || 'default');
        const isExpertUi = (state.uiComplexity === 'expert');
        const segmented = renderPerspectiveSegmentedControl({
          action: 'set-context-memory-perspective',
          selected: activePerspective,
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
              <span class="badge ${badgeForStatus(selected.status)}">${escHtml(selected.status)}</span>
            </div>
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

        ${ui.archiveError ? `<div class="error-banner" style="margin-top:12px;">⚠️ ${escHtml(ui.archiveError)}</div>` : ''}
        ${ui.deleteError ? `<div class="error-banner" style="margin-top:12px;">⚠️ ${escHtml(ui.deleteError)}</div>` : ''}

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

  return top + filterRow + renderForm() + renderLinkForm() + `
    <div style="display:grid;grid-template-columns:360px 1fr;gap:14px;align-items:start;">
      <div>${list}</div>
      <div>${detail || ''}</div>
    </div>
  `;
}

function registerStrategicContextsFeature() {
  window.DecisionArena.views['strategic-contexts'] = renderStrategicContexts;
}

export { registerStrategicContextsFeature };


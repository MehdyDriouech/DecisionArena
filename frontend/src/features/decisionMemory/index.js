/**
 * Decision Memory feature – view registration.
 */

import { deriveChains, deriveEventsForChain, summarizeChainChange, groupTimeline, toDateKey } from './timeline.js';
import { getPlaybookById } from '../../core/playbooks.js';
import { renderPendingConfirmation } from '../../ui/components.js';

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml, formatDate } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, formatDate, t };
}

function badgeForStatus(status) {
  switch (status) {
    case 'proceed': return 'badge-success';
    case 'proceed_with_constraints': return 'badge-warning';
    case 'validate_first': return 'badge-warning';
    case 'pivot': return 'badge-warning';
    case 'kill': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function badgeForConfidence(level) {
  switch (level) {
    case 'strong': return 'badge-success';
    case 'moderate': return 'badge-warning';
    case 'weak': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function badgeForLifecycleState(s) {
  switch (s) {
    case 'active': return 'badge-success';
    case 'superseded': return 'badge-warning';
    case 'stale': return 'badge-warning';
    case 'invalidated': return 'badge-danger';
    case 'archived': return 'badge-muted';
    default: return 'badge-muted';
  }
}

function badgeForStaleness(level) {
  switch (level) {
    case 'fresh': return 'badge-muted';
    case 'aging': return 'badge-warning';
    case 'stale': return 'badge-danger';
    default: return 'badge-muted';
  }
}

function passesFilters(m, filters, links, idxLinksByMemory) {
  const f = filters || {};
  const playbook = String(m.playbook_id || '');
  const status = String(m.decision_status || '');
  const conf = String(m.confidence || '');
  const unresolved = Array.isArray(m.unresolved_risks) ? m.unresolved_risks : [];

  if (f.playbook_id && String(f.playbook_id).trim() && playbook !== String(f.playbook_id).trim()) return false;
  if (f.decision_status && String(f.decision_status).trim() && status !== String(f.decision_status).trim()) return false;
  if (f.confidence && String(f.confidence).trim() && conf !== String(f.confidence).trim()) return false;
  if (String(f.has_unresolved || '').trim() === 'yes' && unresolved.length === 0) return false;
  if (String(f.has_unresolved || '').trim() === 'no' && unresolved.length > 0) return false;

  const lt = String(f.link_type || '').trim();
  if (lt) {
    const rel = idxLinksByMemory.get(String(m.memory_id)) || [];
    if (!rel.some((l) => String(l.link_type || '') === lt)) return false;
  }

  return true;
}

function truncateSummaryLine(text, max) {
  const s = String(text || '').replace(/\s+/g, ' ').trim();
  if (s.length <= max) return s;
  return `${s.slice(0, Math.max(0, max - 1))}…`;
}

function uxReadableDecisionStatus(t, raw) {
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

function uxReadableConfidence(t, raw) {
  const key = String(raw || '').trim().toLowerCase();
  if (key === 'strong') return t('decisionMemory.confidence.strong');
  if (key === 'moderate') return t('decisionMemory.confidence.moderate');
  if (key === 'weak') return t('decisionMemory.confidence.weak');
  const s = String(raw || '').trim();
  return s || '—';
}

function badgeForDecisionChainRoomStatus(status) {
  switch (String(status || '').toLowerCase()) {
    case 'active': return 'badge-success';
    case 'paused': return 'badge-warning';
    case 'completed': return 'badge-muted';
    case 'archived': return 'badge-muted';
    default: return 'badge-muted';
  }
}

/** Restreint la liste de mémoires selon initiative + chaîne (sans dupliquer la timeline). */
function applyExplorerMemoryFilter(memories, state) {
  if (!Array.isArray(memories)) return [];
  const ui = state.decisionMemoryUi || {};
  const cid = ui.navStrategicContextId ?? null;
  if (!cid) return memories.slice();

  const nav = state.decisionMemoryNav || {};
  const ctxList = Array.isArray(nav.contexts) ? nav.contexts : [];
  const ctx = ctxList.find((c) => String(c.context_id) === String(cid));
  let allow = new Set((ctx?.linked_memory_ids || []).map(String));

  const rid = ui.navDecisionChainId ?? null;
  if (rid) {
    const rooms = (nav.roomsByContextId && nav.roomsByContextId[cid]) || [];
    const room = rooms.find((r) => String(r.room_id) === String(rid));
    allow = new Set((room?.linked_memory_ids || []).map(String));
  }

  return memories.filter((m) => allow.has(String(m.memory_id)));
}

function renderMemoryExplorerBar(state, { escHtml, formatDate, t }) {
  const nav = state.decisionMemoryNav || {};
  const ui = state.decisionMemoryUi || {};
  const contexts = Array.isArray(nav.contexts) ? nav.contexts : [];
  const selCtx = ui.navStrategicContextId ?? null;
  const selRoom = ui.navDecisionChainId ?? null;

  const ctxOpts = [
    ['', t('decisionMemory.nav.allInitiatives')],
    ...contexts.map((c) => [String(c.context_id || ''), String(c.title || c.context_id || '')]),
  ].map(([val, label]) => `<option value="${escHtml(val)}" ${String(selCtx || '') === val ? 'selected' : ''}>${escHtml(label)}</option>`).join('');

  const hintLoad = nav.contextsLoading
    ? `<span style="font-size:11px;color:var(--text-muted);">${escHtml(t('decisionMemory.nav.loadingContexts'))}</span>`
    : '';
  const err = nav.contextsError
    ? `<div style="font-size:11px;color:var(--danger,#b91c1c);margin-top:6px;">${escHtml(t('decisionMemory.nav.contextsUnavailable'))}</div>`
    : '';

  let chainRow = '';
  if (selCtx) {
    const rooms = (nav.roomsByContextId && nav.roomsByContextId[selCtx]) || [];
    const roomLoading = nav.roomsLoading
      ? `<span style="font-size:11px;color:var(--text-muted);margin-left:8px;">${escHtml(t('decisionMemory.nav.loadingChains'))}</span>`
      : '';
    const noChainsHint =
      !nav.roomsLoading && rooms.length === 0
        ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);line-height:1.45;">${escHtml(t('decisionMemory.nav.noChainsHint'))}</div>`
        : '';

    const chips = [];
    chips.push(`
      <button type="button" class="btn btn-secondary btn-sm ${!selRoom ? 'btn-primary' : ''}"
        data-action="set-memory-explorer-chain" data-room-id=""
        style="white-space:normal;text-align:left;max-width:100%;">${escHtml(t('decisionMemory.nav.linkedMemoriesOnly'))}</button>
    `);

    rooms.forEach((r) => {
      const rid = String(r.room_id || '');
      if (!rid) return;
      const active = selRoom === rid;
      const st = badgeForDecisionChainRoomStatus(r.status);
      const cs = r.current_state || {};
      const ds = cs.current_decision_status ? escHtml(uxReadableDecisionStatus(t, cs.current_decision_status)) : '—';
      const cf = cs.current_confidence ? escHtml(uxReadableConfidence(t, cs.current_confidence)) : '';
      const nx = cs.latest_next_step ? escHtml(truncateSummaryLine(cs.latest_next_step, 72)) : '';
      chips.push(`
        <button type="button" class="btn btn-secondary btn-sm ${active ? 'btn-primary' : ''}"
          data-action="set-memory-explorer-chain" data-room-id="${escHtml(rid)}"
          style="white-space:normal;text-align:left;display:inline-flex;flex-direction:column;align-items:flex-start;gap:4px;max-width:280px;padding:10px 12px;">
          <span style="font-weight:700;">${escHtml(String(r.title || rid))}</span>
          <span style="display:flex;flex-wrap:wrap;gap:4px;font-size:11px;line-height:1.3;">
            <span class="badge ${st}">${escHtml(String(r.status || 'active'))}</span>
            ${cs.current_decision_status ? `<span class="badge badge-info">${ds}</span>` : `<span class="badge badge-muted">—</span>`}
            ${cf ? `<span class="badge ${badgeForConfidence(cs.current_confidence)}">${cf}</span>` : ''}
          </span>
          ${nx ? `<span style="font-size:11px;color:var(--text-secondary);line-height:1.35;"><strong>${escHtml(t('contexts.next'))}:</strong> ${nx}</span>` : ''}
        </button>
      `);
    });

    chainRow = `
      <div style="margin-top:14px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.nav.decisionChain'))}</span>
          ${roomLoading}
          ${selRoom ? `
            <button type="button" class="btn btn-secondary btn-sm" data-action="toggle-room-memory-md" style="margin-left:auto;">
              ${escHtml(t('snapshots.viewMemoryMd'))}
            </button>
          ` : ''}
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
          ${chips.join('')}
        </div>
        ${noChainsHint}
        ${nav.roomsError ? `<div style="margin-top:6px;font-size:11px;color:var(--danger,#b91c1c);">${escHtml(t('decisionMemory.nav.chainsUnavailable'))}</div>` : ''}
      </div>
    `;
  }

  return `
    <div class="card decision-memory-explorer-card" style="padding:14px 16px;margin:0 0 14px;border:1px solid rgba(99,102,241,0.18);background:rgba(99,102,241,0.04);">
      <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;">
        <div style="flex:1;min-width:240px;">
          <label style="display:block;font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">
            ${escHtml(t('decisionMemory.nav.strategicContext'))}
          </label>
          <select class="input" style="max-width:100%;" data-action="set-memory-explorer-context">${ctxOpts}</select>
          ${hintLoad}
          ${err}
        </div>
      </div>
      ${chainRow}
      ${(ui.roomMemoryMdOpen && selRoom) ? `
        <div class="card" style="margin-top:12px;padding:12px 12px;background:rgba(0,0,0,0.02);">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
            <div style="font-weight:800;">${escHtml(t('snapshots.memoryMdTitle'))}</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button type="button" class="btn btn-secondary btn-sm" data-action="copy-room-memory-md">${escHtml(t('snapshots.copy'))}</button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="close-room-memory-md">${escHtml(t('snapshots.close'))}</button>
            </div>
          </div>
          ${ui.roomMemoryMdLoading ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">${escHtml(t('snapshots.loading'))}</div>` : ''}
          ${ui.roomMemoryMdError ? `<div class="error-banner" style="margin-top:10px;">⚠️ ${escHtml(ui.roomMemoryMdError)}</div>` : ''}
          <pre style="margin:10px 0 0;white-space:pre-wrap;font-size:12px;line-height:1.5;color:var(--text-secondary);">${escHtml(String(ui.roomMemoryMdContent || ''))}</pre>
        </div>
      ` : ''}
    </div>
  `;
}

function renderFiltersBar(state, { escHtml, t }) {
  const ui = state.decisionMemoryUi || {};
  const filters = ui.filters || {};
  const lang = window.i18n?.getLanguage?.() || 'fr';
  const isExpert = state.uiMode === 'expert';
  const selCount = Array.isArray(state.selectedMemoryIds) ? state.selectedMemoryIds.length : 0;
  const select = (key, opts) => `
    <select class="input decision-memory-filter-select"
      data-action="set-decision-memory-filter" data-filter-key="${escHtml(key)}">
      ${opts.map(([val, label]) => `<option value="${escHtml(val)}" ${String(filters[key] || '') === val ? 'selected' : ''}>${escHtml(label)}</option>`).join('')}
    </select>`;

  const optLabel = (ux, runtime) => isExpert && runtime ? `${ux} (${runtime})` : ux;

  const pbIds = ['founder-sprint', 'ceo-challenge', 'stress-test', 'jury', 'confrontation', 'quick-decision'];
  const pbOpts = [
    ['', t('decisionMemory.filters.allPlaybooks')],
    ...pbIds.map((id) => {
      const pb = getPlaybookById(id, lang);
      const name = pb?.name ? pb.name : id;
      return [id, name];
    }),
  ];

  const statusOpts = [
    ['', t('decisionMemory.filters.allStatuses')],
    ['proceed', optLabel(t('decisionMemory.status.proceed'), 'proceed')],
    ['proceed_with_constraints', optLabel(t('decisionMemory.status.proceedWithConstraints'), 'proceed_with_constraints')],
    ['validate_first', optLabel(t('decisionMemory.status.validateFirst'), 'validate_first')],
    ['pivot', optLabel(t('decisionMemory.status.pivot'), 'pivot')],
    ['kill', optLabel(t('decisionMemory.status.kill'), 'kill')],
  ];

  const confidenceOpts = [
    ['', t('decisionMemory.filters.allConfidence')],
    ['strong', optLabel(t('decisionMemory.confidence.strong'), 'strong')],
    ['moderate', optLabel(t('decisionMemory.confidence.moderate'), 'moderate')],
    ['weak', optLabel(t('decisionMemory.confidence.weak'), 'weak')],
  ];

  const linkTypeOpts = [
    ['', t('decisionMemory.filters.allLinkTypes')],
    ['continuation', optLabel(t('decisionMemory.linkType.continuation'), 'continuation')],
    ['pivot', optLabel(t('decisionMemory.linkType.pivot'), 'pivot')],
    ['experiment_followup', optLabel(t('decisionMemory.linkType.experimentFollowup'), 'experiment_followup')],
    ['related', optLabel(t('decisionMemory.linkType.related'), 'related')],
  ];

  return `
    <div class="card decision-memory-filters-card">
      <div class="decision-memory-filters-row">
        <span class="decision-memory-filters-title">${escHtml(t('timeline.filters'))}</span>
        <div class="decision-memory-filters-grid">
          <div class="decision-memory-filter">
            <label class="decision-memory-filter-label">${escHtml(t('decisionMemory.filters.playbook'))}</label>
            ${select('playbook_id', pbOpts)}
          </div>
          <div class="decision-memory-filter">
            <label class="decision-memory-filter-label">${escHtml(t('decisionMemory.filters.status'))}</label>
            ${select('decision_status', statusOpts)}
          </div>
          <div class="decision-memory-filter">
            <label class="decision-memory-filter-label">${escHtml(t('decisionMemory.filters.confidence'))}</label>
            ${select('confidence', confidenceOpts)}
          </div>
          <div class="decision-memory-filter">
            <label class="decision-memory-filter-label">${escHtml(t('decisionMemory.filters.risks'))}</label>
            ${select('has_unresolved', [['', t('timeline.filter.risksAny')], ['yes', t('timeline.filter.risksYes')], ['no', t('timeline.filter.risksNo')]])}
          </div>
          <div class="decision-memory-filter">
            <label class="decision-memory-filter-label">${escHtml(t('decisionMemory.filters.linkType'))}</label>
            ${select('link_type', linkTypeOpts)}
          </div>
        </div>
      </div>
      ${isExpert ? `
        <div data-ui="expert-only" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end;padding:10px 14px 14px;">
          ${selCount ? `<span class="badge badge-muted">${escHtml(t('decisionMemory.selected'))}: ${selCount}</span>` : ''}
          <button class="btn btn-secondary btn-sm" data-action="select-all-visible-memories">${escHtml(t('decisionMemory.selectAllVisible'))}</button>
          <button class="btn btn-secondary btn-sm" data-action="clear-selected-memories" ${selCount ? '' : 'disabled'}>${escHtml(t('decisionMemory.clearSelection'))}</button>
          <button class="btn btn-danger btn-sm" data-action="request-delete-selected-memories" ${selCount ? '' : 'disabled'}>🗑️ ${escHtml(t('decisionMemory.deleteSelected'))}</button>
        </div>
      ` : ''}
    </div>
  `;
}

function renderChainView(chainsPkg, state, { escHtml, formatDate, t }, linksIndex) {
  const ui = state.decisionMemoryUi || {};
  const selectedChainId = ui.selectedChainId || (chainsPkg.chains[0]?.chain_id ?? null);
  const chain = chainsPkg.chains.find((c) => c.chain_id === selectedChainId) || null;
  if (!chain) return `<div class="empty-state"><div class="empty-state-text">${escHtml(t('timeline.noChains'))}</div></div>`;

  const events = deriveEventsForChain(chain, chainsPkg.graph);
  const summary = summarizeChainChange(chain);

  const chainList = chainsPkg.chains.slice(0, 50).map((c) => {
    const active = c.chain_id === selectedChainId;
    const pb = c.playbooks.length === 1 ? c.playbooks[0] : `${c.playbooks[0] || '—'} +${Math.max(0, c.playbooks.length - 1)}`;
    return `
      <div class="card" style="padding:10px 12px;margin-bottom:8px;cursor:pointer;${active ? 'border-color:rgba(99,102,241,0.55);background:rgba(99,102,241,0.06);' : ''}"
        data-action="select-decision-memory-chain" data-chain-id="${escHtml(c.chain_id)}">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <span class="badge badge-muted">chain</span>
          <span class="badge badge-info">${escHtml(pb)}</span>
          <span class="badge ${badgeForStatus(c.latest_status)}">${escHtml(c.latest_status || '—')}</span>
          <span class="badge ${badgeForConfidence(c.latest_confidence)}">${escHtml(c.latest_confidence || '—')}</span>
        </div>
        <div style="margin-top:6px;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(c.first_at))} → ${escHtml(formatDate(c.last_at))}</div>
      </div>
    `;
  }).join('');

  const nodeHtml = chain.items.map((m) => {
    const unresolved = Array.isArray(m.unresolved_risks) ? m.unresolved_risks : [];
    const next = Array.isArray(m.recommended_next_steps) ? m.recommended_next_steps : [];
    const out = (linksIndex.get(String(m.memory_id)) || []).filter((l) => l.from_memory_id === m.memory_id);
    const linkBadges = out.map((l) => `<span class="badge badge-muted">→ ${escHtml(String(l.link_type))}</span>`).join('');
    return `
      <div class="card" style="padding:14px 16px;margin-bottom:10px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          ${state.uiMode === 'expert' ? `<span class="badge badge-muted">#${escHtml(String(m.memory_id).slice(0, 8))}</span>` : ''}
          <span class="badge badge-info">${escHtml(String(m.playbook_id || ''))}</span>
          <span class="badge ${badgeForStatus(m.decision_status)}">${escHtml(String(m.decision_status || '—'))}</span>
          <span class="badge ${badgeForConfidence(m.confidence)}">${escHtml(String(m.confidence || '—'))}</span>
          <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(m.created_at))}</span>
        </div>
        <div style="margin-top:8px;font-size:13px;line-height:1.5;">${escHtml(String(m.decision_summary || ''))}</div>
        ${linkBadges ? `<div data-ui="expert-only" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">${linkBadges}</div>` : ''}
        <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.unresolved'))}</div>
            ${unresolved.length ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);">${unresolved.slice(0, 4).map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);margin-top:6px;">—</div>`}
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.nextSteps'))}</div>
            ${next.length ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);">${next.slice(0, 4).map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);margin-top:6px;">—</div>`}
          </div>
        </div>
      </div>
    `;
  }).join('');

  const eventsHtml = events.map((e) => `<li><strong>${escHtml(e.type)}</strong> — ${escHtml(formatDate(e.at))}</li>`).join('');
  const summaryHtml = summary ? `
    <div class="card" style="padding:14px 16px;margin-bottom:12px;">
      <div style="font-weight:700;margin-bottom:6px;">${escHtml(t('timeline.changeSummary'))}</div>
      <div style="font-size:12px;color:var(--text-muted);">${escHtml(summary.from.at)} → ${escHtml(summary.to.at)}</div>
      <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${escHtml(t('timeline.improved'))}</div>
          <div style="font-size:12px;color:var(--text-secondary);margin-top:6px;">
            ${summary.improved ? escHtml(JSON.stringify(summary.improved)) : '—'}
          </div>
        </div>
        <div>
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${escHtml(t('timeline.worse'))}</div>
          <div style="font-size:12px;color:var(--text-secondary);margin-top:6px;">
            ${summary.worse ? escHtml(JSON.stringify(summary.worse)) : '—'}
          </div>
        </div>
      </div>
      <div style="margin-top:10px;font-size:12px;color:var(--text-secondary);">
        <div><strong>${escHtml(t('timeline.validated'))}</strong>: ${escHtml(String((summary.validated || []).slice(0, 5).join(' | ') || '—'))}</div>
        <div style="margin-top:6px;"><strong>${escHtml(t('timeline.failed'))}</strong>: ${escHtml(String((summary.failed || []).slice(0, 5).join(' | ') || '—'))}</div>
        <div style="margin-top:6px;"><strong>${escHtml(t('timeline.latestNextStep'))}</strong>: ${escHtml(summary.latest_recommended_next_step || '—')}</div>
      </div>
    </div>
  ` : '';

  return `
    <div style="display:grid;grid-template-columns:320px 1fr;gap:14px;align-items:start;">
      <div>
        <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;">${escHtml(t('timeline.chains'))}</div>
        ${chainList}
      </div>
      <div>
        ${summaryHtml}
        <details class="card" style="padding:14px 16px;margin-bottom:12px;" open>
          <summary style="cursor:pointer;font-weight:700;">${escHtml(t('timeline.chainView'))}</summary>
          <div style="margin-top:10px;">${nodeHtml}</div>
        </details>
        <details class="card" style="padding:14px 16px;">
          <summary style="cursor:pointer;font-weight:700;">${escHtml(t('timeline.events'))}</summary>
          <ul style="margin:10px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);line-height:1.5;">
            ${eventsHtml || `<li>${escHtml(t('timeline.noEvents'))}</li>`}
          </ul>
        </details>
      </div>
    </div>
  `;
}

function renderTimelineView(memories, links, state, { escHtml, formatDate, t }, linksIndex) {
  const ui = state.decisionMemoryUi || {};
  const filters = ui.filters || {};
  const filtered = memories.filter((m) => passesFilters(m, filters, links, linksIndex));
  const selectedId = ui.selectedMemoryId ? String(ui.selectedMemoryId) : '';
  const isExpert = state.uiMode === 'expert';

  const renderInlineDetail = (m) => {
    const unresolved = Array.isArray(m.unresolved_risks) ? m.unresolved_risks : [];
    const next = Array.isArray(m.recommended_next_steps) ? m.recommended_next_steps : [];
    const val = Array.isArray(m.validated_hypotheses) ? m.validated_hypotheses : [];
    const fail = Array.isArray(m.failed_assumptions) ? m.failed_assumptions : [];
    const relLinks = linksIndex.get(String(m.memory_id)) || [];

    const ps = (m.persistence_safety && typeof m.persistence_safety === 'object') ? m.persistence_safety : {};
    const decay = (m.decay && typeof m.decay === 'object') ? m.decay : null;
    const lifeState = decay ? String(decay.memory_state || '') : String(m.memory_state || 'active');
    const staleness = decay ? String(decay.staleness_level || '') : '';
    const supersededBy = decay ? String(decay.superseded_by || '') : String(m.superseded_by || '');
    const invalidReason = decay ? String(decay.invalidated_reason || '') : String(m.invalidated_reason || '');
    const warning = decay ? String(decay.reuse_warning || '') : '';
    const lastReviewed = decay ? String(decay.last_reviewed_at || '') : String(m.last_reviewed_at || '');

    const list = (items, empty = '—', limit = 6) =>
      (Array.isArray(items) && items.length)
        ? `<ul style="margin:6px 0 0 18px;">${items.slice(0, limit).map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>`
        : `<div style="margin-top:6px;color:var(--text-muted);">${escHtml(empty)}</div>`;

    const basicHtml = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
        <div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.unresolved'))}</div>
          ${list(unresolved, '—', 6)}
        </div>
        <div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.nextSteps'))}</div>
          ${list(next, '—', 6)}
        </div>
      </div>
    `;

    const expertHtml = isExpert ? `
      <div data-ui="expert-only" style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('timeline.validated'))}</div>
          ${list(val, '—', 6)}
        </div>
        <div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:800;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('timeline.failed'))}</div>
          ${list(fail, '—', 6)}
        </div>
      </div>
      ${(warning || supersededBy || invalidReason || lastReviewed) ? `
        <div data-ui="expert-only" style="margin-top:12px;font-size:12px;color:var(--text-secondary);line-height:1.55;">
          ${warning ? `<div>⚠ ${escHtml(warning)}</div>` : ''}
          ${supersededBy ? `<div>${escHtml(t('memoryLifecycle.supersededBy'))}: <code>${escHtml(supersededBy)}</code></div>` : ''}
          ${invalidReason ? `<div>${escHtml(t('memoryLifecycle.invalidatedReason'))}: ${escHtml(invalidReason)}</div>` : ''}
          ${lastReviewed ? `<div>${escHtml(t('memoryLifecycle.lastReviewedAt'))}: ${escHtml(formatDate(lastReviewed))}</div>` : ''}
          ${(lifeState || staleness) ? `<div style="margin-top:6px;"><strong>lifecycle</strong>: ${escHtml(lifeState || 'active')} ${staleness ? `· ${escHtml(staleness)}` : ''}</div>` : ''}
        </div>
      ` : ''}
      <div data-ui="expert-only" style="margin-top:12px;font-size:12px;color:var(--text-secondary);line-height:1.55;">
        <div><strong>contract_version</strong>: ${escHtml(String(m.contract_version || ''))}</div>
        <div><strong>taxonomy_version</strong>: ${escHtml(String(m.taxonomy_version || ''))}</div>
        <div><strong>persistence_safety</strong>: <code style="font-size:11px;">${escHtml(JSON.stringify(ps))}</code></div>
      </div>
      ${relLinks.length ? `<details data-ui="expert-only" style="margin-top:12px;"><summary style="cursor:pointer;color:var(--text-muted);">${escHtml(t('timeline.links'))}</summary><pre style="white-space:pre-wrap;font-size:11px;margin-top:8px;">${escHtml(JSON.stringify(relLinks, null, 2))}</pre></details>` : ''}
    ` : '';

    return `
      <div class="decision-memory-inline-detail">
        <div style="font-size:12px;color:var(--text-secondary);line-height:1.6;">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            ${isExpert ? `<span class="badge badge-muted">#${escHtml(String(m.memory_id).slice(0, 8))}</span>` : ''}
            <span class="badge badge-info">${escHtml(String(m.playbook_id || ''))}</span>
            <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(m.created_at))}</span>
          </div>
          <div style="margin-top:10px;font-size:13px;color:var(--text-primary);">${escHtml(String(m.decision_summary || ''))}</div>
          ${basicHtml}
          ${expertHtml}
        </div>
      </div>
    `;
  };

  const grouped = groupTimeline(filtered);
  const sections = grouped.map((g) => {
    const byPlaybook = new Map();
    g.items.forEach((m) => {
      const pb = String(m.playbook_id || '—');
      if (!byPlaybook.has(pb)) byPlaybook.set(pb, []);
      byPlaybook.get(pb).push(m);
    });
    const playbooks = [...byPlaybook.keys()].sort();
    const pbHtml = playbooks.map((pb) => {
      const items = byPlaybook.get(pb) || [];
      const rows = items.map((m) => {
        const unresolved = Array.isArray(m.unresolved_risks) ? m.unresolved_risks : [];
        const isSelected = selectedId && String(m.memory_id) === selectedId;
        return `
          <div class="card decision-memory-timeline-card ${isSelected ? 'selected' : ''}" style="padding:12px 14px;margin-bottom:${isSelected ? '10px' : '8px'};cursor:pointer;"
            data-action="select-decision-memory-detail" data-memory-id="${escHtml(String(m.memory_id))}">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <span class="badge badge-muted">${escHtml(toDateKey(m.created_at))}</span>
              <span class="badge ${badgeForStatus(m.decision_status)}">${escHtml(String(m.decision_status || '—'))}</span>
              <span class="badge ${badgeForConfidence(m.confidence)}">${escHtml(String(m.confidence || '—'))}</span>
              ${unresolved.length ? `<span class="badge badge-warning">${escHtml(t('timeline.openRisks'))}: ${unresolved.length}</span>` : `<span class="badge badge-muted">${escHtml(t('timeline.openRisks'))}: 0</span>`}
              <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(m.created_at))}</span>
            </div>
            <div style="margin-top:8px;font-size:13px;line-height:1.45;color:var(--text-secondary);">${escHtml(String(m.decision_summary || ''))}</div>
          </div>
          ${isSelected ? renderInlineDetail(m) : ''}
        `;
      }).join('');
      return `
        <div style="margin-top:10px;">
          <div style="font-weight:700;margin:10px 0 6px;">${escHtml(pb)}</div>
          ${rows}
        </div>
      `;
    }).join('');

    return `
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(g.month)}</div>
        ${pbHtml}
      </div>
    `;
  }).join('');

  return sections || `<div class="empty-state"><div class="empty-state-text">${escHtml(t('timeline.empty'))}</div></div>`;
}

function renderDecisionMemory() {
  const { state, escHtml, formatDate, t } = getCtx();
  const isExpert = state.uiMode === 'expert';
  const pkg = state.decisionMemory || { loading: false, error: null, memories: null };
  const memories = Array.isArray(pkg.memories) ? pkg.memories : null;
  const links = Array.isArray(pkg.links) ? pkg.links : [];
  const ui = state.decisionMemoryUi || { mode: 'timeline', filters: {} };
  const searchPkg = state.decisionMemorySearch || null;
  const searchQ = String(ui.searchQ || '').trim();
  const semanticEnabled = state.semanticMemoryEnabled; // undefined until first call; false when disabled
  const similarState = state.decisionMemorySimilar || {};

  const topBar = `
    <div class="page-header" style="flex-direction:row;justify-content:space-between;align-items:flex-start;gap:12px;">
      <div>
        <div class="page-title">${escHtml(t('decisionMemory.title'))}</div>
        <div class="page-subtitle">${escHtml(t('decisionMemory.subtitle'))}</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-secondary btn-sm" data-action="load-decision-memories">↺ ${escHtml(t('decisionMemory.refresh'))}</button>
        <button class="btn btn-secondary btn-sm ${ui.mode === 'timeline' ? 'btn-primary' : ''}" data-action="set-decision-memory-mode" data-mode="timeline">${escHtml(t('timeline.mode.timeline'))}</button>
        <button class="btn btn-secondary btn-sm ${ui.mode === 'chain' ? 'btn-primary' : ''}" data-ui="expert-only" data-action="set-decision-memory-mode" data-mode="chain">${escHtml(t('timeline.mode.chain'))}</button>
        <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="load-decision-memories" data-include-archived="1">${escHtml(t('memoryLifecycle.includeArchived'))}</button>
      </div>
    </div>
  `;

  if (pkg.loading) {
    return topBar + `<div class="loading-state"><span class="spinner spinner-lg"></span> ${escHtml(t('decisionMemory.loading'))}</div>`;
  }
  if (pkg.error) {
    return topBar + `<div class="error-banner">⚠️ ${escHtml(pkg.error)}</div>`;
  }
  if (!memories) {
    return topBar + `
      <div class="empty-state">
        <div class="empty-state-icon">🗂️</div>
        <div class="empty-state-text">${escHtml(t('decisionMemory.emptyUnloaded'))}</div>
        <button class="btn btn-primary btn-sm" data-action="load-decision-memories">${escHtml(t('decisionMemory.load'))}</button>
      </div>
    `;
  }

  const explorerBar = renderMemoryExplorerBar(state, { escHtml, formatDate, t });

  const searchBar = (() => {
    const hint = ui.navDecisionChainId
      ? t('decisionMemory.search.withinChain')
      : ui.navStrategicContextId
        ? t('decisionMemory.search.withinContext')
        : '';
    const expertMeta = isExpert && searchPkg && !searchPkg.loading && !searchPkg.error ? `
      <div data-ui="expert-only" style="margin-top:8px;font-size:11px;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap;">
        <span><strong>search_mode</strong>: ${escHtml(String(searchPkg.search_mode || '—'))}</span>
        <span><strong>scope</strong>: ${escHtml(JSON.stringify(searchPkg.scope || {}))}</span>
      </div>
    ` : '';
    return `
      <div class="card" style="padding:12px 14px;margin:0 0 14px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <div style="flex:1;min-width:240px;">
            <label style="display:block;font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">
              ${escHtml(t('decisionMemory.search.label'))}
            </label>
            <input class="input" data-action="set-decision-memory-search"
              placeholder="${escHtml(hint || t('decisionMemory.search.label'))}"
              value="${escHtml(searchQ)}"
              style="width:100%;"
            />
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            ${searchQ ? `<button type="button" class="btn btn-secondary btn-sm" data-action="clear-decision-memory-search">${escHtml(t('filters.clear') || 'Clear')}</button>` : ''}
            ${isExpert ? `
              <button type="button" class="btn btn-secondary btn-sm ${ui.includeStale ? 'btn-primary' : ''}" data-action="toggle-decision-memory-search-stale">
                ${escHtml(t('decisionMemory.search.includeStale'))}
              </button>
              <button type="button" class="btn btn-secondary btn-sm ${ui.expertOverride ? 'btn-primary' : ''}" data-action="toggle-decision-memory-search-expert-override">
                ${escHtml(t('decisionMemory.search.expertOverride'))}
              </button>
            ` : ''}
          </div>
        </div>
        ${searchQ ? `<div style=”margin-top:10px;font-size:12px;color:var(--text-secondary);”>”${escHtml(t('decisionMemory.search.disclaimer'))}”</div>` : ''}
        ${searchPkg?.error ? `<div class=”error-banner” style=”margin-top:10px;”>⚠️ ${escHtml(String(searchPkg.error))}</div>` : ''}
        ${searchPkg?.loading ? `<div style=”margin-top:10px;font-size:12px;color:var(--text-muted);”><span class=”spinner”></span> ${escHtml(t('decisionMemory.search.searching'))}</div>` : ''}
        ${expertMeta}
      </div>
    `;
  })();

  if (memories.length === 0) {
    return topBar + explorerBar + searchBar + `
      <div class="empty-state">
        <div class="empty-state-icon">🗂️</div>
        <div class="empty-state-text">${escHtml(t('decisionMemory.empty'))}</div>
      </div>
    `;
  }

  const navMemories = applyExplorerMemoryFilter(memories, state);
  const uiNav = state.decisionMemoryUi || {};

  const linksIndex = (() => {
    const m = new Map();
    links.forEach((l) => {
      const from = String(l?.from_memory_id || '');
      const to = String(l?.to_memory_id || '');
      if (from) { if (!m.has(from)) m.set(from, []); m.get(from).push(l); }
      if (to) { if (!m.has(to)) m.set(to, []); m.get(to).push(l); }
    });
    return m;
  })();

  const filtersBar = renderFiltersBar(state, { escHtml, t });

  const filteredEmptyMessage = () => {
    if (uiNav.navStrategicContextId && uiNav.navDecisionChainId) return t('decisionMemory.nav.emptyRoomMemories');
    if (uiNav.navStrategicContextId) return t('decisionMemory.nav.noLinkedMemories');
    return t('timeline.empty');
  };

  const filteredEmptyBanner = navMemories.length === 0
    ? `<div class="empty-state" style="margin-bottom:14px;"><div class="empty-state-text">${escHtml(filteredEmptyMessage())}</div></div>`
    : '';

  const bodyCore = (() => {
    if (navMemories.length === 0) return '';
    if (searchQ && searchPkg && !searchPkg.loading && !searchPkg.error) {
      const ids = new Set((Array.isArray(searchPkg.results) ? searchPkg.results : []).map((r) => String(r?.memory_id || '')).filter(Boolean));
      const subset = navMemories.filter((m) => ids.has(String(m.memory_id)));
      return renderTimelineView(subset, links, state, { escHtml, formatDate, t }, linksIndex);
    }
    if (ui.mode === 'chain' && isExpert) {
      const chainsPkg = deriveChains(navMemories, links);
      return renderChainView(chainsPkg, state, { escHtml, formatDate, t }, linksIndex);
    }
    return renderTimelineView(navMemories, links, state, { escHtml, formatDate, t }, linksIndex);
  })();

  const body = filteredEmptyBanner + bodyCore;

  const selected = state.selectedMemoryIds || [];
  const copyBlock = (() => {
    const picked = navMemories.filter((m) => selected.includes(m.memory_id));
    if (picked.length === 0) return '';
    const compact = picked.slice(0, 5).map((m) => `- [${m.playbook_id}] ${m.decision_status} (${m.confidence}): ${m.decision_summary}`).join('\n');
    return `
      <div class="card" style="padding:14px 16px;margin-bottom:14px;background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.2);">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <div style="font-weight:700;font-size:13px;">${escHtml(t('decisionMemory.selected'))}: ${picked.length}</div>
          <button class="btn btn-primary btn-sm" data-action="copy-selected-memories">${escHtml(t('decisionMemory.copyCompact'))}</button>
          <button class="btn btn-secondary btn-sm" data-action="clear-selected-memories">${escHtml(t('decisionMemory.clearSelection'))}</button>
          ${isExpert ? `<button class="btn btn-danger btn-sm" data-ui="expert-only" data-action="request-delete-selected-memories">🗑️ ${escHtml(t('decisionMemory.deleteSelected'))}</button>` : ''}
        </div>
        <pre data-ui="expert-only" style="margin:10px 0 0;white-space:pre-wrap;font-size:12px;color:var(--text-secondary);">${escHtml(compact)}</pre>
      </div>
    `;
  })();

  const rows = navMemories.map((m) => {
    const statusCls = badgeForStatus(m.decision_status);
    const confCls = badgeForConfidence(m.confidence);
    const ps = m.persistence_safety || {};
    const safe = ps.safe_to_persist === true;
    const needs = ps.requires_user_confirmation === true;
    const persistBadge = safe && !needs ? `<span class="badge badge-success">${escHtml(t('decisionMemory.persist.safe'))}</span>`
      : needs ? `<span class="badge badge-warning">${escHtml(t('decisionMemory.persist.confirm'))}</span>`
        : `<span class="badge badge-danger">${escHtml(t('decisionMemory.persist.unsafe'))}</span>`;

    const checked = selected.includes(m.memory_id) ? 'checked' : '';
    const outbound = links.filter((l) => l.from_memory_id === m.memory_id);
    const outboundBadges = outbound.slice(0, 4).map((l) => `<span class="badge badge-muted" title="${escHtml(String(l.to_memory_id))}">→ ${escHtml(String(l.link_type))}</span>`).join('');
    const expertMeta = isExpert ? `
      <details data-ui="expert-only" style="margin-top:10px;">
        <summary style="cursor:pointer;color:var(--text-muted);">${escHtml(t('decisionMemory.details'))}</summary>
        <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
          <div><strong>contract_version</strong>: ${escHtml(String(m.contract_version || ''))}</div>
          <div><strong>taxonomy_version</strong>: ${escHtml(String(m.taxonomy_version || ''))}</div>
          <div><strong>user_confirmed</strong>: ${escHtml(String(!!m.user_confirmed))}</div>
          <div><strong>persistence_safety</strong>: <code style="font-size:11px;">${escHtml(JSON.stringify(ps))}</code></div>
        </div>
      </details>
    ` : '';

    const unresolved = Array.isArray(m.unresolved_risks) ? m.unresolved_risks : [];
    const next = Array.isArray(m.recommended_next_steps) ? m.recommended_next_steps : [];
    const decay = (m.decay && typeof m.decay === 'object') ? m.decay : null;
    const lifeState = decay ? String(decay.memory_state || '') : String(m.memory_state || 'active');
    const staleness = decay ? String(decay.staleness_level || '') : '';
    const supersededBy = decay ? String(decay.superseded_by || '') : String(m.superseded_by || '');
    const invalidReason = decay ? String(decay.invalidated_reason || '') : String(m.invalidated_reason || '');
    const warning = decay ? String(decay.reuse_warning || '') : '';
    const lastReviewed = decay ? String(decay.last_reviewed_at || '') : String(m.last_reviewed_at || '');
    const lifeBadge = `<span class="badge ${badgeForLifecycleState(lifeState)}">${escHtml(lifeState || 'active')}</span>`;
    const staleBadge = staleness ? `<span class="badge ${badgeForStaleness(staleness)}">${escHtml(staleness)}</span>` : '';

    const sim = similarState[String(m.memory_id)] || null;
    const simOpen = !!sim?.open;
    const simLoading = !!sim?.loading;
    const simErr = sim?.error ? String(sim.error) : '';
    const simEnabled = sim?.enabled;
    const simResults = Array.isArray(sim?.results) ? sim.results : [];

    const simSection = (isExpert && simOpen) ? `
      <div class="card" style="margin-top:12px;padding:12px 12px;background:rgba(0,0,0,0.02);">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
          <div style="font-weight:800;">${escHtml('Related prior decisions')}</div>
          <div data-ui="expert-only" style="font-size:11px;color:var(--text-muted);">
            ${(sim?.meta?.provider || sim?.meta?.model) ? `${escHtml(String(sim?.meta?.provider || ''))} · ${escHtml(String(sim?.meta?.model || ''))}` : ''}
          </div>
        </div>
        <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
          <div>“Similarity does not imply correctness.”</div>
          <div>“These are prior decision records, not verified facts.”</div>
        </div>
        ${simLoading ? `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);"><span class="spinner"></span> ${escHtml('Loading…')}</div>` : ''}
        ${simErr ? `<div class="error-banner" style="margin-top:10px;">⚠️ ${escHtml(simErr)}</div>` : ''}
        ${(simEnabled === false) ? `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);">${escHtml('Semantic similarity is disabled.')}</div>` : ''}
        ${(!simLoading && !simErr && simEnabled !== false) ? (simResults.length ? `
          <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
            ${simResults.map((r) => `
              <div class="card" style="padding:10px 12px;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                  <span class="badge badge-muted">sim ${(Number(r.similarity_score || 0).toFixed(3))}</span>
                  <span class="badge badge-info">${escHtml(String(r.playbook_id || ''))}</span>
                  <span class="badge ${badgeForStatus(r.decision_status)}">${escHtml(String(r.decision_status || '—'))}</span>
                  <span class="badge ${badgeForConfidence(r.confidence)}">${escHtml(String(r.confidence || '—'))}</span>
                  <span class="badge ${badgeForLifecycleState(r?.lifecycle?.memory_state)}">${escHtml(String(r?.lifecycle?.memory_state || 'active'))}</span>
                  <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">${escHtml(formatDate(r.created_at))}</span>
                </div>
                <div style="margin-top:8px;font-size:13px;line-height:1.45;color:var(--text-secondary);">${escHtml(String(r.decision_summary || ''))}</div>
              </div>
            `).join('')}
          </div>
        ` : `<div style="margin-top:10px;font-size:12px;color:var(--text-muted);">${escHtml('No similar decisions found in scope.')}</div>`) : ''}
      </div>
    ` : '';

    const inlineConfirmation = state.pendingConfirmation?.mode === 'inline'
      && state.pendingConfirmation?.anchor?.kind === 'decision-memory'
      && String(state.pendingConfirmation?.anchor?.id || '') === String(m.memory_id || '')
      ? renderPendingConfirmation(state.pendingConfirmation, { inlineOnly: true, uiMode: state.uiMode })
      : '';

    return `
      <div class="card" style="padding:16px 18px;margin-bottom:12px;">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" data-action="toggle-memory-selection" data-memory-id="${escHtml(m.memory_id)}" ${checked} style="accent-color:var(--accent);">
            ${isExpert ? `<span class="badge badge-muted">#${escHtml(String(m.memory_id).slice(0, 8))}</span>` : ''}
          </label>
          <div style="flex:1;min-width:260px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <span class="badge badge-info">${escHtml(m.playbook_id)}</span>
              <span class="badge ${statusCls}">${escHtml(m.decision_status)}</span>
              <span class="badge ${confCls}">${escHtml(m.confidence)}</span>
              ${lifeBadge}
              ${staleBadge}
              ${persistBadge}
              ${m.user_confirmed ? `<span class="badge badge-success">${escHtml(t('decisionMemory.confirmed'))}</span>` : `<span class="badge badge-muted">${escHtml(t('decisionMemory.notConfirmed'))}</span>`}
            </div>
            ${warning ? `<div style="margin-top:8px;font-size:12px;color:var(--text-muted);">⚠ ${escHtml(warning)}</div>` : ''}
            ${supersededBy ? `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${escHtml(t('memoryLifecycle.supersededBy'))}: <code>${escHtml(supersededBy)}</code></div>` : ''}
            ${invalidReason ? `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">${escHtml(t('memoryLifecycle.invalidatedReason'))}: ${escHtml(invalidReason)}</div>` : ''}
            ${lastReviewed ? `<div style="margin-top:6px;font-size:11px;color:var(--text-muted);">${escHtml(t('memoryLifecycle.lastReviewedAt'))}: ${escHtml(formatDate(lastReviewed))}</div>` : ''}
            <div style="margin-top:10px;font-size:14px;color:var(--text-primary);line-height:1.5;">
              ${escHtml(m.decision_summary)}
            </div>
            ${(unresolved.length || next.length) ? `
              <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                  <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.unresolved'))}</div>
                  ${unresolved.length ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);">${unresolved.slice(0, 4).map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);margin-top:6px;">—</div>`}
                </div>
                <div>
                  <div style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">${escHtml(t('decisionMemory.nextSteps'))}</div>
                  ${next.length ? `<ul style="margin:6px 0 0 18px;padding:0;font-size:12px;color:var(--text-secondary);">${next.slice(0, 4).map((x) => `<li>${escHtml(String(x))}</li>`).join('')}</ul>` : `<div style="font-size:12px;color:var(--text-muted);margin-top:6px;">—</div>`}
                </div>
              </div>
            ` : ''}
            ${expertMeta}
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
            <div style="font-size:11px;color:var(--text-muted);">${escHtml(formatDate(m.created_at))}</div>
            <button class="btn btn-secondary btn-sm" data-action="open-session" data-session-id="${escHtml(m.session_id)}" data-mode="session-history">
              ${escHtml(t('decisionMemory.openSession'))}
            </button>
            ${isExpert ? `
              <button class="btn btn-danger btn-sm" data-ui="expert-only" data-action="request-delete-decision-memory" data-memory-id="${escHtml(m.memory_id)}">
                🗑️ ${escHtml(t('decisionMemory.deleteOne'))}
              </button>
            ` : ''}
            ${(isExpert && semanticEnabled !== false) ? `
              <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="toggle-similar-decisions" data-memory-id="${escHtml(m.memory_id)}">
                ${escHtml('Similar decisions')}
              </button>
            ` : ''}
            <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="create-decision-memory-link" data-from-memory-id="${escHtml(m.memory_id)}">🔗 Lier</button>
            <button class="btn btn-secondary btn-sm" data-ui="expert-only" data-action="link-memory-to-strategic-context" data-memory-id="${escHtml(m.memory_id)}">🧭 ${escHtml(t('contexts.linkMemory'))}</button>
            <div data-ui="expert-only" style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
              <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(m.memory_id)}" data-lifecycle-action="review">${escHtml(t('memoryLifecycle.review'))}</button>
              ${lifeState === 'archived'
                ? `<button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(m.memory_id)}" data-lifecycle-action="restore">${escHtml(t('memoryLifecycle.restore'))}</button>`
                : `<button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(m.memory_id)}" data-lifecycle-action="archive">${escHtml(t('memoryLifecycle.archive'))}</button>`
              }
              <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(m.memory_id)}" data-lifecycle-action="supersede">${escHtml(t('memoryLifecycle.supersede'))}</button>
              <button class="btn btn-secondary btn-sm" data-action="decision-memory-lifecycle" data-memory-id="${escHtml(m.memory_id)}" data-lifecycle-action="invalidate">${escHtml(t('memoryLifecycle.invalidate'))}</button>
            </div>
          </div>
        </div>
        ${outboundBadges ? `<div data-ui="expert-only" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">${outboundBadges}</div>` : ''}
        ${inlineConfirmation}
        ${simSection}
      </div>
    `;
  }).join('');

  // Keep legacy record list in Expert only (debug view); timeline is the default.
  const legacyList = isExpert ? `<details data-ui="expert-only" style="margin-top:12px;"><summary style="cursor:pointer;color:var(--text-muted);">${escHtml(t('timeline.rawList'))}</summary><div style="margin-top:10px;">${copyBlock}${rows}</div></details>` : '';
  return topBar + explorerBar + searchBar + filtersBar + body + legacyList;
}

function registerDecisionMemoryFeature() {
  window.DecisionArena.views['decision-memory'] = renderDecisionMemory;
}

export { registerDecisionMemoryFeature };

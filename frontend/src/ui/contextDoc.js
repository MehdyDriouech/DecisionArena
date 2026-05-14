/**
 * Shared context-document UI helpers.
 * Used across: chat, decision-room, confrontation, stress-test, new-session.
 */

function getCtx() {
  const arena = window.DecisionArena;
  const state = arena.store.state;
  const { escHtml } = arena.utils;
  const t = (key) => window.i18n?.t(key) ?? key;
  return { state, escHtml, t };
}

function renderContextDocBadge() {
  const { state, escHtml, t } = getCtx();
  const doc = state.currentContextDoc;
  if (!doc) return '';
  return `
    <button class="ctx-doc-badge" data-action="toggle-ctx-doc-panel" title="${escHtml(t('contextDoc.open'))}">
      ${t('contextDoc.badge')}
      <span class="ctx-doc-badge-count">${doc.character_count.toLocaleString()} car.</span>
    </button>
  `;
}

function renderContextDocPanel() {
  const { state, escHtml, t } = getCtx();
  const doc = state.currentContextDoc;
  if (!doc || !state.ctxDocPanelOpen) return '';
  const session = state.currentSession;
  const isLarge = doc.character_count > 30000;

  return `
    <div class="ctx-doc-panel" id="ctx-doc-panel">
      <div class="ctx-doc-panel-header">
        <span>${t('contextDoc.sectionTitle')}</span>
        <button class="ctx-doc-panel-close" data-action="toggle-ctx-doc-panel">✕</button>
      </div>
      <div class="ctx-doc-panel-body">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center;">
          ${doc.title ? `<strong>${escHtml(doc.title)}</strong>` : ''}
          <span class="badge" style="background:var(--bg-secondary);color:var(--text-secondary);">${escHtml(doc.source_type)}</span>
          ${doc.original_filename ? `<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);">📎 ${escHtml(doc.original_filename)}</span>` : ''}
          <span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);">${doc.character_count.toLocaleString()} car.</span>
          ${isLarge ? `<span class="badge badge-warning">⚠️ ${t('contextDoc.largeWarning')}</span>` : ''}
        </div>
        <div class="ctx-doc-panel-content">${escHtml(doc.content)}</div>
        ${session ? `
          <div style="display:flex;gap:8px;margin-top:14px;">
            <button class="btn btn-secondary btn-sm" data-action="replace-ctx-doc" data-session-id="${escHtml(session.id)}">${t('contextDoc.replace')}</button>
            <button class="btn btn-danger btn-sm" data-action="delete-ctx-doc" data-session-id="${escHtml(session.id)}">${t('contextDoc.delete')}</button>
          </div>
        ` : ''}
      </div>
    </div>
  `;
}

function renderInlineContextDocEditor(sessionId) {
  const { state, escHtml, t } = getCtx();
  const ed = state.ctxDocEditor || { tab: 'manual', title: '', content: '', open: false };
  if (!ed.open) {
    return `<button class="btn btn-secondary btn-sm" data-action="open-ctx-doc-editor" data-session-id="${escHtml(sessionId)}" style="margin-top:8px;">${t('contextDoc.add')}</button>`;
  }

  const charCount = (ed.content || '').length;
  const isLarge   = charCount > 30000;
  const isOver    = charCount > 50000;

  return `
    <div class="ctx-doc-editor" id="ctx-doc-editor">
      <div style="font-weight:600;font-size:13px;margin-bottom:12px;">${t('contextDoc.sectionTitle')}</div>
      <div class="ctx-doc-tabs" style="margin-bottom:14px;">
        <button class="ctx-doc-tab ${ed.tab === 'manual' ? 'active' : ''}" data-action="ctx-doc-editor-tab" data-tab="manual">${t('contextDoc.tabs.manual')}</button>
        <button class="ctx-doc-tab ${ed.tab === 'upload' ? 'active' : ''}" data-action="ctx-doc-editor-tab" data-tab="upload">${t('contextDoc.tabs.upload')}</button>
      </div>
      <div class="form-group">
        <label>${t('contextDoc.titleLabel')}</label>
        <input class="input" id="ctx-doc-ed-title" type="text" value="${escHtml(ed.title)}" data-action="ctx-doc-ed-title-change">
      </div>
      ${ed.tab === 'manual' ? `
        <div class="form-group">
          <textarea class="textarea" id="ctx-doc-ed-content" style="min-height:120px;" data-action="ctx-doc-ed-content-change">${escHtml(ed.content)}</textarea>
          <div style="font-size:12px;color:${isOver ? '#ef4444' : isLarge ? '#f59e0b' : 'var(--text-muted)'};margin-top:4px;">${charCount.toLocaleString()} / 50 000 car.</div>
          ${isLarge && !isOver ? `<div class="ctx-doc-warning">⚠️ ${t('contextDoc.largeWarning')}</div>` : ''}
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" data-action="save-ctx-doc-manual" data-session-id="${escHtml(sessionId)}" ${isOver ? 'disabled' : ''}>${t('contextDoc.save')}</button>
          <button class="btn btn-secondary btn-sm" data-action="close-ctx-doc-editor">${t('contextDoc.cancel')}</button>
        </div>
      ` : `
        <div class="form-group">
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">${t('contextDoc.fileHint')}</div>
          <input class="input" id="ctx-doc-ed-file" type="file" accept=".txt,.md,.pdf,.docx" style="padding:6px;">
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" data-action="save-ctx-doc-upload" data-session-id="${escHtml(sessionId)}">${t('contextDoc.upload')}</button>
          <button class="btn btn-secondary btn-sm" data-action="close-ctx-doc-editor">${t('contextDoc.cancel')}</button>
        </div>
      `}
      <span id="ctx-doc-ed-status" style="font-size:12px;margin-left:10px;"></span>
    </div>
  `;
}

export { renderContextDocBadge, renderContextDocPanel, renderInlineContextDocEditor };

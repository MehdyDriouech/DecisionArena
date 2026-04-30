/* Context Document feature — action handlers and input live-sync */
import { registerAction, registerInputListener } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:             a.store.state,
    render:            () => a.render?.(),
    ContextDocService: a.services.ContextDocService,
    t:                 (key) => window.i18n?.t(key) ?? key,
  };
}

function registerContextDocHandlers() {
  /* ── New-session draft enable/tab ────────────────────────────────────── */
  registerAction('toggle-ctx-doc-enabled', () => {
    const { state, render } = getCtx();
    state.newSession.ctxDocEnabled = !state.newSession.ctxDocEnabled;
    if (!state.newSession.ctxDocEnabled) {
      state.newSession.ctxDocDraftSaved   = false;
      state.newSession.ctxDocDraftSummary = null;
    }
    render();
  });

  registerAction('ctx-doc-tab', ({ element }) => {
    const { state, render } = getCtx();
    state.newSession.ctxDocTab          = element.dataset.tab;
    state.newSession.ctxDocDraftSaved   = false;
    state.newSession.ctxDocDraftSummary = null;
    render();
  });

  registerAction('save-ctx-doc-draft-manual', () => {
    const { state, render } = getCtx();
    const ns = state.newSession;
    if (!ns.ctxDocContent?.trim()) return;
    ns.ctxDocDraftSaved   = true;
    ns.ctxDocDraftSummary = `${ns.ctxDocContent.length.toLocaleString()} car. · manual`;
    render();
  });

  registerAction('save-ctx-doc-draft-upload', () => {
    const { state, render, t } = getCtx();
    const fileInput = document.getElementById('ctx-doc-file');
    const file = fileInput?.files?.[0];
    if (!file) { state.error = t('contextDoc.selectFile'); render(); return; }
    state.newSession.ctxDocDraftSaved   = true;
    state.newSession.ctxDocDraftSummary = `${file.name} · ${(file.size / 1024).toFixed(0)} KB · upload`;
    render();
  });

  /* ── Session ctx-doc panel ───────────────────────────────────────────── */
  registerAction('toggle-ctx-doc-panel', () => {
    const { state, render } = getCtx();
    state.ctxDocPanelOpen = !state.ctxDocPanelOpen;
    render();
  });

  registerAction('open-ctx-doc-editor', () => {
    const { state, render } = getCtx();
    state.ctxDocEditor = { open: true, tab: 'manual', title: '', content: '' };
    render();
  });

  registerAction('close-ctx-doc-editor', () => {
    const { state, render } = getCtx();
    if (state.ctxDocEditor) state.ctxDocEditor.open = false;
    render();
  });

  registerAction('ctx-doc-editor-tab', ({ element }) => {
    const { state, render } = getCtx();
    if (state.ctxDocEditor) state.ctxDocEditor.tab = element.dataset.tab;
    render();
  });

  registerAction('replace-ctx-doc', () => {
    const { state, render } = getCtx();
    state.ctxDocEditor    = { open: true, tab: 'manual', title: '', content: '' };
    state.ctxDocPanelOpen = true;
    render();
  });

  registerAction('save-ctx-doc-manual', async ({ element }) => {
    const { state, render, ContextDocService, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    const title     = document.getElementById('ctx-doc-ed-title')?.value.trim()   || '';
    const content   = document.getElementById('ctx-doc-ed-content')?.value        || '';
    const statusEl  = document.getElementById('ctx-doc-ed-status') || document.getElementById('ctx-doc-hist-status');
    if (statusEl) statusEl.textContent = t('contextDoc.saving');
    try {
      const res = await ContextDocService.saveManual(sessionId, title, content);
      state.currentContextDoc = res.context_document || null;
      if (state.ctxDocEditor) state.ctxDocEditor.open = false;
      state.ctxDocPanelOpen = true;
      render();
      if (res.warning) { state.error = res.warning; render(); }
    } catch (err) {
      if (statusEl) statusEl.textContent = '⚠️ ' + err.message;
      else { state.error = err.message; render(); }
    }
  });

  registerAction('save-ctx-doc-upload', async ({ element }) => {
    const { state, render, ContextDocService, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    const fileInput = document.getElementById('ctx-doc-ed-file');
    const title     = document.getElementById('ctx-doc-ed-title')?.value.trim() || '';
    const statusEl  = document.getElementById('ctx-doc-ed-status') || document.getElementById('ctx-doc-hist-status');
    if (!fileInput?.files[0]) {
      if (statusEl) statusEl.textContent = '⚠️ ' + t('contextDoc.selectFile');
      return;
    }
    if (statusEl) statusEl.textContent = t('contextDoc.uploading');
    try {
      const res = await ContextDocService.upload(sessionId, title, fileInput.files[0]);
      state.currentContextDoc = res.context_document || null;
      if (state.ctxDocEditor) state.ctxDocEditor.open = false;
      state.ctxDocPanelOpen = true;
      render();
      if (res.warning) { state.error = res.warning; render(); }
    } catch (err) {
      if (statusEl) statusEl.textContent = '⚠️ ' + err.message;
      else { state.error = err.message; render(); }
    }
  });

  registerAction('delete-ctx-doc', async ({ element }) => {
    const { state, render, ContextDocService, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!confirm(t('contextDoc.deleteConfirm'))) return;
    try {
      await ContextDocService.remove(sessionId);
      state.currentContextDoc = null;
      state.ctxDocPanelOpen   = false;
      render();
    } catch (err) {
      state.error = err.message;
      render();
    }
  });

  /* ── Live input sync ─────────────────────────────────────────────────── */
  registerInputListener((e) => {
    const { state, render } = getCtx();
    const action = e.target.dataset.action;
    if (action === 'ctx-doc-content-change') {
      state.newSession.ctxDocContent = e.target.value;
      render();
      return true;
    }
    if (action === 'ctx-doc-title-change') {
      state.newSession.ctxDocTitle = e.target.value;
      return true;
    }
    if (action === 'ctx-doc-ed-content-change') {
      if (!state.ctxDocEditor) state.ctxDocEditor = {};
      state.ctxDocEditor.content = e.target.value;
      render();
      return true;
    }
    if (action === 'ctx-doc-ed-title-change') {
      if (!state.ctxDocEditor) state.ctxDocEditor = {};
      state.ctxDocEditor.title = e.target.value;
      return true;
    }
    return false;
  });
}

export { registerContextDocHandlers };

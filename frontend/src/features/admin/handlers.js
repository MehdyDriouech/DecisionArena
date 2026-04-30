/* Admin feature — action handlers and change/input listeners for providers, personas, templates */
import { registerAction, registerChangeListener, registerInputListener, registerSubmit } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:          a.store.state,
    render:         () => a.render?.(),
    navigate:       (v) => a.router.navigate(v),
    apiFetch:       a.services.apiFetch,
    PersonaService: a.services.PersonaService,
    escHtml:        a.utils.escHtml,
    t:              (key) => window.i18n?.t(key) ?? key,
  };
}

function getViews() {
  return window.DecisionArena.views.shared || {};
}

/* ── Providers ─────────────────────────────────────────────────────────── */

function updateProviderModelSelectDom(models) {
  const { escHtml, t } = getCtx();
  const select  = document.getElementById('pf-model-select');
  if (!select) return;
  const current = document.getElementById('pf-model')?.value || '';
  const options = ['<option value="">— ' + escHtml(t('providers.selectFetchedModel')) + ' —</option>']
    .concat((models || []).map((m) => {
      const selected = current && m.id === current ? ' selected' : '';
      const label    = `${m.name || m.id}${m.details ? ` (${m.details})` : ''}`;
      return `<option value="${escHtml(m.id)}"${selected}>${escHtml(label)}</option>`;
    }));
  select.innerHTML = options.join('');
}

async function doSaveProvider() {
  const { state, render, apiFetch, escHtml, t } = getCtx();
  const id            = document.getElementById('pf-id')?.value.trim();
  const name          = document.getElementById('pf-name')?.value.trim();
  const type          = document.getElementById('pf-type')?.value;
  const base_url      = document.getElementById('pf-base-url')?.value.trim();
  const api_key       = document.getElementById('pf-api-key')?.value.trim();
  const default_model = document.getElementById('pf-model')?.value.trim();
  const enabled       = document.getElementById('pf-enabled')?.checked ?? true;
  const priority      = parseInt(document.getElementById('pf-priority')?.value ?? '100', 10);
  const resultEl      = document.getElementById('provider-form-result');

  if (!id || !base_url) {
    if (resultEl) resultEl.innerHTML = `<div class="provider-test-result fail">${escHtml(t('providers.fieldId'))} + ${escHtml(t('providers.fieldBaseUrl'))} required.</div>`;
    return;
  }
  try {
    const body = { id, name, type, base_url, default_model, enabled, priority: Number.isFinite(priority) ? priority : 100 };
    if (api_key) body.api_key = api_key;
    const result = await apiFetch('/api/providers', { method: 'POST', body: JSON.stringify(body) });
    const existingIdx = state.providers.findIndex((p) => p.id === result.id);
    if (existingIdx >= 0) state.providers[existingIdx] = result;
    else state.providers.push(result);
    state.providerModelOptions = [];
    state.providerModelStatus  = null;
    state.providerModelError   = '';
    if (resultEl) resultEl.innerHTML = `<div class="provider-test-result ok">✅ ${escHtml(t('providers.save'))}</div>`;
    setTimeout(() => { render(); }, 800);
  } catch (err) {
    if (resultEl) resultEl.innerHTML = `<div class="provider-test-result fail">❌ ${escHtml(err.message)}</div>`;
  }
}

function readProviderRoutingFromDom() {
  const mode = document.getElementById('pr-routing-mode')?.value || 'single-primary';
  const primary = document.getElementById('pr-primary')?.value || '';
  const preferred = document.getElementById('pr-preferred')?.value || '';
  const strategy = document.getElementById('pr-lb-strategy')?.value || 'round-robin';
  const fallbackIds = Array.from(document.querySelectorAll('.pr-fallback'))
    .filter((el) => el.checked)
    .map((el) => el.dataset.providerId)
    .filter(Boolean);
  return {
    routing_mode: mode,
    primary_provider_id: primary || null,
    preferred_provider_id: preferred || null,
    fallback_provider_ids: fallbackIds,
    load_balance_strategy: strategy,
  };
}

function registerAdminHandlers() {
  /* ── Persona ──────────────────────────────────────────────────────────── */
  registerAction('show-persona', ({ element }) => {
    const fn = getViews().showPersonaModal;
    if (fn) fn(element.dataset.personaId);
  });

  registerAction('save-persona-modes', async ({ element }) => {
    const { state, t } = getCtx();
    const personaId = element.dataset.personaId;
    const checkboxes = document.querySelectorAll(`.mode-checkbox[data-persona-id="${CSS.escape(personaId)}"]`);
    const modes = [];
    checkboxes.forEach((cb) => { if (cb.checked) modes.push(cb.dataset.mode); });
    const statusEl = document.getElementById(`mode-status-${personaId}`);
    try {
      const { apiFetch } = getCtx();
      await apiFetch('/api/personas/modes', { method: 'POST', body: JSON.stringify({ persona_id: personaId, modes }) });
      const p = state.personas.find((x) => x.id === personaId);
      if (p) p.available_modes = modes;
      if (statusEl) { statusEl.textContent = t('personas.modesSaved'); statusEl.className = 'mode-save-status ok'; }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 2500);
    } catch (err) {
      if (statusEl) { statusEl.textContent = t('personas.modesError'); statusEl.className = 'mode-save-status fail'; }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 2500);
    }
  });

  registerAction('pm-generate',         () => _generatePersonaMake(false));
  registerAction('pm-generate-improve',  () => _generatePersonaMake(true));
  registerAction('pm-save',             () => _savePersonaMake());
  registerAction('pm-tab', ({ element }) => {
    const { state, render } = getCtx();
    state.personaMaker.previewTab = element.dataset.tab;
    render();
  });

  registerAction('pb-generate-draft', () => _generatePersonaDraft(false));
  registerAction('pb-improve-draft',  () => _generatePersonaDraft(true));
  registerAction('pb-save',           () => _saveCustomPersona());
  registerAction('pb-tab', ({ element }) => {
    const { state, render } = getCtx();
    state.personaBuilder.previewTab = element.dataset.tab;
    render();
  });

  /* ── Providers ────────────────────────────────────────────────────────── */
  registerAction('test-provider', async ({ element }) => {
    const { apiFetch, escHtml, t } = getCtx();
    const providerId = element.dataset.providerId;
    const resultEl   = document.getElementById(`provider-test-result-${providerId}`);
    if (resultEl) resultEl.innerHTML = `<span style="color:var(--text-muted);font-size:12px;">${t('providers.testing')}</span>`;
    try {
      const result = await apiFetch('/api/providers/test', { method: 'POST', body: JSON.stringify({ provider_id: providerId }) });
      if (resultEl) {
        const ok = result.success || result.status === 'ok';
        resultEl.innerHTML = `<div class="provider-test-result ${ok ? 'ok' : 'fail'}">${ok ? '✅ Connected' : '❌ Failed'}: ${escHtml(result.message || result.error || JSON.stringify(result))}</div>`;
      }
    } catch (err) {
      if (resultEl) resultEl.innerHTML = `<div class="provider-test-result fail">❌ ${escHtml(err.message)}</div>`;
    }
  });

  registerAction('edit-provider', ({ element }) => {
    const { state } = getCtx();
    const providerId = element.dataset.providerId;
    const provider   = state.providers.find((p) => p.id === providerId);
    if (!provider) return;
    state.providerModelOptions = [];
    state.providerModelStatus  = null;
    state.providerModelError   = '';
    const formContainer = document.querySelector('#provider-form');
    if (!formContainer) return;
    const formParent = formContainer.parentElement;
    if (formParent) {
      const renderFn = window.DecisionArena.views.shared?.renderProviderForm;
      if (renderFn) { formParent.innerHTML = renderFn(provider); formParent.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }
  });

  registerAction('fetch-provider-models', async () => {
    const { state, apiFetch, escHtml, t } = getCtx();
    const type     = document.getElementById('pf-type')?.value      || '';
    const base_url = document.getElementById('pf-base-url')?.value.trim() || '';
    const api_key  = document.getElementById('pf-api-key')?.value.trim()  || '';
    const statusEl = document.getElementById('provider-model-status');
    state.providerModelStatus = 'loading';
    state.providerModelError  = '';
    if (statusEl) statusEl.innerHTML = `<div class="provider-test-result">${t('providers.fetchingModels')}</div>`;
    try {
      const payload = { type, base_url };
      if (api_key) payload.api_key = api_key;
      const result = await apiFetch('/api/providers/models', { method: 'POST', body: JSON.stringify(payload) });
      state.providerModelOptions = Array.isArray(result.models) ? result.models : [];
      state.providerModelStatus  = 'ok';
      state.providerModelError   = '';
      updateProviderModelSelectDom(state.providerModelOptions);
      const modelInput = document.getElementById('pf-model');
      if (modelInput && !modelInput.value && state.providerModelOptions.length > 0) modelInput.value = state.providerModelOptions[0].id;
      if (statusEl) statusEl.innerHTML = `<div class="provider-test-result ok">✅ ${t('providers.modelsLoaded')}</div>`;
    } catch (err) {
      state.providerModelOptions = [];
      state.providerModelStatus  = 'error';
      state.providerModelError   = err.message;
      updateProviderModelSelectDom([]);
      if (statusEl) statusEl.innerHTML = `<div class="provider-test-result fail">❌ ${escHtml(err.message || t('providers.fetchModelsError'))}</div>`;
    }
  });

  registerAction('refresh-provider-models', async ({ element }) => {
    const { state, apiFetch, escHtml, t } = getCtx();
    const providerId = element.dataset.providerId;
    if (!providerId) return;
    state.providerModelStatus = 'loading';
    state.providerModelError  = '';
    const statusEl = document.getElementById('provider-model-status');
    if (statusEl) statusEl.innerHTML = `<div class="provider-test-result">${t('providers.fetchingModels')}</div>`;
    try {
      const result = await apiFetch('/api/providers/models', { method: 'POST', body: JSON.stringify({ provider_id: providerId }) });
      state.providerModelOptions = Array.isArray(result.models) ? result.models : [];
      state.providerModelStatus  = 'ok';
      state.providerModelError   = '';
      updateProviderModelSelectDom(state.providerModelOptions);
      if (statusEl) statusEl.innerHTML = `<div class="provider-test-result ok">✅ ${t('providers.modelsLoaded')}</div>`;
    } catch (err) {
      state.providerModelOptions = [];
      state.providerModelStatus  = 'error';
      state.providerModelError   = err.message;
      updateProviderModelSelectDom([]);
      if (statusEl) statusEl.innerHTML = `<div class="provider-test-result fail">❌ ${escHtml(err.message || t('providers.fetchModelsError'))}</div>`;
    }
  });

  registerAction('save-provider', async (ctx) => {
    if (ctx.event) ctx.event.preventDefault();
    await doSaveProvider();
  });

  registerAction('save-provider-routing', async () => {
    const { state, render, apiFetch, t } = getCtx();
    state.providerRoutingSaveStatus = null;
    state.providerRoutingSaveMessage = '';
    render();
    try {
      const payload = readProviderRoutingFromDom();
      const saved = await apiFetch('/api/providers/routing', { method: 'PUT', body: JSON.stringify(payload) });
      state.providerRoutingSettings = saved;
      state.providerRoutingSaveStatus = 'success';
      state.providerRoutingSaveMessage = t('providers.routing.saved');
    } catch (err) {
      state.providerRoutingSaveStatus = 'error';
      state.providerRoutingSaveMessage = err.message;
    }
    render();
  });

  registerAction('delete-provider', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const providerId = element.dataset.providerId;
    if (!providerId) return;
    if (!confirm(t('providers.confirmDelete'))) return;
    try {
      await apiFetch(`/api/providers/${providerId}`, { method: 'DELETE' });
      state.providers = state.providers.filter((p) => p.id !== providerId);
      state.error     = null;
      render();
    } catch (err) {
      state.error = err.message;
      render();
    }
  });

  /* ── Logs (admin) ─────────────────────────────────────────────────────── */
  registerAction('logs-refresh', async () => {
    const { state, render, apiFetch } = getCtx();
    state.logs.loading = true;
    state.logs.error = null;
    render();
    try {
      const f = state.logs.filters || {};
      const qs = new URLSearchParams();
      ['level','category','session_id','provider_id','agent_id','from','to','search','limit','offset'].forEach((k) => {
        const v = f[k];
        if (v !== undefined && v !== null && String(v).trim() !== '') qs.set(k, String(v));
      });
      const res = await apiFetch('/api/logs' + (qs.toString() ? `?${qs.toString()}` : ''));
      state.logs.items = Array.isArray(res.logs) ? res.logs : [];
      state.logs.selectedId = null;
      state.logs.selected = null;
    } catch (err) {
      state.logs.error = err.message;
    } finally {
      state.logs.loading = false;
      render();
    }
  });

  registerAction('logs-open', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const id = element.dataset.logId;
    if (!id) return;
    state.logs.selectedId = id;
    state.logs.selected = null;
    render();
    try {
      const res = await apiFetch(`/api/logs/${encodeURIComponent(id)}`);
      state.logs.selected = res.log || null;
    } catch (err) {
      state.logs.error = err.message;
    }
    render();
  });

  registerAction('logs-clear-filters', () => {
    const { state, render } = getCtx();
    state.logs.filters = {
      level: '', category: '', session_id: '', provider_id: '', agent_id: '',
      from: '', to: '', search: '', limit: 100, offset: 0,
    };
    state.logs.exportStatus = null;
    state.logs.maintenanceStatus = null;
    render();
  });

  registerAction('logs-quick-filter', ({ element }) => {
    const { state, render } = getCtx();
    const f = element.dataset.filter;
    if (f === 'llm_requests') { state.logs.filters.category = 'llm_request'; state.logs.filters.level = ''; }
    if (f === 'llm_responses') { state.logs.filters.category = 'llm_response'; state.logs.filters.level = ''; }
    if (f === 'errors') { state.logs.filters.level = 'error'; }
    if (f === 'provider_issues') { state.logs.filters.category = 'provider'; state.logs.filters.level = 'error'; }
    if (f === 'frontend_actions') { state.logs.filters.category = 'ui_action'; state.logs.filters.level = ''; }
    if (f === 'current_session') { state.logs.filters.session_id = state.currentSession?.id || ''; }
    render();
  });

  registerAction('logs-copy', async ({ element }) => {
    const { state } = getCtx();
    const field = element.dataset.copyField;
    const log = state.logs.selected;
    if (!log || !field) return;
    const text = log[field] || '';
    try { await navigator.clipboard.writeText(String(text)); } catch (_) {}
  });

  registerAction('logs-delete-old', async () => {
    const { state, render, apiFetch, t } = getCtx();
    if (!confirm(t('logs.confirmDeleteOld'))) return;
    state.logs.maintenanceStatus = t('logs.deleting');
    render();
    try {
      const res = await apiFetch('/api/logs', { method: 'DELETE', body: JSON.stringify({ older_than_days: 7 }) });
      state.logs.maintenanceStatus = `${t('logs.deleted')}: ${res.deleted || 0}`;
    } catch (err) {
      state.logs.maintenanceStatus = 'Failed: ' + err.message;
    }
    render();
  });

  registerAction('logs-delete-all', async () => {
    const { state, render, apiFetch, t } = getCtx();
    const conf = prompt(t('logs.confirmDeleteAllPrompt'), '');
    if (conf !== 'DELETE') return;
    state.logs.maintenanceStatus = t('logs.deleting');
    render();
    try {
      const res = await apiFetch('/api/logs', { method: 'DELETE', body: JSON.stringify({ confirm: 'DELETE' }) });
      state.logs.maintenanceStatus = `${t('logs.deleted')}: ${res.deleted || 0}`;
      state.logs.items = [];
      state.logs.selectedId = null;
      state.logs.selected = null;
    } catch (err) {
      state.logs.maintenanceStatus = 'Failed: ' + err.message;
    }
    render();
  });

  registerAction('logs-export', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const format = element.dataset.format || 'json';
    state.logs.exportStatus = t('logs.exporting');
    render();
    try {
      const filters = state.logs.filters || {};
      const res = await apiFetch('/api/logs/export', { method: 'POST', body: JSON.stringify({ format, filters }) });
      // Provide content via a download file blob
      const filename = res.filename || (format === 'markdown' ? 'logs.md' : 'logs.json');
      let content = '';
      if (format === 'markdown') content = res.content || '';
      else content = JSON.stringify(res.logs || [], null, 2);
      const blob = new Blob([content], { type: format === 'markdown' ? 'text/markdown' : 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      state.logs.exportStatus = t('logs.exportDone');
    } catch (err) {
      state.logs.exportStatus = 'Failed: ' + err.message;
    }
    render();
  });

  /* ── Templates ────────────────────────────────────────────────────────── */
  registerAction('edit-template', ({ element }) => {
    const { state, navigate } = getCtx();
    const templateId = element.dataset.templateId;
    const template   = state.templates.find((tmpl) => tmpl.id === templateId);
    if (!template) return;
    state.templateMakerData = {
      id: template.id, name: template.name, description: template.description || '',
      mode: template.mode, selectedAgents: [...(template.selected_agents || [])],
      rounds: template.rounds || 2, forceDisagreement: template.force_disagreement || false,
      interactionStyle: template.interaction_style || 'sequential',
      replyPolicy: template.reply_policy || 'all-agents-reply',
      finalSynthesis: template.final_synthesis !== false,
      promptStarter: template.prompt_starter || '', expectedOutput: template.expected_output || '',
      notes: template.notes || '', enabled: template.enabled !== false,
      editingId: template.id, saveStatus: null, saveMessage: '', overwrite: true,
    };
    navigate('template-maker');
  });

  registerAction('duplicate-template', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const templateId = element.dataset.templateId;
    const template   = state.templates.find((t) => t.id === templateId);
    if (!template) return;
    const newId = 'copy-of-' + templateId;
    try {
      const result = await apiFetch(`/api/templates/${templateId}/duplicate`, {
        method: 'POST', body: JSON.stringify({ new_id: newId, name: 'Copy of ' + template.name }),
      });
      if (result.template) { state.templates.push(result.template); render(); }
    } catch (err) {
      state.error = 'Duplicate failed: ' + err.message;
      render();
    }
  });

  registerAction('delete-template', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const templateId = element.dataset.templateId;
    const name       = element.dataset.templateName || '';
    if (!window.confirm(`${t('template.confirmDelete')} "${name}" ?`)) return;
    try {
      await apiFetch(`/api/templates/${templateId}`, { method: 'DELETE' });
      state.templates = state.templates.filter((tmpl) => tmpl.id !== templateId);
      render();
    } catch (err) {
      state.error = 'Delete failed: ' + err.message;
      render();
    }
  });

  registerAction('tmd-toggle-agent', ({ element }) => {
    const { state, render } = getCtx();
    const agentId = element.dataset.agentId;
    const agents  = state.templateMakerData.selectedAgents || [];
    const idx     = agents.indexOf(agentId);
    if (idx >= 0) agents.splice(idx, 1); else agents.push(agentId);
    state.templateMakerData.selectedAgents = agents;
    render();
  });

  registerAction('tmd-save', async () => {
    const { state, render, apiFetch, t } = getCtx();
    const td = state.templateMakerData;
    if (!td.id || !td.name) {
      state.templateMakerData.saveStatus  = 'error';
      state.templateMakerData.saveMessage = 'ID and name are required.';
      render(); return;
    }
    if (!/^[a-z0-9-]+$/.test(td.id)) {
      state.templateMakerData.saveStatus  = 'error';
      state.templateMakerData.saveMessage = 'ID must match /^[a-z0-9-]+$/';
      render(); return;
    }
    const body = {
      id: td.id, name: td.name, description: td.description, mode: td.mode,
      selected_agents: td.selectedAgents,
      rounds: td.mode === 'quick-decision' ? 1 : td.rounds,
      force_disagreement: td.forceDisagreement ? 1 : 0,
      interaction_style: td.interactionStyle, reply_policy: td.replyPolicy,
      final_synthesis: td.finalSynthesis ? 1 : 0,
      prompt_starter: td.promptStarter, expected_output: td.expectedOutput,
      notes: td.notes, enabled: td.enabled ? 1 : 0,
    };
    try {
      const result = td.editingId
        ? await apiFetch(`/api/templates/${td.editingId}`, { method: 'PUT', body: JSON.stringify(body) })
        : await apiFetch('/api/templates', { method: 'POST', body: JSON.stringify(body) });
      const saved = result.template;
      const idx = state.templates.findIndex((tmpl) => tmpl.id === saved.id);
      if (idx >= 0) state.templates[idx] = saved; else state.templates.push(saved);
      state.templateMakerData.saveStatus  = 'success';
      state.templateMakerData.saveMessage = td.editingId ? t('template.savedEdit') : t('template.savedNew');
      state.templateMakerData.editingId   = saved.id;
    } catch (err) {
      state.templateMakerData.saveStatus  = 'error';
      state.templateMakerData.saveMessage = 'Failed: ' + err.message;
    }
    render();
  });

  registerAction('tm-generate', async () => {
    const { state, render, apiFetch } = getCtx();
    const tm = state.templateMaker;
    if (!tm.description?.trim()) { state.templateMaker.error = 'Please describe the template first.'; render(); return; }
    state.templateMaker.isGenerating = true;
    state.templateMaker.error        = null;
    state.templateMaker.result       = null;
    render();
    try {
      const result = await apiFetch('/api/templates/make', {
        method: 'POST',
        body: JSON.stringify({ description: tm.description, provider_id: tm.providerId || null, model: tm.model || null }),
      });
      if (result.error) {
        state.templateMaker.error = result.message;
      } else if (result.template) {
        state.templateMaker.result = result.template;
        const tpl = result.template;
        state.templateMakerData = {
          id: tpl.id || '', name: tpl.name || '', description: tpl.description || '',
          mode: tpl.mode || 'decision-room',
          selectedAgents: Array.isArray(tpl.selected_agents) ? tpl.selected_agents : [],
          rounds: tpl.rounds || 2, forceDisagreement: !!tpl.force_disagreement,
          interactionStyle: tpl.interaction_style || 'sequential',
          replyPolicy: tpl.reply_policy || 'all-agents-reply',
          finalSynthesis: tpl.final_synthesis !== false,
          promptStarter: tpl.prompt_starter || '', expectedOutput: tpl.expected_output || '',
          notes: tpl.notes || '', enabled: true,
          editingId: null, saveStatus: null, saveMessage: '', overwrite: false,
        };
      }
    } catch (err) {
      state.templateMaker.error = err.message;
    } finally {
      state.templateMaker.isGenerating = false;
      render();
    }
  });

  /* ── Provider form submit ─────────────────────────────────────────────── */
  registerSubmit('provider-form', () => doSaveProvider());

  /* ── Provider type change / model select (change listener) ───────────── */
  registerChangeListener((e) => {
    const { state } = getCtx();
    if (e.target.id === 'pf-type') {
      const type     = e.target.value;
      const urlInput = document.getElementById('pf-base-url');
      if (urlInput && !urlInput.value) {
        const defaults = { ollama: 'http://localhost:11434', lmstudio: 'http://localhost:1234', 'openai-compatible': 'https://api.openai.com' };
        if (defaults[type]) urlInput.value = defaults[type];
      }
      state.providerModelOptions = [];
      state.providerModelStatus  = null;
      state.providerModelError   = '';
      return true;
    }
    if (e.target.id === 'pf-model-select') {
      const modelInput = document.getElementById('pf-model');
      if (modelInput && e.target.value) modelInput.value = e.target.value;
      return true;
    }

    // Provider routing settings
    if (['pr-routing-mode', 'pr-primary', 'pr-preferred', 'pr-lb-strategy'].includes(e.target.id) || e.target.classList?.contains('pr-fallback')) {
      state.providerRoutingSettings = readProviderRoutingFromDom();
      state.providerRoutingSaveStatus = null;
      state.providerRoutingSaveMessage = '';
      getCtx().render();
      return true;
    }
    return false;
  });

  /* ── Template Maker Data change/input ────────────────────────────────── */
  registerChangeListener((e) => {
    const { state, render } = getCtx();
    if (e.target.dataset.tmdField) {
      const field = e.target.dataset.tmdField;
      if (!state.templateMakerData) return false;
      if (e.target.type === 'checkbox') state.templateMakerData[field] = e.target.checked;
      else if (e.target.type === 'radio') { state.templateMakerData[field] = e.target.value; render(); }
      else state.templateMakerData[field] = e.target.value;
      return true;
    }
    if (e.target.dataset.tmField) {
      const field = e.target.dataset.tmField;
      if (!state.templateMaker) return false;
      state.templateMaker[field] = e.target.value;
      return true;
    }
    if (e.target.dataset.pmField) {
      const field = e.target.dataset.pmField;
      state.personaMaker[field] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
      return true;
    }
    if (e.target.dataset.pmMode) {
      const mode = e.target.dataset.pmMode;
      if (state.personaMaker.result?.persona) {
        const modes = state.personaMaker.result.persona.available_modes || ['chat', 'decision-room', 'confrontation'];
        if (e.target.checked) { if (!modes.includes(mode)) modes.push(mode); }
        else { const idx = modes.indexOf(mode); if (idx >= 0) modes.splice(idx, 1); }
        state.personaMaker.result.persona.available_modes = modes;
      }
      return true;
    }
    if (e.target.dataset.pbField) {
      const field = e.target.dataset.pbField;
      if (e.target.type === 'checkbox') state.personaBuilder[field] = e.target.checked;
      else state.personaBuilder[field] = e.target.value;
      const previewEl = document.querySelector('.pb-preview-content');
      if (previewEl) {
        const shared = window.DecisionArena.views.shared || {};
        previewEl.textContent = state.personaBuilder.previewTab === 'persona'
          ? (shared.buildPersonaMarkdownPreview?.() || '')
          : (shared.buildSoulMarkdownPreview?.() || '');
      }
      return true;
    }
    return false;
  });

  registerInputListener((e) => {
    const { state, render } = getCtx();
    if (e.target.dataset.tmdField) {
      const field = e.target.dataset.tmdField;
      if (!state.templateMakerData) return false;
      if (e.target.type === 'range') {
        state.templateMakerData[field] = parseInt(e.target.value, 10);
        if (field === 'rounds') {
          const label = document.querySelector('label[for="tmd-rounds"]');
          if (label) label.textContent = `${window.i18n?.t('newSession.rounds') ?? 'Rounds'} (${state.templateMakerData.rounds})`;
        }
      } else { state.templateMakerData[field] = e.target.value; }
      return true;
    }
    if (e.target.dataset.tmField) {
      const field = e.target.dataset.tmField;
      if (!state.templateMaker) return false;
      state.templateMaker[field] = e.target.value;
      return true;
    }
    if (e.target.dataset.pmField) {
      const field = e.target.dataset.pmField;
      state.personaMaker[field] = e.target.value;
      return true;
    }
    if (e.target.dataset.pbField) {
      const field = e.target.dataset.pbField;
      if (e.target.type === 'checkbox') state.personaBuilder[field] = e.target.checked;
      else state.personaBuilder[field] = e.target.value;
      const previewEl = document.querySelector('.pb-preview-content');
      if (previewEl) {
        const shared = window.DecisionArena.views.shared || {};
        previewEl.textContent = state.personaBuilder.previewTab === 'persona'
          ? (shared.buildPersonaMarkdownPreview?.() || '')
          : (shared.buildSoulMarkdownPreview?.() || '');
      }
      return true;
    }
    return false;
  });

  registerInputListener((e) => {
    const { state } = getCtx();
    if (e.target.dataset.logsFilter) {
      const key = e.target.dataset.logsFilter;
      state.logs.filters[key] = e.target.value;
      return true;
    }
    return false;
  });

  registerChangeListener((e) => {
    const { state, render } = getCtx();
    if (e.target.dataset.logsFilter) {
      const key = e.target.dataset.logsFilter;
      state.logs.filters[key] = e.target.value;
      render();
      return true;
    }
    return false;
  });
}

/* ── Private helpers ───────────────────────────────────────────────────── */

async function _generatePersonaMake(improve) {
  const { state, render, apiFetch } = getCtx();
  const pm = state.personaMaker;
  if (!pm.description.trim()) { state.personaMaker.error = 'Please describe the persona first.'; render(); return; }
  state.personaMaker.isGenerating = true;
  state.personaMaker.error        = null;
  render();
  try {
    const body = { description: pm.description, provider_id: pm.providerId || null, model: pm.model.trim() || null };
    const result = await apiFetch('/api/personas/make', { method: 'POST', body: JSON.stringify(body) });
    if (result.error) { state.personaMaker.error = result.message || 'Generation failed.'; }
    else { state.personaMaker.result = result; state.personaMaker.error = null; }
  } catch (err) { state.personaMaker.error = 'Request failed: ' + err.message; }
  finally { state.personaMaker.isGenerating = false; render(); }
}

async function _savePersonaMake() {
  const { state, render, apiFetch, PersonaService } = getCtx();
  const pm = state.personaMaker;
  if (!pm.result?.persona) return;
  const persona = pm.result.persona;
  const soul    = pm.result.soul;
  if (!persona.id) { state.personaMaker.saveStatus = 'error'; state.personaMaker.saveMessage = 'Persona ID is missing.'; render(); return; }
  try {
    const result = await apiFetch('/api/personas/save-custom', {
      method: 'POST',
      body: JSON.stringify({
        persona: {
          id: persona.id, name: persona.name, title: persona.title, icon: persona.icon,
          tags: persona.tags, available_modes: persona.available_modes || ['chat', 'decision-room', 'confrontation'],
          role: persona.role, when_to_use: persona.when_to_use, style: persona.style,
          identity: persona.identity, focus: persona.focus, core_principles: persona.core_principles,
          capabilities: persona.capabilities, constraints: persona.constraints,
          default_response_format: persona.default_response_format,
          system_instructions: persona.system_instructions,
          default_provider: pm.providerId || '', default_model: pm.model || '',
        },
        soul, overwrite: pm.overwrite,
      }),
    });
    state.personaMaker.saveStatus  = 'success';
    state.personaMaker.saveMessage = result.message || `Persona "${persona.id}" saved.`;
    const data = await PersonaService.list();
    state.personas = Array.isArray(data) ? data : (data.personas || []);
  } catch (err) { state.personaMaker.saveStatus = 'error'; state.personaMaker.saveMessage = err.message; }
  render();
}

async function _generatePersonaDraft(improve) {
  const { state, render, apiFetch } = getCtx();
  const pb = state.personaBuilder;
  if (!pb.description.trim()) { state.personaBuilder.generationError = 'Please describe the persona first.'; render(); return; }
  state.personaBuilder.isGenerating    = true;
  state.personaBuilder.generationError = null;
  render();
  try {
    const body = { description: pb.description, provider_id: pb.defaultProvider || null };
    if (improve && pb.id) {
      const shared = window.DecisionArena.views.shared || {};
      body.existing_persona = shared.buildPersonaMarkdownPreview?.() || '';
      body.existing_soul    = shared.buildSoulMarkdownPreview?.()    || '';
    }
    const result  = await apiFetch('/api/personas/build-draft', { method: 'POST', body: JSON.stringify(body) });
    const persona = result.persona || {};
    const soul    = result.soul    || {};
    Object.assign(state.personaBuilder, {
      id: persona.id || pb.id, name: persona.name || pb.name, title: persona.title || pb.title,
      icon: persona.icon || pb.icon,
      tags: Array.isArray(persona.tags) ? persona.tags.join(', ') : (persona.tags || pb.tags),
      role: persona.role || pb.role, whenToUse: persona.when_to_use || pb.whenToUse,
      style: persona.style || pb.style, identity: persona.identity || pb.identity,
      focus: persona.focus || pb.focus, corePrinciples: persona.core_principles || pb.corePrinciples,
      capabilities: persona.capabilities || pb.capabilities, constraints: persona.constraints || pb.constraints,
      defaultResponseFormat: persona.default_response_format || pb.defaultResponseFormat,
      systemInstructions: persona.system_instructions || pb.systemInstructions,
      personality: soul.personality || pb.personality,
      behavioralRules: soul.behavioral_rules || pb.behavioralRules,
      reasoningStyle: soul.reasoning_style || pb.reasoningStyle,
      communicationStyle: soul.communication_style || pb.communicationStyle,
      defaultBias: soul.default_bias || pb.defaultBias,
      challengeLevel: soul.challenge_level || pb.challengeLevel,
      outputPreferences: soul.output_preferences || pb.outputPreferences,
      guardrails: soul.guardrails || pb.guardrails,
    });
  } catch (err) { state.personaBuilder.generationError = 'Generation failed: ' + err.message; }
  finally { state.personaBuilder.isGenerating = false; render(); }
}

async function _saveCustomPersona() {
  const { state, render, apiFetch, PersonaService } = getCtx();
  const pb = state.personaBuilder;
  if (!pb.id) { state.personaBuilder.saveStatus = 'error'; state.personaBuilder.saveMessage = 'Persona ID is required.'; render(); return; }
  const tagsArr    = pb.tags ? pb.tags.split(',').map((s) => s.trim()).filter(Boolean) : [];
  const personaData = {
    id: pb.id, name: pb.name, title: pb.title, icon: pb.icon, tags: tagsArr,
    default_provider: pb.defaultProvider || null, default_model: pb.defaultModel || null,
    enabled: pb.enabled, role: pb.role, when_to_use: pb.whenToUse, style: pb.style,
    identity: pb.identity, focus: pb.focus, core_principles: pb.corePrinciples,
    capabilities: pb.capabilities, constraints: pb.constraints,
    default_response_format: pb.defaultResponseFormat, system_instructions: pb.systemInstructions,
  };
  const soulData = {
    personality: pb.personality, behavioral_rules: pb.behavioralRules,
    reasoning_style: pb.reasoningStyle, communication_style: pb.communicationStyle,
    default_bias: pb.defaultBias, challenge_level: pb.challengeLevel,
    output_preferences: pb.outputPreferences, guardrails: pb.guardrails,
  };
  try {
    const result = await apiFetch('/api/personas/save-custom', {
      method: 'POST', body: JSON.stringify({ persona: personaData, soul: soulData, overwrite: pb.overwrite }),
    });
    state.personaBuilder.saveStatus  = 'success';
    state.personaBuilder.saveMessage = result.message || 'Persona saved successfully.';
    const data = await PersonaService.list();
    state.personas = Array.isArray(data) ? data : (data.personas || []);
  } catch (err) { state.personaBuilder.saveStatus = 'error'; state.personaBuilder.saveMessage = 'Request failed: ' + err.message; }
  render();
}

/* ── Scenario Packs handlers ── */

function registerScenarioPackAdminHandlers() {
  function spCtx() {
    const a = window.DecisionArena;
    return {
      state:                a.store.state,
      render:               () => a.render?.(),
      navigate:             (v) => a.router.navigate(v),
      ScenarioPackService:  a.services.ScenarioPackService,
      t:                    (key) => window.i18n?.t(key) ?? key,
    };
  }

  async function reloadPacks(state, ScenarioPackService) {
    try {
      const data = await ScenarioPackService.list(true);
      state.scenarioPacksAdmin = Array.isArray(data) ? data : [];
      // Also update the public list used by New Session
      const pub = await ScenarioPackService.list(false);
      state.scenarioPacks = Array.isArray(pub) ? pub : [];
    } catch (_) {}
  }

  registerAction('load-scenario-packs-admin', async () => {
    const { state, render, ScenarioPackService } = spCtx();
    await reloadPacks(state, ScenarioPackService);
    render();
  });

  registerAction('new-scenario-pack', () => {
    const { state, render } = spCtx();
    state.scenarioPackShowForm  = true;
    state.scenarioPackEditing   = null;
    render();
  });

  registerAction('cancel-scenario-pack-form', () => {
    const { state, render } = spCtx();
    state.scenarioPackShowForm = false;
    state.scenarioPackEditing  = null;
    render();
  });

  registerAction('save-scenario-pack', async ({ element }) => {
    const { state, render, ScenarioPackService, t } = spCtx();
    const existingId = element?.dataset?.scenarioId || '';
    const form       = document.getElementById('scenario-pack-form');
    if (!form) return;

    const id    = (form.querySelector('#sp-id')?.value || '').trim();
    const name  = (form.querySelector('#sp-name')?.value || '').trim();
    const mode  = form.querySelector('#sp-mode')?.value || 'decision-room';

    if (!id || !name) {
      const res = document.getElementById('scenario-pack-form-result');
      if (res) res.innerHTML = `<span style="color:var(--danger);">⚠️ ID et nom requis.</span>`;
      return;
    }

    const personas   = (form.querySelector('#sp-personas')?.value || '').split(',').map((s) => s.trim()).filter(Boolean);
    const rounds     = parseInt(form.querySelector('#sp-rounds')?.value || '2', 10);
    const threshold  = parseFloat(form.querySelector('#sp-threshold')?.value || '0.55');
    const force      = form.querySelector('#sp-force')?.checked || false;
    const desc       = form.querySelector('#sp-desc')?.value || '';
    const target     = form.querySelector('#sp-target')?.value || '';
    const prompt     = form.querySelector('#sp-prompt')?.value || '';

    const payload = { id, name, description: desc, target_profile: target,
                      recommended_mode: mode, persona_ids: personas, rounds,
                      force_disagreement: force, decision_threshold: threshold,
                      prompt_starter: prompt };

    try {
      if (existingId) {
        await ScenarioPackService.update(existingId, payload);
      } else {
        await ScenarioPackService.create(payload);
      }
      state.scenarioPackShowForm = false;
      state.scenarioPackEditing  = null;
      await reloadPacks(state, ScenarioPackService);
    } catch (err) {
      const res = document.getElementById('scenario-pack-form-result');
      if (res) res.innerHTML = `<span style="color:var(--danger);">❌ ${err.message}</span>`;
    }
    render();
  });

  registerAction('edit-scenario-pack', ({ element }) => {
    const { state, render } = spCtx();
    const packId = element?.dataset?.scenarioId;
    const pack   = (state.scenarioPacksAdmin || state.scenarioPacks || []).find((p) => p.id === packId);
    if (!pack) return;
    state.scenarioPackEditing  = pack;
    state.scenarioPackShowForm = true;
    render();
    requestAnimationFrame(() => {
      document.getElementById('scenario-pack-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  registerAction('duplicate-scenario-pack', async ({ element }) => {
    const { state, render, ScenarioPackService, t } = spCtx();
    const packId = element?.dataset?.scenarioId;
    if (!packId) return;
    const newId = 'copy-of-' + packId + '-' + Date.now().toString(36);
    try {
      await ScenarioPackService.duplicate(packId, { new_id: newId });
      await reloadPacks(state, ScenarioPackService);
    } catch (err) {
      console.error('Duplicate scenario pack failed:', err.message);
    }
    render();
  });

  registerAction('delete-scenario-pack', async ({ element }) => {
    const { state, render, ScenarioPackService, t } = spCtx();
    const packId   = element?.dataset?.scenarioId;
    const packName = element?.dataset?.scenarioName || packId;
    if (!packId) return;
    if (!window.confirm(`${t('scenario.admin.delete')} "${packName}" ?`)) return;
    try {
      await ScenarioPackService.remove(packId);
      await reloadPacks(state, ScenarioPackService);
    } catch (err) {
      console.error('Delete scenario pack failed:', err.message);
    }
    render();
  });
}

export { registerAdminHandlers, registerScenarioPackAdminHandlers };

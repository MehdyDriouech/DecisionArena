/* Admin feature — action handlers and change/input listeners for providers, personas, templates */
import { registerAction, registerChangeListener, registerInputListener, registerSubmit } from '../../core/events.js';
import { normalizeDecisionDynamics } from '../../utils/decisionDynamics.js';
import { updateProviderSettings, deleteProviderKey, maskProviderKey } from '../../core/store.js';
import { withProviderRuntime } from '../../core/providerRuntime.js';
import { getAvailableProviders } from '../../core/providerRouting.js';
import { testProviderConnection } from '../../services/providerService.js';

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

function slugifyLocalProviderId(raw) {
  const s = String(raw || '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return s || 'serveur';
}

/** IDs uniques (tous providers serveur). L’entrée en cours d’édition est exclue des collisions pour un renommage. */
function allocateLocalProviderId({ name, type, originalId, allProviders }) {
  const typeSlug =
    String(type || 'ollama')
      .toLowerCase()
      .replace(/[^a-z0-9-]/g, '-') || 'ollama';
  const baseSlug = name.trim() ? slugifyLocalProviderId(name) : typeSlug;
  let base = `local-${baseSlug}`;
  const ids = new Set(
    (allProviders || []).filter((p) => !originalId || p.id !== originalId).map((p) => p.id),
  );
  let candidate = base;
  let n = 2;
  while (ids.has(candidate) && candidate !== originalId) {
    candidate = `${base}-${n++}`;
  }
  return candidate;
}

async function doSaveProvider() {
  const { state, render, apiFetch, escHtml, t } = getCtx();
  const originalId = document.getElementById('pf-original-id')?.value.trim() || '';
  let id = document.getElementById('pf-id')?.value.trim() || '';
  let name = document.getElementById('pf-name')?.value.trim() || '';
  const type = document.getElementById('pf-type')?.value;
  const base_url = document.getElementById('pf-base-url')?.value.trim();
  const api_key = document.getElementById('pf-api-key')?.value.trim();
  const default_model = document.getElementById('pf-model')?.value.trim();
  const enabled = document.getElementById('pf-enabled')?.checked ?? true;
  const priority = parseInt(document.getElementById('pf-priority')?.value ?? '100', 10);
  const resultEl = document.getElementById('provider-form-result');
  const allProv = state.providers || [];

  if (!base_url) {
    if (resultEl)
      resultEl.innerHTML = `<div class="provider-test-result fail">${escHtml(t('providers.fieldBaseUrl'))} requis.</div>`;
    return;
  }

  if (!id) {
    id = allocateLocalProviderId({ name, type, originalId, allProviders: allProv });
  }

  if (id !== originalId && allProv.some((p) => p.id === id)) {
    if (resultEl)
      resultEl.innerHTML = `<div class="provider-test-result fail">Un provider local utilise déjà l’identifiant <strong>${escHtml(id)}</strong>. Choisissez un autre ID.</div>`;
    return;
  }

  if (!name) name = id;

  try {
    const body = {
      id,
      name,
      type,
      base_url,
      default_model,
      enabled,
      priority: Number.isFinite(priority) ? priority : 100,
    };
    if (api_key) body.api_key = api_key;

    if (originalId && originalId !== id) {
      await apiFetch(`/api/providers/${encodeURIComponent(originalId)}`, { method: 'DELETE' });
    }
    const result = await apiFetch('/api/providers', { method: 'POST', body: JSON.stringify(body) });
    const existingIdx = state.providers.findIndex((p) => p.id === result.id);
    if (existingIdx >= 0) state.providers[existingIdx] = result;
    else state.providers.push(result);
    state.providerModelOptions = [];
    state.providerModelStatus = null;
    state.providerModelError = '';
    if (resultEl) resultEl.innerHTML = `<div class="provider-test-result ok">✅ ${escHtml(t('providers.save'))}</div>`;
    const localDlg = document.getElementById('provider-local-modal');
    if (localDlg && typeof localDlg.close === 'function') localDlg.close();
    setTimeout(() => {
      render();
    }, 800);
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

/** Local BYOK (browser store) provider ids matching data-provider on cards. */
const ALLOWED_BYOK = new Set(['openai', 'anthropic', 'mistral', 'openrouter']);

const BYOK_UI_LABELS = {
  openai: 'OpenAI',
  anthropic: 'Anthropic',
  mistral: 'Mistral AI',
  openrouter: 'OpenRouter',
};

const BYOK_DEFAULT_BASE_URL = {
  openai: 'https://api.openai.com/v1',
  anthropic: 'https://api.anthropic.com',
  mistral: 'https://api.mistral.ai/v1',
  openrouter: 'https://openrouter.ai/api/v1',
};

function normalizeByokProvider(raw) {
  const id = typeof raw === 'string' ? raw.trim().toLowerCase() : '';
  return ALLOWED_BYOK.has(id) ? id : null;
}

/**
 * Feedback BYOK liste rapide (+ optionnel modal BYOK pendant que le dialogue est ouvert).
 * @param {string} provider
 * @param {string} message
 * @param {'ok'|'fail'|'pending'} kind
 * @param {{ modalOnly?: boolean }} [options]
 */
function setByokFeedback(provider, message, kind, options = {}) {
  const modalOnly = options.modalOnly === true;
  const esc = CSS.escape(provider);
  const row = document.querySelector(`.byok-quick-row[data-byok-provider="${esc}"]`);

  function apply(el) {
    if (!el) return;
    el.textContent = message || '';
    el.hidden = !message;
    const isModalSlot = el.dataset.feedbackSlot === 'modal' || el.classList.contains('byok-modal-feedback');
    el.className = isModalSlot
      ? 'byok-modal-feedback provider-test-result'
      : 'byok-feedback provider-test-result byok-quick-feedback';
    if (kind === 'ok') el.classList.add('ok');
    else if (kind === 'fail') el.classList.add('fail');
    else if (kind === 'pending') el.classList.add('pending');
  }

  if (!modalOnly) {
    apply(row?.querySelector('.byok-feedback'));
  }

  const dlg = document.getElementById('provider-byok-modal');
  if (dlg && dlg.dataset.openProvider === provider && (modalOnly || dlg.open)) {
    apply(dlg.querySelector('.byok-modal-feedback'));
  }
}

function resolveByokApiKeyInput(provider, input, state) {
  const storeKey =
    typeof state.providerSettings?.[provider]?.apiKey === 'string'
      ? state.providerSettings[provider].apiKey.trim()
      : '';
  if (!input) return storeKey;
  const typed = (input.value || '').trim();
  if (input.dataset.byokMaskOnly === '1') return storeKey;
  if (typed && storeKey && typed === maskProviderKey(storeKey)) return storeKey;
  if (typed) return typed;
  return storeKey;
}

/** Types « serveur » (même jeu que l’écran admin / formulaire provider). */
const LOCAL_SERVER_PROVIDER_TYPES = new Set(['ollama', 'lmstudio', 'openai-compatible']);

function sortedLocalServerProviders(providerList) {
  return (providerList || [])
    .filter((p) => p && LOCAL_SERVER_PROVIDER_TYPES.has(p.type))
    .slice()
    .sort((a, b) => {
      const pa = Number(a.priority ?? 100);
      const pb = Number(b.priority ?? 100);
      if (pa !== pb) return pa - pb;
      return String(a.id).localeCompare(String(b.id));
    });
}

/** Injecte `renderProviderForm` dans le modal « Provider local ». */
function ensurePersonaSandboxState(state) {
  if (!state.personaSandbox) {
    state.personaSandbox = {
      prompt: '',
      personaId: '',
      providerId: '',
      model: '',
      temperature: '',
      compareMode: 'single',
      comparePersonaIds: [],
      compareProviderIds: [],
      compareModelsText: '',
      loading: false,
      error: null,
      results: [],
    };
  }
  return state.personaSandbox;
}

function syncPersonaSandboxFromDom(state) {
  const sb = ensurePersonaSandboxState(state);
  document.querySelectorAll('[data-ps-field]').forEach((el) => {
    const field = el.dataset.psField;
    if (!field) return;
    sb[field] = el.type === 'checkbox' ? el.checked : el.value;
  });
  for (const listName of ['comparePersonaIds', 'compareProviderIds']) {
    sb[listName] = Array.from(document.querySelectorAll(`[data-ps-list="${listName}"]`))
      .filter((el) => el.checked)
      .map((el) => el.value)
      .filter(Boolean);
  }
  return sb;
}

function splitSandboxModels(raw) {
  return String(raw || '')
    .split(/[\n,]+/)
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, 6);
}

function buildPersonaSandboxRuns(state) {
  const sb = syncPersonaSandboxFromDom(state);
  const personas = state.personas || [];
  const providers = getAvailableProviders(state);
  const basePersonaId = sb.personaId || personas[0]?.id || '';
  const baseProviderId = sb.providerId || '';
  const baseModel = String(sb.model || '').trim();
  const temperature = String(sb.temperature || '').trim();
  const base = {
    persona_id: basePersonaId,
    provider_id: baseProviderId || null,
    model: baseModel || null,
    temperature: temperature || null,
  };

  if (sb.compareMode === 'persona') {
    const ids = (sb.comparePersonaIds?.length ? sb.comparePersonaIds : personas.slice(0, 2).map((p) => p.id)).slice(0, 6);
    return ids.map((personaId) => ({ ...base, persona_id: personaId }));
  }
  if (sb.compareMode === 'provider') {
    const ids = (sb.compareProviderIds?.length ? sb.compareProviderIds : providers.slice(0, 2).map((p) => p.id)).slice(0, 6);
    return ids.map((providerId) => ({ ...base, provider_id: providerId }));
  }
  if (sb.compareMode === 'model') {
    const models = splitSandboxModels(sb.compareModelsText || baseModel);
    return (models.length ? models : [baseModel]).filter(Boolean).slice(0, 6).map((model) => ({ ...base, model }));
  }
  return [base];
}

function mountLocalServerProviderIntoModal(provider) {
  const renderFn = window.DecisionArena.views.shared?.renderProviderForm;
  const host = document.getElementById('provider-local-modal-form-host');
  const dlg = document.getElementById('provider-local-modal');
  const titleEl = document.getElementById('provider-local-modal-title');
  if (!renderFn || !host || !dlg || typeof dlg.showModal !== 'function') return false;

  const { state } = getCtx();
  state.providerModelOptions = [];
  state.providerModelStatus = null;
  state.providerModelError = '';

  host.innerHTML = renderFn(provider ?? null);
  if (titleEl) {
    titleEl.textContent =
      provider?.id ? `Modifier · ${provider.name || provider.id}` : 'Configurer un provider local';
  }
  dlg.showModal();
  requestAnimationFrame(() => {
    document.getElementById('pf-id')?.focus?.();
  });
  return true;
}

function registerAdminHandlers() {
  /* ── Persona ──────────────────────────────────────────────────────────── */
  registerAction('show-persona', ({ element }) => {
    const fn = getViews().showPersonaModal;
    if (fn) fn(element.dataset.personaId);
  });

  registerAction('run-persona-sandbox', async () => {
    const { state, render, apiFetch } = getCtx();
    const sb = syncPersonaSandboxFromDom(state);
    const prompt = String(sb.prompt || '').trim();
    const runs = buildPersonaSandboxRuns(state);
    if (!prompt) {
      sb.error = 'Prompt utilisateur requis.';
      sb.results = [];
      render();
      return;
    }
    if (!runs.length || !runs[0]?.persona_id) {
      sb.error = 'Selectionnez au moins une persona.';
      sb.results = [];
      render();
      return;
    }
    sb.loading = true;
    sb.error = null;
    sb.results = [];
    render();
    try {
      const result = await apiFetch('/api/personas/sandbox-test', {
        method: 'POST',
        body: JSON.stringify(withProviderRuntime({
          prompt,
          language: state.newSession?.language || 'fr',
          runs,
        })),
      });
      sb.results = Array.isArray(result.runs) ? result.runs : [];
      sb.error = result.error ? (result.message || 'Sandbox request failed.') : null;
    } catch (err) {
      sb.error = err.message || 'Sandbox request failed.';
      sb.results = [];
    } finally {
      sb.loading = false;
      render();
    }
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

  registerAction('update-persona-dynamics', ({ element }) => {
    const { state, render } = getCtx();
    const personaId = element?.dataset?.personaId ?? '';
    const field = element?.dataset?.field ?? '';
    if (!personaId || !field) return;
    const p = state.personas.find((x) => x.id === personaId);
    if (!p) return;
    const cur = normalizeDecisionDynamics(p.decision_dynamics);
    const rawVal = element.value;
    const next = { ...cur };
    if (field === 'reputation') {
      const n = parseFloat(rawVal);
      next.reputation = Number.isFinite(n) ? n : cur.reputation;
    } else if (field === 'consensus_resistance') {
      next.consensus_resistance = rawVal;
    } else if (field === 'evidence_sensitivity') {
      next.evidence_sensitivity = rawVal;
    } else if (field === 'risk_tolerance') {
      next.risk_tolerance = rawVal;
    } else {
      return;
    }
    p.decision_dynamics = normalizeDecisionDynamics(next);
    render();
  });

  async function persistPersonaDecisionDynamics(personaId) {
    const { state, render, apiFetch, t } = getCtx();
    if (!personaId) return;
    const statusEl = document.getElementById(`dd-dyn-status-${personaId}`);
    const p = state.personas.find((x) => x.id === personaId);
    const fromDom = () => {
      const rep = parseFloat(document.querySelector(`select.dd-reputation-select[data-persona-id="${CSS.escape(personaId)}"]`)?.value ?? '');
      const consensus = document.querySelector(`select.dd-consensus-select[data-persona-id="${CSS.escape(personaId)}"]`)?.value ?? '';
      const evidence = document.querySelector(`select.dd-evidence-select[data-persona-id="${CSS.escape(personaId)}"]`)?.value ?? '';
      const risk = document.querySelector(`select.dd-risk-select[data-persona-id="${CSS.escape(personaId)}"]`)?.value ?? '';
      if (p?.decision_dynamics) {
        return normalizeDecisionDynamics({
          ...p.decision_dynamics,
          reputation: Number.isFinite(rep) ? rep : undefined,
          consensus_resistance: consensus || undefined,
          evidence_sensitivity: evidence || undefined,
          risk_tolerance: risk || undefined,
        });
      }
      return normalizeDecisionDynamics({
        reputation: Number.isFinite(rep) ? rep : 1,
        consensus_resistance: consensus || 'normal',
        evidence_sensitivity: evidence || 'normal',
        risk_tolerance: risk || 'balanced',
      });
    };
    const normalized = fromDom();
    try {
      const res = await apiFetch('/api/personas/decision-dynamics', {
        method: 'POST',
        body: JSON.stringify({
          persona_id: personaId,
          decision_dynamics: normalized,
        }),
      });
      const norm = res.decision_dynamics || normalized;
      if (p) p.decision_dynamics = norm;
      if (statusEl) { statusEl.textContent = t('admin.dynamics.saved'); statusEl.className = 'mode-save-status ok'; }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 2500);
      render();
    } catch (err) {
      if (statusEl) { statusEl.textContent = t('admin.dynamics.error'); statusEl.className = 'mode-save-status fail'; }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3500);
    }
  }

  registerAction('save-persona-decision-dynamics', async ({ element }) => {
    await persistPersonaDecisionDynamics(element?.dataset?.personaId || '');
  });

  registerAction('save-persona-dynamics', async ({ element }) => {
    await persistPersonaDecisionDynamics(element?.dataset?.personaId || '');
  });

  registerAction('load-dynamics-reco-admin', async () => {
    const { state, render, apiFetch } = getCtx();
    state.adminDynamicsReco = { loading: true, suggestions: [] };
    render();
    try {
      const res = await apiFetch('/api/analysis/agent-dynamics-suggestions');
      state.adminDynamicsReco = res;
    } catch (err) {
      state.adminDynamicsReco = {
        error: err.message || String(err),
        suggestions: [],
        disclaimer: '',
      };
    }
    render();
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
    const provider = state.providers.find((p) => p.id === providerId);
    if (!provider) return;
    if (!LOCAL_SERVER_PROVIDER_TYPES.has(provider.type)) return;
    mountLocalServerProviderIntoModal(provider);
  });

  registerAction('open-local-provider-modal', ({ element }) => {
    const { state } = getCtx();
    const providerId = typeof element?.dataset?.providerId === 'string' ? element.dataset.providerId.trim() : '';
    const provider = providerId ? state.providers.find((p) => p.id === providerId) : null;
    mountLocalServerProviderIntoModal(
      provider && LOCAL_SERVER_PROVIDER_TYPES.has(provider.type) ? provider : null,
    );
  });

  registerAction('close-local-provider-modal', () => {
    const dlg = document.getElementById('provider-local-modal');
    if (dlg && typeof dlg.close === 'function') dlg.close();
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

  /* ── BYOK (clés API locales, store navigateur) ────────────────────────── */
  registerAction('open-provider-modal', ({ element }) => {
    const provider = normalizeByokProvider(element?.dataset?.provider);
    const modeRaw = typeof element?.dataset?.modalMode === 'string' ? element.dataset.modalMode : 'connect';
    const mode = modeRaw === 'edit' ? 'edit' : 'connect';
    if (!provider) return;
    const { state } = getCtx();
    const dlg = document.getElementById('provider-byok-modal');
    if (!dlg || typeof dlg.showModal !== 'function') return;

    dlg.dataset.openProvider = provider;
    dlg.dataset.modalMode = mode;

    const titleEl = dlg.querySelector('#provider-byok-modal-title');
    const nm = BYOK_UI_LABELS[provider] || provider;
    if (titleEl) titleEl.textContent = mode === 'edit' ? `Modifier — ${nm}` : `Connecter — ${nm}`;

    const row = state.providerSettings?.[provider] || {};
    const rawKey = typeof row.apiKey === 'string' ? row.apiKey : '';
    const hasKey = rawKey.trim() !== '';

    const keyInput = document.getElementById('byok-modal-api-key');
    if (keyInput) {
      keyInput.value = '';
      keyInput.dataset.provider = provider;
      delete keyInput.dataset.byokMaskOnly;
    }

    const baseInput = document.getElementById('byok-modal-base-url');
    if (baseInput) {
      const savedBase = typeof row.baseUrl === 'string' ? row.baseUrl.trim() : '';
      baseInput.value = savedBase || BYOK_DEFAULT_BASE_URL[provider] || '';
      baseInput.dataset.provider = provider;
    }

    const prioEl = document.getElementById('byok-modal-priority');
    if (prioEl) {
      const pr = row.priority;
      const n = typeof pr === 'number' && Number.isFinite(pr) ? pr : parseInt(String(pr ?? '100'), 10);
      prioEl.value = String(Number.isFinite(n) ? n : 100);
      prioEl.dataset.provider = provider;
    }

    const dmEl = document.getElementById('byok-modal-default-model');
    if (dmEl) {
      dmEl.value = typeof row.defaultModel === 'string' ? row.defaultModel : '';
      dmEl.dataset.provider = provider;
    }

    const disc = document.getElementById('byok-modal-disconnect');
    if (disc) {
      disc.dataset.provider = provider;
      disc.hidden = !(mode === 'edit' && hasKey);
    }

    const feedback = dlg.querySelector('.byok-modal-feedback');
    if (feedback) {
      feedback.textContent = '';
      feedback.hidden = true;
      feedback.className = 'byok-modal-feedback provider-test-result';
    }

    const submitBtn = dlg.querySelector('[data-action="save-provider-key"]');
    if (submitBtn) submitBtn.textContent = mode === 'edit' ? 'Enregistrer' : 'Connecter';

    dlg.showModal();
    requestAnimationFrame(() => keyInput?.focus?.());
  });

  registerAction('close-provider-modal', () => {
    const dlg = document.getElementById('provider-byok-modal');
    if (!dlg || typeof dlg.close !== 'function') return;
    delete dlg.dataset.openProvider;
    delete dlg.dataset.modalMode;
    dlg.close();
  });

  registerAction('save-provider-key', async ({ element }) => {
    const dlg = element.closest('#provider-byok-modal');
    const provider =
      normalizeByokProvider(element?.dataset?.provider) ||
      normalizeByokProvider(dlg?.dataset?.openProvider);
    if (!provider) return;

    const { render, state } = getCtx();
    const fromModal = !!dlg;
    const modalMode = dlg?.dataset?.modalMode === 'edit' ? 'edit' : 'connect';

    const input = fromModal
      ? document.getElementById('byok-modal-api-key')
      : document.querySelector(
          `.byok-quick-row[data-byok-provider="${CSS.escape(provider)}"] input[data-action="provider-key-input"][data-provider="${CSS.escape(provider)}"]`,
        );

    const baseEl = fromModal
      ? document.getElementById('byok-modal-base-url')
      : document.querySelector(
          `.byok-quick-row[data-byok-provider="${CSS.escape(provider)}"] input.byok-base-url-input[data-provider="${CSS.escape(provider)}"]`,
        );

    const prioEl = fromModal ? document.getElementById('byok-modal-priority') : null;
    const dmEl = fromModal ? document.getElementById('byok-modal-default-model') : null;

    const trimmed = input?.value?.trim() ?? '';

    const patch = { enabled: true };
    if (baseEl && typeof baseEl.value === 'string') {
      const b = baseEl.value.trim();
      if (b) patch.baseUrl = b;
    }
    if (prioEl && prioEl.value !== '') {
      const pr = parseInt(prioEl.value, 10);
      if (Number.isFinite(pr)) patch.priority = pr;
    }
    if (dmEl && typeof dmEl.value === 'string') {
      patch.defaultModel = dmEl.value.trim();
    }

    /* Connexion rapide (hors modal) : exiger une clé. */
    if (!fromModal && !trimmed) {
      setByokFeedback(provider, 'Saisissez une clé API avant d\'enregistrer.', 'fail');
      return;
    }

    /* Modal « modifier » : enregistrer priorité / modèle / URL sans ressaisir la clé. */
    if (fromModal && modalMode === 'edit' && !trimmed) {
      updateProviderSettings(provider, patch);
      const dlgClose = document.getElementById('provider-byok-modal');
      if (dlgClose && typeof dlgClose.close === 'function') {
        delete dlgClose.dataset.openProvider;
        delete dlgClose.dataset.modalMode;
        dlgClose.close();
      }
      render();
      requestAnimationFrame(() => setByokFeedback(provider, 'Paramètres enregistrés.', 'ok'));
      return;
    }

    if (!trimmed) {
      if (fromModal) setByokFeedback(provider, 'Saisissez une clé API.', 'fail', { modalOnly: true });
      else setByokFeedback(provider, 'Saisissez une clé API avant d\'enregistrer.', 'fail');
      return;
    }

    patch.apiKey = trimmed;

    updateProviderSettings(provider, patch);

    const baseUrlAfter =
      (typeof state.providerSettings?.[provider]?.baseUrl === 'string'
        ? state.providerSettings[provider].baseUrl.trim()
        : '') ||
      BYOK_DEFAULT_BASE_URL[provider] ||
      '';

    if (!fromModal) {
      render();
      requestAnimationFrame(async () => {
        setByokFeedback(provider, 'Vérification de la connexion…', 'pending');
        const testRes = await testProviderConnection(provider, { apiKey: trimmed, baseUrl: baseUrlAfter });
        const msg = testRes.ok ? testRes.message : `Enregistré — ${testRes.message}`;
        setByokFeedback(provider, msg, testRes.ok ? 'ok' : 'fail');
      });
      return;
    }

    setByokFeedback(provider, 'Vérification de la connexion…', 'pending', { modalOnly: true });
    const testRes = await testProviderConnection(provider, { apiKey: trimmed, baseUrl: baseUrlAfter });
    const msg = testRes.ok ? `Connecté · ${testRes.message}` : `Enregistré · ${testRes.message}`;
    setByokFeedback(provider, msg, testRes.ok ? 'ok' : 'fail', { modalOnly: true });

    if (testRes.ok) {
      window.setTimeout(() => {
        dlg.close();
        delete dlg.dataset.openProvider;
        delete dlg.dataset.modalMode;
        render();
      }, 900);
    } else {
      dlg.close();
      delete dlg.dataset.openProvider;
      delete dlg.dataset.modalMode;
      render();
      requestAnimationFrame(() => setByokFeedback(provider, msg, 'fail'));
    }
  });

  registerAction('delete-provider-key', async ({ element }) => {
    const dlg = element.closest('#provider-byok-modal');
    const provider =
      normalizeByokProvider(element?.dataset?.provider) ||
      normalizeByokProvider(dlg?.dataset?.openProvider);
    if (!provider) return;
    const { render } = getCtx();
    deleteProviderKey(provider);
    if (dlg && typeof dlg.close === 'function') {
      delete dlg.dataset.openProvider;
      delete dlg.dataset.modalMode;
      dlg.close();
    }
    render();
    requestAnimationFrame(() => setByokFeedback(provider, 'Déconnecté — clé supprimée.', 'ok'));
  });

  registerAction('toggle-provider-key-visibility', ({ element }) => {
    const provider = normalizeByokProvider(element?.dataset?.provider);
    if (!provider) return;
    const { state } = getCtx();
    const root = element.closest('.byok-card') || element.closest('#provider-byok-modal');
    const input = root?.querySelector(
      `input[data-action="provider-key-input"][data-provider="${CSS.escape(provider)}"]`,
    );
    if (!input) return;
    const savedKey = (state.providerSettings?.[provider]?.apiKey || '').trim();
    const reveal = input.type === 'password';
    if (reveal) {
      input.type = 'text';
      if (!input.value.trim() && savedKey) {
        input.value = maskProviderKey(savedKey);
        input.dataset.byokMaskOnly = '1';
      }
      element.textContent = 'Masquer';
    } else {
      if (input.dataset.byokMaskOnly === '1') {
        input.value = '';
        delete input.dataset.byokMaskOnly;
      }
      input.type = 'password';
      element.textContent = 'Afficher';
    }
  });

  registerAction('toggle-provider-enabled', ({ element }) => {
    const provider = normalizeByokProvider(element?.dataset?.provider);
    if (!provider) return;
    const { render } = getCtx();
    const checked = !!element.checked;
    updateProviderSettings(provider, { enabled: checked });
    render();
    requestAnimationFrame(() =>
      setByokFeedback(provider, checked ? 'Fournisseur activé.' : 'Fournisseur désactivé.', 'ok'),
    );
  });

  registerAction('test-provider-key', async ({ element }) => {
    const provider = normalizeByokProvider(element?.dataset?.provider);
    if (!provider) return;
    const { state } = getCtx();
    const root =
      element.closest('#provider-byok-modal') ||
      element.closest('.byok-card') ||
      element.closest('.byok-quick-row');
    const input = root?.querySelector(
      `input[data-action="provider-key-input"][data-provider="${CSS.escape(provider)}"]`,
    );
    const baseEl = root?.querySelector(`input.byok-base-url-input[data-provider="${CSS.escape(provider)}"]`);
    const apiKey = resolveByokApiKeyInput(provider, input, state);
    const baseDom = baseEl && typeof baseEl.value === 'string' ? baseEl.value.trim() : '';
    const baseStore =
      typeof state.providerSettings?.[provider]?.baseUrl === 'string'
        ? state.providerSettings[provider].baseUrl.trim()
        : '';
    const baseUrl = baseDom || baseStore;
    setByokFeedback(provider, 'Test en cours…', 'pending', { modalOnly: !!element.closest('#provider-byok-modal') });
    const result = await testProviderConnection(provider, { apiKey, baseUrl });
    setByokFeedback(provider, result.message, result.ok ? 'ok' : 'fail', {
      modalOnly: !!element.closest('#provider-byok-modal'),
    });
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

  registerAction('admin-open-template-create', () => {
    const { state, render, navigate } = getCtx();
    const sel = document.querySelector('input[name="admin-template-create-type"]:checked');
    const type = sel?.value || 'simple';
    if (type === 'scenario') {
      state.scenarioPackShowForm = true;
      state.scenarioPackEditing = null;
      render();
      requestAnimationFrame(() => {
        document.getElementById('scenario-pack-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    } else {
      state.scenarioPackShowForm = false;
      state.scenarioPackEditing = null;
      navigate('template-maker');
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
        body: JSON.stringify(
          withProviderRuntime({ description: tm.description, provider_id: tm.providerId || null, model: tm.model || null }),
        ),
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

  registerInputListener((e) => {
    if (!e.target.dataset.psField) return false;
    const { state } = getCtx();
    const sb = ensurePersonaSandboxState(state);
    sb[e.target.dataset.psField] = e.target.value;
    if (sb.error) sb.error = null;
    return true;
  });

  registerChangeListener((e) => {
    const { state, render } = getCtx();
    if (e.target.dataset.psField || e.target.dataset.psList) {
      syncPersonaSandboxFromDom(state);
      if (e.target.dataset.psField === 'compareMode') {
        render();
      }
      return true;
    }
    return false;
  });

  registerInputListener((e) => {
    const el = e.target.closest('[data-action="provider-key-input"]');
    if (!el) return false;
    const prov = normalizeByokProvider(el.dataset.provider);
    if (!prov) return false;
    const { state } = getCtx();
    if (el.dataset.byokMaskOnly === '1') {
      const sk = (state.providerSettings?.[prov]?.apiKey || '').trim();
      if (sk && el.value !== maskProviderKey(sk)) delete el.dataset.byokMaskOnly;
    }
    const modal = el.closest('#provider-byok-modal');
    const slot = modal
      ? modal.querySelector('.byok-modal-feedback')
      : el.closest('.byok-quick-row')?.querySelector('.byok-feedback');
    if (slot) {
      slot.textContent = '';
      slot.hidden = true;
      slot.className = slot.dataset.feedbackSlot === 'modal'
        ? 'byok-modal-feedback provider-test-result'
        : 'byok-feedback provider-test-result byok-quick-feedback';
    }
    return false;
  });

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
    const result = await apiFetch('/api/personas/make', { method: 'POST', body: JSON.stringify(withProviderRuntime(body)) });
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
    const result  = await apiFetch('/api/personas/build-draft', { method: 'POST', body: JSON.stringify(withProviderRuntime(body)) });
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

/* ══════════════════════════════════════════════════════════════════════
   Feature 5 — Post-mortem stats (admin retrospective)
═══════════════════════════════════════════════════════════════════════ */

let _postmortemStatsFetchGen = 0;

function registerRetrospectiveHandlers() {
  registerAction('load-postmortem-stats', async () => {
    const { state, render, apiFetch } = getCtx();
    const myGen = ++_postmortemStatsFetchGen;
    const hadStatsAlready = !!state.postmortemStats;

    state.postmortemStatsLoading = false;
    state.postmortemStatsAwaiting = true;
    state.postmortemStatsError = null;
    render();

    let spinnerShown = false;
    let spinnerShownAt = 0;
    const SHOW_SPINNER_AFTER_MS = 220;
    const MIN_SPINNER_MS = 340;

    const spinTimer = setTimeout(() => {
      if (myGen !== _postmortemStatsFetchGen || hadStatsAlready) return;
      spinnerShown = true;
      spinnerShownAt = Date.now();
      state.postmortemStatsLoading = true;
      render();
    }, SHOW_SPINNER_AFTER_MS);

    const finishSpinnerUi = () => {
      state.postmortemStatsAwaiting = false;
      state.postmortemStatsLoading = false;
      render();
    };

    try {
      const result = await apiFetch('/api/postmortems/stats');
      if (myGen !== _postmortemStatsFetchGen) return;
      const total = Number(result?.total ?? 0);
      state.postmortemStats = {
        total,
        correct: Number(result?.correct ?? 0),
        incorrect: Number(result?.incorrect ?? 0),
        partial: Number(result?.partial ?? 0),
        by_mode: result?.by_mode && typeof result.by_mode === 'object' ? result.by_mode : {},
        by_agent: result?.by_agent && typeof result.by_agent === 'object' ? result.by_agent : {},
      };
      state.postmortemStatsError = null;
    } catch (err) {
      if (myGen !== _postmortemStatsFetchGen) return;
      console.error('postmortem-stats', err);
      state.postmortemStatsError = err?.message || String(err);
    } finally {
      clearTimeout(spinTimer);
      if (myGen !== _postmortemStatsFetchGen) return;

      if (!spinnerShown) {
        finishSpinnerUi();
        return;
      }

      const visibleFor = Date.now() - spinnerShownAt;
      const waitMore = MIN_SPINNER_MS - visibleFor;
      if (waitMore > 0) {
        setTimeout(() => {
          if (myGen !== _postmortemStatsFetchGen) return;
          finishSpinnerUi();
        }, waitMore);
      } else {
        finishSpinnerUi();
      }
    }
  });
}

/* ══════════════════════════════════════════════════════════════════════
   Feature 6 — Prompt Policies editor
═══════════════════════════════════════════════════════════════════════ */

function registerPromptPolicyHandlers() {
  const PromptPolicyService = window.DecisionArena?.services?.PromptPolicyService;

  // Load list when entering the view
  registerAction('nav-prompt-policies', async () => {
    const { state, render, t } = getCtx();
    if (!state.promptPolicies?.items?.length) {
      await _loadPolicyList(state, render, t);
    }
  });

  // Select a policy from the sidebar
  registerAction('policy-select', async ({ element }) => {
    const { state, render, t } = getCtx();
    const id = element?.dataset?.policyId;
    if (!id) return;

    const ps = state.promptPolicies || {};
    if (ps.activeId === id && ps.content !== undefined) return; // already loaded

    // Warn if unsaved changes
    if (ps.draft !== null && ps.draft !== undefined && ps.activeId && ps.activeId !== id) {
      if (!window.confirm(t('admin.promptPolicies.confirmDiscard'))) return;
    }

    state.promptPolicies = { ...ps, loadingId: id, error: null };
    render();

    try {
      const data = await _policyService().get(id);
      state.promptPolicies = {
        ...state.promptPolicies,
        activeId:          id,
        activeTitle:       data.title || id,
        activeFilename:    data.filename || '',
        activeDescription: data.description || '',
        content:           data.content || '',
        draft:             null,
        savedId:           null,
        loadingId:         null,
        error:             null,
      };
    } catch (err) {
      state.promptPolicies = { ...state.promptPolicies, loadingId: null, error: String(err.message || err) };
    }
    render();
  });

  // Track edits in textarea
  registerAction('policy-draft', ({ element }) => {
    const { state } = getCtx();
    const id = element?.dataset?.policyId;
    if (!id || !state.promptPolicies) return;
    state.promptPolicies.draft  = element.value;
    state.promptPolicies.savedId = null;
    state.promptPolicies.error  = null;
    // No full render — just update status badge in place
    const statusEl = document.querySelector('.admin-policy-status-area');
    if (statusEl) {
      const t = (k) => window.i18n?.t(k) ?? k;
      statusEl.innerHTML = `<span class="admin-policy-status admin-policy-status--unsaved">● ${t('admin.promptPolicies.unsaved')}</span>`;
    }
  });

  // Save
  registerAction('policy-save', async ({ element }) => {
    const { state, render, t } = getCtx();
    const id = element?.dataset?.policyId || state.promptPolicies?.activeId;
    if (!id) return;
    const ps = state.promptPolicies || {};
    const content = ps.draft !== null && ps.draft !== undefined ? ps.draft : ps.content || '';

    state.promptPolicies = { ...ps, saving: true, error: null };
    render();

    try {
      await _policyService().update(id, content);
      state.promptPolicies = {
        ...state.promptPolicies,
        saving:  false,
        savedId: id,
        content: content,
        draft:   null,
        error:   null,
      };
    } catch (err) {
      state.promptPolicies = { ...state.promptPolicies, saving: false, error: String(err.message || err) };
    }
    render();
  });

  // Reset local draft
  registerAction('policy-reset', ({ element }) => {
    const { state, render } = getCtx();
    const id = element?.dataset?.policyId || state.promptPolicies?.activeId;
    if (!id || !state.promptPolicies) return;
    state.promptPolicies.draft  = null;
    state.promptPolicies.savedId = null;
    state.promptPolicies.error  = null;
    render();
  });

  // Auto-load list when navigating to prompt-policies
  const _origNavigate = window.DecisionArena?.router?.navigate;

  async function _loadPolicyList(state, render, t) {
    try {
      const data = await _policyService().list();
      const items = Array.isArray(data.items) ? data.items : [];
      state.promptPolicies = state.promptPolicies || {};
      state.promptPolicies.items = items;
      render();
      // Auto-select first
      if (items.length && !state.promptPolicies.activeId) {
        const firstId = items[0].id;
        state.promptPolicies.loadingId = firstId;
        render();
        try {
          const detail = await _policyService().get(firstId);
          state.promptPolicies = {
            ...state.promptPolicies,
            activeId:          firstId,
            activeTitle:       detail.title || firstId,
            activeFilename:    detail.filename || '',
            activeDescription: detail.description || '',
            content:           detail.content || '',
            draft:             null,
            savedId:           null,
            loadingId:         null,
          };
        } catch (_) {
          state.promptPolicies.loadingId = null;
        }
        render();
      }
    } catch (err) {
      console.error('prompt-policy-list', err);
    }
  }

  // Patch router to auto-load on view activation
  const arena = window.DecisionArena;
  if (arena?.router) {
    const origDispatch = arena.router._dispatch || null;
    const _patchRouterOnce = () => {
      if (arena.router._policyPatchDone) return;
      arena.router._policyPatchDone = true;
      const origNavigate = arena.router.navigate.bind(arena.router);
      arena.router.navigate = async (view, ...args) => {
        origNavigate(view, ...args);
        if (view === 'prompt-policies') {
          const { state, render, t: _t } = getCtx();
          state.promptPolicies = state.promptPolicies || {};
          if (!state.promptPolicies.items?.length) {
            await _loadPolicyList(state, render, _t);
          }
        }
      };
    };
    _patchRouterOnce();
  }

  function _policyService() {
    return window.DecisionArena?.services?.PromptPolicyService
      || window.DecisionArena?.services?.promptPolicyService
      || (window.DecisionArena?.services?.apiFetch
        ? {
            list:   () => window.DecisionArena.services.apiFetch('/api/prompt-policies'),
            get:    (id) => window.DecisionArena.services.apiFetch(`/api/prompt-policies/${encodeURIComponent(id)}`),
            update: (id, content) => window.DecisionArena.services.apiFetch(`/api/prompt-policies/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify({ content }) }),
          }
        : null);
  }
}

/* ══════════════════════════════════════════════════════════════════════
   Learning Layer — handlers
═══════════════════════════════════════════════════════════════════════ */

function registerLearningHandlers() {
  registerAction('load-learning', async () => {
    const { state, render, apiFetch } = getCtx();
    state.learningLoading = true;
    state.learningError = null;
    render();
    try {
      const result = await apiFetch('/api/learning/overview');
      state.learningReport = result || null;
      state.learningError  = null;
    } catch (err) {
      console.error('[learning] load', err);
      state.learningError = err?.message || String(err);
    } finally {
      state.learningLoading = false;
      render();
    }
  });

  registerAction('recompute-learning', async () => {
    const { state, render, apiFetch } = getCtx();
    state.learningLoading = true;
    state.learningError = null;
    render();
    try {
      const result = await apiFetch('/api/learning/recompute', { method: 'POST' });
      state.learningReport = result?.report || result || null;
      state.learningError  = null;
    } catch (err) {
      console.error('[learning] recompute', err);
      state.learningError = err?.message || String(err);
    } finally {
      state.learningLoading = false;
      render();
    }
  });

  registerAction('export-learning', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const format = element?.dataset?.format || 'markdown';
    state.learningExportStatus = 'loading';
    render();
    try {
      const result = await apiFetch(`/api/learning/export?format=${format}`);
      if (result?.error) {
        state.learningExportStatus = 'error';
        state.learningError = result.message || 'Export failed';
        render();
        return;
      }
      const content  = result.content || (format === 'json' ? JSON.stringify(result, null, 2) : '');
      const filename = result.filename || `learning-report.${format === 'json' ? 'json' : 'md'}`;
      const mime     = format === 'json' ? 'application/json' : 'text/markdown;charset=utf-8';
      const blob     = new Blob([content], { type: mime });
      const url      = URL.createObjectURL(blob);
      const a        = document.createElement('a');
      a.href         = url;
      a.download     = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      state.learningExportStatus = 'ok';
    } catch (err) {
      console.error('[learning] export', err);
      state.learningExportStatus = 'error';
      state.learningError = err?.message || String(err);
    } finally {
      setTimeout(() => { state.learningExportStatus = null; render(); }, 3000);
      render();
    }
  });
}

export { registerAdminHandlers, registerScenarioPackAdminHandlers, registerRetrospectiveHandlers, registerPromptPolicyHandlers, registerLearningHandlers };

/* Loader Service — loads data from API and writes directly to global state */

function _svc() { return window.DecisionArena.services; }
function _state() { return window.DecisionArena.store.state; }

async function loadPersonas() {
  try {
    const data = await _svc().PersonaService.list();
    _state().personas = Array.isArray(data) ? data : (data.personas || []);
  } catch (err) {
    console.warn('Could not load personas:', err.message);
    _state().personas = [];
  }
}

async function loadSessions() {
  try {
    const data = await _svc().SessionService.list();
    _state().sessions = Array.isArray(data) ? data : (data.sessions || []);
  } catch (err) {
    console.warn('Could not load sessions:', err.message);
    _state().sessions = [];
  }
}

async function loadProviders() {
  try {
    const data = await _svc().ProviderService.list();
    _state().providers = Array.isArray(data) ? data : (data.providers || []);
  } catch (err) {
    console.warn('Could not load providers:', err.message);
    _state().providers = [];
  }
}

async function loadProviderRoutingSettings() {
  try {
    const data = await _svc().ProviderService.getRouting();
    _state().providerRoutingSettings = data || null;
  } catch (err) {
    console.warn('Could not load provider routing settings:', err.message);
    _state().providerRoutingSettings = null;
  }
}

async function loadSouls() {
  try {
    const data = await _svc().SoulService.list();
    _state().souls = Array.isArray(data) ? data : [];
  } catch (err) {
    console.warn('Could not load souls:', err.message);
    _state().souls = [];
  }
}

async function loadTemplates() {
  try {
    const data = await _svc().TemplateService.list();
    _state().templates = Array.isArray(data) ? data : [];
  } catch (err) {
    console.warn('Could not load templates:', err.message);
    _state().templates = [];
  }
}

async function loadComparisons() {
  try {
    const data = await _svc().ComparisonService.list();
    _state().comparisons = Array.isArray(data.comparisons) ? data.comparisons : [];
  } catch (_) {
    _state().comparisons = [];
  }
}

async function loadScenarioPacks() {
  try {
    const data = await _svc().ScenarioPackService.list();
    _state().scenarioPacks = Array.isArray(data) ? data : [];
  } catch (err) {
    console.warn('Could not load scenario packs:', err.message);
    _state().scenarioPacks = [];
  }
}

async function loadInitialData() {
  await Promise.allSettled([
    loadPersonas(),
    loadSessions(),
    loadProviders(),
    loadProviderRoutingSettings(),
    loadSouls(),
    loadTemplates(),
    loadScenarioPacks(),
  ]);
}

export const LoaderService = {
  loadPersonas,
  loadSessions,
  loadProviders,
  loadProviderRoutingSettings,
  loadSouls,
  loadTemplates,
  loadComparisons,
  loadScenarioPacks,
  loadInitialData,
};

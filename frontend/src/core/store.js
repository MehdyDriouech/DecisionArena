function normalizeUiMode(mode) {
  if (mode === 'expert' || mode === 'advanced') return 'expert';
  if (mode === 'simple' || mode === 'basic') return 'simple';
  return 'simple';
}

function legacyComplexityToUiMode(level) {
  return normalizeUiMode(level);
}

function uiModeToLegacyComplexity(mode) {
  return normalizeUiMode(mode) === 'expert' ? 'expert' : 'basic';
}

function readInitialUiMode() {
  try {
    const savedMode = localStorage.getItem('da_ui_mode');
    if (savedMode) return normalizeUiMode(savedMode);

    const legacyComplexity = localStorage.getItem('da_ui_complexity');
    if (legacyComplexity) return legacyComplexityToUiMode(legacyComplexity);
  } catch (_) {}
  return 'simple';
}

/** local BYOK keys; never log or stringify for errors */
const PROVIDER_SETTINGS_STORAGE_KEY = 'providerSettings';

const DEFAULT_PROVIDER_SETTINGS = {
  openai: {
    enabled: false,
    apiKey: '',
    baseUrl: 'https://api.openai.com/v1',
    priority: 100,
    defaultModel: '',
  },
  anthropic: {
    enabled: false,
    apiKey: '',
    baseUrl: 'https://api.anthropic.com',
    priority: 100,
    defaultModel: '',
  },
  mistral: {
    enabled: false,
    apiKey: '',
    baseUrl: 'https://api.mistral.ai/v1',
    priority: 100,
    defaultModel: '',
  },
  openrouter: {
    enabled: false,
    apiKey: '',
    baseUrl: 'https://openrouter.ai/api/v1',
    priority: 100,
    defaultModel: '',
  },
};

function mergeProviderSettingsFromStorage(stored) {
  const defaults = DEFAULT_PROVIDER_SETTINGS;
  const next = {
    openai: { ...defaults.openai },
    anthropic: { ...defaults.anthropic },
    mistral: { ...defaults.mistral },
    openrouter: { ...defaults.openrouter },
  };
  if (!stored || typeof stored !== 'object') {
    return next;
  }
  for (const key of Object.keys(defaults)) {
    const row = stored[key];
    if (row && typeof row === 'object') {
      const pr = row.priority;
      const prio =
        typeof pr === 'number' && Number.isFinite(pr)
          ? pr
          : parseInt(String(pr ?? ''), 10);
      const nextPriority = Number.isFinite(prio) ? prio : defaults[key].priority;
      const dm = row.defaultModel;
      const nextDefaultModel = typeof dm === 'string' ? dm : defaults[key].defaultModel;
      next[key] = {
        enabled: !!row.enabled,
        apiKey: typeof row.apiKey === 'string' ? row.apiKey : '',
        baseUrl:
          typeof row.baseUrl === 'string' && row.baseUrl.trim() !== ''
            ? row.baseUrl
            : defaults[key].baseUrl,
        priority: nextPriority,
        defaultModel: nextDefaultModel,
      };
    }
  }
  return next;
}

function readStoredProviderSettings() {
  try {
    const raw = localStorage.getItem(PROVIDER_SETTINGS_STORAGE_KEY);
    if (!raw || raw.trim() === '') {
      return mergeProviderSettingsFromStorage(null);
    }
    const parsed = JSON.parse(raw);
    return mergeProviderSettingsFromStorage(parsed);
  } catch (_) {
    return mergeProviderSettingsFromStorage(null);
  }
}

function persistProviderSettingsSnapshot(settings) {
  try {
    localStorage.setItem(PROVIDER_SETTINGS_STORAGE_KEY, JSON.stringify(settings));
  } catch (_) {}
}

const KNOWN_PROVIDER_SETTINGS_IDS = Object.freeze(
  new Set(['openai', 'anthropic', 'mistral', 'openrouter']),
);

/**
 * Snapshot of BYOK entries (cloned per vendor). Prefer mutating via updateProviderSettings.
 * @returns {typeof DEFAULT_PROVIDER_SETTINGS}
 */
function getProviderSettings() {
  const ps = state.providerSettings;
  const out = {};
  for (const id of KNOWN_PROVIDER_SETTINGS_IDS) {
    const row = ps[id];
    const def = DEFAULT_PROVIDER_SETTINGS[id];
    if (!row) {
      out[id] = { ...def };
      continue;
    }
    const pr = row.priority;
    const prio =
      typeof pr === 'number' && Number.isFinite(pr)
        ? pr
        : parseInt(String(pr ?? ''), 10);
    out[id] = {
      enabled: !!row.enabled,
      apiKey: typeof row.apiKey === 'string' ? row.apiKey : '',
      baseUrl: typeof row.baseUrl === 'string' ? row.baseUrl : def.baseUrl,
      priority: Number.isFinite(prio) ? prio : def.priority,
      defaultModel: typeof row.defaultModel === 'string' ? row.defaultModel : def.defaultModel,
    };
  }
  return /** @type {typeof DEFAULT_PROVIDER_SETTINGS} */ (out);
}

function updateProviderSettings(provider, patch) {
  if (!KNOWN_PROVIDER_SETTINGS_IDS.has(provider) || !patch || typeof patch !== 'object') {
    return state.providerSettings;
  }
  const def = DEFAULT_PROVIDER_SETTINGS[provider];
  const cur = state.providerSettings[provider] || { ...def };
  let nextEnabled = cur.enabled;
  let nextApiKey = typeof cur.apiKey === 'string' ? cur.apiKey : '';
  let nextBase = typeof cur.baseUrl === 'string' ? cur.baseUrl : def.baseUrl;
  let nextPriority =
    typeof cur.priority === 'number' && Number.isFinite(cur.priority) ? cur.priority : def.priority;
  let nextDefaultModel = typeof cur.defaultModel === 'string' ? cur.defaultModel : def.defaultModel;

  if ('enabled' in patch) nextEnabled = !!patch.enabled;
  if ('apiKey' in patch) nextApiKey = typeof patch.apiKey === 'string' ? patch.apiKey : '';
  if ('baseUrl' in patch)
    nextBase =
      typeof patch.baseUrl === 'string' && patch.baseUrl.trim() !== ''
        ? patch.baseUrl
        : def.baseUrl;
  if ('priority' in patch) {
    const p = patch.priority;
    const n = typeof p === 'number' && Number.isFinite(p) ? p : parseInt(String(p ?? ''), 10);
    nextPriority = Number.isFinite(n) ? n : def.priority;
  }
  if ('defaultModel' in patch) {
    nextDefaultModel = typeof patch.defaultModel === 'string' ? patch.defaultModel : def.defaultModel;
  }

  state.providerSettings = {
    ...state.providerSettings,
    [provider]: {
      enabled: nextEnabled,
      apiKey: nextApiKey,
      baseUrl: nextBase,
      priority: nextPriority,
      defaultModel: nextDefaultModel,
    },
  };

  persistProviderSettingsSnapshot(state.providerSettings);
  return state.providerSettings;
}

function deleteProviderKey(provider) {
  return updateProviderSettings(provider, { apiKey: '', enabled: false });
}

/**
 * Visible mask only (UI / display). Never use as real credential.
 */
function maskProviderKey(apiKey) {
  const s = typeof apiKey === 'string' ? apiKey : '';
  if (s.trim() === '') return '';
  const tail = s.length >= 4 ? s.slice(-4) : '';
  return tail === '' ? '••••••••' : `••••••••${tail}`;
}

const createInitialState = () => {
  const initialUiMode = readInitialUiMode();
  const providerSettingsHydrated = readStoredProviderSettings();
  return ({
  view: 'dashboard',
  sessions: [],
  personas: [],
  providers: [],
  providerSettings: providerSettingsHydrated,
  providerRoutingSettings: null,
  providerRoutingSaveStatus: null,
  providerRoutingSaveMessage: '',
  logs: {
    items: null,
    loading: false,
    error: null,
    selectedId: null,
    selected: null,
    filters: {
      level: '',
      category: '',
      session_id: '',
      provider_id: '',
      agent_id: '',
      from: '',
      to: '',
      search: '',
      limit: 100,
      offset: 0,
    },
    exportStatus: null,
    maintenanceStatus: null,
  },
  providerModelOptions: [],
  providerModelStatus: null,
  providerModelError: '',
  souls: [],
  currentSession: null,
  currentMessages: [],
  followUpMessages: [],
  followUpLoading: false,
  isLoading: false,
  drResults: null,
  drRunning: false,
  decisionBrief: null,
  confrontationResults: null,
  confrontationRunning: false,
  reactiveChat: {
    enabled:                      false,
    primaryAgentId:               '',
    reactorAgentIds:              [],
    turnsMin:                     2,
    turnsMax:                     4,
    earlyStopEnabled:             true,
    earlyStopConfidenceThreshold: 0.85,
    noNewArgumentsThreshold:      2,
    reactorMode:                  'independent',
    debateIntensity:              'medium',
    reactionStyle:                'critical',
    includeFinalSynthesis:        true,
    running:                      false,
    error:                        null,
  },
  reactiveChatResults: null,
  snapshotStatus: null,
  error: null,
  /** "simple" | "expert" — progressive disclosure source of truth */
  uiMode: initialUiMode,
  /** Legacy alias. Mapped from uiMode: simple -> basic, expert -> expert. */
  uiComplexity: uiModeToLegacyComplexity(initialUiMode),
  /** Set of panel keys currently collapsed in session history */
  collapsedPanels: (() => {
    try {
      const saved = localStorage.getItem('da_collapsed_panels');
      return saved ? new Set(JSON.parse(saved)) : new Set(['social-dynamics', 'bias-detection', 'llm-used', 'evidence', 'risk', 'persona-scores']);
    } catch (_) { return new Set(); }
  })(),
  sessionHistory: null,
  /** Session id -> GET /api/analysis/agent-dynamics-suggestions response (post-mortem reco UI) */
  dynamicsRecoBySession: {},
  /** GET /api/analysis/agent-dynamics-suggestions (full list) when admin personas panel loaded */
  adminDynamicsReco: null,
  collapsedMessages: {},
  showDebateDetails: false,
    newSession: {
    title: '',
    idea: '',
    mode: 'chat',
    simpleIntent: 'decide',
    selectedAgents: [],
    rounds: 3,
    language: 'fr',
    blueTeam: ['pm', 'architect', 'po', 'ux-expert'],
    redTeam: ['analyst', 'critic'],
    includeSynthesis: true,
    cfRounds: 3,
    cfStyle: 'sequential',
    cfReplyPolicy: 'all-agents-reply',
    forceDisagreement: false,
    juryThreshold: 0.55,
    selectedScenarioId: null,
    ctxDocEnabled: false,
    ctxDocTab: 'manual',
    ctxDocTitle: '',
    ctxDocContent: '',
    ctxDocDraftSaved: false,
    ctxDocDraftSummary: null,
    fastDecisionEnabled: true,
    customizing: false,
    selectedTemplateId: null,
    /** Modèle de départ choisi sur Nouvelle session ({ type, id } | null) */
    selectedStarter: null,
    /** Replie la grille « Démarrer avec un modèle » sur Nouvelle session */
    starterModelsCollapsed: false,
    isFork: false,
    source_session_id: null,
    forkDraftSessionId: null,
    /** Session-only DecisionDynamicsPreset id (balanced|conservative|aggressive|critical) */
    decisionDynamicsPreset: 'balanced',
  },
  currentContextDoc: null,
  ctxDocPanelOpen: false,
  personaMaker: {
    description: '',
    providerId: '',
    model: '',
    isGenerating: false,
    result: null,
    error: null,
    previewTab: 'persona',
    saveStatus: null,
    saveMessage: '',
    overwrite: false,
  },
  personaBuilder: {
    id: '',
    name: '',
    title: '',
    icon: '🤖',
    tags: '',
    defaultProvider: '',
    defaultModel: '',
    enabled: true,
    role: '',
    whenToUse: '',
    style: '',
    identity: '',
    focus: '',
    corePrinciples: '',
    capabilities: '',
    constraints: '',
    defaultResponseFormat: '',
    systemInstructions: '',
    personality: '',
    behavioralRules: '',
    reasoningStyle: '',
    communicationStyle: '',
    defaultBias: '',
    challengeLevel: 'medium',
    outputPreferences: '',
    guardrails: '',
    description: '',
    isGenerating: false,
    previewTab: 'persona',
    saveStatus: null,
    saveMessage: '',
    generationError: null,
    overwrite: false,
  },
  sessionFilter: 'all',
  templates: [],
  scenarioPacks: [],
  qdResults: null,
  qdRunning: false,
  stResults: null,
  stRunning: false,
  chatAbortController: null,
  graphData: null,
  graphLoading: false,
  graphError: null,
  heatmapData: null,
  heatmapLoading: false,
  heatmapError: null,
  heatmapFilter: 'all',
  replayEvents: null,
  replayLoading: false,
  replayError: null,
  replayIndex: 0,
  replayPlaying: false,
  replaySpeed: 1,
  auditData: null,
  auditLoading: false,
  auditError: null,
  voteExplanation: null,
  voteExplanationLoading: false,
  voteExplanationError: null,
  lastRecomputeThreshold: null, // { sessionId: string, threshold: number } | null
  actionPlan: null,
  actionPlanLoading: false,
  actionPlanStatus: null,
  comparisons: [],
  currentComparison: null,
  comparisonLoading: false,
  compareSelectedIds: [],
  rerunModal: {
    open: false,
    sessionId: null,
    variations: [],
    targetMode: '',
    language: '',
    customInstruction: '',
    keepContext: true,
    loading: false,
  },
  launchAssistant: {
    step: 1,
    intent: null,
    description: '',
    recommendation: null,
    loading: false,
    editMode: null,
    editRounds: 2,
    editAgents: [],
    editForceDisagreement: true,
    editMode2: '',
  },
  templateMaker: {
    description: '',
    providerId: '',
    model: '',
    isGenerating: false,
    result: null,
    error: null,
    saveStatus: null,
    saveMessage: '',
  },
  postmortemStats: null,
  postmortemStatsAwaiting: false,
  postmortemStatsLoading: false,
  postmortemStatsError: null,
  premortemLaunchSessionId: null,

  templateMakerData: {
    id: '',
    name: '',
    description: '',
    mode: 'decision-room',
    selectedAgents: [],
    rounds: 2,
    forceDisagreement: false,
    interactionStyle: 'sequential',
    replyPolicy: 'all-agents-reply',
    finalSynthesis: true,
    promptStarter: '',
    expectedOutput: '',
    notes: '',
    enabled: true,
    editingId: null,
    saveStatus: null,
    saveMessage: '',
    overwrite: false,
  },
  });
};

const state = createInitialState();

function resetState() {
  const fresh = createInitialState();
  Object.keys(state).forEach((key) => {
    delete state[key];
  });
  Object.assign(state, fresh);
}

function patchState(partial) {
  Object.assign(state, partial);
}

function setView(view) {
  state.view = view;
}

function setUiMode(mode) {
  const normalized = normalizeUiMode(mode);
  state.uiMode = normalized;
  state.uiComplexity = uiModeToLegacyComplexity(normalized);
  try {
    localStorage.setItem('da_ui_mode', normalized);
    localStorage.setItem('da_ui_complexity', state.uiComplexity);
  } catch (_) {}
  return normalized;
}

function setUiComplexity(level) {
  return setUiMode(legacyComplexityToUiMode(level));
}

export {
  createInitialState,
  state,
  resetState,
  patchState,
  setView,
  setUiMode,
  setUiComplexity,
  normalizeUiMode,
  legacyComplexityToUiMode,
  uiModeToLegacyComplexity,
  getProviderSettings,
  updateProviderSettings,
  deleteProviderKey,
  maskProviderKey,
};

export {
  getAvailableProviders,
  selectPrimaryProvider,
  formatRoutingOptionLabel,
} from './providerRouting.js';
export { withProviderRuntime, buildProviderRuntimePayload } from './providerRuntime.js';

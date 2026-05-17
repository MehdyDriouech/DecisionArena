import {
  getPlaybookById,
  getPlaybooks,
  resolvePlaybookForNewSession,
} from './playbooks.js';

function normalizeUiMode(mode) {
  // Product UX: only two UI modes exist: "basic" | "expert".
  // Legacy compatibility:
  // - "advanced" -> "expert"
  // - "simple"   -> "basic"
  // - legacy "basic"/"expert" preserved
  if (mode === 'expert' || mode === 'advanced') return 'expert';
  if (mode === 'basic' || mode === 'simple') return 'basic';
  return 'basic';
}

function legacyComplexityToUiMode(level) {
  return normalizeUiMode(level);
}

function uiModeToLegacyComplexity(mode) {
  return normalizeUiMode(mode) === 'expert' ? 'expert' : 'basic';
}

function isExpertMode(uiMode) {
  return normalizeUiMode(uiMode) === 'expert';
}

function isBasicMode(uiMode) {
  return normalizeUiMode(uiMode) === 'basic';
}

const PERSISTED_ANALYSIS_STATUSES = Object.freeze(new Set(['draft', 'running', 'completed', 'archived']));

function parseSessionResultPayload(resultRaw) {
  if (!resultRaw) return null;
  if (typeof resultRaw === 'object') return resultRaw;
  if (typeof resultRaw !== 'string') return null;
  try {
    return JSON.parse(resultRaw);
  } catch (_) {
    return null;
  }
}

function normalizePersistedAnalysisStatus(status) {
  const raw = String(status || '').trim().toLowerCase();
  if (PERSISTED_ANALYSIS_STATUSES.has(raw)) return raw;
  if (raw === 'active') return 'running';
  if (raw === 'error' || raw === 'blocked') return 'draft';
  return 'draft';
}

function mapAnalysisLifecycle(session) {
  const result = parseSessionResultPayload(session?.result);
  const baseStatus = normalizePersistedAnalysisStatus(session?.status);
  const overlays = [];

  const falseConsensusRisk = String(
    session?.false_consensus_risk ||
      session?.false_consensus?.risk_level ||
      result?.false_consensus_risk ||
      result?.false_consensus?.risk_level ||
      '',
  ).toLowerCase();
  if (falseConsensusRisk === 'high' || falseConsensusRisk === 'critical') {
    overlays.push('fragile');
  }

  const guardrailStatus = String(
    session?.guardrails?.guardrail_status ||
      result?.guardrails?.guardrail_status ||
      '',
  ).toLowerCase();
  const rawStatus = String(session?.status || '').toLowerCase();
  if (
    guardrailStatus === 'blocked' ||
    guardrailStatus === 'auto_retry_triggered' ||
    rawStatus === 'blocked' ||
    rawStatus === 'error'
  ) {
    overlays.push('blocked');
  }

  const lineageSource = String(
    session?.parent_session_id ||
      session?.source_session_id ||
      session?.rerun_of_session_id ||
      '',
  ).trim();
  const lineageReason = String(session?.rerun_reason || '').toLowerCase();
  const isForked = lineageReason.includes('fork');
  if (lineageSource) {
    overlays.push(isForked ? 'forked' : 'rerun');
  }

  return {
    baseStatus,
    overlays,
    primaryStatus: baseStatus,
    allStatuses: [baseStatus, ...overlays],
  };
}

function readInitialUiMode() {
  try {
    const savedMode = localStorage.getItem('da_ui_mode');
    if (savedMode) return normalizeUiMode(savedMode);

    const legacyComplexity = localStorage.getItem('da_ui_complexity');
    if (legacyComplexity) return legacyComplexityToUiMode(legacyComplexity);
  } catch (_) {}
  return 'basic';
}

const EXPERIMENTAL_FEATURES_STORAGE_KEY = 'da_experimental_features';
const MOBILE_LAYOUT_NOTICE_STORAGE_KEY = 'da_mobile_layout_notice_dismissed';
const MOBILE_LAYOUT_MAX_WIDTH_PX = 768;

function readMobileLayoutNoticeDismissed() {
  try {
    return localStorage.getItem(MOBILE_LAYOUT_NOTICE_STORAGE_KEY) === '1';
  } catch (_) {
    return false;
  }
}

function isMobileLayout() {
  if (typeof window === 'undefined' || !window.matchMedia) return false;
  return window.matchMedia(`(max-width: ${MOBILE_LAYOUT_MAX_WIDTH_PX}px)`).matches;
}

function shouldShowMobileLayoutNotice(stateRef = state) {
  return isMobileLayout() && !stateRef.mobileLayoutNoticeDismissed;
}

function dismissMobileLayoutNotice() {
  try {
    localStorage.setItem(MOBILE_LAYOUT_NOTICE_STORAGE_KEY, '1');
  } catch (_) {}
  state.mobileLayoutNoticeDismissed = true;
}

function readInitialExperimentalFeatures() {
  try {
    return localStorage.getItem(EXPERIMENTAL_FEATURES_STORAGE_KEY) === '1';
  } catch (_) {
    return false;
  }
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
  gemini: {
    enabled: false,
    apiKey: '',
    baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai',
    priority: 100,
    defaultModel: 'gemini-2.5-flash',
  },
};

function mergeProviderSettingsFromStorage(stored) {
  const defaults = DEFAULT_PROVIDER_SETTINGS;
  const next = {
    openai: { ...defaults.openai },
    anthropic: { ...defaults.anthropic },
    mistral: { ...defaults.mistral },
    openrouter: { ...defaults.openrouter },
    gemini: { ...defaults.gemini },
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
  new Set(['openai', 'anthropic', 'mistral', 'openrouter', 'gemini']),
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
  const initialExpertUi = initialUiMode === 'expert';
  const providerSettingsHydrated = readStoredProviderSettings();
  return ({
  view: 'dashboard',
  demoAuth: {
    loading: true,
    authRequired: false,
    authenticated: false,
    user: null,
    error: null,
    accountMenuOpen: false,
  },
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
  personaSandbox: {
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
  },
  souls: [],
  currentSession: null,
  currentMessages: [],
  followUpMessages: [],
  followUpLoading: false,
  isLoading: false,
  drResults: null,
  drRunning: false,
  runProgress: null,
  runProgressBySessionId: {},
  runProgressPolling: { active: false, intervalMs: 1500, error: null, lastUpdateAt: null },
  liveRunReloadError: null,
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
  toast: null,
  pendingConfirmation: null,
  openSpace: {
    selectedContextId: null,
    activeBoardId: null,
    orchestratorProposal: null,
    tasks: [],
    selectedTaskId: null,
    selectedAgentId: null,
    messages: [],
    loading: false,
    error: null,
    boards: [],
    proposals: [],
    contexts: [],
    warnings: [],
    orchestratorObjective: '',
    orchestratorConstraints: '',
    newTaskTitle: '',
    newTaskDescription: '',
    newTaskPriority: 'medium',
    newTaskStatus: 'backlog',
    filterStatus: '',
    filterAgent: '',
    chatInput: '',
  },
  /** Contexte stratégique « workspace » unique (persisté côté API, hydraté au bootstrap). */
  activeStrategicContextId: null,
  activeStrategicContext: null,
  strategicContexts: { loading: false, error: null, items: [] },
  strategicContextUi: {
    statusFilter: 'active',
    selectedContextId: null,
    // Inline forms (avoid browser prompt/confirm)
    formOpen: false,
    formMode: 'create', // "create" | "edit"
    formContextId: null,
    formValues: { title: '', description: '', status: 'active' },
    formError: '',
    linkFormOpen: false,
    linkType: 'session', // "session" | "memory"
    linkId: '',
    linkError: '',
    linkSuccess: '',
    archiveError: '',
    deleteConfirmContextId: null,
    deleteError: '',
    memoryMdOpen: false,
    memoryMdLoading: false,
    memoryMdError: '',
    memoryMdContent: '',
    /** GET /api/strategic-contexts/{id}/memory-overview — résumé mémoire (Basic + données diagnostics Expert). */
    memoryOverview: { loading: false, error: '', data: null },
    agentMemoryForceSync: { loading: false, error: '', report: null },
    /** Comparaison non destructive entre deux contextes (ne modifie pas l’actif workspace). */
    compareOpen: false,
    compareLeftId: null,
    compareRightId: null,
    /** Comparaison Basic (POST compare) — cible choisie + résultat court, sans ouvrir le panneau Expert. */
    basicCompare: { targetId: null, loading: false, error: '', result: null },
    /** GET /api/strategic-contexts/{context_id}/timeline — rempli par load-workspace-timeline */
    workspaceTimeline: { loading: false, error: '', data: null },
    /** GET /api/strategic-contexts/{id}/narrative — rempli par load-strategic-narrative */
    strategicNarrative: { loading: false, error: '', data: null },
    /** Beliefs Engine MVP (expert) — load-context-beliefs / load-agent-beliefs */
    beliefsEngine: {
      loading: false,
      error: '',
      items: [],
      mode: 'context',
      filterAgent: '',
      filterType: '',
      filterStatus: '',
      disputedOnly: false,
      byAgentId: '',
      saving: false,
      formError: '',
    },
    /** Memory Compiler (expert) — consolidations dérivées, audit trail. */
    memoryCompiler: {
      loading: false,
      error: '',
      compileSaving: false,
      items: [],
      filterType: '',
      filterStatus: '',
      selectedCompilationId: null,
      detailLoading: false,
      detail: null,
    },
    /** Context Snapshots (expert) — captures immuables du contexte stratégique. */
    contextSnapshots: {
      loading: false,
      error: '',
      createSaving: false,
      items: [],
      selectedSnapshotId: null,
      detailLoading: false,
      detail: null,
      compareLeft: '',
      compareRight: '',
      compareLoading: false,
      compareResult: null,
      longLoading: false,
      longError: '',
      longViewMarkdown: '',
    },
    /** Panneau expert : mémoire markdown par agent (fichier memory.md par contexte). */
    agentContextMemory: {
      open: false,
      agentId: 'pm',
      loading: false,
      saving: false,
      consolidating: false,
      recentNoteBusy: false,
      contradictionBusy: false,
      deprecateBusy: false,
      compactBusy: false,
      error: '',
      content: '',
      appendNote: '',
      appendSection: 'recent',
      maintenanceRecentNote: '',
      maintenanceSessionId: '',
      contradictionText: '',
      contradictionSource: '',
      deprecateText: '',
      deprecateReason: '',
    },
    /** Chat situé (expert) — POST /api/strategic-contexts/.../agents/.../chat */
    situatedAgentChat: {
      open: false,
      conversationId: null,
      agentId: 'pm',
      messages: [],
      input: '',
      loading: false,
      includeMemory: true,
      includeRecentDecisions: true,
      includeSocialContext: true,
      lastWarnings: [],
      error: '',
    },
    /** Comparaison croisée read-only (POST /api/strategic-contexts/compare) — expert. */
    contextDeepCompare: {
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
    },
  },
  decisionMemory: { loading: false, error: null, memories: null },
  selectedMemoryIds: [],
  memoryConfirmingSessionId: null,
  /** @type {Record<string, { memory_id: string, strategic_context_id: string|null, strategic_context_linked: boolean }>} */
  postPersistDecisionMemoryBySession: {},
  agentMemoryPropagationPreview: null,
  agentMemoryPropagationBusy: false,
  decisionMemoryNav: {
    contextsLoading: false,
    contextsError: null,
    roomsLoading: false,
    roomsError: null,
    contexts: [],
    roomsByContextId: {},
    /** Une fois true, on ne ré-applique plus le workspace actif sur navStrategicContextId automatiquement. */
    initialWorkspaceNavHydrated: false,
  },
  decisionMemoryUi: {
    mode: 'timeline', // "timeline" | "chain"
    filters: { playbook_id: '', decision_status: '', confidence: '', has_unresolved: '', link_type: '' },
    selectedChainId: null,
    selectedMemoryId: null,
    /** null = toutes les initiatives; sinon context_id stratégique */
    navStrategicContextId: null,
    /** null = mémoires liées au contexte (sans chaîne); sinon room_id */
    navDecisionChainId: null,
    roomMemoryMdOpen: false,
    roomMemoryMdLoading: false,
    roomMemoryMdError: '',
    roomMemoryMdContent: '',
  },
  /** "basic" | "expert" — progressive disclosure source of truth */
  uiMode: initialUiMode,
  /** Legacy alias. Mapped from uiMode: simple -> basic, expert -> expert. */
  uiComplexity: uiModeToLegacyComplexity(initialUiMode),
  /** Alpha / experimental UI (OpenSpace, couches cognitives avancées). Persisté localStorage ; défaut false. */
  experimentalFeaturesEnabled: readInitialExperimentalFeatures(),
  /** Avertissement layout mobile (≤768px) fermé par l'utilisateur. */
  mobileLayoutNoticeDismissed: readMobileLayoutNoticeDismissed(),
  /** Dernière vue OpenSpace demandée avant page de garde (debug / copy). */
  experimentalGateRequestedView: null,
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
  /** Catalogue Cognitive Governance (GET /api/cognitive-governance) — expert, lecture seule */
  cognitiveGovernance: {
    loading: false,
    error: null,
    catalog: null,
  },
  collapsedMessages: {},
  showDebateDetails: false,
  /** providerId -> { status, models, error } — liste modèles pour routage LLM (nouvelle session). */
  providerModelsCache: {},
    newSession: {
    title: '',
    idea: '',
    mode: initialExpertUi ? 'chat' : 'stress-test',
    selectedPlaybookId: initialExpertUi ? null : 'stress-test',
    productFamily: initialExpertUi ? null : 'validate',
    productPreset: null,
    founderInterrogation: null,
    simpleIntent: initialExpertUi ? 'decide' : 'test',
    selectedIntent: initialExpertUi ? 'decide' : 'validate',
    selectedAgents: [],
    rounds: 3,
    language: 'fr',
    blueTeam: ['pm', 'architect', 'po', 'ux-expert'],
    redTeam: ['analyst', 'critic'],
    includeSynthesis: true,
    cfRounds: 3,
    cfStyle: 'sequential',
    cfReplyPolicy: 'all-agents-reply',
    forceDisagreement: initialExpertUi ? false : true,
    juryThreshold: 0.55,
    selectedScenarioId: null,
    ctxDocEnabled: false,
    ctxDocTab: 'manual',
    ctxDocTitle: '',
    ctxDocContent: '',
    ctxDocDraftSaved: false,
    ctxDocDraftSummary: null,
    fastDecisionEnabled: initialExpertUi,
    customizing: false,
    selectedTemplateId: null,
    presetRationale: null,
    /** Modèle de départ choisi sur Nouvelle session ({ type, id } | null) */
    selectedStarter: null,
    /** Replie la grille « Démarrer avec un modèle » sur Nouvelle session */
    starterModelsCollapsed: true,
    isFork: false,
    source_session_id: null,
    forkDraftSessionId: null,
    /** Session-only DecisionDynamicsPreset id (balanced|conservative|aggressive|critical) */
    decisionDynamicsPreset: 'balanced',
    /** Expert : contourner le garde-fou « contexte stratégique actif requis » (compatibilité legacy). */
    confirmLegacyNoActiveStrategicContext: false,
    /** Overrides appliqués (persistés en session_agent_providers via POST /api/sessions). */
    agentProviders: {},
    /** Sélections en brouillon (non persistées tant que « Appliquer l’override » n’est pas cliqué). */
    agentProviderDrafts: {},
    /** Overrides appliqués par équipe (confrontation). */
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
    /** Brouillons d’override par équipe (confrontation). */
    teamProviderDrafts: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
    /** UI : saisie manuelle modèle (liste déroulante masquée) par agent / équipe. */
    llmModelManualOpen: { agents: {}, teams: {} },
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
  analysesWorkspace: {
    query: '',
    mode: 'all',
    status: 'all',
    contextId: 'all',
    verdict: 'all',
    dateRange: 'all',
    selectedIds: [],
    visibleIds: [],
  },
  dashboardSummary: {
    loading: false,
    error: null,
    data: null,
    lastLoadedAt: null,
    collapsedSections: (() => {
      try {
        const raw = localStorage.getItem('da_dashboard_collapsed_sections');
        const parsed = raw ? JSON.parse(raw) : null;
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (_) {
        return {};
      }
    })(),
    contextsFilterStatus: (() => {
      try {
        const raw = localStorage.getItem('da_dashboard_context_filter_status');
        const allowed = ['all', 'active', 'paused', 'completed', 'abandoned'];
        return allowed.includes(raw || '') ? raw : 'all';
      } catch (_) {
        return 'all';
      }
    })(),
    contextsSortBy: (() => {
      try {
        const raw = localStorage.getItem('da_dashboard_context_sort_by');
        const allowed = ['open_risks_desc', 'analyses_desc', 'reruns_desc', 'snapshot_desc'];
        return allowed.includes(raw || '') ? raw : 'open_risks_desc';
      } catch (_) {
        return 'open_risks_desc';
      }
    })(),
    contextsHighRiskOnly: (() => {
      try {
        return localStorage.getItem('da_dashboard_context_high_risk_only') === '1';
      } catch (_) {
        return false;
      }
    })(),
    scopeContextId: (() => {
      try {
        return localStorage.getItem('da_dashboard_scope_context_id') || 'auto';
      } catch (_) {
        return 'auto';
      }
    })(),
    scopePickerOpen: false,
    detailPanel: {
      open: false,
      kind: '',
    },
  },
  /** Expert : `GET /api/sessions?all_contexts=1` pour lister toutes les sessions (y compris hors contexte actif / legacy). */
  sessionsListAllContexts: false,
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
    confirmLegacyNoWorkspace: false,
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

function setExperimentalFeaturesEnabled(enabled) {
  const v = !!enabled;
  state.experimentalFeaturesEnabled = v;
  try {
    localStorage.setItem(EXPERIMENTAL_FEATURES_STORAGE_KEY, v ? '1' : '0');
  } catch (_) {}
  return v;
}

/** Affichage des modules alpha / expérimentaux (distinct de uiMode). */
function canShowExperimentalFeatures(stateRef = state) {
  return normalizeUiMode(stateRef.uiMode) === 'expert' && stateRef.experimentalFeaturesEnabled === true;
}

function getSelectedPlaybook(language = window.i18n?.getLanguage?.() || 'fr') {
  return resolvePlaybookForNewSession(state.newSession || {}, language);
}

export {
  createInitialState,
  state,
  resetState,
  patchState,
  setView,
  setUiMode,
  setUiComplexity,
  setExperimentalFeaturesEnabled,
  canShowExperimentalFeatures,
  normalizeUiMode,
  isExpertMode,
  isBasicMode,
  legacyComplexityToUiMode,
  uiModeToLegacyComplexity,
  mapAnalysisLifecycle,
  getPlaybookById,
  getPlaybooks,
  getSelectedPlaybook,
  getProviderSettings,
  updateProviderSettings,
  deleteProviderKey,
  maskProviderKey,
  MOBILE_LAYOUT_NOTICE_STORAGE_KEY,
  MOBILE_LAYOUT_MAX_WIDTH_PX,
  isMobileLayout,
  shouldShowMobileLayoutNotice,
  dismissMobileLayoutNotice,
  readMobileLayoutNoticeDismissed,
};

export {
  getAvailableProviders,
  selectPrimaryProvider,
  formatRoutingOptionLabel,
} from './providerRouting.js';
export { withProviderRuntime, buildProviderRuntimePayload } from './providerRuntime.js';

const INTENT_PRESETS = {
  explore: {
    label: 'Explorer une id\u00e9e',
    mode: 'chat',
    rounds: 1,
    personas: ['pm', 'ux-expert', 'analyst', 'critic'],
    rationale: 'Explorer n\u00e9cessite diversit\u00e9 produit, utilisateur, march\u00e9 et contradiction l\u00e9g\u00e8re.',
  },

  decide: {
    label: 'Prendre une d\u00e9cision',
    mode: 'quick-decision',
    rounds: 1,
    personas: ['pm', 'architect', 'critic', 'synthesizer'],
    rationale: 'D\u00e9cider n\u00e9cessite produit, faisabilit\u00e9 technique, critique et synth\u00e8se.',
  },

  test: {
    label: 'Tester une id\u00e9e',
    mode: 'stress-test',
    rounds: 3,
    personas: ['critic', 'qa', 'architect', 'pm'],
    rationale: 'Tester n\u00e9cessite critique, qualit\u00e9, risques techniques et arbitrage produit.',
  },
};

const PERSONA_FALLBACKS = {
  pm: ['pm', 'po', 'product-owner'],
  'ux-expert': ['ux-expert', 'ux', 'designer'],
  analyst: ['analyst', 'market-analyst', 'strategy'],
  critic: ['critic', 'qa'],
  architect: ['architect', 'tech-lead'],
  synthesizer: ['synthesizer', 'analyst'],
  qa: ['qa', 'critic'],
  'founder-partner': ['founder-partner', 'pm', 'po'],
  'product-wedge-strategist': ['product-wedge-strategist', 'pm', 'analyst'],
  'customer-reality-checker': ['customer-reality-checker', 'ux-expert', 'analyst'],
  'engineering-pragmatist': ['engineering-pragmatist', 'architect', 'dev'],
  'startup-devils-advocate': ['startup-devils-advocate', 'critic', 'qa'],
  'strategic-founder': ['strategic-founder', 'pm', 'po'],
  'gtm-skeptic': ['gtm-skeptic', 'analyst', 'pm'],
  'product-simplifier': ['product-simplifier', 'pm', 'critic'],
  'market-timing-reviewer': ['market-timing-reviewer', 'analyst'],
};

const DEFAULT_PERSONA_ROLES = ['pm', 'architect', 'critic'];

function _normalizePersonaId(id) {
  return String(id || '').trim().toLowerCase();
}

function _uniqueExisting(ids) {
  const out = [];
  (ids || []).forEach((id) => {
    if (id && !out.includes(id)) out.push(id);
  });
  return out;
}

function _personaSelectionId(persona) {
  return persona?.id || persona?.slug || persona?.name || '';
}

function _isPersonaAvailableForMode(persona, mode) {
  if (!mode) return true;
  if (mode === 'quick-decision' || mode === 'stress-test' || mode === 'jury') return true;
  const modes = Array.isArray(persona?.available_modes)
    ? persona.available_modes
    : ['chat', 'decision-room', 'confrontation'];
  return modes.includes(mode);
}

function getAvailablePersonaIds() {
  const personas = window.DecisionArena?.store?.state?.personas || [];
  return personas.map((p) => _personaSelectionId(p)).filter(Boolean);
}

function getAvailablePersonaIdsForMode(mode) {
  const personas = window.DecisionArena?.store?.state?.personas || [];
  return personas
    .filter((p) => _isPersonaAvailableForMode(p, mode))
    .map((p) => _personaSelectionId(p))
    .filter(Boolean);
}

function resolvePresetPersonas(personaIds, availableIds) {
  const ids = _uniqueExisting((availableIds || []).map((id) => String(id)));
  const exactIds = new Set(ids);
  const normalizedIds = new Map(ids.map((id) => [_normalizePersonaId(id), id]));
  const resolved = [];

  for (const wanted of personaIds || []) {
    const candidates = [wanted, ...(PERSONA_FALLBACKS[wanted] || [])];
    const match = candidates.find((id) => exactIds.has(id) || normalizedIds.has(_normalizePersonaId(id)));
    const resolvedId = match && (exactIds.has(match) ? match : normalizedIds.get(_normalizePersonaId(match)));
    if (resolvedId && !resolved.includes(resolvedId)) {
      resolved.push(resolvedId);
    }
  }

  return resolved;
}

function filterAvailablePersonas(personaIds, availableIds = getAvailablePersonaIds()) {
  const ids = _uniqueExisting((availableIds || []).map((id) => String(id)));
  const exactIds = new Set(ids);
  const normalizedIds = new Map(ids.map((id) => [_normalizePersonaId(id), id]));
  return _uniqueExisting((personaIds || []).map((id) => {
    if (exactIds.has(id)) return id;
    return normalizedIds.get(_normalizePersonaId(id));
  }).filter(Boolean));
}

function resolveDefaultPresetPersonas(availableIds = getAvailablePersonaIds()) {
  const resolved = resolvePresetPersonas(DEFAULT_PERSONA_ROLES, availableIds);
  return resolved.length > 0 ? resolved : _uniqueExisting(availableIds || []).slice(0, 3);
}

function applyIntentPreset(intent) {
  const DA = window.DecisionArena;
  const preset = INTENT_PRESETS[intent];
  if (!DA || !preset) return false;

  const ns = DA.store.state.newSession || {};
  const availableIds = getAvailablePersonaIdsForMode(preset.mode);
  const resolvedPersonas = resolvePresetPersonas(preset.personas, availableIds);
  const existingSelected = filterAvailablePersonas(ns.selectedAgents || [], availableIds);
  const defaultPersonas = resolveDefaultPresetPersonas(availableIds);

  const productFamilyFromIntent = intent === 'decide' ? 'decide' : intent === 'test' ? 'validate' : null;

  DA.store.state.newSession = {
    ...ns,
    simpleIntent: intent,
    selectedIntent: intent,
    productFamily: productFamilyFromIntent,
    productPreset: null,
    mode: preset.mode,
    rounds: preset.rounds,
    selectedAgents: resolvedPersonas.length > 0
      ? resolvedPersonas
      : (existingSelected.length > 0 ? existingSelected : defaultPersonas),
    fastDecisionEnabled: false,
    forceDisagreement: intent !== 'explore',
    selectedStarter: null,
    selectedScenarioId: null,
    selectedTemplateId: null,
    facilitationFramework: null,
    presetRationale: preset.rationale,
  };

  return true;
}

export {
  INTENT_PRESETS,
  PERSONA_FALLBACKS,
  getAvailablePersonaIds,
  getAvailablePersonaIdsForMode,
  resolvePresetPersonas,
  filterAvailablePersonas,
  resolveDefaultPresetPersonas,
  applyIntentPreset,
};

/**
 * Presets produit (ex. Founder Sprint) — surcouche sans nouveau runner backend.
 * Réutilise modes / personas / dynamics existants.
 */
import {
  getAvailablePersonaIdsForMode,
  resolvePresetPersonas,
  filterAvailablePersonas,
  resolveDefaultPresetPersonas,
} from '../../utils/intentPresets.js';

export const FOUNDER_SPRINT_PRESET_ID = 'founder-sprint';
export const CEO_CHALLENGE_PRESET_ID = 'ceo-challenge';

/** @type {Record<string, {
 *   productFamily: string,
 *   mode: string,
 *   rounds: number,
 *   decisionDynamicsPreset: string,
 *   devilAdvocateEnabled: boolean,
 *   forceDisagreement: boolean,
 *   fastDecisionEnabled: boolean,
 *   personas: string[],
 * }>} */
const PRODUCT_PRESETS = {
  [FOUNDER_SPRINT_PRESET_ID]: {
    productFamily: 'validate',
    mode: 'decision-room',
    rounds: 3,
    decisionDynamicsPreset: 'critical',
    devilAdvocateEnabled: false,
    forceDisagreement: true,
    fastDecisionEnabled: false,
    personas: [
      'founder-partner',
      'product-wedge-strategist',
      'customer-reality-checker',
      'engineering-pragmatist',
      'startup-devils-advocate',
      'synthesizer',
    ],
  },
  [CEO_CHALLENGE_PRESET_ID]: {
    productFamily: 'decide',
    mode: 'decision-room',
    rounds: 3,
    decisionDynamicsPreset: 'critical',
    devilAdvocateEnabled: false,
    forceDisagreement: true,
    fastDecisionEnabled: false,
    personas: [
      'strategic-founder',
      'gtm-skeptic',
      'product-simplifier',
      'market-timing-reviewer',
      'startup-devils-advocate',
      'synthesizer',
    ],
  },
};

function buildEmptyFounderInterrogation(open = true) {
  return {
    open: !!open,
    pain: '',
    icp: '',
    statusQuo: '',
    criticalAssumption: '',
    wedge: '',
    validationSignal: '',
  };
}

function isFounderInterrogationFilled(fi) {
  if (!fi || typeof fi !== 'object') return false;
  return [
    fi.pain,
    fi.icp,
    fi.statusQuo,
    fi.criticalAssumption,
    fi.wedge,
    fi.validationSignal,
  ].some((v) => String(v || '').trim() !== '');
}

function applyProductPreset(presetId) {
  const DA = window.DecisionArena;
  const def = PRODUCT_PRESETS[presetId];
  if (!DA || !def) return false;

  const ns = DA.store.state.newSession || {};
  const mode = def.mode;
  const availableIds = getAvailablePersonaIdsForMode(mode);
  const resolved = resolvePresetPersonas(def.personas, availableIds);
  const existingSelected = filterAvailablePersonas(ns.selectedAgents || [], availableIds);
  const defaultPersonas = resolveDefaultPresetPersonas(availableIds);
  const selectedAgents = resolved.length > 0
    ? resolved
    : (existingSelected.length > 0 ? existingSelected : defaultPersonas);

  const existingFi = ns.founderInterrogation && typeof ns.founderInterrogation === 'object'
    ? ns.founderInterrogation
    : null;

  DA.store.state.newSession = {
    ...ns,
    productPreset: presetId,
    selectedPlaybookId: presetId,
    productFamily: def.productFamily,
    selectedIntent: def.productFamily,
    simpleIntent: def.productFamily === 'decide' ? 'decide' : 'test',
    founderInterrogation: presetId === FOUNDER_SPRINT_PRESET_ID
      ? (isFounderInterrogationFilled(existingFi)
        ? existingFi
        : buildEmptyFounderInterrogation(DA.store.state.uiMode === 'basic'))
      : null,
    mode,
    rounds: def.rounds,
    cfRounds: ns.cfRounds ?? 3,
    decisionDynamicsPreset: def.decisionDynamicsPreset,
    devilAdvocateEnabled: !!def.devilAdvocateEnabled,
    forceDisagreement: !!def.forceDisagreement,
    fastDecisionEnabled: !!def.fastDecisionEnabled,
    selectedAgents,
    selectedStarter: null,
    selectedScenarioId: null,
    selectedTemplateId: null,
    facilitationFramework: null,
    presetRationale: null,
    agentProviders: {},
    agentProviderDrafts: {},
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
    teamProviderDrafts: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
  };

  return true;
}

export { PRODUCT_PRESETS, applyProductPreset, buildEmptyFounderInterrogation };

/**
 * Taxonomie produit (Lot 1) — familles d’analyse → modes techniques existants.
 * Aucun champ backend supplémentaire : seul mode / agents / options sont envoyés comme aujourd’hui.
 */
import {
  getAvailablePersonaIdsForMode,
  resolvePresetPersonas,
  filterAvailablePersonas,
  resolveDefaultPresetPersonas,
} from '../../utils/intentPresets.js';
import { isModeBackedPlaybook } from '../../core/playbooks.js';

/**
 * @typedef {Object} AnalysisFamilyDefinition
 * @property {string} id
 * @property {string} family
 * @property {'stress-test'|'quick-decision'|'confrontation'|'decision-room'|'chat'|'jury'} defaultMode
 * @property {number} rounds
 * @property {string[]} [personas]
 * @property {string[]} [blueTeam]
 * @property {string[]} [redTeam]
 * @property {number} [cfRounds]
 * @property {boolean} [expertOnly]
 * @property {boolean} [forceDisagreement]
 */

/** @type {Record<string, AnalysisFamilyDefinition>} */
const ANALYSIS_CATALOG = {
  validate: {
    id: 'validate',
    family: 'validate',
    defaultMode: 'stress-test',
    rounds: 3,
    personas: ['critic', 'qa', 'architect', 'pm'],
    expertOnly: false,
    forceDisagreement: true,
  },
  decide: {
    id: 'decide',
    family: 'decide',
    defaultMode: 'quick-decision',
    rounds: 1,
    personas: ['pm', 'architect', 'critic', 'synthesizer'],
    expertOnly: false,
    forceDisagreement: true,
  },
  stress: {
    id: 'stress',
    family: 'stress',
    defaultMode: 'confrontation',
    rounds: 3,
    cfRounds: 3,
    blueTeam: ['pm', 'architect', 'po', 'ux-expert'],
    redTeam: ['analyst', 'critic'],
    expertOnly: false,
    forceDisagreement: true,
  },
  ship: {
    id: 'ship',
    family: 'ship',
    defaultMode: 'stress-test',
    rounds: 4,
    personas: ['qa', 'critic', 'architect', 'pm'],
    expertOnly: false,
    forceDisagreement: true,
  },
};

/**
 * Applique une famille produit au state `newSession` (pré-remplit mode, tours, équipes).
 * @param {string} familyId
 * @returns {boolean}
 */
function applyAnalysisFamily(familyId) {
  const DA = window.DecisionArena;
  const catalog = ANALYSIS_CATALOG[familyId];
  if (!DA || !catalog) return false;

  const ns = DA.store.state.newSession || {};
  const mode = catalog.defaultMode;
  const availableIds = getAvailablePersonaIdsForMode(mode);

  let selectedAgents = ns.selectedAgents || [];
  let blueTeam = catalog.blueTeam ? [...catalog.blueTeam] : [...(ns.blueTeam || [])];
  let redTeam = catalog.redTeam ? [...catalog.redTeam] : [...(ns.redTeam || [])];

  if (mode === 'confrontation') {
    selectedAgents = [...new Set([...blueTeam, ...redTeam])];
  } else {
    const resolved = resolvePresetPersonas(catalog.personas || [], availableIds);
    const existingSelected = filterAvailablePersonas(ns.selectedAgents || [], availableIds);
    const defaultPersonas = resolveDefaultPresetPersonas(availableIds);
    selectedAgents = resolved.length > 0
      ? resolved
      : (existingSelected.length > 0 ? existingSelected : defaultPersonas);
  }

  const cfRounds = catalog.cfRounds ?? ns.cfRounds ?? 3;

  const simpleIntentLegacy = familyId === 'decide' ? 'decide' : 'test';

  DA.store.state.newSession = {
    ...ns,
    productFamily: familyId,
    selectedPlaybookId: isModeBackedPlaybook(mode) ? mode : null,
    selectedIntent: familyId,
    simpleIntent: simpleIntentLegacy,
    mode,
    rounds: catalog.rounds ?? 3,
    cfRounds,
    blueTeam,
    redTeam,
    selectedAgents,
    fastDecisionEnabled: false,
    forceDisagreement: catalog.forceDisagreement !== false,
    selectedStarter: null,
    selectedScenarioId: null,
    selectedTemplateId: null,
    facilitationFramework: null,
    presetRationale: null,
    productPreset: null,
    llmAssignmentMode: 'global',
    agentProviders: {},
    teamProviderAssignments: { blue: { provider_id: '', model: '' }, red: { provider_id: '', model: '' } },
  };

  return true;
}

export {
  ANALYSIS_CATALOG,
  applyAnalysisFamily,
};

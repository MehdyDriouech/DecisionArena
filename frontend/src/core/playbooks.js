const PLAYBOOK_COPY = {
  fr: {
    'founder-sprint': {
      id: 'founder-sprint',
      name: 'Founder Sprint',
      tagline: 'Passer d\'une idée floue à une validation terrain actionnable.',
      intention: 'Valider une idée',
      when_to_use: [
        'Vous explorez une nouvelle idee produit, marche ou offre.',
        'Vous devez identifier le wedge, l ICP et le premier test utile.',
      ],
      what_is_challenged: 'Le probleme client, le segment initial, les hypotheses critiques et la preuve de validation.',
      expected_outputs: [
        'Un wedge critique priorise.',
        'Un ICP initial challenge.',
        'Un signal de validation observable.',
        'Des criteres de kill/pivot.',
        'Une prochaine experience concrete.',
      ],
      good_outcome_definition: 'La sortie permet de savoir quoi tester en premier, avec quel segment, quel signal mesurer et quand arreter.',
      kill_signal_definition: 'Aucun segment urgent, absence de douleur specifique, signal de validation non mesurable ou test impossible a lancer rapidement.',
      recommended_for: [
        'Founders, PM, equipes zero-to-one.',
        'Idees encore exploratoires ou hypotheses marche a clarifier.',
      ],
      anti_patterns: [
        'Decision deja largement documentee avec options comparables.',
        'Besoin d arbitrage executif plus que de validation terrain.',
      ],
      estimated_duration: '35-45 min',
      cognitive_load: 'Advanced',
      output_contract: [
        'Angle d\'entrée critique',
        'Défi ICP',
        'Signal de validation',
        'Critères d\'abandon',
        'Prochain test',
      ],
      decision_type: 'Go test / narrow / pivot / kill sur une idee.',
    },
    'ceo-challenge': {
      id: 'ceo-challenge',
      name: 'CEO Challenge',
      tagline: 'Mettre une stratégie sous pression avant d\'engager l\'équipe.',
      intention: 'Challenger une stratégie',
      when_to_use: [
        'Vous avez une direction strategique importante a valider.',
        'Vous devez exposer les angles morts, compromis et risques d execution.',
      ],
      what_is_challenged: 'Les hypotheses strategiques, le timing, la distribution, la defensabilite et le cout d execution.',
      expected_outputs: [
        'Les hypotheses strategiques explicites.',
        'Les angles morts prioritaires.',
        'Les risques d execution.',
        'Une analyse des compromis.',
        'Un memo de decision leadership.',
      ],
      good_outcome_definition: 'La sortie aide un dirigeant a choisir poursuivre, reduire, pivoter, differer ou tuer avec des raisons nettes.',
      kill_signal_definition: 'Strategie trop large, avantage non defendable, distribution fragile, timing faible ou compromis inacceptables.',
      recommended_for: [
        'CEO, founders, leadership team, product strategy.',
        'Choix de cap, repositionnement, investissement majeur.',
      ],
      anti_patterns: [
        'Simple question operationnelle a trancher vite.',
        'Idee trop immature sans hypothese strategique formulable.',
      ],
      estimated_duration: '40-55 min',
      cognitive_load: 'Expert',
      output_contract: [
        'Hypothèses stratégiques',
        'Angles morts',
        'Risques d\'exécution',
        'Analyse des compromis',
        'Mémo de décision leadership',
      ],
      decision_type: 'Poursuivre / reduire / pivoter / differer / tuer une strategie.',
    },
    'stress-test': {
      id: 'stress-test',
      name: 'Stress Test',
      tagline: 'Chercher ce qui casse avant que le marché ou l\'exécution ne le fasse.',
      intention: 'Casser une hypothèse',
      when_to_use: [
        'Vous avez une hypothese forte a verifier avant engagement.',
        'Vous voulez identifier les scenarios d echec et les preuves manquantes.',
      ],
      what_is_challenged: 'L hypothese coeur, les assumptions les plus faibles, les preuves disponibles et les scenarios d echec.',
      expected_outputs: [
        'L hypothese coeur reformulee.',
        'Les scenarios d echec probables.',
        'Les assumptions les plus faibles.',
        'Les trous de preuve.',
        'Les signaux de pivot ou kill.',
      ],
      good_outcome_definition: 'La sortie revele ce qui doit etre prouve avant de continuer, avec des seuils observables.',
      kill_signal_definition: 'Hypothese non falsifiable, preuves absentes sur le point critique, risque dominant sans mitigation credible.',
      recommended_for: [
        'Plans, hypotheses produit, choix techniques ou go-to-market.',
        'Equipes qui veulent reduire le risque avant execution.',
      ],
      anti_patterns: [
        'Arbitrage entre plusieurs options deja connues.',
        'Besoin d ideation ouverte plutot que de falsification.',
      ],
      estimated_duration: '25-40 min',
      cognitive_load: 'Advanced',
      output_contract: [
        'Hypothèse coeur',
        'Scénarios d\'échec',
        'Assumptions fragiles',
        'Trous de preuve',
        'Signaux de pivot ou kill',
      ],
      decision_type: 'Continuer / prouver davantage / pivoter / tuer une hypothese.',
    },
    jury: {
      id: 'jury',
      name: 'Jury',
      tagline: 'Comparer des options et produire une recommandation argumentée.',
      intention: 'Arbitrer',
      when_to_use: [
        'Vous devez choisir entre plusieurs options defendables.',
        'Vous voulez un verdict structure avec criteres et niveau de confiance.',
      ],
      what_is_challenged: 'Les criteres de decision, les avantages/inconvenients par perspective et la robustesse de la recommandation.',
      expected_outputs: [
        'Les options de decision.',
        'Les criteres d evaluation.',
        'Les pour/contre par perspective.',
        'Une recommandation finale.',
        'Un niveau de confiance.',
      ],
      good_outcome_definition: 'La sortie montre pourquoi une option gagne, quelles conditions changeraient le verdict et le niveau de confiance.',
      kill_signal_definition: 'Options mal definies, criteres absents, donnees insuffisantes ou aucun ecart significatif entre alternatives.',
      recommended_for: [
        'Arbitrages produit, priorisation, build vs buy, choix d investissement.',
        'Decisions ou plusieurs perspectives doivent voter ou converger.',
      ],
      anti_patterns: [
        'Hypothese unique a casser.',
        'Decision urgente qui ne justifie pas une evaluation multi-criteres.',
      ],
      estimated_duration: '30-45 min',
      cognitive_load: 'Advanced',
      output_contract: [
        'Options de décision',
        'Critères d\'évaluation',
        'Pour/contre par perspective',
        'Recommandation finale',
        'Niveau de confiance',
      ],
      decision_type: 'Choisir une option et expliciter les conditions du verdict.',
    },
    confrontation: {
      id: 'confrontation',
      name: 'Confrontation',
      tagline: 'Faire s\'affronter deux visions pour clarifier le vrai désaccord.',
      intention: 'Opposer deux visions',
      when_to_use: [
        'Deux directions, visions ou strategies s opposent.',
        'Vous devez clarifier les points de conflit avant de decider.',
      ],
      what_is_challenged: 'La solidite de chaque position, les points de conflit, les arguments forts et les concessions possibles.',
      expected_outputs: [
        'La position A.',
        'La position B.',
        'Les points de conflit.',
        'Les arguments les plus solides.',
        'Une synthese ou chemin de decision.',
      ],
      good_outcome_definition: 'La sortie isole le vrai desaccord et donne un chemin de synthese, decision ou test comparatif.',
      kill_signal_definition: 'Positions caricaturales, absence de conflit reel, debat de preference sans criteres de decision.',
      recommended_for: [
        'Strategie A vs B, centraliser vs decentraliser, court terme vs long terme.',
        'Equipes qui tournent autour du meme desaccord sans le nommer.',
      ],
      anti_patterns: [
        'Decision deja contrainte par un fait non negociable.',
        'Besoin d un verdict rapide sans debat structure.',
      ],
      estimated_duration: '35-50 min',
      cognitive_load: 'Expert',
      output_contract: [
        'Position A',
        'Position B',
        'Points de conflit',
        'Arguments les plus solides',
        'Synthèse ou chemin de décision',
      ],
      decision_type: 'Choisir, combiner ou tester deux visions concurrentes.',
    },
    'quick-decision': {
      id: 'quick-decision',
      name: 'Quick Decision',
      tagline: 'Trancher avec les meilleures informations disponibles, sans sur-analyser.',
      intention: 'Trancher rapidement',
      when_to_use: [
        'La decision est reversible ou le delai compte plus que la precision parfaite.',
        'Vous avez besoin d une option immediate et d un risque principal.',
      ],
      what_is_challenged: 'Le cadrage de la decision, la contrainte cle, le risque principal et l action immediate.',
      expected_outputs: [
        'Un cadrage de decision.',
        'La contrainte cle.',
        'La meilleure option disponible.',
        'Le risque principal.',
        'La prochaine action immediate.',
      ],
      good_outcome_definition: 'La sortie permet d agir maintenant, tout en nommant le risque a surveiller.',
      kill_signal_definition: 'Decision irreversible, stakes eleves, information critique manquante ou besoin d alignement multi-parties.',
      recommended_for: [
        'Arbitrages rapides, choix tactiques, prochaines actions.',
        'Decisions a faible cout de correction.',
      ],
      anti_patterns: [
        'Decision irreversible ou fortement reglementee.',
        'Conflit strategique qui exige confrontation ou jury.',
      ],
      estimated_duration: '10-15 min',
      cognitive_load: 'Basic',
      output_contract: [
        'Cadrage de la décision',
        'Contrainte clé',
        'Meilleure option disponible',
        'Risque principal',
        'Action suivante immédiate',
      ],
      decision_type: 'Choisir maintenant et lancer l action suivante.',
    },
  },
};

PLAYBOOK_COPY.en = {
  'founder-sprint': {
    ...PLAYBOOK_COPY.fr['founder-sprint'],
    tagline: 'Move from a fuzzy idea to an actionable validation path.',
    intention: 'Validate an idea',
    decision_type: 'Go test / narrow / pivot / kill an idea.',
  },
  'ceo-challenge': {
    ...PLAYBOOK_COPY.fr['ceo-challenge'],
    tagline: 'Pressure-test a strategy before committing the team.',
    intention: 'Challenge a strategy',
    decision_type: 'Pursue / narrow / pivot / defer / kill a strategy.',
  },
  'stress-test': {
    ...PLAYBOOK_COPY.fr['stress-test'],
    tagline: 'Find what breaks before the market or execution does.',
    intention: 'Break a hypothesis',
    decision_type: 'Continue / prove more / pivot / kill a hypothesis.',
  },
  jury: {
    ...PLAYBOOK_COPY.fr.jury,
    tagline: 'Compare options and produce a reasoned recommendation.',
    intention: 'Arbitrate',
    decision_type: 'Choose an option and expose the conditions behind the verdict.',
  },
  confrontation: {
    ...PLAYBOOK_COPY.fr.confrontation,
    tagline: 'Put two visions in tension to clarify the real disagreement.',
    intention: 'Oppose two visions',
    decision_type: 'Choose, combine, or test competing visions.',
  },
  'quick-decision': {
    ...PLAYBOOK_COPY.fr['quick-decision'],
    tagline: 'Decide with the best available information, without over-analysis.',
    intention: 'Decide quickly',
    decision_type: 'Choose now and launch the next action.',
  },
};

Object.assign(PLAYBOOK_COPY.en['founder-sprint'], {
  when_to_use: [
    'You are exploring a new product, market, or offer idea.',
    'You need to identify the wedge, ICP, and first useful test.',
  ],
  what_is_challenged: 'The customer problem, initial segment, critical assumptions, and validation evidence.',
  expected_outputs: [
    'A prioritized critical wedge.',
    'A challenged initial ICP.',
    'An observable validation signal.',
    'Kill or pivot criteria.',
    'A concrete next experiment.',
  ],
  good_outcome_definition: 'The output makes clear what to test first, with which segment, what signal to measure, and when to stop.',
  kill_signal_definition: 'No urgent segment, no specific pain, non-measurable validation signal, or no small experiment that can be launched quickly.',
  recommended_for: [
    'Founders, PMs, and zero-to-one teams.',
    'Exploratory ideas or market hypotheses that need sharpening.',
  ],
  anti_patterns: [
    'A decision that is already documented with comparable options.',
    'Executive arbitration matters more than field validation.',
  ],
  output_contract: [
    'Wedge critique',
    'ICP challenge',
    'Validation signal',
    'Kill criteria',
    'Next experiment',
  ],
});

Object.assign(PLAYBOOK_COPY.en['ceo-challenge'], {
  when_to_use: [
    'You have an important strategic direction to validate.',
    'You need to expose blind spots, trade-offs, and execution risks.',
  ],
  what_is_challenged: 'Strategic assumptions, timing, distribution, defensibility, and execution cost.',
  expected_outputs: [
    'Explicit strategic assumptions.',
    'Priority blind spots.',
    'Execution risks.',
    'Trade-off analysis.',
    'A leadership decision memo.',
  ],
  good_outcome_definition: 'The output helps leadership choose pursue, narrow, pivot, defer, or kill with crisp reasoning.',
  kill_signal_definition: 'Strategy is too broad, advantage is not defensible, distribution is fragile, timing is weak, or trade-offs are unacceptable.',
  recommended_for: [
    'CEOs, founders, leadership teams, and product strategy.',
    'Strategic direction, repositioning, or major investment calls.',
  ],
  anti_patterns: [
    'A simple operational choice that should be made quickly.',
    'An idea too immature to express as a strategic hypothesis.',
  ],
  output_contract: [
    'Strategic assumptions',
    'Blind spots',
    'Execution risks',
    'Trade-off analysis',
    'Leadership decision memo',
  ],
});

Object.assign(PLAYBOOK_COPY.en['stress-test'], {
  when_to_use: [
    'You have a strong hypothesis to check before committing.',
    'You want to identify failure scenarios and missing evidence.',
  ],
  what_is_challenged: 'The core hypothesis, weakest assumptions, available evidence, and failure scenarios.',
  expected_outputs: [
    'The core hypothesis restated.',
    'Likely failure scenarios.',
    'The weakest assumptions.',
    'Evidence gaps.',
    'Pivot or kill signals.',
  ],
  good_outcome_definition: 'The output reveals what must be proven before continuing, with observable thresholds.',
  kill_signal_definition: 'The hypothesis is not falsifiable, evidence is missing on the critical point, or the dominant risk has no credible mitigation.',
  recommended_for: [
    'Plans, product hypotheses, technical choices, or go-to-market bets.',
    'Teams that want to reduce risk before execution.',
  ],
  anti_patterns: [
    'Arbitration between several already-known options.',
    'Open ideation rather than falsification.',
  ],
  output_contract: [
    'Core hypothesis',
    'Failure scenarios',
    'Weakest assumptions',
    'Evidence gaps',
    'Pivot / kill signals',
  ],
});

Object.assign(PLAYBOOK_COPY.en.jury, {
  when_to_use: [
    'You need to choose between several defensible options.',
    'You want a structured verdict with criteria and confidence level.',
  ],
  what_is_challenged: 'Decision criteria, pros and cons by perspective, and the robustness of the recommendation.',
  expected_outputs: [
    'Decision options.',
    'Evaluation criteria.',
    'Pros and cons by perspective.',
    'A final recommendation.',
    'A confidence level.',
  ],
  good_outcome_definition: 'The output shows why one option wins, what would change the verdict, and how confident the system is.',
  kill_signal_definition: 'Options are poorly defined, criteria are absent, data is insufficient, or alternatives are not meaningfully different.',
  recommended_for: [
    'Product arbitration, prioritization, build versus buy, and investment choices.',
    'Decisions where multiple perspectives should vote or converge.',
  ],
  anti_patterns: [
    'A single hypothesis to break.',
    'An urgent decision that does not justify multi-criteria evaluation.',
  ],
  output_contract: [
    'Decision options',
    'Evaluation criteria',
    'Pros / cons by perspective',
    'Final recommendation',
    'Confidence level',
  ],
});

Object.assign(PLAYBOOK_COPY.en.confrontation, {
  when_to_use: [
    'Two directions, visions, or strategies are in tension.',
    'You need to clarify conflict points before deciding.',
  ],
  what_is_challenged: 'The strength of each position, conflict points, strongest arguments, and possible concessions.',
  expected_outputs: [
    'Position A.',
    'Position B.',
    'Conflict points.',
    'Strongest arguments.',
    'A synthesis or decision path.',
  ],
  good_outcome_definition: 'The output isolates the real disagreement and gives a path toward synthesis, decision, or comparative test.',
  kill_signal_definition: 'Positions are caricatures, there is no real conflict, or the debate is preference-based without decision criteria.',
  recommended_for: [
    'Strategy A versus B, centralize versus decentralize, short term versus long term.',
    'Teams circling the same disagreement without naming it.',
  ],
  anti_patterns: [
    'The decision is already constrained by a non-negotiable fact.',
    'A fast verdict is needed without structured debate.',
  ],
  output_contract: [
    'Position A',
    'Position B',
    'Conflict points',
    'Strongest arguments',
    'Synthesis or decision path',
  ],
});

Object.assign(PLAYBOOK_COPY.en['quick-decision'], {
  when_to_use: [
    'The decision is reversible or speed matters more than perfect precision.',
    'You need an immediate option and the main risk.',
  ],
  what_is_challenged: 'Decision framing, the key constraint, the main risk, and the immediate action.',
  expected_outputs: [
    'Decision framing.',
    'The key constraint.',
    'The best available option.',
    'The main risk.',
    'The immediate next action.',
  ],
  good_outcome_definition: 'The output lets you act now while naming the risk to monitor.',
  kill_signal_definition: 'The decision is irreversible, stakes are high, critical information is missing, or multi-party alignment is required.',
  recommended_for: [
    'Fast arbitration, tactical choices, and next actions.',
    'Decisions with low correction cost.',
  ],
  anti_patterns: [
    'Irreversible or highly regulated decisions.',
    'Strategic conflict that requires Confrontation or Jury.',
  ],
  output_contract: [
    'Decision framing',
    'Key constraint',
    'Best available option',
    'Main risk',
    'Immediate next action',
  ],
});

const PLAYBOOK_ORDER = Object.freeze([
  'founder-sprint',
  'ceo-challenge',
  'stress-test',
  'jury',
  'confrontation',
  'quick-decision',
]);

const PLAYBOOK_INTENT_TAXONOMY = Object.freeze({
  fr: [
    {
      id: 'validation',
      label: 'Valider',
      question: 'Faut-il agir maintenant, tester ou resserrer ?',
      description: 'Pour passer d\'une idée ou d\'un choix rapide à une prochaine action observable.',
      playbookIds: ['founder-sprint', 'quick-decision'],
    },
    {
      id: 'challenge',
      label: 'Challenger',
      question: 'Qu\'est-ce qui peut casser ?',
      description: 'Pour exposer les hypothèses fragiles, les risques et les conditions de pivot.',
      playbookIds: ['stress-test', 'ceo-challenge'],
    },
    {
      id: 'arbitration',
      label: 'Arbitrer',
      question: 'Quelle option ou quelle vision doit gagner ?',
      description: 'Pour comparer des alternatives et clarifier les vrais désaccords.',
      playbookIds: ['jury', 'confrontation'],
    },
  ],
  en: [
    {
      id: 'validation',
      label: 'Validate',
      question: 'Should you act now, test, or narrow?',
      description: 'For turning an idea or fast choice into an observable next action.',
      playbookIds: ['founder-sprint', 'quick-decision'],
    },
    {
      id: 'challenge',
      label: 'Challenge',
      question: 'What could break?',
      description: 'For exposing fragile assumptions, risks, and pivot conditions.',
      playbookIds: ['stress-test', 'ceo-challenge'],
    },
    {
      id: 'arbitration',
      label: 'Arbitrate',
      question: 'Which option or vision should win?',
      description: 'For comparing alternatives and clarifying real disagreements.',
      playbookIds: ['jury', 'confrontation'],
    },
  ],
});

const PLAYBOOK_MODE_IDS = Object.freeze(new Set(['stress-test', 'jury', 'confrontation', 'quick-decision']));
const PRODUCT_PLAYBOOK_IDS = Object.freeze(new Set(['founder-sprint', 'ceo-challenge']));
const REQUIRED_CANONICAL_PLAYBOOK_FIELDS = Object.freeze([
  'intention',
  'tagline',
  'when_to_use',
  'what_is_challenged',
  'expected_outputs',
  'good_outcome_definition',
  'kill_signal_definition',
  'recommended_for',
  'anti_patterns',
  'estimated_duration',
  'cognitive_load',
  'output_contract',
]);

const LEGACY_INTENT_PLAYBOOK_IDS = Object.freeze({
  explore: null,
  decide: 'quick-decision',
  test: 'stress-test',
});

const LEGACY_LAUNCH_INTENT_PLAYBOOK_IDS = Object.freeze({
  'validate-idea': 'founder-sprint',
  'challenge-product': 'ceo-challenge',
  'find-risks': 'stress-test',
  'compare-options': 'jury',
  'prepare-decision': 'quick-decision',
  'stress-test-idea': 'stress-test',
  validate: 'founder-sprint',
  challenge: 'ceo-challenge',
  risks: 'stress-test',
  compare: 'jury',
  decision: 'quick-decision',
  stress: 'stress-test',
});

function normalizePlaybookLanguage(language) {
  return language === 'en' ? 'en' : 'fr';
}

function clonePlaybook(definition) {
  if (!definition) return null;
  return {
    ...definition,
    when_to_use: [...definition.when_to_use],
    expected_outputs: [...definition.expected_outputs],
    recommended_for: [...definition.recommended_for],
    anti_patterns: [...definition.anti_patterns],
    output_contract: [...definition.output_contract],
  };
}

function getPlaybookById(id, language = 'fr') {
  const lang = normalizePlaybookLanguage(language);
  return clonePlaybook(PLAYBOOK_COPY[lang]?.[id] || PLAYBOOK_COPY.fr[id] || null);
}

function getPlaybooks(language = 'fr') {
  return PLAYBOOK_ORDER.map((id) => getPlaybookById(id, language)).filter(Boolean);
}

function getPlaybookIntentGroups(language = 'fr') {
  const lang = normalizePlaybookLanguage(language);
  return (PLAYBOOK_INTENT_TAXONOMY[lang] || PLAYBOOK_INTENT_TAXONOMY.fr).map((group) => ({
    ...group,
    playbooks: group.playbookIds.map((id) => getPlaybookById(id, lang)).filter(Boolean),
  }));
}

function isPlaybookId(id) {
  return PLAYBOOK_ORDER.includes(id);
}

function resolvePlaybookIdForNewSession(newSession = {}) {
  if (newSession.selectedPlaybookId && isPlaybookId(newSession.selectedPlaybookId)) {
    return newSession.selectedPlaybookId;
  }
  if (newSession.productPreset && isPlaybookId(newSession.productPreset)) {
    return newSession.productPreset;
  }
  if (PLAYBOOK_MODE_IDS.has(newSession.mode)) {
    return newSession.mode;
  }
  return null;
}

function resolvePlaybookForNewSession(newSession = {}, language = 'fr') {
  return getPlaybookById(resolvePlaybookIdForNewSession(newSession), language);
}

function isModeBackedPlaybook(id) {
  return PLAYBOOK_MODE_IDS.has(id);
}

function isProductPlaybook(id) {
  return PRODUCT_PLAYBOOK_IDS.has(id);
}

function getPlaybookIdForLegacyIntent(intent) {
  return LEGACY_INTENT_PLAYBOOK_IDS[String(intent || '')] || null;
}

function getPlaybookIdForLaunchIntent(intent) {
  return LEGACY_LAUNCH_INTENT_PLAYBOOK_IDS[String(intent || '')] || null;
}

function validatePlaybookCatalog({ devOnly = true } = {}) {
  if (devOnly) {
    const host = typeof window !== 'undefined' ? window.location?.hostname : '';
    const isDevHost = ['', 'localhost', '127.0.0.1', '::1'].includes(host);
    if (!isDevHost) return [];
  }

  const warnings = [];
  const warn = (message) => {
    warnings.push(message);
    if (typeof console !== 'undefined' && console.warn) {
      console.warn(`[playbooks] ${message}`);
    }
  };

  Object.entries(PLAYBOOK_COPY).forEach(([language, catalog]) => {
    PLAYBOOK_ORDER.forEach((id) => {
      const playbook = catalog?.[id];
      if (!playbook) {
        warn(`${language}.${id} is missing`);
        return;
      }
      REQUIRED_CANONICAL_PLAYBOOK_FIELDS.forEach((field) => {
        const value = playbook[field];
        const missingArray = Array.isArray(value) && value.length === 0;
        if (value === undefined || value === null || value === '' || missingArray) {
          warn(`${language}.${id}.${field} is missing`);
        }
      });
    });
  });

  return warnings;
}

export {
  PLAYBOOK_ORDER,
  PLAYBOOK_INTENT_TAXONOMY,
  REQUIRED_CANONICAL_PLAYBOOK_FIELDS,
  getPlaybookById,
  getPlaybookIntentGroups,
  getPlaybooks,
  isPlaybookId,
  resolvePlaybookIdForNewSession,
  resolvePlaybookForNewSession,
  isModeBackedPlaybook,
  isProductPlaybook,
  getPlaybookIdForLegacyIntent,
  getPlaybookIdForLaunchIntent,
  validatePlaybookCatalog,
};

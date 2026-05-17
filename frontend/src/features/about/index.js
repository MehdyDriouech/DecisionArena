/** Canonical contact for About page (mailto target). Display copy comes from i18n. */

const CONTACT_EMAIL = 'driouechmehdy.pro@gmail.com';



function esc(s) {

  return window.DecisionArena.utils.escHtml(String(s ?? ''));

}



function tx(t, key) {

  return esc(t(key));

}



function liFromKey(t, key) {

  return `<li>${tx(t, key)}</li>`;

}



function renderGridCards(itemsHtml, gridClass = 'about-grid') {

  return `<div class="${gridClass}">${itemsHtml}</div>`;

}



function renderVisibleCard(t, titleKey, descKey) {

  return `<article class="about-card card about-card--visible">

    <h3 class="about-card__title">${tx(t, titleKey)}</h3>

    <p class="about-card__desc">${tx(t, descKey)}</p>

  </article>`;

}



function renderModeCard(t, id, icon) {

  return `<article class="about-card card about-mode-card">

    <span class="about-card__icon" aria-hidden="true">${icon}</span>

    <h3 class="about-card__title">${tx(t, `about.modes.${id}.title`)}</h3>

    <p class="about-card__desc">${tx(t, `about.modes.${id}.desc`)}</p>

  </article>`;

}



function renderCapabilityCard(t, key) {

  return `<article class="about-card card about-card--capability">

    <p class="about-card__body">${tx(t, key)}</p>

  </article>`;

}



function renderAudienceChip(t, key) {

  return `<span class="about-audience-chip">${tx(t, key)}</span>`;

}



function renderAboutView() {

  const t = (k) => (window.i18n?.t?.(k) ?? k);

  const mailHref = `mailto:${CONTACT_EMAIL}`;



  const visibleItems = [

    ['about.visible.disagreements.title', 'about.visible.disagreements.desc'],

    ['about.visible.hypotheses.title', 'about.visible.hypotheses.desc'],

    ['about.visible.blindSpots.title', 'about.visible.blindSpots.desc'],

    ['about.visible.risks.title', 'about.visible.risks.desc'],

    ['about.visible.falseConsensus.title', 'about.visible.falseConsensus.desc'],

    ['about.visible.arguments.title', 'about.visible.arguments.desc'],

    ['about.visible.robustness.title', 'about.visible.robustness.desc'],

  ];



  const capabilityKeys = [

    'about.capabilities.createAnalysis',

    'about.capabilities.personas',

    'about.capabilities.compareViews',

    'about.capabilities.challengeHypotheses',

    'about.capabilities.falseConsensus',

    'about.capabilities.testRobustness',

    'about.capabilities.synthesis',

    'about.capabilities.history',

    'about.capabilities.export',

    'about.capabilities.signals',

    'about.capabilities.local',

  ];



  const modeDefs = [

    ['quickDecision', '⚡'],

    ['debate', '◆'],

    ['confrontation', '⇄'],

    ['stressTest', '◎'],

    ['jury', '⚖'],

    ['personaChat', '✦'],

  ];



  const audienceKeys = [

    'about.audience.product',

    'about.audience.founders',

    'about.audience.managers',

    'about.audience.consultants',

    'about.audience.researchers',

    'about.audience.coaches',

    'about.audience.innovation',

    'about.audience.tech',

    'about.audience.business',

    'about.audience.freelancers',

    'about.audience.curious',

  ];



  const archKeys = [

    'about.local.arch.php',

    'about.local.arch.frontend',

    'about.local.arch.sqlite',

    'about.local.arch.providers',

    'about.local.arch.execution',

  ];



  const communityFeedbackKeys = [

    'about.community.feedback.bug',

    'about.community.feedback.analysis',

    'about.community.feedback.usability',

    'about.community.feedback.ux',

    'about.community.feedback.useCase',

    'about.community.feedback.feature',

    'about.community.feedback.contribution',

    'about.community.feedback.partnership',

  ];



  const futureFeatureKeys = [

    'about.future.feature.collaboration',

    'about.future.feature.governance',

    'about.future.feature.audit',

    'about.future.feature.portfolio',

    'about.future.feature.integration',

    'about.future.feature.workflows',

    'about.future.feature.dashboards',

    'about.future.feature.history',

    'about.future.feature.scale',

  ];



  const principleKeys = [

    'about.expert.principles.simpleArchitecture',

    'about.expert.principles.separation',

    'about.expert.principles.structuredModes',

    'about.expert.principles.personas',

    'about.expert.principles.persistence',

    'about.expert.principles.traceability',

  ];

  const contributorKeys = [

    'about.expert.contributors.readContext',

    'about.expert.contributors.checkConventions',

    'about.expert.contributors.keepDataAttributes',

    'about.expert.contributors.avoidRefactors',

    'about.expert.contributors.tests',

    'about.expert.contributors.documentAssumptions',

  ];



  const heroChips = [

    'about.hero.chip.agents',

    'about.hero.chip.debate',

    'about.hero.chip.traceable',

  ];



  return `

    <article class="about-page">

      <header class="about-hero">

        <span class="about-hero__badge badge">${tx(t, 'about.hero.badge')}</span>

        <h1 class="about-hero__title">${tx(t, 'about.title')}</h1>

        <p class="about-hero__tagline">${tx(t, 'about.hero.tagline')}</p>

        <p class="about-hero__subtext">${tx(t, 'about.hero.subtext')}</p>

        <div class="about-hero__chips" role="list" aria-label="${tx(t, 'about.hero.chipsAria')}">

          ${heroChips.map((k) => `<span class="about-hero__chip" role="listitem">${tx(t, k)}</span>`).join('')}

        </div>

      </header>



      <section class="about-section" aria-labelledby="about-why-heading">

        <h2 id="about-why-heading" class="about-section__title">${tx(t, 'about.why.title')}</h2>

        <article class="card about-section-card">
          <div class="about-section__lead">
            <p>${tx(t, 'about.why.body1')}</p>
            <p>${tx(t, 'about.why.body2')}</p>
          </div>
          <blockquote class="about-quote about-quote--in-card">
            <p>${tx(t, 'about.why.quote')}</p>
          </blockquote>
        </article>
      </section>

      <section class="about-section" aria-labelledby="about-visible-heading">

        <h2 id="about-visible-heading" class="about-section__title">${tx(t, 'about.visible.title')}</h2>

        <p class="about-section__intro">${tx(t, 'about.visible.intro')}</p>

        ${renderGridCards(visibleItems.map(([titleKey, descKey]) => renderVisibleCard(t, titleKey, descKey)).join(''), 'about-grid about-grid--visible')}

      </section>



      <section class="about-section" aria-labelledby="about-capabilities-heading">

        <h2 id="about-capabilities-heading" class="about-section__title">${tx(t, 'about.capabilities.title')}</h2>

        ${renderGridCards(capabilityKeys.map((k) => renderCapabilityCard(t, k)).join(''), 'about-grid about-grid--capabilities')}

      </section>



      <section class="about-section" aria-labelledby="about-modes-heading">

        <h2 id="about-modes-heading" class="about-section__title">${tx(t, 'about.modes.title')}</h2>

        ${renderGridCards(modeDefs.map(([id, icon]) => renderModeCard(t, id, icon)).join(''), 'about-grid about-grid--modes')}

      </section>



      <section class="about-section" aria-labelledby="about-audience-heading">

        <h2 id="about-audience-heading" class="about-section__title">${tx(t, 'about.audience.title')}</h2>

        <div class="about-audience-chips">

          ${audienceKeys.map((k) => renderAudienceChip(t, k)).join('')}

        </div>

        <p class="about-section__closing">${tx(t, 'about.audience.closing')}</p>

      </section>



      <section class="about-section" aria-labelledby="about-local-heading">

        <h2 id="about-local-heading" class="about-section__title">${tx(t, 'about.local.title')}</h2>

        <article class="about-callout card">

          <p class="about-callout__lead">${tx(t, 'about.local.intro')}</p>

          <h3 class="about-callout__subtitle">${tx(t, 'about.local.archTitle')}</h3>

          <ul class="about-arch-list">

            ${archKeys.map((k) => liFromKey(t, k)).join('')}

          </ul>

          <p class="about-callout__closing">${tx(t, 'about.local.closing')}</p>

        </article>

      </section>



      <section class="about-section" aria-labelledby="about-community-heading">

        <h2 id="about-community-heading" class="about-section__title">${tx(t, 'about.community.title')}</h2>

        <article class="about-callout about-callout--community card">

          <p>${tx(t, 'about.community.body1')}</p>

          <p>${tx(t, 'about.community.body2')}</p>

          <p>${tx(t, 'about.community.body3')}</p>

          <p>${tx(t, 'about.community.body4')}</p>

          <h3 class="about-callout__subtitle">${tx(t, 'about.community.feedbackTitle')}</h3>

          <ul class="about-feedback-list">

            ${communityFeedbackKeys.map((k) => liFromKey(t, k)).join('')}

          </ul>

        </article>

      </section>



      <section class="about-section" aria-labelledby="about-future-heading">
        <h2 id="about-future-heading" class="about-section__title">${tx(t, 'about.future.title')}</h2>
        <article class="card about-section-card about-section-card--future">
          <p class="about-section__intro">${tx(t, 'about.future.intro')}</p>
          <p class="about-section__intro">${tx(t, 'about.future.subintro')}</p>
          <ul class="about-future-list">
            ${futureFeatureKeys.map((k) => liFromKey(t, k)).join('')}
          </ul>
          <p class="about-section__closing">${tx(t, 'about.future.closing1')}</p>
          <p class="about-section__closing about-section__closing--muted">${tx(t, 'about.future.closing2')}</p>
        </article>
      </section>



      <section class="about-section" aria-labelledby="about-philosophy-heading">

        <h2 id="about-philosophy-heading" class="about-section__title">${tx(t, 'about.philosophy.title')}</h2>

        <blockquote class="about-quote about-quote--manifesto">

          <p>${tx(t, 'about.philosophy.p1')}</p>

          <p class="about-quote__emphasis">${tx(t, 'about.philosophy.p2')}</p>

          <p>${tx(t, 'about.philosophy.p3')}</p>

          <p>${tx(t, 'about.philosophy.p4')}</p>

          <p>${tx(t, 'about.philosophy.p5')}</p>

          <p class="about-quote__closing">${tx(t, 'about.philosophy.p6')}</p>

        </blockquote>

      </section>



      <section class="about-section about-contact" aria-labelledby="about-contact-heading">
        <article class="card about-contact__card">
          <h2 id="about-contact-heading" class="about-contact__title">${tx(t, 'about.contact.title')}</h2>
          <p class="about-contact__text">${tx(t, 'about.contact.body1')}</p>
          <div class="about-contact__actions">
            <a class="btn btn-primary about-contact__btn" href="${mailHref}" aria-label="${tx(t, 'about.contact.mailtoAria')}">${tx(t, 'about.contact.cta')}</a>
            <a class="about-mailto" href="${mailHref}">${tx(t, 'about.contact.email')}</a>
          </div>
          <p class="about-contact__thanks">${tx(t, 'about.contact.thanks')}</p>
        </article>
      </section>



      <section class="about-section about-expert-block" data-ui="expert-only" aria-labelledby="about-expert-heading">

        <h2 id="about-expert-heading" class="about-section__title">${tx(t, 'about.expert.title')}</h2>

        <article class="card about-card about-card--expert">

          <p class="about-p">${tx(t, 'about.expert.intro')}</p>

          <h3 class="about-subsection-heading">${tx(t, 'about.expert.principles.title')}</h3>

          <ul class="about-list">

            ${principleKeys.map((k) => liFromKey(t, k)).join('')}

          </ul>

          <h3 class="about-subsection-heading">${tx(t, 'about.expert.caution.title')}</h3>

          <p class="about-p">${tx(t, 'about.expert.caution.body1')}</p>

          <p class="about-p">${tx(t, 'about.expert.caution.body2')}</p>

          <h3 class="about-subsection-heading">${tx(t, 'about.expert.contributors.title')}</h3>

          <p class="about-p">${tx(t, 'about.expert.contributors.body1')}</p>

          <ul class="about-list about-list-tight">

            ${contributorKeys.map((k) => liFromKey(t, k)).join('')}

          </ul>

          <p class="about-p about-p-last">${tx(t, 'about.expert.contributors.body2')}</p>

        </article>

      </section>

    </article>

  `;

}



function registerAboutFeature() {

  window.DecisionArena.views.about = renderAboutView;

}



export { registerAboutFeature, renderAboutView };


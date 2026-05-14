/** Canonical contact for About page (mailto target). Display copy comes from i18n. */
const CONTACT_EMAIL = 'driouechmehdy.pro@gmail.com';

function esc(s) {
  return window.DecisionArena.utils.escHtml(String(s ?? ''));
}

function liFromKey(t, key) {
  return `<li>${esc(t(key))}</li>`;
}

function renderAboutView() {
  const t = (k) => (window.i18n?.t?.(k) ?? k);
  const mailHref = `mailto:${CONTACT_EMAIL}`;

  const featHeadKeys = [
    'about.features.createAnalysis',
    'about.features.personas',
    'about.features.compareViews',
  ];
  const modeKeys = [
    'about.features.modeQuickDecision',
    'about.features.modeDebate',
    'about.features.modeConfrontation',
    'about.features.modeStressTest',
    'about.features.modeJury',
    'about.features.modePersonaChat',
  ];
  const featTailKeys = [
    'about.features.synthesis',
    'about.features.history',
    'about.features.signals',
    'about.features.export',
    'about.features.local',
  ];
  const feedbackKeys = [
    'about.contact.feedback.bug',
    'about.contact.feedback.display',
    'about.contact.feedback.analysis',
    'about.contact.feedback.feature',
    'about.contact.feedback.useCase',
    'about.contact.feedback.contribution',
    'about.contact.feedback.paidVersion',
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

  return `
    <article class="about-page">
      <header class="page-header">
        <h1 class="page-title">${esc(t('about.title'))}</h1>
      </header>

      <section class="card about-card">
        <p class="about-p">${esc(t('about.intro.part1'))}</p>
        <p class="about-p">${esc(t('about.intro.part2'))}</p>
        <p class="about-p about-p-last">${esc(t('about.intro.part3'))}</p>
      </section>

      <section class="card about-card" aria-labelledby="about-features-heading">
        <h2 id="about-features-heading" class="about-section-heading">${esc(t('about.features.title'))}</h2>
        <ul class="about-list">
          ${featHeadKeys.map((k) => liFromKey(t, k)).join('')}
          <li>${esc(t('about.features.modes'))}
            <ul class="about-sublist">
              ${modeKeys.map((k) => liFromKey(t, k)).join('')}
            </ul>
          </li>
          ${featTailKeys.map((k) => liFromKey(t, k)).join('')}
        </ul>
      </section>

      <section class="card about-card" aria-labelledby="about-community-heading">
        <h2 id="about-community-heading" class="about-section-heading">${esc(t('about.community.title'))}</h2>
        <p class="about-p">${esc(t('about.community.body1'))}</p>
        <p class="about-p">${esc(t('about.community.body2'))}</p>
        <p class="about-p">${esc(t('about.community.body3'))}</p>
        <p class="about-p">${esc(t('about.community.body4'))}</p>
        <p class="about-p about-p-last">${esc(t('about.community.body5'))}</p>
      </section>

      <section class="card about-card" aria-labelledby="about-why-heading">
        <h2 id="about-why-heading" class="about-section-heading">${esc(t('about.why.title'))}</h2>
        <p class="about-p">${esc(t('about.why.body1'))}</p>
        <p class="about-p">${esc(t('about.why.body2'))}</p>
        <blockquote class="about-quote"><p>${esc(t('about.why.quote'))}</p></blockquote>
        <p class="about-p about-p-last">${esc(t('about.why.body3'))}</p>
      </section>

      <section class="card about-card" aria-labelledby="about-contact-heading">
        <h2 id="about-contact-heading" class="about-section-heading">${esc(t('about.contact.title'))}</h2>
        <p class="about-p">${esc(t('about.contact.body1'))}</p>
        <p class="about-p">${esc(t('about.contact.body2'))}</p>
        <p class="about-p">
          <a class="about-mailto" href="${mailHref}" aria-label="${esc(t('about.contact.mailtoAria'))}">${esc(t('about.contact.email'))}</a>
        </p>
        <p class="about-p">${esc(t('about.contact.feedbackIntro'))}</p>
        <ul class="about-list about-list-tight">
          ${feedbackKeys.map((k) => liFromKey(t, k)).join('')}
        </ul>
        <p class="about-p about-p-last about-thanks">${esc(t('about.contact.thanks'))}</p>
      </section>

      <section class="card about-card about-expert-block" data-ui="expert-only" aria-labelledby="about-expert-heading">
        <h2 id="about-expert-heading" class="about-section-heading">${esc(t('about.expert.title'))}</h2>
        <p class="about-p">${esc(t('about.expert.intro'))}</p>
        <h3 class="about-subsection-heading">${esc(t('about.expert.principles.title'))}</h3>
        <ul class="about-list">
          ${principleKeys.map((k) => liFromKey(t, k)).join('')}
        </ul>
        <h3 class="about-subsection-heading">${esc(t('about.expert.caution.title'))}</h3>
        <p class="about-p">${esc(t('about.expert.caution.body1'))}</p>
        <p class="about-p">${esc(t('about.expert.caution.body2'))}</p>
        <h3 class="about-subsection-heading">${esc(t('about.expert.contributors.title'))}</h3>
        <p class="about-p">${esc(t('about.expert.contributors.body1'))}</p>
        <ul class="about-list about-list-tight">
          ${contributorKeys.map((k) => liFromKey(t, k)).join('')}
        </ul>
        <p class="about-p about-p-last">${esc(t('about.expert.contributors.body2'))}</p>
      </section>
    </article>
  `;
}

function registerAboutFeature() {
  window.DecisionArena.views.about = renderAboutView;
}

export { registerAboutFeature, renderAboutView };

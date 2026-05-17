import { shouldShowMobileLayoutNotice } from '../core/store.js';

const HOST_ID = 'da-mobile-layout-notice-host';

function t(key) {
  return window.i18n?.t(key) ?? key;
}

function escHtml(s) {
  return window.DecisionArena?.utils?.escHtml ? window.DecisionArena.utils.escHtml(s) : String(s);
}

function renderMobileLayoutNoticeMarkup() {
  return [
    '<div class="mobile-layout-notice" role="presentation">',
    '  <div class="mobile-layout-notice__overlay" aria-hidden="true"></div>',
    '  <div',
    '    class="mobile-layout-notice__card"',
    '    role="dialog"',
    '    aria-modal="true"',
    '    aria-labelledby="mobile-layout-notice-title"',
    '    aria-describedby="mobile-layout-notice-body mobile-layout-notice-helper"',
    '  >',
    `    <span class="mobile-layout-notice__badge">${escHtml(t('mobileNotice.badge'))}</span>`,
    `    <h2 id="mobile-layout-notice-title" class="mobile-layout-notice__title">${escHtml(t('mobileNotice.title'))}</h2>`,
    `    <p id="mobile-layout-notice-body" class="mobile-layout-notice__body">${escHtml(t('mobileNotice.body'))}</p>`,
    `    <p id="mobile-layout-notice-helper" class="mobile-layout-notice__helper">${escHtml(t('mobileNotice.helper'))}</p>`,
    '    <div class="mobile-layout-notice__actions">',
    '      <button type="button" class="btn btn-primary" data-action="dismiss-mobile-layout-notice">',
    `        ${escHtml(t('mobileNotice.continue'))}`,
    '      </button>',
    '      <button type="button" class="btn btn-secondary" data-action="dismiss-mobile-layout-notice">',
    `        ${escHtml(t('mobileNotice.understood'))}`,
    '      </button>',
    '    </div>',
    '  </div>',
    '</div>',
  ].join('\n');
}

function renderMobileLayoutNotice(state = window.DecisionArena?.store?.state) {
  if (typeof document === 'undefined') return;
  let host = document.getElementById(HOST_ID);
  if (!host) {
    host = document.createElement('div');
    host.id = HOST_ID;
    document.body.appendChild(host);
  }
  host.innerHTML = shouldShowMobileLayoutNotice(state) ? renderMobileLayoutNoticeMarkup() : '';
}

let viewportListenerBound = false;

function bindMobileLayoutNoticeViewportListener() {
  if (viewportListenerBound || typeof window === 'undefined' || !window.matchMedia) return;
  viewportListenerBound = true;
  const mq = window.matchMedia('(max-width: 768px)');
  let wasMobile = mq.matches;
  mq.addEventListener('change', () => {
    const isMobile = mq.matches;
    if (isMobile === wasMobile) return;
    wasMobile = isMobile;
    window.DecisionArena?.render?.();
  });
}

export { renderMobileLayoutNotice, bindMobileLayoutNoticeViewportListener };

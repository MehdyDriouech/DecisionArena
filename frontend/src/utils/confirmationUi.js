let confirmationSeq = 0;

function uiCopy(fr, en) {
  return window.i18n?.getLanguage?.() === 'en' ? en : fr;
}

function requestConfirmation(state, config = {}) {
  if (!state || !config.action) return null;
  const id = config.id || `confirm-${Date.now()}-${++confirmationSeq}`;
  state.pendingConfirmation = {
    id,
    mode: config.mode === 'inline' ? 'inline' : 'modal',
    tone: config.tone || 'warning',
    title: config.title || uiCopy('Confirmer cette action ?', 'Confirm this action?'),
    body: config.body || '',
    expertBody: config.expertBody || '',
    confirmLabel: config.confirmLabel || uiCopy('Confirmer', 'Confirm'),
    cancelLabel: config.cancelLabel || uiCopy('Annuler', 'Cancel'),
    action: config.action,
    payload: config.payload || {},
    fields: Array.isArray(config.fields) ? config.fields : [],
    anchor: config.anchor || null,
    fieldError: '',
  };
  return state.pendingConfirmation;
}

function isConfirmationConfirmed(context = {}) {
  return context.confirmed === true || context.element?.dataset?.confirmed === '1';
}

function confirmationPayload(context = {}, element = null) {
  return {
    ...(element?.dataset || {}),
    ...(context.confirmationPayload || {}),
  };
}

export { requestConfirmation, isConfirmationConfirmed, confirmationPayload, uiCopy };

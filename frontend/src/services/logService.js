// API_BASE is a static const in apiClient.js — safe to import here.
// apiClient.js does NOT import logService.js (it uses window.DecisionArena.services.LogService),
// so there is no circular ES module dependency.
import { API_BASE } from './apiClient.js';

function safeJson(obj) {
  try { return JSON.stringify(obj); } catch (_) { return '{}'; }
}

async function postFrontendLog(payload) {
  try {
    await fetch(API_BASE + '/api/logs/frontend', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: safeJson(payload),
    });
  } catch (_) {
    // Never break UI for logging
  }
}

const LogService = {
  logFrontendEvent(level, category, data = {}) {
    const payload = {
      level: level || 'info',
      category: category || 'ui_action',
      action: data.action || null,
      session_id: data.session_id || null,
      agent_id: data.agent_id || null,
      provider_id: data.provider_id || null,
      model: data.model || null,
      metadata: data.metadata || null,
      error_message: data.error_message || null,
    };
    return postFrontendLog(payload);
  },

  logUiAction(action, metadata = {}, level = 'info') {
    return this.logFrontendEvent(level, 'ui_action', { action, metadata });
  },

  logNavigation(view, fromView = null) {
    return this.logFrontendEvent('info', 'frontend', {
      action: 'navigate',
      metadata: { view, from: fromView },
    });
  },

  logApiError(path, errMessage, metadata = {}) {
    // Avoid recursion if logging endpoint fails
    if (String(path || '').includes('/api/logs/frontend')) return;
    return this.logFrontendEvent('error', 'frontend', {
      action: 'api_error',
      metadata: { path, ...metadata },
      error_message: errMessage,
    });
  },
};

export { LogService };


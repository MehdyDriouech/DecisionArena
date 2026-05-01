import { API_BASE, apiFetch } from './apiClient.js';

const ChatService = {
  send(body, signal) {
    return apiFetch('/api/chat/send', {
      method: 'POST',
      signal,
      body: JSON.stringify(body),
    });
  },
  sendReactive(body, signal) {
    return apiFetch('/api/chat/reactive', {
      method: 'POST',
      signal,
      body: JSON.stringify(body),
    });
  },
  runDecisionRoom(body) {
    return apiFetch('/api/decision-room/run', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },
  runConfrontation(body) {
    return apiFetch('/api/confrontation/run', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },
  runQuickDecision(body) {
    return apiFetch('/api/quick-decision/run', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },
  runStressTest(body) {
    return apiFetch('/api/stress-test/run', {
      method: 'POST',
      body: JSON.stringify(body),
    });
  },
  async uploadContextDocument(sessionId, title, file) {
    const fd = new FormData();
    fd.append('file', file);
    if (title) fd.append('title', title);
    const res = await fetch(API_BASE + `/api/sessions/${sessionId}/context-document/upload`, {
      method: 'POST',
      body: fd,
    });
    if (!res.ok) {
      const text = await res.text().catch(() => '');
      let msg = `HTTP ${res.status}`;
      try {
        const parsed = JSON.parse(text);
        msg = parsed.message || msg;
      } catch (_) {}
      throw new Error(msg);
    }
    return res.json();
  },
  getContextDocument(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/context-document`);
  },
  saveContextDocumentManual(sessionId, title, content) {
    return apiFetch(`/api/sessions/${sessionId}/context-document/manual`, {
      method: 'POST',
      body: JSON.stringify({ title, content }),
    });
  },
  deleteContextDocument(sessionId) {
    return apiFetch(`/api/sessions/${sessionId}/context-document`, {
      method: 'DELETE',
    });
  },
};

export { ChatService };

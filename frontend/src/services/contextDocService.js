/* Context Document Service — thin API wrapper (no state mutations here) */

function _api() { return window.DecisionArena.services.apiFetch; }
function _base() { return window.DecisionArena.services.API_BASE; }

async function loadContextDoc(sessionId) {
  try {
    const data = await _api()(`/api/sessions/${sessionId}/context-document`);
    return data.context_document || null;
  } catch (_) {
    return null;
  }
}

async function saveManual(sessionId, title, content) {
  return _api()(`/api/sessions/${sessionId}/context-document/manual`, {
    method: 'POST',
    body: JSON.stringify({ title, content }),
  });
}

async function upload(sessionId, title, file) {
  const fd = new FormData();
  fd.append('file', file);
  if (title) fd.append('title', title);
  const res = await fetch(_base() + `/api/sessions/${sessionId}/context-document/upload`, {
    method: 'POST',
    body: fd,
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    let msg = `HTTP ${res.status}`;
    try { const j = JSON.parse(text); msg = j.message || msg; } catch (_) {}
    throw new Error(msg);
  }
  return res.json();
}

async function remove(sessionId) {
  return _api()(`/api/sessions/${sessionId}/context-document`, { method: 'DELETE' });
}

export const ContextDocService = { loadContextDoc, saveManual, upload, remove };

import { apiFetch } from './apiClient.js';

export async function fetchDemoAuthConfig() {
  try {
    return await apiFetch('/api/demo-auth/config');
  } catch (_) {
    return { auth_required: false };
  }
}

export async function fetchDemoMe() {
  try {
    return await apiFetch('/api/demo-auth/me');
  } catch (_) {
    return { auth_required: false, authenticated: false, user: null };
  }
}

export async function demoLogin(login, password) {
  return apiFetch('/api/demo-auth/login', {
    method: 'POST',
    body: JSON.stringify({ login, password }),
  });
}

export async function demoLogout() {
  return apiFetch('/api/demo-auth/logout', { method: 'POST', body: '{}' });
}

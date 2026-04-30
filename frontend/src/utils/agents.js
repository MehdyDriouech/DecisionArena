import { escHtml } from './html.js';

function getPersonaById(personas, id) {
  return (personas || []).find((p) => p.id === id) || null;
}

function agentIcon(personas, id) {
  const persona = getPersonaById(personas, id);
  return persona ? escHtml(persona.icon || '🤖') : '🤖';
}

function agentName(personas, id) {
  const persona = getPersonaById(personas, id);
  return persona ? (persona.name || id) : id;
}

function agentTitleText(personas, id) {
  const persona = getPersonaById(personas, id);
  return persona ? (persona.title || '') : '';
}

export {
  getPersonaById,
  agentIcon,
  agentName,
  agentTitleText,
};

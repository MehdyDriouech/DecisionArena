import {
  ensureOpenSpaceState,
  renderOpenSpaceContextSwitcher,
  renderContextRequiredEmptyState,
  selectedContextFromState,
  t,
  esc,
} from './shared.js';

function renderProposal(os) {
  const proposal = os.orchestratorProposal;
  if (!proposal) return '';
  let parsed = null;
  try {
    parsed = typeof proposal.proposal_json === 'string' ? JSON.parse(proposal.proposal_json) : proposal.proposal_json;
  } catch (_) {
    parsed = null;
  }
  const tasks = Array.isArray(parsed?.proposed_tasks) ? parsed.proposed_tasks : [];
  const agents = Array.isArray(parsed?.recommended_agents) ? parsed.recommended_agents : [];
  const risks = Array.isArray(parsed?.risks) ? parsed.risks : [];
  const questions = Array.isArray(parsed?.open_questions) ? parsed.open_questions : [];
  const assumptions = Array.isArray(parsed?.assumptions) ? parsed.assumptions : [];
  const nextAction = String(parsed?.next_recommended_action || '').trim();

  return `
    <div class="card openspace-card openspace-proposal-card">
      <h3 class="openspace-section-title">${esc(t('openspace.orchestrator.result'))}</h3>
      <div class="openspace-kv"><strong>${esc(t('openspace.orchestrator.recommendedMode'))}:</strong> ${esc(parsed?.recommended_mode || 'open-space')}</div>
      <div class="openspace-kv"><strong>${esc(t('openspace.orchestrator.modeRationale'))}:</strong> ${esc(parsed?.mode_rationale || '—')}</div>
      <div class="openspace-kv"><strong>${esc(t('openspace.orchestrator.recommendedAgents'))}:</strong> ${esc(agents.map((a) => `${a?.agent_id || ''}${a?.reason ? ` (${a.reason})` : ''}`).join(', ') || '—')}</div>
      <div class="openspace-kv"><strong>${esc(t('openspace.orchestrator.proposalSource'))}:</strong> ${esc(proposal.proposal_source || 'llm')}</div>
      ${proposal.warning ? `<div class="openspace-warning-banner">${esc(t('openspace.orchestrator.warningFallback'))}</div>` : ''}
      <div class="openspace-section-label">${esc(t('openspace.orchestrator.proposedTasks'))}</div>
      <ul class="openspace-list">${tasks.map((task) => `<li>${esc(task?.title || '')} <span class="openspace-tag">${esc(task?.status || 'backlog')}</span></li>`).join('')}</ul>
      <div class="openspace-section-label">${esc(t('openspace.orchestrator.risks'))}</div>
      <ul class="openspace-list">${risks.map((row) => `<li>${esc(row)}</li>`).join('')}</ul>
      <div class="openspace-section-label">${esc(t('openspace.orchestrator.questions'))}</div>
      <ul class="openspace-list">${questions.map((row) => `<li>${esc(row)}</li>`).join('')}</ul>
      <div class="openspace-section-label">${esc(t('openspace.orchestrator.assumptions'))}</div>
      <ul class="openspace-list">${assumptions.map((row) => `<li>${esc(row)}</li>`).join('')}</ul>
      ${nextAction ? `<div class="openspace-kv"><strong>${esc(t('openspace.orchestrator.nextAction'))}:</strong> ${esc(nextAction)}</div>` : ''}
      <button type="button" class="btn btn-primary btn-sm" data-action="open-space-accept-proposal" data-proposal-id="${esc(proposal.id || '')}">
        ${esc(t('openspace.orchestrator.createTasks'))}
      </button>
    </div>
  `;
}

function renderOpenSpaceOrchestrator() {
  const state = window.DecisionArena.store.state;
  const os = ensureOpenSpaceState(state);
  const selectedContextId = selectedContextFromState(state);
  const loading = os.loading ? '<span class="spinner"></span>' : '';
  const errorBlock = os.error ? `<div class="error-banner">⚠️ ${esc(os.error)}</div>` : '';
  const switcher = renderOpenSpaceContextSwitcher(state);
  if (!selectedContextId) {
    return `
      <div class="page-header">
        <div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.orchestrator'))}</div>
      </div>
      ${switcher}
      ${renderContextRequiredEmptyState()}
    `;
  }

  return `
    <div class="page-header">
      <div class="page-title">${esc(t('openspace.title'))} · ${esc(t('openspace.orchestrator'))}</div>
      <div class="page-subtitle">${esc(t('openspace.orchestrator.subtitle'))}</div>
    </div>
    ${switcher}
    ${errorBlock}
    <div class="card openspace-card">
      <label class="openspace-label">${esc(t('openspace.orchestrator.objective'))}</label>
      <textarea class="textarea openspace-textarea" data-action="open-space-set-field" data-field="orchestratorObjective" rows="4" placeholder="${esc(t('openspace.orchestrator.objectivePh'))}">${esc(os.orchestratorObjective)}</textarea>
      <label class="openspace-label">${esc(t('openspace.orchestrator.constraints'))}</label>
      <textarea class="textarea openspace-textarea" data-action="open-space-set-field" data-field="orchestratorConstraints" rows="4" placeholder="${esc(t('openspace.orchestrator.constraintsPh'))}">${esc(os.orchestratorConstraints)}</textarea>
      <div class="openspace-actions-row">
        <button type="button" class="btn btn-primary btn-sm" data-action="open-space-propose-plan">${loading} ${esc(t('openspace.orchestrator.propose'))}</button>
      </div>
    </div>
    ${renderProposal(os)}
  `;
}

export { renderOpenSpaceOrchestrator };


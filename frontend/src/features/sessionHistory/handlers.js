/* Session History feature — action handlers (rerun modal + action plan + memory) */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:          a.store.state,
    render:         () => a.render?.(),
    navigate:       (v) => a.router.navigate(v),
    apiFetch:       a.services.apiFetch,
    SessionService: a.services.SessionService,
    escHtml:        a.utils.escHtml,
    t:              (key) => window.i18n?.t(key) ?? key,
  };
}

function renderRerunModal() {
  const { state, escHtml, t } = getCtx();
  const rm  = state.rerunModal;
  if (!rm?.open) return '';

  const session = state.sessions.find((s) => s.id === rm.sessionId) || {};
  const modes   = ['decision-room', 'confrontation', 'quick-decision', 'stress-test', 'chat'];
  const variationOptions = [
    { id: 'more-disagreement',    label: t('rerun.moreDisagreement') },
    { id: 'more-critical-agents', label: t('rerun.moreCritical') },
    { id: 'fewer-agents',         label: t('rerun.fewerAgents') },
    { id: 'different-mode',       label: t('rerun.differentMode') },
    { id: 'different-language',   label: t('rerun.differentLanguage') },
  ];

  return `
    <div class="persona-modal-overlay" id="rerun-modal-overlay">
      <div class="persona-modal" style="max-width:540px;">
        <button class="persona-modal-close" data-action="close-rerun-modal">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:4px;">🔁 ${t('rerun.title')}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">"${escHtml(session.title || rm.sessionId)}"</div>
        <div style="margin-bottom:16px;">
          <div style="font-weight:600;font-size:13px;margin-bottom:10px;">${t('rerun.selectVariations')}</div>
          ${variationOptions.map((v) => `
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer;font-size:13px;">
              <input type="checkbox" data-action="toggle-rerun-variation" data-variation="${escHtml(v.id)}" ${rm.variations.includes(v.id) ? 'checked' : ''} style="accent-color:var(--accent);">
              ${v.label}
            </label>
          `).join('')}
        </div>
        ${rm.variations.includes('different-mode') ? `
          <div class="form-group"><label>${t('rerun.targetMode')}</label>
            <select class="input" id="rerun-target-mode">
              ${modes.map((m) => `<option value="${m}" ${rm.targetMode === m ? 'selected' : ''}>${m}</option>`).join('')}
            </select>
          </div>` : ''}
        ${rm.variations.includes('different-language') ? `
          <div class="form-group"><label>${t('newSession.responseLanguage')}</label>
            <select class="input" id="rerun-language">
              <option value="fr" ${rm.language === 'fr' ? 'selected' : ''}>🇫🇷 Français</option>
              <option value="en" ${rm.language === 'en' ? 'selected' : ''}>🇬🇧 English</option>
            </select>
          </div>` : ''}
        <div class="form-group">
          <label>${t('rerun.customInstruction')}</label>
          <textarea class="textarea" id="rerun-custom-instruction" style="min-height:70px;" placeholder="${t('rerun.customInstructionPlaceholder')}">${escHtml(rm.customInstruction || '')}</textarea>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;font-size:13px;">
          <input type="checkbox" id="rerun-keep-context" ${rm.keepContext ? 'checked' : ''} style="accent-color:var(--accent);">
          ${t('rerun.keepContextDoc')}
        </label>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-primary" data-action="apply-rerun" ${rm.loading ? 'disabled' : ''}>
            ${rm.loading ? '<span class="spinner"></span>' : '🔁'} ${t('rerun.apply')}
          </button>
          <button class="btn btn-secondary" data-action="close-rerun-modal">${t('la.back')}</button>
        </div>
      </div>
    </div>
  `;
}

function upsertRerunModal() {
  const html = renderRerunModal();
  const existing = document.getElementById('rerun-modal-overlay');
  if (existing) { existing.outerHTML = html; }
  else if (html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    if (div.firstElementChild) document.body.appendChild(div.firstElementChild);
  }
}

function registerSessionHistoryHandlers() {
  /* ── Rerun Modal ──────────────────────────────────────────────────────── */
  registerAction('open-rerun-modal', ({ element }) => {
    const { state, render } = getCtx();
    const sid  = element.dataset.sessionId;
    const sess = state.sessions.find((s) => s.id === sid) || {};
    state.rerunModal = {
      open: true, sessionId: sid, variations: [],
      targetMode: sess.mode || 'decision-room',
      language:   sess.language || 'fr',
      customInstruction: '', keepContext: true, loading: false,
    };
    render();
    upsertRerunModal();
  });

  registerAction('close-rerun-modal', () => {
    const { state, render } = getCtx();
    state.rerunModal.open = false;
    render();
    document.getElementById('rerun-modal-overlay')?.remove();
  });

  registerAction('toggle-rerun-variation', ({ element }) => {
    const { state } = getCtx();
    const v   = element.dataset.variation;
    const idx = state.rerunModal.variations.indexOf(v);
    if (idx >= 0) state.rerunModal.variations.splice(idx, 1);
    else state.rerunModal.variations.push(v);
    upsertRerunModal();
  });

  registerAction('apply-rerun', async () => {
    const { state, render, navigate, apiFetch } = getCtx();
    const rm = state.rerunModal;
    if (!rm.sessionId) return;

    rm.targetMode        = document.getElementById('rerun-target-mode')?.value        || '';
    rm.language          = document.getElementById('rerun-language')?.value            || 'fr';
    rm.customInstruction = document.getElementById('rerun-custom-instruction')?.value  || '';
    rm.keepContext       = document.getElementById('rerun-keep-context')?.checked !== false;
    rm.loading           = true;
    render();

    try {
      const result = await apiFetch(`/api/sessions/${rm.sessionId}/rerun`, {
        method: 'POST',
        body: JSON.stringify({
          variations:            rm.variations,
          target_mode:           rm.targetMode || null,
          language:              rm.language,
          custom_instruction:    rm.customInstruction,
          keep_context_document: rm.keepContext,
        }),
      });

      if (result.session) {
        const s = result.session;
        state.sessions.unshift(s);
        state.rerunModal.open = false;
        state.newSession = {
          title:           s.title           || '',
          idea:            s.initial_prompt  || '',
          mode:            s.mode            || 'decision-room',
          selectedAgents:  Array.isArray(s.selected_agents) ? s.selected_agents : [],
          rounds:          s.rounds || 2,
          language:        s.language || 'fr',
          blueTeam:        ['pm', 'architect', 'po', 'ux-expert'],
          redTeam:         ['analyst', 'critic'],
          includeSynthesis: true,
          cfRounds:        s.cf_rounds || 3,
          cfStyle:         s.cf_interaction_style || 'sequential',
          cfReplyPolicy:   s.cf_reply_policy || 'all-agents-reply',
          forceDisagreement: !!s.force_disagreement,
          ctxDocEnabled: false, ctxDocTab: 'manual', ctxDocTitle: '', ctxDocContent: '',
          ctxDocDraftSaved: false, ctxDocDraftSummary: null,
        };
        navigate('new-session');
      }
    } catch (err) {
      state.error = err.message;
      render();
    } finally {
      rm.loading = false;
      document.getElementById('rerun-modal-overlay')?.remove();
    }
  });

  /* ── Decision Threshold ──────────────────────────────────────────────── */

  registerAction('preview-session-threshold', ({ element }) => {
    const sid = element?.dataset?.sessionId;
    if (!sid) return;
    const val    = parseFloat(element.value || 0.55);
    const pct    = Math.round(val * 100);
    const valEl  = document.getElementById(`hist-threshold-val-${sid}`);
    if (valEl) valEl.textContent = pct + '%';
  });

  registerAction('save-decision-threshold', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const sessionId = element?.dataset?.sessionId;
    if (!sessionId) return;
    const sliderEl  = document.getElementById(`hist-threshold-${sessionId}`);
    const statusEl  = document.getElementById(`hist-threshold-status-${sessionId}`);
    const threshold = parseFloat(sliderEl?.value || 0.55);
    if (statusEl) statusEl.textContent = '⏳';
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/decision-threshold`, {
        method: 'PUT',
        body: JSON.stringify({ decision_threshold: threshold }),
      });
      // Sync the updated session into every relevant state slot (same pattern as save-memory)
      if (result.session) {
        const sess = result.session;
        if (state.sessionHistory) state.sessionHistory.session = sess;
        if (state.currentSession?.id === sessionId) state.currentSession = sess;
        const idx = state.sessions.findIndex((s) => s.id === sessionId);
        if (idx >= 0) state.sessions[idx] = sess;
        render();
      }
      if (statusEl) {
        statusEl.textContent = '✅ ' + t('vote.thresholdSaved');
        setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
      }
    } catch (err) {
      if (statusEl) statusEl.textContent = '❌ ' + err.message;
    }
  });

  /* ── Action Plan ─────────────────────────────────────────────────────── */
  registerAction('generate-action-plan', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    state.actionPlanLoading = true;
    state.actionPlanStatus  = null;
    render();
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/action-plan/generate`, {
        method: 'POST', body: JSON.stringify({}),
      });
      if (state.sessionHistory) state.sessionHistory.actionPlan = result.action_plan || null;
      state.actionPlan       = result.action_plan || null;
      state.actionPlanStatus = t('actionPlan.generated');
    } catch (err) {
      state.actionPlanStatus = '❌ ' + err.message;
    } finally {
      state.actionPlanLoading = false;
      render();
    }
  });

  registerAction('save-action-plan-notes', async ({ element }) => {
    const { state, SessionService, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    const el        = document.getElementById('ap-owner-notes');
    const statusEl  = document.getElementById('ap-notes-status');
    if (!el) return;
    if (statusEl) statusEl.textContent = '⏳';
    try {
      const result = await SessionService.updateActionPlan(sessionId, { owner_notes: el.value });
      if (state.sessionHistory) state.sessionHistory.actionPlan = result.action_plan || state.sessionHistory.actionPlan;
      if (statusEl) { statusEl.textContent = '✅ ' + t('memory.saved'); }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
    } catch (err) {
      if (statusEl) statusEl.textContent = '❌ ' + err.message;
    }
  });

  /* ── Session Memory ───────────────────────────────────────────────────── */
  registerAction('save-memory', async ({ element }) => {
    const { state, apiFetch, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    const favEl     = document.getElementById('mem-favorite');
    const refEl     = document.getElementById('mem-reference');
    const decEl     = document.getElementById('mem-decision');
    const learnEl   = document.getElementById('mem-learnings');
    const followEl  = document.getElementById('mem-followup');
    const statusEl  = document.getElementById('mem-save-status');
    if (!favEl) return;
    if (statusEl) statusEl.textContent = '⏳';
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/memory`, {
        method: 'PUT',
        body: JSON.stringify({
          is_favorite:    favEl.checked ? 1 : 0,
          is_reference:   refEl?.checked ? 1 : 0,
          decision_taken: decEl?.value  || '',
          user_learnings: learnEl?.value || '',
          follow_up_notes: followEl?.value || '',
        }),
      });
      if (result.session) {
        const sess = result.session;
        if (state.sessionHistory) state.sessionHistory.session = sess;
        if (state.currentSession?.id === sessionId) state.currentSession = sess;
        const idx = state.sessions.findIndex((s) => s.id === sessionId);
        if (idx >= 0) state.sessions[idx] = sess;
      }
      if (statusEl) { statusEl.textContent = '✅ ' + t('memory.saved'); }
      setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
    } catch (err) {
      if (statusEl) statusEl.textContent = '❌ ' + err.message;
    }
  });

  /* ══════════════════════════════════════════════════════════════════════
     Feature 1 — Persona Scores
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('load-persona-scores', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/persona-scores`);
      state.personaScores = state.personaScores || {};
      state.personaScores[sessionId] = result.scores || [];
      render();
    } catch (err) {
      console.error('persona-scores', err);
    }
  });

  registerAction('open-persona-editor', ({ element }) => {
    const agentId = element.dataset.agentId;
    if (agentId) {
      window.DecisionArena.router.navigate('persona-builder');
    }
  });

  /* ══════════════════════════════════════════════════════════════════════
     Feature 2 — Confidence Timeline
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('load-confidence-timeline', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/confidence-timeline`);
      state.confidenceTimeline = state.confidenceTimeline || {};
      state.confidenceTimeline[sessionId] = result;
      render();
    } catch (err) {
      console.error('confidence-timeline', err);
    }
  });

  /* ══════════════════════════════════════════════════════════════════════
     Feature 5 — Post-mortem
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('open-postmortem-form', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    state.postmortemFormOpen = state.postmortemFormOpen || {};
    state.postmortemFormOpen[sessionId] = true;
    // Load existing postmortem if not yet loaded
    if (!state.postmortem?.[sessionId]) {
      try {
        const result = await apiFetch(`/api/sessions/${sessionId}/postmortem`);
        state.postmortem = state.postmortem || {};
        state.postmortem[sessionId] = result.postmortem || null;
      } catch (_) {}
    }
    render();
  });

  registerAction('close-postmortem-form', ({ element }) => {
    const { state, render } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    state.postmortemFormOpen = state.postmortemFormOpen || {};
    state.postmortemFormOpen[sessionId] = false;
    render();
  });

  registerAction('select-postmortem-outcome', ({ element }) => {
    const { state } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    state.postmortem = state.postmortem || {};
    state.postmortem[sessionId] = state.postmortem[sessionId] || {};
    state.postmortem[sessionId].outcome = element.value;
  });

  registerAction('preview-postmortem-confidence', ({ element }) => {
    const sid = element?.dataset?.sessionId;
    if (!sid) return;
    const val = parseFloat(element.value || 0.5);
    const el  = document.getElementById(`pm-conf-val-${sid}`);
    if (el) el.textContent = Math.round(val * 100) + '%';
  });

  registerAction('submit-postmortem', async ({ element }) => {
    const { state, render, apiFetch, t } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    const statusEl    = document.getElementById(`pm-status-${sessionId}`);
    const outcomeEl   = document.querySelector(`input[name="pm-outcome-${sessionId}"]:checked`);
    const confidenceEl = document.getElementById(`pm-confidence-${sessionId}`);
    const notesEl      = document.getElementById(`pm-notes-${sessionId}`);
    if (!outcomeEl) {
      if (statusEl) statusEl.textContent = '⚠ Sélectionnez un résultat.';
      return;
    }
    if (statusEl) statusEl.textContent = '⏳';
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/postmortem`, {
        method: 'POST',
        body: JSON.stringify({
          outcome:                  outcomeEl.value,
          confidence_in_retrospect: parseFloat(confidenceEl?.value || 0.5),
          notes:                    notesEl?.value || '',
        }),
      });
      state.postmortem = state.postmortem || {};
      state.postmortem[sessionId] = result.postmortem || null;
      state.postmortemFormOpen = state.postmortemFormOpen || {};
      state.postmortemFormOpen[sessionId] = false;
      if (statusEl) statusEl.textContent = '✅ ' + t('postmortem.saved');
      render();
    } catch (err) {
      if (statusEl) statusEl.textContent = '❌ ' + err.message;
    }
  });

  /* ══════════════════════════════════════════════════════════════════════
     Feature 6 — Bias Detection
  ═══════════════════════════════════════════════════════════════════════ */
  registerAction('load-bias-report', async ({ element }) => {
    const { state, render, apiFetch } = getCtx();
    const sessionId = element.dataset.sessionId;
    if (!sessionId) return;
    try {
      const result = await apiFetch(`/api/sessions/${sessionId}/bias-report`);
      state.biasReport = state.biasReport || {};
      state.biasReport[sessionId] = result.bias_report || null;
      render();
    } catch (err) {
      console.error('bias-report', err);
    }
  });
}

export { registerSessionHistoryHandlers };

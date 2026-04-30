/* Comparisons feature — action handlers */
import { registerAction } from '../../core/events.js';

function getCtx() {
  const a = window.DecisionArena;
  return {
    state:             a.store.state,
    render:            () => a.render?.(),
    navigate:          (v) => a.router.navigate(v),
    apiFetch:          a.services.apiFetch,
    ComparisonService: a.services.ComparisonService,
    LoaderService:     a.services.LoaderService,
    t:                 (key) => window.i18n?.t(key) ?? key,
  };
}

function registerComparisonsHandlers() {
  registerAction('goto-compare-sessions', async () => {
    const { navigate, LoaderService } = getCtx();
    await LoaderService.loadComparisons();
    navigate('session-comparisons');
  });

  registerAction('create-comparison', async () => {
    const { state, render, navigate, ComparisonService } = getCtx();
    if ((state.compareSelectedIds || []).length < 2) return;
    state.comparisonLoading = true;
    render();
    try {
      const result = await ComparisonService.create({ session_ids: state.compareSelectedIds });
      if (result.comparison) {
        state.comparisons.unshift(result.comparison);
        state.currentComparison  = result.comparison;
        state.compareSelectedIds = [];
        navigate('session-comparison');
      }
    } catch (err) {
      state.error = err.message;
      render();
    } finally {
      state.comparisonLoading = false;
    }
  });

  registerAction('open-comparison', async ({ element }) => {
    const { state, render, navigate, apiFetch } = getCtx();
    const compId = element.dataset.compId;
    try {
      const data = await apiFetch(`/api/session-comparisons/${compId}`);
      state.currentComparison = data.comparison || null;
      navigate('session-comparison');
    } catch (err) {
      state.error = err.message;
      render();
    }
  });

  registerAction('delete-comparison', async ({ element }) => {
    const { state, render, navigate, ComparisonService, t } = getCtx();
    const compId = element.dataset.compId;
    if (!confirm(t('compare.confirmDelete'))) return;
    try {
      await ComparisonService.remove(compId);
      state.comparisons = state.comparisons.filter((c) => c.id !== compId);
      if (state.currentComparison?.id === compId) {
        state.currentComparison = null;
        navigate('session-comparisons');
      }
      render();
    } catch (err) {
      state.error = err.message;
      render();
    }
  });

  registerAction('export-comparison', () => {
    const { state } = getCtx();
    const comp = state.currentComparison;
    if (!comp) return;
    const blob = new Blob([comp.content_markdown || ''], { type: 'text/markdown' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'comparison-' + comp.id + '.md';
    a.click();
    URL.revokeObjectURL(url);
  });
}

export { registerComparisonsHandlers };

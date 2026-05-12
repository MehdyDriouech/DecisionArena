function parseMetaJson(meta) {
  if (!meta) return null;
  if (typeof meta === 'object') return meta;
  if (typeof meta !== 'string') return null;
  try {
    const parsed = JSON.parse(meta);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch (_) {
    return null;
  }
}

function compactLlmLabel(provider, model) {
  const parts = [provider, model].map((v) => String(v || '').trim()).filter(Boolean);
  return parts.join(' / ');
}

function sourceLabel(source, t) {
  const map = {
    session_override: t('message.llm.source.sessionOverride'),
    explicit_call: t('message.llm.source.explicitCall'),
    persona_default: t('message.llm.source.personaDefault'),
    global_routing: t('message.llm.source.globalRouting'),
    fallback_from_override: t('message.llm.source.fallbackFromOverride'),
  };
  return map[source] || source || t('message.llm.source.unknown');
}

export function normalizeLlmRoutingMeta(msg) {
  const meta = parseMetaJson(msg?.meta_json ?? msg?.meta);
  const routing = meta && typeof meta === 'object' && meta.llm_routing && typeof meta.llm_routing === 'object'
    ? meta.llm_routing
    : {};

  const requestedProvider = routing.requested_provider_id ?? msg?.requested_provider_id ?? null;
  const requestedModel = routing.requested_model ?? msg?.requested_model ?? null;
  const usedProvider = routing.resolved_provider_label ?? routing.resolved_provider_id ?? msg?.provider_name ?? msg?.provider_id ?? null;
  const usedModel = routing.resolved_model ?? msg?.model ?? null;
  const routingSource = routing.routing_source ?? meta?.routing_source ?? null;
  const fallbackUsed = routing.provider_fallback_used === true
    || routing.provider_fallback_used === 1
    || msg?.provider_fallback_used === true
    || msg?.provider_fallback_used === 1
    || msg?.provider_fallback_used === '1';
  const fallbackReason = routing.fallback_reason ?? msg?.provider_fallback_reason ?? null;

  return {
    requestedProvider,
    requestedModel,
    usedProvider,
    usedModel,
    routingSource,
    fallbackUsed,
    fallbackReason,
    requestedLabel: compactLlmLabel(requestedProvider, requestedModel),
    usedLabel: compactLlmLabel(usedProvider, usedModel),
  };
}

export function renderLlmRoutingCompact(msg, { escHtml, t, expert = false } = {}) {
  const info = normalizeLlmRoutingMeta(msg);
  if (!info.usedLabel && !info.requestedLabel && !info.routingSource) return '';

  if (info.fallbackUsed) {
    const reason = info.fallbackReason ? `<div>${t('message.llm.reason')}: ${escHtml(String(info.fallbackReason))}</div>` : '';
    return `
      <div class="message-llm-routing">
        <div class="message-llm-warning">⚠ ${t('message.llm.overrideUnavailable')}</div>
        <div>${t('message.llm.requested')}: ${escHtml(info.requestedLabel || '—')}</div>
        <div>${t('message.llm.used')}: ${escHtml(info.usedLabel || '—')}</div>
        <div>${t('message.llm.source')}: ${escHtml(sourceLabel(info.routingSource, t))}</div>
        ${reason}
      </div>
    `;
  }

  const summarySource = sourceLabel(info.routingSource, t);
  const compact = `${summarySource} · ${info.usedLabel || t('message.llm.routingGlobal')}`;
  if (!expert) {
    return `<span class="message-llm-meta provider-badge">${escHtml(compact)}</span>`;
  }

  return `
    <details class="message-llm-routing-detail">
      <summary class="message-llm-meta provider-badge">${escHtml(compact)}</summary>
      <div class="message-llm-routing">
        <div>${t('message.llm.requested')}: ${escHtml(info.requestedLabel || '—')}</div>
        <div>${t('message.llm.used')}: ${escHtml(info.usedLabel || '—')}</div>
        <div>${t('message.llm.source')}: ${escHtml(summarySource)}</div>
        <div>${t('message.llm.fallback')}: ${t('message.llm.fallbackNo')}</div>
      </div>
    </details>
  `;
}

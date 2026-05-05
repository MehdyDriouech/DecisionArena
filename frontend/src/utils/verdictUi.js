/**
 * Nettoie `recommended_action` du verdict quand le LLM y colle du JSON sous ```json`.
 */

/**
 * @param {unknown} obj
 */
function normalizeTradeoffsBrief(obj) {
  if (!obj || typeof obj !== 'object') return obj;
  const o = /** @type {Record<string, unknown>} */ (obj);
  if (o.tradeoffs && typeof o.tradeoffs === 'object' && !Array.isArray(o.tradeoffs)) {
    const tr = /** @type {Record<string, unknown>} */ ({ ...o.tradeoffs });
    if (tr.Summary != null && tr.summary == null) tr.summary = tr.Summary;
    if (tr.Criteria != null && tr.criteria == null) tr.criteria = tr.Criteria;
    return { tradeoffs: tr };
  }
  return o;
}

/**
 * @param {string} text
 * @returns {{ prose: string, jsonText: string | null }}
 */
function stripFenceJsonBlocks(text) {
  let jsonText = null;
  const prose = text.replace(/```(?:json)?\s*\n?([\s\S]*?)```/gi, (_, inner) => {
    const t = String(inner).trim();
    if (!jsonText && t.startsWith('{')) {
      try {
        JSON.parse(t);
        jsonText = t;
      } catch {
        /* pas du JSON exploitable */
      }
    }
    return '\n';
  });
  return { prose: prose.replace(/\n{3,}/g, '\n\n').trim(), jsonText };
}

/**
 * @param {string | null | undefined} raw
 * @returns {{ prose: string, tradeoffBrief: object | null }}
 */
function parseRecommendedActionForDisplay(raw) {
  if (raw == null || raw === '') return { prose: '', tradeoffBrief: null };
  const s = String(raw).trim();
  let tradeoffBrief = null;

  const { prose: withoutFences, jsonText } = stripFenceJsonBlocks(s);
  let prose = withoutFences;

  if (jsonText) {
    try {
      tradeoffBrief = normalizeTradeoffsBrief(JSON.parse(jsonText));
    } catch {
      tradeoffBrief = null;
    }
  }

  if (!tradeoffBrief && prose.startsWith('{')) {
    try {
      tradeoffBrief = normalizeTradeoffsBrief(JSON.parse(prose));
      prose = '';
    } catch {
      /* garder comme prose */
    }
  }

  return { prose: prose.trim(), tradeoffBrief };
}

export { parseRecommendedActionForDisplay, normalizeTradeoffsBrief };

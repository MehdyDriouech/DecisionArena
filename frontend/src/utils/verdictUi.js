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
 * Extrait le premier objet JSON équilibré à partir du premier `{` (guillemets simples dans chaînes gérées partiellement).
 * @param {string} s
 * @returns {string | null}
 */
function extractBalancedJsonObject(s) {
  const start = s.indexOf('{');
  if (start === -1) return null;
  let depth = 0;
  let inString = false;
  let escape = false;
  for (let i = start; i < s.length; i++) {
    const c = s[i];
    if (escape) {
      escape = false;
      continue;
    }
    if (inString) {
      if (c === '\\') {
        escape = true;
        continue;
      }
      if (c === '"') inString = false;
      continue;
    }
    if (c === '"') {
      inString = true;
      continue;
    }
    if (c === '{') depth += 1;
    else if (c === '}') {
      depth -= 1;
      if (depth === 0) return s.slice(start, i + 1);
    }
  }
  return null;
}

/**
 * Retire un enveloppe `{ "tradeoffs": … }` non fenceée ou avec fence mal fermée (dernière occurrence).
 * @param {string} text
 * @returns {{ prose: string, jsonText: string | null }}
 */
function stripUnfencedTradeoffsEnvelope(text) {
  const re = /\{\s*"tradeoffs"\s*:/g;
  let last = -1;
  let m;
  while ((m = re.exec(text)) !== null) {
    last = m.index;
  }
  if (last < 0) return { prose: text, jsonText: null };
  const tail = text.slice(last);
  const balanced = extractBalancedJsonObject(tail);
  if (!balanced) return { prose: text, jsonText: null };
  let parsed;
  try {
    parsed = JSON.parse(balanced);
  } catch {
    return { prose: text, jsonText: null };
  }
  if (!parsed || typeof parsed !== 'object' || parsed.tradeoffs == null || typeof parsed.tradeoffs !== 'object') {
    return { prose: text, jsonText: null };
  }
  let before = text
    .slice(0, last)
    .replace(/[`]{1,3}\s*json\s*$/i, '')
    .replace(/\bjson\s*$/i, '')
    .replace(/```(?:json)?\s*$/i, '')
    .trimEnd();
  const after = text.slice(last + balanced.length);
  const prose = `${before}${after}`.replace(/\n{3,}/g, '\n\n').trim();
  return { prose, jsonText: balanced };
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

  const { prose: prose2, jsonText: jsonBare } = stripUnfencedTradeoffsEnvelope(prose);
  prose = prose2;
  if (jsonBare && !tradeoffBrief) {
    try {
      tradeoffBrief = normalizeTradeoffsBrief(JSON.parse(jsonBare));
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

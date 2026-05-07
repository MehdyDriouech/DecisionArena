/**
 * Deterministic markdown → HTML projection for perspective snapshots.
 *
 * Phase 1 — UX polish layer only. The backend remains the single source of
 * truth: this module never invents content, only renders the already
 * deterministic markdown produced by MemorySnapshotGenerator into a more
 * operational, decision-oriented layout.
 */

const SECTION_ICONS = {
  'Current State': '🧭',
  'Active Risks': '⚠️',
  'Open Risks': '⚠️',
  'Failed Assumptions': '✕',
  'Validated Hypotheses': '✓',
  'Decision Chains': '🔗',
  'Decision Chain': '🔗',
  'Recommended Next Actions': '→',
  'Linked Sessions': '◷',
  'Unassigned Decision Memories': '◫',
  'Perspective Relevance': '◑',
  'Safety / Metadata': '🛡',
};

const SECTION_KEY_BY_TITLE = {
  'Current State': 'current-state',
  'Active Risks': 'active-risks',
  'Open Risks': 'open-risks',
  'Failed Assumptions': 'failed-assumptions',
  'Validated Hypotheses': 'validated-hypotheses',
  'Decision Chains': 'decision-chains',
  'Decision Chain': 'decision-chain',
  'Recommended Next Actions': 'next-actions',
  'Linked Sessions': 'linked-sessions',
  'Unassigned Decision Memories': 'unassigned',
  'Perspective Relevance': 'relevance',
  'Safety / Metadata': 'safety',
};

const RELEVANCE_BUCKETS = new Set(['none', 'low', 'medium', 'high']);

const PERSPECTIVE_LABEL_KEYS = {
  default: 'snapshots.perspective.default',
  ceo: 'snapshots.perspective.ceo',
  cto: 'snapshots.perspective.cto',
  cfo: 'snapshots.perspective.cfo',
  product: 'snapshots.perspective.product',
  growth: 'snapshots.perspective.growth',
  legal: 'snapshots.perspective.legal',
};

function defaultEsc(s) {
  const arena = (typeof window !== 'undefined') ? window.DecisionArena : null;
  const fn = arena?.utils?.escHtml;
  if (fn) return fn(s);
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function defaultT(key) {
  const i18n = (typeof window !== 'undefined') ? window.i18n : null;
  const fn = i18n?.t;
  return typeof fn === 'function' ? fn(key) : key;
}

/**
 * Parse a deterministic perspective snapshot markdown string into a tree of
 * sections, preserving the order of sections and bullets.
 *
 * @param {string} markdown
 * @returns {{
 *   title: string,
 *   disclaimers: string[],
 *   sections: Array<{ title: string, key: string, lines: string[] }>,
 * }}
 */
function parseSnapshot(markdown) {
  const text = String(markdown || '');
  const lines = text.split(/\r?\n/);
  const out = { title: '', disclaimers: [], sections: [] };
  let current = null;
  for (let raw of lines) {
    const line = raw.replace(/\s+$/g, '');
    if (line === '') {
      if (current) current.lines.push('');
      continue;
    }
    if (!current && line.startsWith('# ')) {
      out.title = line.slice(2).trim();
      continue;
    }
    if (!current && line.startsWith('> ')) {
      out.disclaimers.push(line.slice(2).trim());
      continue;
    }
    if (line.startsWith('## ')) {
      const title = line.slice(3).trim();
      const key = SECTION_KEY_BY_TITLE[title] || title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      current = { title, key, lines: [] };
      out.sections.push(current);
      continue;
    }
    if (current) current.lines.push(line);
  }
  for (const s of out.sections) {
    while (s.lines.length && s.lines[s.lines.length - 1] === '') s.lines.pop();
  }
  return out;
}

function relevanceLine(line) {
  const m = line.match(/^-\s+(.+?)\s+relevance:\s+(none|low|medium|high)\b(.*)$/i);
  if (!m) return null;
  const label = m[1].trim();
  const bucket = m[2].toLowerCase();
  const tail = (m[3] || '').trim();
  let detail = '';
  let isCurrent = false;
  if (tail) {
    if (/\(current\)/i.test(tail)) isCurrent = true;
    const dm = tail.match(/\((\d+)\/(\d+)\s+field\s+matches?\)/i);
    if (dm) detail = `${dm[1]}/${dm[2]}`;
  }
  return { label, bucket, detail, isCurrent };
}

function renderRelevanceBlock(section, opts) {
  const esc = opts.escHtml;
  const t = opts.t;
  const isExpert = !!opts.isExpert;
  const blocks = [];
  const items = [];
  let intro = '';
  for (const ln of section.lines) {
    if (ln.startsWith('> ')) {
      intro = ln.slice(2).trim();
      continue;
    }
    const parsed = relevanceLine(ln);
    if (parsed) items.push(parsed);
  }
  blocks.push(`<p class="ps-relevance-hint">${esc(t('snapshots.relevance.help'))}</p>`);
  if (isExpert && intro) {
    blocks.push(`<p class="ps-relevance-intro" data-ui-min="advanced">${esc(intro)}</p>`);
  }
  if (items.length) {
    const pills = items.map((it) => {
      const detail = isExpert && it.detail
        ? ` <span class="ps-relevance-detail" data-ui-min="advanced">${esc(it.detail)}</span>`
        : '';
      const cur = it.isCurrent ? ` <span class="ps-relevance-current">${esc(t('snapshots.relevance.current'))}</span>` : '';
      const aria = it.isCurrent ? ` aria-current="true"` : '';
      return `<li class="ps-relevance-item ps-relevance-item--${esc(it.bucket)}"${aria}>
        <span class="ps-relevance-label">${esc(it.label)}</span>
        <span class="ps-relevance-bucket">${esc(t('snapshots.relevance.bucket.' + it.bucket))}</span>${detail}${cur}
      </li>`;
    }).join('');
    blocks.push(`<ul class="ps-relevance-list">${pills}</ul>`);
  }
  return blocks.join('\n');
}

function renderBulletList(lines, esc, opts = {}) {
  const items = [];
  for (const ln of lines) {
    if (!ln.startsWith('- ')) continue;
    let body = ln.slice(2).trim();
    if (body === '—' || body === '-') {
      items.push(`<li class="ps-item ps-item--empty">${esc('—')}</li>`);
      continue;
    }
    if (body.startsWith('★ ')) {
      const text = body.slice(2).trim();
      items.push(`<li class="ps-item ps-item--star"><span class="ps-star" aria-hidden="true">★</span> <span class="ps-item-text">${esc(text)}</span><span class="visually-hidden"> (${esc(opts.starSrLabel || 'high relevance')})</span></li>`);
      continue;
    }
    items.push(`<li class="ps-item">${esc(body)}</li>`);
  }
  if (!items.length) return `<p class="ps-empty">${esc('—')}</p>`;
  return `<ul class="ps-bullet-list">${items.join('')}</ul>`;
}

function renderKeyValueList(lines, esc) {
  const rows = [];
  for (const ln of lines) {
    if (!ln.startsWith('- ')) continue;
    const body = ln.slice(2);
    const idx = body.indexOf(':');
    if (idx <= 0) {
      rows.push(`<div class="ps-kv-row"><span class="ps-kv-value">${esc(body.trim())}</span></div>`);
      continue;
    }
    const k = body.slice(0, idx).trim();
    const v = body.slice(idx + 1).trim();
    rows.push(`<div class="ps-kv-row"><span class="ps-kv-key">${esc(k)}</span><span class="ps-kv-value">${esc(v || '—')}</span></div>`);
  }
  if (!rows.length) return '';
  return `<div class="ps-kv">${rows.join('')}</div>`;
}

function renderChainsBlock(section, esc) {
  const out = [];
  let buf = [];
  let chainTitle = null;
  let inRecent = false;
  const flushChain = () => {
    if (!chainTitle && !buf.length) return;
    const meta = [];
    const recent = [];
    let curr = inRecent ? recent : meta;
    for (const ln of buf) {
      if (/^####\s+Recent Memories/i.test(ln)) { curr = recent; continue; }
      if (ln === '') continue;
      curr.push(ln);
    }
    out.push(
      `<article class="ps-chain">
        ${chainTitle ? `<h4 class="ps-chain-title">${esc(chainTitle)}</h4>` : ''}
        ${meta.length ? renderKeyValueList(meta, esc) : ''}
        ${recent.length ? `<div class="ps-chain-recent">
          <div class="ps-chain-recent-label">${esc(defaultT('snapshots.chain.recent'))}</div>
          ${renderBulletList(recent, esc)}
        </div>` : ''}
      </article>`
    );
    buf = [];
    chainTitle = null;
    inRecent = false;
  };
  for (const ln of section.lines) {
    if (ln.startsWith('### ')) {
      flushChain();
      chainTitle = ln.slice(4).trim();
      continue;
    }
    if (/^_No decision chains/i.test(ln)) {
      out.push(`<p class="ps-empty">${esc(ln.replace(/^_|_$/g, ''))}</p>`);
      continue;
    }
    if (ln.startsWith('#### Recent Memories')) {
      inRecent = true;
      continue;
    }
    buf.push(ln);
  }
  flushChain();
  return out.join('\n') || `<p class="ps-empty">${esc('—')}</p>`;
}

/**
 * Section renderer dispatcher. Sections we don't recognize fall back to the
 * generic bullet list renderer.
 */
function renderSection(section, opts) {
  const esc = opts.escHtml;
  const t = opts.t;
  const key = section.key;
  const isExpert = !!opts.isExpert;
  const dataUi = (key === 'safety') ? ' data-ui-min="advanced"' : '';
  const icon = SECTION_ICONS[section.title] || '';
  const titleHtml = `<h3 class="ps-section-title">${icon ? `<span class="ps-section-icon" aria-hidden="true">${esc(icon)}</span>` : ''}<span>${esc(section.title)}</span></h3>`;

  let bodyHtml = '';
  if (key === 'current-state') {
    bodyHtml = renderKeyValueList(section.lines, esc);
  } else if (key === 'safety') {
    bodyHtml = renderKeyValueList(section.lines, esc);
  } else if (key === 'decision-chains' || key === 'decision-chain') {
    bodyHtml = renderChainsBlock(section, esc);
  } else if (key === 'relevance') {
    bodyHtml = renderRelevanceBlock(section, { escHtml: esc, t, isExpert });
  } else {
    bodyHtml = renderBulletList(section.lines, esc, { starSrLabel: t('snapshots.starSrLabel') });
  }

  return `<section class="ps-section ps-section--${esc(key)}"${dataUi}>
    ${titleHtml}
    <div class="ps-section-body">${bodyHtml}</div>
  </section>`;
}

/**
 * @param {string} markdown
 * @param {{
 *   escHtml?: (s: string) => string,
 *   t?: (k: string) => string,
 *   isExpert?: boolean,
 *   perspective?: string,
 *   raw?: string,
 * }} options
 * @returns {string}
 */
function renderPerspectiveSnapshot(markdown, options = {}) {
  const esc = options.escHtml || defaultEsc;
  const t = options.t || defaultT;
  const isExpert = !!options.isExpert;
  const parsed = parseSnapshot(markdown);
  const titleHtml = parsed.title ? `<h2 class="ps-doc-title">${esc(parsed.title)}</h2>` : '';
  const perspective = String(options.perspective || 'default').toLowerCase();
  const labelKey = PERSPECTIVE_LABEL_KEYS[perspective] || PERSPECTIVE_LABEL_KEYS.default;
  const perspectiveLabel = t(labelKey);
  const tagHtml = (perspective && perspective !== 'default')
    ? `<span class="ps-perspective-tag" aria-label="${esc(t('snapshots.perspective'))}">
         <span class="ps-perspective-tag-key">${esc(t('snapshots.perspective'))}</span>
         <span class="ps-perspective-tag-value">${esc(perspectiveLabel)}</span>
       </span>`
    : `<span class="ps-perspective-tag ps-perspective-tag--default" aria-label="${esc(t('snapshots.perspective'))}">
         <span class="ps-perspective-tag-key">${esc(t('snapshots.perspective'))}</span>
         <span class="ps-perspective-tag-value">${esc(perspectiveLabel)}</span>
       </span>`;

  const disclaimerHtml = parsed.disclaimers.length
    ? `<aside class="ps-disclaimer" role="note">
        ${parsed.disclaimers.map((d) => `<p>${esc(d)}</p>`).join('')}
      </aside>`
    : '';

  const sectionsHtml = parsed.sections.map((s) => renderSection(s, { escHtml: esc, t, isExpert })).join('\n');

  const rawAttachment = options.raw
    ? `<details class="ps-raw" data-ui-min="advanced">
        <summary>${esc(t('snapshots.raw.summary'))}</summary>
        <pre class="ps-raw-pre">${esc(options.raw)}</pre>
      </details>`
    : '';

  return `<article class="ps-doc" data-perspective="${esc(perspective)}">
    <header class="ps-doc-header">
      ${titleHtml}
      ${tagHtml}
    </header>
    ${disclaimerHtml}
    ${sectionsHtml}
    ${rawAttachment}
  </article>`;
}

/**
 * Compact accessible segmented control for perspective selection.
 * Buttons carry `data-action`, `data-perspective`, and `aria-pressed`.
 *
 * @param {{
 *   action: string,
 *   selected?: string,
 *   ariaLabel?: string,
 *   escHtml?: (s: string) => string,
 *   t?: (k: string) => string,
 * }} opts
 */
function renderPerspectiveSegmentedControl(opts = {}) {
  const esc = opts.escHtml || defaultEsc;
  const t = opts.t || defaultT;
  const action = String(opts.action || '');
  const selected = String(opts.selected || 'default').toLowerCase();
  const items = ['default', 'ceo', 'cto', 'cfo', 'product', 'growth', 'legal'];
  const buttons = items.map((p) => {
    const pressed = (p === selected) ? 'true' : 'false';
    const labelKey = PERSPECTIVE_LABEL_KEYS[p] || PERSPECTIVE_LABEL_KEYS.default;
    const label = t(labelKey);
    const cls = `ps-segment ${p === selected ? 'ps-segment--active' : ''}`.trim();
    return `<button type="button"
      class="${cls}"
      data-action="${esc(action)}"
      data-perspective="${esc(p)}"
      aria-pressed="${pressed}"
      title="${esc(label)}"
    >${esc(label)}</button>`;
  }).join('');
  return `<div class="ps-segmented" role="group" aria-label="${esc(opts.ariaLabel || t('snapshots.perspective'))}">${buttons}</div>`;
}

export {
  parseSnapshot,
  renderPerspectiveSnapshot,
  renderPerspectiveSegmentedControl,
};

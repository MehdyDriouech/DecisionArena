/**
 * Six Thinking Hats — framework helpers (sessions + Decision Room grouping).
 */

export const SIX_THINKING_HATS_FRAMEWORK = 'six-thinking-hats';

export const SIX_HATS_SEQUENCE = ['hat-white', 'hat-red', 'hat-black', 'hat-yellow', 'hat-green', 'hat-blue'];

export function isSixThinkingHatsSession(session) {
  const fw = session?.facilitation_framework;
  return fw === SIX_THINKING_HATS_FRAMEWORK;
}

function hatGroupLabelKey(agentId) {
  const keys = {
    'hat-white': 'sixHats.group.facts',
    'hat-red': 'sixHats.group.emotions',
    'hat-black': 'sixHats.group.risks',
    'hat-yellow': 'sixHats.group.opportunities',
    'hat-green': 'sixHats.group.creativity',
    'hat-blue': 'sixHats.group.synthesis',
  };
  return keys[agentId] || null;
}

export function renderSixThinkingMethodBanner(session, t, escHtml) {
  if (!isSixThinkingHatsSession(session)) return '';
  const label = escHtml(t('sixHats.methodBadge'));
  return `
    <div class="six-hats-method-banner" style="margin-bottom:14px;padding:10px 14px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.28);border-radius:8px;font-size:13px;color:var(--text-secondary);">
      <strong style="color:var(--text-primary);">🎩</strong>
      ${label}
      <span style="display:block;font-size:11px;color:var(--text-muted);margin-top:6px;line-height:1.45;">
        ${escHtml(t('sixHats.methodHint'))}
      </span>
    </div>`;
}

/**
 * Builds HTML: one block per hat, all contributions across rounds (with round labels).
 */
export function renderSixThinkingHatsGroupedDebate(rounds, {
  escHtml, renderMarkdown, agentIcon, agentName, agentTitleText, t,
}) {
  const roundNums = Object.keys(rounds || {}).map(Number).sort((a, b) => a - b);
  const byHat = {};
  SIX_HATS_SEQUENCE.forEach((id) => { byHat[id] = []; });

  roundNums.forEach((rNum) => {
    const msgs = rounds[rNum] || [];
    msgs.forEach((msg, idx) => {
      const aid = msg.agent_id;
      if (byHat[aid]) {
        byHat[aid].push({ round: rNum, idx, msg });
      }
    });
  });

  const blocks = SIX_HATS_SEQUENCE.map((hatId) => {
    const items = byHat[hatId];
    if (!items.length) return '';
    const lk = hatGroupLabelKey(hatId);
    const sectionTitleRaw = lk ? t(lk) : (agentName(hatId) || hatId);
    const sectionTitle = escHtml(sectionTitleRaw);
    const icon = agentIcon(hatId) || '🎩';
    const subt = agentTitleText ? agentTitleText(hatId) : '';
    const body = items.map(({ round, msg }) => {
      const rndLabel = `${t('dr.round')} ${round}`;
      const md = typeof renderMarkdown === 'function' ? renderMarkdown(msg.content || '') : escHtml(msg.content || '');
      return `
        <div class="six-hats-msg" style="margin-bottom:14px;">
          <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">${escHtml(rndLabel)}</div>
          <div class="agent-content md-content">${md}</div>
        </div>`;
    }).join('');

    return `
      <div class="six-hats-group-card" style="margin-bottom:16px;padding:14px 16px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
          <span style="font-size:22px;">${icon}</span>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:14px;color:var(--text-primary);">${sectionTitle}</div>
            ${subt ? `<div style="font-size:12px;color:var(--text-muted);">${escHtml(subt)}</div>` : ''}
          </div>
        </div>
        ${body}
      </div>`;
  }).join('');

  if (!blocks.trim()) return '';

  return `
    <div class="six-hats-grouped-debate" style="margin-bottom:16px;">
      <div style="font-weight:600;font-size:13px;color:var(--text-secondary);margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;">
        ${escHtml(t('sixHats.groupedTitle'))}
      </div>
      ${blocks}
    </div>`;
}

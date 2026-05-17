import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const projectRoot = path.resolve(root, '..');

let PASS = 0;
let WARN = 0;
let FAIL = 0;

function read(rel) {
  return fs.readFileSync(path.join(projectRoot, rel), 'utf8');
}

function listFiles(dir, ext = '.js') {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...listFiles(p, ext));
    if (entry.isFile() && entry.name.endsWith(ext)) out.push(p);
  }
  return out;
}

function rel(file) {
  return path.relative(projectRoot, file).replace(/\\/g, '/');
}

function pass(label) {
  PASS += 1;
  console.log(`[PASS] ${label}`);
}

function warn(label, detail = '') {
  WARN += 1;
  console.log(`[WARN] ${label}${detail ? ` - ${detail}` : ''}`);
}

function fail(label, detail = '') {
  FAIL += 1;
  console.log(`[FAIL] ${label}${detail ? ` - ${detail}` : ''}`);
}

function check(ok, label, detail = '') {
  ok ? pass(label) : fail(label, detail);
}

function contains(fileRel, needle) {
  return read(fileRel).includes(needle);
}

function fileHasAll(fileRel, needles) {
  const text = read(fileRel);
  return needles.every((needle) => text.includes(needle));
}

function sourceCorpus(globRoot = 'frontend/src') {
  return listFiles(path.join(projectRoot, globRoot))
    .map((file) => ({ file, text: fs.readFileSync(file, 'utf8') }));
}

console.log('Decision Arena frontend contracts\n');

// 1. UI mode contract: no tri-mode "advanced" source of truth.
const srcFiles = sourceCorpus();
const forbiddenAdvancedPatterns = [
  /uiMode\s*[:=]\s*['"]advanced['"]/,
  /data-ui-mode\s*=\s*['"]advanced['"]/,
  /data-nav\s*=\s*['"]advanced['"]/,
  /setUiMode\(\s*['"]advanced['"]\s*\)/,
  /state\.uiMode\s*={2,3}\s*['"]advanced['"]/,
];
const forbiddenAdvancedHits = [];
const legacyAdvancedHits = [];
for (const { file, text } of srcFiles) {
  for (const pattern of forbiddenAdvancedPatterns) {
    if (pattern.test(text)) forbiddenAdvancedHits.push(`${rel(file)} :: ${pattern}`);
  }
  if (/\badvanced\b/i.test(text)) {
    const allowedLegacy =
      rel(file).endsWith('core/store.js') ||
      rel(file).endsWith('core/globalHandlers.js') ||
      rel(file).endsWith('utils/perspectiveSnapshotRenderer.js');
    if (allowedLegacy) legacyAdvancedHits.push(rel(file));
  }
}
check(forbiddenAdvancedHits.length === 0, 'no advanced UI mode reintroduced', forbiddenAdvancedHits.slice(0, 5).join('; '));
if (legacyAdvancedHits.length > 0) {
  warn('legacy advanced compatibility references remain', [...new Set(legacyAdvancedHits)].join(', '));
}

// 2. Critical routes and view registry.
const router = read('frontend/src/core/router.js');
const main = read('frontend/src/main.js');
const viewFiles = [
  'frontend/src/features/dashboard/index.js',
  'frontend/src/features/about/index.js',
  'frontend/src/features/analyses/index.js',
  'frontend/src/features/newSession/index.js',
  'frontend/src/features/strategicContexts/index.js',
  'frontend/src/features/admin/index.js',
  'frontend/src/features/openSpace/index.js',
].map(read).join('\n');

const routeNeedles = {
  dashboard: '#/dashboard',
  about: "about: '#/about'",
  analyses: '#/analyses',
  sessions: 'sessions: \'#/analyses\'',
  'new-session': '#/new-session',
  'strategic-contexts': '#/strategic-contexts',
  admin: "admin: 'administration'",
  'openspace/orchestrator': '#/openspace/orchestrator',
  'openspace/kanban': '#/openspace/kanban',
  'openspace/agent-chat': '#/openspace/agent-chat',
};
for (const [name, needle] of Object.entries(routeNeedles)) {
  check(router.includes(needle), `route declared ${name}`, needle);
}
for (const view of ['dashboard', 'about', 'analyses', 'new-session', 'strategic-contexts', 'administration', 'providers', 'templates', 'persona-builder', 'openspace-orchestrator', 'openspace-kanban', 'openspace-agent-chat']) {
  check(viewFiles.includes(`views['${view}']`) || viewFiles.includes(`views.${view}`), `view registered ${view}`);
}
check(main.includes('registerOpenSpaceFeature()') && main.includes('registerExperimentalGateFeature()'), 'OpenSpace and experimental gate registered in bootstrap');
check(main.includes('registerAboutFeature()'), 'About view registered in bootstrap');
check(fileHasAll('frontend/src/core/renderer.js', ['https://dawp-engineering.com/', 'rel="noopener noreferrer"', 'target="_blank"', 'data-nav="about"', 'function renderAppFooter']), 'app footer external link + about nav + renderer present');

// 2b. About / footer i18n keys.
check(fileHasAll('frontend/i18n.js', ["'footer.poweredByPrefix': 'Propulsé par '", "'footer.about': 'À propos'", "'about.title': 'À propos de Decision Arena'", "'footer.poweredByPrefix': 'Powered by '", "'footer.about': 'About'", "'about.title': 'About Decision Arena'"]), 'footer and about i18n keys present in fr and en');
check(fileHasAll('frontend/i18n.js', ["'about.community.body1': 'Decision Arena est pensé pour exister sous la forme d’une Community Edition ouverte, accessible et expérimentale.'", "'about.community.body1': 'Decision Arena is meant to exist as an open, accessible, experimental Community Edition.'"]), 'about community edition copy present (fr + en, no OSI open-source claim)');
check(contains('frontend/i18n.js', "'about.contact.email': 'driouechmehdy.pro@gmail.com'"), 'about contact email key present');

// 2c. About view markup contracts.
const aboutView = read('frontend/src/features/about/index.js');
check(aboutView.includes('data-ui="expert-only"') && aboutView.includes('mailto:') && aboutView.includes('driouechmehdy.pro@gmail.com'), 'about view includes expert-only block, mailto, and contact email');
check(aboutView.includes('CONTACT_EMAIL') && aboutView.includes('tx(t,'), 'about view keeps fixed mailto target and escapes i18n strings');

// 3. Deep-link session routes.
check(fileHasAll('frontend/src/core/router.js', ['head === \'sessions\'', 'head === \'analyses\'', 'head === \'session-history\'', 'openSessionFromHash']), 'deep-link session aliases declared');
check(router.includes('session-not-found') && router.includes('Identifiant de session invalide'), 'invalid session deep-link has controlled error');
check(router.includes('Object.prototype.hasOwnProperty.call(VIEW_TO_HASH, mapped)') && router.includes("navigate('dashboard', { __skipHashSync: true })") && router.includes('async function applyHashRoute()'), 'unknown hash route falls back to dashboard explicitly');

// 4. OpenSpace gate.
check(fileHasAll('frontend/src/core/router.js', ['OPENSPACE_GATED_VIEWS', 'experimental-gate', 'canShowExperimentalFeatures']), 'direct OpenSpace routes gated by experimental flag');
check(fileHasAll('frontend/src/core/renderer.js', ['showOpenSpaceNav = canShowExperimentalFeatures', 'openspace-orchestrator', 'openspace-kanban', 'openspace-agent-chat']), 'sidebar hides OpenSpace unless gate allows it');
check(fileHasAll('frontend/src/core/shell.js', ['showOpenSpaceNav = canShowExperimentalFeatures', 'openspace-orchestrator', 'openspace-kanban', 'openspace-agent-chat']), 'shell sidebar hides OpenSpace unless gate allows it');
check(fileHasAll('frontend/src/features/experimentalGate/index.js', ['experimental.featureDisabled', 'data-nav="administration"', 'data-action="set-ui-mode"', 'data-nav="dashboard"']), 'experimental guard view offers controlled recovery');

// 5. Feature flags.
check(fileHasAll('frontend/src/core/store.js', ['EXPERIMENTAL_FEATURES_STORAGE_KEY', 'readInitialExperimentalFeatures', 'return false', 'experimentalFeaturesEnabled: readInitialExperimentalFeatures()']), 'experimentalFeaturesEnabled exists and defaults false');
check(fileHasAll('frontend/src/core/globalHandlers.js', ['toggle-experimental-features', 'normalizeUiMode(state.uiMode) !== \'expert\'', 'setExperimentalFeaturesEnabled(next)']), 'experimental toggle is expert-only');

// 6. OpenSpace / Kanban statuses.
check(contains('frontend/src/features/openSpace/shared.js', "const OPEN_SPACE_STATUSES = ['backlog', 'todo', 'doing', 'testing', 'done']"), 'OpenSpace Kanban statuses are canonical');
for (const status of ['backlog', 'todo', 'doing', 'testing', 'done']) {
  check(contains('frontend/i18n.js', `'openspace.status.${status}'`), `i18n status ${status}`);
}

// 7. Critical i18n copy.
const i18n = read('frontend/i18n.js');
const i18nNeedles = [
  "'openspace.title': 'OpenSpace'",
  '🧪 ALPHA',
  'Orchestrator',
  'Kanban',
  'Agent Chat',
  'Fonctionnalités expérimentales',
  'Expérimental',
  'Persister avec revue requise',
  'Signaux décisionnels contradictoires',
  'Synchroniser les mémoires agents',
];
for (const needle of i18nNeedles) {
  check(i18n.includes(needle), `critical i18n copy present: ${needle}`);
}

// 8. Run Progress visible contracts.
check(fileHasAll('frontend/src/ui/runProgressPanel.js', ['staleness', 'current_llm_call', 'run_finalization', 'run_timeout_diagnostics', 'pollingLabel']), 'Run Progress panel renders staleness, current LLM, finalization, timeout and polling');
check(fileHasAll('frontend/src/services/runProgressService.js', ['startRunProgressPolling', 'intervalMs = 1500', 'terminal', 'stopRunProgressPolling']), 'Run Progress polling lifecycle present');
check(fileHasAll('frontend/i18n.js', ['runProgress.pollingOk', 'runProgress.stale60', 'runProgress.stale180', 'runProgress.providerSlow', 'runProgress.expertWall']), 'Run Progress i18n keys present');

// 9. Critical data-action orphan checks.
const criticalActions = [
  'toggle-experimental-features',
  'reload-live-session-result',
  'open-space-propose-plan',
  'open-space-accept-proposal',
  'open-space-create-task',
  'open-space-send-message',
  'open-space-load-agent-memory',
  'open-space-refresh-agent-memory',
  'open-space-export-board-jira',
  'open-space-export-task-jira',
  'preview-agent-context-memories-sync',
  'apply-agent-context-memories-sync',
];
const corpusText = srcFiles.map((x) => x.text).join('\n');

// 5b. Mobile layout notice (desktop-first, non-blocking).
check(fileHasAll('frontend/src/core/store.js', [
  'da_mobile_layout_notice_dismissed',
  'mobileLayoutNoticeDismissed',
  'dismissMobileLayoutNotice',
  'shouldShowMobileLayoutNotice',
  'isMobileLayout',
  'MOBILE_LAYOUT_MAX_WIDTH_PX = 768',
]), 'mobile layout notice store helpers and storage key');
check(fileHasAll('frontend/src/ui/mobileLayoutNotice.js', [
  'renderMobileLayoutNotice',
  'dismiss-mobile-layout-notice',
  'mobile-layout-notice__card',
  'max-width: 768px',
]), 'mobile layout notice UI component');
check(fileHasAll('frontend/src/core/renderer.js', ['renderMobileLayoutNotice']), 'renderer mounts mobile layout notice');
check(fileHasAll('frontend/src/core/globalHandlers.js', ["registerAction('dismiss-mobile-layout-notice'"]), 'dismiss-mobile-layout-notice action registered');
check(fileHasAll('frontend/i18n.js', [
  "'mobileNotice.title': 'Expérience ordinateur recommandée'",
  "'mobileNotice.title': 'Desktop experience recommended'",
  "'mobileNotice.continue': 'Continuer quand même'",
  "'mobileNotice.continue': 'Continue anyway'",
]), 'mobile notice i18n keys (fr + en)');
check(
  corpusText.includes('data-action="dismiss-mobile-layout-notice"')
    && corpusText.includes("registerAction('dismiss-mobile-layout-notice'"),
  'dismiss-mobile-layout-notice emitted and registered',
);

for (const action of criticalActions) {
  const emitted = corpusText.includes(`data-action="${action}"`) || corpusText.includes(`data-action='${action}'`);
  const registered = corpusText.includes(`registerAction('${action}'`) || corpusText.includes(`registerAction("${action}"`);
  check(emitted && registered, `critical data-action wired ${action}`, `emitted=${emitted} registered=${registered}`);
}

// 10. Service endpoint contracts used by E2E plan.
const services = [
  ['sessions get', 'frontend/src/services/sessionService.js', '/api/sessions/${sessionId}'],
  ['run-status', 'frontend/src/services/sessionService.js', '/run-status'],
  ['decision memory confirm', 'frontend/src/services/decisionMemoryService.js', '/decision-memory/confirm'],
  ['strategic memory overview', 'frontend/src/services/strategicContextService.js', '/memory-overview'],
  ['agent memories sync', 'frontend/src/services/strategicContextService.js', '/agent-memories/sync'],
  ['provider routing', 'frontend/src/services/providerService.js', '/api/providers/routing'],
  ['provider models', 'frontend/src/services/providerService.js', '/api/providers/models'],
  ['open space orchestrate', 'frontend/src/services/openSpaceService.js', '/api/open-space/orchestrate'],
  ['open space tasks', 'frontend/src/services/openSpaceService.js', '/api/open-space/tasks'],
  ['open space board jira export', 'frontend/src/services/openSpaceService.js', '/jira-export?context_id='],
];
for (const [label, file, needle] of services) {
  check(contains(file, needle), `service endpoint present ${label}`, `${file} missing ${needle}`);
}

console.log('\nSummary');
console.log(`PASS=${PASS} WARN=${WARN} FAIL=${FAIL}`);
if (FAIL > 0) process.exit(1);

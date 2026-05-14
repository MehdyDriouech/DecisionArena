import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(__dirname, '..', '..');

let PASS = 0;
let WARN = 0;
let FAIL = 0;

function read(rel) {
  return fs.readFileSync(path.join(projectRoot, rel), 'utf8');
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

function hasAll(text, needles) {
  return needles.every((needle) => text.includes(needle));
}

console.log('Decision Arena router contracts\n');

const router = read('frontend/src/core/router.js');
const experimentalGate = read('frontend/src/features/experimentalGate/index.js');
const sessionsHandlers = read('frontend/src/features/sessions/handlers.js');
const sessionHistory = read('frontend/src/features/sessionHistory/index.js');

check(hasAll(router, ["'': 'dashboard'", "'/': 'dashboard'", "dashboard: '#/dashboard'"]), '#/dashboard maps to dashboard');
check(hasAll(router, ['analyses: \'analyses\'', "analyses: '#/analyses'"]), '#/analyses maps to analyses');
check(hasAll(router, ["sessions: 'analyses'", "sessions: '#/analyses'"]), '#/sessions maps to analyses list alias');
check(hasAll(router, ["'new-session': '#/new-session'", "rawView || 'dashboard'"]), '#/new-session route declared');
check(hasAll(router, ["'strategic-contexts': '#/strategic-contexts'", "contexts: 'strategic-contexts'"]), '#/strategic-contexts route declared with alias');
check(router.includes("about: '#/about'"), "router VIEW_TO_HASH declares about -> '#/about'");
check(hasAll(router, ["admin: 'administration'", "administration: '#/administration'"]), '#/admin aliases administration (DA-SMOKE-002); canonical hash unchanged');

check(hasAll(router, ["head === 'sessions'", 'openSessionFromHash(route.sessionId)']), '#/sessions/{id} opens a session');
check(hasAll(router, ["head === 'analyses'", 'routeSource: \'hash\'']), '#/analyses/{id} aliases session open');
check(hasAll(router, ["head === 'session-history'", 'requestedMode: \'auto\'']), '#/session-history/{id} aliases session open');
check(hasAll(sessionsHandlers, ["registerAction('open-session'", 'SessionService.get(sid)', "navigate('session-history'"]), 'open-session handler fetches API and opens session history');
check(hasAll(sessionHistory, ["views['session-history']", "views['session-not-found'"]), 'session history and not-found views registered');

check(hasAll(router, ['UUID_LIKE_RE', 'invalid-id', 'session-not-found']), 'invalid session id produces controlled error view');
check(hasAll(router, ['fallback', 'openSessionById', 'handler indisponible']), 'missing open-session handler has controlled fallback/error');
check(hasAll(router, ['Object.prototype.hasOwnProperty.call(VIEW_TO_HASH, mapped)', "navigate('dashboard', { __skipHashSync: true })", 'async function applyHashRoute()']), 'unknown route falls back to dashboard explicitly');

for (const [hash, view] of [
  ['#/openspace/orchestrator', 'openspace-orchestrator'],
  ['#/openspace/kanban', 'openspace-kanban'],
  ['#/openspace/agent-chat', 'openspace-agent-chat'],
]) {
  check(router.includes(`view: '${view}'`), `${hash} route resolves to ${view}`);
}
check(hasAll(router, ['OPENSPACE_GATED_VIEWS', 'experimentalGateRequestedView', "resolvedView = 'experimental-gate'"]), 'OpenSpace direct routes are gated when experimental OFF');
check(hasAll(experimentalGate, ['experimental.featureDisabled.title', 'experimental.featureDisabled.detailOpenSpace', 'data-nav="administration"', 'data-action="set-ui-mode"', 'data-nav="dashboard"']), 'experimental gate renders explanatory guard page');

warn('router parser is validated by static inspection', 'parseHashRoute is not exported for direct Node import; browser smoke remains required for runtime hash behavior.');

console.log('\nSummary');
console.log(`PASS=${PASS} WARN=${WARN} FAIL=${FAIL}`);
if (FAIL > 0) process.exit(1);

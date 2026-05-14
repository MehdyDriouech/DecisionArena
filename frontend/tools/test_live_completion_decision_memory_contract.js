/**
 * Contract statique DA-SMOKE-001 : après run live completed, la session doit être
 * réhydratée via GET /api/sessions/{id} (finalizeLiveRunAfterTerminalPoll), pas seulement
 * via le dernier poll avant stopPolling().
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(__dirname, '..', '..');

let FAIL = 0;

function read(rel) {
  return fs.readFileSync(path.join(projectRoot, rel), 'utf8');
}

function check(cond, label) {
  if (!cond) {
    FAIL += 1;
    console.log(`[FAIL] ${label}`);
  } else {
    console.log(`[PASS] ${label}`);
  }
}

console.log('DA-SMOKE-001 live completion / Decision Memory contracts\n');

const qdHandlers = read('frontend/src/features/quickDecision/handlers.js');
check(
  qdHandlers.includes('await finalizeLiveRunAfterTerminalPoll')
    && qdHandlers.includes("'quick-decision'")
    && qdHandlers.includes("status: 'completed'"),
  'quickDecision handlers await finalize after POST with completed payload',
);

const drHandlers = read('frontend/src/features/decisionRoom/handlers.js');
check(
  drHandlers.includes('await finalizeLiveRunAfterTerminalPoll')
    && drHandlers.includes("'decision-room'"),
  'decisionRoom handlers await finalize after POST',
);

const completion = read('frontend/src/utils/liveRunCompletion.js');
check(
  completion.includes('liveRunFinalizeInFlight')
    && completion.includes('hydrateLiveRunSession')
    && completion.includes('ensureLiveRunSessionHydratedIfMismatch'),
  'liveRunCompletion exports hydrate + dedupe + ensure guard',
);

const qdView = read('frontend/src/features/quickDecision/index.js');
check(
  qdView.includes('ensureLiveRunSessionHydratedIfMismatch')
    && qdView.includes('terminalRunCompleted'),
  'quickDecision view triggers ensure guard and passes terminalRunCompleted to outcome card',
);

const components = read('frontend/src/ui/components.js');
check(
  components.includes('terminalRunCompleted')
    && components.includes('persistSyncingFinalResult')
    && components.includes('showPersistNotCompletedLine'),
  'renderDecisionOutcomeCard handles terminal completed vs session stale (no false not-completed line)',
);

const i18n = read('frontend/i18n.js');
check(
  i18n.includes('decisionMemory.persistSyncingFinalResult')
    && i18n.includes('decisionMemory.persist.status.syncing'),
  'i18n defines syncing strings for Decision Memory card',
);

if (FAIL > 0) {
  console.error(`\n${FAIL} check(s) failed`);
  process.exit(1);
}
console.log('\nAll DA-SMOKE-001 contract checks passed.');

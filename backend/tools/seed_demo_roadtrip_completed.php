<?php
declare(strict_types=1);

/**
 * Seed idempotent : use case COMPLETED « Roadtrip Moto » (synthétique, sans LLM).
 *
 * Usage:
 *   php backend/tools/seed_demo_roadtrip_completed.php --help
 *   php backend/tools/seed_demo_roadtrip_completed.php --dry-run
 *   php backend/tools/seed_demo_roadtrip_completed.php --yes
 *   php backend/tools/seed_demo_roadtrip_completed.php --yes --reset-existing
 *
 * Réutilise le contexte « Beta Roadtrip Moto B2C » s’il existe.
 * Ne crée pas de draft. Ne nettoie pas toute la DB.
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/Migration.php';

use Domain\Agents\DecisionDynamicsPreset;
use Domain\DecisionReliability\ReliabilityConfig;
use Domain\Orchestration\DecisionOutcomeProjector;
use Domain\Orchestration\StructuredRunResult;
use Domain\Sessions\SessionStrategicContextGuard;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

const PLAYBOOK_ID = 'founder-sprint';
const CONTEXT_TITLE = 'Beta Roadtrip Moto B2C';
const SESSION_ID = 'roadtrip-moto-completed-beta-launch';
const SESSION_TITLE = 'COMPLETED — Décision bêta Roadtrip Moto';

const CONTEXT_DESCRIPTION = <<<'TXT'
Nous préparons le lancement en bêta publique d’une application B2C destinée aux motards qui veulent préparer et partager des roadtrips. L’application est techniquement stable, mais le périmètre fonctionnel est réduit. La décision porte sur l’opportunité de lancer maintenant une bêta limitée avec un bouton de feedback intégré, ou d’attendre un périmètre plus riche.
TXT;

const SESSION_QUESTION = 'Devons-nous lancer maintenant une bêta publique limitée de l’application Roadtrip Moto, malgré un périmètre fonctionnel réduit, si l’app est stable et intègre un bouton de feedback utilisateur ?';

const CONTEXT_DOC_TITLE = 'Contexte produit — Bêta Roadtrip Moto';

/** Même document que seed_demo_roadtrip_draft.php */
const CONTEXT_DOC_CONTENT = <<<'MD'
# Contexte produit

Nous préparons le lancement en bêta publique d’une application B2C destinée aux motards qui veulent préparer et partager des roadtrips.

L’application est techniquement stable, mais le périmètre fonctionnel est réduit. La décision porte sur l’opportunité de lancer maintenant une bêta limitée avec un bouton de feedback intégré, ou d’attendre un périmètre plus riche.

## Cible

- Motards loisirs.
- Jeunes permis A/A2.
- Groupes de balade.
- Utilisateurs solo qui préparent leurs sorties.
- Motards qui aiment découvrir des cols, routes panoramiques et points d’intérêt.

## Proposition de valeur

Aider un motard à préparer simplement un roadtrip, enregistrer des étapes, visualiser un itinéraire basique, partager une balade et remonter du feedback.

## État actuel du produit

- Application stable techniquement.
- Création d’un roadtrip basique.
- Ajout de quelques étapes.
- Consultation d’un itinéraire.
- Bouton feedback intégré.
- Tracking d’événements minimal.
- Expérience suffisamment fluide pour une petite cohorte.

## Manques fonctionnels

- Pas de navigation GPS avancée.
- Pas de météo intégrée.
- Pas de recommandations automatiques de cols ou routes.
- Pas de mode offline.
- Pas de partage social avancé.
- Pas de scoring qualité d’itinéraire.
- Peu de contenu communautaire au démarrage.
- Pas encore d’alertes sécurité ou travaux.
- Pas encore de mode groupe avancé.

## Contraintes

- Petite équipe.
- Besoin d’apprendre vite.
- Risque de décevoir les premiers utilisateurs si la promesse marketing est trop large.
- Besoin de feedback réel.
- Coût d’attente : perte d’apprentissage et de momentum.
- Budget limité.
- La stabilité technique est meilleure que la richesse fonctionnelle.

## Hypothèses

- Une bêta limitée, honnête et bien cadrée peut créer plus de valeur qu’une attente prolongée.
- Les motards acceptent un périmètre réduit si les limitations sont explicites.
- Le bouton feedback peut transformer les frustrations en apprentissage produit.
- La vraie priorité fonctionnelle doit être décidée à partir d’usages réels, pas uniquement d’intuition interne.

## Risques

- Mauvaise compréhension de la promesse produit.
- Comparaison défavorable avec des apps GPS établies.
- Feedback non exploitable si trop libre.
- Early adopters trop indulgents ou trop exigeants.
- Mauvais signal si trop peu d’utilisateurs créent réellement un roadtrip.

## Critères de succès bêta

- 50 à 100 motards inscrits.
- Au moins 40 % créent un roadtrip.
- Au moins 25 % utilisent ou consultent le feedback.
- Rétention J7 supérieure à 20 %.
- Au moins 30 feedbacks exploitables en 2 semaines.
- Top 3 des manques fonctionnels clairement identifiés.

## Critères de pause

- Confusion majoritaire sur la promesse.
- Retours négatifs récurrents sur le manque de GPS/offline.
- Moins de 10 % des utilisateurs créent un roadtrip.
- Feedback trop pauvre pour orienter la roadmap.
- Problèmes de stabilité malgré les tests internes.
MD;

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function hasFlag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function printHelp(): void
{
    out('Seed COMPLETED — Roadtrip Moto (session synthétique + decision brief + memory).');
    out('');
    out('Usage:');
    out('  php backend/tools/seed_demo_roadtrip_completed.php [--dry-run] [--yes] [--reset-existing] [--help]');
    out('');
    out('Options:');
    out('  --dry-run          Affiche le plan sans écrire en base');
    out('  --yes              Exécute les écritures (obligatoire hors dry-run)');
    out('  --reset-existing   Supprime uniquement l’ancien completed Roadtrip Moto');
    out('  --help             Cette aide');
}

function findContextByTitle(\PDO $pdo, string $title): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM strategic_contexts WHERE title = ? LIMIT 1');
    $stmt->execute([$title]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findCompletedSession(\PDO $pdo): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ? OR title = ? LIMIT 1');
    $stmt->execute([SESSION_ID, SESSION_TITLE]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function buildInitialPrompt(string $question): string
{
    return trim($question) . "\n\n## Runtime Playbook\nplaybook_id: " . PLAYBOOK_ID;
}

/** @return array<string,mixed> */
function decisionBriefPayload(): array
{
    return [
        'decision' => 'Lancer une bêta publique limitée de Roadtrip Moto',
        'confidence' => 'medium',
        'why' => [
            'La stabilité technique permet un apprentissage réel sans attendre un périmètre complet.',
            'Le manque fonctionnel est acceptable si la promesse bêta est honnête et limitée.',
            'Le bouton de feedback permet de transformer les manques fonctionnels en apprentissages priorisés.',
        ],
        'risks' => [
            'Déception utilisateur si la bêta est perçue comme une app de navigation complète.',
            'Feedback trop dispersé si les questions ne sont pas structurées.',
            'Bouche-à-oreille négatif si les limitations ne sont pas assumées.',
            'Biais de premiers testeurs motards très engagés.',
        ],
        'next_step' => 'Lancer une bêta contrôlée auprès de 50 à 100 motards avec promesse limitée, feedback contextualisé et critères de pause.',
    ];
}

/** @return array<string,mixed> */
function canonicalSynthesisPayload(): array
{
    return [
        'playbook_id' => PLAYBOOK_ID,
        'decision' => 'GO limité — lancer une bêta publique contrôlée',
        'status' => 'proceed_with_constraints',
        'confidence' => 'moderate',
        'why' => decisionBriefPayload()['why'],
        'risks' => decisionBriefPayload()['risks'],
        'blocking_unknowns' => [],
        'recommended_next_actions' => [
            'Définir la promesse bêta en une phrase.',
            'Limiter la cohorte à 50-100 motards.',
            'Ajouter le feedback dans les moments clés : création, fin de roadtrip, abandon.',
            'Mesurer activation, roadtrips créés, feedback envoyé, rétention J7.',
            'Décider après 2 semaines : élargir, itérer ou suspendre.',
        ],
        'evidence_claims' => [
            [
                'claim' => 'Une version stable mais fonctionnellement réduite peut créer de l’apprentissage si la promesse est claire.',
                'verification_status' => 'verified',
            ],
            [
                'claim' => 'Le feedback intégré peut orienter la roadmap mieux qu’un développement à l’aveugle.',
                'verification_status' => 'verified',
            ],
        ],
        'parser_diagnostics' => [
            'parser_confidence' => 1.0,
            'missing_fields' => [],
            'repaired_fields' => [],
            'fallback_used' => false,
            'extraction_strategy_used' => ['demo_seed'],
            'warnings' => [],
        ],
    ];
}

/** @return array<string,mixed> */
function buildRunResultForMemory(): array
{
    $canonical = canonicalSynthesisPayload();
    $outcome = DecisionOutcomeProjector::fromCanonical($canonical, []);

    return [
        'canonical_synthesis' => $canonical,
        'decision_outcome' => $outcome,
    ];
}

/** @return array<string,mixed> */
function minimalPersistableResult(): array
{
    $adjusted = [
        'decision_label' => 'go',
        'vote_label' => 'GO',
        'ui_decision_label' => 'go',
        'legacy_decision_label' => 'go',
        'decision_status' => 'CONFIDENT',
        'final_outcome' => 'GO_CONFIDENT',
        'confidence_level' => 'medium',
    ];

    $full = [
        'raw_decision' => $adjusted,
        'adjusted_decision' => $adjusted,
        'decision_outcome' => [
            'label' => 'go',
            'summary' => 'Lancer une bêta publique limitée, sous conditions strictes de cadrage, de feedback et de mesure.',
            'confidence' => 'medium',
            'status' => 'proceed_with_constraints',
        ],
        'decision_quality_score' => 78,
        'false_consensus' => [
            'risk_level' => 'medium',
            'signals' => [
                'Consensus conditionnel autour du lancement',
                'Désaccord maintenu sur l’impact du manque fonctionnel',
            ],
        ],
        'final_votes' => [
            ['agent_id' => 'pm', 'vote' => 'go', 'confidence' => 7, 'rationale' => 'Le lancement limité maximise l’apprentissage produit.'],
            ['agent_id' => 'ux-expert', 'vote' => 'reduce-scope', 'confidence' => 7, 'rationale' => 'Le lancement est acceptable uniquement avec une promesse très claire et un onboarding explicite.'],
            ['agent_id' => 'architect', 'vote' => 'go', 'confidence' => 8, 'rationale' => 'La stabilité technique réduit le risque opérationnel.'],
            ['agent_id' => 'qa', 'vote' => 'go', 'confidence' => 7, 'rationale' => 'Le risque qualité est acceptable si le périmètre reste contrôlé.'],
            ['agent_id' => 'analyst', 'vote' => 'needs-more-info', 'confidence' => 6, 'rationale' => 'La cohorte et les métriques de succès doivent être définies avant ouverture.'],
            ['agent_id' => 'critic', 'vote' => 'reduce-scope', 'confidence' => 8, 'rationale' => 'Le principal danger est la confusion avec une application GPS complète.'],
        ],
        'recommended_conditions' => [
            'Présenter le produit comme une bêta limitée.',
            'Limiter la première cohorte à 50-100 motards.',
            'Ne pas promettre navigation GPS, offline ou météo intégrée.',
            'Rendre le bouton feedback visible et contextualisé.',
            'Décider après deux semaines sur la base des métriques d’usage.',
        ],
        'seeded_demo_notice' => 'Résultat synthétique de démonstration : les messages, votes et edges détaillés n’ont pas été générés par un run LLM.',
        'canonical_synthesis' => canonicalSynthesisPayload(),
        'decision_outcome' => buildRunResultForMemory()['decision_outcome'],
    ];

    return StructuredRunResult::persistableResultSlice($full);
}

function deleteCompletedSession(\PDO $pdo, DecisionMemoryRepository $memRepo, string $sessionId, bool $dryRun): void
{
    if ($dryRun) {
        out('[DRY] DELETE completed session artifacts for ' . $sessionId);
        return;
    }

    $existingMem = $memRepo->findBySession($sessionId);
    if ($existingMem && !empty($existingMem['memory_id'])) {
        $memRepo->deleteHard((string)$existingMem['memory_id']);
        out('[OK] Decision memory supprimée: ' . $existingMem['memory_id']);
    }

    $sid = $pdo->quote($sessionId);
    try {
        $pdo->exec('DELETE FROM session_context_document_chunks WHERE session_id = ' . $sid);
    } catch (\Throwable) {
    }
    $pdo->exec('DELETE FROM session_context_documents WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM strategic_context_sessions WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM messages WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM session_snapshots WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM session_verdicts WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM session_action_plans WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM arguments WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM agent_positions WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM interaction_edges WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM session_votes WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM session_decisions WHERE session_id = ' . $sid);
    $pdo->exec('DELETE FROM sessions WHERE id = ' . $sid);
}

function resetExisting(\PDO $pdo, DecisionMemoryRepository $memRepo, bool $dryRun): void
{
    $session = findCompletedSession($pdo);
    if (!$session) {
        out('[INFO] Aucun completed Roadtrip Moto à supprimer.');
        return;
    }
    $sid = (string)($session['id'] ?? '');
    out(($dryRun ? '[DRY] ' : '') . 'Reset completed: ' . $sid . ' — ' . ($session['title'] ?? ''));
    deleteCompletedSession($pdo, $memRepo, $sid, $dryRun);
}

/**
 * @return array{context_id:string,session_id:string,memory_id:string}
 */
function runSeed(bool $dryRun, bool $resetExisting): array
{
    $pdo = Database::getConnection();
    (new Migration(Database::getInstance()))->run();

    $ctxRepo = new StrategicContextRepository();
    $sessionRepo = new SessionRepository();
    $docRepo = new ContextDocumentRepository();
    $memRepo = new DecisionMemoryRepository();

    $existingSession = findCompletedSession($pdo);
    if ($existingSession && !$resetExisting) {
        $contextId = (string)($existingSession['strategic_context_id'] ?? '');
        $mem = $memRepo->findBySession((string)$existingSession['id']) ?? [];
        out('[SKIP] Completed déjà présent. Utilisez --reset-existing pour recréer.');
        return [
            'context_id' => $contextId,
            'session_id' => (string)$existingSession['id'],
            'memory_id' => (string)($mem['memory_id'] ?? ''),
        ];
    }

    // —— Strategic context (réutiliser ou créer) ——
    $ctxRow = findContextByTitle($pdo, CONTEXT_TITLE);
    $contextId = '';
    if ($ctxRow) {
        $contextId = (string)($ctxRow['context_id'] ?? '');
        out('[REUSE] Strategic context: ' . $contextId);
    } elseif ($dryRun) {
        $contextId = '(new-uuid)';
        out('[DRY] CREATE strategic context « ' . CONTEXT_TITLE . ' »');
    } else {
        $created = $ctxRepo->create(CONTEXT_TITLE, CONTEXT_DESCRIPTION, 'active');
        $contextId = (string)($created['context_id'] ?? '');
        out('[OK] Strategic context créé: ' . $contextId);
    }

    if (!$dryRun && $contextId !== '') {
        $ctxRepo->setActiveContext($contextId);
        out('[OK] Workspace actif: ' . $contextId);
    } elseif ($dryRun) {
        out('[DRY] setActiveContext(' . $contextId . ')');
    }

    $agents = ['pm', 'ux-expert', 'architect', 'qa', 'analyst', 'critic'];
    $now = date('c');
    $brief = decisionBriefPayload();
    $resultSlice = minimalPersistableResult();

    if ($dryRun) {
        out('[DRY] CREATE session completed id=' . SESSION_ID);
        out('[DRY]   decision_brief + result (slice StructuredRunResult)');
        out('[DRY]   persistAfterConfirmation → decision_memories (status taxonomy: proceed_with_constraints)');
        out('[DRY] UPSERT context document');
        return [
            'context_id' => $contextId,
            'session_id' => SESSION_ID,
            'memory_id' => '(new-memory-id)',
        ];
    }

    $sessionRepo->create([
        'id' => SESSION_ID,
        'title' => SESSION_TITLE,
        'mode' => 'decision-room',
        'initial_prompt' => buildInitialPrompt(SESSION_QUESTION),
        'selected_agents' => $agents,
        'rounds' => 2,
        'language' => 'fr',
        'status' => 'completed',
        'force_disagreement' => 1,
        'decision_threshold' => 0.55,
        'strategic_context_id' => $contextId,
        'selected_memory_ids' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $preset = DecisionDynamicsPreset::normalizeId('balanced');
    try {
        $sessionRepo->update(SESSION_ID, ['decision_dynamics_preset' => $preset]);
    } catch (\Throwable) {
    }

    SessionStrategicContextGuard::syncStrategicContextSessionLink($contextId, SESSION_ID);
    $pdo->prepare('UPDATE sessions SET strategic_context_id = ? WHERE id = ?')
        ->execute([$contextId, SESSION_ID]);

    $sessionRepo->update(SESSION_ID, [
        'status' => 'completed',
        'result' => json_encode($resultSlice, JSON_UNESCAPED_UNICODE),
        'decision_brief' => json_encode($brief, JSON_UNESCAPED_UNICODE),
        'context_quality_score' => 0.72,
        'context_quality_level' => 'medium',
    ]);

    out('[OK] Session completed: ' . SESSION_ID);

    $docRepo->upsert([
        'id' => 'ctxdoc-' . SESSION_ID,
        'session_id' => SESSION_ID,
        'title' => CONTEXT_DOC_TITLE,
        'source_type' => 'seed',
        'original_filename' => null,
        'mime_type' => 'text/markdown',
        'content' => CONTEXT_DOC_CONTENT,
        'character_count' => strlen(CONTEXT_DOC_CONTENT),
        'created_at' => $now,
    ]);
    out('[OK] Context document upserted');

    $memory = $memRepo->persistAfterConfirmation(buildRunResultForMemory(), SESSION_ID);
    if (!$memory || empty($memory['memory_id'])) {
        throw new \RuntimeException('persistAfterConfirmation a échoué — vérifier le contrat canonical/outcome');
    }

    $memoryId = (string)$memory['memory_id'];
    $demoSummary = 'Lancer une bêta publique limitée de Roadtrip Moto avec promesse cadrée, bouton feedback visible et critères de décision avant élargissement.';
    $pdo->prepare('
        UPDATE decision_memories SET
            decision_status = :st,
            confidence = :conf,
            decision_summary = :sum,
            validated_hypotheses = :vh,
            failed_assumptions = :fa,
            unresolved_risks = :ur,
            recommended_next_steps = :rn,
            historical_outcome = :ho,
            memory_state = :ms,
            user_confirmed = 1
        WHERE memory_id = :id
    ')->execute([
        ':st' => 'proceed_with_constraints',
        ':conf' => 'moderate',
        ':sum' => $demoSummary,
        ':vh' => json_encode([
            'Une version stable mais fonctionnellement réduite peut créer de l’apprentissage si la promesse est claire.',
            'Le feedback intégré peut orienter la roadmap mieux qu’un développement à l’aveugle.',
        ], JSON_UNESCAPED_UNICODE),
        ':fa' => '[]',
        ':ur' => json_encode([
            'Les utilisateurs peuvent attendre une navigation GPS complète.',
            'Le feedback peut manquer de structure.',
            'La cohorte initiale peut ne pas représenter le marché B2C large.',
        ], JSON_UNESCAPED_UNICODE),
        ':rn' => json_encode([
            'Définir la promesse bêta en une phrase.',
            'Limiter la cohorte à 50-100 motards.',
            'Ajouter le feedback dans les moments clés : création, fin de roadtrip, abandon.',
            'Mesurer activation, roadtrips créés, feedback envoyé, rétention J7.',
            'Décider après 2 semaines : élargir, itérer ou suspendre.',
        ], JSON_UNESCAPED_UNICODE),
        ':ho' => 'proceed_with_constraints',
        ':ms' => 'active',
        ':id' => $memoryId,
    ]);
    try {
        $memRepo->rebuildDecisionMemoryFts();
    } catch (\Throwable) {
    }

    $memory = $memRepo->findById($memoryId) ?? $memory;
    out('[OK] Decision memory: ' . $memory['memory_id'] . ' (state=' . ($memory['memory_state'] ?? '') . ')');

    return [
        'context_id' => $contextId,
        'session_id' => SESSION_ID,
        'memory_id' => (string)$memory['memory_id'],
    ];
}

// —— CLI ——
$argv = $argv ?? [];
if (hasFlag($argv, '--help') || hasFlag($argv, '-h')) {
    printHelp();
    exit(0);
}

$dryRun = hasFlag($argv, '--dry-run');
$yes = hasFlag($argv, '--yes');
$reset = hasFlag($argv, '--reset-existing');

if (!$dryRun && !$yes) {
    out('Précisez --dry-run ou --yes.');
    exit(1);
}

out('=== Seed COMPLETED Roadtrip Moto ===');
out('Taxonomie mémoire : proceed_with_constraints / moderate (pas « APPROVE » — voir RuntimeContracts).');
out('');

$pdo = Database::getConnection();
(new Migration(Database::getInstance()))->run();
$memRepo = new DecisionMemoryRepository();

if ($reset) {
    resetExisting($pdo, $memRepo, $dryRun);
}

try {
    $ids = runSeed($dryRun, $reset);
} catch (\Throwable $e) {
    out('[ERROR] ' . $e->getMessage());
    exit(1);
}

out('');
out('--- Résumé ---');
out('context_id: ' . $ids['context_id']);
out('session_id: ' . $ids['session_id']);
out('memory_id: ' . $ids['memory_id']);
out('Done.');

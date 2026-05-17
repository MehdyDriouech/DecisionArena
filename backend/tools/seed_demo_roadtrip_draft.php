<?php
declare(strict_types=1);

/**
 * Seed idempotent : use case DRAFT « Roadtrip Moto » (bêta publique).
 *
 * Usage:
 *   php backend/tools/seed_demo_roadtrip_draft.php --help
 *   php backend/tools/seed_demo_roadtrip_draft.php --dry-run
 *   php backend/tools/seed_demo_roadtrip_draft.php --yes
 *   php backend/tools/seed_demo_roadtrip_draft.php --yes --reset-existing
 *
 * Ne crée pas de session completed, messages, votes, mémoires, etc.
 * Playbook runtime : founder-sprint (marqueur dans initial_prompt — voir PLAYBOOK_NOTE).
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
use Domain\Sessions\SessionStrategicContextGuard;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/** Playbook choisi : founder-sprint (lancement bêta produit, signaux validation/kill). */
const PLAYBOOK_ID = 'founder-sprint';
const PLAYBOOK_NOTE = 'founder-sprint via marqueur playbook_id dans initial_prompt (PlaybookRuntime::MARKERS); pas de colonne product_preset en DB.';

const CONTEXT_TITLE = 'Beta Roadtrip Moto B2C';
const SESSION_ID = 'roadtrip-moto-draft-beta-launch';
const SESSION_TITLE = 'DRAFT — Arbitrer le lancement bêta Roadtrip Moto';
const SCENARIO_PACK_ID = 'roadtrip-moto-beta-launch';

const CONTEXT_DESCRIPTION = <<<'TXT'
Nous préparons le lancement en bêta publique d’une application B2C destinée aux motards qui veulent préparer et partager des roadtrips. L’application est techniquement stable, mais le périmètre fonctionnel est réduit. La décision porte sur l’opportunité de lancer maintenant une bêta limitée avec un bouton de feedback intégré, ou d’attendre un périmètre plus riche.
TXT;

const SESSION_QUESTION = 'Devons-nous lancer maintenant une bêta publique limitée de l’application Roadtrip Moto, malgré un périmètre fonctionnel réduit, si l’app est stable et intègre un bouton de feedback utilisateur ?';

const CONTEXT_DOC_TITLE = 'Contexte produit — Bêta Roadtrip Moto';

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
    out('Seed DRAFT — Roadtrip Moto (strategic context + session draft + context document).');
    out('');
    out('Usage:');
    out('  php backend/tools/seed_demo_roadtrip_draft.php [--dry-run] [--yes] [--reset-existing] [--help]');
    out('');
    out('Options:');
    out('  --dry-run          Affiche le plan sans écrire en base');
    out('  --yes              Exécute les écritures (obligatoire hors dry-run)');
    out('  --reset-existing   Supprime uniquement l’ancien draft Roadtrip Moto s’il existe');
    out('  --help             Cette aide');
}

function buildInitialPrompt(string $question): string
{
    $q = trim($question);
    $marker = "\n\n## Runtime Playbook\nplaybook_id: " . PLAYBOOK_ID;
    return $q . $marker;
}

function findContextByTitle(\PDO $pdo, string $title): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM strategic_contexts WHERE title = ? LIMIT 1');
    $stmt->execute([$title]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findSessionByTitleOrId(\PDO $pdo, string $title, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ? OR title = ? LIMIT 1');
    $stmt->execute([$id, $title]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function deleteSessionArtifacts(\PDO $pdo, string $sessionId, bool $dryRun): void
{
    if ($dryRun) {
        out('[DRY] DELETE session artifacts for ' . $sessionId);
        return;
    }
    $q = static fn (string $sql) => $pdo->exec($sql);
    $sid = $pdo->quote($sessionId);
    try {
        $pdo->exec('DELETE FROM session_context_document_chunks WHERE session_id = ' . $sid);
    } catch (\Throwable) {
    }
    $q("DELETE FROM session_context_documents WHERE session_id = {$sid}");
    $q("DELETE FROM strategic_context_sessions WHERE session_id = {$sid}");
    $q("DELETE FROM messages WHERE session_id = {$sid}");
    $q("DELETE FROM session_snapshots WHERE session_id = {$sid}");
    $q("DELETE FROM session_verdicts WHERE session_id = {$sid}");
    $q("DELETE FROM session_action_plans WHERE session_id = {$sid}");
    $q("DELETE FROM arguments WHERE session_id = {$sid}");
    $q("DELETE FROM agent_positions WHERE session_id = {$sid}");
    $q("DELETE FROM interaction_edges WHERE session_id = {$sid}");
    $q("DELETE FROM session_votes WHERE session_id = {$sid}");
    $q("DELETE FROM session_decisions WHERE session_id = {$sid}");
    $q("DELETE FROM sessions WHERE id = {$sid}");
}

function resetExisting(\PDO $pdo, StrategicContextRepository $ctxRepo, bool $dryRun): void
{
    $session = findSessionByTitleOrId($pdo, SESSION_TITLE, SESSION_ID);
    if ($session) {
        out(($dryRun ? '[DRY] ' : '') . 'Reset session: ' . ($session['id'] ?? '') . ' — ' . ($session['title'] ?? ''));
        deleteSessionArtifacts($pdo, (string)$session['id'], $dryRun);
    }

    $ctx = findContextByTitle($pdo, CONTEXT_TITLE);
    if ($ctx) {
        $cid = (string)($ctx['context_id'] ?? '');
        out(($dryRun ? '[DRY] ' : '') . 'Reset strategic context: ' . $cid);
        if (!$dryRun && $cid !== '') {
            $ctxRepo->delete($cid);
        }
    }

    if (tableExists($pdo, 'scenario_packs')) {
        out(($dryRun ? '[DRY] ' : '') . 'Reset scenario pack: ' . SCENARIO_PACK_ID);
        if (!$dryRun) {
            $pdo->prepare('DELETE FROM scenario_packs WHERE id = ?')->execute([SCENARIO_PACK_ID]);
        }
    }
}

function seedScenarioPack(\PDO $pdo, bool $dryRun): void
{
    if (!tableExists($pdo, 'scenario_packs')) {
        out('[SKIP] scenario_packs table absent — pack non créé (lancer seed_templates.php si besoin).');
        return;
    }

    $questions = [
        'Quelle promesse exacte faisons-nous aux bêta-testeurs ?',
        'Quelles fonctionnalités sont explicitement hors périmètre ?',
        'Quel est le profil de la première cohorte ?',
        'Quels signaux déclenchent un élargissement ?',
        'Quels signaux déclenchent une pause ?',
    ];

    $row = [
        'id' => SCENARIO_PACK_ID,
        'title' => 'Roadtrip Moto — Décision de lancement bêta',
        'description' => 'Arbitrage lancement bêta publique limitée Roadtrip Moto.',
        'mode' => 'decision-room',
        'selected_agents' => json_encode(['pm', 'ux-expert', 'architect', 'qa', 'analyst', 'critic'], JSON_UNESCAPED_UNICODE),
        'rounds' => 2,
        'force_disagreement' => 1,
        'devil_advocate_enabled' => 0,
        'auto_retry_on_weak_debate' => 0,
        'context_questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
        'is_system_template' => 0,
    ];

    if ($dryRun) {
        out('[DRY] UPSERT scenario_packs id=' . SCENARIO_PACK_ID);
        return;
    }

    $pdo->prepare('DELETE FROM scenario_packs WHERE id = ?')->execute([SCENARIO_PACK_ID]);
    $stmt = $pdo->prepare(
        'INSERT INTO scenario_packs
            (id, title, description, mode, selected_agents, rounds, force_disagreement,
             devil_advocate_enabled, auto_retry_on_weak_debate, context_questions, is_system_template)
         VALUES
            (:id, :title, :description, :mode, :selected_agents, :rounds, :force_disagreement,
             :devil_advocate_enabled, :auto_retry_on_weak_debate, :context_questions, :is_system_template)'
    );
    $stmt->execute($row);
    out('[OK] scenario_packs — ' . SCENARIO_PACK_ID);
}

/** @return array{context_id:string,session_id:string} */
function runSeed(bool $dryRun, bool $resetExisting): array
{
    $pdo = Database::getConnection();
    (new Migration(Database::getInstance()))->run();

    $ctxRepo = new StrategicContextRepository();
    $sessionRepo = new SessionRepository();
    $docRepo = new ContextDocumentRepository();

    $existingCtx = findContextByTitle($pdo, CONTEXT_TITLE);
    $existingSession = findSessionByTitleOrId($pdo, SESSION_TITLE, SESSION_ID);

    if ($existingCtx && $existingSession && !$resetExisting) {
        $contextId = (string)($existingCtx['context_id'] ?? '');
        $sessionId = (string)($existingSession['id'] ?? '');
        if (!$dryRun && $contextId !== '') {
            $ctxRepo->setActiveContext($contextId);
            SessionStrategicContextGuard::syncStrategicContextSessionLink($contextId, $sessionId);
        }
        out('[SKIP] Use case déjà présent (contexte + session). Utilisez --reset-existing pour recréer.');
        return ['context_id' => $contextId, 'session_id' => $sessionId];
    }

    // —— Strategic context ——
    $contextId = '';
    if ($existingCtx && !$resetExisting) {
        $contextId = (string)$existingCtx['context_id'];
        out('[REUSE] Strategic context ' . $contextId);
    } elseif ($dryRun) {
        $contextId = '(new-uuid)';
        out('[DRY] CREATE strategic context « ' . CONTEXT_TITLE . ' » (active)');
    } else {
        $created = $ctxRepo->create(CONTEXT_TITLE, CONTEXT_DESCRIPTION, 'active');
        $contextId = (string)($created['context_id'] ?? '');
        if ($contextId === '') {
            throw new \RuntimeException('Échec création strategic context');
        }
        out('[OK] Strategic context créé: ' . $contextId);
    }

    if (!$dryRun && $contextId !== '') {
        if (!$ctxRepo->setActiveContext($contextId)) {
            throw new \RuntimeException('setActiveContext a échoué pour ' . $contextId);
        }
        out('[OK] Workspace actif: ' . $contextId);
    } elseif ($dryRun) {
        out('[DRY] setActiveContext(' . $contextId . ')');
    }

    // —— Session draft ——
    $sessionId = SESSION_ID;
    $agents = ['pm', 'ux-expert', 'architect', 'qa', 'analyst', 'critic'];
    $now = date('c');

    if ($existingSession && !$resetExisting) {
        $sessionId = (string)$existingSession['id'];
        out('[REUSE] Session ' . $sessionId);
    } elseif ($dryRun) {
        out('[DRY] CREATE session draft id=' . SESSION_ID);
        out('[DRY]   mode=decision-room status=draft rounds=2 lang=fr threshold=0.55');
        out('[DRY]   agents=' . implode(',', $agents));
        out('[DRY]   playbook=' . PLAYBOOK_ID . ' (' . PLAYBOOK_NOTE . ')');
    } else {
        $sessionRepo->create([
            'id' => SESSION_ID,
            'title' => SESSION_TITLE,
            'mode' => 'decision-room',
            'initial_prompt' => buildInitialPrompt(SESSION_QUESTION),
            'selected_agents' => $agents,
            'rounds' => 2,
            'language' => 'fr',
            'status' => 'draft',
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

        out('[OK] Session draft créée: ' . SESSION_ID);
        $sessionId = SESSION_ID;
    }

    // —— Context document ——
    if ($dryRun) {
        out('[DRY] UPSERT context document for session ' . $sessionId);
    } else {
        $docRepo->upsert([
            'id' => 'ctxdoc-' . $sessionId,
            'session_id' => $sessionId,
            'title' => CONTEXT_DOC_TITLE,
            'source_type' => 'seed',
            'original_filename' => null,
            'mime_type' => 'text/markdown',
            'content' => CONTEXT_DOC_CONTENT,
            'character_count' => strlen(CONTEXT_DOC_CONTENT),
            'created_at' => $now,
        ]);
        out('[OK] Context document upserted for ' . $sessionId);
    }

    seedScenarioPack($pdo, $dryRun);

    return [
        'context_id' => $contextId,
        'session_id' => $sessionId,
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

out('=== Seed DRAFT Roadtrip Moto ===');
out('Playbook: ' . PLAYBOOK_ID . ' — ' . PLAYBOOK_NOTE);
out('');

$pdo = Database::getConnection();
(new Migration(Database::getInstance()))->run();
$ctxRepo = new StrategicContextRepository();

if ($reset) {
    resetExisting($pdo, $ctxRepo, $dryRun);
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
out('scenario_pack: ' . (tableExists($pdo, 'scenario_packs') ? SCENARIO_PACK_ID : '(table absente)'));
out('Done.');

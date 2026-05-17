<?php
declare(strict_types=1);

/**
 * Nettoyage des données métier (sessions, contextes, mémoires, openspace…).
 *
 * Usage:
 *   php backend/tools/clean_demo_db.php --help
 *   php backend/tools/clean_demo_db.php --dry-run
 *   php backend/tools/clean_demo_db.php --yes
 *   php backend/tools/clean_demo_db.php --yes --include-templates-demo
 *
 * Ne supprime PAS : providers, demo_users, demo_llm_usage, personas (fichiers), prompts, routing.
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

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\Migration;

final class CleanDemoDb
{
    private \PDO $pdo;
    private bool $dryRun;
    private bool $includeDemoTemplates;

    /** @var list<array{table:string,sql:string,label?:string}> */
    private array $steps = [];

    public function __construct(\PDO $pdo, bool $dryRun, bool $includeDemoTemplates)
    {
        $this->pdo = $pdo;
        $this->dryRun = $dryRun;
        $this->includeDemoTemplates = $includeDemoTemplates;
        $this->buildSteps();
    }

    private function buildSteps(): void
    {
        $s = fn (string $table, string $sql, ?string $label = null) => $this->steps[] = [
            'table' => $table,
            'sql' => $sql,
            'label' => $label ?? $table,
        ];

        // —— OpenSpace (dépend des contextes) ——
        $s('open_space_task_messages', 'DELETE FROM open_space_task_messages');
        $s('open_space_task_events', 'DELETE FROM open_space_task_events');
        $s('open_space_tasks', 'DELETE FROM open_space_tasks');
        $s('open_space_orchestrator_proposals', 'DELETE FROM open_space_orchestrator_proposals');
        $s('open_space_boards', 'DELETE FROM open_space_boards');

        // —— Chat contextuel agent ——
        $s('agent_context_chat_messages', 'DELETE FROM agent_context_chat_messages');
        $s('agent_context_conversations', 'DELETE FROM agent_context_conversations');

        // —— Beliefs / gouvernance contexte ——
        $s('strategic_context_belief_events', 'DELETE FROM strategic_context_belief_events');
        $s('strategic_context_belief_relations', 'DELETE FROM strategic_context_belief_relations');
        $s('strategic_context_belief_agent_positions', 'DELETE FROM strategic_context_belief_agent_positions');
        $s('strategic_context_beliefs', 'DELETE FROM strategic_context_beliefs');
        $s('strategic_context_memory_governance_events', 'DELETE FROM strategic_context_memory_governance_events');
        $s('strategic_context_snapshots', 'DELETE FROM strategic_context_snapshots');
        $s('strategic_context_memory_compilations', 'DELETE FROM strategic_context_memory_compilations');
        $s('strategic_context_narratives', 'DELETE FROM strategic_context_narratives');

        // —— Decision rooms (Palace) ——
        $s('decision_room_sessions', 'DELETE FROM decision_room_sessions');
        $s('decision_room_memories', 'DELETE FROM decision_room_memories');
        $s('decision_rooms', 'DELETE FROM decision_rooms');

        // —— Decision Memory ——
        $s('decision_memory_links', 'DELETE FROM decision_memory_links');
        $s('decision_memory_audit_events', 'DELETE FROM decision_memory_audit_events');
        $s('decision_memory_embeddings', 'DELETE FROM decision_memory_embeddings');
        $s('decision_memory_fts', 'DELETE FROM decision_memory_fts');
        $s('strategic_context_memories', 'DELETE FROM strategic_context_memories');
        $s('decision_memories', 'DELETE FROM decision_memories');

        $s('strategic_context_sessions', 'DELETE FROM strategic_context_sessions');

        // —— Social dynamics (par session) ——
        $s('relationship_events', 'DELETE FROM relationship_events');
        $s('agent_relationships', 'DELETE FROM agent_relationships');

        // —— Evidence / risk / jury / learning ——
        $s('evidence_claims', 'DELETE FROM evidence_claims');
        $s('evidence_reports', 'DELETE FROM evidence_reports');
        $s('session_risk_profiles', 'DELETE FROM session_risk_profiles');
        $s('jury_adversarial_reports', 'DELETE FROM jury_adversarial_reports');
        $s(
            'learning_insights_cache',
            "DELETE FROM learning_insights_cache WHERE scope_id IS NOT NULL AND TRIM(scope_id) != ''"
        );

        // —— Session artefacts ——
        $s('session_postmortems', 'DELETE FROM session_postmortems');
        $s('session_bias_reports', 'DELETE FROM session_bias_reports');
        $s('session_confidence_timeline', 'DELETE FROM session_confidence_timeline');
        $s('session_persona_scores', 'DELETE FROM session_persona_scores');
        $s('session_agent_providers', 'DELETE FROM session_agent_providers');
        $s('session_action_plans', 'DELETE FROM session_action_plans');
        $s('session_verdicts', 'DELETE FROM session_verdicts');
        $s('session_decisions', 'DELETE FROM session_decisions');
        $s('session_votes', 'DELETE FROM session_votes');
        $s('interaction_edges', 'DELETE FROM interaction_edges');
        $s('agent_positions', 'DELETE FROM agent_positions');
        $s('arguments', 'DELETE FROM arguments');
        $s('messages', 'DELETE FROM messages');
        $s('context_document_chunks', 'DELETE FROM context_document_chunks');
        $s('session_context_documents', 'DELETE FROM session_context_documents');
        $s('session_snapshots', 'DELETE FROM session_snapshots');
        $s('session_comparisons', 'DELETE FROM session_comparisons');

        $s('sessions', 'DELETE FROM sessions');

        $s('strategic_context_agents', 'DELETE FROM strategic_context_agents');
        $s('strategic_contexts', 'DELETE FROM strategic_contexts');

        // —— Logs liés aux sessions ——
        $s(
            'app_logs',
            "DELETE FROM app_logs WHERE session_id IS NOT NULL AND TRIM(session_id) != ''"
        );

        if ($this->includeDemoTemplates) {
            $s(
                'session_templates',
                "DELETE FROM session_templates WHERE id LIKE 'demo-%' OR id LIKE 'roadtrip%' OR id LIKE 'tpl-roadtrip%' OR LOWER(COALESCE(source, '')) = 'demo'"
            );
            $s(
                'scenario_packs',
                "DELETE FROM scenario_packs WHERE id LIKE 'demo-%' OR id LIKE 'roadtrip%' OR id LIKE 'tpl-roadtrip%'"
            );
        }
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    private function countForStep(array $step): int
    {
        $table = $step['table'];
        if (!$this->tableExists($table)) {
            return -1;
        }
        $sql = $step['sql'];
        if (preg_match('/^DELETE\s+FROM\s+([a-z0-9_]+)\s*(.*)$/is', $sql, $m)) {
            $where = trim($m[2] ?? '');
            $q = 'SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' ' . $where : '');
            return (int)$this->pdo->query($q)->fetchColumn();
        }
        return 0;
    }

    /** @return array{deleted:array<string,int>,skipped:array<string,string>,errors:array<string,string>} */
    public function run(): array
    {
        $deleted = [];
        $skipped = [];
        $errors = [];

        if ($this->dryRun) {
            echo "=== DRY-RUN (aucune écriture) ===\n\n";
            $total = 0;
            foreach ($this->steps as $step) {
                $n = $this->countForStep($step);
                if ($n < 0) {
                    $skipped[$step['table']] = 'table absente';
                    echo sprintf("[SKIP] %-40s table absente\n", $step['label']);
                    continue;
                }
                $total += $n;
                echo sprintf("[COUNT] %-40s %d ligne(s)\n", $step['label'], $n);
            }
            echo "\nTotal estimé : {$total} ligne(s)\n";
            return ['deleted' => [], 'skipped' => $skipped, 'errors' => $errors];
        }

        echo "=== SUPPRESSION (--yes) ===\n\n";
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        try {
            $this->pdo->beginTransaction();

            foreach ($this->steps as $step) {
                $table = $step['table'];
                if (!$this->tableExists($table)) {
                    $skipped[$table] = 'table absente';
                    echo sprintf("[SKIP] %-40s table absente\n", $step['label']);
                    continue;
                }
                try {
                    $stmt = $this->pdo->exec($step['sql']);
                    if ($stmt === false) {
                        $err = $this->pdo->errorInfo();
                        throw new \RuntimeException(($err[2] ?? 'exec failed'));
                    }
                    $deleted[$table] = $stmt;
                    echo sprintf("[OK]   %-40s %d ligne(s) supprimée(s)\n", $step['label'], $stmt);
                } catch (\Throwable $e) {
                    $errors[$table] = $e->getMessage();
                    echo sprintf("[FAIL] %-40s %s\n", $step['label'], $e->getMessage());
                    throw $e;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo "\nTransaction annulée : " . $e->getMessage() . "\n";
            return ['deleted' => $deleted, 'skipped' => $skipped, 'errors' => $errors];
        }

        $fkIssues = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);
        if ($fkIssues !== []) {
            echo "\n[WARN] PRAGMA foreign_key_check : " . count($fkIssues) . " problème(s)\n";
            foreach (array_slice($fkIssues, 0, 5) as $row) {
                echo '  - ' . json_encode($row) . "\n";
            }
        } else {
            echo "\n[OK] PRAGMA foreign_key_check : aucun problème\n";
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function verify(): void
    {
        echo "\n=== VÉRIFICATION POST-NETTOYAGE ===\n";
        $checks = [
            'sessions' => 'SELECT COUNT(*) FROM sessions',
            'strategic_contexts' => 'SELECT COUNT(*) FROM strategic_contexts',
            'decision_memories' => 'SELECT COUNT(*) FROM decision_memories',
            'providers' => 'SELECT COUNT(*) FROM providers',
            'demo_users' => 'SELECT COUNT(*) FROM demo_users',
            'demo_llm_usage' => 'SELECT COUNT(*) FROM demo_llm_usage',
            'persona_decision_dynamics' => 'SELECT COUNT(*) FROM persona_decision_dynamics',
        ];
        foreach ($checks as $label => $sql) {
            if (!$this->tableExists($label)) {
                echo "[SKIP] {$label} : table absente\n";
                continue;
            }
            $n = (int)$this->pdo->query($sql)->fetchColumn();
            echo sprintf("%-28s %d\n", $label . ':', $n);
        }
    }
}

function printHelp(): void
{
    echo <<<HELP
Decision Arena — nettoyage données métier démo

Usage:
  php backend/tools/clean_demo_db.php --dry-run
  php backend/tools/clean_demo_db.php --yes
  php backend/tools/clean_demo_db.php --yes --include-templates-demo

Options:
  --dry-run                 Compte les lignes sans supprimer
  --yes                     Exécute la suppression (transaction SQLite)
  --include-templates-demo  Supprime aussi session_templates / scenario_packs
                            dont l'id commence par demo- ou roadtrip ou tpl-roadtrip
  --help                    Affiche cette aide

Sans --dry-run ni --yes : aucune suppression (message d'aide).

Conservé : providers, provider_routing_settings, demo_users, demo_llm_usage,
personas (fichiers storage), prompts, config *.local.php

HELP;
}

// —— Bootstrap DB ——
(new Migration(Database::getInstance()))->run();

$argv = $argv ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$yes = in_array('--yes', $argv, true);
$includeTemplates = in_array('--include-templates-demo', $argv, true);

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    printHelp();
    exit(0);
}

if (!$dryRun && !$yes) {
    echo "Aucune action : utilisez --dry-run pour prévisualiser ou --yes pour supprimer.\n";
    echo "Aide : php backend/tools/clean_demo_db.php --help\n";
    exit(1);
}

$pdo = Database::getInstance()->pdo();
$cleaner = new CleanDemoDb($pdo, $dryRun, $includeTemplates);
$result = $cleaner->run();

if ($yes && $result['errors'] === []) {
    $cleaner->verify();
}

exit($result['errors'] !== [] ? 1 : 0);

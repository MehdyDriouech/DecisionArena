<?php
namespace Infrastructure\Persistence;

use Domain\DecisionReliability\ReliabilityConfig;

class Migration {
    private \PDO $pdo;

    public function __construct(Database $db) {
        $this->pdo = $db->pdo();
    }

    /**
     * Default log retention in days. Logs older than this are purged at boot.
     * Set to 0 or negative to disable automatic purge.
     */
    private const LOG_RETENTION_DAYS = 90;

    public function run(): void {
        $this->createSessionsTable();
        $this->createMessagesTable();
        $this->createArgumentsTable();
        $this->createAgentPositionsTable();
        $this->createInteractionEdgesTable();
        $this->createSessionVotesTable();
        $this->createSessionDecisionsTable();
        $this->createProvidersTable();
        $this->createProviderRoutingSettingsTable();
        $this->createAppLogsTable();
        $this->createSnapshotsTable();
        $this->createPersonaModeVisibilityTable();
        $this->addMissingColumns();
        $this->createSessionTemplatesTable();
        $this->createSessionVerdictsTable();
        $this->createContextDocumentsTable();
        $this->createContextDocumentChunksFts();
        $this->createActionPlansTable();
        $this->createSessionComparisonsTable();
        $this->createAppSettingsTable();
        $this->createScenarioPacksTable();
        // Deliberation Intelligence v2
        $this->createPersonaScoresTable();
        $this->createConfidenceTimelineTable();
        $this->createBiasReportsTable();
        $this->createPostmortemsTable();
        $this->createSessionAgentProvidersTable();
        $this->addMissingColumnsV2();
        $this->createSocialDynamicsTables();
        $this->createEvidenceTables();
        $this->extendEvidenceClaimsPhase3Columns();
        $this->createRiskProfileTable();
        $this->createLearningInsightsCacheTable();
        $this->createJuryAdversarialReportsTable();
        $this->seedDefaultTemplates();
        $this->seedStressTestTemplate();
        $this->seedDefaultScenarioPacks();
        $this->backfillContextDocumentChunks();
        $this->purgeOldLogs();
    }

    /**
     * Auto-purge logs older than LOG_RETENTION_DAYS, at most once per day.
     * Uses app_settings to track the last purge date so it does not run on
     * every request (which would cause a DELETE on every HTTP hit).
     */
    private function purgeOldLogs(): void {
        if (self::LOG_RETENTION_DAYS <= 0) return;
        try {
            // Skip if already purged within the last 24 hours
            $lastPurge = $this->getAppSetting('last_log_purge');
            if ($lastPurge !== null) {
                $lastPurgeAt = new \DateTimeImmutable($lastPurge);
                $hoursSince  = (new \DateTimeImmutable('now'))->getTimestamp() - $lastPurgeAt->getTimestamp();
                if ($hoursSince < 86400) return; // 24 h
            }

            $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='app_logs'")->fetchAll();
            if (empty($tables)) return;

            $cutoff = (new \DateTimeImmutable('now'))->modify('-' . self::LOG_RETENTION_DAYS . ' days')->format('c');
            $stmt = $this->pdo->prepare('DELETE FROM app_logs WHERE created_at < ?');
            $stmt->execute([$cutoff]);

            $this->setAppSetting('last_log_purge', (new \DateTimeImmutable('now'))->format('c'));
        } catch (\Throwable $e) {
            // Retention purge must never break the application
        }
    }

    private function createAppSettingsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");
    }

    private function getAppSetting(string $key): ?string {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (string)$row['value'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function setAppSetting(string $key, string $value): void {
        $now  = date('c');
        $stmt = $this->pdo->prepare('
            INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
        ');
        $stmt->execute([$key, $value, $now]);
    }

    private function createSessionsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                mode TEXT NOT NULL DEFAULT 'chat',
                initial_prompt TEXT DEFAULT '',
                selected_agents TEXT DEFAULT '[]',
                rounds INTEGER DEFAULT 2,
                language TEXT DEFAULT 'en',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");
    }

    private function createMessagesTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                agent_id TEXT NULL,
                provider_id TEXT NULL,
                model TEXT NULL,
                round INTEGER NULL,
                phase TEXT NULL,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createArgumentsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS arguments (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                round INTEGER NOT NULL,
                agent_id TEXT NOT NULL,
                argument_text TEXT NOT NULL,
                argument_type TEXT NOT NULL DEFAULT 'claim',
                target_argument_id TEXT NULL,
                strength INTEGER DEFAULT 1,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createAgentPositionsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS agent_positions (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                round INTEGER NOT NULL,
                agent_id TEXT NOT NULL,
                stance TEXT NOT NULL DEFAULT 'needs-more-info',
                confidence INTEGER NOT NULL DEFAULT 5,
                impact INTEGER NOT NULL DEFAULT 5,
                domain_weight INTEGER NOT NULL DEFAULT 5,
                weight_score REAL NOT NULL DEFAULT 5,
                main_argument TEXT NULL,
                biggest_risk TEXT NULL,
                change_since_last_round TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createInteractionEdgesTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS interaction_edges (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                round INTEGER NOT NULL,
                source_agent_id TEXT NOT NULL,
                target_agent_id TEXT NOT NULL,
                edge_type TEXT NOT NULL DEFAULT 'neutral',
                weight INTEGER NOT NULL DEFAULT 1,
                argument_id TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createSessionVotesTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_votes (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                round INTEGER NULL,
                agent_id TEXT NOT NULL,
                vote TEXT NOT NULL,
                confidence INTEGER NOT NULL,
                impact INTEGER NOT NULL,
                domain_weight INTEGER NOT NULL,
                weight_score REAL NOT NULL,
                rationale TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createSessionDecisionsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_decisions (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                decision_label TEXT NOT NULL,
                decision_score REAL NOT NULL,
                confidence_level TEXT NOT NULL,
                threshold_used REAL NOT NULL,
                vote_summary TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createProvidersTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS providers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                base_url TEXT DEFAULT '',
                api_key TEXT DEFAULT '',
                default_model TEXT DEFAULT '',
                enabled INTEGER DEFAULT 1,
                priority INTEGER DEFAULT 100,
                is_local INTEGER DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");
    }

    private function createProviderRoutingSettingsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS provider_routing_settings (
                id TEXT PRIMARY KEY,
                routing_mode TEXT NOT NULL DEFAULT 'single-primary',
                primary_provider_id TEXT NULL,
                preferred_provider_id TEXT NULL,
                fallback_provider_ids TEXT NULL,
                load_balance_strategy TEXT DEFAULT 'round-robin',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");

        // Seed default row if missing
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM provider_routing_settings WHERE id = ?');
            $stmt->execute(['default']);
            $exists = (int)$stmt->fetchColumn() > 0;
            if (!$exists) {
                $now = date('c');
                $this->pdo->prepare("
                    INSERT INTO provider_routing_settings
                        (id, routing_mode, primary_provider_id, preferred_provider_id, fallback_provider_ids, load_balance_strategy, created_at, updated_at)
                    VALUES
                        (:id, :routing_mode, :primary_provider_id, :preferred_provider_id, :fallback_provider_ids, :load_balance_strategy, :created_at, :updated_at)
                ")->execute([
                    ':id' => 'default',
                    ':routing_mode' => 'single-primary',
                    ':primary_provider_id' => null,
                    ':preferred_provider_id' => null,
                    ':fallback_provider_ids' => json_encode([]),
                    ':load_balance_strategy' => 'round-robin',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // best-effort seed
        }
    }

    private function createAppLogsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS app_logs (
                id TEXT PRIMARY KEY,
                level TEXT NOT NULL,
                category TEXT NOT NULL,
                session_id TEXT NULL,
                message_id TEXT NULL,
                provider_id TEXT NULL,
                model TEXT NULL,
                agent_id TEXT NULL,
                action TEXT NULL,
                request_payload TEXT NULL,
                response_payload TEXT NULL,
                metadata TEXT NULL,
                error_message TEXT NULL,
                created_at TEXT NOT NULL
            )
        ");

        // Indexes (best-effort, SQLite doesn't support IF NOT EXISTS on older versions consistently)
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_app_logs_created_at ON app_logs(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_app_logs_category ON app_logs(category)',
            'CREATE INDEX IF NOT EXISTS idx_app_logs_session_id ON app_logs(session_id)',
            'CREATE INDEX IF NOT EXISTS idx_app_logs_provider_id ON app_logs(provider_id)',
            'CREATE INDEX IF NOT EXISTS idx_app_logs_agent_id ON app_logs(agent_id)',
            'CREATE INDEX IF NOT EXISTS idx_app_logs_level ON app_logs(level)',
        ];
        foreach ($indexes as $sql) {
            try { $this->pdo->exec($sql); } catch (\Throwable $e) {}
        }
    }

    private function createSnapshotsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_snapshots (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                title TEXT NOT NULL,
                content_markdown TEXT NOT NULL,
                content_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createPersonaModeVisibilityTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS persona_mode_visibility (
                persona_id TEXT NOT NULL,
                mode TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (persona_id, mode)
            )
        ");
    }

    private function addMissingColumns(): void {
        $this->addColumnIfMissing('sessions', 'language', "TEXT DEFAULT 'en'");
        $this->addColumnIfMissing('sessions', 'status', "TEXT DEFAULT 'draft'");
        $this->addColumnIfMissing('sessions', 'cf_rounds', 'INTEGER DEFAULT 3');
        $this->addColumnIfMissing('sessions', 'cf_interaction_style', "TEXT DEFAULT 'sequential'");
        $this->addColumnIfMissing('sessions', 'cf_reply_policy', "TEXT DEFAULT 'all-agents-reply'");
        $this->addColumnIfMissing('sessions', 'is_favorite', 'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('sessions', 'is_reference', 'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('sessions', 'decision_taken', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'user_learnings', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'follow_up_notes', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'force_disagreement', 'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('sessions', 'parent_session_id', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'rerun_reason', 'TEXT NULL');
        $this->addColumnIfMissing('messages', 'phase', 'TEXT NULL');
        $this->addColumnIfMissing('messages', 'target_agent_id', 'TEXT NULL');
        $this->addColumnIfMissing('messages', 'mode_context', 'TEXT NULL');
        $this->addColumnIfMissing('messages', 'message_type', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'decision_threshold', 'REAL DEFAULT ' . ReliabilityConfig::DEFAULT_DECISION_THRESHOLD);
        $this->addColumnIfMissing('sessions', 'context_quality_score', 'REAL DEFAULT NULL');
        $this->addColumnIfMissing('sessions', 'context_quality_level', 'TEXT DEFAULT NULL');
        $this->addColumnIfMissing('sessions', 'context_quality_report', 'TEXT DEFAULT NULL');
        $this->addColumnIfMissing('sessions', 'reliability_cap', 'REAL DEFAULT NULL');
        $this->addColumnIfMissing('sessions', 'result', 'TEXT DEFAULT NULL');
        $this->addColumnIfMissing('sessions', 'decision_brief', 'TEXT DEFAULT NULL');

        // Providers (routing + ordering)
        $this->addColumnIfMissing('providers', 'priority', 'INTEGER DEFAULT 100');
        $this->addColumnIfMissing('providers', 'is_local', 'INTEGER DEFAULT 0');
    }

    private function createSessionTemplatesTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_templates (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT NULL,
                mode TEXT NOT NULL DEFAULT 'decision-room',
                selected_agents TEXT DEFAULT '[]',
                rounds INTEGER DEFAULT 2,
                force_disagreement INTEGER DEFAULT 0,
                interaction_style TEXT NULL,
                reply_policy TEXT NULL,
                final_synthesis INTEGER DEFAULT 1,
                prompt_starter TEXT NULL,
                expected_output TEXT NULL,
                notes TEXT NULL,
                source TEXT DEFAULT 'custom',
                enabled INTEGER DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");
    }

    private function createSessionVerdictsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_verdicts (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                verdict_label TEXT NOT NULL,
                verdict_summary TEXT NOT NULL,
                feasibility_score INTEGER NULL,
                product_value_score INTEGER NULL,
                ux_score INTEGER NULL,
                risk_score INTEGER NULL,
                confidence_score INTEGER NULL,
                recommended_action TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function seedDefaultTemplates(): void {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM session_templates');
        if ($stmt->fetchColumn() > 0) {
            return;
        }

        $now = date('c');
        $templates = [
            [
                'id'                => 'product-launch',
                'name'              => 'Product Launch Challenge',
                'description'       => 'Challenge a product launch with a multi-agent confrontation',
                'mode'              => 'confrontation',
                'selected_agents'   => json_encode(['analyst','pm','ux-expert','critic','synthesizer']),
                'rounds'            => 3,
                'force_disagreement'=> 1,
                'interaction_style' => 'agent-to-agent',
                'reply_policy'      => 'all-agents-reply',
                'final_synthesis'   => 1,
                'prompt_starter'    => 'I want to launch a new product. Challenge the value proposition, target users, risks, go-to-market and MVP scope.',
                'expected_output'   => null,
                'notes'             => null,
                'source'            => 'system',
                'enabled'           => 1,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'id'                => 'technical-architecture-review',
                'name'              => 'Technical Architecture Review',
                'description'       => 'Structured review of a technical architecture decision',
                'mode'              => 'decision-room',
                'selected_agents'   => json_encode(['architect','po','critic','synthesizer']),
                'rounds'            => 2,
                'force_disagreement'=> 1,
                'interaction_style' => null,
                'reply_policy'      => null,
                'final_synthesis'   => 1,
                'prompt_starter'    => 'Review this technical architecture decision. Challenge feasibility, complexity, maintainability, risks and alternatives.',
                'expected_output'   => null,
                'notes'             => null,
                'source'            => 'system',
                'enabled'           => 1,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'id'                => 'startup-idea-validation',
                'name'              => 'Startup Idea Validation',
                'description'       => 'Quick validation of a startup idea',
                'mode'              => 'quick-decision',
                'selected_agents'   => json_encode(['analyst','pm','critic','synthesizer']),
                'rounds'            => 1,
                'force_disagreement'=> 1,
                'interaction_style' => null,
                'reply_policy'      => null,
                'final_synthesis'   => 1,
                'prompt_starter'    => 'Validate this startup idea. Challenge the problem, market, willingness to pay, MVP and risks.',
                'expected_output'   => null,
                'notes'             => null,
                'source'            => 'system',
                'enabled'           => 1,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [
                'id'                => 'ux-flow-review',
                'name'              => 'UX Flow Review',
                'description'       => 'Review a user journey or UX flow',
                'mode'              => 'decision-room',
                'selected_agents'   => json_encode(['ux-expert','pm','critic','synthesizer']),
                'rounds'            => 2,
                'force_disagreement'=> 0,
                'interaction_style' => null,
                'reply_policy'      => null,
                'final_synthesis'   => 1,
                'prompt_starter'    => 'Review this user journey. Challenge clarity, friction, user motivation and accessibility.',
                'expected_output'   => null,
                'notes'             => null,
                'source'            => 'system',
                'enabled'           => 1,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
        ];

        $sql = "
            INSERT INTO session_templates
                (id, name, description, mode, selected_agents, rounds, force_disagreement,
                 interaction_style, reply_policy, final_synthesis, prompt_starter,
                 expected_output, notes, source, enabled, created_at, updated_at)
            VALUES
                (:id, :name, :description, :mode, :selected_agents, :rounds, :force_disagreement,
                 :interaction_style, :reply_policy, :final_synthesis, :prompt_starter,
                 :expected_output, :notes, :source, :enabled, :created_at, :updated_at)
        ";
        $stmt = $this->pdo->prepare($sql);
        foreach ($templates as $tpl) {
            $stmt->execute([
                ':id'                => $tpl['id'],
                ':name'              => $tpl['name'],
                ':description'       => $tpl['description'],
                ':mode'              => $tpl['mode'],
                ':selected_agents'   => $tpl['selected_agents'],
                ':rounds'            => $tpl['rounds'],
                ':force_disagreement'=> $tpl['force_disagreement'],
                ':interaction_style' => $tpl['interaction_style'],
                ':reply_policy'      => $tpl['reply_policy'],
                ':final_synthesis'   => $tpl['final_synthesis'],
                ':prompt_starter'    => $tpl['prompt_starter'],
                ':expected_output'   => $tpl['expected_output'],
                ':notes'             => $tpl['notes'],
                ':source'            => $tpl['source'],
                ':enabled'           => $tpl['enabled'],
                ':created_at'        => $tpl['created_at'],
                ':updated_at'        => $tpl['updated_at'],
            ]);
        }
    }

    private function createContextDocumentsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_context_documents (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                title TEXT NULL,
                source_type TEXT NOT NULL DEFAULT 'manual',
                original_filename TEXT NULL,
                mime_type TEXT NULL,
                content TEXT NOT NULL,
                character_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createContextDocumentChunksFts(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS context_document_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                chunk_index INTEGER NOT NULL,
                start_offset INTEGER NOT NULL,
                end_offset INTEGER NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_context_doc_chunks_session ON context_document_chunks(session_id)');

        $this->pdo->exec('
            CREATE VIRTUAL TABLE IF NOT EXISTS context_document_chunks_fts USING fts5(
                content,
                content=\'context_document_chunks\',
                content_rowid=\'id\'
            )
        ');

        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS context_document_chunks_ai AFTER INSERT ON context_document_chunks BEGIN
                INSERT INTO context_document_chunks_fts(rowid, content) VALUES (new.id, new.content);
            END
        ");
        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS context_document_chunks_ad AFTER DELETE ON context_document_chunks BEGIN
                INSERT INTO context_document_chunks_fts(context_document_chunks_fts, rowid, content) VALUES('delete', old.id, old.content);
            END
        ");
        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS context_document_chunks_au AFTER UPDATE ON context_document_chunks BEGIN
                INSERT INTO context_document_chunks_fts(context_document_chunks_fts, rowid, content) VALUES('delete', old.id, old.content);
                INSERT INTO context_document_chunks_fts(rowid, content) VALUES (new.id, new.content);
            END
        ");
    }

    private function backfillContextDocumentChunks(): void
    {
        try {
            $check = $this->pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name='context_document_chunks'"
            );
            if (!$check || !$check->fetch()) {
                return;
            }
            $stmt = $this->pdo->query('SELECT session_id, content FROM session_context_documents');
            if (!$stmt) {
                return;
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                return;
            }
            $repo = new ContextDocumentChunkRepository();
            foreach ($rows as $row) {
                $sid = (string)($row['session_id'] ?? '');
                $content = (string)($row['content'] ?? '');
                if ($sid === '' || $content === '') {
                    continue;
                }
                $repo->reindexSession($sid, $content);
            }
        } catch (\Throwable) {
            // Non-fatal: FTS/chunk layer must not break startup
        }
    }

    private function createActionPlansTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_action_plans (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                source_message_id TEXT NULL,
                summary TEXT,
                immediate_actions TEXT,
                short_term_actions TEXT,
                experiments TEXT,
                risks_to_monitor TEXT,
                owner_notes TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
    }

    private function createSessionComparisonsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_comparisons (
                id TEXT PRIMARY KEY,
                title TEXT,
                session_ids TEXT NOT NULL,
                content_markdown TEXT NOT NULL,
                content_json TEXT NULL,
                created_at TEXT NOT NULL
            )
        ");
    }

    private function seedStressTestTemplate(): void {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM session_templates WHERE id = ?');
        $stmt->execute(['stress-test-product-idea']);
        if ($stmt->fetchColumn() > 0) {
            return;
        }
        $now = date('c');
        $this->pdo->prepare("
            INSERT INTO session_templates
                (id, name, description, mode, selected_agents, rounds, force_disagreement,
                 interaction_style, reply_policy, final_synthesis, prompt_starter,
                 expected_output, notes, source, enabled, created_at, updated_at)
            VALUES
                (:id, :name, :description, :mode, :selected_agents, :rounds, :force_disagreement,
                 :interaction_style, :reply_policy, :final_synthesis, :prompt_starter,
                 :expected_output, :notes, :source, :enabled, :created_at, :updated_at)
        ")->execute([
            ':id'                => 'stress-test-product-idea',
            ':name'              => 'Stress Test Product Idea',
            ':description'       => 'Identify how a product idea could fail and what to test before investing',
            ':mode'              => 'stress-test',
            ':selected_agents'   => json_encode(['critic', 'pm', 'architect', 'ux-expert', 'synthesizer']),
            ':rounds'            => 2,
            ':force_disagreement'=> 1,
            ':interaction_style' => null,
            ':reply_policy'      => null,
            ':final_synthesis'   => 1,
            ':prompt_starter'    => 'Stress test this idea. Identify how it could fail, what assumptions are weakest, and what should be tested before investing more.',
            ':expected_output'   => 'Stress Test Report with failure modes, risks, mitigations, kill criteria and final verdict.',
            ':notes'             => null,
            ':source'            => 'system',
            ':enabled'           => 1,
            ':created_at'        => $now,
            ':updated_at'        => $now,
        ]);
    }

    private function createScenarioPacksTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scenario_persona_packs (
                id                 TEXT PRIMARY KEY,
                name               TEXT NOT NULL,
                description        TEXT,
                target_profile     TEXT,
                scenario_type      TEXT,
                recommended_mode   TEXT NOT NULL,
                persona_ids        TEXT NOT NULL DEFAULT '[]',
                rounds             INTEGER DEFAULT 2,
                force_disagreement INTEGER DEFAULT 0,
                decision_threshold REAL DEFAULT " . ReliabilityConfig::DEFAULT_DECISION_THRESHOLD . ",
                prompt_starter     TEXT,
                max_personas       INTEGER NULL,
                enabled            INTEGER DEFAULT 1,
                source             TEXT DEFAULT 'system',
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )
        ");
    }

    private function seedDefaultScenarioPacks(): void {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scenario_persona_packs');
        if ((int)$stmt->fetchColumn() > 0) return;

        $now = date('c');
        $packs = [
            [
                'id'                 => 'po-grooming-squad',
                'name'               => 'PO — Grooming Squad',
                'description'        => 'Structure and prioritise your backlog with a multi-expert panel.',
                'target_profile'     => 'Product Owner',
                'scenario_type'      => 'backlog',
                'recommended_mode'   => 'decision-room',
                'persona_ids'        => json_encode(['dev','qa','architect','ux-expert','critic','synthesizer']),
                'rounds'             => 2,
                'force_disagreement' => 1,
                'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
                'prompt_starter'     => 'Analyse and prioritise this backlog item or user story. Challenge feasibility, value, edge cases and effort.',
                'max_personas'       => null,
            ],
            [
                'id'                 => 'ux-user-testing-panel',
                'name'               => 'UX — User Testing Panel',
                'description'        => 'Confront two perspectives on a UX flow or interface before user testing.',
                'target_profile'     => 'UX Designer / Product Manager',
                'scenario_type'      => 'ux',
                'recommended_mode'   => 'confrontation',
                'persona_ids'        => json_encode(['ux-expert','pm','analyst','critic','synthesizer']),
                'rounds'             => 3,
                'force_disagreement' => 0,
                'decision_threshold' => 0.60,
                'prompt_starter'     => 'Review this UX flow or interface. Challenge usability, clarity, user motivation and accessibility from both a defensive and critical angle.',
                'max_personas'       => null,
            ],
            [
                'id'                 => 'ceo-product-launch',
                'name'               => 'CEO — Product Launch',
                'description'        => 'Simulate a board-level committee vote on a product launch decision.',
                'target_profile'     => 'CEO / Founder',
                'scenario_type'      => 'launch',
                'recommended_mode'   => 'jury',
                'persona_ids'        => json_encode(['pm','analyst','critic','architect','ux-expert','synthesizer']),
                'rounds'             => 3,
                'force_disagreement' => 0,
                'decision_threshold' => 0.60,
                'prompt_starter'     => 'Evaluate whether we should launch this product now. Consider market readiness, competitive positioning, risks and strategic alignment.',
                'max_personas'       => 50,
            ],
            [
                'id'                 => 'tech-lead-architecture-board',
                'name'               => 'Tech Lead — Architecture Board',
                'description'        => 'Stress-test a technical architecture decision before implementation.',
                'target_profile'     => 'Tech Lead / Architect',
                'scenario_type'      => 'architecture',
                'recommended_mode'   => 'stress-test',
                'persona_ids'        => json_encode(['architect','dev','qa','critic','synthesizer']),
                'rounds'             => 2,
                'force_disagreement' => 1,
                'decision_threshold' => 0.65,
                'prompt_starter'     => 'Stress-test this technical architecture. Identify failure modes, scalability limits, hidden complexity and what assumptions must be validated first.',
                'max_personas'       => null,
            ],
            [
                'id'                 => 'marketing-market-reaction',
                'name'               => 'Marketing — Market Reaction',
                'description'        => 'Simulate market and audience reactions to a campaign or product positioning.',
                'target_profile'     => 'Marketing / Growth',
                'scenario_type'      => 'marketing',
                'recommended_mode'   => 'confrontation',
                'persona_ids'        => json_encode(['analyst','pm','ux-expert','critic']),
                'rounds'             => 3,
                'force_disagreement' => 0,
                'decision_threshold' => ReliabilityConfig::DEFAULT_DECISION_THRESHOLD,
                'prompt_starter'     => 'Simulate market reaction to this campaign or launch message. Challenge positioning, target audience fit and potential backfires.',
                'max_personas'       => null,
            ],
        ];

        $sql  = "INSERT INTO scenario_persona_packs
                    (id, name, description, target_profile, scenario_type, recommended_mode,
                     persona_ids, rounds, force_disagreement, decision_threshold,
                     prompt_starter, max_personas, enabled, source, created_at, updated_at)
                 VALUES
                    (:id, :name, :description, :target_profile, :scenario_type, :recommended_mode,
                     :persona_ids, :rounds, :force_disagreement, :decision_threshold,
                     :prompt_starter, :max_personas, 1, 'system', :created_at, :updated_at)";
        $stmt = $this->pdo->prepare($sql);
        foreach ($packs as $p) {
            $stmt->execute([
                ':id'                 => $p['id'],
                ':name'               => $p['name'],
                ':description'        => $p['description'],
                ':target_profile'     => $p['target_profile'],
                ':scenario_type'      => $p['scenario_type'],
                ':recommended_mode'   => $p['recommended_mode'],
                ':persona_ids'        => $p['persona_ids'],
                ':rounds'             => $p['rounds'],
                ':force_disagreement' => $p['force_disagreement'],
                ':decision_threshold' => $p['decision_threshold'],
                ':prompt_starter'     => $p['prompt_starter'],
                ':max_personas'       => $p['max_personas'],
                ':created_at'         => $now,
                ':updated_at'         => $now,
            ]);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
       Deliberation Intelligence v2 — new tables
    ═══════════════════════════════════════════════════════════════════ */

    private function createPersonaScoresTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_persona_scores (
                id              TEXT PRIMARY KEY,
                session_id      TEXT NOT NULL,
                agent_id        TEXT NOT NULL,
                message_count   INTEGER NOT NULL DEFAULT 0,
                avg_message_length REAL NOT NULL DEFAULT 0,
                citation_count  INTEGER NOT NULL DEFAULT 0,
                influence_score REAL NOT NULL DEFAULT 0,
                dominance       TEXT NOT NULL DEFAULT 'passive',
                computed_at     TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_persona_scores_session ON session_persona_scores(session_id)');
        } catch (\Throwable $e) {}
    }

    private function createConfidenceTimelineTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_confidence_timeline (
                id                  TEXT PRIMARY KEY,
                session_id          TEXT NOT NULL,
                round               INTEGER NOT NULL,
                confidence          REAL NOT NULL DEFAULT 0,
                dominant_position   TEXT NOT NULL DEFAULT 'ITERATE',
                consensus_forming   INTEGER NOT NULL DEFAULT 0,
                computed_at         TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_confidence_timeline_session ON session_confidence_timeline(session_id)');
        } catch (\Throwable $e) {}
    }

    private function createBiasReportsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_bias_reports (
                id              TEXT PRIMARY KEY,
                session_id      TEXT NOT NULL,
                bias_report_json TEXT NOT NULL,
                computed_at     TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_bias_reports_session ON session_bias_reports(session_id)');
        } catch (\Throwable $e) {}
    }

    private function createPostmortemsTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_postmortems (
                id                      TEXT PRIMARY KEY,
                session_id              TEXT NOT NULL UNIQUE,
                outcome                 TEXT NOT NULL,
                confidence_in_retrospect REAL NOT NULL DEFAULT 0.5,
                notes                   TEXT NULL,
                created_at              TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_postmortems_session ON session_postmortems(session_id)');
        } catch (\Throwable $e) {}
    }

    private function createSessionAgentProvidersTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_agent_providers (
                id          TEXT PRIMARY KEY,
                session_id  TEXT NOT NULL,
                agent_id    TEXT NOT NULL,
                provider_id TEXT NOT NULL,
                model       TEXT NULL,
                UNIQUE (session_id, agent_id),
                FOREIGN KEY (session_id) REFERENCES sessions(id)
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sap_session ON session_agent_providers(session_id)');
        } catch (\Throwable $e) {}
    }

    private function createSocialDynamicsTables(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS agent_relationships (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL,
              source_agent_id TEXT NOT NULL,
              target_agent_id TEXT NOT NULL,
              affinity REAL DEFAULT 0,
              trust REAL DEFAULT 0.5,
              conflict REAL DEFAULT 0,
              support_count INTEGER DEFAULT 0,
              challenge_count INTEGER DEFAULT 0,
              alliance_count INTEGER DEFAULT 0,
              attack_count INTEGER DEFAULT 0,
              last_interaction_type TEXT,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL,
              UNIQUE(session_id, source_agent_id, target_agent_id)
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS relationship_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL,
              round_index INTEGER,
              source_agent_id TEXT NOT NULL,
              target_agent_id TEXT,
              event_type TEXT NOT NULL,
              intensity REAL DEFAULT 0.5,
              evidence TEXT,
              created_at TEXT NOT NULL
            )
        ");
        try {
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_agent_rel_sessions ON agent_relationships(session_id)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rel_events_session ON relationship_events(session_id)');
        } catch (\Throwable $e) {
        }
    }

    private function addMissingColumnsV2(): void {
        // Devil's Advocate session config
        $this->addColumnIfMissing('sessions', 'devil_advocate_enabled',   'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('sessions', 'devil_advocate_threshold', 'REAL DEFAULT 0.65');
        // Message type for devil advocate
        $this->addColumnIfMissing('messages', 'is_devil_advocate', 'INTEGER DEFAULT 0');
        // LLM metadata — provider name, requested vs used, fallback tracking
        $this->addColumnIfMissing('messages', 'provider_name',           'TEXT NULL');
        $this->addColumnIfMissing('messages', 'requested_provider_id',   'TEXT NULL');
        $this->addColumnIfMissing('messages', 'requested_model',         'TEXT NULL');
        $this->addColumnIfMissing('messages', 'provider_fallback_used',  'INTEGER DEFAULT 0');
        $this->addColumnIfMissing('messages', 'provider_fallback_reason','TEXT NULL');
        // Session team agent assignments (for Confrontation LLM assignment)
        $this->addColumnIfMissing('sessions', 'blue_team_agents', 'TEXT NULL');
        $this->addColumnIfMissing('sessions', 'red_team_agents',  'TEXT NULL');
        // Reactive Chat thread metadata
        $this->addColumnIfMissing('messages', 'thread_type',        'TEXT NULL');
        $this->addColumnIfMissing('messages', 'thread_turn',        'INTEGER NULL');
        $this->addColumnIfMissing('messages', 'reaction_role',      'TEXT NULL');
        $this->addColumnIfMissing('messages', 'reactive_thread_id', 'TEXT NULL');
        // Human-in-the-loop v2 — trace challenges without new tables
        $this->addColumnIfMissing('messages', 'meta_json', 'TEXT NULL');
    }

    private function createEvidenceTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS evidence_claims (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL,
              message_id TEXT,
              agent_id TEXT,
              claim_text TEXT NOT NULL,
              claim_type TEXT NOT NULL,
              status TEXT DEFAULT 'unsupported',
              confidence REAL DEFAULT 0.5,
              evidence_text TEXT,
              source_reference TEXT,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            )
        ");
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_evidence_claims_session
            ON evidence_claims(session_id)
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS evidence_reports (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL UNIQUE,
              report_json TEXT NOT NULL,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            )
        ");
    }

    /** Phase 3 — claim support taxonomy (nullable for backward compatibility). */
    private function extendEvidenceClaimsPhase3Columns(): void {
        $this->addColumnIfMissing('evidence_claims', 'support_class', "TEXT DEFAULT 'not_applicable'");
        $this->addColumnIfMissing('evidence_claims', 'importance', "TEXT DEFAULT 'medium'");
        $this->addColumnIfMissing('evidence_claims', 'linked_chunk_ids', 'TEXT NULL');
        $this->addColumnIfMissing('evidence_claims', 'source_layer', "TEXT DEFAULT 'none'");
        $this->addColumnIfMissing('evidence_claims', 'challenge_flag', 'INTEGER DEFAULT 0');
    }

    private function createRiskProfileTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS session_risk_profiles (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL UNIQUE,
              risk_level TEXT NOT NULL,
              reversibility TEXT NOT NULL,
              risk_categories_json TEXT,
              estimated_error_cost TEXT,
              recommended_threshold REAL,
              required_process TEXT,
              report_json TEXT NOT NULL,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            )
        ");
    }

    private function createLearningInsightsCacheTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS learning_insights_cache (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              scope TEXT NOT NULL,
              scope_id TEXT,
              report_json TEXT NOT NULL,
              computed_at TEXT NOT NULL,
              UNIQUE(scope, scope_id)
            )
        ");
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void {
        try {
            $stmt    = $this->pdo->query("PRAGMA table_info($table)");
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
            if (!in_array($column, $columns)) {
                $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        } catch (\Throwable $e) {
            // Column already exists in another form — ignore
        }
    }

    private function createJuryAdversarialReportsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS jury_adversarial_reports (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              session_id TEXT NOT NULL UNIQUE,
              enabled INTEGER DEFAULT 0,
              debate_quality_score REAL,
              challenge_count INTEGER DEFAULT 0,
              challenge_ratio REAL,
              position_changes INTEGER DEFAULT 0,
              position_changers_json TEXT,
              minority_report_present INTEGER DEFAULT 0,
              interaction_density REAL,
              most_challenged_agent TEXT,
              warnings_json TEXT,
              compliance_retries INTEGER DEFAULT 0,
              planned_rounds INTEGER,
              executed_rounds INTEGER,
              report_json TEXT,
              created_at TEXT NOT NULL,
              updated_at TEXT NOT NULL
            )
        ");
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_jury_adversarial_session
            ON jury_adversarial_reports(session_id)
        ");
    }
}

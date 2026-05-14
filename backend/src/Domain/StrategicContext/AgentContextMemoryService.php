<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\DecisionMemory\DecisionMemoryPersistenceClassifier;
use Domain\CognitiveGovernance\RuntimeFilesystemGuard;
use Domain\Sessions\SessionAgentResolver;

/**
 * Mémoire markdown par couple (strategic_context_id, agent_id) — confinement filesystem strict.
 * Pas d’LLM, pas d’embeddings.
 */
final class AgentContextMemoryService
{
    public const MAX_PROMPT_INJECT_CHARS = 3500;

    /** Ordre canonique produit (sections manquantes ajoutées en fin de fichier si besoin). */
    private const CANONICAL_SECTIONS_TO_ENSURE = [
        '## Stable Beliefs',
        '## Strategic Assumptions',
        '## Decisions Remembered',
        '## Failed Predictions',
        '## Relationships',
        '## User Preferences',
        '## Open Questions',
        '## Recent Notes',
        '## Contradictions To Review',
        '## Deprecated / Forgotten',
        '## Current Position',
        '## Pending Consolidation Notes',
    ];

    private const AGENT_ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/i';

    private const MEMORY_TEMPLATE = <<<'MD'
# Agent Context Memory

## Stable Beliefs

No stable belief extracted yet.

## Strategic Assumptions

No strategic assumption extracted yet.

## Decisions Remembered

No confirmed decision memory synced yet.

## Failed Predictions

No failed prediction recorded yet.

## Relationships

No relationship signal extracted yet.

## User Preferences

No explicit user preference extracted yet.

## Open Questions

No open question extracted yet.

## Recent Notes



## Contradictions To Review

No contradiction recorded yet.

## Deprecated / Forgotten

No deprecated memory recorded yet.

## Current Position

No current position extracted yet.

## Pending Consolidation Notes

No pending consolidation note.

MD;

    private string $storageRoot;

    public function __construct()
    {
        $this->storageRoot = dirname(__DIR__, 3) . '/storage/strategic-contexts';
    }

    public function isValidContextUuid(string $id): bool
    {
        $id = trim($id);
        return $id !== '' && (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public function isValidAgentId(string $agentId): bool
    {
        $agentId = trim($agentId);
        return $agentId !== '' && (bool)preg_match(self::AGENT_ID_PATTERN, $agentId);
    }

    /**
     * @return array{ok:bool,path:?string,error?:string}
     */
    private function resolveMemoryFile(string $contextUuid, string $agentId): array
    {
        if (!$this->isValidContextUuid($contextUuid)) {
            return ['ok' => false, 'path' => null, 'error' => 'invalid_context_id'];
        }
        if (!$this->isValidAgentId($agentId)) {
            return ['ok' => false, 'path' => null, 'error' => 'invalid_agent_id'];
        }
        $base = realpath($this->storageRoot);
        if ($base === false) {
            RuntimeFilesystemGuard::inspect('mkdir', $this->storageRoot, [
                'source' => 'AgentContextMemoryService::resolveMemoryFile',
                'strategic_context_id' => strtolower($contextUuid),
                'agent_id' => strtolower($agentId),
            ]);
            if (!@mkdir($this->storageRoot, 0755, true)) {
                return ['ok' => false, 'path' => null, 'error' => 'storage_unavailable'];
            }
            $base = realpath($this->storageRoot);
            if ($base === false) {
                return ['ok' => false, 'path' => null, 'error' => 'storage_unavailable'];
            }
        }
        $ctxLower = strtolower($contextUuid);
        $dir = $base . DIRECTORY_SEPARATOR . $ctxLower . DIRECTORY_SEPARATOR . 'agents' . DIRECTORY_SEPARATOR . strtolower($agentId);
        if (!is_dir($dir)) {
            RuntimeFilesystemGuard::inspect('mkdir', $dir, [
                'source' => 'AgentContextMemoryService::resolveMemoryFile',
                'strategic_context_id' => $ctxLower,
                'agent_id' => strtolower($agentId),
            ]);
            if (!@mkdir($dir, 0755, true)) {
                return ['ok' => false, 'path' => null, 'error' => 'mkdir_failed'];
            }
        }
        $realDir = realpath($dir);
        if ($realDir === false || !str_starts_with($realDir, $base)) {
            return ['ok' => false, 'path' => null, 'error' => 'path_traversal'];
        }
        $file = $realDir . DIRECTORY_SEPARATOR . 'memory.md';
        if (!str_starts_with($file, $realDir)) {
            return ['ok' => false, 'path' => null, 'error' => 'path_traversal'];
        }
        return ['ok' => true, 'path' => $file];
    }

    public function ensureFile(string $contextUuid, string $agentId): void
    {
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return;
        }
        if (!is_file($r['path'])) {
            $this->atomicWrite($r['path'], self::MEMORY_TEMPLATE);
        }
    }

    public function read(string $contextUuid, string $agentId): string
    {
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return '';
        }
        $this->ensureFile($contextUuid, $agentId);
        $c = @file_get_contents($r['path']);
        return is_string($c) ? $c : '';
    }

    /**
     * Lecture read-only : aucun mkdir, aucun fichier créé (comparaisons / audits).
     *
     * @return array{exists:bool,content:string}
     */
    public function readIfExistsNoSideEffects(string $contextUuid, string $agentId): array
    {
        if (!$this->isValidContextUuid($contextUuid) || !$this->isValidAgentId($agentId)) {
            return ['exists' => false, 'content' => ''];
        }
        $base = realpath($this->storageRoot);
        if ($base === false) {
            return ['exists' => false, 'content' => ''];
        }
        $path = $base . DIRECTORY_SEPARATOR . strtolower($contextUuid)
            . DIRECTORY_SEPARATOR . 'agents' . DIRECTORY_SEPARATOR . strtolower($agentId)
            . DIRECTORY_SEPARATOR . 'memory.md';
        if (!is_file($path)) {
            return ['exists' => false, 'content' => ''];
        }
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $base)) {
            return ['exists' => false, 'content' => ''];
        }
        $c = @file_get_contents($real);
        return ['exists' => true, 'content' => is_string($c) ? $c : ''];
    }

    /**
     * Résumé d’état memory.md pour l’UI OpenSpace / diagnostics (lecture seule sur disque).
     *
     * @param list<string> $linkedMemoryIds
     * @return array{state:string,template_only:bool,participation_sync:bool,decision_memory_sync:bool,file_exists:bool}
     */
    public function summarizeMemoryMdForAgentContext(string $contextUuid, string $agentId, array $linkedMemoryIds): array
    {
        $ex = $this->readIfExistsNoSideEffects($contextUuid, $agentId);
        $exists = $ex['exists'] === true;
        $content = $exists ? str_replace("\r\n", "\n", (string)($ex['content'] ?? '')) : '';
        if (!$exists) {
            return [
                'state' => 'missing',
                'template_only' => false,
                'participation_sync' => false,
                'decision_memory_sync' => false,
                'file_exists' => false,
            ];
        }
        $participationSync = $content !== '' && str_contains($content, 'participant_context_sync:');
        $decisionSync = false;
        if ($content !== '' && $linkedMemoryIds !== []) {
            foreach ($linkedMemoryIds as $mid) {
                $mid = trim((string)$mid);
                if ($mid === '') {
                    continue;
                }
                if (str_contains($content, 'da-decision-memory-sync:' . $mid)
                    || str_contains($content, 'da-propagated-decision:' . $mid)) {
                    $decisionSync = true;
                    break;
                }
            }
        }
        $templateOnly = $this->isTemplateOnlyMemoryBody($content);
        $state = 'loaded';
        if ($participationSync) {
            $state = 'participation_sync';
        } elseif ($decisionSync) {
            $state = 'decision_memory_sync';
        } elseif ($templateOnly) {
            $state = 'template_only';
        }

        return [
            'state' => $state,
            'template_only' => $templateOnly,
            'participation_sync' => $participationSync,
            'decision_memory_sync' => $decisionSync,
            'file_exists' => true,
        ];
    }

    private function isTemplateOnlyMemoryBody(string $content): bool
    {
        $content = str_replace("\r\n", "\n", $content);
        foreach (explode("\n", $content) as $ln) {
            $t = trim($ln);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^#+\s/', $t)) {
                continue;
            }
            if (preg_match('/^<!--.*-->$/', $t)) {
                continue;
            }
            if (preg_match('/^<!--/', $t)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Normalisation douce : ajoute les sections canoniques manquantes en fin de fichier.
     * Ne supprime aucune section ; n’écrase pas le corps existant.
     *
     * @return array{ok:bool,changed:bool,sections_touched:list<string>,warnings:list<string>,message?:string}
     */
    public function normalizeSoft(string $contextUuid, string $agentId): array
    {
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => [], 'message' => $r['error'] ?? 'resolve_failed'];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $merge = $this->softMergeMissingSections($body);
        if (!$merge['changed']) {
            return ['ok' => true, 'changed' => false, 'sections_touched' => [], 'warnings' => $merge['warnings']];
        }
        if (!$this->atomicWrite($r['path'], $merge['body'])) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => $merge['warnings'], 'message' => 'write_failed'];
        }
        return [
            'ok' => true,
            'changed' => true,
            'sections_touched' => $merge['added'],
            'warnings' => $merge['warnings'],
        ];
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function write(string $contextUuid, string $agentId, string $content): array
    {
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed'];
        }
        $this->ensureFile($contextUuid, $agentId);
        if (!$this->atomicWrite($r['path'], $content)) {
            return ['ok' => false, 'message' => 'write_failed'];
        }
        return ['ok' => true];
    }

    /**
     * @param 'recent'|'pending' $section
     * @return array{ok:bool,message?:string}
     */
    public function appendNote(
        string $contextUuid,
        string $agentId,
        string $note,
        string $section = 'recent',
        ?string $sessionId = null
    ): array {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'empty_note'];
        }
        $fallbackRecent = '## Recent Learnings';
        $heading = '## Recent Notes';
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed'];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $m = $this->softMergeMissingSections($body);
        $body = $m['body'];
        if ($section === 'pending') {
            $heading = '## Pending Consolidation Notes';
            if (!str_contains($body, $heading)) {
                $body = rtrim($body) . "\n\n" . $heading . "\n\n";
            }
        } else {
            if (str_contains($body, '## Recent Notes')) {
                $heading = '## Recent Notes';
            } elseif (str_contains($body, $fallbackRecent)) {
                $heading = $fallbackRecent;
            } else {
                $body = rtrim($body) . "\n\n## Recent Notes\n\n";
                $heading = '## Recent Notes';
            }
        }
        $ts = gmdate('Y-m-d H:i') . ' UTC';
        $sess = $sessionId !== null && trim($sessionId) !== '' ? ' session:' . trim($sessionId) : '';
        $line = '- ' . $ts . $sess . ' — ' . str_replace(["\r", "\n"], ' ', $note);
        if (mb_strlen($line, 'UTF-8') > 500) {
            $line = mb_substr($line, 0, 497, 'UTF-8') . '…';
        }
        $newBody = $this->insertLineAfterHeading($body, $heading, $line);
        if ($newBody === null) {
            return ['ok' => false, 'message' => 'append_failed'];
        }
        return $this->write($contextUuid, $agentId, $newBody);
    }

    /**
     * Note récente (canonique : ## Recent Notes ; repli ## Recent Learnings).
     *
     * @return array{ok:bool,changed?:bool,sections_touched?:list<string>,warnings?:list<string>,message?:string}
     */
    public function appendRecentNote(
        string $contextUuid,
        string $agentId,
        string $note,
        ?string $sessionId = null
    ): array {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'empty_note', 'sections_touched' => [], 'warnings' => []];
        }
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed', 'sections_touched' => [], 'warnings' => []];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $primary = '## Recent Notes';
        $fallback = '## Recent Learnings';
        $heading = str_contains($body, $primary) || !str_contains($body, $fallback) ? $primary : $fallback;
        if (!str_contains($body, $heading)) {
            $body = rtrim($body) . "\n\n" . $primary . "\n\n";
            $heading = $primary;
        }
        $ts = gmdate('c');
        $sess = $sessionId !== null && trim($sessionId) !== '' ? ' session:' . trim($sessionId) : '';
        $line = '- ' . $ts . $sess . ' — ' . str_replace(["\r", "\n"], ' ', $note);
        if (mb_strlen($line, 'UTF-8') > 800) {
            $line = mb_substr($line, 0, 797, 'UTF-8') . '…';
        }
        $newBody = $this->insertLineAfterHeading($body, $heading, $line);
        if ($newBody === null) {
            return ['ok' => false, 'message' => 'append_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        $w = $this->write($contextUuid, $agentId, $newBody);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message'] ?? 'write_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        return [
            'ok' => true,
            'changed' => true,
            'sections_touched' => [trim($heading, '# ')],
            'warnings' => $warnings,
        ];
    }

    /**
     * Liste les agent_id ayant un fichier memory.md existant pour ce contexte (read-only sur le disque).
     *
     * @return list<string>
     */
    public function listAgentIdsWithExistingMemoryFile(string $contextUuid): array
    {
        if (!$this->isValidContextUuid($contextUuid)) {
            return [];
        }
        $base = realpath($this->storageRoot);
        if ($base === false) {
            return [];
        }
        $agentsDir = $base . DIRECTORY_SEPARATOR . strtolower($contextUuid) . DIRECTORY_SEPARATOR . 'agents';
        if (!is_dir($agentsDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($agentsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->isValidAgentId($entry)) {
                continue;
            }
            $sub = $agentsDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($sub)) {
                continue;
            }
            $md = $sub . DIRECTORY_SEPARATOR . 'memory.md';
            if (!is_file($md)) {
                continue;
            }
            $realMd = realpath($md);
            if ($realMd === false || !str_starts_with($realMd, $base)) {
                continue;
            }
            $out[] = strtolower($entry);
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Ajoute un bloc factuel sous ## Decisions Remembered (flux propagation explicite).
     * Déduplication par commentaire HTML `da-propagated-decision:{memory_id}`.
     *
     * @return array{ok:bool,changed:bool,sections_touched:list<string>,warnings:list<string>,message?:string,skipped_duplicate?:bool}
     */
    public function appendPropagatedDecisionBlock(
        string $contextUuid,
        string $agentId,
        string $memoryId,
        string $markdownBlock
    ): array {
        $memoryId = trim($memoryId);
        $markdownBlock = trim(str_replace("\r\n", "\n", $markdownBlock));
        if ($memoryId === '' || $markdownBlock === '') {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => [], 'message' => 'empty_payload'];
        }
        $marker = '<!-- da-propagated-decision:' . $memoryId . ' -->';
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => [], 'message' => $r['error'] ?? 'resolve_failed'];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        if (str_contains($body, $marker)) {
            return [
                'ok' => true,
                'changed' => false,
                'sections_touched' => [],
                'warnings' => [],
                'skipped_duplicate' => true,
            ];
        }
        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $heading = '## Decisions Remembered';
        if (!str_contains($body, $heading)) {
            $body = rtrim($body) . "\n\n" . $heading . "\n\n";
            $warnings[] = 'Added missing section: Decisions Remembered';
        }
        $block = "- {$marker}\n" . $markdownBlock;
        $newBody = $this->insertBlockAfterHeadingRobust($body, $heading, $block, []);
        $w = $this->write($contextUuid, $agentId, $newBody);
        if (!$w['ok']) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => $warnings, 'message' => $w['message'] ?? 'write_failed'];
        }
        return [
            'ok' => true,
            'changed' => true,
            'sections_touched' => ['Decisions Remembered'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Synchronisation automatique depuis une Decision Memory (factuel, idempotent).
     *
     * @param array{
     *   memory_id:string,
     *   session_id:string,
     *   mode:string,
     *   playbook_id:string,
     *   decision_status:string,
     *   decision_summary:string,
     *   required_next_actions:list<string>,
     *   risk_level:string,
     *   created_at:string
     * } $payload
     *
     * @return array{ok:bool,changed:bool,sections_touched:list<string>,warnings:list<string>,message?:string,skipped_duplicate?:bool}
     */
    public function appendDecisionMemoryAutoSync(string $contextUuid, string $agentId, array $payload): array
    {
        $memoryId = trim((string)($payload['memory_id'] ?? ''));
        if ($memoryId === '') {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => [], 'message' => 'empty_memory_id'];
        }
        $markerMain = '<!-- da-decision-memory-sync:' . $memoryId . ' -->';
        $markerLegacy = '<!-- da-propagated-decision:' . $memoryId . ' -->';
        $markerRecent = '<!-- da-decision-memory-sync-recent:' . $memoryId . ' -->';

        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => [], 'message' => $r['error'] ?? 'resolve_failed'];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        if (str_contains($body, $markerMain) || str_contains($body, $markerLegacy)) {
            return [
                'ok' => true,
                'changed' => false,
                'sections_touched' => [],
                'warnings' => [],
                'skipped_duplicate' => true,
            ];
        }

        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $headingDr = '## Decisions Remembered';
        if (!str_contains($body, $headingDr)) {
            $body = rtrim($body) . "\n\n" . $headingDr . "\n\n";
            $warnings[] = 'Added missing section: Decisions Remembered';
        }

        $sessionId = trim((string)($payload['session_id'] ?? ''));
        $mode = trim((string)($payload['mode'] ?? ''));
        $playbook = trim((string)($payload['playbook_id'] ?? ''));
        $status = trim((string)($payload['decision_status'] ?? ''));
        $summary = trim((string)($payload['decision_summary'] ?? ''));
        $risk = trim((string)($payload['risk_level'] ?? ''));
        $createdRaw = trim((string)($payload['created_at'] ?? ''));
        $date = $createdRaw !== '' ? substr($createdRaw, 0, 10) : gmdate('Y-m-d');
        $next = is_array($payload['required_next_actions'] ?? null)
            ? array_values(array_filter(array_map('strval', $payload['required_next_actions'])))
            : [];
        $memState = trim((string)($payload['memory_state'] ?? ''));
        $pQual = trim((string)($payload['persistence_quality'] ?? ''));
        $revReq = ($payload['review_required'] ?? false) === true;
        $origOutcome = trim((string)($payload['original_outcome_label'] ?? ''));
        $verdictL = trim((string)($payload['verdict_label'] ?? ''));
        $cx = is_array($payload['contradictions'] ?? null)
            ? array_values(array_filter(array_map('strval', $payload['contradictions'])))
            : [];

        $lines = [];
        $lines[] = '- [' . $date . '] Decision Memory `' . $memoryId . '` ' . $markerMain;
        $lines[] = '  - Session: `' . $sessionId . '`';
        $lines[] = '  - Mode: `' . ($mode !== '' ? $mode : '—') . '`';
        $lines[] = '  - Playbook: `' . ($playbook !== '' ? $playbook : '—') . '`';
        $lines[] = '  - Status (normalized): `' . ($status !== '' ? $status : '—') . '`';
        $lines[] = '  - Original outcome: `' . ($origOutcome !== '' ? str_replace('`', "'", $origOutcome) : '—') . '`';
        if ($verdictL !== '') {
            $lines[] = '  - Verdict signal: `' . $verdictL . '`';
        }
        $lines[] = '  - Memory state: `' . ($memState !== '' ? $memState : '—') . '`';
        $lines[] = '  - Persistence quality: `' . ($pQual !== '' ? $pQual : '—') . '`';
        $lines[] = '  - Review required: `' . ($revReq ? 'yes' : 'no') . '`';
        $lines[] = '  - Summary: ' . str_replace(["\r", "\n"], ' ', $this->truncatePlain($summary, 400));
        if ($risk !== '') {
            $lines[] = '  - Risk level: `' . $risk . '`';
        }
        if ($cx !== []) {
            $lines[] = '  - Contradictions:';
            foreach (array_slice($cx, 0, 8) as $c) {
                $lines[] = '    - ' . str_replace(["\r", "\n"], ' ', $this->truncatePlain((string)$c, 400));
            }
        }
        $lines[] = '  - Required next actions:';
        if ($next !== []) {
            foreach (array_slice($next, 0, 12) as $n) {
                $lines[] = '    - ' . str_replace(["\r", "\n"], ' ', $this->truncatePlain((string)$n, 240));
            }
        } else {
            $lines[] = '    - none extracted';
        }
        $lines[] = '  - Source: decision_memory_auto_sync';

        $block = implode("\n", $lines);
        $newBody = $this->insertBlockAfterHeadingRobust($body, $headingDr, $block, []);
        $sectionsTouched = ['Decisions Remembered'];

        if (!str_contains($newBody, $markerRecent)) {
            $primary = '## Recent Notes';
            $fallback = '## Recent Learnings';
            $headingRn = str_contains($newBody, $primary) || !str_contains($newBody, $fallback) ? $primary : $fallback;
            if (!str_contains($newBody, $headingRn)) {
                $newBody = rtrim($newBody) . "\n\n" . $primary . "\n\n";
                $headingRn = $primary;
            }
            $noteLine = '- [' . $date . '] Decision memory `' . $memoryId . '` synced from session `' . $sessionId . '`. ' . $markerRecent;
            $withRecent = $this->insertLineAfterHeading($newBody, $headingRn, $noteLine);
            if (is_string($withRecent) && $withRecent !== $newBody) {
                $newBody = $withRecent;
                $sectionsTouched[] = trim($headingRn, '# ');
            }
        }

        $w = $this->write($contextUuid, $agentId, $newBody);
        if (!$w['ok']) {
            return ['ok' => false, 'changed' => false, 'sections_touched' => [], 'warnings' => $warnings, 'message' => $w['message'] ?? 'write_failed'];
        }

        return [
            'ok' => true,
            'changed' => true,
            'sections_touched' => array_values(array_unique($sectionsTouched)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Crée ou met à jour memory.md pour chaque agent participant d’une session contextualisée terminée.
     * Trace factuelle idempotente par session : <!-- participant_context_sync:{session_uuid} -->
     * (distinct du flux Decision Memory / da-decision-memory-sync).
     *
     * @param array<string,mixed> $session Ligne session (status, strategic_context_id, …).
     *
     * @return array{
     *   ok:bool,
     *   skipped?:string,
     *   context_id?:string,
     *   session_id?:string,
     *   agents?:list<array<string,mixed>>
     * }
     */
    /**
     * @param array{include_synthesizer?:bool,include_devil_advocate?:bool} $resolverOptions
     */
    public function syncParticipantMemoryOnSessionCompleted(
        string $sessionId,
        array $session,
        ?string $strategicContextIdOverride = null,
        ?string $contextDisplayLabel = null,
        array $resolverOptions = []
    ): array {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return ['ok' => false, 'skipped' => 'empty_session_id', 'agents' => []];
        }
        $sidNorm = strtolower($sessionId);
        $contextId = trim((string)($strategicContextIdOverride ?? ($session['strategic_context_id'] ?? '')));
        if (!$this->isValidContextUuid($contextId)) {
            return ['ok' => false, 'skipped' => 'invalid_or_missing_context_id', 'agents' => []];
        }
        $st = strtolower(trim((string)($session['status'] ?? '')));
        if ($st !== 'completed') {
            return ['ok' => false, 'skipped' => 'not_completed', 'agents' => []];
        }

        $ctxLabel = trim((string)($contextDisplayLabel ?? ''));
        if ($ctxLabel === '') {
            $ctxLabel = $contextId;
        }
        $ctxEsc = str_replace(["\r", "\n", '`'], [' ', ' ', "'"], $ctxLabel);

        $resolver = new SessionAgentResolver();
        $agentIds = $resolver->resolveParticipants($sessionId, $resolverOptions);
        $agentIds = $resolver->filterParticipantsForMemorySync($sessionId, $session, $agentIds);
        $marker = '<!-- participant_context_sync:' . $sidNorm . ' -->';
        $date = gmdate('Y-m-d');

        $runResult = null;
        if (!empty($session['result']) && is_string($session['result'])) {
            $decoded = json_decode($session['result'], true);
            $runResult = is_array($decoded) ? $decoded : null;
        }
        $diag = is_array($runResult)
            ? DecisionMemoryPersistenceClassifier::participationDiagnosticNotes($runResult)
            : [
                'outcome_status_norm' => 'unknown',
                'verdict_label' => '',
                'reliability' => '',
                'blocking_unknowns' => [],
                'contradictions' => [],
            ];
        $modeEsc = str_replace(["\r", "\n", '`'], [' ', ' ', "'"], trim((string)($session['mode'] ?? '')));
        $titleEsc = str_replace(["\r", "\n", '`'], [' ', ' ', "'"], trim((string)($session['title'] ?? '')));
        $outcomeSig = str_replace(["\r", "\n", '`'], [' ', ' ', "'"], (string)$diag['outcome_status_norm']);
        $verdictDisp = ($diag['verdict_label'] ?? '') !== ''
            ? str_replace(["\r", "\n", '`'], [' ', ' ', "'"], (string)$diag['verdict_label'])
            : 'unknown';
        $relDisp = ($diag['reliability'] ?? '') !== ''
            ? str_replace(["\r", "\n", '`'], [' ', ' ', "'"], (string)$diag['reliability'])
            : 'unknown';
        $cx = is_array($diag['contradictions'] ?? null) ? $diag['contradictions'] : [];
        $mainTension = $cx[0] ?? 'none';
        $mainTension = str_replace(["\r", "\n", '`'], [' ', ' ', "'"], (string)$mainTension);

        $lines = [];
        $lines[] = "- [{$date}] Participated in session `{$sidNorm}` in context `{$ctxEsc}`.";
        $lines[] = "  - Mode: `{$modeEsc}`";
        $lines[] = "  - Topic: `{$titleEsc}`";
        $lines[] = "  - Session outcome signal: `{$outcomeSig}`";
        $lines[] = "  - Verdict signal: `{$verdictDisp}`";
        $lines[] = "  - Reliability: `{$relDisp}`";
        $lines[] = "  - Main tension: `{$this->truncatePlain($mainTension, 360)}`";
        $lines[] = '  - Participation role: participant agent';
        $lines[] = "  {$marker}";
        $lines[] = '  Source: participant_context_sync';
        $block = implode("\n", $lines);

        $psFb = false;
        if (is_array($runResult)) {
            $do = $runResult['decision_outcome'] ?? null;
            if (is_array($do) && is_array($do['persistence_safety'] ?? null)) {
                $psFb = ($do['persistence_safety']['derived_from_fallback'] ?? false) === true;
            }
        }
        $needsPending = $cx !== [] || $psFb || ($verdictDisp === 'go' && str_contains(strtolower($relDisp), 'weak'));

        $agentsOut = [];
        foreach ($agentIds as $agentId) {
            $agentId = strtolower(trim($agentId));
            if ($agentId === '' || !$this->isValidAgentId($agentId)) {
                $agentsOut[] = ['agent_id' => $agentId, 'ok' => false, 'skipped' => 'invalid_agent_id'];
                continue;
            }
            $r = $this->resolveMemoryFile($contextId, $agentId);
            if (!$r['ok'] || $r['path'] === null) {
                $agentsOut[] = ['agent_id' => $agentId, 'ok' => false, 'skipped' => $r['error'] ?? 'resolve_failed'];
                continue;
            }
            $this->ensureFile($contextId, $agentId);
            $body = str_replace("\r\n", "\n", $this->read($contextId, $agentId));
            if (str_contains($body, $marker)) {
                $stripped = $this->stripLegacyParticipantBlockIfNeeded($body, $marker);
                if ($stripped === $body) {
                    $agentsOut[] = ['agent_id' => $agentId, 'ok' => true, 'changed' => false, 'skipped_duplicate' => true];
                    continue;
                }
                $body = $stripped;
            }
            $merge = $this->softMergeMissingSections($body);
            $body = $merge['body'];
            $body = $this->applyParticipationAuxiliarySections($body, $diag, $sidNorm, $needsPending);
            $headingPrimary = '## Recent Notes';
            $newBody = $this->insertBlockAfterHeadingRobust(
                $body,
                $headingPrimary,
                $block,
                ['## Recent Learnings']
            );
            $w = $this->write($contextId, $agentId, $newBody);
            $agentsOut[] = [
                'agent_id' => $agentId,
                'ok' => $w['ok'],
                'changed' => $w['ok'],
            ];
        }

        return [
            'ok' => true,
            'context_id' => $contextId,
            'session_id' => $sessionId,
            'agents' => $agentsOut,
        ];
    }

    /**
     * Alias produit : même comportement que {@see syncParticipantMemoryOnSessionCompleted}.
     *
     * @param array<string,mixed> $session
     *
     * @return array<string,mixed>
     */
    /**
     * @param array{include_synthesizer?:bool,include_devil_advocate?:bool} $resolverOptions
     */
    public function ensureParticipantMemoryForContext(
        string $sessionId,
        array $session,
        ?string $strategicContextIdOverride = null,
        ?string $contextDisplayLabel = null,
        array $resolverOptions = []
    ): array {
        return $this->syncParticipantMemoryOnSessionCompleted(
            $sessionId,
            $session,
            $strategicContextIdOverride,
            $contextDisplayLabel,
            $resolverOptions
        );
    }

    private function stripLegacyParticipantBlockIfNeeded(string $body, string $marker): string
    {
        if (!str_contains($body, $marker)) {
            return $body;
        }
        $pos = strpos($body, $marker);
        if ($pos !== false) {
            $window = substr($body, max(0, $pos - 500), 900);
            if (str_contains($window, 'Session outcome signal:')) {
                return $body;
            }
        }
        $pattern = '/(^|\R)- \[[^\]]+\] Participated in session[^\n]*\R(?:  [^\n]*\R)*?  '
            . preg_quote($marker, '/') . '\R?/u';
        $new = preg_replace($pattern, "\n", $body, 1);

        return is_string($new) ? $new : $body;
    }

    /**
     * @param array{
     *   outcome_status_norm?:string,
     *   verdict_label?:string,
     *   reliability?:string,
     *   blocking_unknowns?:list<string>,
     *   contradictions?:list<string>
     * } $diag
     */
    private function applyParticipationAuxiliarySections(string $body, array $diag, string $sidNorm, bool $needsPending): string
    {
        $unknowns = is_array($diag['blocking_unknowns'] ?? null)
            ? array_values(array_filter(array_map('strval', $diag['blocking_unknowns'])))
            : [];
        if ($unknowns !== []) {
            $oq = '## Open Questions';
            $pos = strpos($body, $oq);
            if ($pos !== false) {
                $next = strpos($body, "\n## ", $pos + strlen($oq));
                $end = $next !== false ? $next : strlen($body);
                $section = substr($body, $pos, $end - $pos);
                if (str_contains($section, 'No open question extracted yet.')) {
                    $bullets = '';
                    foreach (array_slice($unknowns, 0, 12) as $uq) {
                        $uq = $this->truncatePlain(str_replace(["\r", "\n"], ' ', trim($uq)), 400);
                        if ($uq === '') {
                            continue;
                        }
                        $bullets .= '- ' . $uq . "\n  Source: session `" . $sidNorm . "`\n";
                    }
                    if ($bullets !== '') {
                        $newSection = $oq . "\n\n" . rtrim($bullets) . "\n";
                        $body = substr($body, 0, $pos) . $newSection . substr($body, $end);
                    }
                }
            }
        }

        $cx = is_array($diag['contradictions'] ?? null)
            ? array_values(array_filter(array_map('strval', $diag['contradictions'])))
            : [];
        $ctrMarker = '<!-- contradiction_session:' . strtolower($sidNorm) . ' -->';
        if ($cx !== [] && !str_contains($body, $ctrMarker)) {
            $h = '## Contradictions To Review';
            $block = '- Contradiction detected in session `' . $sidNorm . '`:' . "\n";
            foreach ($cx as $c) {
                $block .= '  - ' . $this->truncatePlain(str_replace(["\r", "\n"], ' ', $c), 500) . "\n";
            }
            $block .= "  {$ctrMarker}\n  Source: participant_context_sync\n";
            $body = $this->insertBlockAfterHeadingRobust($body, $h, rtrim($block), []);
        }

        $pendMarker = '<!-- pending_consolidation:' . strtolower($sidNorm) . ' -->';
        if ($needsPending && !str_contains($body, $pendMarker)) {
            $h = '## Pending Consolidation Notes';
            $block = '- Session `' . $sidNorm
                . '` should be reviewed before promoting any signal to Stable Beliefs or Strategic Assumptions.' . "\n"
                . "  {$pendMarker}\n  Source: participant_context_sync\n";
            $body = $this->insertBlockAfterHeadingRobust($body, $h, rtrim($block), []);
        }

        $posMarker = '<!-- current_position_last_participation:' . strtolower($sidNorm) . ' -->';
        if (!str_contains($body, $posMarker)) {
            $h = '## Current Position';
            $block = '- No current position extracted yet.' . "\n"
                . '- Last participation: `' . $sidNorm . '`' . "\n"
                . "  {$posMarker}\n  Source: participant_context_sync\n";
            $body = $this->insertBlockAfterHeadingRobust($body, $h, rtrim($block), []);
        }

        return $body;
    }

    private function truncatePlain(string $s, int $max): string
    {
        $x = preg_replace('/\s+/', ' ', $s);
        $x = trim((string)$x);
        if (function_exists('mb_strlen') && mb_strlen($x) > $max) {
            return mb_substr($x, 0, max(0, $max - 1)) . '…';
        }
        if (strlen($x) > $max) {
            return substr($x, 0, $max - 1) . '…';
        }

        return $x;
    }

    /**
     * Insère un bloc après un titre de section. Ne retourne jamais null : append la section canonique + bloc en fin si aucune cible.
     *
     * @param list<string> $alternateHeadings variantes exactes à essayer (ex. ## Recent Learnings)
     */
    private function insertBlockAfterHeadingRobust(
        string $body,
        string $canonicalHeading,
        string $block,
        array $alternateHeadings
    ): string {
        $body = str_replace("\r\n", "\n", $body);
        $try = array_values(array_unique(array_merge([$canonicalHeading], $alternateHeadings)));
        foreach ($try as $h) {
            $nb = $this->insertBlockAfterHeadingLine($body, $h, $block);
            if ($nb !== null) {
                return $nb;
            }
        }
        foreach (explode("\n", $body) as $line) {
            $trim = rtrim($line);
            if ($canonicalHeading === '## Recent Notes') {
                if (preg_match('/^##\s+Recent\s+Notes\s*$/iu', $trim)) {
                    $nb = $this->insertBlockAfterHeadingLine($body, $trim, $block);
                    if ($nb !== null) {
                        return $nb;
                    }
                }
                if (preg_match('/^##\s+Recent\s+Learnings\s*$/iu', $trim)) {
                    $nb = $this->insertBlockAfterHeadingLine($body, $trim, $block);
                    if ($nb !== null) {
                        return $nb;
                    }
                }
            } elseif ($canonicalHeading === '## Decisions Remembered') {
                if (preg_match('/^##\s+Decisions\s+Remembered\s*$/iu', $trim)) {
                    $nb = $this->insertBlockAfterHeadingLine($body, $trim, $block);
                    if ($nb !== null) {
                        return $nb;
                    }
                }
            }
        }

        return rtrim($body) . "\n\n" . $canonicalHeading . "\n\n" . $block . "\n";
    }

    private function insertBlockAfterHeadingLine(string $body, string $heading, string $block): ?string
    {
        $pos = strpos($body, $heading);
        if ($pos === false) {
            return null;
        }
        $lineEnd = strpos($body, "\n", $pos);
        $insertAt = $lineEnd !== false ? $lineEnd + 1 : ($pos + strlen($heading));
        return substr($body, 0, $insertAt) . $block . "\n" . substr($body, $insertAt);
    }

    /**
     * @return array{ok:bool,changed?:bool,sections_touched?:list<string>,warnings?:list<string>,message?:string}
     */
    public function addContradiction(
        string $contextUuid,
        string $agentId,
        string $contradiction,
        ?string $source = null
    ): array {
        $contradiction = trim($contradiction);
        if ($contradiction === '') {
            return ['ok' => false, 'message' => 'empty_contradiction', 'sections_touched' => [], 'warnings' => []];
        }
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed', 'sections_touched' => [], 'warnings' => []];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $heading = '## Contradictions To Review';
        if (!str_contains($body, $heading)) {
            $body = rtrim($body) . "\n\n" . $heading . "\n\n";
        }
        $src = $source !== null && trim($source) !== '' ? ' source:' . str_replace(["\r", "\n", ' '], ' ', trim($source)) : '';
        $line = '- ' . gmdate('c') . $src . ' — ' . str_replace(["\r", "\n"], ' ', mb_substr($contradiction, 0, 600, 'UTF-8'));
        $newBody = $this->insertLineAfterHeading($body, $heading, $line);
        if ($newBody === null) {
            return ['ok' => false, 'message' => 'append_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        $w = $this->write($contextUuid, $agentId, $newBody);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message'] ?? 'write_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        return ['ok' => true, 'changed' => true, 'sections_touched' => ['Contradictions To Review'], 'warnings' => $warnings];
    }

    /**
     * @return array{ok:bool,changed?:bool,sections_touched?:list<string>,warnings?:list<string>,message?:string}
     */
    public function markDeprecated(
        string $contextUuid,
        string $agentId,
        string $text,
        ?string $reason = null
    ): array {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'message' => 'empty_text', 'sections_touched' => [], 'warnings' => []];
        }
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed', 'sections_touched' => [], 'warnings' => []];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $heading = '## Deprecated / Forgotten';
        if (!str_contains($body, $heading)) {
            $body = rtrim($body) . "\n\n" . $heading . "\n\n";
        }
        $reasonPart = $reason !== null && trim($reason) !== ''
            ? ' | reason: ' . str_replace(["\r", "\n"], ' ', mb_substr(trim($reason), 0, 200, 'UTF-8'))
            : '';
        $snippet = str_replace(["\r", "\n"], ' ', mb_substr($text, 0, 400, 'UTF-8'));
        $line = '- ' . gmdate('c') . ' — marked deprecated' . $reasonPart . ': «' . $snippet . '»';
        $newBody = $this->insertLineAfterHeading($body, $heading, $line);
        if ($newBody === null) {
            return ['ok' => false, 'message' => 'append_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        $w = $this->write($contextUuid, $agentId, $newBody);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message'] ?? 'write_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        return ['ok' => true, 'changed' => true, 'sections_touched' => ['Deprecated / Forgotten'], 'warnings' => $warnings];
    }

    /**
     * Compact déterministe MVP : dédoublon Recent Notes/Learnings, déplacer entrées anciennes vers Deprecated, horodatage maintenance.
     * Aucune suppression physique du fichier ; contenu déplacé = recopié sous Deprecated.
     *
     * @return array{ok:bool,changed?:bool,sections_touched?:list<string>,warnings?:list<string>,message?:string}
     */
    public function compactMemory(string $contextUuid, string $agentId): array
    {
        $r = $this->resolveMemoryFile($contextUuid, $agentId);
        if (!$r['ok'] || $r['path'] === null) {
            return ['ok' => false, 'message' => $r['error'] ?? 'resolve_failed', 'sections_touched' => [], 'warnings' => []];
        }
        $this->ensureFile($contextUuid, $agentId);
        $body = str_replace("\r\n", "\n", $this->read($contextUuid, $agentId));
        $merge = $this->softMergeMissingSections($body);
        $body = $merge['body'];
        $warnings = $merge['warnings'];
        $sectionsTouched = [];
        $now = time();
        $cutoff = $now - 90 * 86400;

        $beforeSnapshot = $body;
        $body = $this->injectMaintenanceComment($body, gmdate('c'));
        if ($body !== $beforeSnapshot) {
            $sectionsTouched[] = '_maintenance_comment';
        }

        foreach (['## Recent Notes', '## Recent Learnings'] as $h) {
            $res = $this->compactListSection($body, $h, $cutoff);
            if ($res['changed']) {
                $body = $res['body'];
                $sectionsTouched[] = trim($h, '# ');
            }
        }
        $body = preg_replace("/\n{4,}/", "\n\n\n", $body) ?? $body;

        $changed = $body !== $beforeSnapshot;
        if (!$changed) {
            return ['ok' => true, 'changed' => false, 'sections_touched' => [], 'warnings' => $warnings];
        }
        $w = $this->write($contextUuid, $agentId, $body);
        if (!$w['ok']) {
            return ['ok' => false, 'message' => $w['message'] ?? 'write_failed', 'sections_touched' => [], 'warnings' => $warnings];
        }
        return ['ok' => true, 'changed' => true, 'sections_touched' => array_values(array_unique($sectionsTouched)), 'warnings' => $warnings];
    }

    /**
     * Consolidation déterministe (pas de LLM) : dédoublonnage de lignes dans Recent/Pending, plafond de lignes.
     *
     * @return array{ok:bool,message?:string,lines_removed?:int}
     */
    public function consolidate(string $contextUuid, string $agentId): array
    {
        $raw = $this->read($contextUuid, $agentId);
        if ($raw === '') {
            return ['ok' => true, 'lines_removed' => 0];
        }
        $before = mb_substr_count($raw, "\n");
        $out = str_replace("\r\n", "\n", $raw);
        $out = $this->consolidateSectionLines($out, '## Recent Notes', 120);
        $out = $this->consolidateSectionLines($out, '## Recent Learnings', 120);
        $out = $this->consolidateSectionLines($out, '## Pending Consolidation Notes', 100);
        $out = preg_replace("/\n{4,}/", "\n\n\n", $out) ?? $out;
        $w = $this->write($contextUuid, $agentId, $out);
        if (!$w['ok']) {
            return $w;
        }
        $after = mb_substr_count($out, "\n");
        return ['ok' => true, 'lines_removed' => max(0, $before - $after)];
    }

    private function consolidateSectionLines(string $full, string $heading, int $maxLines): string
    {
        $pos = strpos($full, $heading);
        if ($pos === false) {
            return $full;
        }
        $hLen = strlen($heading);
        $bodyStart = $pos + $hLen;
        if (isset($full[$bodyStart]) && $full[$bodyStart] === "\n") {
            $bodyStart++;
        }
        $next = strpos($full, "\n## ", $bodyStart);
        $end = $next !== false ? $next : strlen($full);
        $sectionBody = substr($full, $bodyStart, $end - $bodyStart);
        $lines = preg_split('/\R/', $sectionBody) ?: [];
        $kept = [];
        $seen = [];
        foreach ($lines as $ln) {
            $t = trim((string)$ln);
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $kept[] = $t;
        }
        if (count($kept) > $maxLines) {
            $kept = array_slice($kept, -$maxLines);
        }
        $newBody = $kept === [] ? "\n" : "\n" . implode("\n", $kept) . "\n";
        return substr($full, 0, $bodyStart) . $newBody . substr($full, $end);
    }

    /**
     * Bloc markdown pour injection prompt (strict read-only, sans effet de bord).
     * Vide si legacy / invalide / fichier absent.
     * Exclut ## Deprecated / Forgotten. Inclut Contradictions / Recent Notes de façon bornée via buildSnippetForPrompt.
     */
    public function buildPromptInjectionBlock(?string $strategicContextUuid, string $agentId): string
    {
        if ($strategicContextUuid === null || trim($strategicContextUuid) === '') {
            return '';
        }
        $ctx = trim($strategicContextUuid);
        if (!$this->isValidContextUuid($ctx) || !$this->isValidAgentId($agentId)) {
            return '';
        }
        if (in_array(strtolower($agentId), ['synthesizer', 'devil_advocate'], true)) {
            return '';
        }
        $read = $this->readIfExistsNoSideEffects($ctx, $agentId);
        if (($read['exists'] ?? false) !== true) {
            return '';
        }
        $full = (string)($read['content'] ?? '');
        if (trim($full) === '') {
            return '';
        }
        $forSnippet = $this->stripDeprecatedSectionForPrompt($full);
        $snippet = $this->buildSnippetForPrompt($forSnippet, self::MAX_PROMPT_INJECT_CHARS);
        if (trim($snippet) === '') {
            return '';
        }
        $trunc = mb_strlen($forSnippet, 'UTF-8') > mb_strlen($snippet, 'UTF-8');
        $notice = $trunc
            ? "\n\n[NOTICE: Agent context memory truncated for prompt. Full file on disk.]\n"
            : '';
        return "\n\n-----------------------------------\n"
            . "Contextual memory for this agent in this strategic context\n"
            . "-----------------------------------\n\n"
            . $snippet
            . $notice;
    }

    public function buildSnippetForPrompt(string $full, int $maxChars): string
    {
        $full = str_replace("\r\n", "\n", $full);
        if (mb_strlen($full, 'UTF-8') <= $maxChars) {
            return trim($full);
        }
        $parts = [];
        $budget = $maxChars - 400;
        $head = mb_substr($full, 0, min(1200, (int)floor($budget * 0.22)), 'UTF-8');
        $parts[] = rtrim($head) . "\n\n[…]\n";
        foreach (
            [
                '## Current Strategic Direction',
                '## Open Questions',
                '## Recent Notes',
                '## Recent Learnings',
                '## Contradictions To Review',
                '## Strategic Assumptions',
                '## Stable Beliefs',
                '## Persistent Beliefs',
                '## Decisions Remembered',
                '## Failed Predictions',
                '## Failed Predictions / Regrets',
                '## Relationships',
                '## Relationships in this Context',
                '## User Preferences',
                '## User Preferences in this Context',
            ] as $h
        ) {
            $chunk = $this->extractSection($full, $h, (int)floor($budget * 0.12));
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }
        $out = trim(implode("\n\n", array_filter($parts)));
        if (mb_strlen($out, 'UTF-8') > $maxChars) {
            $out = mb_substr($out, 0, $maxChars - 20, 'UTF-8') . "\n[…truncated…]";
        }
        return $out;
    }

    private function stripDeprecatedSectionForPrompt(string $full): string
    {
        $marker = '## Deprecated / Forgotten';
        $pos = mb_strpos($full, $marker, 0, 'UTF-8');
        if ($pos === false) {
            return $full;
        }
        $next = mb_strpos($full, "\n## ", $pos + mb_strlen($marker, 'UTF-8'), 'UTF-8');
        if ($next === false) {
            return rtrim(mb_substr($full, 0, $pos, 'UTF-8'));
        }
        return rtrim(mb_substr($full, 0, $pos, 'UTF-8')) . mb_substr($full, $next, null, 'UTF-8');
    }

    /**
     * @return array{changed:bool,body:string,warnings:list<string>,added:list<string>}
     */
    private function softMergeMissingSections(string $body): array
    {
        $warnings = [];
        $added = [];
        $body = str_replace("\r\n", "\n", $body);
        $changed = false;
        foreach (self::CANONICAL_SECTIONS_TO_ENSURE as $heading) {
            if (!str_contains($body, $heading)) {
                $body = rtrim($body) . "\n\n" . $heading . "\n\n";
                $changed = true;
                $label = trim($heading, '# ');
                $warnings[] = 'Added missing section: ' . $label;
                $added[] = $label;
            }
        }
        return ['changed' => $changed, 'body' => $body, 'warnings' => $warnings, 'added' => $added];
    }

    private function insertLineAfterHeading(string $body, string $heading, string $line): ?string
    {
        $escapedHeading = preg_quote($heading, '/');
        $pattern = '/(' . $escapedHeading . "\s*\n)/u";
        $replacement = '$1' . $line . "\n";
        $newBody = preg_replace($pattern, $replacement, $body, 1);
        return is_string($newBody) && $newBody !== $body ? $newBody : null;
    }

    private function injectMaintenanceComment(string $body, string $iso): string
    {
        $tag = '<!-- da-memory-maintenance: ' . $iso . ' -->';
        if (str_contains($body, 'da-memory-maintenance:')) {
            $body = preg_replace('/<!--\s*da-memory-maintenance:[^>]+-->/', $tag, $body, 1) ?? $body;
            return $body;
        }
        $lines = explode("\n", $body, 2);
        if ($lines === []) {
            return $tag . "\n";
        }
        return $lines[0] . "\n" . $tag . "\n" . ($lines[1] ?? '');
    }

    /**
     * @return array{changed:bool,body:string,moved:list<string>}
     */
    private function compactListSection(string $full, string $heading, int $cutoffTs): array
    {
        $pos = strpos($full, $heading);
        if ($pos === false) {
            return ['changed' => false, 'body' => $full, 'moved' => []];
        }
        $hLen = strlen($heading);
        $bodyStart = $pos + $hLen;
        if (isset($full[$bodyStart]) && $full[$bodyStart] === "\n") {
            $bodyStart++;
        }
        $next = strpos($full, "\n## ", $bodyStart);
        $end = $next !== false ? $next : strlen($full);
        $sectionBody = substr($full, $bodyStart, $end - $bodyStart);
        $lines = preg_split('/\R/', $sectionBody) ?: [];
        $kept = [];
        $moved = [];
        $seen = [];
        foreach ($lines as $ln) {
            $t = trim((string)$ln);
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $tsLine = $this->parseLineTimestampUtc($t);
            if ($tsLine !== null && $tsLine < $cutoffTs) {
                $moved[] = $t;
                continue;
            }
            $kept[] = $t;
        }
        $newSectionBody = $kept === [] ? "\n" : "\n" . implode("\n", $kept) . "\n";
        $newFull = substr($full, 0, $bodyStart) . $newSectionBody . substr($full, $end);
        if ($moved === []) {
            $changed = $newFull !== $full;
            return ['changed' => $changed, 'body' => $newFull, 'moved' => []];
        }
        $dep = '## Deprecated / Forgotten';
        $stamp = gmdate('c');
        $appendLines = [];
        foreach ($moved as $m) {
            $appendLines[] = '- ' . $stamp . ' — moved from ' . trim($heading, '# ') . ': ' . $m;
        }
        $blob = implode("\n", $appendLines) . "\n";
        $ins = $this->insertLineAfterHeading($newFull, $dep, $blob);
        if ($ins === null) {
            $merge = $this->softMergeMissingSections($newFull);
            $newFull = $merge['body'];
            $ins = $this->insertLineAfterHeading($newFull, $dep, $blob);
        }
        if ($ins === null) {
            $ins = rtrim($newFull) . "\n\n" . $blob;
        }
        return ['changed' => true, 'body' => $ins, 'moved' => $moved];
    }

    private function parseLineTimestampUtc(?string $line): ?int
    {
        if ($line === null || $line === '') {
            return null;
        }
        if (preg_match('/^-\s*([0-9]{4}-[0-9]{2}-[0-9]{2})[ T]([0-9]{2}):([0-9]{2})/', $line, $m)) {
            $str = $m[1] . 'T' . $m[2] . ':' . $m[3] . ':00+00:00';
            $t = strtotime($str);
            return $t !== false ? $t : null;
        }
        if (preg_match('/^-\s*([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:\-+Z]+)/', $line, $m2)) {
            $t = strtotime($m2[1]);
            return $t !== false ? $t : null;
        }
        return null;
    }

    private function extractSection(string $full, string $heading, int $maxBodyChars): string
    {
        $pos = mb_strpos($full, $heading, 0, 'UTF-8');
        if ($pos === false) {
            return '';
        }
        $start = $pos;
        $nl = mb_strpos($full, "\n", $pos + mb_strlen($heading, 'UTF-8'), 'UTF-8');
        $bodyStart = $nl !== false ? $nl + 1 : $pos;
        $next = mb_strpos($full, "\n## ", $bodyStart, 'UTF-8');
        $end = $next !== false ? $next : mb_strlen($full, 'UTF-8');
        $body = trim(mb_substr($full, $bodyStart, $end - $bodyStart, 'UTF-8'));
        if ($body === '') {
            return $heading . "\n\n_(empty)_\n";
        }
        if (mb_strlen($body, 'UTF-8') > $maxBodyChars) {
            $body = mb_substr($body, 0, $maxBodyChars - 30, 'UTF-8') . "\n…";
        }
        return $heading . "\n\n" . $body;
    }

    private function atomicWrite(string $path, string $content): bool
    {
        RuntimeFilesystemGuard::inspect('write', $path, [
            'source' => 'AgentContextMemoryService::atomicWrite',
            'bytes' => strlen($content),
        ]);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        RuntimeFilesystemGuard::inspect('rename', $tmp . ' -> ' . $path, [
            'source' => 'AgentContextMemoryService::atomicWrite',
        ]);
        if (!@rename($tmp, $path)) {
            RuntimeFilesystemGuard::inspect('delete', $tmp, [
                'source' => 'AgentContextMemoryService::atomicWrite',
            ]);
            @unlink($tmp);
            RuntimeFilesystemGuard::inspect('write_fallback', $path, [
                'source' => 'AgentContextMemoryService::atomicWrite',
                'bytes' => strlen($content),
            ]);
            return @file_put_contents($path, $content, LOCK_EX) !== false;
        }
        return true;
    }

    /** Chemin relatif pour logs API (pas de fuite absolue). */
    public function relativePath(string $contextUuid, string $agentId): string
    {
        return 'strategic-contexts/' . strtolower(trim($contextUuid)) . '/agents/' . strtolower(trim($agentId)) . '/memory.md';
    }
}

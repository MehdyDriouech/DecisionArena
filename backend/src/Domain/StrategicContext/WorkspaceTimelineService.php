<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\DecisionRoomRepository;

/**
 * Timeline workspace read-only par Strategic Context (aucun LLM, pas de scoring).
 * Filtrage strict : sessions et artefacts rattachés au contexte uniquement ;
 * relationship_events : strategic_context_id = contexte.
 */
final class WorkspaceTimelineService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * @param list<string> $sessionIds
     * @return list<string>
     */
    private function uniqueSessionIds(array $sessionIds): array
    {
        $out = [];
        foreach ($sessionIds as $sid) {
            $sid = trim((string)$sid);
            if ($sid !== '' && !in_array($sid, $out, true)) {
                $out[] = $sid;
            }
        }
        return $out;
    }

    /**
     * @return array{type:string,id:string,title:string,summary:string,session_id:?string,room_id:?string,memory_id:?string,created_at:string,metadata:array<string,mixed>}
     */
    private function item(
        string $type,
        string $id,
        string $title,
        string $summary,
        ?string $sessionId,
        ?string $roomId,
        ?string $memoryId,
        string $createdAt,
        array $metadata = []
    ): array {
        $sid = $sessionId !== null && trim($sessionId) !== '' ? trim($sessionId) : null;
        $rid = $roomId !== null && trim($roomId) !== '' ? trim($roomId) : null;
        $mid = $memoryId !== null && trim($memoryId) !== '' ? trim($memoryId) : null;

        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'summary' => $summary,
            'session_id' => $sid,
            'room_id' => $rid,
            'memory_id' => $mid,
            'created_at' => $createdAt,
            'metadata' => $metadata,
        ];
    }

    /** Résumé léger depuis JSON (clés de premier niveau uniquement, pas de parcours profond). */
    private function lightReportSnippet(?string $json, int $maxLen = 180): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        try {
            $d = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }
        if (!is_array($d)) {
            return '';
        }
        $parts = [];
        foreach (['evidence_badge', 'evidence_score', 'score', 'risk_level', 'recommendation', 'summary', 'headline'] as $k) {
            if (!array_key_exists($k, $d)) {
                continue;
            }
            $v = $d[$k];
            if (is_string($v) || is_int($v) || is_float($v)) {
                $parts[] = $k . ': ' . (string)$v;
            }
            $joined = implode(' · ', $parts);
            if (mb_strlen($joined, 'UTF-8') >= $maxLen) {
                break;
            }
        }
        $t = trim(implode(' · ', $parts));
        if (mb_strlen($t, 'UTF-8') <= $maxLen) {
            return $t;
        }
        return mb_substr($t, 0, $maxLen - 1, 'UTF-8') . '…';
    }

    /**
     * @return array{context_id:string,items:list<array<string,mixed>>,legacy_count:int,warnings:list<string>}
     */
    public function build(string $contextId, bool $includeLegacyOrphans = false): array
    {
        $items = [];
        $warnings = [];
        $legacyCount = 0;

        // Sessions du contexte : colonne OU lien strategic_context_sessions (sans autre contexte).
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT s.id, s.title, s.mode, s.status, s.created_at, s.strategic_context_id, s.decision_brief, s.result
            FROM sessions s
            LEFT JOIN strategic_context_sessions scs
              ON scs.session_id = s.id AND scs.context_id = :c_join
            WHERE s.strategic_context_id = :c_col OR scs.session_id IS NOT NULL
            ORDER BY s.created_at DESC
            LIMIT 200
        ');
        $stmt->execute([':c_join' => $contextId, ':c_col' => $contextId]);
        $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $contextSessionIds = [];
        foreach ($sessions as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid !== '') {
                $contextSessionIds[] = $sid;
            }
        }
        $contextSessionIds = $this->uniqueSessionIds($contextSessionIds);

        foreach ($sessions as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $scol = $s['strategic_context_id'] ?? null;
            $colStr = $scol !== null ? (string)$scol : '';
            if ($colStr === '' || $colStr !== $contextId) {
                $legacyCount++;
            }
            $items[] = $this->item(
                'session',
                $sid,
                (string)($s['title'] ?? ''),
                trim((string)($s['mode'] ?? '') . ' · ' . (string)($s['status'] ?? '')),
                $sid,
                null,
                null,
                (string)($s['created_at'] ?? ''),
                [
                    'strategic_context_id' => $colStr !== '' ? $colStr : null,
                    'workspace_column_match' => $colStr === $contextId,
                ]
            );

            if (($s['status'] ?? '') === 'completed') {
                $brief = $s['decision_brief'] ?? null;
                $sum = '';
                if (is_string($brief) && $brief !== '') {
                    $dec = json_decode($brief, true);
                    if (is_array($dec)) {
                        $sum = (string)($dec['headline'] ?? $dec['summary'] ?? $dec['decision_label'] ?? '');
                    }
                }
                if ($sum === '' && !empty($s['result'])) {
                    $res = is_string($s['result']) ? json_decode($s['result'], true) : null;
                    if (is_array($res)) {
                        $adj = $res['adjusted_decision'] ?? null;
                        if (is_array($adj)) {
                            $sum = (string)($adj['ui_decision_label'] ?? $adj['decision_label'] ?? '');
                        }
                    }
                }
                $items[] = $this->item(
                    'decision',
                    $sid . ':decision',
                    'Décision · ' . (string)($s['title'] ?? ''),
                    $sum !== '' ? $sum : 'Session terminée',
                    $sid,
                    null,
                    null,
                    (string)($s['created_at'] ?? ''),
                    []
                );
            }
        }

        $roomsRepo = new DecisionRoomRepository();
        foreach ($roomsRepo->listByContext($contextId, 80) as $room) {
            $rid = (string)($room['room_id'] ?? '');
            if ($rid === '') {
                continue;
            }
            $items[] = $this->item(
                'room',
                $rid,
                (string)($room['title'] ?? ''),
                (string)($room['status'] ?? ''),
                null,
                $rid,
                null,
                (string)($room['updated_at'] ?? $room['created_at'] ?? ''),
                ['playbook_id' => (string)($room['playbook_id'] ?? '')]
            );
        }

        $mStmt = $this->pdo->prepare('
            SELECT m.memory_id, m.decision_summary, m.created_at, m.session_id
            FROM decision_memories m
            INNER JOIN strategic_context_memories scm ON scm.memory_id = m.memory_id AND scm.context_id = ?
            ORDER BY m.created_at DESC
            LIMIT 120
        ');
        $mStmt->execute([$contextId]);
        foreach ($mStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $m) {
            $mid = (string)($m['memory_id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $sum = trim((string)($m['decision_summary'] ?? ''));
            $items[] = $this->item(
                'memory',
                $mid,
                'Mémoire décisionnelle',
                mb_substr($sum, 0, 200, 'UTF-8'),
                (string)($m['session_id'] ?? '') !== '' ? (string)$m['session_id'] : null,
                null,
                $mid,
                (string)($m['created_at'] ?? ''),
                []
            );
        }

        $evStmt = $this->pdo->prepare('
            SELECT id, session_id, round_index, source_agent_id, target_agent_id, event_type, intensity, created_at
            FROM relationship_events
            WHERE strategic_context_id = ?
            ORDER BY created_at ASC, id ASC
            LIMIT 300
        ');
        $evStmt->execute([$contextId]);
        foreach ($evStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $e) {
            $eid = (string)($e['id'] ?? '');
            $items[] = $this->item(
                'relationship_event',
                $eid !== '' ? 'relationship_event:' . $eid : 'relationship_event:unknown',
                (string)($e['event_type'] ?? 'event') . ' · ' . (string)($e['source_agent_id'] ?? '') . ' → ' . (string)($e['target_agent_id'] ?? ''),
                'Round ' . (string)($e['round_index'] ?? '') . ' · intensity ' . (string)($e['intensity'] ?? ''),
                (string)($e['session_id'] ?? '') !== '' ? (string)$e['session_id'] : null,
                null,
                null,
                (string)($e['created_at'] ?? ''),
                [
                    'event_type' => (string)($e['event_type'] ?? ''),
                    'intensity' => isset($e['intensity']) ? (float)$e['intensity'] : null,
                ]
            );
        }

        // Evidence & risk : sessions du contexte uniquement (pas de JSON lourd).
        if ($contextSessionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($contextSessionIds), '?'));
            try {
                $erStmt = $this->pdo->prepare("
                    SELECT session_id, created_at, updated_at, report_json
                    FROM evidence_reports
                    WHERE session_id IN ($placeholders)
                    ORDER BY datetime(COALESCE(updated_at, created_at)) DESC
                    LIMIT 100
                ");
                $erStmt->execute($contextSessionIds);
                foreach ($erStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $er) {
                    $esid = (string)($er['session_id'] ?? '');
                    if ($esid === '') {
                        continue;
                    }
                    $ts = (string)($er['updated_at'] ?? $er['created_at'] ?? '');
                    $snippet = $this->lightReportSnippet($er['report_json'] ?? null, 160);
                    $items[] = $this->item(
                        'evidence',
                        $esid . ':evidence',
                        'Evidence · session ' . $esid,
                        $snippet !== '' ? $snippet : 'Rapport evidence',
                        $esid,
                        null,
                        null,
                        $ts,
                        ['source' => 'evidence_reports']
                    );
                }
            } catch (\Throwable) {
                // table absente ou erreur : ignorer
            }
            try {
                $rpStmt = $this->pdo->prepare("
                    SELECT session_id, risk_level, reversibility, created_at, updated_at, report_json
                    FROM session_risk_profiles
                    WHERE session_id IN ($placeholders)
                    ORDER BY datetime(COALESCE(updated_at, created_at)) DESC
                    LIMIT 100
                ");
                $rpStmt->execute($contextSessionIds);
                foreach ($rpStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $rp) {
                    $rsid = (string)($rp['session_id'] ?? '');
                    if ($rsid === '') {
                        continue;
                    }
                    $ts = (string)($rp['updated_at'] ?? $rp['created_at'] ?? '');
                    $lvl = (string)($rp['risk_level'] ?? '');
                    $rev = (string)($rp['reversibility'] ?? '');
                    $sum = trim($lvl . ' · ' . $rev);
                    $extra = $this->lightReportSnippet($rp['report_json'] ?? null, 120);
                    if ($extra !== '') {
                        $sum = trim($sum . ' — ' . $extra);
                    }
                    $items[] = $this->item(
                        'risk',
                        $rsid . ':risk',
                        'Risque · session ' . $rsid,
                        $sum !== '' ? $sum : 'Profil de risque',
                        $rsid,
                        null,
                        null,
                        $ts,
                        [
                            'risk_level' => $lvl !== '' ? $lvl : null,
                            'reversibility' => $rev !== '' ? $rev : null,
                            'source' => 'session_risk_profiles',
                        ]
                    );
                }
            } catch (\Throwable) {
            }
        }

        if ($includeLegacyOrphans) {
            $warnings[] = 'include_legacy=1 : les événements relationship sans strategic_context_id ne sont pas fusionnés en V1 (filtrage strict sur strategic_context_id).';
        }

        usort($items, static function (array $a, array $b): int {
            $ta = (string)($a['created_at'] ?? '');
            $tb = (string)($b['created_at'] ?? '');
            $c = strcmp($tb, $ta);
            if ($c !== 0) {
                return $c;
            }
            $c = strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
            if ($c !== 0) {
                return $c;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return [
            'context_id' => $contextId,
            'items' => $items,
            'legacy_count' => $legacyCount,
            'warnings' => $warnings,
        ];
    }
}

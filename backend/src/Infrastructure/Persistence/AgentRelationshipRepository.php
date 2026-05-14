<?php
namespace Infrastructure\Persistence;

use Domain\SocialDynamics\RelationshipEvent;

class AgentRelationshipRepository {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->pdo();
    }

    public function deleteBySession(string $sessionId): void {
        $stmt = $this->pdo->prepare('DELETE FROM relationship_events WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $stmt = $this->pdo->prepare('DELETE FROM agent_relationships WHERE session_id = ?');
        $stmt->execute([$sessionId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function findBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM agent_relationships WHERE session_id = ? ORDER BY updated_at ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function findForAgent(string $sessionId, string $agentId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM agent_relationships WHERE session_id = ?
             AND (source_agent_id = ? OR target_agent_id = ?)'
        );
        $stmt->execute([$sessionId, $agentId, $agentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function findEventsBySession(string $sessionId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM relationship_events WHERE session_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findRelationship(string $sessionId, string $sourceAgentId, string $targetAgentId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM agent_relationships WHERE session_id = ? AND source_agent_id = ? AND target_agent_id = ?'
        );
        $stmt->execute([$sessionId, $sourceAgentId, $targetAgentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function upsertRelationship(array $data): array {
        $now = date('c');
        $sessionId       = $data['session_id'];
        $source          = $data['source_agent_id'];
        $target          = $data['target_agent_id'];
        $affinity        = (float)$data['affinity'];
        $trust           = (float)$data['trust'];
        $conflict        = (float)$data['conflict'];
        $supportCount    = (int)$data['support_count'];
        $challengeCount = (int)$data['challenge_count'];
        $allianceCount   = (int)$data['alliance_count'];
        $attackCount     = (int)$data['attack_count'];
        $lastType        = $data['last_interaction_type'] ?? null;

        $existing = $this->findRelationship($sessionId, $source, $target);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO agent_relationships
                  (session_id, source_agent_id, target_agent_id, affinity, trust, conflict,
                   support_count, challenge_count, alliance_count, attack_count, last_interaction_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $sessionId, $source, $target, $affinity, $trust, $conflict,
                $supportCount, $challengeCount, $allianceCount, $attackCount, $lastType,
                $now, $now,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE agent_relationships SET
                  affinity = ?, trust = ?, conflict = ?, support_count = ?, challenge_count = ?,
                  alliance_count = ?, attack_count = ?, last_interaction_type = ?, updated_at = ?
                 WHERE session_id = ? AND source_agent_id = ? AND target_agent_id = ?'
            );
            $stmt->execute([
                $affinity, $trust, $conflict, $supportCount, $challengeCount,
                $allianceCount, $attackCount, $lastType, $now,
                $sessionId, $source, $target,
            ]);
        }
        return $this->findRelationship($sessionId, $source, $target) ?? [];
    }

    /** @param array<string,mixed> $data */
    public function addEvent(array $data): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO relationship_events
              (session_id, round_index, source_agent_id, target_agent_id, event_type, intensity, evidence, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['session_id'],
            $data['round_index'] ?? null,
            $data['source_agent_id'],
            $data['target_agent_id'] ?? null,
            RelationshipEvent::normalizeType((string)$data['event_type']),
            (float)($data['intensity'] ?? 0.5),
            $data['evidence'] ?? null,
            $data['created_at'] ?? date('c'),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM relationship_events WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{text_lines:array<int,string>,relations:array<int,array<string,mixed>>}
     */
    public function summarizeForPrompt(string $sessionId, string $agentId): array {
        $rels     = $this->findForAgent($sessionId, $agentId);
        $linesOut = [];

        $outAffinity = [];
        $outConflict = [];
        foreach ($rels as $r) {
            if (($r['source_agent_id'] ?? '') !== $agentId) {
                continue;
            }
            $tgt = (string)($r['target_agent_id'] ?? '');
            if ($tgt === '') continue;
            $aff = (float)($r['affinity'] ?? 0);
            $trs = (float)($r['trust'] ?? 0.5);
            $cnf = (float)($r['conflict'] ?? 0);
            if ($aff >= 0.18 && $trs >= 0.48) {
                $outAffinity[] = [
                    'line' => 'You often align with @' . $tgt . ' (trust: ' . round($trs, 2) . ', affinity: ' . round($aff, 2) . ').',
                    'score' => $aff,
                ];
            }
            if ($cnf >= 0.32) {
                $outConflict[] = [
                    'line' => 'You are in recurring conflict with @' . $tgt . ' (conflict: ' . round($cnf, 2) . ').',
                    'score' => $cnf,
                ];
            }
        }
        usort($outAffinity, fn($a, $b) => $b['score'] <=> $a['score']);
        usort($outConflict, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach (array_slice($outAffinity, 0, 2) as $x) {
            $linesOut[] = $x['line'];
        }
        foreach (array_slice($outConflict, 0, 2) as $x) {
            $linesOut[] = $x['line'];
        }

        foreach ($rels as $r) {
            if (($r['target_agent_id'] ?? '') !== $agentId) continue;
            $src = (string)($r['source_agent_id'] ?? '');
            if ($src === '') continue;
            $cnf = (float)($r['conflict'] ?? 0);
            if ($cnf >= 0.32 || (int)($r['challenge_count'] ?? 0) >= 2) {
                $linesOut[] = '@' . $src . ' frequently challenges your positions (conflict: ' . round(max($cnf, 0.3), 2) . ').';
                break;
            }
        }

        $events = $this->findEventsBySession($sessionId);
        $maxRound = 0;
        foreach ($events as $e) {
            $maxRound = max($maxRound, (int)($e['round_index'] ?? 0));
        }
        if ($maxRound > 0) {
            foreach (array_reverse($events) as $e) {
                if ((int)($e['round_index'] ?? 0) !== $maxRound) continue;
                if (($e['target_agent_id'] ?? '') !== $agentId) continue;
                $stype = (string)($e['event_type'] ?? '');
                $srcEv = (string)($e['source_agent_id'] ?? '');
                if ($srcEv === '' || $stype === RelationshipEvent::TYPE_NEUTRAL) continue;
                $linesOut[] = '@' . $srcEv . ' engaged you recently with interaction type: ' . $stype . '.';
                break;
            }
        }

        return ['text_lines' => array_slice(array_unique($linesOut), 0, 8), 'relations' => $rels];
    }
}

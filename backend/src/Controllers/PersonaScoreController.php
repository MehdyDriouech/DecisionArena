<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\PersonaScoreRepository;

class PersonaScoreController {
    private SessionRepository $sessionRepo;
    private PersonaScoreRepository $scoreRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->scoreRepo   = new PersonaScoreRepository();
    }

    public function show(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $pdo = Database::getInstance()->pdo();

        $stmt = $pdo->prepare("
            SELECT * FROM messages
            WHERE session_id = ?
              AND role = 'assistant'
              AND agent_id IS NOT NULL
              AND agent_id != 'devil_advocate'
            ORDER BY created_at ASC
        ");
        $stmt->execute([$id]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $computedAt = $this->scoreRepo->getLastComputedAt($id);
        if ($computedAt) {
            $latestMsgAt = null;
            foreach ($messages as $m) {
                if ($latestMsgAt === null || $m['created_at'] > $latestMsgAt) {
                    $latestMsgAt = $m['created_at'];
                }
            }
            if ($latestMsgAt === null || $latestMsgAt <= $computedAt) {
                $cached = $this->scoreRepo->findBySession($id);
                if ($cached) {
                    $personaNames = $this->loadPersonaNames();
                    return ['scores' => $this->formatCached($cached, $personaNames)];
                }
            }
        }

        if (empty($messages)) {
            return ['scores' => []];
        }

        $scores       = $this->computeScores($messages);
        $personaNames = $this->loadPersonaNames();

        foreach ($scores as &$score) {
            $agentId             = $score['agent_id'];
            $score['persona_name'] = $personaNames[$agentId] ?? $agentId;
            $score['label']        = $this->buildLabel($score['dominance'], $score['citation_count']);
        }
        unset($score);

        $now = date('c');
        $this->scoreRepo->upsert($id, $scores, $now);

        return ['scores' => $scores];
    }

    private function computeScores(array $messages): array {
        $byAgent = [];
        foreach ($messages as $msg) {
            $byAgent[$msg['agent_id']][] = $msg;
        }

        $raw = [];
        foreach ($byAgent as $agentId => $agentMessages) {
            $messageCount = count($agentMessages);
            $totalLen     = 0;
            foreach ($agentMessages as $m) {
                $totalLen += mb_strlen($m['content'] ?? '');
            }
            $avgLen = $messageCount > 0 ? $totalLen / $messageCount : 0;

            $citationCount = 0;
            foreach ($messages as $m) {
                if ($m['agent_id'] !== $agentId) {
                    if (stripos($m['content'] ?? '', $agentId) !== false) {
                        $citationCount++;
                    }
                }
            }

            $raw[$agentId] = [
                'agent_id'           => $agentId,
                'message_count'      => $messageCount,
                'avg_message_length' => (int) round($avgLen),
                'citation_count'     => $citationCount,
            ];
        }

        $maxMsgCount  = max(array_column($raw, 'message_count'));
        $maxAvgLen    = max(array_column($raw, 'avg_message_length'));
        $maxCitations = max(array_column($raw, 'citation_count'));

        $scores = [];
        foreach ($raw as $agentId => $data) {
            $normMsg  = $maxMsgCount  > 0 ? $data['message_count']      / $maxMsgCount  : 0;
            $normLen  = $maxAvgLen    > 0 ? $data['avg_message_length'] / $maxAvgLen    : 0;
            $normCite = $maxCitations > 0 ? $data['citation_count']     / $maxCitations : 0;

            $influence = round(0.3 * $normMsg + 0.3 * $normLen + 0.4 * $normCite, 2);
            $dominance = $influence >= 0.6 ? 'active' : ($influence >= 0.3 ? 'moderate' : 'passive');

            $scores[] = [
                'agent_id'           => $agentId,
                'persona_name'       => $agentId,
                'message_count'      => $data['message_count'],
                'avg_message_length' => $data['avg_message_length'],
                'citation_count'     => $data['citation_count'],
                'influence_score'    => $influence,
                'dominance'          => $dominance,
                'label'              => '',
            ];
        }

        usort($scores, fn($a, $b) => $b['influence_score'] <=> $a['influence_score']);

        return $scores;
    }

    private function buildLabel(string $dominance, int $citationCount): string {
        return match ($dominance) {
            'active'   => "Highly influential — cited {$citationCount} times by peers",
            'moderate' => 'Moderate participation',
            default    => 'Low participation — consider revising prompt',
        };
    }

    private function loadPersonaNames(): array {
        $names = [];
        $dir   = __DIR__ . '/../../storage/personas/';
        $files = glob($dir . '*.md');
        if (!$files) return $names;

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!$content || !str_starts_with($content, '---')) continue;

            $end = strpos($content, '---', 3);
            if ($end === false) continue;

            $frontmatter = substr($content, 3, $end - 3);
            $id   = null;
            $name = null;

            foreach (explode("\n", $frontmatter) as $line) {
                if (preg_match('/^id:\s*(.+)$/', trim($line), $m)) {
                    $id = trim($m[1]);
                }
                if (preg_match('/^name:\s*(.+)$/', trim($line), $m)) {
                    $name = trim($m[1]);
                }
            }

            if ($id !== null && $name !== null) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    private function formatCached(array $rows, array $personaNames): array {
        return array_map(function ($r) use ($personaNames) {
            $agentId   = $r['agent_id'];
            $dominance = $r['dominance'];
            $citations = (int) $r['citation_count'];
            return [
                'agent_id'           => $agentId,
                'persona_name'       => $personaNames[$agentId] ?? $agentId,
                'message_count'      => (int) $r['message_count'],
                'avg_message_length' => (int) $r['avg_message_length'],
                'citation_count'     => $citations,
                'influence_score'    => (float) $r['influence_score'],
                'dominance'          => $dominance,
                'label'              => $this->buildLabel($dominance, $citations),
            ];
        }, $rows);
    }
}

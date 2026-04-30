<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\BiasReportRepository;

class BiasDetectionController {
    private SessionRepository $sessionRepo;
    private BiasReportRepository $biasRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->biasRepo    = new BiasReportRepository();
    }

    public function show(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $pdo = Database::getInstance()->pdo();

        $messages  = $this->fetchMessages($pdo, $id);
        $computedAt = $this->biasRepo->getLastComputedAt($id);

        if ($computedAt) {
            $latestMsgAt = null;
            foreach ($messages as $m) {
                if ($latestMsgAt === null || $m['created_at'] > $latestMsgAt) {
                    $latestMsgAt = $m['created_at'];
                }
            }
            if ($latestMsgAt === null || $latestMsgAt <= $computedAt) {
                $cached = $this->biasRepo->findBySession($id);
                if ($cached) {
                    return ['bias_report' => $cached];
                }
            }
        }

        $positions = $this->fetchPositions($pdo, $id);
        $votes     = $this->fetchVotes($pdo, $id);
        $arguments = $this->fetchArguments($pdo, $id);

        $totalRounds = max(1, (int) ($session['rounds'] ?? 2));

        $detected = [];
        $this->detectGroupthink($messages, $positions, $totalRounds, $detected);
        $this->detectAnchoring($messages, $detected);
        $this->detectConfirmationBias($arguments, $positions, $detected);
        $this->detectAvailabilityBias($messages, $detected);
        $this->detectAuthorityBias($messages, $detected);

        $report = [
            'detected' => $detected,
            'clean'    => empty($detected),
        ];

        $now = date('c');
        $this->biasRepo->upsert($id, $report, $now);

        return ['bias_report' => $report];
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------

    private function fetchMessages(\PDO $pdo, string $sessionId): array {
        $stmt = $pdo->prepare(
            "SELECT * FROM messages WHERE session_id = ? AND role = 'assistant' ORDER BY created_at ASC"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function fetchPositions(\PDO $pdo, string $sessionId): array {
        $stmt = $pdo->prepare(
            'SELECT * FROM agent_positions WHERE session_id = ? ORDER BY round ASC, created_at ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function fetchVotes(\PDO $pdo, string $sessionId): array {
        $stmt = $pdo->prepare('SELECT * FROM session_votes WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function fetchArguments(\PDO $pdo, string $sessionId): array {
        $stmt = $pdo->prepare('SELECT * FROM arguments WHERE session_id = ? ORDER BY created_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // Bias detectors
    // -------------------------------------------------------------------------

    private function detectGroupthink(array $messages, array $positions, int $totalRounds, array &$detected): void {
        if (empty($positions)) return;

        // Collect unique agents from positions (exclude devil_advocate)
        $agentIds = array_unique(array_column($positions, 'agent_id'));
        $agentIds = array_values(array_filter($agentIds, fn($a) => $a !== 'devil_advocate'));

        if (count($agentIds) < 3) return;

        // Group stances by round and agent
        $byRound = [];
        foreach ($positions as $pos) {
            $round   = (int) ($pos['round'] ?? 1);
            $agent   = $pos['agent_id'] ?? '';
            $stance  = strtolower(trim($pos['stance'] ?? $pos['position'] ?? ''));
            if ($agent === 'devil_advocate' || $stance === '') continue;
            $byRound[$round][$agent] = $stance;
        }

        if (empty($byRound)) return;

        $convergenceRound = null;
        $convergenceStance = null;

        ksort($byRound);
        foreach ($byRound as $round => $agentStances) {
            $stances = array_values($agentStances);
            if (count(array_unique($stances)) === 1 && count($agentStances) >= 3) {
                $convergenceRound  = $round;
                $convergenceStance = $stances[0];
                break;
            }
        }

        if ($convergenceRound === null) return;
        if ($convergenceRound > ($totalRounds / 2)) return;

        // Check no agent changed position after round 1
        $positionChangedAfterRound1 = false;
        $agentLastStance = [];
        foreach ($byRound as $round => $agentStances) {
            if ($round <= 1) {
                $agentLastStance = $agentStances;
                continue;
            }
            foreach ($agentStances as $agent => $stance) {
                if (isset($agentLastStance[$agent]) && $agentLastStance[$agent] !== $stance) {
                    $positionChangedAfterRound1 = true;
                    break 2;
                }
            }
            $agentLastStance = array_merge($agentLastStance, $agentStances);
        }

        if ($positionChangedAfterRound1) return;

        $severity = $convergenceRound <= ($totalRounds / 2) ? 'high' : 'medium';

        $detected[] = [
            'bias'           => 'groupthink',
            'severity'       => $severity,
            'evidence'       => "All agents converged by round {$convergenceRound} with no documented opposition.",
            'recommendation' => 'Enable Forced Dissent mode or add a Critic persona.',
        ];
    }

    private function detectAnchoring(array $messages, array &$detected): void {
        if (count($messages) < 2) return;

        $firstAgentId = null;
        foreach ($messages as $m) {
            if (!empty($m['agent_id'])) {
                $firstAgentId = $m['agent_id'];
                break;
            }
        }

        if ($firstAgentId === null) return;

        $subsequentMessages = array_slice($messages, 1);
        if (empty($subsequentMessages)) return;

        $mentionCount = 0;
        foreach ($subsequentMessages as $m) {
            if (stripos($m['content'] ?? '', $firstAgentId) !== false) {
                $mentionCount++;
            }
        }

        $total = count($subsequentMessages);
        $pct   = $total > 0 ? round(($mentionCount / $total) * 100, 1) : 0;

        if ($pct <= 60) return;

        $detected[] = [
            'bias'           => 'anchoring',
            'severity'       => 'medium',
            'evidence'       => "Initial framing by {$firstAgentId} was referenced in {$pct}% of subsequent messages.",
            'recommendation' => 'Consider starting with a neutral context document.',
        ];
    }

    private function detectConfirmationBias(array $arguments, array $positions, array &$detected): void {
        $stanceCounts = [];

        // Count from arguments table (argument_type field)
        foreach ($arguments as $arg) {
            $type = strtolower(trim($arg['argument_type'] ?? $arg['type'] ?? ''));
            if ($type === '') continue;
            $stanceCounts[$type] = ($stanceCounts[$type] ?? 0) + 1;
        }

        // Supplement with positions stance if arguments table is thin
        if (empty($stanceCounts)) {
            foreach ($positions as $pos) {
                $stance = strtolower(trim($pos['stance'] ?? $pos['position'] ?? ''));
                if ($stance === '') continue;
                $stanceCounts[$stance] = ($stanceCounts[$stance] ?? 0) + 1;
            }
        }

        if (empty($stanceCounts)) return;

        $total = array_sum($stanceCounts);
        if ($total === 0) return;

        $maxCount  = max($stanceCounts);
        $maxStance = array_search($maxCount, $stanceCounts);
        $pct       = round(($maxCount / $total) * 100, 1);

        if ($pct <= 70) return;

        $detected[] = [
            'bias'           => 'confirmation_bias',
            'severity'       => 'medium',
            'evidence'       => "Arguments heavily skewed ({$pct}% one-sided).",
            'recommendation' => 'Balance your agent selection between defenders and challengers.',
        ];
    }

    private function detectAvailabilityBias(array $messages, array &$detected): void {
        $agentMessages = array_filter($messages, fn($m) => !empty($m['agent_id']));
        if (empty($agentMessages)) return;

        $countByAgent = [];
        foreach ($agentMessages as $m) {
            $countByAgent[$m['agent_id']] = ($countByAgent[$m['agent_id']] ?? 0) + 1;
        }

        $total = array_sum($countByAgent);
        if ($total === 0) return;

        arsort($countByAgent);
        $topAgent = array_key_first($countByAgent);
        $topCount = $countByAgent[$topAgent];
        $pct      = round(($topCount / $total) * 100, 1);

        if ($pct <= 50) return;

        $severity = $pct > 60 ? 'high' : 'medium';

        $detected[] = [
            'bias'           => 'availability_bias',
            'severity'       => $severity,
            'evidence'       => "Agent {$topAgent} produced {$pct}% of all contributions.",
            'recommendation' => 'Reduce rounds or add balancing personas.',
        ];
    }

    private function detectAuthorityBias(array $messages, array &$detected): void {
        $agentMessages = array_filter($messages, fn($m) => !empty($m['agent_id']));
        if (empty($agentMessages)) return;

        $agentIds = array_unique(array_column(array_values($agentMessages), 'agent_id'));

        $citationCount = [];
        foreach ($agentIds as $agentId) {
            $citationCount[$agentId] = 0;
        }

        foreach ($agentMessages as $m) {
            foreach ($agentIds as $agentId) {
                if ($m['agent_id'] !== $agentId && stripos($m['content'] ?? '', $agentId) !== false) {
                    $citationCount[$agentId]++;
                }
            }
        }

        arsort($citationCount);
        $sorted = array_values($citationCount);
        $keys   = array_keys($citationCount);

        if (count($sorted) < 2) return;

        $topCount    = $sorted[0];
        $secondCount = $sorted[1];
        $topAgent    = $keys[0];
        $secondAgent = $keys[1];

        if ($topCount < 2 || $secondCount < 2) return;
        if ($topCount <= 2 * $secondCount) return;

        $detected[] = [
            'bias'           => 'authority_bias',
            'severity'       => 'medium',
            'evidence'       => "Agent {$topAgent} was cited {$topCount} times — more than 2× the next ({$secondCount} times).",
            'recommendation' => 'Challenge the dominant voice with a Devil\'s Advocate.',
        ];
    }
}

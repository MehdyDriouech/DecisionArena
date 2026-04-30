<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\VoteRepository;
use Infrastructure\Persistence\ConfidenceTimelineRepository;

class ConfidenceTimelineController {
    private SessionRepository           $sessionRepo;
    private MessageRepository           $messageRepo;
    private VoteRepository              $voteRepo;
    private ConfidenceTimelineRepository $timelineRepo;

    private const POSITIVE_KEYWORDS = ['go', 'recommend', 'feasible', 'viable', 'strong', 'agree', 'approve', 'yes'];
    private const NEGATIVE_KEYWORDS = ['no-go', 'reject', 'infeasible', 'risk', 'disagree', 'fail', 'stop', 'avoid'];

    public function __construct() {
        $this->sessionRepo  = new SessionRepository();
        $this->messageRepo  = new MessageRepository();
        $this->voteRepo     = new VoteRepository();
        $this->timelineRepo = new ConfidenceTimelineRepository();
    }

    public function show(Request $req): array {
        $sessionId = $req->param('id');

        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        // Cache freshness check
        $computedAt    = $this->timelineRepo->getLastComputedAt($sessionId);
        $latestMsgAt   = $this->getLatestMessageCreatedAt($sessionId);

        if ($computedAt !== null && $latestMsgAt !== null && $computedAt >= $latestMsgAt) {
            $cached = $this->timelineRepo->findBySession($sessionId) ?? [];
            return $this->buildResponse($cached);
        }

        // Load all assistant messages, grouped by round
        $allMessages = $this->messageRepo->findBySession($sessionId);
        $byRound     = [];
        foreach ($allMessages as $msg) {
            if ($msg['role'] !== 'assistant') continue;
            $round = (int)($msg['round'] ?? 0);
            if ($round <= 0) continue;
            $byRound[$round][] = $msg;
        }

        if (empty($byRound)) {
            return [
                'rounds'                    => [],
                'consensus_reached_at_round' => null,
                'late_consensus'            => false,
            ];
        }

        ksort($byRound);

        // Load votes and positions
        $votes     = $this->voteRepo->findVotesBySession($sessionId);
        $positions = $this->loadPositions($sessionId);

        $totalRounds = max(array_keys($byRound));
        $roundKeys   = array_keys($byRound);
        $lastRound   = end($roundKeys);

        $rounds = [];
        foreach ($byRound as $round => $messages) {
            $rounds[] = $this->computeRound($round, $messages, $votes, $lastRound);
        }

        // Find consensus_reached_at_round
        $consensusRound = null;
        foreach ($rounds as $r) {
            if ($r['consensus_forming']) {
                $consensusRound = $r['round'];
                break;
            }
        }

        $lateConsensus = false;
        if ($consensusRound !== null && $totalRounds > 0) {
            $lateConsensus = $consensusRound > $totalRounds * 0.75;
        }

        // Cache
        $now = date('c');
        $this->timelineRepo->upsertRounds($sessionId, $rounds, $now);

        return [
            'rounds'                    => $rounds,
            'consensus_reached_at_round' => $consensusRound,
            'late_consensus'            => $lateConsensus,
        ];
    }

    private function computeRound(int $round, array $messages, array $votes, int $lastRound): array {
        $totalMessages = count($messages);
        $pos = 0;
        $neg = 0;

        foreach ($messages as $msg) {
            $content = strtolower($msg['content'] ?? '');
            $isPos   = false;
            $isNeg   = false;
            foreach (self::POSITIVE_KEYWORDS as $kw) {
                if (str_contains($content, $kw)) { $isPos = true; break; }
            }
            foreach (self::NEGATIVE_KEYWORDS as $kw) {
                if (str_contains($content, $kw)) { $isNeg = true; break; }
            }
            if ($isPos) $pos++;
            elseif ($isNeg) $neg++;
        }

        $neutral      = $totalMessages - $pos - $neg;
        $rawConfidence = ($pos + 0.5 * $neutral) / max(1, $totalMessages);

        // Votes blending only on final round
        $confidence = $rawConfidence;
        if ($round === $lastRound) {
            $roundVotes = array_filter($votes, fn($v) => $v['round'] === null || (int)$v['round'] === $round);
            if (!empty($roundVotes)) {
                $goCount   = count(array_filter($roundVotes, fn($v) => strtoupper($v['vote']) === 'GO'));
                $nogoCount = count(array_filter($roundVotes, fn($v) => strtoupper($v['vote']) === 'NO-GO'));
                $total     = $goCount + $nogoCount;
                if ($total > 0) {
                    $voteConfidence = $goCount / $total;
                    $confidence     = 0.5 * $rawConfidence + 0.5 * $voteConfidence;
                }
            }
        }

        $confidence = round(min(1.0, max(0.0, $confidence)), 2);

        if ($confidence > 0.6) {
            $dominantPosition = 'GO';
        } elseif ($confidence < 0.4) {
            $dominantPosition = 'NO-GO';
        } else {
            $dominantPosition = 'ITERATE';
        }

        $consensusForming = $confidence >= 0.65;

        return [
            'round'             => $round,
            'confidence'        => $confidence,
            'dominant_position' => $dominantPosition,
            'consensus_forming' => $consensusForming,
        ];
    }

    private function buildResponse(array $cachedRows): array {
        $rounds = [];
        $consensusRound = null;
        $totalRounds = count($cachedRows);

        foreach ($cachedRows as $row) {
            $cf = (bool)$row['consensus_forming'];
            $rounds[] = [
                'round'             => (int)$row['round'],
                'confidence'        => (float)$row['confidence'],
                'dominant_position' => $row['dominant_position'],
                'consensus_forming' => $cf,
            ];
            if ($cf && $consensusRound === null) {
                $consensusRound = (int)$row['round'];
            }
        }

        $lateConsensus = false;
        if ($consensusRound !== null && $totalRounds > 0) {
            $lateConsensus = $consensusRound > $totalRounds * 0.75;
        }

        return [
            'rounds'                    => $rounds,
            'consensus_reached_at_round' => $consensusRound,
            'late_consensus'            => $lateConsensus,
        ];
    }

    private function getLatestMessageCreatedAt(string $sessionId): ?string {
        $pdo  = \Infrastructure\Persistence\Database::getInstance()->pdo();
        $stmt = $pdo->prepare(
            'SELECT created_at FROM messages WHERE session_id = ? AND role = \'assistant\' ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['created_at'] : null;
    }

    private function loadPositions(string $sessionId): array {
        try {
            $pdo  = \Infrastructure\Persistence\Database::getInstance()->pdo();
            $stmt = $pdo->prepare('SELECT * FROM agent_positions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}

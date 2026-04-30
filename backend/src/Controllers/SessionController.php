<?php
namespace Controllers;

use Domain\Orchestration\DebateMemoryService;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SnapshotRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;

class SessionController {
    private SessionRepository  $sessionRepo;
    private MessageRepository  $messageRepo;
    private SnapshotRepository $snapshotRepo;
    private DebateRepository   $debateRepo;
    private DebateMemoryService $debateMemory;
    private VoteRepository $voteRepo;

    public function __construct() {
        $this->sessionRepo  = new SessionRepository();
        $this->messageRepo  = new MessageRepository();
        $this->snapshotRepo = new SnapshotRepository();
        $this->debateRepo   = new DebateRepository();
        $this->debateMemory = new DebateMemoryService($this->debateRepo);
        $this->voteRepo     = new VoteRepository();
    }

    public function index(Request $req): array {
        return $this->sessionRepo->findAll();
    }

    public function show(Request $req): array {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $messages = $this->messageRepo->findBySession($id);
        $arguments = $this->debateRepo->findArgumentsBySession($id);
        $positions = $this->debateRepo->findPositionsBySession($id);
        $edges = $this->debateRepo->findEdgesBySession($id);
        $votes = $this->voteRepo->findVotesBySession($id);
        $decision = $this->voteRepo->findDecisionBySession($id);
        $state = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];
        return [
            'session' => $session,
            'messages' => $messages,
            'arguments' => $arguments,
            'positions' => $positions,
            'interaction_edges' => $edges,
            'weighted_analysis' => $this->debateMemory->buildWeightedAnalysis($state),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($state),
            'votes' => $votes,
            'automatic_decision' => $decision,
        ];
    }

    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['title'])) {
            return Response::error('title required', 400);
        }
        $now = date('c');
        $id  = $this->uuid();
        $session = [
            'id'                   => $id,
            'title'                => $data['title'],
            'mode'                 => $data['mode'] ?? 'chat',
            'initial_prompt'       => $data['initial_prompt'] ?? '',
            'selected_agents'      => json_encode($data['selected_agents'] ?? []),
            'rounds'               => (int)($data['rounds'] ?? 2),
            'language'             => $data['language'] ?? 'en',
            'status'               => 'draft',
            'cf_rounds'            => (int)($data['cf_rounds'] ?? 3),
            'cf_interaction_style' => $data['cf_interaction_style'] ?? 'sequential',
            'cf_reply_policy'      => $data['cf_reply_policy'] ?? 'all-agents-reply',
            'is_favorite'          => 0,
            'is_reference'         => 0,
            'force_disagreement'   => (int)($data['force_disagreement'] ?? 0),
            'parent_session_id'    => $data['parent_session_id'] ?? null,
            'rerun_reason'         => $data['rerun_reason'] ?? null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
        return $this->sessionRepo->create($session);
    }

    public function memory(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $allowed = ['is_favorite','is_reference','decision_taken','user_learnings','follow_up_notes'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (!empty($updates)) {
            $this->sessionRepo->update($id, $updates);
        }
        return ['session' => $this->sessionRepo->findById($id)];
    }

    public function updateThreshold(Request $req): array {
        $id      = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $data      = $req->body();
        $threshold = (float)($data['decision_threshold'] ?? 0.55);
        if ($threshold <= 0 || $threshold >= 1) $threshold = 0.55;
        $this->sessionRepo->update($id, ['decision_threshold' => $threshold]);
        return ['session' => $this->sessionRepo->findById($id)];
    }

    public function delete(Request $req): array {
        $id = $req->param('id');
        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }
        $this->pdo()->exec("DELETE FROM messages WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_snapshots WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_context_documents WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_verdicts WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_action_plans WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM arguments WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM agent_positions WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM interaction_edges WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_votes WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM session_decisions WHERE session_id = " . $this->pdo()->quote($id));
        $this->pdo()->exec("DELETE FROM sessions WHERE id = " . $this->pdo()->quote($id));
        return ['success' => true, 'deleted_id' => $id];
    }

    public function deleteAll(Request $req): array {
        $this->pdo()->exec("DELETE FROM messages");
        $this->pdo()->exec("DELETE FROM session_snapshots");
        $this->pdo()->exec("DELETE FROM session_context_documents");
        $this->pdo()->exec("DELETE FROM session_verdicts");
        $this->pdo()->exec("DELETE FROM session_action_plans");
        $this->pdo()->exec("DELETE FROM arguments");
        $this->pdo()->exec("DELETE FROM agent_positions");
        $this->pdo()->exec("DELETE FROM interaction_edges");
        $this->pdo()->exec("DELETE FROM session_votes");
        $this->pdo()->exec("DELETE FROM session_decisions");
        $this->pdo()->exec("DELETE FROM sessions");
        return ['success' => true];
    }

    public function updateStatus(Request $req): array {
        $id     = $req->param('id');
        $data   = $req->body();
        $status = $data['status'] ?? 'completed';
        $this->pdo()->exec(
            "UPDATE sessions SET status = " . $this->pdo()->quote($status) .
            ", updated_at = " . $this->pdo()->quote(date('c')) .
            " WHERE id = " . $this->pdo()->quote($id)
        );
        return ['success' => true];
    }

    private function pdo(): \PDO {
        return \Infrastructure\Persistence\Database::getInstance()->pdo();
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

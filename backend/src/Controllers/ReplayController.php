<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;
use Infrastructure\Persistence\ActionPlanRepository;

class ReplayController {
    private SessionRepository   $sessionRepo;
    private MessageRepository   $messageRepo;
    private DebateRepository    $debateRepo;
    private VoteRepository      $voteRepo;
    private ActionPlanRepository $actionPlanRepo;

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->messageRepo    = new MessageRepository();
        $this->debateRepo     = new DebateRepository();
        $this->voteRepo       = new VoteRepository();
        $this->actionPlanRepo = new ActionPlanRepository();
    }

    public function show(Request $req): array {
        $id = $req->param('id');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $events = [];

        // 1. Messages
        $messages = $this->messageRepo->findBySession($id);
        foreach ($messages as $msg) {
            $agentId = $msg['agent_id'] ?? null;
            $phase   = $msg['phase'] ?? '';
            $round   = (int)($msg['round'] ?? 0);
            $content = $msg['content'] ?? '';

            $voteMeta = $this->extractVoteMeta($content);

            $events[] = [
                'id'              => $msg['id'],
                'timestamp'       => $msg['created_at'] ?? '',
                'round'           => $round,
                'phase'           => $phase,
                'type'            => 'message',
                'agent_id'        => $agentId,
                'target_agent_id' => $msg['target_agent_id'] ?? null,
                'title'           => $this->formatTitle($agentId, $phase),
                'content'         => mb_substr($content, 0, 500),
                'metadata'        => [
                    'vote'          => $voteMeta['vote'],
                    'confidence'    => $voteMeta['confidence'],
                    'relation_type' => null,
                    'message_type'  => $msg['message_type'] ?? null,
                    'mode_context'  => $msg['mode_context'] ?? null,
                ],
            ];
        }

        // 2. Interaction edges
        $edges = $this->debateRepo->findEdgesBySession($id);
        foreach ($edges as $edge) {
            $source = $edge['source_agent_id'] ?? 'unknown';
            $target = $edge['target_agent_id'] ?? 'unknown';
            $type   = $edge['edge_type'] ?? 'neutral';
            $events[] = [
                'id'              => $edge['id'],
                'timestamp'       => $edge['created_at'] ?? '',
                'round'           => (int)($edge['round'] ?? 0),
                'phase'           => 'interaction',
                'type'            => 'interaction',
                'agent_id'        => $source,
                'target_agent_id' => $target,
                'title'           => ucfirst($source) . ' ' . $type . 's ' . ucfirst($target),
                'content'         => ucfirst($source) . ' ' . $type . 's ' . ucfirst($target),
                'metadata'        => [
                    'vote'          => null,
                    'confidence'    => null,
                    'relation_type' => $type,
                ],
            ];
        }

        // 3. Votes
        $votes = $this->voteRepo->findVotesBySession($id);
        foreach ($votes as $vote) {
            $agentId = $vote['agent_id'] ?? 'unknown';
            $events[] = [
                'id'              => $vote['id'],
                'timestamp'       => $vote['created_at'] ?? '',
                'round'           => (int)($vote['round'] ?? 0),
                'phase'           => 'vote',
                'type'            => 'vote',
                'agent_id'        => $agentId,
                'target_agent_id' => null,
                'title'           => ucfirst($agentId) . ' — final vote: ' . ($vote['vote'] ?? '?'),
                'content'         => ($vote['rationale'] ?? ''),
                'metadata'        => [
                    'vote'          => $vote['vote'] ?? null,
                    'confidence'    => (int)($vote['confidence'] ?? 0),
                    'impact'        => (int)($vote['impact'] ?? 0),
                    'domain_weight' => (int)($vote['domain_weight'] ?? 0),
                    'weight_score'  => (float)($vote['weight_score'] ?? 0),
                    'relation_type' => null,
                ],
            ];
        }

        // 4. Automatic decision
        $decision = $this->voteRepo->findDecisionBySession($id);
        if ($decision) {
            $events[] = [
                'id'              => $decision['id'],
                'timestamp'       => $decision['created_at'] ?? '',
                'round'           => 0,
                'phase'           => 'decision',
                'type'            => 'decision',
                'agent_id'        => null,
                'target_agent_id' => null,
                'title'           => 'Automatic decision: ' . ($decision['decision_label'] ?? '?'),
                'content'         => 'Decision: ' . ($decision['decision_label'] ?? '') . ' (score: ' . round((float)($decision['decision_score'] ?? 0), 2) . ', confidence: ' . ($decision['confidence_level'] ?? '') . ')',
                'metadata'        => [
                    'vote'           => $decision['decision_label'] ?? null,
                    'confidence'     => null,
                    'relation_type'  => null,
                    'decision_score' => (float)($decision['decision_score'] ?? 0),
                    'threshold_used' => (float)($decision['threshold_used'] ?? 0),
                ],
            ];
        }

        // 5. Action plan
        $actionPlan = $this->actionPlanRepo->findBySession($id);
        if ($actionPlan) {
            $events[] = [
                'id'              => $actionPlan['id'],
                'timestamp'       => $actionPlan['created_at'] ?? '',
                'round'           => 0,
                'phase'           => 'action_plan',
                'type'            => 'action_plan',
                'agent_id'        => null,
                'target_agent_id' => null,
                'title'           => 'Action Plan generated',
                'content'         => mb_substr($actionPlan['summary'] ?? '', 0, 500),
                'metadata'        => [
                    'vote'          => null,
                    'confidence'    => null,
                    'relation_type' => null,
                ],
            ];
        }

        // Sort all events by timestamp ASC
        usort($events, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        return [
            'session_id' => $id,
            'events'     => $events,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function extractVoteMeta(string $content): array {
        $vote       = null;
        $confidence = null;

        // Look for ## Vote section
        if (preg_match('/##\s*Vote\s*\n+\s*(go|no-go|reduce-scope|needs-more-info|pivot)/im', $content, $m)) {
            $vote = trim($m[1]);
        }
        if (preg_match('/##\s*Confidence\s*\n+\s*(\d+)/im', $content, $m)) {
            $confidence = (int)$m[1];
        }

        return ['vote' => $vote, 'confidence' => $confidence];
    }

    private function formatTitle(?string $agentId, string $phase): string {
        $agent = $agentId ? ucfirst($agentId) : 'Agent';
        $phaseLabel = match (true) {
            $phase === 'jury-opening'           => 'opening statement',
            $phase === 'jury-cross-examination' => 'cross-examination',
            str_starts_with($phase, 'jury-deliberation') => 'deliberation',
            $phase === 'jury-verdict'           => 'committee verdict',
            $phase === 'synthesis'              => 'synthesis',
            str_starts_with($phase, 'round-')  => str_replace('round-', 'round ', $phase),
            $phase !== ''                       => $phase,
            default                             => 'contribution',
        };
        return $agent . ' — ' . $phaseLabel;
    }
}

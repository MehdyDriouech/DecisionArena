<?php
namespace Controllers;

use Domain\DecisionReliability\DecisionReliabilityService;
use Domain\DecisionReliability\ReliabilityConfig;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SnapshotRepository;
use Infrastructure\Persistence\VerdictRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\ActionPlanRepository;
use Infrastructure\Persistence\DebateRepository;
use Infrastructure\Persistence\VoteRepository;
use Infrastructure\Persistence\ProviderRoutingSettingsRepository;
use Infrastructure\Persistence\ConfidenceTimelineRepository;
use Infrastructure\Persistence\PersonaScoreRepository;
use Infrastructure\Persistence\BiasReportRepository;
use Domain\Orchestration\DebateMemoryService;

class ExportController {
    private SessionRepository         $sessionRepo;
    private MessageRepository         $messageRepo;
    private SnapshotRepository        $snapshotRepo;
    private VerdictRepository         $verdictRepo;
    private ContextDocumentRepository $docRepo;
    private ActionPlanRepository      $planRepo;
    private DebateRepository          $debateRepo;
    private VoteRepository            $voteRepo;
    private ProviderRoutingSettingsRepository $providerRoutingRepo;
    private DebateMemoryService       $debateMemory;
    private DecisionReliabilityService $reliabilityService;
    private ConfidenceTimelineRepository $timelineRepo;
    private PersonaScoreRepository $personaScoreRepo;
    private BiasReportRepository $biasRepo;

    public function __construct() {
        $this->sessionRepo  = new SessionRepository();
        $this->messageRepo  = new MessageRepository();
        $this->snapshotRepo = new SnapshotRepository();
        $this->verdictRepo  = new VerdictRepository();
        $this->docRepo      = new ContextDocumentRepository();
        $this->planRepo     = new ActionPlanRepository();
        $this->debateRepo   = new DebateRepository();
        $this->voteRepo     = new VoteRepository();
        $this->providerRoutingRepo = new ProviderRoutingSettingsRepository();
        $this->debateMemory = new DebateMemoryService();
        $this->reliabilityService = new DecisionReliabilityService();
        $this->timelineRepo = new ConfidenceTimelineRepository();
        $this->personaScoreRepo = new PersonaScoreRepository();
        $this->biasRepo = new BiasReportRepository();
    }

    public function export(Request $req): array {
        $id     = $req->param('id');
        $format = $req->get('format', 'markdown');

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages   = $this->messageRepo->findBySession($id);
        $contextDoc = $this->docRepo->findBySession($id);
        $arguments  = $this->debateRepo->findArgumentsBySession($id);
        $positions  = $this->debateRepo->findPositionsBySession($id);
        $edges      = $this->debateRepo->findEdgesBySession($id);
        $votes      = $this->voteRepo->findVotesBySession($id);
        $decision   = $this->voteRepo->findDecisionBySession($id);
        $threshold  = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        $objective  = (string)($session['initial_prompt'] ?? '');
        $timelineRows = $this->timelineRepo->findBySession($id);
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $decision,
            $votes,
            $positions,
            $edges,
            $threshold,
            $timelineRows ? ['rounds' => $timelineRows] : null,
            $this->personaScoreRepo->findBySession($id),
            $this->biasRepo->findBySession($id)
        );

        $actionPlan = $this->planRepo->findBySession($id);

        if ($format === 'json') {
            $verdict  = $this->verdictRepo->findBySession($id);
            $routing  = null;
            try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
            $debateState = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];
            return [
                'format'               => 'json',
                'session'              => $session,
                'messages'             => $messages,
                'verdict'              => $verdict,
                'context_document'     => $contextDoc,
                'provider_routing'     => $routing,
                'arguments'            => $arguments,
                'edges'                => $edges,
                'positions'            => $positions,
                'votes'                => $votes,
                'decision'             => $decision,
                'automatic_decision'   => $decision,
                'raw_decision'         => $reliability['raw_decision'],
                'adjusted_decision'    => $reliability['adjusted_decision'],
                'context_quality'      => $reliability['context_quality'],
                'reliability_cap'      => $reliability['reliability_cap'],
                'false_consensus_risk' => $reliability['false_consensus_risk'],
                'false_consensus'      => $reliability['false_consensus'],
                'reliability_warnings' => $reliability['reliability_warnings'],
                'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
                'context_clarification' => $reliability['context_clarification'] ?? null,
                'weighted_analysis'    => $this->debateMemory->buildWeightedAnalysis($debateState),
                'dominance_indicator'  => $this->debateMemory->buildDominanceIndicator($debateState),
                'action_plan'          => $actionPlan,
                'memory'               => [
                    'decision_taken'   => $session['decision_taken']    ?? null,
                    'user_learnings'   => $session['user_learnings']    ?? null,
                    'follow_up_notes'  => $session['follow_up_notes']   ?? null,
                ],
                'filename'             => 'session-' . $id . '.json',
            ];
        }

        $routing = null;
        try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
        $content = $this->buildMarkdown($session, $messages, $contextDoc, $actionPlan, $arguments, $positions, $edges, $votes, $decision, $routing, $reliability);
        return [
            'format'   => 'markdown',
            'content'  => $content,
            'filename' => 'session-' . $id . '.md',
        ];
    }

    public function snapshot(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();

        $session = $this->sessionRepo->findById($id);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $messages   = $this->messageRepo->findBySession($id);
        $contextDoc = $this->docRepo->findBySession($id);
        $arguments  = $this->debateRepo->findArgumentsBySession($id);
        $positions  = $this->debateRepo->findPositionsBySession($id);
        $edges      = $this->debateRepo->findEdgesBySession($id);
        $votes      = $this->voteRepo->findVotesBySession($id);
        $decision   = $this->voteRepo->findDecisionBySession($id);
        $threshold  = ReliabilityConfig::normalizeThreshold($session['decision_threshold'] ?? null);
        $objective  = (string)($session['initial_prompt'] ?? '');
        $timelineRows = $this->timelineRepo->findBySession($id);
        $reliability = $this->reliabilityService->buildEnvelope(
            $objective,
            $contextDoc,
            $decision,
            $votes,
            $positions,
            $edges,
            $threshold,
            $timelineRows ? ['rounds' => $timelineRows] : null,
            $this->personaScoreRepo->findBySession($id),
            $this->biasRepo->findBySession($id)
        );
        $routing     = null;
        try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
        $debateState = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];
        $md          = $this->buildMarkdown($session, $messages, $contextDoc, null, $arguments, $positions, $edges, $votes, $decision, $routing, $reliability);
        $json        = json_encode([
            'session'             => $session,
            'messages'            => $messages,
            'context_document'    => $contextDoc,
            'provider_routing'    => $routing,
            'arguments'           => $arguments,
            'edges'               => $edges,
            'positions'           => $positions,
            'votes'               => $votes,
            'decision'            => $decision,
            'automatic_decision'  => $decision,
            'raw_decision'        => $reliability['raw_decision'],
            'adjusted_decision'   => $reliability['adjusted_decision'],
            'context_quality'     => $reliability['context_quality'],
            'reliability_cap'     => $reliability['reliability_cap'],
            'false_consensus_risk'=> $reliability['false_consensus_risk'],
            'false_consensus'     => $reliability['false_consensus'],
            'reliability_warnings'=> $reliability['reliability_warnings'],
            'decision_reliability_summary' => $reliability['decision_reliability_summary'] ?? null,
            'context_clarification' => $reliability['context_clarification'] ?? null,
            'weighted_analysis'   => $this->debateMemory->buildWeightedAnalysis($debateState),
            'dominance_indicator' => $this->debateMemory->buildDominanceIndicator($debateState),
            'action_plan'         => null,
            'memory'              => [
                'decision_taken'  => $session['decision_taken']  ?? null,
                'user_learnings'  => $session['user_learnings']  ?? null,
                'follow_up_notes' => $session['follow_up_notes'] ?? null,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $title      = trim($data['title'] ?? '') ?: ($session['title'] . ' — ' . date('Y-m-d H:i'));

        $snapshot = $this->snapshotRepo->create([
            'id'               => $this->uuid(),
            'session_id'       => $id,
            'title'            => $title,
            'content_markdown' => $md,
            'content_json'     => $json,
            'created_at'       => date('c'),
        ]);

        return ['success' => true, 'snapshot' => $snapshot];
    }

    private function buildMarkdown(
        array  $session,
        array  $messages,
        ?array $contextDoc        = null,
        ?array $actionPlan        = null,
        array  $arguments         = [],
        array  $positions         = [],
        array  $edges             = [],
        array  $votes             = [],
        ?array $automaticDecision = null,
        ?array $routing           = null,
        ?array $reliability       = null
    ): string {
        $agents = is_array($session['selected_agents'])
            ? implode(', ', $session['selected_agents'])
            : ($session['selected_agents'] ?? '');

        $md  = "# Decision Arena Export\n\n";

        // ── 1. Session Summary ────────────────────────────────────────────
        $md .= "## 1. Session Summary\n\n";
        $md .= "- **Title:** " . ($session['title'] ?? 'Session') . "\n";
        $md .= "- **Mode:** "  . ($session['mode']  ?? 'chat')    . "\n";
        $md .= "- **Agents:** " . $agents . "\n";
        $md .= "- **Date:** "  . ($session['created_at'] ?? '') . "\n";
        $md .= "- **Language:** " . ($session['language'] ?? 'en') . "\n\n";

        // ── 2. Initial Problem ────────────────────────────────────────────
        $md .= "## 2. Initial Problem\n\n";
        $md .= !empty($session['initial_prompt'])
            ? $session['initial_prompt'] . "\n\n"
            : "_No initial problem recorded._\n\n";

        // ── 3. Context Document ───────────────────────────────────────────
        $md .= "## 3. Context Document\n\n";
        if ($contextDoc && !empty($contextDoc['content'])) {
            $chars = $contextDoc['character_count'] ?? mb_strlen((string)$contextDoc['content'], 'UTF-8');
            $md .= "- **Title:** "    . ($contextDoc['title']             ?? '') . "\n";
            $md .= "- **Source:** "   . ($contextDoc['source_type']       ?? 'manual') . "\n";
            $md .= "- **File:** "     . ($contextDoc['original_filename'] ?? '') . "\n";
            $md .= "- **Characters:** " . $chars . "\n\n";
            $md .= $contextDoc['content'] . "\n\n";
        } else {
            $md .= "_No context document attached._\n\n";
        }

        // ── 4. Debate Timeline ────────────────────────────────────────────
        $md .= "## 4. Debate Timeline\n\n";
        $currentRound = null;
        $currentPhase = null;
        foreach ($messages as $msg) {
            if ($msg['round'] !== null && $msg['round'] != $currentRound) {
                $currentRound = $msg['round'];
                $md .= "\n### Round " . $currentRound . "\n\n";
                $currentPhase = null;
            }
            if (!empty($msg['phase']) && $msg['phase'] !== $currentPhase) {
                $currentPhase = $msg['phase'];
                $md .= "\n#### Phase: " . ucwords(str_replace('-', ' ', $currentPhase)) . "\n\n";
            }
            if ($msg['role'] === 'user') {
                $md .= '**User:** ' . $msg['content'] . "\n\n";
            } else {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $modelInfo  = '';
                if (!empty($msg['model'])) {
                    $provider  = !empty($msg['provider_id']) ? $msg['provider_id'] . ' / ' : '';
                    $modelInfo = ' *(' . $provider . $msg['model'] . ')*';
                }
                $md .= '**' . $agentLabel . '**' . $modelInfo . " :\n\n" . $msg['content'] . "\n\n---\n\n";
            }
        }
        if (empty($messages)) {
            $md .= "_No messages recorded._\n\n";
        }

        // ── 5. Argument Memory ────────────────────────────────────────────
        $md .= "## 5. Argument Memory\n\n";
        if (!empty($arguments)) {
            $grouped = [];
            foreach ($arguments as $arg) {
                $type = $arg['argument_type'] ?? 'claim';
                $grouped[$type][] = $arg;
            }
            foreach ($grouped as $type => $items) {
                $md .= "### " . str_replace('_', ' ', ucfirst($type)) . "\n\n";
                foreach ($items as $item) {
                    $agent    = $item['agent_id']      ?? 'agent';
                    $strength = $item['strength']      ?? 1;
                    $target   = !empty($item['target_argument_id']) ? ' *(challenges another)*' : '';
                    $md .= "- [{$agent}] " . ($item['argument_text'] ?? '') . " *(strength: {$strength}){$target}*\n";
                }
                $md .= "\n";
            }
        } else {
            $md .= "_No arguments recorded._\n\n";
        }

        // ── 6. Interaction Graph ──────────────────────────────────────────
        $md .= "## 6. Interaction Graph\n\n";
        if (!empty($edges)) {
            foreach ($edges as $edge) {
                $md .= "- " . ($edge['source_agent_id'] ?? '?')
                    . " → " . ($edge['target_agent_id'] ?? '?')
                    . " (" . ($edge['edge_type'] ?? 'neutral')
                    . ", weight " . ($edge['weight'] ?? 1) . ")\n";
            }
            $md .= "\n";

            // Agent positions summary
            if (!empty($positions)) {
                $md .= "### Agent Positions\n\n";
                foreach ($positions as $pos) {
                    $md .= "- **" . ($pos['agent_id'] ?? 'agent')
                        . "** (round " . ($pos['round'] ?? '?') . "): "
                        . ($pos['stance'] ?? 'needs-more-info')
                        . " | confidence " . ($pos['confidence'] ?? 0)
                        . " | weight_score " . ($pos['weight_score'] ?? 0)
                        . (!empty($pos['main_argument']) ? "\n  - Main: " . $pos['main_argument'] : '')
                        . (!empty($pos['biggest_risk'])  ? "\n  - Risk: " . $pos['biggest_risk']  : '')
                        . "\n";
                }
                $md .= "\n";
            }
        } else {
            $md .= "_No interaction edges recorded._\n\n";
        }

        // ── 7. Weighted Votes ─────────────────────────────────────────────
        $md .= "## 7. Weighted Votes\n\n";
        if (!empty($votes)) {
            $md .= "| Agent | Vote | Weight Score | Rationale |\n";
            $md .= "|-------|------|:------------:|----------|\n";
            foreach ($votes as $vote) {
                $md .= "| " . ($vote['agent_id']     ?? 'agent') . " | "
                    .         ($vote['vote']          ?? '')       . " | "
                    .  round((float)($vote['weight_score'] ?? 0), 2) . " | "
                    .         ($vote['rationale']     ?? '')       . " |\n";
            }
            $md .= "\n";
        } else {
            $md .= "_No votes recorded._\n\n";
        }

        // ── 8. Final Decision ─────────────────────────────────────────────
        $md .= "## 8. Final Decision\n\n";
        if (!empty($automaticDecision)) {
            $label     = $automaticDecision['decision_label']    ?? 'no-consensus';
            $scorePct  = round((float)($automaticDecision['decision_score'] ?? 0) * 100, 1);
            $conf      = $automaticDecision['confidence_level']  ?? 'low';
            $threshold = round((float)($automaticDecision['threshold_used'] ?? ReliabilityConfig::DEFAULT_DECISION_THRESHOLD) * 100, 1);
            $md .= "- **Decision:** {$label}\n";
            $md .= "- **Score:** {$scorePct}%\n";
            $md .= "- **Confidence:** {$conf}\n";
            $md .= "- **Threshold:** {$threshold}%\n";

            $voteSummary = is_array($automaticDecision['vote_summary'])
                ? $automaticDecision['vote_summary']
                : (json_decode((string)($automaticDecision['vote_summary'] ?? ''), true) ?: []);
            if (!empty($voteSummary['decision_scores']) && is_array($voteSummary['decision_scores'])) {
                $md .= "- **Vote distribution:**\n";
                foreach ($voteSummary['decision_scores'] as $lbl => $sc) {
                    $md .= "  - {$lbl}: " . round((float)$sc * 100, 1) . "%\n";
                }
            }
            $md .= "\n";
        } else {
            $md .= "_No automatic decision computed._\n\n";
        }

        if (!empty($reliability)) {
            $md .= "### Decision Reliability\n\n";
            $cq = $reliability['context_quality'] ?? [];
            $adj = $reliability['adjusted_decision'] ?? [];
            $md .= "- **Context quality:** " . (($cq['level'] ?? 'unknown')) . " (" . round((float)($cq['score'] ?? 0), 2) . ")\n";
            $md .= "- **Semantic density:** " . round((float)($cq['semantic_density'] ?? 0), 2) . "\n";
            $md .= "- **Reliability cap:** " . round((float)($reliability['reliability_cap'] ?? 1), 2) . "\n";
            $md .= "- **False consensus risk:** " . ($reliability['false_consensus_risk'] ?? 'low') . "\n";
            $fc = $reliability['false_consensus'] ?? [];
            if (isset($fc['diversity_score'])) {
                $md .= "- **Argument diversity score:** " . $fc['diversity_score'] . " (low values increase risk)\n";
            }
            if (!empty($adj['final_outcome'])) {
                $md .= "- **Final outcome:** " . $adj['final_outcome'] . " (vote: " . ($adj['vote_label'] ?? $adj['decision_label'] ?? '') . ", quality: " . ($adj['decision_status'] ?? '') . ")\n";
                if (!empty($adj['legacy_decision_label'])) {
                    $md .= "- **Legacy display label:** " . $adj['legacy_decision_label'] . "\n";
                }
            } elseif (!empty($adj['decision_label'])) {
                $md .= "- **Adjusted vote label:** " . $adj['decision_label'] . " (" . round((float)($adj['decision_score'] ?? 0) * 100, 1) . "%)\n";
            }
            $rsum = $reliability['decision_reliability_summary'] ?? null;
            if (is_array($rsum)) {
                $md .= "- **Decision possible:** " . (($rsum['decision_possible'] ?? true) ? 'yes' : 'no') . "\n";
                $md .= "- **Reliability level:** " . ($rsum['reliability_level'] ?? '') . "\n";
                if (!empty($rsum['recommended_action'])) {
                    $md .= "- **Recommended action:** " . $rsum['recommended_action'] . "\n";
                }
                if (!empty($rsum['top_issues']) && is_array($rsum['top_issues'])) {
                    $md .= "- **Top issues:**\n";
                    foreach ($rsum['top_issues'] as $iss) {
                        if (is_array($iss) && isset($iss['key'])) {
                            $md .= "  - " . $iss['key'] . "\n";
                        }
                    }
                }
            }
            $cl = $reliability['context_clarification'] ?? null;
            if (is_array($cl) && !empty($cl['questions'])) {
                $md .= "- **Clarification prompts:**\n";
                foreach ($cl['questions'] as $q) {
                    if (is_array($q)) {
                        $md .= "  - " . ($q['key'] ?? '') . ": " . ($q['fallback'] ?? '') . "\n";
                    }
                }
            }
            if (!empty($reliability['reliability_warnings']) && is_array($reliability['reliability_warnings'])) {
                $md .= "- **Warnings:**\n";
                foreach ($reliability['reliability_warnings'] as $w) {
                    $md .= "  - " . $w . "\n";
                }
            }
            $md .= "\n";
        }

        // ── 9. Action Plan ────────────────────────────────────────────────
        $md .= "## 9. Action Plan\n\n";
        if ($actionPlan && !empty($actionPlan['summary'])) {
            $md .= "**Summary:** " . $actionPlan['summary'] . "\n\n";

            foreach ([
                'immediate_actions'  => 'Immediate Actions',
                'short_term_actions' => 'Short-Term Actions',
            ] as $key => $label) {
                if (!empty($actionPlan[$key])) {
                    $md .= "### {$label}\n\n";
                    foreach ($actionPlan[$key] as $a) {
                        $md .= "- **" . ($a['title'] ?? '') . "** (" . ($a['priority'] ?? '') . "): " . ($a['description'] ?? '') . "\n";
                    }
                    $md .= "\n";
                }
            }

            if (!empty($actionPlan['experiments'])) {
                $md .= "### Experiments\n\n";
                foreach ($actionPlan['experiments'] as $e) {
                    $md .= "- **" . ($e['title'] ?? '') . "**: " . ($e['hypothesis'] ?? '') . " (Success: " . ($e['success_metric'] ?? '') . ")\n";
                }
                $md .= "\n";
            }

            if (!empty($actionPlan['risks_to_monitor'])) {
                $md .= "### Risks to Monitor\n\n";
                foreach ($actionPlan['risks_to_monitor'] as $r) {
                    $md .= "- **" . ($r['risk'] ?? '') . "**: " . ($r['mitigation'] ?? '') . "\n";
                }
                $md .= "\n";
            }
        } else {
            $md .= "_No action plan generated._\n\n";
        }

        // ── 10. Provider Routing ──────────────────────────────────────────
        $md .= "## 10. Provider Routing\n\n";
        if (!empty($routing)) {
            $mode        = $routing['routing_mode']    ?? 'single-primary';
            $primaryId   = $routing['primary_provider_id'] ?? null;
            $fallbackIds = $routing['fallback_provider_ids'] ?? [];
            if (is_string($fallbackIds)) {
                $fallbackIds = json_decode($fallbackIds, true) ?: [];
            }
            $md .= "- **Mode:** {$mode}\n";
            if ($primaryId) {
                $md .= "- **Primary provider ID:** {$primaryId}\n";
            }
            if (!empty($fallbackIds)) {
                $md .= "- **Fallback provider IDs:** " . implode(', ', $fallbackIds) . "\n";
            }
            $md .= "\n";
        } else {
            $md .= "_No provider routing settings recorded._\n\n";
        }

        // ── 11. User Notes ────────────────────────────────────────────────
        $md .= "## 11. User Notes\n\n";
        $hasNotes = false;
        if (!empty($session['decision_taken'])) {
            $md .= "**Decision taken:** " . $session['decision_taken'] . "\n\n";
            $hasNotes = true;
        }
        if (!empty($session['user_learnings'])) {
            $md .= "**Learnings:** " . $session['user_learnings'] . "\n\n";
            $hasNotes = true;
        }
        if (!empty($actionPlan['owner_notes'])) {
            $md .= "**Owner notes:** " . $actionPlan['owner_notes'] . "\n\n";
            $hasNotes = true;
        }
        if (!$hasNotes) {
            $md .= "_No user notes recorded._\n\n";
        }

        return $md;
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

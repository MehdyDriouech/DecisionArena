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
use Infrastructure\Persistence\EvidenceRepository;
use Infrastructure\Persistence\RiskProfileRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Domain\Orchestration\DebateMemoryService;
use Infrastructure\Persistence\JuryAdversarialReportRepository;

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
    private EvidenceRepository $evidenceRepo;
    private RiskProfileRepository $riskRepo;
    private SessionAgentProvidersRepository  $agentProvidersRepo;
    private JuryAdversarialReportRepository  $adversarialRepo;

    public function __construct() {
        $this->sessionRepo        = new SessionRepository();
        $this->messageRepo        = new MessageRepository();
        $this->snapshotRepo       = new SnapshotRepository();
        $this->verdictRepo        = new VerdictRepository();
        $this->docRepo            = new ContextDocumentRepository();
        $this->planRepo           = new ActionPlanRepository();
        $this->debateRepo         = new DebateRepository();
        $this->voteRepo           = new VoteRepository();
        $this->providerRoutingRepo = new ProviderRoutingSettingsRepository();
        $this->debateMemory       = new DebateMemoryService();
        $this->reliabilityService = new DecisionReliabilityService();
        $this->timelineRepo       = new ConfidenceTimelineRepository();
        $this->personaScoreRepo   = new PersonaScoreRepository();
        $this->biasRepo           = new BiasReportRepository();
        $this->evidenceRepo       = new EvidenceRepository();
        $this->riskRepo           = new RiskProfileRepository();
        $this->agentProvidersRepo = new SessionAgentProvidersRepository();
        $this->adversarialRepo    = new JuryAdversarialReportRepository();
    }

    public function export(Request $req): array {
        $id            = $req->param('id');
        $format        = $req->get('format', 'markdown');
        $redactedParam = (string)($req->query('redacted') ?? $req->get('redacted', '0'));
        $redactionLevel = match(true) {
            $redactedParam === 'strong' => 'strong',
            $redactedParam === '1' || $redactedParam === 'true' || $redactedParam === 'standard' => 'standard',
            default => null,
        };

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

        $actionPlan    = $this->planRepo->findBySession($id);
        $evidenceReport = $this->evidenceRepo->findReportBySession($id);
        $evidenceClaims = $evidenceReport !== null ? $this->evidenceRepo->findClaimsBySession($id) : [];
        $riskProfile    = $this->riskRepo->findBySession($id);

        // Apply redaction
        if ($redactionLevel !== null) {
            $session    = $this->redactSession($session, $redactionLevel);
            $messages   = $this->redactMessages($messages, $redactionLevel);
            $contextDoc = $this->redactContextDoc($contextDoc, $redactionLevel);
        }

        $suffix   = $redactionLevel ? '-redacted' : '';
        $ext      = $format === 'json' ? 'json' : 'md';
        $filename = 'session-' . $id . $suffix . '.' . $ext;

        // Load jury_adversarial report: persisted table first, then recomputed fallback
        $isJurySession = ($session['mode'] ?? '') === 'jury';
        $juryAdversarial = null;
        if ($isJurySession) {
            $juryAdversarial = $this->adversarialRepo->findBySession($id);
            if ($juryAdversarial === null) {
                // Fallback for sessions created before persistence was added
                $juryAdversarial = $this->buildJuryAdversarialReport($edges, $positions, $messages);
            }
        }

        if ($format === 'json') {
            $verdict  = $this->verdictRepo->findBySession($id);
            $routing  = null;
            try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
            if ($redactionLevel !== null && $routing !== null) {
                $routing = $this->redactProviderRouting($routing, $redactionLevel);
            }
            $debateState = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];
            $payload = [
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
                'evidence_report'      => $evidenceReport,
                'evidence_claims'      => $evidenceClaims,
                'risk_profile'         => $riskProfile,
                'jury_adversarial'     => $juryAdversarial,
                'memory'               => [
                    'decision_taken'   => $session['decision_taken']    ?? null,
                    'user_learnings'   => $session['user_learnings']    ?? null,
                    'follow_up_notes'  => $session['follow_up_notes']   ?? null,
                ],
                'filename' => $filename,
            ];
            if ($redactionLevel !== null) {
                $payload['redacted']         = true;
                $payload['redaction_level']  = $redactionLevel;
            }
            return $payload;
        }

        $routing = null;
        try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
        if ($redactionLevel !== null && $routing !== null) {
            $routing = $this->redactProviderRouting($routing, $redactionLevel);
        }
        $content = $this->buildMarkdown($session, $messages, $contextDoc, $actionPlan, $arguments, $positions, $edges, $votes, $decision, $routing, $reliability, $evidenceReport, $evidenceClaims, $riskProfile, $juryAdversarial);
        if ($redactionLevel !== null) {
            $content = "<!-- redacted={$redactionLevel} -->\n\n" . $content;
        }
        return [
            'format'   => 'markdown',
            'content'  => $content,
            'filename' => $filename,
        ];
    }

    // ── Redaction helpers ────────────────────────────────────────────────────

    /** Sensitive field name patterns to mask at standard level. */
    private const SENSITIVE_KEYS = ['api_key', 'apikey', 'api-key', 'token', 'secret', 'password', 'authorization', 'auth', 'bearer'];

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $pattern) {
            if (str_contains($lower, $pattern)) return true;
        }
        return false;
    }

    private function redactSession(array $session, string $level): array
    {
        foreach ($session as $k => $v) {
            if ($this->isSensitiveKey($k)) {
                $session[$k] = '[REDACTED]';
            }
        }
        return $session;
    }

    private function redactMessages(array $messages, string $level): array
    {
        return array_map(function (array $msg) use ($level): array {
            if ($level === 'strong') {
                $msg['content'] = '[REDACTED]';
            }
            foreach ($msg as $k => $v) {
                if ($this->isSensitiveKey($k)) $msg[$k] = '[REDACTED]';
            }
            return $msg;
        }, $messages);
    }

    private function redactContextDoc(?array $doc, string $level): ?array
    {
        if ($doc === null) return null;
        if ($level === 'strong') {
            $doc['content'] = '[REDACTED]';
        }
        return $doc;
    }

    private function redactProviderRouting(?array $routing, string $level): ?array
    {
        if ($routing === null) return null;
        $redacted = [];
        foreach ($routing as $k => $v) {
            if ($this->isSensitiveKey($k)) {
                $redacted[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $inner = [];
                foreach ($v as $ik => $iv) {
                    $inner[$ik] = $this->isSensitiveKey((string)$ik) ? '[REDACTED]' : $iv;
                }
                $redacted[$k] = $inner;
            } else {
                $redacted[$k] = $v;
            }
        }
        return $redacted;
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
        $snapshotEvidence       = $this->evidenceRepo->findReportBySession($id);
        $snapshotEvidenceClaims = $snapshotEvidence !== null ? $this->evidenceRepo->findClaimsBySession($id) : [];
        $snapshotRisk           = $this->riskRepo->findBySession($id);
        $routing     = null;
        try { $routing = $this->providerRoutingRepo->get(); } catch (\Throwable $e) { $routing = null; }
        $debateState = ['arguments' => $arguments, 'positions' => $positions, 'edges' => $edges];
        $md          = $this->buildMarkdown($session, $messages, $contextDoc, null, $arguments, $positions, $edges, $votes, $decision, $routing, $reliability, $snapshotEvidence, $snapshotEvidenceClaims, $snapshotRisk);
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
            'agent_providers'     => $this->agentProvidersRepo->findBySession($id),
            'evidence_report'     => $snapshotEvidence,
            'evidence_claims'     => $snapshotEvidenceClaims,
            'risk_profile'        => $snapshotRisk,
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
        ?array $reliability       = null,
        ?array $evidenceReport    = null,
        array  $evidenceClaims    = [],
        ?array $riskProfile       = null,
        ?array $juryAdversarial   = null
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
                $agentLabel   = $msg['agent_id'] ?? 'Agent';
                $providerName = $msg['provider_name'] ?? $msg['provider_id'] ?? null;
                $model        = $msg['model'] ?? null;
                $modelInfo    = '';
                if ($model || $providerName) {
                    $parts = array_filter([$model, $providerName ? 'via ' . $providerName : null]);
                    $modelInfo = ' *(' . implode(' ', $parts) . ')*';
                }
                $fallbackNote = '';
                if (!empty($msg['provider_fallback_used'])) {
                    $reqParts = array_filter([
                        $msg['requested_model'] ?? null,
                        $msg['requested_provider_id'] ?? null,
                    ]);
                    if (!empty($reqParts)) {
                        $fallbackNote = ' ⚠ *Fallback depuis ' . implode(' / ', $reqParts) . '*';
                    }
                }
                // Reactive Chat metadata
                $reactiveMeta = '';
                if (!empty($msg['thread_type']) && $msg['thread_type'] === 'reactive_chat') {
                    $roleLbl  = ucfirst($msg['reaction_role'] ?? 'agent');
                    $turnLbl  = isset($msg['thread_turn']) ? " · Tour " . $msg['thread_turn'] : '';
                    $threadLbl = !empty($msg['reactive_thread_id']) ? ' · thread:' . substr($msg['reactive_thread_id'], 0, 8) : '';
                    $reactiveMeta = " `[RC:{$roleLbl}{$turnLbl}{$threadLbl}]`";
                }
                $md .= '**' . $agentLabel . '**' . $modelInfo . $fallbackNote . $reactiveMeta . " :\n\n" . $msg['content'] . "\n\n---\n\n";
            }
        }
        if (empty($messages)) {
            $md .= "_No messages recorded._\n\n";
        }

        // ── 4b. LLM utilisés ─────────────────────────────────────────────────
        $agentLlmMap = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') continue;
            $aid = $msg['agent_id'] ?? null;
            if (!$aid || isset($agentLlmMap[$aid])) continue;
            $agentLlmMap[$aid] = $msg;
        }
        if (!empty($agentLlmMap)) {
            $md .= "## LLM utilisés\n\n";
            $md .= "| Agent | Provider demandé | Modèle demandé | Provider utilisé | Modèle utilisé | Fallback |\n";
            $md .= "|---|---|---|---|---|---|\n";
            foreach ($agentLlmMap as $aid => $msg) {
                $reqProvider = $msg['requested_provider_id'] ?? '—';
                $reqModel    = $msg['requested_model']       ?? '—';
                $useProvider = $msg['provider_name'] ?? $msg['provider_id'] ?? '—';
                $useModel    = $msg['model']         ?? '—';
                $fallback    = !empty($msg['provider_fallback_used']) ? ('⚠ ' . ($msg['provider_fallback_reason'] ?? 'yes')) : 'non';
                $md .= "| {$aid} | {$reqProvider} | {$reqModel} | {$useProvider} | {$useModel} | {$fallback} |\n";
            }
            $md .= "\n";
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

        // ── 10. Evidence Layer ────────────────────────────────────────────
        $md .= "## 10. Evidence Assessment\n\n";
        if (!empty($evidenceReport)) {
            $evScore    = round((float)($evidenceReport['evidence_score']            ?? 1.0) * 100, 1);
            $evUnsup    = (int)($evidenceReport['unsupported_claims_count']           ?? 0);
            $evContra   = (int)($evidenceReport['contradicted_claims_count']          ?? 0);
            $evImpact   = (string)($evidenceReport['decision_impact']                ?? 'low');
            $evRec      = (string)($evidenceReport['recommendation']                 ?? '');
            $evUnknowns = (array)($evidenceReport['critical_unknowns']               ?? []);
            $md .= "- **Evidence coverage score:** {$evScore}%\n";
            $md .= "- **Unsupported claims:** {$evUnsup}\n";
            $md .= "- **Contradicted claims:** {$evContra}\n";
            $md .= "- **Decision impact of evidence gaps:** {$evImpact}\n";
            if (!empty($evUnknowns)) {
                $md .= "- **Critical unknowns:**\n";
                foreach ($evUnknowns as $u) {
                    $md .= "  - " . $u . "\n";
                }
            }
            if ($evRec !== '') {
                $md .= "- **Recommendation:** {$evRec}\n";
            }
            $md .= "\n";
            // Top unsupported claims
            $topUnsupported = array_filter($evidenceClaims, fn($c) => in_array($c['status'] ?? '', ['unsupported', 'needs_source'], true));
            if (!empty($topUnsupported)) {
                $md .= "### Top Unsupported Claims\n\n";
                foreach (array_slice(array_values($topUnsupported), 0, 5) as $c) {
                    $md .= "- [{$c['claim_type']}] {$c['claim_text']}\n";
                }
                $md .= "\n";
            }
            // Contradicted claims
            $contradictedList = array_filter($evidenceClaims, fn($c) => ($c['status'] ?? '') === 'contradicted');
            if (!empty($contradictedList)) {
                $md .= "### Contradicted Claims\n\n";
                foreach (array_values($contradictedList) as $c) {
                    $md .= "- [{$c['claim_type']}] {$c['claim_text']}\n";
                    if (!empty($c['evidence_text'])) {
                        $md .= "  _Context: " . mb_substr((string)$c['evidence_text'], 0, 200) . "_\n";
                    }
                }
                $md .= "\n";
            }
        } else {
            $md .= "_No evidence report available for this session._\n\n";
        }

        // ── 11. Risk & Reversibility ──────────────────────────────────────
        $md .= "## 11. Risk & Reversibility\n\n";
        if (!empty($riskProfile)) {
            $rl    = (string)($riskProfile['risk_level']            ?? 'unknown');
            $rev   = (string)($riskProfile['reversibility']         ?? 'unknown');
            $cost  = (string)($riskProfile['estimated_error_cost']  ?? 'unknown');
            $proc  = (string)($riskProfile['required_process']      ?? 'standard');
            $rthr  = isset($riskProfile['recommended_threshold'])
                ? round((float)$riskProfile['recommended_threshold'] * 100, 1) . '%'
                : '–';
            $cats  = (array)($riskProfile['risk_categories']        ?? []);
            $recs  = (array)($riskProfile['recommendations']        ?? []);
            $md .= "- **Risk level:** {$rl}\n";
            $md .= "- **Reversibility:** {$rev}\n";
            $md .= "- **Estimated error cost:** {$cost}\n";
            $md .= "- **Required process:** {$proc}\n";
            $md .= "- **Recommended threshold:** {$rthr}\n";
            if (!empty($cats)) {
                $md .= "- **Categories:** " . implode(', ', $cats) . "\n";
            }
            if (!empty($recs)) {
                $md .= "- **Recommendations:**\n";
                foreach ($recs as $r) {
                    $md .= "  - {$r}\n";
                }
            }
            if ($reliability !== null && isset($reliability['risk_threshold_info'])) {
                $rti = $reliability['risk_threshold_info'];
                $md .= "- **Configured threshold:** " . round((float)($rti['configured_threshold'] ?? 0) * 100, 1) . "%\n";
                $md .= "- **Risk-adjusted threshold:** " . round((float)($rti['risk_adjusted_threshold'] ?? 0) * 100, 1) . "%\n";
                if (!empty($rti['threshold_reason'])) {
                    $md .= "- **Adjustment reason:** " . $rti['threshold_reason'] . "\n";
                }
            }
            $md .= "\n";
        } else {
            $md .= "_No risk profile available for this session._\n\n";
        }

        // ── 12. Jury Adversarial Quality ──────────────────────────────────
        if ($juryAdversarial !== null) {
            $md .= "## 12. Qualité adversariale du jury\n\n";
            $jaScore   = $juryAdversarial['debate_quality_score'] ?? 0;
            $jaChalCnt = $juryAdversarial['challenge_count']      ?? 0;
            $jaChalRat = round((float)($juryAdversarial['challenge_ratio'] ?? 0) * 100, 1);
            $jaPosCh   = $juryAdversarial['position_changes']     ?? 0;
            $jaMinority = ($juryAdversarial['minority_report_present'] ?? false) ? 'yes' : 'no';
            $jaMostCh  = $juryAdversarial['most_challenged_agent'] ?? '—';
            $jaDensity = round((float)($juryAdversarial['interaction_density'] ?? 0) * 100, 1);
            $jaWarnings = (array)($juryAdversarial['warnings'] ?? []);

            $md .= "- **Debate quality score:** {$jaScore}/100\n";
            $md .= "- **Challenge count:** {$jaChalCnt} ({$jaChalRat}% of all interactions)\n";
            $md .= "- **Position changes:** {$jaPosCh}\n";
            $md .= "- **Minority report present:** {$jaMinority}\n";
            $md .= "- **Interaction density:** {$jaDensity}%\n";
            $md .= "- **Most challenged agent:** {$jaMostCh}\n";
            if (!empty($jaWarnings)) {
                $md .= "- **Adversarial warnings:** " . implode(', ', $jaWarnings) . "\n";
            }
            $posChangers = $juryAdversarial['position_changers'] ?? [];
            if (!empty($posChangers)) {
                $md .= "- **Position changers:**\n";
                foreach ($posChangers as $agent => $change) {
                    $from = is_array($change) ? ($change['from'] ?? '') : '';
                    $to   = is_array($change) ? ($change['to']   ?? '') : '';
                    $md .= "  - {$agent}: {$from} → {$to}\n";
                }
            }
            $md .= "\n";
        }

        // ── 13. Provider Routing ──────────────────────────────────────────
        $md .= "## 13. Provider Routing\n\n";
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

        // ── 14. User Notes ────────────────────────────────────────────────
        $md .= "## 14. User Notes\n\n";
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

    /**
     * Recompute jury adversarial quality report from persisted DB data.
     * No extra table needed — derived from edges, positions, and messages.
     */
    private function buildJuryAdversarialReport(array $edges, array $positions, array $messages): array {
        $totalEdges     = count($edges);
        $challengeEdges = 0;
        $challengesByTarget = [];
        foreach ($edges as $e) {
            if (($e['edge_type'] ?? '') === 'challenge') {
                $challengeEdges++;
                $tid = (string)($e['target_agent_id'] ?? '');
                if ($tid !== '') {
                    $challengesByTarget[$tid] = ($challengesByTarget[$tid] ?? 0) + 1;
                }
            }
        }
        arsort($challengesByTarget);
        $mostChallengedAgent = !empty($challengesByTarget) ? array_key_first($challengesByTarget) : null;
        $challengeRatio = $totalEdges > 0 ? round($challengeEdges / $totalEdges, 2) : 0.0;

        // Position changes
        $firstByAgent = [];
        $lastByAgent  = [];
        foreach ($positions as $pos) {
            $agentId = $pos['agent_id'] ?? '';
            $round   = (int)($pos['round'] ?? 0);
            if ($agentId === '' || $agentId === 'synthesizer') continue;
            if (!isset($firstByAgent[$agentId]) || $round < (int)($firstByAgent[$agentId]['round'] ?? PHP_INT_MAX)) {
                $firstByAgent[$agentId] = $pos;
            }
            if (!isset($lastByAgent[$agentId]) || $round >= (int)($lastByAgent[$agentId]['round'] ?? 0)) {
                $lastByAgent[$agentId] = $pos;
            }
        }
        $positionChangers = [];
        foreach ($lastByAgent as $agentId => $last) {
            $first = $firstByAgent[$agentId] ?? null;
            if ($first && ($first['stance'] ?? '') !== '' && ($last['stance'] ?? '') !== ''
                && ($first['stance'] ?? '') !== ($last['stance'] ?? '')) {
                $positionChangers[$agentId] = [
                    'from' => $first['stance'] ?? '',
                    'to'   => $last['stance'] ?? '',
                ];
            }
        }

        // Minority report present (check message phases)
        $minorityReportPresent = false;
        foreach ($messages as $msg) {
            if (($msg['phase'] ?? '') === 'jury-minority-report' || ($msg['message_type'] ?? '') === 'jury-minority-report') {
                $minorityReportPresent = true;
                break;
            }
        }

        // Unique agent pairs (interaction density)
        $agentIds = array_values(array_unique(array_filter(
            array_column($positions, 'agent_id'),
            fn($id) => !empty($id) && $id !== 'synthesizer'
        )));
        $agentCount = count($agentIds);
        $interactingPairs = $totalEdges > 0
            ? count(array_unique(array_map(
                fn($e) => min($e['source_agent_id'] ?? '', $e['target_agent_id'] ?? '')
                         . '_' .
                         max($e['source_agent_id'] ?? '', $e['target_agent_id'] ?? ''),
                $edges
              )))
            : 0;
        $maxPairs = max(1, ($agentCount * ($agentCount - 1)) / 2);
        $densityRatio = round($interactingPairs / $maxPairs, 2);

        // Quality score
        $challengeScore = (int)round($challengeRatio * 40);
        $positionScore  = min(20, count($positionChangers) * 7);
        $minorityScore  = $minorityReportPresent ? 20 : 0;
        $densityScore   = (int)round(min(1.0, $densityRatio) * 20);
        $qualityScore   = min(100, $challengeScore + $positionScore + $minorityScore + $densityScore);

        // Build warnings
        $warnings = [];
        if ($qualityScore < 50) $warnings[] = 'weak_debate_quality';
        if ($challengeRatio < 0.20) $warnings[] = 'insufficient_challenge';
        $warnings[] = 'synthesis_constrained_by_vote';

        return [
            'enabled'                 => true,
            'debate_quality_score'    => $qualityScore,
            'challenge_count'         => $challengeEdges,
            'challenge_ratio'         => $challengeRatio,
            'position_changes'        => count($positionChangers),
            'position_changers'       => $positionChangers,
            'minority_report_present' => $minorityReportPresent,
            'interaction_density'     => $densityRatio,
            'most_challenged_agent'   => $mostChallengedAgent,
            'warnings'                => $warnings,
        ];
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

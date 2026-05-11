<?php
namespace Domain\Orchestration;

use Domain\CognitiveGovernance\PromptInjectionRegistry;
use Domain\Agents\Agent;
use Domain\Agents\DecisionDynamics;
use Domain\StrategicContext\BeliefEngineService;
use Domain\StrategicContext\AgentContextMemoryService;
use Infrastructure\Markdown\MarkdownFileLoader;
use Infrastructure\Persistence\ContextDocumentChunkRepository;
use Infrastructure\Persistence\StrategicContextRepository;

class PromptBuilder {
    /** Upper bound aligned with ContextDocumentController (storage). */
    public const MAX_CONTEXT_STORAGE_CHARS = 50000;
    /** Max characters injected into model prompts (UTF-8); rest truncated with flag. */
    public const MAX_CONTEXT_INJECT_CHARS = 32000;

    private string $storageDir;
    private MarkdownFileLoader $loader;
    private PlaybookRuntime $playbookRuntime;

    private ?AgentContextMemoryService $agentContextMemoryService = null;
    private ?BeliefEngineService $beliefEngineService = null;
    private ?StrategicContextRepository $strategicContextRepository = null;

    /** @var array<string, string> strategic context id -> prepared prompt block */
    private array $strategicContextPromptCache = [];

    /** @var array<string, mixed> Metadata from last buildContextDocumentContent FTS step (merged into prompt logs). */
    private array $lastRetrievalLogMeta = [];

    /** @var array<string, list<array{id:int,chunk_index:int,content:string,rank:float}>> */
    private static array $ftsRetrievalResultCache = [];

    private const FTS_CACHE_MAX_ENTRIES = 64;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../../storage';
        $this->loader     = new MarkdownFileLoader($this->storageDir);
        $this->playbookRuntime = new PlaybookRuntime();
    }

    /**
     * Adds prompt injection fields without mutating stored document text.
     * - content: full text from DB (for evidence / risk / exports)
     * - prompt_content: optional slice passed to the model via buildContextDocumentContent()
     *
     * @param ?array<string,mixed> $doc
     * @return ?array<string,mixed>
     */
    public function prepareContextDocumentForPrompt(?array $doc): ?array {
        if ($doc === null) {
            return null;
        }
        $content = (string)($doc['content'] ?? '');
        if ($content === '') {
            return $doc;
        }
        $charset = 'UTF-8';
        $storageChars = (int)($doc['character_count'] ?? mb_strlen($content, $charset));
        if ($storageChars !== mb_strlen($content, $charset)) {
            $storageChars = mb_strlen($content, $charset);
        }
        $hash      = md5($content);
        $max       = self::MAX_CONTEXT_INJECT_CHARS;
        $out       = array_merge($doc, [
            'context_truncated'      => false,
            'context_injected_chars' => $storageChars,
            'context_hash'           => $hash,
            'context_storage_chars'  => $storageChars,
        ]);
        if (mb_strlen($content, $charset) > $max) {
            $promptBody = mb_substr($content, 0, $max, $charset)
                . "\n\n[NOTICE: Context truncated for model prompt. Full document: {$storageChars} chars; injected: {$max} chars.]";
            $out['prompt_content']         = $promptBody;
            $out['context_truncated']      = true;
            $out['context_injected_chars']   = mb_strlen($promptBody, $charset);
        }
        return $out;
    }

    /**
     * System-level evidence discipline (Phase 1 — all orchestration modes).
     */
    public function buildEvidenceDisciplineSystemBlock(): string {
        return <<<'TEXT'

---
## Evidence discipline (shared context)

Use an evidence-first posture without becoming bureaucratic:

- distinguish facts, assumptions, signals, intuitions, and unknowns when it matters
- name critical unknowns that could change the decision
- challenge weak evidence and overconfident claims
- keep the answer natural; do not turn every response into a form

If a claim is not supported by the **Shared Context Document** in this task:

- explicitly label it as **unsupported**
- do NOT rely on prior knowledge as a substitute for missing context
- do NOT fabricate citations

When a "## Retrieved excerpts" section appears in the user message, you may reference those rows as **[E1], [E2], …** only when the cited text is clearly relevant; you are not required to cite in every sentence.

TEXT;
    }

    /**
     * Lightweight debate discipline shared by personas.
     *
     * This is behavioral guidance, not a rigid response template. It keeps
     * agents useful and adversarial without forcing a checklist shape.
     */
    public function buildArgumentDisciplineSystemBlock(): string {
        return <<<'TEXT'

---
## Argument discipline

Keep your persona's point of view, but make the reasoning decision-useful:

- separate observation, assumption, inference, and recommendation when the distinction matters
- challenge weak claims, including attractive claims from your own side
- make disagreement explicit when it changes the decision; do not manufacture consensus
- avoid repeating another agent unless you add new evidence, a sharper trade-off, or a changed conclusion
- name the critical unknown that would most change your vote
- end with the decision implication: proceed, constrain, validate first, pivot, or stop

Be concise. Prefer one strong objection over several generic concerns.

TEXT;
    }

    public function buildPlaybookDebateDisciplineBlock(?string $playbookId, string $language = 'en'): string {
        $rules = match ($playbookId) {
            'stress-test' => [
                'Attack the riskiest assumption, not the easiest objection.',
                'Look for concrete failure modes and hidden dependencies.',
                'Prefer kill/pivot criteria and de-risking tests over broad warnings.',
            ],
            'jury' => [
                'Arbitrate between options with explicit criteria.',
                'Surface minority arguments instead of smoothing them away.',
                'Name what would make the recommendation reliable enough to act on.',
            ],
            'founder-sprint' => [
                'Push toward market validation, not founder preference.',
                'Challenge ICP, wedge, acquisition path, and urgency signals.',
                'Prefer the smallest next experiment and a clear kill criterion.',
            ],
            'ceo-challenge' => [
                'Challenge strategic assumptions, timing, moat, and execution capacity.',
                'Make leadership trade-offs explicit.',
                'Separate ambition from operational readiness.',
            ],
            'confrontation' => [
                'Preserve the strongest version of both sides before synthesizing.',
                'Name the real conflict point instead of splitting the difference.',
                'Convert disagreement into a decision path or test.',
            ],
            'quick-decision' => [
                'Prioritize the constraint, immediate action, and main risk.',
                'Do not over-explain; identify what is good enough to decide now.',
                'Use validate-first when the missing information blocks execution.',
            ],
            default => [],
        };
        if ($rules === []) {
            return '';
        }

        $title = $language === 'fr'
            ? "## Discipline argumentative du playbook"
            : "## Playbook debate discipline";
        $body = implode("\n", array_map(fn($line) => "- {$line}", $rules));
        return "\n\n---\n\n{$title}\n{$body}\n";
    }

    public function buildRepetitionReductionBlock(array $previousMessages, string $language = 'en'): string {
        if ($previousMessages === []) {
            return '';
        }
        return "\n\n---\n\n## Repetition guard\n"
            . "- Do not restate the prior debate summary.\n"
            . "- Add only one of: a new objection, a sharper trade-off, a concrete validation test, or a changed vote.\n"
            . "- If you agree with a prior agent, name the specific claim and add the condition or limit.\n"
            . "- Keep the response shorter when your position did not materially change.\n";
    }

    /**
     * @param ?array<string,mixed> $contextDoc
     * @return array{context_injected_chars:int,context_truncated:bool,context_hash:?string}
     */
    private function contextPromptLogMeta(?array $contextDoc): array {
        if (!$contextDoc || ((string)($contextDoc['content'] ?? '') === '' && ($contextDoc['prompt_content'] ?? '') === '')) {
            return [
                'context_injected_chars' => 0,
                'context_truncated'      => false,
                'context_hash'           => null,
            ];
        }
        return [
            'context_injected_chars' => (int)($contextDoc['context_injected_chars'] ?? 0),
            'context_truncated'      => (bool)($contextDoc['context_truncated'] ?? false),
            'context_hash'           => $contextDoc['context_hash'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function ftsRetrievalPromptLogMeta(): array {
        return $this->lastRetrievalLogMeta;
    }

    /** Mémoire markdown par (strategic_context_id, agent_id) — vide si legacy ou invalide. */
    private function agentContextMemoryBlock(?string $strategicContextUuid, string $agentId): string {
        if ($strategicContextUuid === null || trim($strategicContextUuid) === '') {
            return '';
        }
        $this->agentContextMemoryService ??= new AgentContextMemoryService();
        return $this->agentContextMemoryService->buildPromptInjectionBlock($strategicContextUuid, $agentId);
    }

    /**
     * Inject explicit strategic context orientation written by the user/admin.
     * Reuses strategic_contexts.description (no extra schema).
     */
    private function strategicContextGuidanceBlock(?string $strategicContextId): string
    {
        $ctxId = is_string($strategicContextId) ? trim($strategicContextId) : '';
        if ($ctxId === '') {
            return '';
        }
        if (array_key_exists($ctxId, $this->strategicContextPromptCache)) {
            return $this->strategicContextPromptCache[$ctxId];
        }

        $this->strategicContextRepository ??= new StrategicContextRepository();
        $ctx = $this->strategicContextRepository->find($ctxId);
        if (!is_array($ctx)) {
            $this->strategicContextPromptCache[$ctxId] = '';
            return '';
        }
        $title = trim((string)($ctx['title'] ?? ''));
        $description = trim((string)($ctx['description'] ?? ''));
        if ($description === '') {
            $this->strategicContextPromptCache[$ctxId] = '';
            return '';
        }

        $block = "\n\n## Strategic Context Guidance\n";
        if ($title !== '') {
            $block .= "Context: {$title}\n";
        }
        $block .= "Use this orientation as a hard decision frame unless directly contradicted by stronger evidence:\n";
        $block .= $description . "\n";

        $this->strategicContextPromptCache[$ctxId] = $block;
        return $block;
    }

    public function buildChatMessages(
        Agent $agent,
        string $sessionContext,
        array $conversationHistory,
        string $userMessage,
        string $language = 'en',
        ?array $contextDoc = null,
        ?string $retrievalSessionId = null,
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'chat', $language);
        $contextPrefix = $this->buildContextDocumentContent(
            $contextDoc,
            $retrievalSessionId,
            $sessionContext,
            $userMessage
        );
        $memBlock = $this->agentContextMemoryBlock($strategicContextIdForAgentMemory, $agent->id);
        $ctxBlock = $this->strategicContextGuidanceBlock($strategicContextIdForAgentMemory);
        $userContent   = $contextPrefix . $ctxBlock . $memBlock . $this->buildUserContent($sessionContext, $conversationHistory, $userMessage, null);

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    public function buildDecisionRoomMessages(
        Agent $agent,
        string $objective,
        array $previousRoundMessages,
        int $round,
        int $totalRounds,
        string $language = 'en',
        bool $forceDisagreement = false,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        ?string $assignedTarget = null,
        ?string $socialDynamicsBlock = null,
        bool $forceStrongContradictionNext = false,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null,
        string $sessionVariant = '',
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'decision-room', $language);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('decision-room', [], $objective);

        if ($sessionVariant === 'premortem') {
            $premortemPolicy = $this->loadPrompt('pre_mortem');
            if ($premortemPolicy !== null && $premortemPolicy !== '') {
                $systemContent .= "\n\n---\n" . $premortemPolicy;
            }
        }

        $roundPolicy      = new RoundPolicy();
        $roundInstruction = $roundPolicy->getRoundInstruction($round, $totalRounds, $forceStrongContradictionNext);

        $userContent = '';

        $seg = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $this->appendDecisionRoomSegmentBudgeted(
            $userContent,
            'context_document',
            'context_layer',
            $seg,
            'context_doc_or_fts_retrieval_policy',
            ['playbook_id' => $playbookId],
            true
        );

        $seg = "**Objective:** $objective\n\n";
        $this->appendDecisionRoomSegmentBudgeted($userContent, 'objective', 'task', $seg, 'required_session_objective', [], false);

        $seg = $this->strategicContextGuidanceBlock($strategicContextIdForAgentMemory);
        if ($seg !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'strategic_context_guidance',
                'strategic_context',
                $seg,
                'active_or_selected_strategic_context_description',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('strategic_context_guidance', 'strategic_context', 'no_context_or_empty_description');
        }

        $seg = $this->playbookRuntime->buildPromptBlock($playbookId, $language);
        $this->appendDecisionRoomSegmentBudgeted($userContent, 'playbook_block', 'playbook_runtime', $seg, 'resolved_playbook_block', [], false);

        $seg = $this->buildPlaybookDebateDisciplineBlock($playbookId, $language);
        $this->appendDecisionRoomSegmentBudgeted($userContent, 'debate_discipline', 'governance_layer', $seg, 'playbook_debate_discipline', [], false);

        if (!empty($previousRoundMessages)) {
            $seg = "**Previous Round Contributions:**\n";
            foreach ($previousRoundMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $seg .= "\n**[$agentLabel]:** {$msg['content']}\n";
            }
            $seg .= "\n";
            $seg .= $this->buildRepetitionReductionBlock($previousRoundMessages, $language);
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'previous_rounds',
                'debate_transcript',
                $seg,
                'prior_round_messages_and_repetition_guard',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('previous_rounds', 'debate_transcript', 'no_prior_round_messages');
        }

        if (!empty($memoryContext['argument_memory_summary'])) {
            $seg = "# Argument Memory (summary)\n\n";
            $seg .= $memoryContext['argument_memory_summary'] . "\n\n";
            $seg .= "Instructions:\n";
            $seg .= "- Do not repeat existing arguments unless refining them.\n";
            $seg .= "- Challenge or extend existing arguments.\n";
            $seg .= "- Refer explicitly to previous arguments when relevant.\n\n";
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'argument_memory',
                'debate_memory',
                $seg,
                'debate_memory_state_summary_injected',
                [],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('argument_memory', 'debate_memory', 'no_argument_memory_summary_in_state');
        }

        $socialPolicy = $this->loadPrompt('social-dynamics-policy') ?? '';
        if ($socialPolicy !== '') {
            $seg = "---\n" . $socialPolicy . "\n---\n\n";
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'social_policy',
                'social_governance',
                $seg,
                'static_prompt_file_social_dynamics_policy',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('social_policy', 'social_governance', 'prompt_file_missing_or_empty');
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'social_dynamics_block',
                'social_runtime',
                $socialDynamicsBlock,
                'runner_injected_social_context_block',
                [],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('social_dynamics_block', 'social_runtime', 'runner_passed_empty_or_null_social_block');
        }

        $seg = $this->agentContextMemoryBlock($strategicContextIdForAgentMemory, $agent->id);
        if ($seg !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'agent_context_memory',
                'situated_memory',
                $seg,
                'agent_memory_md_or_service_block',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('agent_context_memory', 'situated_memory', 'no_strategic_context_or_empty_agent_memory');
        }

        $beliefsRuntime = $this->buildBeliefsRuntimeSegments($strategicContextIdForAgentMemory, true);
        if ($beliefsRuntime['prioritized'] !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'beliefs_prioritized',
                'beliefs',
                $beliefsRuntime['prioritized'],
                'runtime_beliefs_prioritized',
                [],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('beliefs_prioritized', 'beliefs', 'no_prioritized_beliefs_or_no_context');
        }
        if ($beliefsRuntime['contested'] !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'beliefs_contested',
                'beliefs',
                $beliefsRuntime['contested'],
                'runtime_beliefs_contested',
                [],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('beliefs_contested', 'beliefs', 'no_contested_beliefs_or_no_context');
        }
        if ($beliefsRuntime['fragile'] !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'beliefs_fragile_assumptions',
                'beliefs',
                $beliefsRuntime['fragile'],
                'runtime_beliefs_fragile_assumptions',
                [],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('beliefs_fragile_assumptions', 'beliefs', 'no_fragile_beliefs_or_no_context');
        }
        if ($beliefsRuntime['invalidated'] !== '') {
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'beliefs_invalidated_optional',
                'beliefs',
                $beliefsRuntime['invalidated'],
                'runtime_beliefs_invalidated_optional',
                ['governance_visibility' => 'always_visible_low_priority'],
                true
            );
        } else {
            $this->traceDecisionRoomSkipped('beliefs_invalidated_optional', 'beliefs', 'no_invalidated_beliefs_or_no_context');
        }

        $seg = "**Your Task:** $roundInstruction\n\n";
        $this->appendDecisionRoomSegmentBudgeted($userContent, 'round_instruction', 'orchestration', $seg, 'round_policy_instruction', [], false);

        if ($round > 1 && $agent->id !== 'synthesizer') {
            $seg = $this->buildTargetAgentHint($agent->id, $previousRoundMessages, $assignedTarget);
            $seg .= $this->buildInteractionContractBlock($assignedTarget !== null);
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'interaction_contract',
                'orchestration',
                $seg,
                'target_hint_and_interaction_contract',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('interaction_contract', 'orchestration', 'synthesizer_or_round_one');
        }

        $seg = 'Use your default response format.';
        $seg .= $this->buildWeightedOpinionInstruction();
        $this->appendDecisionRoomSegmentBudgeted(
            $userContent,
            'response_format',
            'instruction',
            $seg,
            'default_format_plus_weighted_opinion',
            [],
            false
        );

        if ($forceDisagreement) {
            $mode = $agent->id === 'synthesizer' ? 'synthesizer' : 'default';
            $seg = $this->buildForcedDisagreementInstruction($mode);
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'forced_disagreement',
                'governance_layer',
                $seg,
                'session_option_force_disagreement',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('forced_disagreement', 'governance_layer', 'force_disagreement_false');
        }

        if ($agent->id !== 'synthesizer' && $round === $totalRounds) {
            $seg = $this->buildFinalVoteInstruction();
            $this->appendDecisionRoomSegmentBudgeted(
                $userContent,
                'final_vote',
                'vote',
                $seg,
                'final_round_non_synthesizer_vote_schema',
                [],
                false
            );
        } else {
            $this->traceDecisionRoomSkipped('final_vote', 'vote', 'not_final_non_synth_agent_or_synthesizer');
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    /**
     * Append segment message utilisateur DR : CognitiveBudgetEngine (si trace active) + dédup optionnelle + trace.
     *
     * @param array<string, mixed> $extra
     */
    private function appendDecisionRoomSegmentBudgeted(
        string &$userContent,
        string $blockId,
        string $cognitiveLayer,
        string $segment,
        string $inclusionReason,
        array $extra = [],
        bool $useDedup = false
    ): void {
        $dk = PromptInjectionRegistry::deduplicationKeyForBlockId($blockId);
        if ($useDedup && $dk !== null && $segment !== '' && PromptInjectionTraceCollector::isDuplicateSegment($dk, $segment, $blockId, $cognitiveLayer)) {
            return;
        }
        $work = $segment;
        $budgetExtra = [];
        if (PromptInjectionTraceCollector::active() && CognitiveBudgetEngine::active()) {
            $b = CognitiveBudgetEngine::applySegment($blockId, $work);
            $work = $b['content'];
            $budgetExtra = [
                'refused_chars' => $b['refused_chars'],
                'pruning_decision' => $b['pruning_decision'],
                'fallback_decision' => $b['fallback_policy'],
                'score_breakdown' => $b['score_breakdown'],
                'budget_layer' => $b['budget_layer'],
                'chars_budget_allowed' => $b['chars_budget_allowed'],
                'budget_soft_cap_registry' => $b['soft_budget'],
                'budget_hard_cap_registry' => $b['hard_budget'],
            ];
        }
        $userContent .= $work;
        $this->traceDecisionRoomSegment($blockId, $cognitiveLayer, $work, $inclusionReason, null, array_merge($extra, $budgetExtra));
        if ($useDedup && $dk !== null && $segment !== '') {
            PromptInjectionTraceCollector::recordSegmentFingerprint($dk, $segment);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function traceDecisionRoomSegment(
        string $blockId,
        string $cognitiveLayer,
        string $segment,
        string $inclusionReason,
        ?string $exclusionReason = null,
        array $extra = []
    ): void {
        if (!PromptInjectionTraceCollector::active()) {
            return;
        }
        PromptInjectionTraceCollector::addStep(
            $blockId,
            $cognitiveLayer,
            mb_strlen($segment, 'UTF-8'),
            $inclusionReason,
            $exclusionReason,
            $extra
        );
    }

    private function traceDecisionRoomSkipped(string $blockId, string $cognitiveLayer, string $reason): void
    {
        if (!PromptInjectionTraceCollector::active()) {
            return;
        }
        PromptInjectionTraceCollector::addStep(
            $blockId,
            $cognitiveLayer,
            0,
            'skipped',
            $reason,
            ['status' => 'ignored']
        );
    }

    /** @return array{prioritized:string,contested:string,fragile:string,invalidated:string} */
    private function buildBeliefsRuntimeSegments(?string $strategicContextId, bool $includeInvalidated = false): array
    {
        $empty = ['prioritized' => '', 'contested' => '', 'fragile' => '', 'invalidated' => ''];
        $ctx = is_string($strategicContextId) ? trim($strategicContextId) : '';
        if ($ctx === '') {
            return $empty;
        }
        $this->beliefEngineService ??= new BeliefEngineService();
        $all = $this->beliefEngineService->listBeliefsForContext($ctx, ['limit' => 180]);
        if ($all === []) {
            return $empty;
        }
        $prioritized = [];
        $contested = [];
        $fragile = [];
        $invalidated = [];
        foreach ($all as $b) {
            $text = trim((string)($b['belief_text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $conf = (float)($b['confidence'] ?? 0.5);
            $state = (string)($b['contestation_state'] ?? 'weak');
            $status = (string)($b['status'] ?? '');
            $agent = (string)($b['agent_id'] ?? 'group');
            $line = sprintf('- [%s] %s (conf %.2f, state=%s)', $agent, $text, $conf, $state);
            $isInvalidatedOrDeprecated = in_array($state, ['invalidated'], true)
                || in_array($status, ['invalidated', 'deprecated'], true);
            if ($isInvalidatedOrDeprecated) {
                $invalidated[] = $line;
                continue;
            }
            if (in_array($state, ['stable', 'reinforced'], true) && !in_array($status, ['archived', 'disputed'], true)) {
                $prioritized[] = $line;
            }
            if (in_array($state, ['contested', 'unstable'], true) || $status === 'disputed') {
                $contested[] = $line;
            }
            if (in_array($state, ['weak', 'derived'], true) || $conf < 0.45) {
                $fragile[] = $line;
            }
        }
        $prioritized = array_slice($prioritized, 0, 8);
        $contested = array_slice($contested, 0, 8);
        $fragile = array_slice($fragile, 0, 8);
        $invalidated = array_slice($invalidated, 0, 6);

        $out = $empty;
        if ($prioritized !== []) {
            $out['prioritized'] = "\n\n## Beliefs prioritized (runtime)\n" . implode("\n", $prioritized) . "\n";
        }
        if ($contested !== []) {
            $out['contested'] = "\n\n## Beliefs contested (runtime)\n" . implode("\n", $contested) . "\n";
        }
        if ($fragile !== []) {
            $out['fragile'] = "\n\n## Fragile assumptions (runtime)\n" . implode("\n", $fragile) . "\n";
        }
        if ($includeInvalidated && $invalidated !== []) {
            $out['invalidated'] = "\n\n## Invalidated beliefs (optional)\n" . implode("\n", $invalidated) . "\n";
        }

        return $out;
    }

    public function buildConfrontationMessages(
        Agent $agent,
        string $objective,
        array $previousMessages,
        string $phaseKey,
        int $phaseNumber,
        string $language = 'en',
        ?array $contextDoc = null,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent      = $this->buildSystemContent($agent, 'confrontation', $language);
        $confrontationPolicy = $this->loadPrompt('confrontation-policy') ?? '';
        $phaseInstruction   = $this->getConfrontationPhaseInstruction($phaseKey, $agent->id);

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective under debate:** $objective\n\n";
        $userContent .= $this->buildPlaybookDebateDisciplineBlock('confrontation', $language);

        if (!empty($previousMessages)) {
            $userContent .= "**Previous contributions:**\n";
            foreach ($previousMessages as $msg) {
                $agentId  = $msg['agent_id'] ?? 'Agent';
                $phaseName = $msg['phase'] ?? '';
                $userContent .= "\n**[$agentId]** *(Phase: $phaseName)*: {$msg['content']}\n";
            }
            $userContent .= "\n";
            $userContent .= $this->buildRepetitionReductionBlock($previousMessages, $language);
        }

        $userContent .= "**Your task for this phase:** $phaseInstruction";

        $socialPolicy   = $this->loadPrompt('social-dynamics-policy') ?? '';
        $systemFull = $systemContent;
        if ($confrontationPolicy !== '') {
            $systemFull .= "\n\n---\n\n" . $confrontationPolicy;
        }
        if ($socialPolicy !== '') {
            $systemFull .= "\n\n---\n\n" . $socialPolicy;
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemFull],
            ['role' => 'user',   'content' => $userContent],
        ];
        return $msgs;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Configurable confrontation (rounds-based)

    public function buildConfrontationRoundMessages(
        Agent  $agent,
        string $objective,
        array  $previousMessages,
        int    $currentRound,
        int    $totalRounds,
        string $interactionStyle = 'sequential',
        string $language = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        ?string $assignedTarget = null,
        ?string $socialDynamicsBlock = null,
        bool   $forceStrongContradictionNext = false,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null,
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'confrontation', $language);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('confrontation', [], $objective);

        $instruction = $this->getConfrontationRoundInstruction(
            $currentRound, $totalRounds, $interactionStyle, $agent->id, $previousMessages, $assignedTarget,
            $forceStrongContradictionNext
        );

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective under debate:** $objective\n\n";
        $userContent .= $this->strategicContextGuidanceBlock($strategicContextIdForAgentMemory);
        $userContent .= $this->playbookRuntime->buildPromptBlock($playbookId, $language);
        $userContent .= $this->buildPlaybookDebateDisciplineBlock($playbookId, $language);

        if (!empty($previousMessages)) {
            $userContent .= "**Previous Round Contributions:**\n";
            foreach ($previousMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $target     = !empty($msg['target_agent_id']) ? " → replying to [{$msg['target_agent_id']}]" : '';
                $userContent .= "\n**[$agentLabel]**{$target}: {$msg['content']}\n";
            }
            $userContent .= "\n";
            $userContent .= $this->buildRepetitionReductionBlock($previousMessages, $language);
        }
        if (!empty($memoryContext['argument_memory_summary'])) {
            $userContent .= "# Argument Memory (summary)\n\n";
            $userContent .= $memoryContext['argument_memory_summary'] . "\n\n";
            $userContent .= "Instructions:\n";
            $userContent .= "- Do not repeat existing arguments unless refining them.\n";
            $userContent .= "- Challenge or extend existing arguments.\n";
            $userContent .= "- Refer explicitly to previous arguments when relevant.\n\n";
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }

        $userContent .= $this->agentContextMemoryBlock($strategicContextIdForAgentMemory, $agent->id);
        $userContent .= "**Your task:** $instruction";
        if ($currentRound > 1 && ($assignedTarget !== null || $interactionStyle === 'agent-to-agent')) {
            $userContent .= $this->buildInteractionContractBlock(true);
        }
        $userContent .= $this->buildWeightedOpinionInstruction();
        if ($agent->id !== 'synthesizer' && $currentRound === $totalRounds) {
            $userContent .= $this->buildFinalVoteInstruction();
        }

        if ($forceDisagreement) {
            $userContent .= $this->buildForcedDisagreementInstruction('confrontation');
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    public function buildConfrontationSynthesisMessages(
        Agent  $agent,
        string $objective,
        array  $allMessages,
        string $language = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'confrontation', $language);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('confrontation', [], $objective);

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective debated:** $objective\n\n";
        $userContent .= $this->playbookRuntime->buildPromptBlock($playbookId, $language);
        $userContent .= $this->buildPlaybookDebateDisciplineBlock($playbookId, $language);
        $userContent .= "**Full Debate History:**\n";
        foreach ($allMessages as $msg) {
            $agentLabel = $msg['agent_id'] ?? 'Agent';
            $round      = $msg['round'] ?? '?';
            $target     = !empty($msg['target_agent_id']) ? " → replying to [{$msg['target_agent_id']}]" : '';
            $userContent .= "\n**[Round {$round}] [{$agentLabel}]**{$target}:\n{$msg['content']}\n";
        }
        $userContent .= "\n**Your task (FINAL SYNTHESIS):**\n";
        $userContent .= "Summarize this debate as a neutral moderator:\n";
        $userContent .= "1. The strongest argument made\n";
        $userContent .= "2. The strongest objection raised\n";
        $userContent .= "3. Key unresolved disagreements\n";
        $userContent .= "4. Recommended decision\n";
        $userContent .= "5. Suggested next action\n\n";
        if (!empty($memoryContext['weighted_analysis'])) {
            $analysis = $memoryContext['weighted_analysis'];
            $userContent .= "# Weighted Analysis\n\n";
            $userContent .= "## Dominant Position\n";
            $userContent .= ($analysis['dominant_position'] ?? 'needs-more-info') . "\n\n";
            $userContent .= "## Strongest Arguments\n";
            foreach (($analysis['strongest_arguments'] ?? []) as $arg) {
                $userContent .= "- " . ($arg['argument'] ?? '') . " (reuse: " . ($arg['reuse_count'] ?? 1) . ", score: " . ($arg['score'] ?? 0) . ")\n";
            }
            if (empty($analysis['strongest_arguments'])) {
                $userContent .= "- No strong argument detected yet\n";
            }
            $userContent .= "\n## Conflicting High-Weight Opinions\n";
            foreach (($analysis['conflicting_high_weight_opinions'] ?? []) as $c) {
                $userContent .= "- {$c['agent_a']} ({$c['stance_a']}, {$c['weight_a']}) vs {$c['agent_b']} ({$c['stance_b']}, {$c['weight_b']})\n";
            }
            if (empty($analysis['conflicting_high_weight_opinions'])) {
                $userContent .= "- No high-weight conflict detected\n";
            }
            $userContent .= "\n## Weak Signals\n";
            foreach (($analysis['weak_signals'] ?? []) as $w) {
                $userContent .= "- {$w['agent_id']} ({$w['stance']}, weight {$w['weight_score']})\n";
            }
            if (empty($analysis['weak_signals'])) {
                $userContent .= "- None\n";
            }
            $userContent .= "\n";
        }
        $userContent .= "Be decisive. Produce a clear verdict: Proceed / Proceed with conditions / Pause / Stop.";
        $userContent .= $this->buildSynthesizerOutputFormatInstruction($playbookId, $language);

        if ($forceDisagreement) {
            $userContent .= $this->buildForcedDisagreementInstruction('synthesizer');
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    public function buildQuickDecisionMessages(
        Agent  $agent,
        string $objective,
        array  $previousMessages,
        string $language = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc = null,
        ?string $socialDynamicsBlock = null,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null,
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'quick-decision', $language);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('quick-decision', [], $objective);

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective:** $objective\n\n";
        $userContent .= $this->strategicContextGuidanceBlock($strategicContextIdForAgentMemory);
        $userContent .= $this->playbookRuntime->buildPromptBlock($playbookId, $language);
        $userContent .= $this->buildPlaybookDebateDisciplineBlock($playbookId, $language);

        $isSynthesizer = $agent->id === 'synthesizer';

        if (!empty($previousMessages)) {
            $userContent .= "**Other agents' analyses:**\n";
            foreach ($previousMessages as $msg) {
                $userContent .= "\n**[{$msg['agent_id']}]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
            $userContent .= $this->buildRepetitionReductionBlock($previousMessages, $language);
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        } elseif (!$isSynthesizer && count($previousMessages) >= 1) {
            $userContent .= "> **Brief contradiction pass:** Respond to another agent explicitly — cite what you endorse or contest one concrete assumption.\n";
            $userContent .= "> Keep it concise; **challenge reasoning, never the person**.\n\n";
        }

        $userContent .= $this->agentContextMemoryBlock($strategicContextIdForAgentMemory, $agent->id);

        if ($isSynthesizer) {
            $userContent .= "**Your task:** Synthesize the analyses above into a final recommendation.\n";
            $userContent .= "Keep it concise and executable.\n";
            $userContent .= $this->buildSynthesizerOutputFormatInstruction($playbookId, $language);
            if ($forceDisagreement) {
                $userContent .= $this->buildForcedDisagreementInstruction('synthesizer');
            }
        } else {
            $userContent .= "**Your task (QUICK DECISION):** Give a concise decision-oriented analysis.\n\n";
            $userContent .= "Prefer this compact shape, while keeping the answer natural:\n\n## Strongest Argument\n(one key argument for this direction)\n\n## Biggest Risk\n(the single most critical risk)\n\n## Recommendation\n(clear yes/no/conditional recommendation)";
            if ($forceDisagreement) {
                $userContent .= $this->buildForcedDisagreementInstruction();
            }
            $userContent .= $this->buildFinalVoteInstruction();
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    public function buildStressTestMessages(
        Agent  $agent,
        string $objective,
        array  $previousRoundMessages,
        int    $round,
        int    $totalRounds,
        string $language = 'en',
        bool   $forceDisagreement = true,
        ?array $contextDoc = null,
        ?array $memoryContext = null,
        ?string $assignedTarget = null,
        ?string $socialDynamicsBlock = null,
        bool   $forceStrongContradictionNext = false,
        ?string $retrievalSessionId = null,
        ?string $retrievalLastUserMessage = null,
        ?string $strategicContextIdForAgentMemory = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'stress-test', $language);
        $playbookId = $this->playbookRuntime->resolvePlaybookId('stress-test', [], $objective);

        $roundPolicy = new RoundPolicy();

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective to stress-test:** $objective\n\n";
        $userContent .= $this->strategicContextGuidanceBlock($strategicContextIdForAgentMemory);
        $userContent .= $this->playbookRuntime->buildPromptBlock($playbookId, $language);
        $userContent .= $this->buildPlaybookDebateDisciplineBlock($playbookId, $language);

        if (!empty($previousRoundMessages)) {
            $userContent .= "**Previous round analyses:**\n";
            foreach ($previousRoundMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $userContent .= "\n**[$agentLabel]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
            $userContent .= $this->buildRepetitionReductionBlock($previousRoundMessages, $language);
        }
        if (!empty($memoryContext['argument_memory_summary'])) {
            $userContent .= "# Argument Memory (summary)\n\n";
            $userContent .= $memoryContext['argument_memory_summary'] . "\n\n";
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }

        $userContent .= $this->agentContextMemoryBlock($strategicContextIdForAgentMemory, $agent->id);

        $isSynthesizer = ($agent->id === 'synthesizer');

        if ($isSynthesizer && $round === $totalRounds) {
            $userContent .= "**Your task — STRESS TEST REPORT (FINAL SYNTHESIS):**\n\n";
            $userContent .= "Based on all the agents' risk analyses, produce the final Stress Test Report. Prefer this structure, but do not sacrifice clarity if the evidence requires nuance:\n\n";
            $userContent .= "# Stress Test Report\n\n";
            $userContent .= "## Most Likely Failure Modes\n(list 3-5 realistic scenarios where this fails)\n\n";
            $userContent .= "## Highest Impact Risks\n(risks with the most severe consequences)\n\n";
            $userContent .= "## Weakest Assumptions\n(assumptions that, if wrong, kill the idea)\n\n";
            $userContent .= "## Mitigations\n(concrete actions to reduce each major risk)\n\n";
            $userContent .= "## Kill Criteria\n(explicit conditions under which you should stop/pivot)\n\n";
            $userContent .= "## Recommended Next Step\n(the single most important action to de-risk before investing more)\n\n";
            $userContent .= $this->buildSynthesizerOutputFormatInstruction($playbookId, $language);
        } elseif ($round === 1) {
            $userContent .= "**Your task — ROUND 1 (FAILURE SCENARIOS):**\n\n";
            $userContent .= "Adopt a risk-first posture. Stay within your domain expertise.\n\n";
            $userContent .= "Identify how this idea could fail. Focus on:\n";
            $userContent .= "- Concrete failure modes specific to your domain\n";
            $userContent .= "- Weak assumptions that the idea relies on\n";
            $userContent .= "- Blind spots and overlooked risks\n";
            $userContent .= "- Unacceptable risks that would block success\n\n";
            $userContent .= "Be specific and actionable. Avoid vague concerns. Each risk must be falsifiable.";
        } else {
            $userContent .= "**Your task — ROUND 2 (MITIGATIONS & KILL CRITERIA):**\n\n";
            $userContent .= "Based on the failure scenarios identified in Round 1, propose:\n";
            $userContent .= "- Concrete mitigations for the top risks in your domain\n";
            $userContent .= "- Small tests or experiments to validate the riskiest assumptions\n";
            $userContent .= "- Kill criteria: explicit conditions under which you would stop or pivot\n";
            $userContent .= "- De-risking actions that could be done before committing fully\n\n";
            $userContent .= "Be practical. Every action must be executable. Prefer small next steps.";
            $userContent .= $this->buildTargetAgentHint($agent->id, $previousRoundMessages, $assignedTarget);
            $userContent .= $this->buildInteractionContractBlock($assignedTarget !== null);
        }

        if (!$isSynthesizer && $totalRounds > 1) {
            $rType = $roundPolicy->getRoundType($round, $totalRounds);
            $userContent .= "\n\n**Round mindset:** " . $roundPolicy->getRoundTypeDirective($rType, $forceStrongContradictionNext);
        }

        if ($forceDisagreement && !$isSynthesizer) {
            $userContent .= "\n\n**Note:** Challenge any optimistic framing from previous rounds. Do not soften risks.";
        }
        $userContent .= $this->buildWeightedOpinionInstruction();
        if (!$isSynthesizer && $round === $totalRounds) {
            $userContent .= $this->buildFinalVoteInstruction();
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        return $msgs;
    }

    public function buildActionPlanMessages(
        string $sessionContent,
        string $language = 'en'
    ): array {
        $langNote = $language === 'fr'
            ? 'Respond ONLY in French.'
            : 'Respond in English.';

        $system = "You are an expert in converting strategic analysis into concrete, executable action plans.\n"
            . "You produce only practical, specific next steps — no vague advice.\n"
            . $langNote;

        $user = "Based on the following session analysis, produce a structured action plan in JSON.\n\n"
            . "Return ONLY valid JSON. No markdown wrapper. No explanation.\n\n"
            . "Required JSON structure:\n"
            . '{'
            . '"summary": "1-2 sentence overview of the situation and recommended direction",'
            . '"immediate_actions": [{"title":"...","description":"...","priority":"high|medium|low"}],'
            . '"short_term_actions": [{"title":"...","description":"...","priority":"high|medium|low"}],'
            . '"experiments": [{"title":"...","hypothesis":"...","success_metric":"..."}],'
            . '"risks_to_monitor": [{"risk":"...","mitigation":"..."}]'
            . "}\n\n"
            . "Rules:\n"
            . "- Every action must be executable by a real person\n"
            . "- Prefer small next steps over big ones\n"
            . "- Include experiments when uncertainty is high\n"
            . "- No vague advice — be specific\n"
            . "- 2-5 items per section\n\n"
            . "Session Analysis:\n\n"
            . $sessionContent;

        $msgs = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];

        return $msgs;
    }

    public function buildComparisonMessages(
        array  $sessions,
        string $language = 'en'
    ): array {
        $langNote = $language === 'fr'
            ? 'Respond ONLY in French.'
            : 'Respond in English.';

        $system = "You are an expert decision analyst. You compare sessions objectively and produce structured comparisons.\n"
            . "Use only the provided session data. Do not invent facts.\n"
            . $langNote;

        $userContent = "Compare the following sessions as decision options.\n\n"
            . "Return structured Markdown with EXACTLY this format:\n\n"
            . "# Session Comparison\n\n"
            . "## Compared Sessions\n"
            . "## Common Points\n"
            . "## Key Differences\n"
            . "## Risks By Session\n"
            . "## Best Option\n"
            . "## Recommendation\n"
            . "## Final Verdict\n\n"
            . "---\n\n"
            . "Sessions data:\n\n";

        foreach ($sessions as $i => $s) {
            $userContent .= "### Session " . ($i + 1) . ": " . ($s['title'] ?? 'Untitled') . "\n";
            $userContent .= "Mode: " . ($s['mode'] ?? 'unknown') . "\n";
            $userContent .= "Agents: " . implode(', ', (array)($s['selected_agents'] ?? [])) . "\n";
            if (!empty($s['initial_prompt'])) {
                $userContent .= "Initial prompt: " . $s['initial_prompt'] . "\n";
            }
            if (!empty($s['summary'])) {
                $userContent .= "Summary: " . $s['summary'] . "\n";
            }
            if (!empty($s['verdict'])) {
                $userContent .= "Verdict: " . $s['verdict']['verdict_label'] . " — " . $s['verdict']['verdict_summary'] . "\n";
            }
            if (!empty($s['action_plan'])) {
                $userContent .= "Action Plan Summary: " . $s['action_plan']['summary'] . "\n";
            }
            if (!empty($s['synthesis'])) {
                $userContent .= "Synthesis:\n" . $s['synthesis'] . "\n";
            }
            $userContent .= "\n";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    public function buildContextDocumentContent(
        ?array $contextDoc,
        ?string $retrievalSessionId = null,
        ?string $retrievalObjective = null,
        ?string $retrievalLastUserMessage = null
    ): string {
        $this->lastRetrievalLogMeta = [
            'retrieval_query'          => null,
            'number_of_chunks_indexed' => null,
            'number_of_excerpts_used' => 0,
            'retrieval_latency_ms'     => 0,
        ];

        if ($contextDoc === null) {
            return '';
        }
        $body = '';
        if (!empty($contextDoc['prompt_content'])) {
            $body = (string)$contextDoc['prompt_content'];
        } elseif (!empty($contextDoc['content'])) {
            $body = (string)$contextDoc['content'];
        }
        if ($body === '') {
            return '';
        }

        $title          = $contextDoc['title']              ?? 'Context Document';
        $source         = $contextDoc['source_type']        ?? 'manual';
        $filename       = $contextDoc['original_filename']  ?? '';
        $storageChars   = (int)($contextDoc['context_storage_chars']
            ?? $contextDoc['character_count']
            ?? mb_strlen((string)($contextDoc['content'] ?? $body), 'UTF-8'));
        $injectedChars  = (int)($contextDoc['context_injected_chars'] ?? mb_strlen($body, 'UTF-8'));
        $truncatedNote  = !empty($contextDoc['context_truncated'])
            ? 'yes (model prompt uses first ' . self::MAX_CONTEXT_INJECT_CHARS . ' chars; full document retained in app)'
            : 'no';

        $out  = "# Hierarchy (non-negotiable)\n";
        $out .= "1) This Shared Context Document (below)\n";
        $out .= "2) Retrieved excerpts — only if a \"## Retrieved excerpts\" section is present in this message\n";
        $out .= "3) Agent claims — never treat as verified facts without support from (1) or (2)\n";
        $out .= "4) Agent citations — must point to (1) or (2)\n";
        $out .= "5) Your wording — do not fabricate citations\n\n";
        $out .= "# Shared Context Document\n\n";
        $out .= "**Title:** $title\n";
        $out .= "**Source:** $source\n";
        if ($filename) {
            $out .= "**Filename:** $filename\n";
        }
        $out .= "**Characters (stored):** $storageChars\n";
        $out .= "**Characters (injected into this prompt):** $injectedChars\n";
        $out .= "**Truncated for prompt:** $truncatedNote\n\n";
        $out .= "---\n\n";
        $out .= $body;
        $out .= "\n\n---\n\n";

        $out .= $this->buildFtsExcerptBlockForPrompt(
            $retrievalSessionId,
            (string)($retrievalObjective ?? ''),
            $retrievalLastUserMessage
        );

        $out .= "[INSTRUCTIONS]\n";
        $out .= "Use this context if relevant.\n";
        $out .= "If a claim is not supported, label it as unsupported.\n\n";

        return $out;
    }

    /**
     * Phase 2 — machine-ranked excerpts (FTS5). Updates {@see $lastRetrievalLogMeta}.
     * Omits the entire block when there are zero hits (no empty heading).
     */
    private function buildFtsExcerptBlockForPrompt(
        ?string $sessionId,
        string $objective,
        ?string $lastUserMessage
    ): string {
        if ($sessionId === null || $sessionId === '') {
            return '';
        }

        $repo       = new ContextDocumentChunkRepository();
        $chunkCount = $repo->countBySession($sessionId);
        $this->lastRetrievalLogMeta['number_of_chunks_indexed'] = $chunkCount;

        $ftsQ = ContextDocumentChunkRepository::buildFtsMatchQuery($objective, $lastUserMessage);
        $this->lastRetrievalLogMeta['retrieval_query'] = $ftsQ !== '' ? $ftsQ : null;

        if ($ftsQ === '' || $chunkCount === 0) {
            return '';
        }

        $cacheKey = $sessionId . "\0" . $ftsQ;
        $t0       = hrtime(true);
        try {
            if (isset(self::$ftsRetrievalResultCache[$cacheKey])) {
                $picked = self::$ftsRetrievalResultCache[$cacheKey];
            } else {
                $raw = $repo->searchTopChunks($sessionId, $ftsQ, 8);
                $picked = ContextDocumentChunkRepository::dedupeByChunkIndex($raw, 5);
                self::$ftsRetrievalResultCache[$cacheKey] = $picked;
                $this->trimFtsRetrievalCache();
            }

            $this->lastRetrievalLogMeta['retrieval_latency_ms'] = (int) round(
                (hrtime(true) - $t0) / 1_000_000
            );
            $this->lastRetrievalLogMeta['number_of_excerpts_used'] = count($picked);

            if (empty($picked)) {
                return '';
            }

            return $this->formatMachineRankedExcerptsMarkdown($picked) . "\n";
        } catch (\Throwable) {
            $this->lastRetrievalLogMeta['retrieval_latency_ms'] = (int) round(
                (hrtime(true) - $t0) / 1_000_000
            );
            $this->lastRetrievalLogMeta['number_of_excerpts_used'] = 0;
            return '';
        }
    }

    /**
     * @param list<array{id:int,chunk_index:int,content:string,rank:float}> $rows
     */
    private function formatMachineRankedExcerptsMarkdown(array $rows): string
    {
        $buf = "## Retrieved excerpts (machine-ranked)\n\n";
        $buf .= "Rules:\n";
        $buf .= "- Each excerpt comes from the Shared Context Document\n";
        $buf .= "- Use [E#] to cite excerpts\n";
        $buf .= "- If no excerpt supports a claim → label as unsupported\n";
        $buf .= "- do NOT invent sources\n\n";
        $buf .= "| id | chunk_index | score | excerpt |\n";
        $buf .= "|----|-------------|-------|---------|\n";

        $n = 1;
        foreach ($rows as $r) {
            $eid         = 'E' . $n;
            $chunkIdx    = (string)($r['chunk_index'] ?? 0);
            $score       = number_format((float)($r['rank'] ?? 0.0), 2, '.', '');
            $excerptCell = ContextDocumentChunkRepository::excerptCell((string)($r['content'] ?? ''));
            $buf .= "| {$eid} | {$chunkIdx} | {$score} | {$excerptCell} |\n";
            $n++;
        }

        return rtrim($buf);
    }

    private function trimFtsRetrievalCache(): void
    {
        if (count(self::$ftsRetrievalResultCache) <= self::FTS_CACHE_MAX_ENTRIES) {
            return;
        }
        self::$ftsRetrievalResultCache = array_slice(
            self::$ftsRetrievalResultCache,
            -32,
            null,
            true
        );
    }

    private function buildFinalVerdictInstruction(): string {
        $base = "\n\n---\n\n**REQUIRED FINAL SECTION — DO NOT SKIP:**\n\nAfter your analysis, you MUST include this exact section:\n\n# Final Verdict\n\n## Verdict Label\none of: go | no-go | risky | needs-more-info | reduce-scope\n\n## Verdict Summary\nshort explanation (2-3 sentences)\n\n## Scores\n- Feasibility: X/10\n- Product Value: X/10\n- UX: X/10\n- Risk: X/10 (10 = high risk)\n- Confidence: X/10\n\n## Recommended Action\nclear next step";
        return $base . "\n\n" . $this->buildTradeoffsJsonAppendix();
    }

    private function buildForcedDisagreementInstruction(string $mode = 'default'): string {
        $base = "\n\n---\n\n**FORCED DISAGREEMENT ENABLED:**\nYou MUST identify at least one meaningful weakness, disagreement, trade-off, or risk.\nDo not simply agree with other agents.\nIf you agree overall, still explain the strongest counterargument.";

        if ($mode === 'confrontation') {
            $base .= "\nChallenge another agent explicitly. Generic agreement is not acceptable.";
        }
        if ($mode === 'synthesizer') {
            $base .= "\nHighlight real disagreements. If agents converged, identify the strongest remaining trade-off.";
        }
        return $base;
    }

    private function buildWeightedOpinionInstruction(): string {
        return "\n\n---\n\nYou must provide a weighted opinion.\n\n"
            . "- Confidence: how sure you are (0-10)\n"
            . "- Impact: how important your point is (0-10)\n"
            . "- Domain Weight: how relevant your expertise is here (0-10)\n\n"
            . "Avoid always giving maximum scores. Be realistic and consistent.\n\n"
            . "Include this section in your response:\n\n"
            . "# Position\n\n"
            . "## Stance\nsupport | oppose | reduce-scope | alternative | needs-more-info\n\n"
            . "## Confidence\n0-10\n\n"
            . "## Impact\n0-10\n\n"
            . "## Domain Weight\n0-10\n\n"
            . "## Main Argument\n...\n\n"
            . "## Biggest Risk\n...\n\n"
            . "## New Information Or Challenge\n"
            . "(what you add beyond prior agents; if nothing material changed, say so briefly)\n\n"
            . "## Change Since Last Round\n...\n";
    }

    /**
     * Public wrapper for runners that need the weighted opinion block.
     * Keeps the underlying instruction private to avoid leaking too much surface area.
     */
    public function buildWeightedOpinionInstructionBlock(): string
    {
        return $this->buildWeightedOpinionInstruction();
    }

    private function buildFinalVoteInstruction(): string {
        return "\n\n---\n\nAt the end of your response, you MUST include this exact section:\n\n"
            . "# Final Vote\n\n"
            . "## Vote\none of: go | no-go | reduce-scope | needs-more-info | pivot\n\n"
            . "## Confidence\n0-10\n\n"
            . "## Impact\n0-10\n\n"
            . "## Domain Weight\n0-10\n\n"
            . "## Rationale\n- Main objection considered: ...\n- Main concession accepted: ...\n- Reason for final vote: ...\n\n"
            . "Rules:\n"
            . "- Do not vote go if major unresolved risks remain.\n"
            . "- Use reduce-scope when idea is promising but too broad.\n"
            . "- Use needs-more-info when key assumptions are unknown.\n"
            . "- Use pivot when current framing is weak but a nearby alternative is promising.\n"
            . "- Use realistic scoring. Do not always return maximum values.\n";
    }

    private function getConfrontationRoundInstruction(
        int     $currentRound,
        int     $totalRounds,
        string  $interactionStyle,
        string  $agentId,
        array   $prevMessages,
        ?string $assignedTarget = null,
        bool    $forceStrongContradictionNext = false
    ): string {
        $policy = new RoundPolicy();
        $suffix = '';
        if ($totalRounds > 1) {
            $suffix = "\n\n**Round mindset:** " . $policy->getRoundTypeDirective(
                $policy->getRoundType($currentRound, $totalRounds),
                $forceStrongContradictionNext
            );
        }

        if ($currentRound === 1) {
            return "ROUND 1 — INITIAL POSITION: State your position clearly on the objective above. Present your strongest arguments, be specific and evidence-based. Use your default response format."
                . $suffix;
        }

        if ($currentRound === $totalRounds) {
            return "FINAL ROUND — REVISED POSITION: Review all prior positions and objections. State your final, revised position. Indicate your confidence level (low / medium / high). Acknowledge what, if anything, changed your mind. Use your default response format."
                . $suffix;
        }

        // Middle challenge rounds
        if ($interactionStyle === 'agent-to-agent' && !empty($prevMessages)) {
            $agentIds   = array_unique(array_column($prevMessages, 'agent_id'));
            $targets    = array_values(array_filter($agentIds, fn($id) => $id !== $agentId));
            $targetList = implode(', ', $targets);

            return "CHALLENGE ROUND — AGENT-TO-AGENT RESPONSE:\n\n"
                . "Choose ONE agent from the previous round to respond to. Start your response with:\n\n"
                . "## Target Agent\n{the_agent_id_here}\n\n"
                . "Then structure your response as follows:\n\n"
                . "## Agreement / Disagreement\n(what you agree or disagree with specifically)\n\n"
                . "## Objection\n(your concrete challenge to their argument)\n\n"
                . "## Revised Position\n(your updated stance based on this exchange)\n\n"
                . "Available targets: {$targetList}\n"
                . "Do NOT choose yourself. Challenge specific claims, not vague generalities. "
                . "Do not repeat your previous answer."
                . $suffix;
        }

        $targetHint = $this->buildTargetAgentHint($agentId, $prevMessages, $assignedTarget);
        return "CHALLENGE ROUND — CRITICAL ANALYSIS: Review the previous round's positions. "
            . "Challenge the weakest argument you see with specific counter-evidence. "
            . "Update your own position if warranted. Avoid generic agreement. "
            . "Use your default response format."
            . $targetHint
            . $suffix;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function getConfrontationPhaseInstruction(string $phaseKey, string $agentId): string {
        return match($phaseKey) {
            'confrontation-blue-opening' =>
                "PHASE 1 — BLUE TEAM OPENING: You are defending this idea. Present the strongest possible case FOR it. Show concrete execution path, key opportunities, and how main risks can be mitigated. Be honest about challenges but stay constructive. Use your default response format.",

            'confrontation-red-attack' =>
                "PHASE 2 — RED TEAM ATTACK: You are challenging this idea and the Blue Team's arguments. Target the weakest assumption or the biggest risk. Be sharp, specific, and evidence-based. End with: 'For this to work, X would need to be true.' Use your default response format.",

            'confrontation-blue-rebuttal' =>
                "PHASE 3 — BLUE TEAM REBUTTAL: Respond directly to the Red Team's most dangerous challenge. Concede what is valid — do not defend the indefensible. Strengthen your position with new arguments or adjustments. Be specific, not generic. Use your default response format.",

            'confrontation-synthesis' =>
                "FINAL SYNTHESIS — MODERATION: You are the neutral moderator. Summarize the debate:\n1. Name the strongest Blue Team argument\n2. Name the most dangerous Red Team challenge\n3. Identify the key condition for success\n4. Produce a verdict: Proceed / Proceed with conditions / Pause / Stop\n\nBe decisive. No fence-sitting. Use your default response format.",

            default => "Provide your analysis for this confrontation phase.",
        };
    }

    private function buildSystemContent(Agent $agent, string $mode, string $language = 'en'): string {
        $globalSystem       = $this->loadPrompt('global-system') ?? '';
        $orchestratorPolicy = $this->loadPrompt('orchestrator') ?? '';

        $personaContent = $agent->persona->content;
        $soulContent    = $agent->soul?->content ?? '';

        $parts = [
            $globalSystem,
            "---",
            "## Your Persona: {$agent->persona->name} ({$agent->persona->title})",
            $personaContent,
        ];

        $dynBlock = $this->buildDecisionDynamicsBlock($agent);
        if ($dynBlock !== '') {
            $parts[] = $dynBlock;
        }

        if ($soulContent) {
            $parts[] = "---\n## Your Soul / Personality";
            $parts[] = $soulContent;
        }

        $parts[] = "---\n## Orchestration Mode: $mode";
        $parts[] = $orchestratorPolicy;
        $parts[] = "---\n**You are {$agent->persona->name}, the {$agent->persona->title}. Answer ONLY as yourself.**";

        $parts[] = trim($this->buildEvidenceDisciplineSystemBlock());
        $parts[] = trim($this->buildArgumentDisciplineSystemBlock());

        if ($language === 'fr') {
            $parts[] = "---\n## INSTRUCTION DE LANGUE OBLIGATOIRE\n**Tu dois répondre UNIQUEMENT en français. Toutes tes réponses doivent être rédigées en français, sans exception. Même si le contexte est en anglais, ta réponse doit être entièrement en français.**";
        } elseif ($language === 'en') {
            $parts[] = "---\n## LANGUAGE INSTRUCTION\n**Always respond in English.**";
        }

        return implode("\n\n", array_filter($parts));
    }

    /** Lightweight lines derived from explicit admin-controlled decision dynamics only. */
    private function buildDecisionDynamicsBlock(Agent $agent): string {
        $d = $agent->decisionDynamics ?? [];
        if ($d === []) {
            $d = DecisionDynamics::defaults();
        }
        $lines = [];
        if (($d['consensus_resistance'] ?? '') === 'low') {
            $lines[] = 'Tu te rallies plus facilement lorsque les autres agents convergent.';
        }
        if (($d['consensus_resistance'] ?? '') === 'strong') {
            $lines[] = 'Tu maintiens ta position si tu n\'es pas convaincu.';
        }
        if (($d['evidence_sensitivity'] ?? '') === 'low') {
            $lines[] = 'Tu changes rarement d\'avis sans preuves très solides.';
        }
        if (($d['evidence_sensitivity'] ?? '') === 'high') {
            $lines[] = 'Tu ajustes ton jugement si des preuves solides apparaissent.';
        }
        if (($d['risk_tolerance'] ?? '') === 'cautious') {
            $lines[] = 'Tu privilégies la prudence et les risques.';
        }
        if (($d['risk_tolerance'] ?? '') === 'bold') {
            $lines[] = 'Tu acceptes davantage d\'incertitude pour faire avancer la décision.';
        }
        if ($lines === []) {
            return '';
        }
        return "---\n## Dynamique de décision (paramètres explicites)\n" . implode("\n", array_map(fn($x) => '- ' . $x, $lines));
    }

    private function buildUserContent(
        string $sessionContext,
        array $history,
        string $message,
        ?string $roundInstruction
    ): string {
        $content = '';
        if ($sessionContext) {
            $content .= "**Session Context:** $sessionContext\n\n";
        }
        if (!empty($history)) {
            $content .= "**Conversation History:**\n";
            foreach (array_slice($history, -10) as $msg) {
                $role = $msg['role'] === 'user' ? 'User' : ($msg['agent_id'] ?? 'Agent');
                $content .= "[$role]: {$msg['content']}\n";
            }
            $content .= "\n";
        }
        $content .= "**User:** $message";
        if ($roundInstruction) {
            $content .= "\n\n$roundInstruction";
        }
        return $content;
    }

    private function loadPrompt(string $name): ?string {
        $data = $this->loader->loadById('prompts', $name);
        return $data ? $data['content'] : null;
    }

    /**
     * Generates a directive instruction telling the LLM which specific agent to
     * challenge this round, using the standardised ## Target Agent format.
     *
     * When $assignedTarget is provided the instruction is mandatory ("you MUST");
     * otherwise it remains optional and lists all available peers.
     */
    public function buildTargetAgentHint(string $agentId, array $previousMessages, ?string $assignedTarget = null): string {
        if (empty($previousMessages)) {
            return '';
        }
        $ids = array_values(array_unique(array_filter(
            array_column($previousMessages, 'agent_id'),
            fn($id) => !empty($id) && $id !== $agentId
        )));
        if (empty($ids)) {
            return '';
        }

        if ($assignedTarget !== null && in_array($assignedTarget, $ids, true)) {
            return "\n\n---\n\n"
                . "**Interaction assignment:** For this round you MUST directly challenge **[{$assignedTarget}]**'s argument.\n"
                . "Begin your response with this exact block (before any other text):\n\n"
                . "## Target Agent\n{$assignedTarget}\n\n"
                . "Then write your challenge, counter-argument, or rebuttal of their specific position.\n";
        }

        $list = implode(', ', $ids);
        return "\n\n> **Interaction tracking (optional):** If your response directly challenges or builds on a specific agent's argument, begin your response with the following block before any other text:\n"
            . "> ```\n> ## Target Agent\n> {agent_id}\n> ```\n"
            . "> Replace `{agent_id}` with the exact ID of the agent you are responding to. Available IDs: **{$list}**.\n";
    }

    public function buildSynthesizerConstraintBlock(array $reliabilityData): string
    {
        $adj        = $reliabilityData['adjusted_decision'] ?? [];
        $raw        = $reliabilityData['raw_decision'] ?? [];
        $cq         = $reliabilityData['context_quality'] ?? [];
        $fc         = $reliabilityData['false_consensus'] ?? [];
        $guardrails = $reliabilityData['guardrails'] ?? [];
        $evidence   = $reliabilityData['evidence_report'] ?? null;
        $risk       = $reliabilityData['risk_profile'] ?? null;

        $winningLabel  = $raw['winning_label'] ?? 'unknown';
        $winningScore  = number_format((float)($raw['winning_score'] ?? 0), 2);
        $threshold     = number_format((float)($raw['threshold'] ?? 0.65), 2);
        $decisionLabel = $adj['decision_label'] ?? 'unknown';
        $decisionStatus= $adj['decision_status'] ?? 'unknown';
        $finalOutcome  = $adj['final_outcome'] ?? 'unknown';

        $cqLevel       = $cq['level'] ?? 'unknown';
        $cqScore       = number_format((float)($cq['score'] ?? 0), 0);
        $dqScore       = number_format((float)($reliabilityData['debate_quality_score'] ?? 0), 0);
        $fcRisk        = $fc['false_consensus_risk'] ?? 'unknown';
        $evidenceNorm  = 'N/A (no evidence layer)';
        if ($evidence) {
            $scr = isset($evidence['score']) ? (float)$evidence['score'] : (float)($evidence['evidence_score'] ?? 0) * 100;
            $evidenceNorm = number_format($scr, 0) . '/100 (badge: ' . ($evidence['evidence_badge'] ?? 'n/a') . ', density: '
                . number_format((float)($evidence['evidence_density'] ?? 0) * 100, 0) . '%)';
        }
        $riskLevel     = $risk['risk_level'] ?? 'unknown';

        $evidenceWarn = '';
        if (is_array($evidence)) {
            $lines = [];
            $hiu = (int)($evidence['high_importance_unsupported_count'] ?? 0);
            $hic = (int)($evidence['high_importance_contradicted_count'] ?? 0);
            $cu  = (int)($evidence['contradicted_claims_count'] ?? 0);
            if ($hic > 0 || $cu > 0) {
                $lines[] = 'Contradicted: ' . ($hic > 0 ? "{$hic} high-importance" : "{$cu} total");
            }
            if ($hiu > 0) {
                $lines[] = "Unsupported (high importance): {$hiu}";
            }
            if (!empty($cq['context_truncated'])) {
                $lines[] = 'Context was truncated before prompt injection';
            }
            if ($lines !== []) {
                $evidenceWarn = "\n## Evidence warnings (constraints)\n- " . implode("\n- ", $lines) . "\n\nYou MUST reflect material limitations above in your synthesis. Distinguish supported facts from assumptions. Do not fabricate citations.\n";
            }
        }

        return <<<TEXT

## Aggregated Vote Result
- winning_label: {$winningLabel}
- winning_score: {$winningScore}
- threshold: {$threshold}
- decision_label: {$decisionLabel}
- decision_status: {$decisionStatus}
- final_outcome: {$finalOutcome}

## Reliability Signals
- context_quality: {$cqLevel} (score: {$cqScore})
- debate_quality_score: {$dqScore}
- false_consensus_risk: {$fcRisk}
- evidence_score: {$evidenceNorm}
- risk_level: {$riskLevel}
{$evidenceWarn}
## Hard Constraints
You MUST NOT claim there is a clear GO if final_outcome is NO_CONSENSUS, NO_CONSENSUS_FRAGILE or INSUFFICIENT_CONTEXT.
You MUST NOT describe the decision as reliable if decision_status is FRAGILE or INSUFFICIENT_CONTEXT.
You MUST explicitly state when the debate was weak or insufficiently adversarial.
You MUST align the final recommendation with the adjusted_decision above.
If evidence warnings were listed, include a short "## Evidence warnings" section in your response (max 3 bullets).

TEXT;
    }

    public function buildSynthesizerOutputFormatInstruction(?string $playbookId = null, string $language = 'en'): string
    {
        $base = <<<TEXT

Use this stable shape when possible. Keep the synthesis natural and decision-oriented; do not omit key decision fields even if wording or headings vary.

## Decision
GO | NO-GO | ITERATE | NO_CONSENSUS | INSUFFICIENT_CONTEXT

## Confidence
weak | moderate | strong

## Why
- (max 3 bullet points explaining the key reasons)

## Main Risks
- (max 3 bullet points)

The final synthesis should include a clearly named validation logic section.
Do not omit the validation logic itself.
If some information is missing, infer a measurable first validation hypothesis instead of omitting the section.
Avoid vanity metrics. Prefer measurable, observable, time-bounded criteria when possible.

## Validation Logic
Success signal: ...
Validation threshold: ...
Failure signal: ...
Kill criteria: ...

## Reliability Warning
(one sentence — omit this section only if the decision is strong and reliable)

## Evidence warnings
(omit if none; otherwise max 3 bullets: Unsupported / Contradicted / Context limitation — use evidence signals from constraints only)

## Next Step
(one concrete actionable next step)


TEXT;

        return $base
            . $this->playbookRuntime->buildPromptBlock($playbookId, $language)
            . "\n\n" . $this->buildTradeoffsJsonAppendix();
    }

    /**
     * Machine-readable trade-offs appendix for synthesizer modes (Decision Room schema).
     * When Pre-Mortem JSON is also required, emit Premortem first, then this block — both last.
     */
    public function buildTradeoffsJsonAppendix(): string
    {
        return <<<'TEXT'
---
## Decision trade-offs — JSON appendix (mandatory)

After completing all headings and sections defined above, append **exactly one** fenced ```json block at the **very end** of your answer containing a `tradeoffs` object.

If you were also instructed to emit a Pre-Mortem fenced JSON appendix, emit **both** fenced JSON blocks **in order** at the **end**: Pre-Mortem JSON first, then this trade-offs JSON.

Rules:
- Valid JSON only inside the fences — double quotes, no trailing commas, no comments.
- `criteria` MUST have between 3 and 8 items (inclusive).
- Each criterion MUST have: non-empty snake_case `id`, integer `score` from 1 to 5, non-empty short `justification` (honest qualifier — no false precision).

Default criterion ids (reuse these when relevant; you may omit `label` and it will be filled by the UI):
- `user_value` — Valeur utilisateur / user value delivered
- `cost` — Coût / effort (score higher = lighter effort / cheaper)
- `risk` — Risque résiduel (score higher = lower residual risk favourable to proceed)
- `ttm` — Time-to-market (score higher = faster or simpler delivery path)
- `complexity` — Complexité d’exécution (score higher = simpler to steer)
- `confidence` — Confiance / conviction de l’équipe

Optional per-criterion `confidence` string: `low` | `medium` | `high` (how sure you are about that score).

Optional `options`: array of `{ "label": "Option A", "criteria": [ ... same shape as top-level criteria ... ] }` when you compared multiple concrete alternatives (max 8 options total, each 3–8 criteria).

Example (primary recommendation only):
```json
{
  "tradeoffs": {
    "enabled": true,
    "criteria": [
      {"id":"user_value","score":4,"justification":"...","confidence":"medium"},
      {"id":"cost","score":3,"justification":"..."},
      {"id":"risk","score":2,"justification":"..."},
      {"id":"ttm","score":3,"justification":"..."},
      {"id":"complexity","score":3,"justification":"..."},
      {"id":"confidence","score":3,"justification":"..."}
    ],
    "summary": "One short honest paragraph on key trade-offs.",
    "options": null
  }
}
```

TEXT;
    }

    /**
     * For Pre-Mortem sessions: machine-readable appendix on synthesizer output.
     */
    public function buildPremortemSynthesizerJsonAddendum(): string
    {
        return <<<'TEXT'


## Pre-Mortem structured summary (mandatory)
After completing the headings above **when consistent with your analysis**, append **one fenced JSON block** near the **end** of your answer.
If you were also instructed to append **Decision trade-offs JSON**, emit **two** fenced ```json blocks **in this order** at the **very end**: (1) Pre-Mortem JSON below, (2) trade-offs JSON from the other instruction.
Rules:
- Valid JSON only inside the fences (double quotes on keys/strings).
- No comments inside JSON.
```json
{
  "premortem_summary": {
    "failure_scenario": "...",
    "root_causes": ["..."],
    "invalid_assumptions": ["..."],
    "ignored_signals": ["..."],
    "preventive_actions": ["..."],
    "residual_risk_level": "low|medium|high|critical"
  }
}
```

TEXT;
    }

    public function buildAutoRetryAdversarialPrompt(float $initialScore): string
    {
        $score = number_format($initialScore, 0);
        return <<<TEXT
The previous debate quality was weak (score: {$score}/100).
Agents produced mostly parallel answers with limited genuine interaction.
You MUST directly challenge one concrete claim made by another agent.
Reference the target agent explicitly by name (e.g. "I disagree with [Agent]'s claim that...").
Generic agreement or restating your own position is not allowed.
TEXT;
    }

    /**
     * Contrat d'interaction minimal pour les réponses agent-à-agent.
     * Compatible avec les parsers existants : ## Target Agent reste en première position.
     */
    public function buildInteractionContractBlock(bool $isRequired = true): string
    {
        $obligation = $isRequired
            ? 'you MUST include these sections before any other content'
            : 'you are encouraged to include these sections before any other content';

        return "\n\n---\n\n## Interaction Contract\n\n"
            . "When responding to another agent, {$obligation}:\n\n"
            . "## Target Agent\n<exact agent id>\n\n"
            . "## Claim Challenged\n<short quote from the target agent's argument, or None>\n\n"
            . "## Claim Type\nobservation | assumption | inference | recommendation | unknown\n\n"
            . "## Objection\n<your precise objection to that specific claim, or None>\n\n"
            . "## Missing Support\n<what evidence, signal, or test would make this claim reliable, or None>\n\n"
            . "## Concession\n<a valid point you accept from the target agent, or None>\n\n"
            . "## Position Change\nunchanged | weakened | strengthened | changed\n";
    }
}

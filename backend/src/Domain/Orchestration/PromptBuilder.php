<?php
namespace Domain\Orchestration;

use Domain\Agents\Agent;
use Infrastructure\Markdown\MarkdownFileLoader;
use Infrastructure\Logging\Logger;
use Infrastructure\Persistence\ContextDocumentChunkRepository;

class PromptBuilder {
    /** Upper bound aligned with ContextDocumentController (storage). */
    public const MAX_CONTEXT_STORAGE_CHARS = 50000;
    /** Max characters injected into model prompts (UTF-8); rest truncated with flag. */
    public const MAX_CONTEXT_INJECT_CHARS = 32000;

    private string $storageDir;
    private MarkdownFileLoader $loader;
    private Logger $logger;

    /** @var array<string, mixed> Metadata from last buildContextDocumentContent FTS step (merged into prompt logs). */
    private array $lastRetrievalLogMeta = [];

    /** @var array<string, list<array{id:int,chunk_index:int,content:string,rank:float}>> */
    private static array $ftsRetrievalResultCache = [];

    private const FTS_CACHE_MAX_ENTRIES = 64;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../../storage';
        $this->loader     = new MarkdownFileLoader($this->storageDir);
        $this->logger     = new Logger();
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

If a claim is not supported by the **Shared Context Document** in this task:

- explicitly label it as **unsupported**
- do NOT rely on prior knowledge as a substitute for missing context
- do NOT fabricate citations

When a "## Retrieved excerpts" section appears in the user message, you may reference those rows as **[E1], [E2], …** only when the cited text is clearly relevant; you are not required to cite in every sentence.

TEXT;
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

    public function buildChatMessages(
        Agent $agent,
        string $sessionContext,
        array $conversationHistory,
        string $userMessage,
        string $language = 'en',
        ?array $contextDoc = null,
        ?string $retrievalSessionId = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'chat', $language);
        $contextPrefix = $this->buildContextDocumentContent(
            $contextDoc,
            $retrievalSessionId,
            $sessionContext,
            $userMessage
        );
        $userContent   = $contextPrefix . $this->buildUserContent($sessionContext, $conversationHistory, $userMessage, null);

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        $this->logger->logPromptBuild('prompt_built_chat', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'chat',
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

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
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'decision-room', $language);

        $roundPolicy      = new RoundPolicy();
        $roundInstruction = $roundPolicy->getRoundInstruction($round, $totalRounds, $forceStrongContradictionNext);

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective:** $objective\n\n";
        if (!empty($previousRoundMessages)) {
            $userContent .= "**Previous Round Contributions:**\n";
            foreach ($previousRoundMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $userContent .= "\n**[$agentLabel]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
        }
        if (!empty($memoryContext['argument_memory_summary'])) {
            $userContent .= "# Argument Memory (summary)\n\n";
            $userContent .= $memoryContext['argument_memory_summary'] . "\n\n";
            $userContent .= "Instructions:\n";
            $userContent .= "- Do not repeat existing arguments unless refining them.\n";
            $userContent .= "- Challenge or extend existing arguments.\n";
            $userContent .= "- Refer explicitly to previous arguments when relevant.\n\n";
        }
        $socialPolicy = $this->loadPrompt('social-dynamics-policy') ?? '';
        if ($socialPolicy !== '') {
            $userContent .= "---\n" . $socialPolicy . "\n---\n\n";
        }
        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }
        $userContent .= "**Your Task:** $roundInstruction\n\n";
        if ($round > 1 && $agent->id !== 'synthesizer') {
            $userContent .= $this->buildTargetAgentHint($agent->id, $previousRoundMessages, $assignedTarget);
        }
        $userContent .= "Use your default response format.";
        $userContent .= $this->buildWeightedOpinionInstruction();

        if ($forceDisagreement) {
            $mode = $agent->id === 'synthesizer' ? 'synthesizer' : 'default';
            $userContent .= $this->buildForcedDisagreementInstruction($mode);
        }

        if ($agent->id === 'synthesizer' && $round === $totalRounds) {
            $userContent .= $this->buildFinalVerdictInstruction();
        } elseif ($round === $totalRounds) {
            $userContent .= $this->buildFinalVoteInstruction();
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        $this->logger->logPromptBuild('prompt_built_decision_room', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'decision-room',
                'round' => $round,
                'total_rounds' => $totalRounds,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

        return $msgs;
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

        if (!empty($previousMessages)) {
            $userContent .= "**Previous contributions:**\n";
            foreach ($previousMessages as $msg) {
                $agentId  = $msg['agent_id'] ?? 'Agent';
                $phaseName = $msg['phase'] ?? '';
                $userContent .= "\n**[$agentId]** *(Phase: $phaseName)*: {$msg['content']}\n";
            }
            $userContent .= "\n";
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
        $this->logger->logPromptBuild('prompt_built_confrontation_phase', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'confrontation',
                'phase' => $phaseKey,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemFull, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);
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
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'confrontation', $language);

        $instruction = $this->getConfrontationRoundInstruction(
            $currentRound, $totalRounds, $interactionStyle, $agent->id, $previousMessages, $assignedTarget,
            $forceStrongContradictionNext
        );

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective under debate:** $objective\n\n";

        if (!empty($previousMessages)) {
            $userContent .= "**Previous Round Contributions:**\n";
            foreach ($previousMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $target     = !empty($msg['target_agent_id']) ? " → replying to [{$msg['target_agent_id']}]" : '';
                $userContent .= "\n**[$agentLabel]**{$target}: {$msg['content']}\n";
            }
            $userContent .= "\n";
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

        $userContent .= "**Your task:** $instruction";
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

        $this->logger->logPromptBuild('prompt_built_confrontation', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'confrontation',
                'round' => $currentRound,
                'total_rounds' => $totalRounds,
                'interaction_style' => $interactionStyle,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

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

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective debated:** $objective\n\n";
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
        $userContent .= $this->buildFinalVerdictInstruction();

        if ($forceDisagreement) {
            $userContent .= $this->buildForcedDisagreementInstruction('synthesizer');
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        $this->logger->logPromptBuild('prompt_built_confrontation_synthesis', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'confrontation',
                'synthesis' => true,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['weighted_analysis']),
                'force_disagreement' => (bool)$forceDisagreement,
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

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
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'quick-decision', $language);

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective:** $objective\n\n";

        $isSynthesizer = $agent->id === 'synthesizer';

        if (!empty($previousMessages)) {
            $userContent .= "**Other agents' analyses:**\n";
            foreach ($previousMessages as $msg) {
                $userContent .= "\n**[{$msg['agent_id']}]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        } elseif (!$isSynthesizer && count($previousMessages) >= 1) {
            $userContent .= "> **Brief contradiction pass:** Respond to another agent explicitly — cite what you endorse or contest one concrete assumption.\n";
            $userContent .= "> Keep it concise; **challenge reasoning, never the person**.\n\n";
        }

        if ($isSynthesizer) {
            $userContent .= "**Your task:** Synthesize the analyses above into a final recommendation.\n";
            $userContent .= "Format: Conclusion, Key Risks, Recommended Action.\n";
            $userContent .= $this->buildFinalVerdictInstruction();
            if ($forceDisagreement) {
                $userContent .= $this->buildForcedDisagreementInstruction('synthesizer');
            }
        } else {
            $userContent .= "**Your task (QUICK DECISION):** Give a concise decision-oriented analysis.\n\n";
            $userContent .= "Use this exact format:\n\n## Strongest Argument\n(one key argument for this direction)\n\n## Biggest Risk\n(the single most critical risk)\n\n## Recommendation\n(clear yes/no/conditional recommendation)";
            if ($forceDisagreement) {
                $userContent .= $this->buildForcedDisagreementInstruction();
            }
            $userContent .= $this->buildFinalVoteInstruction();
        }

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        $this->logger->logPromptBuild('prompt_built_quick_decision', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'quick-decision',
                'synthesizer' => ($agent->id === 'synthesizer'),
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'force_disagreement' => (bool)$forceDisagreement,
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

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
        ?string $retrievalLastUserMessage = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'stress-test', $language);

        $roundPolicy = new RoundPolicy();

        $userContent  = $this->buildContextDocumentContent(
            $contextDoc, $retrievalSessionId, $objective, $retrievalLastUserMessage
        );
        $userContent .= "**Objective to stress-test:** $objective\n\n";

        if (!empty($previousRoundMessages)) {
            $userContent .= "**Previous round analyses:**\n";
            foreach ($previousRoundMessages as $msg) {
                $agentLabel = $msg['agent_id'] ?? 'Agent';
                $userContent .= "\n**[$agentLabel]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
        }
        if (!empty($memoryContext['argument_memory_summary'])) {
            $userContent .= "# Argument Memory (summary)\n\n";
            $userContent .= $memoryContext['argument_memory_summary'] . "\n\n";
        }

        if ($socialDynamicsBlock !== null && $socialDynamicsBlock !== '') {
            $userContent .= $socialDynamicsBlock;
        }

        $isSynthesizer = ($agent->id === 'synthesizer');

        if ($isSynthesizer && $round === $totalRounds) {
            $userContent .= "**Your task — STRESS TEST REPORT (FINAL SYNTHESIS):**\n\n";
            $userContent .= "Based on all the agents' risk analyses, produce the final Stress Test Report using EXACTLY this structure:\n\n";
            $userContent .= "# Stress Test Report\n\n";
            $userContent .= "## Most Likely Failure Modes\n(list 3-5 realistic scenarios where this fails)\n\n";
            $userContent .= "## Highest Impact Risks\n(risks with the most severe consequences)\n\n";
            $userContent .= "## Weakest Assumptions\n(assumptions that, if wrong, kill the idea)\n\n";
            $userContent .= "## Mitigations\n(concrete actions to reduce each major risk)\n\n";
            $userContent .= "## Kill Criteria\n(explicit conditions under which you should stop/pivot)\n\n";
            $userContent .= "## Recommended Next Step\n(the single most important action to de-risk before investing more)\n\n";
            $userContent .= $this->buildFinalVerdictInstruction();
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

        $this->logger->logPromptBuild('prompt_built_stress_test', [
            'agent_id' => $agent->id,
            'metadata' => array_merge([
                'mode' => 'stress-test',
                'round' => $round,
                'total_rounds' => $totalRounds,
                'synthesizer' => ($agent->id === 'synthesizer'),
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ], $this->contextPromptLogMeta($contextDoc), $this->ftsRetrievalPromptLogMeta()),
        ]);

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

        $this->logger->logPromptBuild('prompt_built_action_plan', [
            'metadata' => [
                'mode' => 'action-plan',
                'message_count' => count($msgs),
                'character_count' => mb_strlen($system, 'UTF-8') + mb_strlen($user, 'UTF-8'),
                'language' => $language,
            ],
        ]);

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
        return "\n\n---\n\n**REQUIRED FINAL SECTION — DO NOT SKIP:**\n\nAfter your analysis, you MUST include this exact section:\n\n# Final Verdict\n\n## Verdict Label\none of: go | no-go | risky | needs-more-info | reduce-scope\n\n## Verdict Summary\nshort explanation (2-3 sentences)\n\n## Scores\n- Feasibility: X/10\n- Product Value: X/10\n- UX: X/10\n- Risk: X/10 (10 = high risk)\n- Confidence: X/10\n\n## Recommended Action\nclear next step";
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
            . "## Change Since Last Round\n...\n";
    }

    private function buildFinalVoteInstruction(): string {
        return "\n\n---\n\nAt the end of your response, you MUST include this exact section:\n\n"
            . "# Final Vote\n\n"
            . "## Vote\none of: go | no-go | reduce-scope | needs-more-info | pivot\n\n"
            . "## Confidence\n0-10\n\n"
            . "## Impact\n0-10\n\n"
            . "## Domain Weight\n0-10\n\n"
            . "## Rationale\nshort explanation\n\n"
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

        if ($soulContent) {
            $parts[] = "---\n## Your Soul / Personality";
            $parts[] = $soulContent;
        }

        $parts[] = "---\n## Orchestration Mode: $mode";
        $parts[] = $orchestratorPolicy;
        $parts[] = "---\n**You are {$agent->persona->name}, the {$agent->persona->title}. Answer ONLY as yourself.**";

        $parts[] = trim($this->buildEvidenceDisciplineSystemBlock());

        if ($language === 'fr') {
            $parts[] = "---\n## INSTRUCTION DE LANGUE OBLIGATOIRE\n**Tu dois répondre UNIQUEMENT en français. Toutes tes réponses doivent être rédigées en français, sans exception. Même si le contexte est en anglais, ta réponse doit être entièrement en français.**";
        } elseif ($language === 'en') {
            $parts[] = "---\n## LANGUAGE INSTRUCTION\n**Always respond in English.**";
        }

        return implode("\n\n", array_filter($parts));
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

    public function buildSynthesizerOutputFormatInstruction(): string
    {
        return <<<TEXT

Respond using EXACTLY this format (no extra sections, no free-form text outside these headings):

## Decision
GO | NO-GO | ITERATE | NO_CONSENSUS | INSUFFICIENT_CONTEXT

## Confidence
LOW | MEDIUM | HIGH

## Why
- (max 3 bullet points explaining the key reasons)

## Main Risks
- (max 3 bullet points)

## Reliability Warning
(one sentence — omit this section only if the decision is strong and reliable)

## Evidence warnings
(omit if none; otherwise max 3 bullets: Unsupported / Contradicted / Context limitation — use evidence signals from constraints only)

## Next Step
(one concrete actionable next step)

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
}

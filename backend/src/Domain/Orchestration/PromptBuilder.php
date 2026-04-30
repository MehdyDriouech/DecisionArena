<?php
namespace Domain\Orchestration;

use Domain\Agents\Agent;
use Infrastructure\Markdown\MarkdownFileLoader;
use Infrastructure\Logging\Logger;

class PromptBuilder {
    private string $storageDir;
    private MarkdownFileLoader $loader;
    private Logger $logger;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../../storage';
        $this->loader     = new MarkdownFileLoader($this->storageDir);
        $this->logger     = new Logger();
    }

    public function buildChatMessages(
        Agent $agent,
        string $sessionContext,
        array $conversationHistory,
        string $userMessage,
        string $language = 'en',
        ?array $contextDoc = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'chat', $language);
        $contextPrefix = $this->buildContextDocumentContent($contextDoc);
        $userContent   = $contextPrefix . $this->buildUserContent($sessionContext, $conversationHistory, $userMessage, null);

        $msgs = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $userContent],
        ];

        $this->logger->logPromptBuild('prompt_built_chat', [
            'agent_id' => $agent->id,
            'metadata' => [
                'mode' => 'chat',
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
            ],
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
        ?array $memoryContext = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'decision-room', $language);

        $roundPolicy      = new RoundPolicy();
        $roundInstruction = $roundPolicy->getRoundInstruction($round, $totalRounds);

        $userContent  = $this->buildContextDocumentContent($contextDoc);
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
        $userContent .= "**Your Task:** $roundInstruction\n\n";
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
            'metadata' => [
                'mode' => 'decision-room',
                'round' => $round,
                'total_rounds' => $totalRounds,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ],
        ]);

        return $msgs;
    }

    public function buildConfrontationMessages(
        Agent $agent,
        string $objective,
        array $previousMessages,
        string $phaseKey,
        int $phaseNumber,
        string $language = 'en'
    ): array {
        $systemContent      = $this->buildSystemContent($agent, 'confrontation', $language);
        $confrontationPolicy = $this->loadPrompt('confrontation-policy') ?? '';
        $phaseInstruction   = $this->getConfrontationPhaseInstruction($phaseKey, $agent->id);

        $userContent = "**Objective under debate:** $objective\n\n";

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

        $systemFull = $confrontationPolicy
            ? $systemContent . "\n\n---\n\n" . $confrontationPolicy
            : $systemContent;

        return [
            ['role' => 'system', 'content' => $systemFull],
            ['role' => 'user',   'content' => $userContent],
        ];
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
        ?array $memoryContext = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'confrontation', $language);

        $instruction = $this->getConfrontationRoundInstruction(
            $currentRound, $totalRounds, $interactionStyle, $agent->id, $previousMessages
        );

        $userContent  = $this->buildContextDocumentContent($contextDoc);
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
            'metadata' => [
                'mode' => 'confrontation',
                'round' => $currentRound,
                'total_rounds' => $totalRounds,
                'interaction_style' => $interactionStyle,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ],
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
        ?array $memoryContext = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'confrontation', $language);

        $userContent  = $this->buildContextDocumentContent($contextDoc);
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
            'metadata' => [
                'mode' => 'confrontation',
                'synthesis' => true,
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['weighted_analysis']),
                'force_disagreement' => (bool)$forceDisagreement,
            ],
        ]);

        return $msgs;
    }

    public function buildQuickDecisionMessages(
        Agent  $agent,
        string $objective,
        array  $previousMessages,
        string $language = 'en',
        bool   $forceDisagreement = false,
        ?array $contextDoc = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'quick-decision', $language);

        $userContent  = $this->buildContextDocumentContent($contextDoc);
        $userContent .= "**Objective:** $objective\n\n";

        if (!empty($previousMessages)) {
            $userContent .= "**Other agents' analyses:**\n";
            foreach ($previousMessages as $msg) {
                $userContent .= "\n**[{$msg['agent_id']}]:** {$msg['content']}\n";
            }
            $userContent .= "\n";
        }

        $isSynthesizer = $agent->id === 'synthesizer';

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
            'metadata' => [
                'mode' => 'quick-decision',
                'synthesizer' => ($agent->id === 'synthesizer'),
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'force_disagreement' => (bool)$forceDisagreement,
            ],
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
        ?array $memoryContext = null
    ): array {
        $systemContent = $this->buildSystemContent($agent, 'stress-test', $language);

        $userContent  = $this->buildContextDocumentContent($contextDoc);
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
            'metadata' => [
                'mode' => 'stress-test',
                'round' => $round,
                'total_rounds' => $totalRounds,
                'synthesizer' => ($agent->id === 'synthesizer'),
                'message_count' => count($msgs),
                'character_count' => mb_strlen($systemContent, 'UTF-8') + mb_strlen($userContent, 'UTF-8'),
                'context_doc_injected' => !empty($contextDoc['content']),
                'memory_injected' => !empty($memoryContext['argument_memory_summary']),
                'force_disagreement' => (bool)$forceDisagreement,
            ],
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

    private function buildContextDocumentContent(?array $contextDoc): string {
        if (!$contextDoc || empty($contextDoc['content'])) {
            return '';
        }

        $title     = $contextDoc['title']             ?? 'Context Document';
        $source    = $contextDoc['source_type']       ?? 'manual';
        $filename  = $contextDoc['original_filename'] ?? '';
        $charCount = $contextDoc['character_count']   ?? mb_strlen($contextDoc['content'], 'UTF-8');

        $out  = "# Shared Context Document\n\n";
        $out .= "**Title:** $title\n";
        $out .= "**Source:** $source\n";
        if ($filename) {
            $out .= "**Filename:** $filename\n";
        }
        $out .= "**Characters:** $charCount\n\n";
        $out .= "---\n\n";
        $out .= $contextDoc['content'];
        $out .= "\n\n---\n\n";
        $out .= "**Instructions for using this context:**\n";
        $out .= "- Use this context as shared background for your analysis.\n";
        $out .= "- Do not ignore it.\n";
        $out .= "- Do not copy large parts of it verbatim.\n";
        $out .= "- Separate context-based facts from assumptions.\n";
        $out .= "- If the context is unclear or contradictory, say so.\n\n";

        return $out;
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
        int    $currentRound,
        int    $totalRounds,
        string $interactionStyle,
        string $agentId,
        array  $prevMessages
    ): string {
        if ($currentRound === 1) {
            return "ROUND 1 — INITIAL POSITION: State your position clearly on the objective above. Present your strongest arguments, be specific and evidence-based. Use your default response format.";
        }

        if ($currentRound === $totalRounds) {
            return "FINAL ROUND — REVISED POSITION: Review all prior positions and objections. State your final, revised position. Indicate your confidence level (low / medium / high). Acknowledge what, if anything, changed your mind. Use your default response format.";
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
                . "Do not repeat your previous answer.";
        }

        return "CHALLENGE ROUND — CRITICAL ANALYSIS: Review the previous round's positions. "
            . "Challenge the weakest argument you see with specific counter-evidence. "
            . "Update your own position if warranted. Avoid generic agreement. "
            . "Use your default response format.";
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
}

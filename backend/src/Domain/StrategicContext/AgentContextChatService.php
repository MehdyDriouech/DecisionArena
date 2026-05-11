<?php
declare(strict_types=1);

namespace Domain\StrategicContext;

use Domain\Agents\AgentAssembler;
use Domain\CognitiveGovernance\PromptInjectionRegistry;
use Domain\Orchestration\CognitiveBudgetEngine;
use Domain\Orchestration\PromptBuilder;
use Domain\Orchestration\PromptInjectionTraceCollector;
use Domain\Providers\ProviderRouter;
use Infrastructure\Persistence\AgentContextChatRepository;
use Infrastructure\Persistence\AgentRelationshipRepository;
use Infrastructure\Persistence\DecisionMemoryRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\StrategicContextRepository;

/**
 * Chat « situé » : un agent, un strategic context, sans session décisionnelle ni /api/chat/send.
 */
final class AgentContextChatService
{
    private AgentContextMemoryService $memorySvc;
    private StrategicContextRepository $contextRepo;
    private AgentContextChatRepository $chatRepo;
    private DecisionMemoryRepository $memoryTableRepo;
    private AgentRelationshipRepository $relRepo;
    private SessionRepository $sessionRepo;
    private SessionAgentProvidersRepository $sapRepo;
    private AgentAssembler $assembler;
    private ProviderRouter $router;
    private PromptBuilder $promptBuilder;
    private BeliefEngineService $beliefsSvc;
    private StrategicNarrativeService $narrativeSvc;
    private ContextSnapshotService $snapshotSvc;
    private MemoryCompilerService $memoryCompilerSvc;

    /**
     * @param ProviderRouter|null $router Injecté pour les tests CLI (mock sans LLM) ; défaut = router réel.
     */
    public function __construct(?ProviderRouter $router = null)
    {
        $this->memorySvc = new AgentContextMemoryService();
        $this->contextRepo = new StrategicContextRepository();
        $this->chatRepo = new AgentContextChatRepository();
        $this->memoryTableRepo = new DecisionMemoryRepository();
        $this->relRepo = new AgentRelationshipRepository();
        $this->sessionRepo = new SessionRepository();
        $this->sapRepo = new SessionAgentProvidersRepository();
        $this->assembler = new AgentAssembler();
        $this->router = $router ?? new ProviderRouter();
        $this->promptBuilder = new PromptBuilder();
        $this->beliefsSvc = new BeliefEngineService();
        $this->narrativeSvc = new StrategicNarrativeService();
        $this->snapshotSvc = new ContextSnapshotService();
        $this->memoryCompilerSvc = new MemoryCompilerService();
    }

    /**
     * @return array{ok:true,context_id:string,agent_id:string,answer:string,memory_used:bool,decisions_used:list<array<string,mixed>>,social_context_used:bool,conversation_id:string,warnings:list<string>}|array{ok:false,message:string,code:int}
     */
    public function exchange(
        string $contextId,
        string $agentId,
        string $userMessage,
        bool $includeMemory,
        bool $includeDecisions,
        bool $includeSocial,
        ?string $conversationIdIn,
        ?string $optionalSessionId,
        string $language
    ): array {
        $contextId = trim($contextId);
        $agentId = trim($agentId);
        $userMessage = trim($userMessage);
        $language = strtolower(trim($language)) === 'fr' ? 'fr' : 'en';

        if (!$this->memorySvc->isValidContextUuid($contextId)) {
            return ['ok' => false, 'message' => 'Invalid context id', 'code' => 400];
        }
        if (!$this->memorySvc->isValidAgentId($agentId)) {
            return ['ok' => false, 'message' => 'Invalid agent id', 'code' => 400];
        }
        if (in_array(strtolower($agentId), ['synthesizer', 'devil_advocate'], true)) {
            return ['ok' => false, 'message' => 'Agent not available for situated chat', 'code' => 400];
        }
        if ($userMessage === '') {
            return ['ok' => false, 'message' => 'message required', 'code' => 400];
        }
        if (mb_strlen($userMessage, 'UTF-8') > 8000) {
            return ['ok' => false, 'message' => 'message too long', 'code' => 400];
        }

        $ctxRow = $this->contextRepo->find($contextId);
        if (!$ctxRow) {
            return ['ok' => false, 'message' => 'Context not found', 'code' => 404];
        }

        $warnings = [];
        $linkSessionId = null;
        if ($optionalSessionId !== null && trim($optionalSessionId) !== '') {
            $sid = trim($optionalSessionId);
            if ($this->sessionAllowedForContext($contextId, $sid)) {
                $linkSessionId = $sid;
            } else {
                $warnings[] = $language === 'fr'
                    ? 'session_id ignoré : la session n’est pas rattachée à ce contexte stratégique.'
                    : 'session_id ignored: session is not bound to this strategic context.';
            }
        }

        $conversationId = trim((string)$conversationIdIn);
        if ($conversationId === '') {
            $conversationId = $this->uuid();
            $this->chatRepo->createConversation($conversationId, $contextId, $agentId, $linkSessionId);
        } else {
            $conv = $this->chatRepo->findConversation($conversationId);
            if (!$conv) {
                return ['ok' => false, 'message' => 'conversation not found', 'code' => 404];
            }
            if (strtolower((string)$conv['strategic_context_id']) !== strtolower($contextId)
                || strtolower((string)$conv['agent_id']) !== strtolower($agentId)) {
                return ['ok' => false, 'message' => 'conversation mismatch for context or agent', 'code' => 400];
            }
        }

        $agent = $this->assembler->assemble($agentId, null, null, null);
        if ($agent === null) {
            return ['ok' => false, 'message' => 'Agent not found', 'code' => 404];
        }

        $memoryBlock = '';
        if ($includeMemory) {
            $this->memorySvc->ensureFile($contextId, $agentId);
            $memoryBlock = $this->memorySvc->buildPromptInjectionBlock($contextId, $agentId);
        }
        $memoryUsed = $includeMemory && trim($memoryBlock) !== '';

        $decisionsUsed = [];
        $decisionsBlock = '';
        if ($includeDecisions) {
            $rows = $this->memoryTableRepo->findLinkedMemoriesForStrategicContext($contextId, 12);
            if ($rows === []) {
                $warnings[] = $language === 'fr'
                    ? 'Aucune Decision Memory liée à ce contexte pour le moment.'
                    : 'No Decision Memory linked to this context yet.';
            }
            foreach ($rows as $row) {
                $mid = (string)($row['memory_id'] ?? '');
                $sum = $this->truncate((string)($row['decision_summary'] ?? ''), 420);
                $decisionsUsed[] = [
                    'memory_id' => $mid,
                    'decision_summary' => $sum,
                    'playbook_id' => (string)($row['playbook_id'] ?? ''),
                    'decision_status' => (string)($row['decision_status'] ?? ''),
                ];
                $decisionsBlock .= "- **{$mid}** [{$row['playbook_id']}] ({$row['decision_status']}): {$sum}\n";
            }
            if ($decisionsBlock !== '') {
                $hdr = $language === 'fr'
                    ? "## Mémoires de décision liées à ce contexte (extraits)\nNe présume pas d’autres décisions hors liste.\n\n"
                    : "## Decision memories linked to this context (excerpts)\nDo not assume other decisions outside this list.\n\n";
                $decisionsBlock = $hdr . $decisionsBlock;
            }
        }

        $socialUsed = false;
        $socialBlock = '';
        if ($includeSocial) {
            $rels = $this->relRepo->findByStrategicContext($contextId);
            $lines = [];
            foreach ($rels as $r) {
                $src = (string)($r['source_agent_id'] ?? '');
                $tgt = (string)($r['target_agent_id'] ?? '');
                if (strcasecmp($src, $agentId) !== 0 && strcasecmp($tgt, $agentId) !== 0) {
                    continue;
                }
                $socialUsed = true;
                $other = strcasecmp($src, $agentId) === 0 ? $tgt : $src;
                $lines[] = '- **' . $other . '** — affinity ' . ($r['affinity'] ?? 0)
                    . ', trust ' . ($r['trust'] ?? 0) . ', conflict ' . ($r['conflict'] ?? 0)
                    . '; last: ' . (string)($r['last_interaction_type'] ?? '');
            }
            if ($lines === []) {
                $warnings[] = $language === 'fr'
                    ? 'Aucune relation sociale enregistrée pour vous dans ce contexte.'
                    : 'No social relationships recorded for you in this context.';
            } else {
                $socialBlock = ($language === 'fr'
                    ? "## Votre graphe social dans ce contexte\n"
                    : "## Your social graph in this context\n") . implode("\n", $lines) . "\n";
            }
        }

        $beliefsRuntime = $this->beliefsSvc->getBeliefsRuntimeProjection($contextId);
        $beliefsAll = is_array($beliefsRuntime['beliefs'] ?? null) ? $beliefsRuntime['beliefs'] : [];
        $contestedBeliefs = is_array($beliefsRuntime['contested'] ?? null) ? $beliefsRuntime['contested'] : [];
        $invalidatedBeliefs = is_array($beliefsRuntime['invalidated'] ?? null) ? $beliefsRuntime['invalidated'] : [];

        $beliefsPrioritizedLines = [];
        $beliefsContestedLines = [];
        $beliefsFragileLines = [];
        $beliefsInvalidatedLines = [];
        foreach ($beliefsAll as $b) {
            if (!is_array($b)) {
                continue;
            }
            $txt = trim((string)($b['belief_text'] ?? ''));
            if ($txt === '') {
                continue;
            }
            $beliefAgent = trim((string)($b['agent_id'] ?? 'groupe'));
            if ($beliefAgent === '') {
                $beliefAgent = 'groupe';
            }
            $conf = (float)($b['confidence'] ?? 0.5);
            $state = (string)($b['contestation_state'] ?? 'weak');
            $line = sprintf('- [%s] %s (conf %.2f, état=%s)', $beliefAgent, $this->truncate($txt, 180), $conf, $state);
            if (in_array($state, ['stable', 'reinforced'], true)) {
                $beliefsPrioritizedLines[] = $line;
            }
            if (in_array($state, ['contested', 'unstable'], true)) {
                $beliefsContestedLines[] = $line;
            }
            if (in_array($state, ['weak', 'derived'], true) || $conf < 0.45) {
                $beliefsFragileLines[] = $line;
            }
            if ($state === 'invalidated') {
                $beliefsInvalidatedLines[] = $line;
            }
        }
        $beliefsPrioritizedLines = array_slice($beliefsPrioritizedLines, 0, 7);
        $beliefsContestedLines = array_slice($beliefsContestedLines, 0, 7);
        $beliefsFragileLines = array_slice($beliefsFragileLines, 0, 7);
        $beliefsInvalidatedLines = array_slice($beliefsInvalidatedLines, 0, 6);

        $narrativeResponse = $this->narrativeSvc->getApiResponse($contextId);
        $narrative = is_array($narrativeResponse['narrative'] ?? null) ? $narrativeResponse['narrative'] : [];
        $narrativeWarnings = is_array($narrativeResponse['warnings'] ?? null) ? $narrativeResponse['warnings'] : [];

        $snapshots = $this->snapshotSvc->listSnapshots($contextId, ['limit' => 3]);
        $compilations = $this->memoryCompilerSvc->listCompilations($contextId, ['status' => 'active', 'limit' => 3]);

        $ctxTitle = (string)($ctxRow['title'] ?? '');
        $ctxDesc = $this->truncate((string)($ctxRow['description'] ?? ''), 2400);
        $ctxStatus = (string)($ctxRow['status'] ?? '');
        $strategicBlock = ($language === 'fr'
            ? "## Contexte stratégique (workspace)\n**ID:** {$contextId}\n**Titre:** {$ctxTitle}\n**Statut:** {$ctxStatus}\n\n{$ctxDesc}\n\nVous ne devez **pas** vous référer à d’autres contextes stratégiques.\n"
            : "## Strategic context (workspace)\n**ID:** {$contextId}\n**Title:** {$ctxTitle}\n**Status:** {$ctxStatus}\n\n{$ctxDesc}\n\nYou must **not** refer to other strategic contexts.\n");

        $personaMd = $this->truncate((string)($agent->persona->content ?? ''), 4500);
        $soulMd = $agent->soul !== null ? $this->truncate((string)$agent->soul->content, 2200) : '';

        $instr = $language === 'fr' ? <<<'FR'

## Consignes (chat situé)

- Répondez **dans votre rôle** ({persona}) pour ce contexte stratégique uniquement.
- Distinguez clairement : **ce que vous savez** (faits présents ci-dessous), **ce que vous inférez**, **ce que vous ignorez**.
- Ne prétendez pas connaître un autre workspace ou des décisions absentes des extraits fournis.
- Si les listes « mémoires » ou « social » sont vides, dites-le explicitement plutôt que d’inventer.
- Répondez en français si l’utilisateur écrit en français ; sinon adaptez-vous à la langue de l’utilisateur.

FR
            : <<<'EN'

## Instructions (situated chat)

- Answer **in character** ({persona}) for **this strategic context only**.
- Clearly separate: **what you know** (from the materials below), **what you infer**, and **what you do not know**.
- Do not claim knowledge of other workspaces or decisions not shown in the excerpts.
- If decision or social sections are empty, say so explicitly rather than inventing.
- Match the user’s language when reasonable.

EN;
        $instr = str_replace('{persona}', $agent->persona->name ?? $agentId, $instr);

        $system = ($language === 'fr'
            ? "Vous participez à un **chat situé** : une conversation entre l’utilisateur et vous, **un seul agent**, dans **un seul** contexte stratégique.\n"
            : "You are in a **situated chat**: a conversation between the user and **you alone**, in **one** strategic context.\n")
            . $instr
            . "\n---\n## Persona\n" . $personaMd . "\n";
        if ($soulMd !== '') {
            $system .= "\n---\n## Soul modifier\n" . $soulMd . "\n";
        }
        $system .= $this->promptBuilder->buildEvidenceDisciplineSystemBlock();
        $system .= "\n\n---\n\n";
        $system .= $language === 'fr'
            ? "## Contrat de réflexion cognitive (obligatoire)\n"
            . "- Distinguez explicitement : **Known**, **Inferred**, **Uncertain**, **Contested**, **Forgotten**.\n"
            . "- Répondez aux questions réflexives quand elles sont posées :\n"
            . "  - « Pourquoi pensais-tu cela ? »\n"
            . "  - « Qu’est-ce qui a changé ? »\n"
            . "  - « Quelles hypothèses restent fragiles ? »\n"
            . "  - « Quelles croyances sont contestées ? »\n"
            . "- N’inventez jamais de souvenir ; utilisez uniquement les blocs situés ci-dessous.\n"
            . "- Si une information manque, dites « inconnu dans ce contexte ».\n"
            . "\n"
            : "## Cognitive self-reflection contract (mandatory)\n"
            . "- Explicitly separate: **Known**, **Inferred**, **Uncertain**, **Contested**, **Forgotten**.\n"
            . "- Handle reflective questions when asked:\n"
            . "  - \"Why did you think that?\"\n"
            . "  - \"What changed?\"\n"
            . "  - \"Which assumptions remain fragile?\"\n"
            . "  - \"Which beliefs are contested?\"\n"
            . "- Never invent memories; use only the situated blocks below.\n"
            . "- If data is missing, say \"unknown in this context\".\n"
            . "\n";

        PromptInjectionTraceCollector::begin([
            'mode' => 'situated-chat',
            'session_id' => $linkSessionId,
            'strategic_context_id' => $contextId,
            'agent_id' => $agentId,
            'inject_strategic_narrative' => true,
            'inject_beliefs_runtime' => true,
        ]);

        $sourcesUsed = [];
        $cognitiveDisclaimers = [];
        $contestedSummary = [];

        $this->appendSituatedSegmentBudgeted(
            $system,
            'situated_chat_context',
            'situated_chat',
            "\n---\n" . $strategicBlock . "\n",
            'situated_context_scope_anchor'
        );

        if ($memoryBlock !== '') {
            $sourcesUsed[] = 'agent_context_memory';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'agent_context_memory',
                'situated_memory',
                "\n" . $memoryBlock . "\n",
                'explicit_agent_memory_block'
            );
        }
        if ($decisionsBlock !== '') {
            $sourcesUsed[] = 'decision_memories';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'decision_memory_system',
                'decision_memory',
                "\n---\n" . $decisionsBlock . "\n",
                'linked_decision_memories_excerpt'
            );
        }
        if ($socialBlock !== '') {
            $sourcesUsed[] = 'social_dynamics';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'social_dynamics_context',
                'social_runtime',
                "\n---\n" . $socialBlock . "\n",
                'context_social_relations_excerpt'
            );
        }

        if ($beliefsPrioritizedLines !== []) {
            $sourcesUsed[] = 'beliefs_prioritized';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'beliefs_prioritized',
                'beliefs',
                "\n\n## Known (beliefs prioritized)\n" . implode("\n", $beliefsPrioritizedLines) . "\n",
                'beliefs_runtime_prioritized',
                ['belief_count' => count($beliefsPrioritizedLines)]
            );
        }
        if ($beliefsContestedLines !== []) {
            $sourcesUsed[] = 'beliefs_contested';
            $contestedSummary = array_values(array_map(
                fn ($x) => $this->truncate((string)$x, 210),
                $beliefsContestedLines
            ));
            $this->appendSituatedSegmentBudgeted(
                $system,
                'beliefs_contested',
                'beliefs',
                "\n\n## Contested (beliefs currently challenged)\n" . implode("\n", $beliefsContestedLines) . "\n",
                'beliefs_runtime_contested',
                ['belief_count' => count($beliefsContestedLines)]
            );
        }
        if ($beliefsFragileLines !== []) {
            $sourcesUsed[] = 'beliefs_fragile_assumptions';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'beliefs_fragile_assumptions',
                'beliefs',
                "\n\n## Uncertain (fragile assumptions)\n" . implode("\n", $beliefsFragileLines) . "\n",
                'beliefs_runtime_fragile',
                ['belief_count' => count($beliefsFragileLines)]
            );
        }
        if ($beliefsInvalidatedLines !== []) {
            $sourcesUsed[] = 'beliefs_invalidated_optional';
            $cognitiveDisclaimers[] = $language === 'fr'
                ? 'Des beliefs invalidés sont présents: utilisez-les uniquement comme historique ("Forgotten"), jamais comme faits actifs.'
                : 'Invalidated beliefs are present: treat them as historical "Forgotten", never as active facts.';
            $this->appendSituatedSegmentBudgeted(
                $system,
                'beliefs_invalidated_optional',
                'beliefs',
                "\n\n## Forgotten (invalidated beliefs)\n" . implode("\n", $beliefsInvalidatedLines) . "\n",
                'beliefs_runtime_invalidated_optional',
                ['belief_count' => count($beliefsInvalidatedLines)]
            );
        }

        if ($narrative !== []) {
            $sourcesUsed[] = 'strategic_narrative';
            $headline = trim((string)($narrative['current_direction'] ?? ''));
            $assumptions = is_array($narrative['key_assumptions'] ?? null) ? array_slice($narrative['key_assumptions'], 0, 5) : [];
            $narrativeLines = [];
            if ($headline !== '') {
                $narrativeLines[] = '- Direction: ' . $this->truncate($headline, 260);
            }
            foreach ($assumptions as $a) {
                $narrativeLines[] = '- Assumption: ' . $this->truncate((string)$a, 220);
            }
            if ($narrativeWarnings !== []) {
                foreach (array_slice($narrativeWarnings, 0, 4) as $nw) {
                    $narrativeLines[] = '- Warning: ' . $this->truncate((string)$nw, 180);
                }
            }
            if ($narrativeLines !== []) {
                $this->appendSituatedSegmentBudgeted(
                    $system,
                    'strategic_narrative_echo',
                    'derived_narrative',
                    "\n\n## Inferred (strategic narrative, derived and non-canonical)\n" . implode("\n", $narrativeLines) . "\n",
                    'strategic_narrative_derived_echo',
                    ['line_count' => count($narrativeLines)]
                );
            }
        }

        if ($snapshots !== []) {
            $sourcesUsed[] = 'context_snapshots';
            $snapshotLines = [];
            foreach (array_slice($snapshots, 0, 3) as $snap) {
                if (!is_array($snap)) {
                    continue;
                }
                $snapshotLines[] = sprintf(
                    '- %s [%s] %s',
                    (string)($snap['created_at'] ?? ''),
                    (string)($snap['snapshot_type'] ?? ''),
                    $this->truncate((string)($snap['title'] ?? ''), 140)
                );
            }
            if ($snapshotLines !== []) {
                $this->appendSituatedSegmentBudgeted(
                    $system,
                    'context_snapshot_echo',
                    'snapshot',
                    "\n\n## What changed (recent snapshots, historical only)\n" . implode("\n", $snapshotLines) . "\n",
                    'recent_snapshot_history',
                    ['snapshot_count' => count($snapshotLines)]
                );
            }
        }

        if ($compilations !== []) {
            $sourcesUsed[] = 'memory_compilations';
            $compLines = [];
            foreach (array_slice($compilations, 0, 3) as $comp) {
                if (!is_array($comp)) {
                    continue;
                }
                $compLines[] = sprintf(
                    '- [%s] %s (stability %.2f, confidence %.2f)',
                    (string)($comp['compilation_type'] ?? ''),
                    $this->truncate((string)($comp['summary'] ?? ''), 180),
                    (float)($comp['stability_score'] ?? 0),
                    (float)($comp['confidence'] ?? 0)
                );
            }
            if ($compLines !== []) {
                $this->appendSituatedSegmentBudgeted(
                    $system,
                    'compiled_memory_echo',
                    'compiled_memory',
                    "\n\n## Strategic memory compilations (derived)\n" . implode("\n", $compLines) . "\n",
                    'active_memory_compilations_summary',
                    ['compilation_count' => count($compLines)]
                );
            }
        }

        if ($contestedBeliefs !== []) {
            $cognitiveDisclaimers[] = $language === 'fr'
                ? 'Certaines croyances sont contestées: explicitez les désaccords au lieu de simuler un consensus.'
                : 'Some beliefs are contested: make disagreements explicit instead of simulating consensus.';
        }
        if ($invalidatedBeliefs !== []) {
            $cognitiveDisclaimers[] = $language === 'fr'
                ? 'Des croyances invalidées existent: signalez-les comme obsolètes (Forgotten).'
                : 'Invalidated beliefs exist: surface them as obsolete (Forgotten).';
        }
        if ($snapshots === []) {
            $cognitiveDisclaimers[] = $language === 'fr'
                ? 'Aucun snapshot récent disponible: indiquer les limites temporelles.'
                : 'No recent snapshot available: highlight temporal limitations.';
        }

        $prior = $this->chatRepo->listMessages($conversationId, 100);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($prior as $pm) {
            $role = strtolower($pm['role']) === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $pm['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $userMsgId = $this->uuid();
        $this->chatRepo->insertMessage($userMsgId, $conversationId, 'user', $userMessage);

        $override = null;
        if ($linkSessionId !== null) {
            $all = $this->sapRepo->findBySession($linkSessionId);
            $override = $all[$agentId] ?? null;
        }

        try {
            $routed = $this->router->chat($messages, $agent, null, null, $override);
            $answer = (string)($routed['content'] ?? '');
        } catch (\Throwable $e) {
            // Évite un message utilisateur orphelin si le provider échoue après persistance du tour user.
            try {
                $this->chatRepo->deleteMessageById($userMsgId);
            } catch (\Throwable $_) {
            }
            PromptInjectionTraceCollector::cancel();
            return ['ok' => false, 'message' => 'LLM error: ' . $e->getMessage(), 'code' => 502];
        }

        $asstId = $this->uuid();
        $this->chatRepo->insertMessage($asstId, $conversationId, 'assistant', $answer);
        $this->chatRepo->touchConversation($conversationId);

        $promptRuntimeTrace = PromptInjectionTraceCollector::finish();

        return [
            'ok' => true,
            'context_id' => $contextId,
            'agent_id' => $agentId,
            'answer' => $answer,
            'memory_used' => $memoryUsed,
            'decisions_used' => $decisionsUsed,
            'social_context_used' => $includeSocial && $socialUsed,
            'conversation_id' => $conversationId,
            'warnings' => array_values($warnings),
            'cognitive_runtime' => [
                'sources_used' => array_values(array_unique($sourcesUsed)),
                'disclaimers' => array_values(array_unique($cognitiveDisclaimers)),
                'contested_beliefs' => array_slice($contestedSummary, 0, 6),
                'belief_counts' => is_array($beliefsRuntime['counts'] ?? null) ? $beliefsRuntime['counts'] : [],
                'narrative_warnings' => array_values(array_map('strval', $narrativeWarnings)),
                'snapshots_used' => count($snapshots),
                'memory_compilations_used' => count($compilations),
            ],
            'prompt_injection_trace' => $promptRuntimeTrace,
        ];
    }

    private function sessionAllowedForContext(string $contextId, string $sessionId): bool
    {
        $sess = $this->sessionRepo->findById($sessionId);
        if (!$sess) {
            return false;
        }
        $sc = trim((string)($sess['strategic_context_id'] ?? ''));
        if ($sc !== '' && strcasecmp($sc, $contextId) === 0) {
            return true;
        }
        $linked = $this->contextRepo->linkedSessionIds($contextId);
        foreach ($linked as $lid) {
            if (strcasecmp((string)$lid, $sessionId) === 0) {
                return true;
            }
        }
        return false;
    }

    private function truncate(string $s, int $maxChars): string
    {
        if (mb_strlen($s, 'UTF-8') <= $maxChars) {
            return $s;
        }
        return mb_substr($s, 0, $maxChars - 1, 'UTF-8') . '…';
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function appendSituatedSegmentBudgeted(
        string &$target,
        string $blockId,
        string $layer,
        string $segment,
        string $reason,
        array $extra = []
    ): void {
        if ($segment === '') {
            return;
        }
        $dedup = PromptInjectionRegistry::deduplicationKeyForBlockId($blockId);
        if (PromptInjectionTraceCollector::isDuplicateSegment($dedup, $segment, $blockId, $layer)) {
            return;
        }
        $budgeted = CognitiveBudgetEngine::applySegment($blockId, $segment);
        $work = (string)($budgeted['content'] ?? '');
        if ($work === '') {
            PromptInjectionTraceCollector::addStep(
                $blockId,
                $layer,
                0,
                'skipped',
                (string)($budgeted['pruning_decision'] ?? 'empty_after_budget'),
                array_merge($extra, [
                    'status' => 'ignored',
                    'refused_chars' => (int)($budgeted['refused_chars'] ?? 0),
                    'budget_layer' => (string)($budgeted['budget_layer'] ?? $layer),
                ])
            );
            return;
        }
        $target .= $work;
        PromptInjectionTraceCollector::addStep(
            $blockId,
            $layer,
            mb_strlen($work, 'UTF-8'),
            $reason,
            null,
            array_merge($extra, [
                'refused_chars' => (int)($budgeted['refused_chars'] ?? 0),
                'pruning_decision' => (string)($budgeted['pruning_decision'] ?? 'none'),
                'fallback_decision' => (string)($budgeted['fallback_policy'] ?? 'full_inject'),
                'score_breakdown' => is_array($budgeted['score_breakdown'] ?? null) ? $budgeted['score_breakdown'] : [],
                'budget_layer' => (string)($budgeted['budget_layer'] ?? $layer),
                'chars_budget_allowed' => (int)($budgeted['chars_budget_allowed'] ?? mb_strlen($work, 'UTF-8')),
                'budget_soft_cap_registry' => $budgeted['soft_budget'] ?? null,
                'budget_hard_cap_registry' => $budgeted['hard_budget'] ?? null,
            ])
        );
        if ($dedup !== null && $dedup !== '') {
            PromptInjectionTraceCollector::recordSegmentFingerprint($dedup, $segment);
        }
    }
}

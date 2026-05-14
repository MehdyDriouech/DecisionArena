<?php

declare(strict_types=1);

namespace Domain\OpenSpace;

use Infrastructure\Markdown\MarkdownFileLoader;

/**
 * Assembles OpenSpace orchestration LLM messages from storage policies + runtime payload.
 * Does not call the LLM, parse JSON, or persist data.
 */
final class OpenSpaceOrchestratorPromptBuilder
{
    private MarkdownFileLoader $loader;

    public function __construct(?MarkdownFileLoader $loader = null)
    {
        $base = realpath(__DIR__ . '/../../../storage') ?: (__DIR__ . '/../../../storage');
        $this->loader = $loader ?? new MarkdownFileLoader($base);
    }

    private function loadPromptBody(string $id): string
    {
        $row = $this->loader->loadById('prompts', $id);
        if ($row === null) {
            throw new \RuntimeException("OpenSpace orchestrator prompt file missing: prompts/{$id}.md");
        }
        return trim((string)($row['content'] ?? ''));
    }

    /**
     * @param array{
     *   context_id: string,
     *   context_title: string,
     *   context_description: string,
     *   objective: string,
     *   constraints: string,
     *   existing_task_lines: list<string>,
     *   decision_lines: list<string>,
     *   agent_lines: list<string>,
     *   memory_snippets: list<string>
     * } $input
     * @return list<array{role: string, content: string}>
     */
    public function buildProposalMessages(array $input): array
    {
        foreach (['context_id', 'context_title', 'context_description', 'objective', 'constraints', 'existing_task_lines', 'decision_lines', 'agent_lines', 'memory_snippets'] as $key) {
            if (!array_key_exists($key, $input)) {
                throw new \InvalidArgumentException("OpenSpace orchestrator prompt input missing key: {$key}");
            }
        }
        $common = $this->loadPromptBody('orchestrator');
        $contract = $this->loadPromptBody('openspace-orchestrator');
        $system = "## Common orchestrator policy\n\n" . $common . "\n\n---\n\n## OpenSpace proposal contract\n\n" . $contract;

        $cid = (string)$input['context_id'];
        $existing = $input['existing_task_lines'];
        $decisions = $input['decision_lines'];
        $agents = $input['agent_lines'];
        $memories = $input['memory_snippets'];

        $existingBlock = $existing !== [] ? implode("\n", $existing) : '- none';
        $decisionBlock = $decisions !== [] ? implode("\n", $decisions) : '- none';
        $agentBlock = $agents !== [] ? implode("\n", $agents) : '- pm';
        $memoryBlock = $memories !== [] ? implode("\n\n", $memories) : 'No context memory available yet.';

        $user = "## Runtime data (read-only)\n\n"
            . "### Strategic context\n"
            . '- id: ' . $cid . "\n"
            . '- title: ' . (string)$input['context_title'] . "\n"
            . '- description: ' . (string)$input['context_description'] . "\n\n"
            . "### Objective\n" . (string)$input['objective'] . "\n\n"
            . "### Constraints\n" . (string)$input['constraints'] . "\n\n"
            . "### Existing OpenSpace tasks (avoid duplicates)\n" . $existingBlock . "\n\n"
            . "### Decision memories (compact)\n" . $decisionBlock . "\n\n"
            . "### Agents / personas available\n" . $agentBlock . "\n\n"
            . "### Agent context memory excerpts\n" . $memoryBlock . "\n\n"
            . "---\n\n"
            . "## Output requirement\n"
            . "Return exactly **one** JSON object matching the schema in the OpenSpace contract above. "
            . "No markdown code fences, no commentary before or after the JSON.\n";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}

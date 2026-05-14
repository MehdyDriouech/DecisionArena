<?php

declare(strict_types=1);

namespace Domain\OpenSpace;

use Infrastructure\Markdown\MarkdownFileLoader;

/**
 * Builds OpenSpace Agent Chat LLM messages from storage policy + runtime payload.
 * Does not call the LLM, persist messages, or read/write agent memory files.
 */
final class OpenSpaceAgentChatPromptBuilder
{
    private MarkdownFileLoader $loader;

    public function __construct(?MarkdownFileLoader $loader = null)
    {
        $base = realpath(__DIR__ . '/../../../storage') ?: (__DIR__ . '/../../../storage');
        $this->loader = $loader ?? new MarkdownFileLoader($base);
    }

    private function loadPolicyBody(): string
    {
        $row = $this->loader->loadById('prompts', 'openspace-agent-chat');
        if ($row === null) {
            throw new \RuntimeException('OpenSpace agent chat policy missing: prompts/openspace-agent-chat.md');
        }
        return trim((string)($row['content'] ?? ''));
    }

    /**
     * @param array{
     *   strategic_context_id: string,
     *   context_title: string,
     *   context_description: string,
     *   task_block: string,
     *   persona_id: string,
     *   persona_name: string,
     *   persona_title: string,
     *   persona_content: string,
     *   soul_content: string,
     *   memory_md_block: string,
     *   decision_memories_block: string,
     *   history_block: string,
     *   user_message: string
     * } $input
     * @return list<array{role: string, content: string}>
     */
    public function buildChatMessages(array $input): array
    {
        $keys = [
            'strategic_context_id',
            'context_title',
            'context_description',
            'task_block',
            'persona_id',
            'persona_name',
            'persona_title',
            'persona_content',
            'soul_content',
            'memory_md_block',
            'decision_memories_block',
            'history_block',
            'user_message',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                throw new \InvalidArgumentException("OpenSpace agent chat input missing key: {$key}");
            }
        }

        $policy = $this->loadPolicyBody();
        $system = $policy . "\n\n---\n\n"
            . "## Assigned persona (summary)\n"
            . '- id: ' . (string)$input['persona_id'] . "\n"
            . '- name: ' . (string)$input['persona_name'] . "\n"
            . '- title: ' . (string)$input['persona_title'] . "\n"
            . "\nThe **full persona document**, situational blocks, and the current user turn are in the user message below.";

        $soulSection = '';
        $soul = trim((string)$input['soul_content']);
        if ($soul !== '') {
            $soulSection = "## Soul modifier (optional)\n" . $soul . "\n\n";
        }

        $user = "## Strategic context\n"
            . '- strategic_context_id: ' . (string)$input['strategic_context_id'] . "\n"
            . '- title: ' . (string)$input['context_title'] . "\n"
            . '- description: ' . (string)$input['context_description'] . "\n\n"
            . "## Linked task\n"
            . (string)$input['task_block'] . "\n\n"
            . "## Full persona document\n"
            . (string)$input['persona_content'] . "\n\n"
            . $soulSection
            . "## Agent context memory (memory.md excerpt)\n"
            . (string)$input['memory_md_block'] . "\n\n"
            . "## Decision memories (compact, read-only)\n"
            . (string)$input['decision_memories_block'] . "\n\n"
            . "## Recent OpenSpace conversation history\n"
            . (string)$input['history_block'] . "\n\n"
            . "## Current user message\n"
            . (string)$input['user_message'];

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}

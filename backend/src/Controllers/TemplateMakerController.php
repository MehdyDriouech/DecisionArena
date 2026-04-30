<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Domain\Providers\ProviderRouter;

class TemplateMakerController {
    private ProviderRouter $providerRouter;

    public function __construct() {
        $this->providerRouter = new ProviderRouter();
    }

    public function make(Request $req): array {
        $data        = $req->body();
        $description = trim($data['description'] ?? '');
        $providerId  = $data['provider_id'] ?? null;
        $model       = $data['model'] ?? null;

        if (!$description) {
            return Response::error('description required', 400);
        }

        $prompt   = $this->buildPrompt($description);

        $raw = $this->providerRouter->chat(
            [['role' => 'user', 'content' => $prompt]],
            null,
            $providerId ? (string)$providerId : null,
            $model ? (string)$model : null
        )['content'];

        $jsonStr = $raw;
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $raw, $m)) {
            $jsonStr = $m[1];
        } elseif (preg_match('/\{[\s\S]+\}/s', $raw, $m)) {
            $jsonStr = $m[0];
        }

        $parsed = json_decode($jsonStr, true);
        if (!$parsed || !isset($parsed['template'])) {
            return [
                'error'      => true,
                'message'    => 'Invalid JSON from LLM.',
                'raw_output' => $raw,
            ];
        }

        $template = $parsed['template'];
        $template['mode'] = $this->normalizeMode((string)($template['mode'] ?? 'decision-room'));
        if (!in_array($template['mode'], ['chat', 'decision-room', 'confrontation', 'quick-decision'], true)) {
            $template['mode'] = 'decision-room';
        }

        if (!isset($template['id']) || !preg_match('/^[a-z0-9-]+$/', $template['id'] ?? '')) {
            $template['id'] = preg_replace('/[^a-z0-9-]/', '-', strtolower($template['name'] ?? 'template-' . time()));
        }
        if (!is_array($template['selected_agents'] ?? null)) {
            $template['selected_agents'] = ['pm', 'critic', 'synthesizer'];
        }
        $template['rounds']            = (int)($template['rounds'] ?? 2);
        if ($template['mode'] === 'quick-decision') $template['rounds'] = 1;
        $template['force_disagreement'] = (bool)($template['force_disagreement'] ?? false);
        $template['final_synthesis']    = (bool)($template['final_synthesis'] ?? true);
        $template['source']             = 'custom';

        return ['template' => $template];
    }

    private function normalizeMode(string $mode): string {
        return match($mode) {
            'multi-agent-chat' => 'chat',
            default => $mode,
        };
    }

    private function buildPrompt(string $description): string {
        return <<<PROMPT
You are an AI assistant that generates session templates for a multi-agent decision-making system.

Available modes: chat, decision-room, confrontation, quick-decision
Available agents: pm, architect, analyst, ux-expert, critic, po, dev, qa, synthesizer

Based on this description: "{$description}"

Generate a session template. Return ONLY valid JSON, no markdown, no explanation:

{
  "template": {
    "id": "lowercase-kebab-case-id",
    "name": "Template Name",
    "description": "Brief description",
    "mode": "one of: chat | decision-room | confrontation | quick-decision",
    "selected_agents": ["agent1", "agent2", "synthesizer"],
    "rounds": 2,
    "force_disagreement": false,
    "interaction_style": "sequential",
    "reply_policy": "all-agents-reply",
    "final_synthesis": true,
    "prompt_starter": "Starter prompt text...",
    "expected_output": "What the user expects from this session",
    "notes": "Additional notes"
  }
}

Rules:
- id must match /^[a-z0-9-]+$/
- mode must be one of the available modes
- selected_agents must be a subset of available agents; always include synthesizer for decision/confrontation/quick-decision
- rounds: quick-decision always = 1; decision-room 1-5; confrontation 1-10
- force_disagreement: true for challenge/critique templates
- Return ONLY the JSON object, nothing else
PROMPT;
    }
}

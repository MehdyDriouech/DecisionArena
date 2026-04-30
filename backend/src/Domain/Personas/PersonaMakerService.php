<?php
namespace Domain\Personas;

use Domain\Providers\ProviderRouter;

class PersonaMakerService {
    private ProviderRouter $providerRouter;

    public function __construct() {
        $this->providerRouter = new ProviderRouter();
    }

    public function make(string $description, ?string $providerId = null, ?string $model = null): array {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a Persona Factory for a multi-agent decision support system. Return ONLY valid JSON. No markdown, no code blocks, no explanation, no text before or after the JSON object.',
            ],
            ['role' => 'user', 'content' => $this->buildPrompt($description)],
        ];

        $raw = $this->providerRouter->chat($messages, null, $providerId, $model)['content'];

        // Strip markdown code fences if the LLM wrapped the JSON anyway
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```\s*$/i', '', $cleaned);
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);

        if (!$data || !isset($data['persona']) || !isset($data['soul'])) {
            return [
                'error'   => true,
                'message' => 'The LLM did not return valid JSON. Try rephrasing your description or use a different model.',
                'raw'     => substr($raw, 0, 500),
            ];
        }

        // Sanitize ID
        $id = strtolower(preg_replace('/[^a-z0-9-]/', '-', $data['persona']['id'] ?? 'custom-agent'));
        $id = trim($id, '-') ?: 'custom-agent';
        $data['persona']['id'] = $id;

        return [
            'persona' => $this->normalizePersona($data['persona']),
            'soul'    => $this->normalizeSoul($data['soul']),
        ];
    }

    private function buildPrompt(string $description): string {
        return <<<PROMPT
You are a Persona Factory for a multi-agent AI decision support system.

Create a complete persona and its soul based on the description below.

Return ONLY valid JSON. No markdown. No explanation. No code blocks. No text before or after the JSON.

JSON schema — fill every field:
{
  "persona": {
    "id": "lowercase-kebab-case-id",
    "name": "Short name or first name",
    "title": "Professional title (concise)",
    "icon": "single emoji",
    "tags": ["tag1", "tag2", "tag3"],
    "available_modes": ["chat", "decision-room", "confrontation"],
    "role": "One sentence role description.",
    "when_to_use": "2-3 sentences describing when to invoke this agent.",
    "style": "1-2 sentences describing communication and reasoning style.",
    "identity": "1-2 sentences defining who this agent is.",
    "focus": "Primary focus areas (comma separated).",
    "core_principles": ["principle1", "principle2", "principle3", "principle4", "principle5"],
    "capabilities": ["capability1", "capability2", "capability3"],
    "constraints": ["constraint1", "constraint2", "constraint3"],
    "default_response_format": ["## Section1", "## Section2", "## Section3", "## Section4"],
    "system_instructions": "2-3 sentences of LLM system instructions."
  },
  "soul": {
    "personality": "2-3 sentences describing personality traits.",
    "behavioral_rules": ["rule1", "rule2", "rule3", "rule4"],
    "reasoning_style": "1-2 sentences on how this agent reasons.",
    "communication_style": "1 sentence on communication style.",
    "default_bias": "1 sentence on what this agent naturally leans toward.",
    "challenge_level": "medium",
    "output_preferences": ["preference1", "preference2"],
    "guardrails": ["guardrail1", "guardrail2", "guardrail3"]
  }
}

Rules:
- id must be lowercase kebab-case only (a-z, 0-9, hyphens).
- available_modes must be a subset of: chat, decision-room, confrontation.
- challenge_level must be one of: low, medium, medium-high, high.
- All array fields must be arrays, never strings.

User description:
{$description}
PROMPT;
    }

    private function normalizePersona(array $p): array {
        $allModes = ['chat', 'decision-room', 'confrontation'];
        $modes    = is_array($p['available_modes'] ?? null)
            ? array_values(array_intersect($p['available_modes'], $allModes))
            : $allModes;

        return [
            'id'                     => $p['id'] ?? 'custom-agent',
            'name'                   => $p['name'] ?? 'Agent',
            'title'                  => $p['title'] ?? 'Custom Agent',
            'icon'                   => $p['icon'] ?? '🤖',
            'tags'                   => is_array($p['tags'] ?? null) ? $p['tags'] : [],
            'available_modes'        => $modes,
            'role'                   => $p['role'] ?? '',
            'when_to_use'            => $p['when_to_use'] ?? '',
            'style'                  => $p['style'] ?? '',
            'identity'               => $p['identity'] ?? '',
            'focus'                  => $p['focus'] ?? '',
            'core_principles'        => is_array($p['core_principles'] ?? null) ? $p['core_principles'] : [],
            'capabilities'           => is_array($p['capabilities'] ?? null) ? $p['capabilities'] : [],
            'constraints'            => is_array($p['constraints'] ?? null) ? $p['constraints'] : [],
            'default_response_format'=> is_array($p['default_response_format'] ?? null) ? $p['default_response_format'] : [],
            'system_instructions'    => $p['system_instructions'] ?? '',
            'default_provider'       => $p['default_provider'] ?? '',
            'default_model'          => $p['default_model'] ?? '',
        ];
    }

    private function normalizeSoul(array $s): array {
        $levels = ['low', 'medium', 'medium-high', 'high'];
        return [
            'personality'        => $s['personality'] ?? '',
            'behavioral_rules'   => is_array($s['behavioral_rules'] ?? null) ? $s['behavioral_rules'] : [],
            'reasoning_style'    => $s['reasoning_style'] ?? '',
            'communication_style'=> $s['communication_style'] ?? '',
            'default_bias'       => $s['default_bias'] ?? '',
            'challenge_level'    => in_array($s['challenge_level'] ?? '', $levels) ? $s['challenge_level'] : 'medium',
            'output_preferences' => is_array($s['output_preferences'] ?? null) ? $s['output_preferences'] : [],
            'guardrails'         => is_array($s['guardrails'] ?? null) ? $s['guardrails'] : [],
        ];
    }
}

<?php
namespace Domain\Personas;

use Domain\Providers\ProviderFactory;
use Infrastructure\Persistence\ProviderRepository;

class PersonaDraftService {
    private ProviderRepository $providerRepo;

    public function __construct() {
        $this->providerRepo = new ProviderRepository();
    }

    public function generate(string $description, ?string $providerId = null): array {
        // Get provider
        $providerData = null;
        if ($providerId) {
            $providerData = $this->providerRepo->findById($providerId);
        }
        if (!$providerData) {
            $all = $this->providerRepo->findAll();
            $providerData = array_values(array_filter($all, fn($p) => $p['enabled']))[0] ?? null;
        }
        if (!$providerData) {
            throw new \RuntimeException('No provider configured. Please add a provider in Settings.');
        }

        $provider = ProviderFactory::create($providerData);
        $model = $providerData['default_model'];

        $prompt = $this->buildPrompt($description);

        $messages = [
            ['role' => 'system', 'content' => 'You are a Persona Builder for a multi-agent decision system. You return only valid JSON, no markdown, no explanation, no code blocks.'],
            ['role' => 'user', 'content' => $prompt]
        ];

        $raw = $provider->chat($messages, $model);

        // Strip markdown code fences if present
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $data = json_decode($cleaned, true);

        if (!$data || !isset($data['persona']) || !isset($data['soul'])) {
            return [
                'error' => true,
                'message' => 'LLM did not return valid JSON. Try again or adjust your description.',
                'debug' => $raw
            ];
        }

        // Validate and sanitize persona ID
        $id = $data['persona']['id'] ?? 'custom-agent';
        $id = strtolower(preg_replace('/[^a-z0-9-]/', '-', $id));
        $id = trim($id, '-');
        $data['persona']['id'] = $id ?: 'custom-agent';

        return [
            'persona' => $this->normalizePersona($data['persona']),
            'soul' => $this->normalizeSoul($data['soul'])
        ];
    }

    private function buildPrompt(string $description): string {
        return <<<PROMPT
You are a Persona Builder for a multi-agent decision support system.

Create a practical, role-specific AI persona and its behavioral soul based on the user description below.

Return ONLY valid JSON. No markdown. No explanation. No code blocks. No text before or after the JSON.

JSON schema (fill all fields):
{
  "persona": {
    "id": "lowercase-kebab-case-id",
    "name": "First name or short name",
    "title": "Professional title",
    "icon": "single emoji",
    "tags": ["tag1", "tag2", "tag3"],
    "role": "One sentence role description",
    "when_to_use": "When to use this agent (2-3 sentences)",
    "style": "Communication and reasoning style (1-2 sentences)",
    "identity": "Who this agent is (1-2 sentences)",
    "focus": "Primary focus areas (comma separated)",
    "core_principles": ["principle1", "principle2", "principle3", "principle4", "principle5"],
    "capabilities": ["capability1", "capability2", "capability3"],
    "constraints": ["constraint1", "constraint2", "constraint3"],
    "default_response_format": ["## Section1", "## Section2", "## Section3", "## Section4"],
    "system_instructions": "2-3 sentences of system instructions for the LLM."
  },
  "soul": {
    "personality": "Personality description (2-3 sentences)",
    "behavioral_rules": ["rule1", "rule2", "rule3", "rule4"],
    "reasoning_style": "How this agent reasons (1-2 sentences)",
    "communication_style": "How this agent communicates (1 sentence)",
    "default_bias": "What this agent naturally leans toward (1 sentence)",
    "challenge_level": "medium",
    "output_preferences": ["preference1", "preference2"],
    "guardrails": ["guardrail1", "guardrail2", "guardrail3"]
  }
}

User description:
{$description}
PROMPT;
    }

    private function normalizePersona(array $p): array {
        return [
            'id' => $p['id'] ?? 'custom-agent',
            'name' => $p['name'] ?? 'Agent',
            'title' => $p['title'] ?? 'Custom Agent',
            'icon' => $p['icon'] ?? '🤖',
            'tags' => is_array($p['tags'] ?? null) ? $p['tags'] : [],
            'role' => $p['role'] ?? '',
            'when_to_use' => $p['when_to_use'] ?? '',
            'style' => $p['style'] ?? '',
            'identity' => $p['identity'] ?? '',
            'focus' => $p['focus'] ?? '',
            'core_principles' => is_array($p['core_principles'] ?? null) ? $p['core_principles'] : [],
            'capabilities' => is_array($p['capabilities'] ?? null) ? $p['capabilities'] : [],
            'constraints' => is_array($p['constraints'] ?? null) ? $p['constraints'] : [],
            'default_response_format' => is_array($p['default_response_format'] ?? null) ? $p['default_response_format'] : [],
            'system_instructions' => $p['system_instructions'] ?? '',
            'default_provider' => $p['default_provider'] ?? 'local-ollama',
            'default_model' => $p['default_model'] ?? 'qwen2.5:14b',
        ];
    }

    private function normalizeSoul(array $s): array {
        return [
            'personality' => $s['personality'] ?? '',
            'behavioral_rules' => is_array($s['behavioral_rules'] ?? null) ? $s['behavioral_rules'] : [],
            'reasoning_style' => $s['reasoning_style'] ?? '',
            'communication_style' => $s['communication_style'] ?? '',
            'default_bias' => $s['default_bias'] ?? '',
            'challenge_level' => in_array($s['challenge_level'] ?? '', ['low', 'medium', 'medium-high', 'high'])
                ? $s['challenge_level']
                : 'medium',
            'output_preferences' => is_array($s['output_preferences'] ?? null) ? $s['output_preferences'] : [],
            'guardrails' => is_array($s['guardrails'] ?? null) ? $s['guardrails'] : [],
        ];
    }
}

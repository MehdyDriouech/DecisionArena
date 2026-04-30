<?php
namespace Domain\Personas;

use Infrastructure\Persistence\PersonaModeRepository;

class PersonaFileWriter {
    private string $personasDir;
    private string $soulsDir;

    public function __construct() {
        $storage = __DIR__ . '/../../../storage';
        $this->personasDir = $storage . '/personas/custom';
        $this->soulsDir = $storage . '/souls/custom';
    }

    public function save(array $persona, array $soul, bool $overwrite = false): array {
        $id = $this->validateId($persona['id'] ?? '');

        $personaFile = $this->personasDir . '/' . $id . '.md';
        $soulFile = $this->soulsDir . '/' . $id . '.soul.md';

        if (!$overwrite && file_exists($personaFile)) {
            throw new \RuntimeException("Persona '{$id}' already exists. Use overwrite=true to replace it.");
        }

        // Create directories
        if (!is_dir($this->personasDir)) {
            mkdir($this->personasDir, 0755, true);
        }
        if (!is_dir($this->soulsDir)) {
            mkdir($this->soulsDir, 0755, true);
        }

        $personaMarkdown = $this->buildPersonaMarkdown($id, $persona);
        $soulMarkdown = $this->buildSoulMarkdown($id, $persona['name'] ?? 'Agent', $soul);

        $this->writeFile($personaFile, $personaMarkdown);
        $this->writeFile($soulFile, $soulMarkdown);

        // Persist available_modes to DB if provided
        if (isset($persona['available_modes']) && is_array($persona['available_modes'])) {
            (new PersonaModeRepository())->saveForPersona($id, $persona['available_modes']);
        }

        return [
            'success'      => true,
            'persona_file' => 'custom/' . $id . '.md',
            'soul_file'    => 'custom/' . $id . '.soul.md',
            'id'           => $id,
        ];
    }

    private function validateId(string $id): string {
        // Prevent path traversal
        if (str_contains($id, '..') || str_contains($id, '/') || str_contains($id, '\\')
            || str_contains($id, '%2e') || str_contains($id, '%2f')) {
            throw new \InvalidArgumentException('Invalid persona ID: path traversal detected.');
        }
        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            throw new \InvalidArgumentException('Persona ID must contain only lowercase letters, digits, and hyphens.');
        }
        if (strlen($id) < 2 || strlen($id) > 64) {
            throw new \InvalidArgumentException('Persona ID must be between 2 and 64 characters.');
        }
        return $id;
    }

    private function writeFile(string $path, string $content): void {
        // Sanitize: reject PHP tags
        if (str_contains($content, '<?php') || str_contains($content, '?>')) {
            throw new \InvalidArgumentException('Content must not contain PHP tags.');
        }
        file_put_contents($path, $content, LOCK_EX);
    }

    private function buildPersonaMarkdown(string $id, array $p): string {
        $name            = $this->esc($p['name'] ?? 'Agent');
        $title           = $this->esc($p['title'] ?? 'Custom Agent');
        $icon            = $this->esc($p['icon'] ?? '🤖');
        $defaultProvider = $this->esc($p['default_provider'] ?? 'local-ollama');
        $defaultModel    = $this->esc($p['default_model'] ?? 'qwen2.5:14b');

        $tags = '';
        foreach ((array)($p['tags'] ?? []) as $tag) {
            $tags .= '  - ' . $this->esc($tag) . "\n";
        }

        $availableModes     = is_array($p['available_modes'] ?? null)
            ? $p['available_modes']
            : ['chat', 'decision-room', 'confrontation'];
        $availableModesYaml = '';
        foreach ($availableModes as $mode) {
            $availableModesYaml .= '  - ' . $this->esc($mode) . "\n";
        }

        $principles = $this->buildList($p['core_principles'] ?? []);
        $capabilities = $this->buildList($p['capabilities'] ?? []);
        $constraints = $this->buildList($p['constraints'] ?? []);
        $responseFormat = $this->buildH2List($p['default_response_format'] ?? []);

        return <<<MD
---
id: {$id}
name: {$name}
title: {$title}
icon: {$icon}
version: 1.0.0
source: custom
default_soul: custom/{$id}.soul.md
default_provider: {$defaultProvider}
default_model: {$defaultModel}
enabled: true
tags:
{$tags}available_modes:
{$availableModesYaml}---

# Role

{$this->esc($p['role'] ?? '')}

# When To Use

{$this->esc($p['when_to_use'] ?? '')}

# Style

{$this->esc($p['style'] ?? '')}

# Identity

{$this->esc($p['identity'] ?? '')}

# Focus

{$this->esc($p['focus'] ?? '')}

# Core Principles

{$principles}

# Capabilities

{$capabilities}

# Constraints

{$constraints}

# Default Response Format

{$responseFormat}

# System Instructions

{$this->esc($p['system_instructions'] ?? '')}
MD;
    }

    private function buildSoulMarkdown(string $id, string $name, array $s): string {
        $rules = $this->buildList($s['behavioral_rules'] ?? []);
        $outputPrefs = $this->buildList($s['output_preferences'] ?? []);
        $guardrails = $this->buildList($s['guardrails'] ?? []);
        $challengeLevel = $this->esc($s['challenge_level'] ?? 'medium');

        return <<<MD
---
id: {$id}-soul
name: {$this->esc($name)} Soul
version: 1.0.0
applies_to:
  - {$id}
intensity: {$challengeLevel}
---

# Personality

{$this->esc($s['personality'] ?? '')}

# Behavioral Rules

{$rules}

# Reasoning Style

{$this->esc($s['reasoning_style'] ?? '')}

# Communication Style

{$this->esc($s['communication_style'] ?? '')}

# Default Bias

{$this->esc($s['default_bias'] ?? '')}

# Challenge Level

{$challengeLevel}

# Output Preferences

{$outputPrefs}

# Guardrails

{$guardrails}
MD;
    }

    private function buildList(array $items): string {
        if (empty($items)) return '- (none specified)';
        return implode("\n", array_map(fn($i) => '- ' . $this->esc($i), $items));
    }

    private function buildH2List(array $items): string {
        if (empty($items)) return '## Analysis\n## Recommendation';
        $result = [];
        foreach ($items as $item) {
            $item = trim($item);
            // If already has ##, keep; otherwise add ##
            if (!str_starts_with($item, '#')) {
                $item = '## ' . $item;
            }
            $result[] = $item;
        }
        return implode("\n", $result);
    }

    private function esc(string $value): string {
        // Basic sanitization: remove null bytes, trim
        return trim(str_replace(["\0", '<?php', '?>'], ['', '', ''], $value));
    }
}

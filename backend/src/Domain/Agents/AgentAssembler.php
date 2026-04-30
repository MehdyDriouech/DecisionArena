<?php
namespace Domain\Agents;

use Infrastructure\Markdown\MarkdownFileLoader;

class AgentAssembler {
    private MarkdownFileLoader $personaLoader;
    private MarkdownFileLoader $soulLoader;
    private string $storageDir;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../../storage';
        $this->personaLoader = new MarkdownFileLoader($this->storageDir);
        $this->soulLoader = new MarkdownFileLoader($this->storageDir);
    }

    public function assemble(string $agentId, ?string $providerId = null, ?string $model = null): ?Agent {
        // Try standard personas first
        $personaData = $this->personaLoader->loadById('personas', $agentId);

        // Try custom personas if not found
        if (!$personaData) {
            $personaData = $this->loadCustomPersona($agentId);
        }

        if (!$personaData) return null;

        $persona = new Persona(
            id: $personaData['id'] ?? $agentId,
            name: $personaData['name'] ?? $agentId,
            title: $personaData['title'] ?? '',
            icon: $personaData['icon'] ?? '🤖',
            content: $personaData['content'] ?? '',
            meta: $personaData
        );

        // Determine soul location
        $defaultSoul = $personaData['default_soul'] ?? '';
        $soul = null;

        if (str_starts_with($defaultSoul, 'custom/')) {
            // Load from custom souls directory
            $soulFileName = str_replace('custom/', '', $defaultSoul);
            $soulFileName = preg_replace('/\.md$/', '', $soulFileName);
            $soulData = $this->loadCustomSoul($soulFileName);
        } else {
            // Try to load matching soul (e.g., analyst.soul)
            $soulData = $this->soulLoader->loadById('souls', $agentId . '.soul');
        }

        if ($soulData) {
            $soul = new Soul(
                id: $soulData['id'] ?? $agentId,
                name: $soulData['name'] ?? $agentId,
                content: $soulData['content'] ?? '',
                meta: $soulData
            );
        } else {
            // Fallback to default soul
            $defaultSoulData = $this->soulLoader->loadById('souls', 'default.soul');
            if ($defaultSoulData) {
                $soul = new Soul(
                    id: 'default',
                    name: 'Default Soul',
                    content: $defaultSoulData['content'] ?? '',
                    meta: $defaultSoulData
                );
            }
        }

        return new Agent(
            id: $agentId,
            persona: $persona,
            soul: $soul,
            providerId: $providerId ?? $personaData['default_provider'] ?? null,
            model: $model ?? $personaData['default_model'] ?? null
        );
    }

    private function loadCustomPersona(string $agentId): ?array {
        $agentId = preg_replace('/[^a-z0-9\-]/', '', $agentId);
        $file = $this->storageDir . '/personas/custom/' . $agentId . '.md';
        if (!file_exists($file)) return null;

        $parser = new \Infrastructure\Markdown\FrontMatterParser();
        $content = file_get_contents($file);
        $parsed = $parser->parse($content);
        return array_merge($parsed['meta'], ['content' => $parsed['content'], 'filename' => 'custom/' . $agentId . '.md']);
    }

    private function loadCustomSoul(string $soulFileName): ?array {
        $soulFileName = preg_replace('/[^a-z0-9\-\.]/', '', $soulFileName);
        $file = $this->storageDir . '/souls/custom/' . $soulFileName . '.md';
        if (!file_exists($file)) return null;

        $parser = new \Infrastructure\Markdown\FrontMatterParser();
        $content = file_get_contents($file);
        $parsed = $parser->parse($content);
        return array_merge($parsed['meta'], ['content' => $parsed['content']]);
    }
}

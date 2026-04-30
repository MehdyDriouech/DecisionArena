<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Markdown\MarkdownFileLoader;
use Infrastructure\Persistence\PersonaModeRepository;
use Domain\Personas\PersonaDraftService;
use Domain\Personas\PersonaFileWriter;

class PersonaController {
    private MarkdownFileLoader $loader;
    private PersonaModeRepository $modeRepo;
    private string $storageDir;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../storage';
        $this->loader     = new MarkdownFileLoader($this->storageDir);
        $this->modeRepo   = new PersonaModeRepository();
    }

    public function index(Request $req): array {
        // Load standard personas
        $standard = $this->loader->loadAll('personas');
        $standard = array_filter($standard, fn($p) => !str_contains($p['filename'] ?? '', 'custom'));

        // Load custom personas
        $custom = $this->loadCustomPersonas();

        $all = array_values(array_merge($standard, $custom));

        // Merge mode visibility
        $modeMap = $this->modeRepo->findAll();
        foreach ($all as &$persona) {
            $id = $persona['id'] ?? '';
            if (isset($modeMap[$id])) {
                $persona['available_modes'] = $modeMap[$id];
            } elseif (isset($persona['available_modes']) && is_array($persona['available_modes'])) {
                // Honour frontmatter value
            } else {
                $persona['available_modes'] = $this->modeRepo->getDefault($id);
            }
        }

        return $all;
    }

    public function saveModes(Request $req): array {
        $data      = $req->body();
        $personaId = $data['persona_id'] ?? '';
        $modes     = $data['modes'] ?? [];

        if (!$personaId) {
            return Response::error('persona_id required', 400);
        }

        $allowed = PersonaModeRepository::allModes();
        $modes   = array_values(array_intersect($modes, $allowed));

        $this->modeRepo->saveForPersona($personaId, $modes);

        return ['success' => true, 'persona_id' => $personaId, 'modes' => $modes];
    }

    public function show(Request $req): array {
        $id = $req->param('id');
        // Try standard first
        $persona = $this->loader->loadById('personas', $id);
        if (!$persona) {
            // Try custom
            $persona = $this->loadCustomPersonaById($id);
        }
        if (!$persona) {
            return Response::error('Persona not found', 404);
        }
        return $persona;
    }

    public function custom(Request $req): array {
        return $this->loadCustomPersonas();
    }

    public function souls(Request $req): array {
        $standard = $this->loader->loadAll('souls');
        $customDir = $this->storageDir . '/souls/custom';
        $custom = [];
        if (is_dir($customDir)) {
            $files = glob($customDir . '/*.md') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $parser = new \Infrastructure\Markdown\FrontMatterParser();
                $parsed = $parser->parse($content);
                $item = array_merge($parsed['meta'], ['content' => $parsed['content'], 'filename' => 'custom/' . basename($file)]);
                $custom[] = $item;
            }
        }
        return array_values(array_merge($standard, $custom));
    }

    public function prompts(Request $req): array {
        return $this->loader->loadAll('prompts');
    }

    public function buildDraft(Request $req): array {
        $data = $req->body();
        $description = trim($data['description'] ?? '');
        $providerId = $data['provider_id'] ?? null;

        if (empty($description)) {
            return Response::error('description is required', 400);
        }

        try {
            $service = new PersonaDraftService();
            $result = $service->generate($description, $providerId);
            return $result;
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function saveCustom(Request $req): array {
        $data = $req->body();
        $personaData = $data['persona'] ?? [];
        $soulData = $data['soul'] ?? [];
        $overwrite = (bool)($data['overwrite'] ?? false);

        if (empty($personaData['id'])) {
            return Response::error('persona.id is required', 400);
        }

        try {
            $writer = new PersonaFileWriter();
            $result = $writer->save($personaData, $soulData, $overwrite);
            return $result;
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return Response::error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    private function loadCustomPersonas(): array {
        $customDir = $this->storageDir . '/personas/custom';
        if (!is_dir($customDir)) return [];

        $parser = new \Infrastructure\Markdown\FrontMatterParser();
        $files = glob($customDir . '/*.md') ?: [];
        $result = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $parsed = $parser->parse($content);
            $item = array_merge($parsed['meta'], [
                'content' => $parsed['content'],
                'filename' => 'custom/' . basename($file),
                'is_custom' => true
            ]);
            $result[] = $item;
        }
        return $result;
    }

    private function loadCustomPersonaById(string $id): ?array {
        // Sanitize
        $id = preg_replace('/[^a-z0-9\-]/', '', $id);
        $file = $this->storageDir . '/personas/custom/' . $id . '.md';
        if (!file_exists($file)) return null;

        $parser = new \Infrastructure\Markdown\FrontMatterParser();
        $content = file_get_contents($file);
        $parsed = $parser->parse($content);
        return array_merge($parsed['meta'], [
            'content' => $parsed['content'],
            'filename' => 'custom/' . $id . '.md',
            'is_custom' => true
        ]);
    }
}

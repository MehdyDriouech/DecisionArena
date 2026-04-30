<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\TemplateRepository;
use Infrastructure\Persistence\SessionRepository;

class TemplateController {
    private TemplateRepository $templateRepo;
    private SessionRepository  $sessionRepo;

    public function __construct() {
        $this->templateRepo = new TemplateRepository();
        $this->sessionRepo  = new SessionRepository();
    }

    public function index(Request $req): array {
        return $this->templateRepo->findAll();
    }

    public function show(Request $req): array {
        $id       = $req->param('id');
        $template = $this->templateRepo->findById($id);
        if (!$template) return Response::error('Template not found', 404);
        return $template;
    }

    public function store(Request $req): array {
        $data = $req->body();
        if (empty($data['id']) || empty($data['name']) || empty($data['mode'])) {
            return Response::error('id, name, mode are required', 400);
        }
        $data['mode'] = $this->normalizeMode((string)$data['mode']);
        if (!in_array($data['mode'], ['chat', 'decision-room', 'confrontation', 'quick-decision'], true)) {
            return Response::error('Invalid mode', 400);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $data['id'])) {
            return Response::error('id must match /^[a-z0-9-]+$/', 400);
        }
        if ($data['mode'] === 'quick-decision') {
            $data['rounds'] = 1;
        }
        $data['source']     = 'custom';
        $data['created_at'] = date('c');
        $template = $this->templateRepo->save($data);
        return ['template' => $template];
    }

    public function update(Request $req): array {
        $id       = $req->param('id');
        $existing = $this->templateRepo->findById($id);
        if (!$existing) return Response::error('Template not found', 404);
        if ($existing['source'] === 'system') {
            return Response::error('System templates cannot be edited directly. Duplicate first.', 403);
        }
        $data       = array_merge($existing, $req->body());
        $data['id'] = $id;
        $data['mode'] = $this->normalizeMode((string)$data['mode']);
        if (!in_array($data['mode'], ['chat', 'decision-room', 'confrontation', 'quick-decision'], true)) {
            return Response::error('Invalid mode', 400);
        }
        if ($data['mode'] === 'quick-decision') $data['rounds'] = 1;
        $template = $this->templateRepo->save($data);
        return ['template' => $template];
    }

    public function destroy(Request $req): array {
        $id       = $req->param('id');
        $existing = $this->templateRepo->findById($id);
        if (!$existing) return Response::error('Template not found', 404);
        if ($existing['source'] === 'system') {
            return Response::error('System templates cannot be deleted.', 403);
        }
        $this->templateRepo->delete($id);
        return ['success' => true];
    }

    public function duplicate(Request $req): array {
        $id       = $req->param('id');
        $data     = $req->body();
        $existing = $this->templateRepo->findById($id);
        if (!$existing) return Response::error('Template not found', 404);

        $newId = $data['new_id'] ?? ('copy-of-' . $id);
        if (!preg_match('/^[a-z0-9-]+$/', $newId)) {
            return Response::error('new_id must match /^[a-z0-9-]+$/', 400);
        }

        $copy = array_merge($existing, [
            'id'         => $newId,
            'name'       => $data['name'] ?? ('Copy of ' . $existing['name']),
            'source'     => 'custom',
            'created_at' => date('c'),
        ]);

        $template = $this->templateRepo->save($copy);
        return ['template' => $template];
    }

    public function fromTemplate(Request $req): array {
        $data       = $req->body();
        $templateId = $data['template_id'] ?? '';
        $title      = $data['title'] ?? '';
        $objective  = $data['objective'] ?? '';

        if (!$templateId || !$title) {
            return Response::error('template_id and title required', 400);
        }

        $template = $this->templateRepo->findById($templateId);
        if (!$template) return Response::error('Template not found', 404);

        $now    = date('c');
        $id     = $this->uuid();
        $agents = $template['selected_agents'];
        if (empty($agents)) $agents = [];

        $session = $this->sessionRepo->create([
            'id'                   => $id,
            'title'                => $title,
            'mode'                 => $template['mode'],
            'initial_prompt'       => $objective ?: ($template['prompt_starter'] ?? ''),
            'selected_agents'      => json_encode($agents),
            'rounds'               => $template['rounds'] ?? 2,
            'language'             => 'fr',
            'status'               => 'draft',
            'cf_rounds'            => $template['rounds'] ?? 3,
            'cf_interaction_style' => $template['interaction_style'] ?? 'sequential',
            'cf_reply_policy'      => $template['reply_policy'] ?? 'all-agents-reply',
            'is_favorite'          => 0,
            'is_reference'         => 0,
            'force_disagreement'   => $template['force_disagreement'] ? 1 : 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        return ['session' => $session, 'template' => $template];
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function normalizeMode(string $mode): string {
        return match($mode) {
            'multi-agent-chat' => 'chat',
            default => $mode,
        };
    }
}

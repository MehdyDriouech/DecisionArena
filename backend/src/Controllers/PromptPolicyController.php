<?php

declare(strict_types=1);

namespace Controllers;

use Domain\Prompts\PromptPolicyService;
use Http\Request;
use Http\Response;
use Infrastructure\Logging\Logger;

class PromptPolicyController
{
    private PromptPolicyService $service;
    private Logger $logger;

    public function __construct()
    {
        $this->service = new PromptPolicyService();
        $this->logger  = new Logger();
    }

    /** GET /api/prompt-policies */
    public function index(Request $req): array
    {
        return ['items' => $this->service->list()];
    }

    /** GET /api/prompt-policies/{id} */
    public function show(Request $req): array
    {
        $id = $req->param('id');
        try {
            return $this->service->get($id);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    /** PUT /api/prompt-policies/{id} */
    public function update(Request $req): array
    {
        $id   = $req->param('id');
        $body = $req->body();

        // Validate id early
        try {
            $this->service->assertAllowed($id);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 404);
        }

        $content = $body['content'] ?? null;
        if ($content === null || !is_string($content)) {
            return Response::error('Field "content" is required and must be a string.', 422);
        }

        try {
            $this->service->save($id, $content);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 500);
        }

        try {
            $this->logger->logBackendEvent('prompt_policy_updated', [
                'metadata' => [
                    'policy_id'      => $id,
                    'content_length' => mb_strlen($content, 'UTF-8'),
                ],
            ]);
        } catch (\Throwable) {}

        return ['ok' => true, 'id' => $id];
    }
}

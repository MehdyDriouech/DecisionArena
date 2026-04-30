<?php
namespace Controllers;

use Domain\Providers\ProviderRouter;
use Http\Request;
use Http\Response;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionRepository;

class DevilAdvocateController {
    private SessionRepository $sessionRepo;
    private MessageRepository $messageRepo;
    private ProviderRouter    $providerRouter;

    public function __construct() {
        $this->sessionRepo    = new SessionRepository();
        $this->messageRepo    = new MessageRepository();
        $this->providerRouter = new ProviderRouter();
    }

    /**
     * POST /api/sessions/{id}/devil-advocate/run
     * Body: { "current_round": 2, "partial_confidence": 0.71 }
     */
    public function run(Request $req): array {
        $sessionId = $req->param('id');
        $session   = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            return Response::error('Session not found', 404);
        }

        $devilAdvocateEnabled = (int)($session['devil_advocate_enabled'] ?? 0);
        if ($devilAdvocateEnabled !== 1) {
            return ['triggered' => false, 'reason' => 'not_enabled'];
        }

        $threshold          = (float)($session['devil_advocate_threshold'] ?? 0.65);
        $data               = $req->body();
        $currentRound       = (int)($data['current_round'] ?? 1);
        $partialConfidence  = (float)($data['partial_confidence'] ?? 0.0);

        if ($partialConfidence < $threshold) {
            return ['triggered' => false, 'reason' => 'below_threshold'];
        }

        $promptPath  = __DIR__ . '/../../storage/prompts/devil_advocate.md';
        $daPrompt    = file_exists($promptPath) ? file_get_contents($promptPath) : '';

        $recentMessages = $this->messageRepo->findBySession($sessionId);
        $assistantMessages = array_values(array_filter(
            $recentMessages,
            fn($m) => ($m['role'] ?? '') === 'assistant'
        ));
        $last5 = array_slice($assistantMessages, -5);
        $messagesSummary = implode("\n\n", array_map(
            fn($m) => '[' . ($m['agent_id'] ?? 'agent') . ']: ' . ($m['content'] ?? ''),
            $last5
        ));

        $messages = [
            ['role' => 'system', 'content' => $daPrompt],
            [
                'role'    => 'user',
                'content' => "Based on this debate context:\n\n{$messagesSummary}\n\nProvide your strongest counterargument.",
            ],
        ];

        try {
            $routed  = $this->providerRouter->chat($messages, null, null, null);
            $content = $routed['content'];

            $msg = $this->messageRepo->create([
                'id'           => $this->uuid(),
                'session_id'   => $sessionId,
                'role'         => 'assistant',
                'agent_id'     => 'devil_advocate',
                'provider_id'  => $routed['provider_id'] ?? null,
                'model'        => $routed['model'] ?? null,
                'round'        => $currentRound,
                'phase'        => 'devil-advocate',
                'mode_context' => 'devil-advocate',
                'message_type' => 'devil_advocate',
                'content'      => $content,
                'created_at'   => date('c'),
            ]);

            return [
                'triggered' => true,
                'message'   => $content,
                'agent_id'  => 'devil_advocate',
                'round'     => $currentRound,
            ];
        } catch (\Throwable $e) {
            return Response::error('Devil advocate call failed: ' . $e->getMessage(), 500);
        }
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
}

<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;
use Infrastructure\Persistence\MessageRepository;

class RerunController {
    private SessionRepository         $sessionRepo;
    private ContextDocumentRepository $docRepo;
    private MessageRepository         $messageRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->docRepo     = new ContextDocumentRepository();
        $this->messageRepo = new MessageRepository();
    }

    public function rerun(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();

        $session = $this->sessionRepo->findById($id);
        if (!$session) return Response::error('Session not found', 404);

        $variations       = (array)($data['variations'] ?? []);
        $targetMode       = $data['target_mode'] ?? null;
        $language         = $data['language'] ?? $session['language'] ?? 'en';
        $customInstruction = trim($data['custom_instruction'] ?? '');
        $keepContext      = (bool)($data['keep_context_document'] ?? true);
        $includeChallenge = (bool)($data['include_challenge_context'] ?? false);
        $challengeFallback = trim((string)($data['challenge_summary_fallback'] ?? ''));

        // Start with original session values
        $selectedAgents   = (array)($session['selected_agents'] ?? []);
        $rounds           = (int)($session['rounds'] ?? 2);
        $forceDisagreement = (bool)($session['force_disagreement'] ?? false);
        $mode             = $session['mode'] ?? 'decision-room';
        $initialPrompt    = $session['initial_prompt'] ?? '';

        // Apply variations
        foreach ($variations as $variation) {
            switch ($variation) {
                case 'more-disagreement':
                    $forceDisagreement = true;
                    if (!in_array('critic', $selectedAgents, true)) {
                        $selectedAgents[] = 'critic';
                    }
                    break;

                case 'more-critical-agents':
                    if (!in_array('critic', $selectedAgents, true)) {
                        $selectedAgents[] = 'critic';
                    }
                    if ($mode !== 'stress-test') {
                        $mode = 'stress-test';
                    }
                    break;

                case 'fewer-agents':
                    $nonSynth = array_values(array_filter($selectedAgents, fn($a) => $a !== 'synthesizer'));
                    $kept     = array_slice($nonSynth, 0, 3);
                    if (in_array('synthesizer', $selectedAgents, true)) {
                        $kept[] = 'synthesizer';
                    }
                    $selectedAgents = $kept;
                    break;

                case 'different-mode':
                    if ($targetMode) {
                        $mode = $targetMode;
                    }
                    break;

                case 'different-language':
                    // Language already applied above
                    break;
            }
        }

        // Override mode if explicitly requested and no 'different-mode' variation
        if ($targetMode && !in_array('different-mode', $variations, true)) {
            $mode = $targetMode;
        }

        // Append custom instruction to initial prompt
        if ($customInstruction) {
            $initialPrompt .= "\n\n[Rerun instruction: " . $customInstruction . "]";
        }

        if ($includeChallenge) {
            $ctxBlock = $this->buildChallengeContextFromParent($id, $challengeFallback);
            if ($ctxBlock !== '') {
                $initialPrompt .= "\n\n"
                    . "[USER CHALLENGE CONTEXT — re-evaluation requested. This block records user disagreement; it is not independently verified fact. Agents should reconcile with the shared context and prior reasoning.]\n"
                    . $ctxBlock;
            }
        }

        // Build new session data
        $now     = date('c');
        $newId   = $this->uuid();
        $newData = [
            'id'                   => $newId,
            'title'                => ($session['title'] ?? 'Session') . ' (rerun)',
            'mode'                 => $mode,
            'initial_prompt'       => $initialPrompt,
            'selected_agents'      => json_encode(array_values(array_unique($selectedAgents))),
            'rounds'               => $rounds,
            'language'             => $language,
            'status'               => 'draft',
            'cf_rounds'            => (int)($session['cf_rounds'] ?? 3),
            'cf_interaction_style' => $session['cf_interaction_style'] ?? 'sequential',
            'cf_reply_policy'      => $session['cf_reply_policy'] ?? 'all-agents-reply',
            'is_favorite'          => 0,
            'is_reference'         => 0,
            'force_disagreement'   => $forceDisagreement ? 1 : 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        // Store parent reference if columns exist
        try {
            $newData['parent_session_id'] = $id;
            $reasonParts = array_values(array_filter([
                implode(', ', $variations) !== '' ? implode(', ', $variations) : 'manual',
                $includeChallenge ? 'challenge_rerun' : null,
            ]));
            $newData['rerun_reason'] = implode(', ', $reasonParts);
        } catch (\Throwable $_) {}

        $created = $this->sessionRepo->create($newData);

        // Copy context document if requested
        if ($keepContext) {
            try {
                $ctxDoc = $this->docRepo->findBySession($id);
                if ($ctxDoc) {
                    $docNow = date('c');
                    $pdo = \Infrastructure\Persistence\Database::getInstance()->pdo();
                    $pdo->prepare("
                        INSERT INTO session_context_documents
                            (id, session_id, title, source_type, original_filename, mime_type, content, character_count, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $this->uuid(), $newId,
                        $ctxDoc['title'] ?? '',
                        'manual',
                        $ctxDoc['original_filename'] ?? '',
                        $ctxDoc['mime_type'] ?? 'text/plain',
                        $ctxDoc['content'] ?? '',
                        $ctxDoc['character_count'] ?? 0,
                        $docNow, $docNow,
                    ]);
                }
            } catch (\Throwable $_) {}
        }

        return ['session' => $created, 'parent_session_id' => $id];
    }

    /**
     * Prefer verbatim user challenge messages from the parent session; use synthesized
     * fallback only when no challenge-thread messages exist (never both).
     */
    private function buildChallengeContextFromParent(string $parentSessionId, string $fallbackSummary): string {
        $messages = $this->messageRepo->findBySession($parentSessionId);
        $lines    = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }
            $meta = [];
            if (!empty($msg['meta_json'])) {
                $d = json_decode((string)$msg['meta_json'], true);
                $meta = is_array($d) ? $d : [];
            }
            $isChallenge = (($meta['context_mode'] ?? '') === 'challenge')
                || (!empty($meta['challenge_origin']));
            if (!$isChallenge) {
                continue;
            }
            $text = trim((string)($msg['content'] ?? ''));
            if ($text !== '') {
                $lines[] = '[User challenge message]' . "\n" . $text;
            }
        }
        $raw = implode("\n\n", $lines);
        if ($raw !== '') {
            return $raw;
        }
        if ($fallbackSummary !== '') {
            return '[USER CHALLENGE]' . "\n" . 'The user has challenged:' . "\n" . '"' . $fallbackSummary . '"';
        }
        return '';
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

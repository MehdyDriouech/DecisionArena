<?php
namespace Controllers;

use Domain\Sessions\SessionStrategicContextGuard;
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
        $parentStatus = strtolower((string)($session['status'] ?? 'draft'));
        if ($parentStatus === 'active') {
            $parentStatus = 'running';
        }
        if (!in_array($parentStatus, ['completed', 'archived'], true)) {
            return Response::error('Rerun/fork is only allowed from completed or archived analyses', 409);
        }

        $variations       = (array)($data['variations'] ?? []);
        $targetMode       = $data['target_mode'] ?? null;
        $language         = $data['language'] ?? $session['language'] ?? 'en';
        $customInstruction = trim($data['custom_instruction'] ?? '');
        $keepContext      = (bool)($data['keep_context_document'] ?? true);
        $includeChallenge = (bool)($data['include_challenge_context'] ?? false);
        $challengeFallback = trim((string)($data['challenge_summary_fallback'] ?? ''));
        $isPremortem = !empty($data['premortem']);

        // Start with original session values
        $selectedAgents   = (array)($session['selected_agents'] ?? []);
        $rounds           = (int)($session['rounds'] ?? 2);
        $forceDisagreement = (bool)($session['force_disagreement'] ?? false);
        $mode             = $session['mode'] ?? 'decision-room';
        $initialPrompt    = $session['initial_prompt'] ?? '';

        // Apply variations
        foreach ($variations as $variation) {
            $vnorm = strtolower((string)$variation);
            if (in_array($vnorm, ['pre-mortem', 'premortem', 'pre_mortem'], true)) {
                $isPremortem = true;
                continue;
            }
            switch ($variation) {
                case 'fork':
                    // Explicit lineage intent for UI lifecycle overlays.
                    break;
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

        if ($isPremortem) {
            // Dérivée Decision Room avec pipeline verdict / brief compatible
            $mode              = 'decision-room';
            $forceDisagreement = true;
            if (!in_array('synthesizer', $selectedAgents, true)) {
                $selectedAgents[] = 'synthesizer';
            }
            if (!in_array('critic', $selectedAgents, true)) {
                $selectedAgents[] = 'critic';
            }
            $initialPrompt .= "\n\n" . $this->buildPremortemPromptAugment($id, $session);
        }

        // Override mode if explicitly requested and no 'different-mode' variation
        if ($targetMode && !in_array('different-mode', $variations, true) && !$isPremortem) {
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
            'title'                => ($session['title'] ?? 'Session') . ($isPremortem ? ' (Pre-Mortem)' : ' (rerun)'),
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
            $reasonBits = [];
            if ($isPremortem) {
                $reasonBits[] = 'premortem';
            }
            if (in_array('fork', $variations, true)) {
                $reasonBits[] = 'fork';
            }
            $varStr = implode(', ', array_values(array_filter(array_map('strval', $variations))));
            if ($varStr !== '') {
                $reasonBits[] = $varStr;
            } elseif (!$isPremortem) {
                $reasonBits[] = 'manual';
            }
            if ($includeChallenge) {
                $reasonBits[] = 'challenge_rerun';
            }
            $newData['rerun_reason'] = implode(', ', $reasonBits);
        } catch (\Throwable $_) {}

        $inheritCtx = isset($session['strategic_context_id']) && (string)$session['strategic_context_id'] !== ''
            ? (string)$session['strategic_context_id']
            : null;
        $resolved = SessionStrategicContextGuard::resolveStrategicContextForCreation(
            $mode,
            is_array($data) ? $data : [],
            $inheritCtx
        );
        if ($resolved['block'] !== null) {
            return $resolved['block'];
        }
        $strategicContextId = $resolved['strategic_context_id'];
        $newData['strategic_context_id'] = $strategicContextId;

        $created = $this->sessionRepo->create($newData);

        SessionStrategicContextGuard::syncStrategicContextSessionLink($strategicContextId, $newId);

        try {
            $preset = \Domain\Agents\DecisionDynamicsPreset::normalizeId($session['decision_dynamics_preset'] ?? null);
            $this->sessionRepo->update($newId, ['decision_dynamics_preset' => $preset]);
        } catch (\Throwable $_) {
        }

        if ($isPremortem) {
            try {
                $this->sessionRepo->update($newId, ['session_variant' => 'premortem']);
            } catch (\Throwable $_) {
            }
        }

        if (!empty($session['facilitation_framework'])) {
            try {
                $this->sessionRepo->update($newId, ['facilitation_framework' => $session['facilitation_framework']]);
            } catch (\Throwable $_) {
            }
        }

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

        return ['session' => $this->sessionRepo->findById($newId) ?: $created, 'parent_session_id' => $id];
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

    /**
     * @param array<string,mixed> $parentSession
     */
    private function buildPremortemPromptAugment(string $parentId, array $parentSession): string {
        $path       = dirname(__DIR__, 2) . '/storage/prompts/pre_mortem.md';
        $policyText = is_readable($path) ? (string)file_get_contents($path) : '';

        $briefRaw = $parentSession['decision_brief'] ?? null;
        $brief    = null;
        if (is_string($briefRaw) && $briefRaw !== '') {
            $d = json_decode($briefRaw, true);
            $brief = is_array($d) ? $d : null;
        } elseif (is_array($briefRaw)) {
            $brief = $briefRaw;
        }

        $parts   = [];
        $parts[] = '---';
        $parts[] = '[PRE-MORTEM SESSION — source_session_id=' . $parentId . ']';
        $parts[] = 'This session is an auditable counterfactual derivative. Treat the FAILURE premise as mandated for brainstorming only.';
        $parts[] = '';
        if ($policyText !== '') {
            $parts[] = trim($policyText);
            $parts[] = '';
        }
        $parts[] = '## Source decision snapshot (from parent session Decision Brief — not a prediction)';
        if (is_array($brief)) {
            $why   = $brief['why']   ?? [];
            $risks = $brief['risks'] ?? [];
            $parts[] = '- **Decision (brief):** ' . ($brief['decision'] ?? '—');
            $parts[] = '- **Confidence:** ' . ($brief['confidence'] ?? '—');
            $parts[] = '- **Reliability:** ' . ($brief['reliability'] ?? '—');
            $parts[] = '- **Quality score:** ' . (isset($brief['quality_score']) ? (string)$brief['quality_score'] : '—');
            $parts[] = '- **Why:** ' . (is_array($why) ? implode(' | ', array_map('strval', $why)) : (string)$why);
            $parts[] = '- **Risks:** ' . (is_array($risks) ? implode(' | ', array_map('strval', $risks)) : (string)$risks);
            $parts[] = '- **Next step:** ' . ($brief['next_step'] ?? '—');
            if (!empty($brief['primary_warning'])) {
                $parts[] = '- **Primary warning:** ' . (string)$brief['primary_warning'];
            }
        } else {
            $parts[] = '_No structured Decision Brief stored on parent — infer from excerpts below and context document._';
        }
        $parts[] = '';
        $parts[] = '## Original user problem (preserve framing — do not replace with unrelated scenarios)';
        $parts[] = trim((string)($parentSession['initial_prompt'] ?? ''));
        $parts[] = '';
        $parts[] = '## Key excerpts from parent debate';
        $parts[] = $this->summarizeParentMessages($parentId);
        $parts[] = '';
        $parts[] = '---';

        return implode("\n", $parts);
    }

    private function summarizeParentMessages(string $parentId): string {
        $msgs = $this->messageRepo->findBySession($parentId);
        if ($msgs === []) {
            return '_No messages._';
        }
        $tail = array_slice($msgs, -10);
        $out  = [];
        foreach ($tail as $m) {
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content, 'UTF-8') > 700) {
                $content = mb_substr($content, 0, 697, 'UTF-8') . '…';
            }
            $role  = (string)($m['role'] ?? '');
            $agent = (string)($m['agent_id'] ?? '');
            $label = ($role === 'user') ? 'User' : ($agent !== '' ? $agent : 'Agent');
            $out[] = '- **' . $label . ':** ' . $content;
        }

        return $out === [] ? '_No excerptable content._' : implode("\n", $out);
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

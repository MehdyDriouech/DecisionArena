<?php

/**
 * Test: Reactive Chat thread persistence in messages table.
 *
 * Verifies that thread_type, thread_turn, reaction_role, reactive_thread_id
 * are correctly persisted and readable.
 *
 * Usage:
 *   php tools/test_reactive_thread_persistence.php
 */

require_once __DIR__ . '/../src/Infrastructure/Persistence/Database.php';
require_once __DIR__ . '/../src/Infrastructure/Persistence/MessageRepository.php';

use Infrastructure\Persistence\Database;
use Infrastructure\Persistence\MessageRepository;

$db   = Database::getInstance();
$repo = new MessageRepository();

function uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

// ── Setup: create a fake session row directly in DB ──────────────────────────
$pdo = $db->pdo();
$sessionId = 'test-reactive-' . substr(uuid(), 0, 8);
try {
    $pdo->exec("INSERT INTO sessions (id, title, mode, status, created_at) VALUES ('{$sessionId}', 'Test RC', 'chat', 'active', datetime('now'))");
} catch (\Throwable $e) {
    // Session might already exist — ignore
}

$threadId = uuid();

// ── Test 1: persist reactive message with all thread fields ──────────────────
$msgId = uuid();
$msg = $repo->create([
    'id'                    => $msgId,
    'session_id'            => $sessionId,
    'role'                  => 'assistant',
    'agent_id'              => 'pm',
    'provider_id'           => 'openai',
    'provider_name'         => 'OpenAI',
    'model'                 => 'gpt-4o',
    'requested_provider_id' => 'openai',
    'requested_model'       => 'gpt-4o',
    'provider_fallback_used'=> 0,
    'provider_fallback_reason' => null,
    'round'                 => 1,
    'phase'                 => 'reactive-turn-1',
    'mode_context'          => 'reactive-chat',
    'message_type'          => 'primary',
    'target_agent_id'       => null,
    'thread_type'           => 'reactive_chat',
    'thread_turn'           => 1,
    'reaction_role'         => 'primary',
    'reactive_thread_id'    => $threadId,
    'content'               => 'Test primary answer',
    'created_at'            => date('c'),
]);

check('create() returns array',         is_array($msg));
check('thread_type = reactive_chat',    ($msg['thread_type'] ?? null) === 'reactive_chat');
check('thread_turn = 1',                (int)($msg['thread_turn'] ?? -1) === 1);
check('reaction_role = primary',        ($msg['reaction_role'] ?? null) === 'primary');
check('reactive_thread_id not null',    !empty($msg['reactive_thread_id']));
check('reactive_thread_id matches',     ($msg['reactive_thread_id'] ?? null) === $threadId);
check('provider_name = OpenAI',         ($msg['provider_name'] ?? null) === 'OpenAI');
check('model = gpt-4o',                 ($msg['model'] ?? null) === 'gpt-4o');

// ── Test 2: persist reactor message ─────────────────────────────────────────
$reactorId = uuid();
$reactorMsg = $repo->create([
    'id'                 => $reactorId,
    'session_id'         => $sessionId,
    'role'               => 'assistant',
    'agent_id'           => 'analyst',
    'provider_id'        => null,
    'provider_name'      => null,
    'model'              => null,
    'round'              => 1,
    'thread_type'        => 'reactive_chat',
    'thread_turn'        => 1,
    'reaction_role'      => 'reactor',
    'reactive_thread_id' => $threadId,
    'target_agent_id'    => 'pm',
    'content'            => 'Reactor feedback',
    'created_at'         => date('c'),
]);

check('reactor reaction_role = reactor', ($reactorMsg['reaction_role'] ?? null) === 'reactor');
check('reactor target_agent_id = pm',   ($reactorMsg['target_agent_id'] ?? null) === 'pm');

// ── Test 3: classic message still works (no thread fields) ───────────────────
$classicId = uuid();
$classic = $repo->create([
    'id'         => $classicId,
    'session_id' => $sessionId,
    'role'       => 'user',
    'agent_id'   => null,
    'content'    => 'Classic user message',
    'created_at' => date('c'),
]);
check('classic message created',              is_array($classic));
check('classic thread_type null',             ($classic['thread_type'] ?? null) === null);
check('classic reaction_role null',           ($classic['reaction_role'] ?? null) === null);
check('classic reactive_thread_id null',      ($classic['reactive_thread_id'] ?? null) === null);

// ── Test 4: findBySession returns all 3 messages ─────────────────────────────
$all = $repo->findBySession($sessionId);
check('findBySession returns 3 messages', count($all) === 3);

// ── Cleanup ──────────────────────────────────────────────────────────────────
try {
    $pdo->exec("DELETE FROM messages WHERE session_id = '{$sessionId}'");
    $pdo->exec("DELETE FROM sessions WHERE id = '{$sessionId}'");
} catch (\Throwable $e) {}

echo "\n--- Results: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);

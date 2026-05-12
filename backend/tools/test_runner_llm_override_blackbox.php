<?php
declare(strict_types=1);

/**
 * Black-box runner checks for LLM override propagation.
 *
 * Goal:
 * session_agent_providers -> runner -> ProviderRouter::chat(sessionAgentOverride)
 *
 * Run:
 * php backend/tools/test_runner_llm_override_blackbox.php
 */

require_once __DIR__ . '/../src/mbstring-polyfill.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Domain\Agents\Agent;
use Domain\Orchestration\ChatRunner;
use Domain\Orchestration\ConfrontationRunner;
use Domain\Orchestration\DecisionRoomRunner;
use Domain\Orchestration\JuryRunner;
use Domain\Orchestration\QuickDecisionRunner;
use Domain\Orchestration\StressTestRunner;
use Domain\Providers\ProviderRouter;
use Infrastructure\Persistence\MessageRepository;
use Infrastructure\Persistence\SessionAgentProvidersRepository;
use Infrastructure\Persistence\SessionRepository;

function ok(string $name, bool $pass): void
{
    echo ($pass ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

function mkSessionId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(6));
}

function createSession(SessionRepository $repo, string $id, string $mode, array $agents, string $objective): void
{
    $now = date('c');
    $repo->create([
        'id' => $id,
        'title' => '[blackbox] ' . $mode,
        'mode' => $mode,
        'initial_prompt' => $objective,
        'selected_agents' => $agents,
        'rounds' => 2,
        'language' => 'fr',
        'status' => 'draft',
        'force_disagreement' => 0,
        'decision_threshold' => 0.55,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function injectSpy(object $runner, SpyProviderRouter $spy): void
{
    $ref = new ReflectionObject($runner);
    $prop = $ref->getProperty('providerRouter');
    $prop->setAccessible(true);
    $prop->setValue($runner, $spy);
}

/**
 * @param list<array<string,mixed>> $calls
 */
function findAgentCall(array $calls, string $agentId): ?array
{
    foreach ($calls as $call) {
        if ((string)($call['agent_id'] ?? '') === $agentId) {
            return $call;
        }
    }
    return null;
}

function latestAssistantMessageMetaByAgent(string $sessionId, string $agentId): ?array
{
    $repo = new MessageRepository();
    $rows = $repo->findBySession($sessionId);
    $rows = array_values(array_filter($rows, static function (array $row) use ($agentId): bool {
        return (string)($row['role'] ?? '') === 'assistant'
            && (string)($row['agent_id'] ?? '') === $agentId;
    }));
    if ($rows === []) {
        return null;
    }
    $last = $rows[count($rows) - 1];
    $meta = json_decode((string)($last['meta_json'] ?? ''), true);
    return is_array($meta) ? $meta : null;
}

/**
 * ProviderRouter spy:
 * - captures each call including session override
 * - returns deterministic metadata/content without external LLM
 */
final class SpyProviderRouter extends ProviderRouter
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct()
    {
        // Intentionally do not call parent constructor: we fully override chat().
    }

    public function chat(
        array $messages,
        ?Agent $agent = null,
        ?string $explicitProviderId = null,
        ?string $explicitModel = null,
        ?array $sessionAgentOverride = null,
        ?array $options = null
    ): array {
        $agentId = trim((string)($agent?->id ?? ''));
        $overridePid = trim((string)($sessionAgentOverride['provider_id'] ?? ''));
        $overrideModel = trim((string)($sessionAgentOverride['model'] ?? ''));

        $explicitPid = trim((string)($explicitProviderId ?? ''));
        $explicitModelTrimmed = trim((string)($explicitModel ?? ''));
        $personaPid = trim((string)($agent?->providerId ?? ''));
        $personaModel = trim((string)($agent?->model ?? ''));

        $routingSource = 'global_routing';
        $resolvedProvider = 'GLOBAL_TEST_PROVIDER';
        $resolvedModel = 'global-test-model';
        $sessionOverridePresent = false;
        $fallbackUsed = false;
        $fallbackReason = null;

        if ($overridePid !== '') {
            if (str_starts_with(strtoupper($overridePid), 'UNAVAILABLE')) {
                $routingSource = 'fallback_from_override';
                $resolvedProvider = 'GLOBAL_TEST_PROVIDER';
                $resolvedModel = 'global-test-model';
                $fallbackUsed = true;
                $fallbackReason = 'Override provider unavailable';
            } else {
                $routingSource = 'session_override';
                $resolvedProvider = $overridePid;
                $resolvedModel = $overrideModel !== '' ? $overrideModel : 'override-fallback-model';
            }
            $sessionOverridePresent = true;
        } elseif ($explicitPid !== '') {
            $routingSource = 'explicit_call';
            $resolvedProvider = $explicitPid;
            $resolvedModel = $explicitModelTrimmed !== '' ? $explicitModelTrimmed : 'explicit-fallback-model';
        } elseif ($personaPid !== '' || $personaModel !== '') {
            $routingSource = 'persona_default';
            $resolvedProvider = $personaPid !== '' ? $personaPid : 'PERSONA_DEFAULT_PROVIDER';
            $resolvedModel = $personaModel !== '' ? $personaModel : 'persona-fallback-model';
        }

        $this->calls[] = [
            'agent_id' => $agentId,
            'session_override' => $sessionAgentOverride,
            'resolved_provider_id' => $resolvedProvider,
            'resolved_model' => $resolvedModel,
            'routing_source' => $routingSource,
            'session_override_present' => $sessionOverridePresent,
            'fallback_used' => $fallbackUsed,
            'fallback_reason' => $fallbackReason,
            'explicit_provider_id' => $explicitPid,
            'explicit_model' => $explicitModelTrimmed,
        ];

        $content = implode("\n", [
            '## Vote',
            'GO',
            '## Confidence',
            '8',
            '## Impact',
            '7',
            '## Domain Weight',
            '7',
            '## Rationale',
            'Black-box test response.',
            '## Verdict',
            'GO',
            '## Verdict Summary',
            'Override smoke response.',
            '## Recommended Action',
            'Proceed',
        ]);

        return [
            'content' => $content,
            'provider_id' => $resolvedProvider,
            'provider_name' => $resolvedProvider,
            'provider_type' => 'test-spy',
            'model' => $resolvedModel,
            'routing_mode' => $routingSource === 'session_override' ? 'session_agent_override' : 'test-fallback',
            'routing_source' => $routingSource,
            'requested_provider_id' => $overridePid !== '' ? $overridePid : null,
            'requested_model' => $overrideModel !== '' ? $overrideModel : null,
            'resolved_provider_id' => $resolvedProvider,
            'resolved_provider_label' => $resolvedProvider,
            'resolved_model' => $resolvedModel,
            'session_override_present' => $sessionOverridePresent,
            'persona_default_provider_ignored' => false,
            'fallback_used' => $fallbackUsed,
            'fallback_reason' => $fallbackReason,
            'fallback_from_provider_id' => $fallbackUsed ? $overridePid : null,
            'fallback_from_model' => $fallbackUsed ? ($overrideModel !== '' ? $overrideModel : null) : null,
        ];
    }
}

$sessionRepo = new SessionRepository();
$sapRepo = new SessionAgentProvidersRepository();

// 1) DecisionRoomRunner
$sidDecision = mkSessionId('bbx-dr');
createSession($sessionRepo, $sidDecision, 'decision-room', ['pm', 'synthesizer'], 'Tester override DR');
$sapRepo->saveForSession($sidDecision, [
    'PM' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'], // mixed-case id
]);
$dr = new DecisionRoomRunner();
$drSpy = new SpyProviderRouter();
injectSpy($dr, $drSpy);
$dr->run(
    $sidDecision,
    'Tester override DR',
    ['pm', 'synthesizer'],
    1,
    'fr',
    false,
    null,
    false,
    0.65,
    $sapRepo->findBySession($sidDecision),
    0.55,
    []
);
$pmDrCall = findAgentCall($drSpy->calls, 'pm');
ok('DecisionRoomRunner appelle router pour pm', is_array($pmDrCall));
ok('DecisionRoomRunner transmet override session', (string)($pmDrCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('DecisionRoomRunner transmet model override', (string)($pmDrCall['resolved_model'] ?? '') === 'ModelB');
ok('DecisionRoomRunner routing_source=session_override', (string)($pmDrCall['routing_source'] ?? '') === 'session_override');

// 2) ChatRunner
$sidChat = mkSessionId('bbx-chat');
createSession($sessionRepo, $sidChat, 'chat', ['pm'], 'Tester override Chat');
$sapRepo->saveForSession($sidChat, [
    'pm' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
]);
$chat = new ChatRunner();
$chatSpy = new SpyProviderRouter();
injectSpy($chat, $chatSpy);
$chat->runWithRuntime($sidChat, '@pm Vérifie override', ['pm'], '', 'fr', null, null);
$pmChatCall = findAgentCall($chatSpy->calls, 'pm');
ok('ChatRunner appelle router pour pm', is_array($pmChatCall));
ok('ChatRunner transmet override session', (string)($pmChatCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('ChatRunner transmet model override', (string)($pmChatCall['resolved_model'] ?? '') === 'ModelB');
ok('ChatRunner routing_source=session_override', (string)($pmChatCall['routing_source'] ?? '') === 'session_override');
$chatMeta = latestAssistantMessageMetaByAgent($sidChat, 'pm');
ok('ChatRunner meta_json contient llm_routing', is_array($chatMeta['llm_routing'] ?? null));
ok('ChatRunner meta_json requested_provider_id', (string)($chatMeta['llm_routing']['requested_provider_id'] ?? '') === 'ProviderB');
ok('ChatRunner meta_json provider_fallback_used=false', ($chatMeta['llm_routing']['provider_fallback_used'] ?? null) === false);

// 3) QuickDecisionRunner
$sidQuick = mkSessionId('bbx-qd');
createSession($sessionRepo, $sidQuick, 'quick-decision', ['pm', 'synthesizer'], 'Tester override Quick');
$sapRepo->saveForSession($sidQuick, [
    'pm' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'synthesizer' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
]);
$qd = new QuickDecisionRunner();
$qdSpy = new SpyProviderRouter();
injectSpy($qd, $qdSpy);
$qd->run(
    $sidQuick,
    'Tester override Quick',
    ['pm', 'synthesizer'],
    'fr',
    false,
    null,
    $sapRepo->findBySession($sidQuick),
    0.55,
    null,
    null
);
$pmQdCall = findAgentCall($qdSpy->calls, 'pm');
ok('QuickDecisionRunner appelle router pour pm', is_array($pmQdCall));
ok('QuickDecisionRunner transmet override session', (string)($pmQdCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('QuickDecisionRunner routing_source=session_override', (string)($pmQdCall['routing_source'] ?? '') === 'session_override');

// 4) StressTestRunner
$sidStress = mkSessionId('bbx-st');
createSession($sessionRepo, $sidStress, 'stress-test', ['pm', 'synthesizer'], 'Tester override Stress');
$sapRepo->saveForSession($sidStress, [
    'pm' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'synthesizer' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
]);
$st = new StressTestRunner();
$stSpy = new SpyProviderRouter();
injectSpy($st, $stSpy);
$st->run(
    $sidStress,
    'Tester override Stress',
    ['pm', 'synthesizer'],
    1,
    'fr',
    false,
    null,
    false,
    0.65,
    $sapRepo->findBySession($sidStress),
    0.55,
    null,
    null
);
$pmStCall = findAgentCall($stSpy->calls, 'pm');
ok('StressTestRunner appelle router pour pm', is_array($pmStCall));
ok('StressTestRunner transmet override session', (string)($pmStCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('StressTestRunner routing_source=session_override', (string)($pmStCall['routing_source'] ?? '') === 'session_override');

// 5) JuryRunner
$sidJury = mkSessionId('bbx-jury');
createSession($sessionRepo, $sidJury, 'jury', ['pm', 'architect', 'synthesizer'], 'Tester override Jury');
$sapRepo->saveForSession($sidJury, [
    'pm' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'architect' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'synthesizer' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
]);
$jury = new JuryRunner();
$jurySpy = new SpyProviderRouter();
injectSpy($jury, $jurySpy);
$jury->run(
    $sidJury,
    'Tester override Jury',
    ['pm', 'architect'],
    2,
    false,
    0.55,
    'fr',
    null,
    $sapRepo->findBySession($sidJury),
    [],
    null,
    null
);
$pmJuryCall = findAgentCall($jurySpy->calls, 'pm');
$synthJuryCall = findAgentCall($jurySpy->calls, 'synthesizer');
ok('JuryRunner appelle router pour pm', is_array($pmJuryCall));
ok('JuryRunner override pm', (string)($pmJuryCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('JuryRunner appelle router pour synthesizer', is_array($synthJuryCall));
ok('JuryRunner override synthesizer', (string)($synthJuryCall['resolved_provider_id'] ?? '') === 'ProviderB');

// 6) ConfrontationRunner (team-like expansion already materialized into per-agent overrides)
$sidCf = mkSessionId('bbx-cf');
createSession($sessionRepo, $sidCf, 'confrontation', ['pm', 'architect', 'critic', 'qa', 'synthesizer'], 'Tester override Confrontation');
$sapRepo->saveForSession($sidCf, [
    'pm' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'architect' => ['provider_id' => 'ProviderB', 'model' => 'ModelB'],
    'critic' => ['provider_id' => 'ProviderC', 'model' => 'ModelC'],
    'qa' => ['provider_id' => 'ProviderC', 'model' => 'ModelC'],
]);
$cf = new ConfrontationRunner();
$cfSpy = new SpyProviderRouter();
injectSpy($cf, $cfSpy);
$cf->run(
    $sidCf,
    'Tester override Confrontation',
    ['pm', 'architect', 'critic', 'qa', 'synthesizer'],
    true,
    'fr',
    1,
    'sequential',
    'all-agents-reply',
    false,
    null,
    false,
    0.65,
    $sapRepo->findBySession($sidCf),
    0.55,
    null,
    null
);
$pmCfCall = findAgentCall($cfSpy->calls, 'pm');
$architectCfCall = findAgentCall($cfSpy->calls, 'architect');
$criticCfCall = findAgentCall($cfSpy->calls, 'critic');
$qaCfCall = findAgentCall($cfSpy->calls, 'qa');
ok('ConfrontationRunner override Blue pm', (string)($pmCfCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('ConfrontationRunner override Blue architect', (string)($architectCfCall['resolved_provider_id'] ?? '') === 'ProviderB');
ok('ConfrontationRunner override Red critic', (string)($criticCfCall['resolved_provider_id'] ?? '') === 'ProviderC');
ok('ConfrontationRunner override Red qa', (string)($qaCfCall['resolved_provider_id'] ?? '') === 'ProviderC');

// 7) Sans override: fallback persona/global + session_override absent
$sidNoOverride = mkSessionId('bbx-noov');
createSession($sessionRepo, $sidNoOverride, 'chat', ['pm'], 'Sans override');
$sapRepo->saveForSession($sidNoOverride, []); // clear for this session
$chatNoOv = new ChatRunner();
$chatNoOvSpy = new SpyProviderRouter();
injectSpy($chatNoOv, $chatNoOvSpy);
$chatNoOv->runWithRuntime($sidNoOverride, '@pm Sans override', ['pm'], '', 'fr', null, null);
$pmNoOvCall = findAgentCall($chatNoOvSpy->calls, 'pm');
ok('Sans override: call present', is_array($pmNoOvCall));
ok(
    'Sans override: routing source fallback',
    in_array((string)($pmNoOvCall['routing_source'] ?? ''), ['persona_default', 'global_routing'], true)
);
ok('Sans override: session_override absent', empty($pmNoOvCall['session_override']));

// 8) Override indisponible -> fallback visible (call + meta_json)
$sidFallback = mkSessionId('bbx-fallback');
createSession($sessionRepo, $sidFallback, 'chat', ['pm'], 'Override indisponible');
$sapRepo->saveForSession($sidFallback, [
    'pm' => ['provider_id' => 'UNAVAILABLE_PROVIDER_B', 'model' => 'ModelB'],
]);
$chatFallback = new ChatRunner();
$chatFallbackSpy = new SpyProviderRouter();
injectSpy($chatFallback, $chatFallbackSpy);
$chatFallback->runWithRuntime($sidFallback, '@pm test fallback', ['pm'], '', 'fr', null, null);
$pmFallbackCall = findAgentCall($chatFallbackSpy->calls, 'pm');
ok('Override indisponible: fallback_used=true', (bool)($pmFallbackCall['fallback_used'] ?? false) === true);
ok('Override indisponible: provider résolu fallback global', (string)($pmFallbackCall['resolved_provider_id'] ?? '') === 'GLOBAL_TEST_PROVIDER');
$fallbackMeta = latestAssistantMessageMetaByAgent($sidFallback, 'pm');
ok('Override indisponible: meta llm_routing présent', is_array($fallbackMeta['llm_routing'] ?? null));
ok('Override indisponible: meta provider_fallback_used=true', ($fallbackMeta['llm_routing']['provider_fallback_used'] ?? null) === true);
ok('Override indisponible: meta fallback_reason non vide', trim((string)($fallbackMeta['llm_routing']['fallback_reason'] ?? '')) !== '');
ok('Override indisponible: meta requested_provider_id conservé', (string)($fallbackMeta['llm_routing']['requested_provider_id'] ?? '') === 'UNAVAILABLE_PROVIDER_B');

// 9) Normalisation ID (mixed case key still resolved)
$sidNorm = mkSessionId('bbx-norm');
createSession($sessionRepo, $sidNorm, 'quick-decision', ['pm'], 'Normalisation ID');
$qdNorm = new QuickDecisionRunner();
$qdNormSpy = new SpyProviderRouter();
injectSpy($qdNorm, $qdNormSpy);
$qdNorm->run(
    $sidNorm,
    'Normalisation ID',
    ['pm'],
    'fr',
    false,
    null,
    [' PM ' => ['provider_id' => 'ProviderB', 'model' => 'ModelB']],
    0.55,
    null,
    null
);
$pmNormCall = findAgentCall($qdNormSpy->calls, 'pm');
ok('Normalisation ID: clé mixed-case retrouvée', (string)($pmNormCall['resolved_provider_id'] ?? '') === 'ProviderB');

// 10) Confrontation team override indisponible pour Blue, Red inchangé
$sidCfFallback = mkSessionId('bbx-cf-fb');
createSession($sessionRepo, $sidCfFallback, 'confrontation', ['pm', 'architect', 'critic', 'qa', 'synthesizer'], 'Confrontation fallback');
$sapRepo->saveForSession($sidCfFallback, [
    'pm' => ['provider_id' => 'UNAVAILABLE_BLUE', 'model' => 'ModelBlue'],
    'architect' => ['provider_id' => 'UNAVAILABLE_BLUE', 'model' => 'ModelBlue'],
    'critic' => ['provider_id' => 'ProviderC', 'model' => 'ModelC'],
    'qa' => ['provider_id' => 'ProviderC', 'model' => 'ModelC'],
]);
$cfFallback = new ConfrontationRunner();
$cfFallbackSpy = new SpyProviderRouter();
injectSpy($cfFallback, $cfFallbackSpy);
$cfFallback->run(
    $sidCfFallback,
    'Confrontation fallback',
    ['pm', 'architect', 'critic', 'qa', 'synthesizer'],
    true,
    'fr',
    1,
    'sequential',
    'all-agents-reply',
    false,
    null,
    false,
    0.65,
    $sapRepo->findBySession($sidCfFallback),
    0.55,
    null,
    null
);
$pmCfFallbackCall = findAgentCall($cfFallbackSpy->calls, 'pm');
$criticCfFallbackCall = findAgentCall($cfFallbackSpy->calls, 'critic');
ok('Confrontation fallback Blue: fallback_used=true', (bool)($pmCfFallbackCall['fallback_used'] ?? false) === true);
ok('Confrontation fallback Blue: provider fallback global', (string)($pmCfFallbackCall['resolved_provider_id'] ?? '') === 'GLOBAL_TEST_PROVIDER');
ok('Confrontation fallback Red: provider reste ProviderC', (string)($criticCfFallbackCall['resolved_provider_id'] ?? '') === 'ProviderC');

echo "All runner LLM override black-box checks passed." . PHP_EOL;


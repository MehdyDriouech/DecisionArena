<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$checks = [
    'DecisionRoomRunner uses collector' => [
        $base . '/src/Domain/Orchestration/DecisionRoomRunner.php',
        'PromptInjectionTraceCollector::begin',
    ],
    'ChatRunner governed payload' => [
        $base . '/src/Domain/Orchestration/ChatRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'ReactiveChatRunner governed payload' => [
        $base . '/src/Domain/Orchestration/ReactiveChatRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'QuickDecisionRunner governed payload' => [
        $base . '/src/Domain/Orchestration/QuickDecisionRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'StressTestRunner governed payload' => [
        $base . '/src/Domain/Orchestration/StressTestRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'JuryRunner governed payload' => [
        $base . '/src/Domain/Orchestration/JuryRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'ConfrontationRunner governed payload' => [
        $base . '/src/Domain/Orchestration/ConfrontationRunner.php',
        'CognitiveRuntimeGovernance::tracePromptPayload',
    ],
    'DecisionRoomRunner uses trace helper' => [
        $base . '/src/Domain/Orchestration/DecisionRoomRunner.php',
        'CognitiveRuntimeGovernance::collectTracesFromRounds',
    ],
    'JuryRunner uses trace helper' => [
        $base . '/src/Domain/Orchestration/JuryRunner.php',
        'CognitiveRuntimeGovernance::collectTracesFromMessageBuckets',
    ],
    'ConfrontationRunner uses trace helper' => [
        $base . '/src/Domain/Orchestration/ConfrontationRunner.php',
        'CognitiveRuntimeGovernance::collectTracesFromMessageBuckets',
    ],
    'Governance exposes runtime metrics' => [
        $base . '/src/Domain/Orchestration/CognitiveRuntimeGovernance.php',
        "'runtime_metrics' =>",
    ],
    'Registry has chat payload key' => [
        $base . '/src/Domain/CognitiveGovernance/PromptInjectionRegistry.php',
        "'chat_user_payload' => 'chat_user_payload'",
    ],
    'Registry has reactive payload key' => [
        $base . '/src/Domain/CognitiveGovernance/PromptInjectionRegistry.php',
        "'reactive_user_payload' => 'reactive_user_payload'",
    ],
];

$failed = 0;
foreach ($checks as $label => [$path, $needle]) {
    $content = @file_get_contents($path);
    if (!is_string($content) || $content === '') {
        echo "[FAIL] {$label} (file unreadable)\n";
        $failed++;
        continue;
    }
    if (strpos($content, $needle) === false) {
        echo "[FAIL] {$label}\n";
        $failed++;
        continue;
    }
    echo "[PASS] {$label}\n";
}

if ($failed > 0) {
    echo "Runtime uniformization checks failed: {$failed}\n";
    exit(1);
}

echo "Runtime uniformization checks passed.\n";
exit(0);

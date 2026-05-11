<?php

declare(strict_types=1);

namespace Domain\Orchestration;

use Domain\CognitiveGovernance\DeterministicHash;
use Domain\CognitiveGovernance\RuntimeSafetyRecorder;

final class CognitiveRuntimeGovernance
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $context
     * @return array{messages:array<int, array<string,mixed>>,trace:?array<string,mixed>,meta_json:?string}
     */
    public static function tracePromptPayload(
        array $messages,
        array $context,
        string $userBlockId,
        string $cognitiveLayer,
        string $inclusionReason,
        array $extra = []
    ): array {
        PromptInjectionTraceCollector::begin($context);
        try {
            $systemChars = 0;
            $userIndex = null;
            foreach ($messages as $idx => $msg) {
                $role = (string)($msg['role'] ?? '');
                if ($role === 'system') {
                    $systemChars += mb_strlen((string)($msg['content'] ?? ''), 'UTF-8');
                }
                if ($role === 'user' && $userIndex === null) {
                    $userIndex = $idx;
                }
            }

            if ($systemChars > 0) {
                PromptInjectionTraceCollector::addStep(
                    'global_system',
                    'governance_layer',
                    $systemChars,
                    'runner_system_prompt_assembled',
                    null,
                    ['mode' => (string)($context['mode'] ?? 'unknown')]
                );
            }

            if ($userIndex === null) {
                PromptInjectionTraceCollector::addStep(
                    $userBlockId,
                    $cognitiveLayer,
                    0,
                    'skipped',
                    'missing_user_prompt_payload',
                    ['status' => 'ignored']
                );
            } else {
                $content = (string)($messages[$userIndex]['content'] ?? '');
                $inputHash = DeterministicHash::sha256([
                    'mode' => (string)($context['mode'] ?? ''),
                    'user_block_id' => $userBlockId,
                    'content' => $content,
                ]);
                $budget = CognitiveBudgetEngine::applySegment($userBlockId, $content);
                $messages[$userIndex]['content'] = $budget['content'];
                $contentHash = DeterministicHash::sha256($budget['content']);
                PromptInjectionTraceCollector::addStep(
                    $userBlockId,
                    $cognitiveLayer,
                    mb_strlen($budget['content'], 'UTF-8'),
                    $inclusionReason,
                    null,
                    array_merge($extra, [
                        'refused_chars' => $budget['refused_chars'],
                        'pruning_decision' => $budget['pruning_decision'],
                        'fallback_decision' => $budget['fallback_policy'],
                        'score_breakdown' => $budget['score_breakdown'],
                        'budget_layer' => $budget['budget_layer'],
                        'chars_budget_allowed' => $budget['chars_budget_allowed'],
                        'budget_soft_cap_registry' => $budget['soft_budget'],
                        'budget_hard_cap_registry' => $budget['hard_budget'],
                        'input_hash' => $inputHash,
                        'content_hash' => $contentHash,
                    ])
                );
            }

            $trace = PromptInjectionTraceCollector::finish();
            if ($trace === null) {
                RuntimeSafetyRecorder::record('runtime_trace_missing', [
                    'mode' => (string)($context['mode'] ?? ''),
                    'session_id' => $context['session_id'] ?? null,
                    'agent_id' => $context['agent_id'] ?? null,
                    'block_id' => $userBlockId,
                ]);
            }
            $metaJson = $trace !== null
                ? json_encode(['prompt_injection_trace' => $trace], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;

            return [
                'messages' => $messages,
                'trace' => $trace,
                'meta_json' => $metaJson,
            ];
        } catch (\Throwable) {
            PromptInjectionTraceCollector::cancel();

            return [
                'messages' => $messages,
                'trace' => null,
                'meta_json' => null,
            ];
        }
    }

    /**
     * @param list<array<string,mixed>> $traces
     * @return array{
     *   prompt_injection_trace:list<array<string,mixed>>,
     *   cognitive_budget:array<string,mixed>,
     *   cognitive_runtime:array<string,mixed>,
     *   runtime_warnings:list<mixed>,
     *   deduplication_events:list<array<string,mixed>>,
     *   qa_mode:string,
     *   provenance_integrity:array<string,mixed>,
     *   runtime_metrics:array<string,mixed>
     * }
     */
    public static function summarizeTraces(array $traces, string $mode): array
    {
        $warnings = [];
        $dedupEvents = [];
        $pruningEvents = [];
        $globalConsumed = 0;
        $stepsCount = 0;
        $seenDedup = [];
        $qaModes = [];
        $missingInputHash = 0;
        $missingSourceHash = 0;
        $missingRuntimeHash = 0;
        $missingProvenance = 0;

        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $stepsCount += is_array($trace['steps'] ?? null) ? count($trace['steps']) : 0;

            $traceWarnings = $trace['runtime_warnings'] ?? ($trace['runtime_policy_warnings'] ?? []);
            if (is_array($traceWarnings)) {
                foreach ($traceWarnings as $warning) {
                    $warnings[] = $warning;
                }
            }
            $qaMode = (string)($trace['qa_mode'] ?? '');
            if ($qaMode !== '') {
                $qaModes[$qaMode] = true;
            }
            if (!is_string($trace['input_hash'] ?? null) || (string)$trace['input_hash'] === '') {
                $missingInputHash++;
            }
            if (!is_string($trace['source_hash'] ?? null) || (string)$trace['source_hash'] === '') {
                $missingSourceHash++;
            }
            if (!is_string($trace['runtime_hash'] ?? null) || (string)$trace['runtime_hash'] === '') {
                $missingRuntimeHash++;
            }
            if (!is_array($trace['cognitive_provenance'] ?? null)) {
                $missingProvenance++;
            }

            $budget = is_array($trace['cognitive_budget'] ?? null) ? $trace['cognitive_budget'] : [];
            $globalConsumed += (int)($budget['global_consumed_chars'] ?? 0);
            $events = is_array($budget['pruning_events'] ?? null) ? $budget['pruning_events'] : [];
            foreach ($events as $event) {
                if (is_array($event)) {
                    $pruningEvents[] = $event;
                }
            }

            $dedup = is_array($trace['deduplication_events'] ?? null) ? $trace['deduplication_events'] : [];
            foreach ($dedup as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $hash = md5(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
                if (isset($seenDedup[$hash])) {
                    continue;
                }
                $seenDedup[$hash] = true;
                $dedupEvents[] = $event;
            }
        }

        $warnings = array_values(array_unique(array_map('strval', $warnings)));
        $traceCount = count($traces);
        $qaMode = count($qaModes) === 1
            ? (string)array_key_first($qaModes)
            : \Domain\CognitiveGovernance\CognitiveRuntimeQAMode::current();
        $provenanceIntegrity = [
            'trace_count' => $traceCount,
            'hashes_complete' => $missingInputHash === 0 && $missingSourceHash === 0 && $missingRuntimeHash === 0,
            'provenance_complete' => $missingProvenance === 0,
            'missing_input_hash' => $missingInputHash,
            'missing_source_hash' => $missingSourceHash,
            'missing_runtime_hash' => $missingRuntimeHash,
            'missing_cognitive_provenance' => $missingProvenance,
            'integrity_hash' => DeterministicHash::sha256([
                'mode' => $mode,
                'trace_count' => $traceCount,
                'missing_input_hash' => $missingInputHash,
                'missing_source_hash' => $missingSourceHash,
                'missing_runtime_hash' => $missingRuntimeHash,
                'missing_cognitive_provenance' => $missingProvenance,
            ]),
        ];
        $traceBytes = 0;
        foreach ($traces as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $encoded = json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $traceBytes += is_string($encoded) ? strlen($encoded) : 0;
        }
        $runtimeMetrics = [
            'trace_count' => $traceCount,
            'steps_count' => $stepsCount,
            'warnings_count' => count($warnings),
            'pruning_events_count' => count($pruningEvents),
            'deduplication_events_count' => count($dedupEvents),
            'estimated_trace_payload_bytes' => $traceBytes,
            'avg_steps_per_trace' => $traceCount > 0 ? round($stepsCount / $traceCount, 2) : 0.0,
        ];

        return [
            'prompt_injection_trace' => $traces,
            'cognitive_budget' => [
                'trace_count' => $traceCount,
                'global_consumed_chars' => $globalConsumed,
                'pruning_events' => $pruningEvents,
                'budget_hash' => DeterministicHash::sha256([
                    'mode' => $mode,
                    'trace_count' => $traceCount,
                    'global_consumed_chars' => $globalConsumed,
                    'pruning_events' => $pruningEvents,
                ]),
            ],
            'cognitive_runtime' => [
                'mode' => $mode,
                'trace_count' => $traceCount,
                'steps_count' => $stepsCount,
                'computed_by' => 'CognitiveRuntimeGovernance::summarizeTraces',
                'runtime_hash' => DeterministicHash::sha256([
                    'mode' => $mode,
                    'trace_count' => $traceCount,
                    'steps_count' => $stepsCount,
                    'global_consumed_chars' => $globalConsumed,
                    'pruning_events' => $pruningEvents,
                    'deduplication_events' => $dedupEvents,
                    'runtime_warnings' => $warnings,
                ]),
            ],
            'runtime_warnings' => $warnings,
            'deduplication_events' => $dedupEvents,
            'qa_mode' => $qaMode,
            'provenance_integrity' => $provenanceIntegrity,
            'runtime_metrics' => $runtimeMetrics,
        ];
    }

    /**
     * @param array<int, array<int, array<string,mixed>>> $messageBuckets
     * @return list<array<string,mixed>>
     */
    public static function collectTracesFromMessageBuckets(array $messageBuckets): array
    {
        $traces = [];
        foreach ($messageBuckets as $bucket) {
            foreach ($bucket as $msg) {
                $metaJson = (string)($msg['meta_json'] ?? '');
                if ($metaJson === '') {
                    continue;
                }
                $decoded = json_decode($metaJson, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $trace = $decoded['prompt_injection_trace'] ?? null;
                if (is_array($trace)) {
                    $traces[] = $trace;
                }
            }
        }

        return $traces;
    }

    /**
     * @param array<int, array<int, array<string,mixed>>> $rounds
     * @return list<array<string,mixed>>
     */
    public static function collectTracesFromRounds(array $rounds): array
    {
        return self::collectTracesFromMessageBuckets($rounds);
    }
}

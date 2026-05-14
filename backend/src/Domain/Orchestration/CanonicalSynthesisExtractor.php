<?php
namespace Domain\Orchestration;

use Domain\Evidence\LightweightClaimExtractor;

/**
 * Tolerant convergence layer for final model syntheses.
 *
 * This is an internal runtime contract, not a generation format. The LLM may
 * answer in prose, Markdown, JSON, or hybrids; this extractor repairs and
 * normalizes useful signals into one stable shape for downstream services.
 */
class CanonicalSynthesisExtractor {
    public const CONTRACT_VERSION = RuntimeContracts::CANONICAL_SYNTHESIS_CONTRACT_VERSION;

    /** @return array<string,mixed> */
    public static function extract(string $content, ?string $playbookId = null): array {
        $content = trim($content);
        $diagnostics = [
            'parser_confidence' => 0.0,
            'extracted_fields' => [],
            'missing_fields' => [],
            'repaired_fields' => [],
            'warnings' => [],
            'fallback_used' => false,
            'extraction_strategy_used' => [],
        ];

        $contract = self::blankContract($playbookId);
        if ($content === '') {
            $contract['parser_diagnostics'] = array_merge($diagnostics, [
                'warnings' => ['empty_synthesis_output'],
                'missing_fields' => self::requiredContractFields(),
            ]);
            return $contract;
        }

        [$jsonData, $jsonDiag] = self::extractJsonish($content);
        if (is_array($jsonData)) {
            $contract = self::mergeContract($contract, self::contractFromArray($jsonData));
            $diagnostics['extraction_strategy_used'][] = 'structured_json';
            if (!empty($jsonDiag['repaired'])) {
                $diagnostics['repaired_fields'][] = 'json_repair';
            }
        }

        $sectionContract = self::extractFromSections($content);
        if (self::hasUsefulData($sectionContract)) {
            $contract = self::mergeContract($contract, $sectionContract);
            $diagnostics['extraction_strategy_used'][] = 'semantic_sections';
        }

        $heuristicContract = self::extractHeuristics($content);
        if (self::hasUsefulData($heuristicContract)) {
            $before = self::filledFields($contract);
            $contract = self::mergeContract($contract, $heuristicContract);
            if (self::filledFields($contract) !== $before) {
                $diagnostics['fallback_used'] = true;
                $diagnostics['extraction_strategy_used'][] = 'heuristic_recovery';
            }
        }

        $fallbackContract = self::inferFallbacks($content, $contract);
        if (self::hasUsefulData($fallbackContract)) {
            $contract = self::mergeContract($contract, $fallbackContract);
            $diagnostics['fallback_used'] = true;
            $diagnostics['extraction_strategy_used'][] = 'fallback_inference';
        }

        $contract['outcomes'] = self::extractPlaybookOutcomes($content, $playbookId, $contract['outcomes']);
        $contract['evidence_claims'] = LightweightClaimExtractor::extract($content, $playbookId);
        $contract['evidence_summary'] = LightweightClaimExtractor::summarize($contract['evidence_claims']);

        $diagnostics['extraction_strategy_used'] = array_values(array_unique($diagnostics['extraction_strategy_used']));
        if ($diagnostics['extraction_strategy_used'] === []) {
            $diagnostics['extraction_strategy_used'][] = 'graceful_degradation';
            $diagnostics['fallback_used'] = true;
        }

        $diagnostics['extracted_fields'] = self::filledFields($contract);
        $diagnostics['missing_fields'] = array_values(array_diff(self::requiredContractFields(), $diagnostics['extracted_fields']));
        if (!empty($jsonDiag['warnings'])) {
            $diagnostics['warnings'] = array_merge($diagnostics['warnings'], $jsonDiag['warnings']);
        }
        if ($diagnostics['missing_fields'] !== []) {
            $diagnostics['warnings'][] = 'partial_synthesis_contract';
        }
        $diagnostics['warnings'] = array_values(array_unique($diagnostics['warnings']));
        $diagnostics['repaired_fields'] = array_values(array_unique($diagnostics['repaired_fields']));
        $diagnostics['parser_confidence'] = self::confidenceScore($contract, $diagnostics);

        $contract['parser_diagnostics'] = $diagnostics;
        return $contract;
    }

    /** @return array<string,mixed> */
    private static function blankContract(?string $playbookId): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'taxonomy_version' => RuntimeContracts::TAXONOMY_VERSION,
            'playbook_id' => $playbookId ?: '',
            'decision' => '',
            'status' => '',
            'confidence' => '',
            'why' => [],
            'risks' => [],
            'blocking_unknowns' => [],
            'recommended_next_actions' => [],
            'validation_logic' => [
                'success_signal' => '',
                'validation_threshold' => '',
                'failure_signal' => '',
                'kill_criteria' => '',
            ],
            'outcomes' => [],
            'evidence_claims' => [],
            'evidence_summary' => [
                'claim_count' => 0,
                'status_counts' => [],
                'primary_fragility' => 'none',
            ],
            'parser_diagnostics' => [],
        ];
    }

    /** @return list<string> */
    private static function requiredContractFields(): array {
        return ['decision', 'confidence', 'why', 'risks', 'recommended_next_actions', 'validation_logic'];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private static function extractJsonish(string $content): array {
        $diagnostics = ['repaired' => false, 'warnings' => []];
        $candidates = [];
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $content, $blocks)) {
            foreach ($blocks[1] as $raw) {
                $candidates[] = trim($raw);
            }
        }
        if (str_contains($content, '{')) {
            $candidates[] = self::truncateBalancedJson($content);
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '' || !str_contains($candidate, '{')) {
                continue;
            }
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return [$decoded, $diagnostics];
            }
            $repaired = self::repairJsonish($candidate);
            if ($repaired !== $candidate) {
                $decoded = json_decode($repaired, true);
                if (is_array($decoded)) {
                    $diagnostics['repaired'] = true;
                    return [$decoded, $diagnostics];
                }
            }
        }

        if ($candidates !== []) {
            $diagnostics['warnings'][] = 'json_candidate_unreadable';
        }
        return [null, $diagnostics];
    }

    /** @return array<string,mixed> */
    private static function contractFromArray(array $data): array {
        $root = $data['canonical_synthesis'] ?? $data['synthesis'] ?? $data['verdict'] ?? $data;
        if (!is_array($root)) {
            $root = $data;
        }

        $validation = $root['validation_logic'] ?? $root['validation'] ?? [];
        $validation = is_array($validation) ? $validation : [];
        return [
            'playbook_id' => (string)($root['playbook_id'] ?? ''),
            'decision' => self::normalizeDecision((string)($root['decision'] ?? $root['verdict_label'] ?? $root['verdict'] ?? '')),
            'status' => self::normalizeStatus((string)($root['status'] ?? $root['decision_status'] ?? '')),
            'confidence' => self::normalizeConfidence((string)($root['confidence'] ?? $root['confidence_level'] ?? $root['confidence_score'] ?? '')),
            'why' => self::normalizeList($root['why'] ?? $root['reasons'] ?? $root['key_reasons'] ?? []),
            'risks' => self::normalizeList($root['risks'] ?? $root['main_risks'] ?? $root['failure_modes'] ?? []),
            'blocking_unknowns' => self::normalizeList($root['blocking_unknowns'] ?? $root['unknowns'] ?? $root['evidence_gaps'] ?? []),
            'recommended_next_actions' => self::normalizeList($root['recommended_next_actions'] ?? $root['recommended_action'] ?? $root['next_step'] ?? []),
            'validation_logic' => [
                'success_signal' => (string)($validation['success_signal'] ?? $validation['success'] ?? ''),
                'validation_threshold' => (string)($validation['validation_threshold'] ?? $validation['threshold'] ?? ''),
                'failure_signal' => (string)($validation['failure_signal'] ?? $validation['failure'] ?? ''),
                'kill_criteria' => (string)($validation['kill_criteria'] ?? $validation['kill'] ?? ''),
            ],
            'outcomes' => is_array($root['outcomes'] ?? null) ? $root['outcomes'] : [],
        ];
    }

    /** @return array<string,mixed> */
    private static function extractFromSections(string $content): array {
        $why = self::listFromSection($content, ['why', 'rationale', 'key reasons', 'verdict summary', 'conclusion']);
        $risks = self::listFromSection($content, ['main risks', 'risks', 'key risks', 'failure modes', 'highest impact risks']);
        $unknowns = self::listFromSection($content, ['blocking unknowns', 'unknowns', 'open questions', 'evidence gaps']);
        $actions = self::listFromSection($content, ['next step', 'recommended action', 'recommended next step', 'immediate next action', 'action plan']);

        return [
            'decision' => self::normalizeDecision(self::sectionText($content, ['decision', 'final verdict', 'verdict label', 'recommendation', 'judgment'])),
            'status' => self::normalizeStatus(self::sectionText($content, ['status', 'reliability assessment', 'decision status'])),
            'confidence' => self::normalizeConfidence(self::sectionText($content, ['confidence', 'confidence level'])),
            'why' => $why,
            'risks' => $risks,
            'blocking_unknowns' => $unknowns,
            'recommended_next_actions' => $actions,
            'validation_logic' => self::extractValidationLogic($content),
        ];
    }

    /** @return array<string,mixed> */
    private static function extractHeuristics(string $content): array {
        $risks = [];
        $unknowns = [];
        $actions = [];
        $why = [];
        if (preg_match('/(?:main risk|biggest risk|primary risk|risk)\s*:\s*(.+?)(?=(?:\s+[A-Z][A-Za-z ]{2,30}\s*:)|[.!?](?:\s|$)|$)/i', $content, $m)) {
            $risks[] = trim((string)$m[1]);
        }
        if (preg_match('/(?:next step|recommended action|immediate next action|action)\s*:\s*(.+?)(?=(?:\s+[A-Z][A-Za-z ]{2,30}\s*:)|[.!?](?:\s|$)|$)/i', $content, $m)) {
            $actions[] = trim((string)$m[1]);
        }
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*[-*]\s*/', '', (string)$line));
            if ($line === '') {
                continue;
            }
            $lower = strtolower($line);
            if (substr_count($line, ':') >= 2 && preg_match('/\b(main risk|next step|confidence|success signal|kill criteria)\s*:/i', $line)) {
                continue;
            }
            if (count($risks) < 3 && preg_match('/\b(risk|failure|fragile|danger|blocker)\b/', $lower)) {
                $risks[] = $line;
            } elseif (count($unknowns) < 3 && preg_match('/\b(unknown|unclear|missing|insufficient|evidence gap|open question)\b/', $lower)) {
                $unknowns[] = $line;
            } elseif (count($actions) < 3 && preg_match('/\b(next|action|recommend|test|experiment|validate)\b/', $lower)) {
                $actions[] = $line;
            } elseif (count($why) < 3 && preg_match('/\b(because|therefore|reason|driven by|based on)\b/', $lower)) {
                $why[] = $line;
            }
        }

        return [
            'decision' => self::inferDecision($content),
            'confidence' => self::inferConfidence($content),
            'why' => $why,
            'risks' => $risks,
            'blocking_unknowns' => $unknowns,
            'recommended_next_actions' => $actions,
            'validation_logic' => self::extractValidationLogic($content),
        ];
    }

    /** @return array<string,mixed> */
    private static function inferFallbacks(string $content, array $current): array {
        $out = [];
        if (($current['decision'] ?? '') === '') {
            $out['decision'] = self::inferDecision($content);
        }
        if (($current['confidence'] ?? '') === '') {
            $out['confidence'] = self::inferConfidence($content);
        }
        if (empty($current['recommended_next_actions'])) {
            $sentence = self::firstSentenceMatching($content, ['recommend', 'next', 'should', 'start by']);
            if ($sentence !== '') {
                $out['recommended_next_actions'] = [$sentence];
            }
        }
        if (empty($current['risks'])) {
            $sentence = self::firstSentenceMatching($content, ['risk', 'failure', 'concern']);
            if ($sentence !== '') {
                $out['risks'] = [$sentence];
            }
        }
        if (empty($current['why'])) {
            $first = self::firstSentence($content);
            if ($first !== '') {
                $out['why'] = [$first];
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function extractValidationLogic(string $content): array {
        $block = self::sectionText($content, ['validation logic', 'validation', 'success signal', 'kill criteria']);
        $haystack = $block !== '' ? $block : $content;
        return [
            'success_signal' => self::labelValue($haystack, ['success signal', 'success metric', 'validation signal']),
            'validation_threshold' => self::labelValue($haystack, ['validation threshold', 'threshold']),
            'failure_signal' => self::labelValue($haystack, ['failure signal', 'failure metric']),
            'kill_criteria' => self::labelValue($haystack, ['kill criteria', 'kill criterion', 'stop criteria', 'pivot criteria']),
        ];
    }

    /** @param array<string,mixed> $existing @return array<string,mixed> */
    private static function extractPlaybookOutcomes(string $content, ?string $playbookId, array $existing): array {
        $contract = PlaybookRuntime::contractFor($playbookId);
        if (!$contract) {
            return $existing;
        }
        $out = $existing;
        foreach (($contract['validation_expectations'] ?? []) as $outcome => $aliases) {
            if (!empty($out[$outcome])) {
                continue;
            }
            $section = self::sectionText($content, $aliases);
            if ($section === '') {
                $section = self::firstSentenceMatching($content, $aliases);
            }
            if ($section !== '') {
                $out[$outcome] = self::limitText($section, 600);
            }
        }
        return $out;
    }

    private static function normalizeDecision(string $raw): string {
        $l = strtolower(trim(strip_tags($raw)));
        if ($l === '') return '';
        if (preg_match('/\b(no[-_\s]?go|reject|stop)\b/', $l)) return 'NO-GO';
        if (preg_match('/\b(go|proceed|pursue|approve|build)\b/', $l)) return 'GO';
        if (preg_match('/\b(kill)\b/', $l)) return 'NO-GO';
        if (preg_match('/\b(no consensus|no_consensus)\b/', $l)) return 'NO_CONSENSUS';
        if (preg_match('/\b(insufficient context|needs? more info|not enough information|validate first|validate[_\s-]?first)\b/', $l)) return 'INSUFFICIENT_CONTEXT';
        if (preg_match('/\b(iterate|pivot|narrow|defer|reduce scope|reduce-scope|pause|test[_\s-]?first)\b/', $l)) return 'ITERATE';
        return '';
    }

    private static function normalizeStatus(string $raw): string {
        // Canonical synthesis stores only persistence-ready runtime taxonomy.
        return RuntimeContracts::normalizeStatus($raw);
    }

    private static function normalizeConfidence(string $raw): string {
        // Canonical synthesis stores only persistence-ready runtime taxonomy.
        return RuntimeContracts::normalizeConfidence($raw);
    }

    private static function inferDecision(string $content): string {
        if (preg_match('/(?:decision|recommendation|verdict|conclusion|judgment)\s*[:\-]\s*(.+?)(?:[.!?]|\n|$)/is', $content, $m)) {
            $hit = self::normalizeDecision((string)$m[1]);
            if ($hit !== '') {
                return $hit;
            }
        }
        if (preg_match('/\b(recommend|should)\s+(proceed|pursue|approve|build|continue)\b/i', $content)) {
            return 'GO';
        }
        if (preg_match('/\b(recommend|should)\s+(stop|reject|kill)\b/i', $content)) {
            return 'NO-GO';
        }
        if (preg_match('/\b(recommend|should)\s+(iterate|pivot|narrow|defer|pause|test)\b/i', $content)) {
            return 'ITERATE';
        }
        return '';
    }

    private static function inferConfidence(string $content): string {
        return self::normalizeConfidence($content);
    }

    /** @param mixed $value @return list<string> */
    private static function normalizeList(mixed $value): array {
        if (is_string($value)) {
            return self::splitListText($value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['description'] ?? $item['title'] ?? json_encode($item, JSON_UNESCAPED_UNICODE);
            }
            $txt = trim((string)$item);
            if ($txt !== '') {
                $out[] = self::limitText($txt, 400);
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    /** @param list<string> $aliases */
    private static function sectionText(string $content, array $aliases): string {
        foreach ($aliases as $alias) {
            $a = preg_quote($alias, '/');
            if (preg_match('/(?:^|\n)#{1,6}\s*' . $a . '\b[^\n]*\n(.*?)(?=\n#{1,6}\s|\z)/is', $content, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/(?:^|\n)\s*(?:[-*]\s*)?' . $a . '\s*[:\-]\s*(.+?)(?=\n\s*(?:[-*]\s*)?[A-Z][A-Za-z _\/-]{2,40}\s*[:\-]|\n#{1,6}\s|\n\n|\z)/is', $content, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    /** @param list<string> $aliases @return list<string> */
    private static function listFromSection(string $content, array $aliases): array {
        $section = self::sectionText($content, $aliases);
        return $section !== '' ? self::splitListText($section) : [];
    }

    /** @return list<string> */
    private static function splitListText(string $text): array {
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*]|\d+[.)])\s*/', '', (string)$line));
            if ($line !== '') {
                $items[] = self::limitText($line, 400);
            }
        }
        if ($items === [] && trim($text) !== '') {
            $items[] = self::limitText(trim($text), 400);
        }
        return array_slice(array_values(array_unique($items)), 0, 6);
    }

    /** @param list<string> $labels */
    private static function labelValue(string $text, array $labels): string {
        foreach ($labels as $label) {
            $l = preg_quote($label, '/');
            if (preg_match('/(?:^|\n)\s*(?:[-*]\s*)?' . $l . '\s*[:\-]\s*(.+?)(?=\n\s*(?:[-*]\s*)?[A-Za-z][A-Za-z _-]{2,35}\s*[:\-]|\n#{1,6}\s|\n\n|\z)/is', $text, $m)) {
                return self::limitText(trim($m[1]), 400);
            }
        }
        return '';
    }

    /** @param list<string> $needles */
    private static function firstSentenceMatching(string $content, array $needles): string {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/', $content) ?: [] as $sentence) {
            $s = trim((string)$sentence);
            if ($s === '') continue;
            $lower = strtolower($s);
            foreach ($needles as $needle) {
                if (str_contains($lower, strtolower($needle))) {
                    return self::limitText($s, 400);
                }
            }
        }
        return '';
    }

    private static function firstSentence(string $content): string {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/', trim($content)) ?: [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '' && !str_starts_with($p, '```')) {
                return self::limitText($p, 400);
            }
        }
        return '';
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $incoming @return array<string,mixed> */
    private static function mergeContract(array $base, array $incoming): array {
        foreach ($incoming as $key => $value) {
            if ($key === 'validation_logic' && is_array($value)) {
                foreach ($value as $vk => $vv) {
                    if (($base[$key][$vk] ?? '') === '' && trim((string)$vv) !== '') {
                        $base[$key][$vk] = trim((string)$vv);
                    }
                }
                continue;
            }
            if ($key === 'outcomes' && is_array($value)) {
                $base['outcomes'] = array_merge($base['outcomes'] ?? [], array_filter($value, fn($v) => $v !== '' && $v !== []));
                continue;
            }
            if (is_array($value)) {
                if (empty($base[$key]) && $value !== []) {
                    $base[$key] = $value;
                }
            } elseif (($base[$key] ?? '') === '' && trim((string)$value) !== '') {
                $base[$key] = trim((string)$value);
            }
        }
        return $base;
    }

    /** @return list<string> */
    private static function filledFields(array $contract): array {
        $fields = [];
        foreach (self::requiredContractFields() as $field) {
            $value = $contract[$field] ?? null;
            if ($field === 'validation_logic') {
                if (is_array($value) && count(array_filter($value, fn($v) => trim((string)$v) !== '')) > 0) {
                    $fields[] = $field;
                }
            } elseif (is_array($value)) {
                if ($value !== []) $fields[] = $field;
            } elseif (trim((string)$value) !== '') {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    private static function hasUsefulData(array $contract): bool {
        return self::filledFields($contract) !== [] || !empty($contract['outcomes']);
    }

    private static function confidenceScore(array $contract, array $diagnostics): float {
        $required = self::requiredContractFields();
        $filled = count(array_intersect($required, $diagnostics['extracted_fields'] ?? []));
        $score = $filled / max(1, count($required));
        if (in_array('structured_json', $diagnostics['extraction_strategy_used'] ?? [], true)) {
            $score += 0.12;
        }
        if (!empty($contract['outcomes'])) {
            $score += 0.08;
        }
        if (!empty($diagnostics['warnings'])) {
            $score -= 0.08;
        }
        return round(max(0.05, min(1.0, $score)), 2);
    }

    private static function repairJsonish(string $json): string {
        $out = trim($json);
        $out = preg_replace('/^```(?:json)?|```$/i', '', $out);
        $out = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D"], '"', $out);
        $out = str_replace("\xE2\x80\x99", "'", $out);
        $out = preg_replace('/,\s*([}\]])/', '$1', $out);
        $out = self::balanceJsonClosers((string)$out);
        return trim((string)$out);
    }

    private static function balanceJsonClosers(string $json): string {
        $stack = [];
        $inString = false;
        $escape = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $stack[] = '}';
            } elseif ($c === '[') {
                $stack[] = ']';
            } elseif (($c === '}' || $c === ']') && $stack !== []) {
                array_pop($stack);
            }
        }
        while ($stack !== []) {
            $json .= array_pop($stack);
        }
        return $json;
    }

    private static function truncateBalancedJson(string $s): string {
        $start = strpos($s, '{');
        if ($start === false) {
            return trim($s);
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return substr($s, $start);
    }

    private static function limitText(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(substr($text, 0, $limit - 3)) . '...';
    }
}

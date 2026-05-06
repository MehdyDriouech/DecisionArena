<?php
declare(strict_types=1);

namespace Domain\DecisionReliability;

/**
 * Canonical interaction / memory summary from debate graph edges.
 * Single source for interaction_density consumed by false_consensus, guardrails and quality score.
 */
final class MemorySummaryBuilder {
    /**
     * @param array<int,array<string,mixed>> $edges
     * @param array<int,array<string,mixed>> $positions
     * @return array{
     *   interaction_density: float,
     *   explicit_edge_count: int,
     *   fallback_edge_count: int,
     *   inferred_edge_count: int,
     *   unknown_edge_count: int,
     *   reliable_edge_count: int,
     *   possible_edge_count: int,
     *   verified_interaction_count: int,
     *   challenged_claim_count: int,
     *   objection_count: int,
     *   concession_count: int,
     *   position_change_count: int,
     *   target_mismatch_count: int,
     *   interaction_contract_total: int,
     *   interaction_contract_verified: int,
     *   interaction_contract_verification_rate: float,
     *   claim_challenged_count: int
     * }
     */
    public static function buildMemorySummary(array $edges, array $positions = []): array {
        $explicit = 0;
        $fallback = 0;
        $inferred = 0;
        $unknown  = 0;
        $reliable = 0;
        $verifiedStructured = 0;
        $challengedClaim = 0;
        $objectionCount = 0;
        $concessionCount = 0;
        $positionChangeCount = 0;
        $targetMismatchCount = 0;
        $contractTotal = 0;
        $contractVerified = 0;

        foreach ($edges as $edge) {
            $src = strtolower((string)($edge['edge_source'] ?? 'unknown'));
            match ($src) {
                'explicit_target'   => $explicit++,
                'assigned_fallback' => $fallback++,
                'inferred_mention'  => $inferred++,
                default               => $unknown++,
            };
            if (self::isReliableInteractionEdge($edge)) {
                $reliable++;
            }
            if (self::isVerifiedStructuredInteraction($edge)) {
                $verifiedStructured++;
            }
            if (trim((string)($edge['claim_challenged'] ?? '')) !== '') {
                $challengedClaim++;
            }
            if (trim((string)($edge['objection'] ?? '')) !== '') {
                $objectionCount++;
            }
            if (trim((string)($edge['concession'] ?? '')) !== '') {
                $concessionCount++;
            }
            $pc = strtolower(trim((string)($edge['position_change'] ?? '')));
            if (in_array($pc, ['weakened', 'strengthened', 'changed'], true)) {
                $positionChangeCount++;
            }
            if (!empty($edge['target_mismatch']) && (int)$edge['target_mismatch'] === 1) {
                $targetMismatchCount++;
            }
            if (self::edgeHasParsedInteractionContract($edge)) {
                $contractTotal++;
                if (self::isVerifiedStructuredInteraction($edge)) {
                    $contractVerified++;
                }
            }
        }

        $agents  = self::collectDebateAgents($positions, $edges);
        $n       = count($agents);
        $possible = max(1, $n * max(0, $n - 1));
        $density  = round(min(1.0, $reliable / $possible), 4);

        $verificationRate = $contractTotal > 0
            ? round($contractVerified / $contractTotal, 4)
            : 0.0;

        return [
            'interaction_density'   => $density,
            'explicit_edge_count'   => $explicit,
            'fallback_edge_count'   => $fallback,
            'inferred_edge_count'   => $inferred,
            'unknown_edge_count'    => $unknown,
            'reliable_edge_count'   => $reliable,
            'possible_edge_count'   => $possible,
            'verified_interaction_count' => $verifiedStructured,
            'challenged_claim_count'     => $challengedClaim,
            'claim_challenged_count'     => $challengedClaim,
            'objection_count'            => $objectionCount,
            'concession_count'           => $concessionCount,
            'position_change_count'      => $positionChangeCount,
            'target_mismatch_count'      => $targetMismatchCount,
            'interaction_contract_total' => $contractTotal,
            'interaction_contract_verified' => $contractVerified,
            'interaction_contract_verification_rate' => $verificationRate,
        ];
    }

    /**
     * Edge qualifies for contract QA denominator when any structured contract field was parsed & stored.
     */
    public static function edgeHasParsedInteractionContract(array $edge): bool {
        if (trim((string)($edge['claim_challenged'] ?? '')) !== '') {
            return true;
        }
        if (trim((string)($edge['objection'] ?? '')) !== '') {
            return true;
        }
        if (trim((string)($edge['concession'] ?? '')) !== '') {
            return true;
        }
        return trim((string)($edge['position_change'] ?? '')) !== '';
    }

    /**
     * Structured interaction contract is "verified" only with explicit targeting,
     * sufficient confidence, and a stated challenged claim or objection (not inferred fallback).
     */
    public static function isVerifiedStructuredInteraction(array $edge): bool {
        $source = strtolower((string)($edge['edge_source'] ?? 'unknown'));
        $confidence = (float)($edge['edge_confidence'] ?? 0.5);
        if ($source !== 'explicit_target' || $confidence < 0.70) {
            return false;
        }
        $claim = trim((string)($edge['claim_challenged'] ?? ''));
        $objection = trim((string)($edge['objection'] ?? ''));
        return $claim !== '' || $objection !== '';
    }

    public static function isReliableInteractionEdge(array $edge): bool {
        $source = strtolower((string)($edge['edge_source'] ?? 'unknown'));
        $confidence = (float)($edge['edge_confidence'] ?? 0.5);
        if ($source === 'explicit_target') {
            return $confidence >= 0.70;
        }
        if ($source === 'inferred_mention') {
            return $confidence >= 0.60;
        }
        return false;
    }

    /**
     * @return array<string,true>
     */
    private static function collectDebateAgents(array $positions, array $edges): array {
        $agents = [];
        foreach ($positions as $position) {
            $id = trim((string)($position['agent_id'] ?? ''));
            if (self::countsAsDebateAgent($id)) {
                $agents[$id] = true;
            }
        }
        foreach ($edges as $edge) {
            foreach (['source_agent_id', 'target_agent_id'] as $key) {
                $id = trim((string)($edge[$key] ?? ''));
                if (self::countsAsDebateAgent($id)) {
                    $agents[$id] = true;
                }
            }
        }
        return $agents;
    }

    private static function countsAsDebateAgent(string $agentId): bool {
        $agentId = trim($agentId);
        return $agentId !== '' && !in_array($agentId, ['synthesizer', 'devil_advocate'], true);
    }
}

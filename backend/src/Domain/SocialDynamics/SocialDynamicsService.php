<?php
namespace Domain\SocialDynamics;

use Infrastructure\Persistence\AgentRelationshipRepository;

class SocialDynamicsService {
    private AgentRelationshipRepository $repo;

    /** @var list<string> */
    private const STRONG_NEGATIVE = [
        'strongly disagree', 'unsupported', 'flawed', 'risky', 'invalid', 'baseless',
        'reckless', 'absurd', 'flat out wrong', 'totally wrong', 'sans fondement',
        'sans fondements', 'invalide',
    ];

    /** @var list<string> */
    private const MILD_NEGATIVE = [
        'disagree', 'misaligned', 'concern', 'weak', 'questionable', 'doute',
        'pas convaincu', 'désaccord', 'je contredis',
    ];

    /** @var list<string> */
    private const POSITIVE = [
        'agree', 'support', 'align', 'aligned', 'convince', 'sound', 'solid',
        'd\'accord', 'aligné', 'soutien',
    ];

    public function __construct(?AgentRelationshipRepository $repo = null) {
        $this->repo = $repo ?? new AgentRelationshipRepository();
    }

    public function clearSession(string $sessionId): void {
        $this->repo->deleteBySession($sessionId);
    }

    /**
     * @param list<string> $participantIds
     * @param array<int,array<string,mixed>> $votes
     * @param array<int,array<string,mixed>> $positions
     */
    public function ingestAgentResponse(
        string $sessionId,
        int $roundIndex,
        string $sourceAgentId,
        string $content,
        ?string $targetFromRunner,
        array $participantIds,
        array $votes,
        array $positions
    ): void {
        if (in_array($sourceAgentId, ['synthesizer', 'devil_advocate'], true)) {
            return;
        }

        $events = $this->parseStructuredSections($content, $sourceAgentId, $participantIds);
        if (empty($events)) {
            $events = $this->inferFallback($content, $sourceAgentId, $targetFromRunner, $participantIds);
        }

        foreach ($events as $ev) {
            if (($ev['target_agent_id'] ?? null) === null && $ev['event_type'] !== RelationshipEvent::TYPE_NEUTRAL) {
                continue;
            }
            if (($ev['target_agent_id'] ?? '') === $sourceAgentId) {
                continue;
            }

            $this->repo->addEvent([
                'session_id'        => $sessionId,
                'round_index'       => $roundIndex,
                'source_agent_id'   => $sourceAgentId,
                'target_agent_id'   => $ev['target_agent_id'] ?? null,
                'event_type'        => $ev['event_type'],
                'intensity'         => $ev['intensity'],
                'evidence'          => $ev['evidence'] ?? null,
                'created_at'        => date('c'),
            ]);

            if (($ev['event_type'] ?? '') === RelationshipEvent::TYPE_NEUTRAL) {
                continue;
            }

            $tgt = $ev['target_agent_id'] ?? null;
            if ($tgt === null || $tgt === '') {
                continue;
            }

            $this->applyEventToPair($sessionId, $sourceAgentId, $tgt, (string)$ev['event_type'], (float)$ev['intensity']);
        }
    }

    /**
     * @return list<array{event_type:string,target_agent_id:?string,intensity:float,evidence:?string}>
     */
    private function parseStructuredSections(string $content, string $sourceAgentId, array $participantIds): array {
        $participantLower = array_map('strtolower', $participantIds);
        $out = [];

        if (preg_match_all(
            '/##\s*(Alignment|Opposition|Challenge|Alliance)\s*\R([\s\S]*?)(?=\R##\s|\z)/iu',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $kind   = mb_strtolower(trim($m[1]), 'UTF-8');
                $body   = trim($m[2]);
                $targets = $this->extractMentions($body, $sourceAgentId, $participantLower);
                if (empty($targets)) {
                    continue;
                }
                $intensity = $this->scoreIntensity($body);
                foreach ($targets as $tgt) {
                    if ($kind === 'alignment' || $kind === 'alliance') {
                        $type = $kind === 'alliance' ? RelationshipEvent::TYPE_ALLIANCE : RelationshipEvent::TYPE_SUPPORT;
                        $out[] = [
                            'event_type'       => $type,
                            'target_agent_id'=> $tgt,
                            'intensity'      => max(0.5, $intensity),
                            'evidence'       => $this->snippet($body),
                        ];
                    } elseif ($kind === 'opposition') {
                        $attack = $this->isStrongAttack($body);
                        $out[] = [
                            'event_type'      => $attack ? RelationshipEvent::TYPE_ATTACK : RelationshipEvent::TYPE_DISAGREEMENT,
                            'target_agent_id' => $tgt,
                            'intensity'       => $attack ? min(1.0, 0.55 + $intensity * 0.45) : max(0.45, $intensity),
                            'evidence'        => $this->snippet($body),
                        ];
                    } else { // challenge
                        $out[] = [
                            'event_type'      => RelationshipEvent::TYPE_CHALLENGE,
                            'target_agent_id' => $tgt,
                            'intensity'       => max(0.5, $intensity),
                            'evidence'        => $this->snippet($body),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $participantIds
     * @return list<array{event_type:string,target_agent_id:?string,intensity:float,evidence:?string}>
     */
    private function inferFallback(
        string $content,
        string $sourceAgentId,
        ?string $targetFromRunner,
        array $participantIds
    ): array {
        $participantLower = array_map('strtolower', $participantIds);
        $lc = mb_strtolower($content, 'UTF-8');

        $target = $targetFromRunner;
        if ($target === null && preg_match('/##\s*Target Agent\s*\R+\s*([a-z][a-z0-9-]*)/iu', $content, $m)) {
            $cand = strtolower(trim($m[1]));
            if (in_array($cand, $participantLower, true) && $cand !== strtolower($sourceAgentId)) {
                $target = $cand;
            }
        }

        if ($target === null) {
            $mentions = $this->extractMentions($content, $sourceAgentId, $participantLower);
            $target = $mentions[0] ?? null;
        }

        $intensity = $this->scoreIntensity($lc);
        $strongNeg = $this->isStrongAttack($lc);
        $hasPos = $this->hasAny($lc, self::POSITIVE);
        $hasNeg = $this->hasAny($lc, self::MILD_NEGATIVE) || $strongNeg;

        if ($target !== null && $target !== '' && strtolower($target) !== strtolower($sourceAgentId)) {
            if ($strongNeg || ($hasNeg && !$hasPos)) {
                return [[
                    'event_type'      => $strongNeg ? RelationshipEvent::TYPE_ATTACK : RelationshipEvent::TYPE_DISAGREEMENT,
                    'target_agent_id' => $target,
                    'intensity'       => max(0.5, min(1.0, $intensity)),
                    'evidence'        => $this->snippet($content),
                ]];
            }
            if ($hasPos && !$hasNeg) {
                return [[
                    'event_type'      => RelationshipEvent::TYPE_SUPPORT,
                    'target_agent_id' => $target,
                    'intensity'       => max(0.45, $intensity),
                    'evidence'        => $this->snippet($content),
                ]];
            }
            if (!$hasNeg && !$hasPos) {
                return [[
                    'event_type'      => RelationshipEvent::TYPE_NEUTRAL,
                    'target_agent_id' => $target,
                    'intensity'       => 0.35,
                    'evidence'        => null,
                ]];
            }
        }

        return [];
    }

    /**
     * @param array<int,string> $participantLower
     * @return list<string>
     */
    private function extractMentions(string $text, string $sourceAgentId, array $participantLower): array {
        if (!preg_match_all('/@([a-z][a-z0-9-]*)/iu', $text, $mm)) {
            return [];
        }
        $seen = [];
        $out = [];
        $src = strtolower($sourceAgentId);
        foreach ($mm[1] as $id) {
            $low = strtolower($id);
            if ($low === $src || !in_array($low, $participantLower, true)) {
                continue;
            }
            if (isset($seen[$low])) continue;
            $seen[$low] = true;
            $out[] = $low;
        }
        return $out;
    }

    private function snippet(string $text): string {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($t, 'UTF-8') > 240) {
            return mb_substr($t, 0, 239, 'UTF-8') . '…';
        }
        return $t;
    }

    /** @param list<string> $words */
    private function hasAny(string $haystack, array $words): bool {
        foreach ($words as $w) {
            if (str_contains($haystack, mb_strtolower($w, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }

    private function isStrongAttack(string $textLc): bool {
        return $this->hasAny($textLc, self::STRONG_NEGATIVE);
    }

    private function scoreIntensity(string $textLc): float {
        $score = 0.45;
        if ($this->isStrongAttack($textLc)) {
            $score += 0.35;
        } elseif ($this->hasAny($textLc, self::MILD_NEGATIVE)) {
            $score += 0.2;
        }
        if ($this->hasAny($textLc, self::POSITIVE)) {
            $score += 0.15;
        }
        return min(1.0, $score);
    }

    private function clampAffinity(float $v): float {
        return max(-1.0, min(1.0, $v));
    }

    private function clampTrust(float $v): float {
        return max(0.0, min(1.0, $v));
    }

    private function clampConflict(float $v): float {
        return max(0.0, min(1.0, $v));
    }

    private function applyEventToPair(
        string $sessionId,
        string $source,
        string $target,
        string $eventType,
        float $intensity
    ): void {
        $row = $this->repo->findRelationship($sessionId, $source, $target);
        $affinity = $row ? (float)($row['affinity'] ?? 0) : 0.0;
        $trust    = $row ? (float)($row['trust'] ?? 0.5) : 0.5;
        $conflict = $row ? (float)($row['conflict'] ?? 0) : 0.0;

        $supportC    = $row ? (int)($row['support_count'] ?? 0) : 0;
        $challengeC  = $row ? (int)($row['challenge_count'] ?? 0) : 0;
        $allianceC   = $row ? (int)($row['alliance_count'] ?? 0) : 0;
        $attackC     = $row ? (int)($row['attack_count'] ?? 0) : 0;
        $lastType    = $row['last_interaction_type'] ?? null;

        $repeatTension = in_array((string)$lastType, [
            RelationshipEvent::TYPE_CHALLENGE,
            RelationshipEvent::TYPE_ATTACK,
            RelationshipEvent::TYPE_DISAGREEMENT,
        ], true)
            && in_array($eventType, [
                RelationshipEvent::TYPE_CHALLENGE,
                RelationshipEvent::TYPE_ATTACK,
                RelationshipEvent::TYPE_DISAGREEMENT,
            ], true);

        switch ($eventType) {
            case RelationshipEvent::TYPE_SUPPORT:
                $affinity += 0.10 * min(1.0, 0.5 + $intensity * 0.5);
                $trust += 0.05;
                $supportC++;
                break;
            case RelationshipEvent::TYPE_DISAGREEMENT:
                $conflict += 0.15 * min(1.2, $intensity + 0.2);
                $affinity -= 0.05;
                $challengeC++;
                break;
            case RelationshipEvent::TYPE_ATTACK:
                $conflict += 0.25 * min(1.15, $intensity + 0.15);
                $trust -= 0.05;
                $attackC++;
                $challengeC++;
                break;
            case RelationshipEvent::TYPE_ALLIANCE:
                $affinity += 0.20 * min(1.1, $intensity + 0.1);
                $trust += 0.10;
                $allianceC++;
                break;
            case RelationshipEvent::TYPE_CHALLENGE:
                $conflict += (0.10 + $intensity * 0.05);
                $affinity -= 0.03;
                $challengeC++;
                break;
            case RelationshipEvent::TYPE_CONCESSION:
                $conflict *= 0.92;
                $affinity += 0.05;
                $trust += 0.06;
                break;
            default:
                return;
        }

        if ($repeatTension) {
            $conflict += min(0.15, (0.04 + ($intensity * 0.06)));
        }
        $trust = max(0.0, min(1.0, $trust));

        $this->repo->upsertRelationship([
            'session_id'          => $sessionId,
            'source_agent_id'     => $source,
            'target_agent_id'     => $target,
            'affinity'            => $this->clampAffinity($affinity),
            'trust'               => $this->clampTrust($trust),
            'conflict'            => $this->clampConflict($conflict),
            'support_count'       => $supportC,
            'challenge_count'     => $challengeC,
            'alliance_count'      => $allianceC,
            'attack_count'        => $attackC,
            'last_interaction_type' => substr($eventType, 0, 32),
        ]);
    }

    /** @param array<int,array<string,mixed>> $votes */

    /** @param array<int,array<string,mixed>> $positions */
    public static function summarizeMajority(array $votes, array $positions): array {
        $go = 0;
        $nogo = 0;
        $iter = 0;

        foreach ($votes as $v) {
            if (($v['agent_id'] ?? '') === 'devil_advocate') {
                continue;
            }
            $vv = strtolower((string)($v['vote'] ?? ''));
            if (str_contains($vv, 'no-go') || str_contains($vv, 'nogo')) {
                $nogo++;
            } elseif (str_contains($vv, 'go') && !str_contains($vv, 'no')) {
                $go++;
            } else {
                $iter++;
            }
        }

        if (($go + $nogo + $iter) === 0) {
            $latest = [];
            foreach ($positions as $p) {
                $ag = (string)($p['agent_id'] ?? '');
                if ($ag === '' || $ag === 'devil_advocate') continue;
                $r = (int)($p['round'] ?? 0);
                if (!isset($latest[$ag]) || $r >= (int)($latest[$ag]['round'] ?? 0)) {
                    $latest[$ag] = $p;
                }
            }
            foreach ($latest as $p) {
                $st = strtolower((string)($p['stance'] ?? ''));
                if (str_contains($st, 'oppose')) {
                    $nogo++;
                } elseif (str_contains($st, 'support')) {
                    $go++;
                } else {
                    $iter++;
                }
            }
        }

        return ['GO' => $go, 'NO-GO' => $nogo, 'ITERATE' => $iter];
    }
}

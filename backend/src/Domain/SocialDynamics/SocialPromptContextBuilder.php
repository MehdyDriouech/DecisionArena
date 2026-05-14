<?php
namespace Domain\SocialDynamics;

use Infrastructure\Persistence\AgentRelationshipRepository;

class SocialPromptContextBuilder {
    private AgentRelationshipRepository $repo;

    public function __construct(?AgentRelationshipRepository $repo = null) {
        $this->repo = $repo ?? new AgentRelationshipRepository();
    }

    /**
     * Builds an optional Markdown block for structured modes.
     *
     * @param array{GO:int,NO-GO:int,ITERATE:int} $majority
     */
    public function buildUserBlock(string $sessionId, string $agentId, array $majority): string {
        $summary = $this->repo->summarizeForPrompt($sessionId, $agentId);
        $lines = $summary['text_lines'] ?? [];

        $out = "## Social Dynamics Context\n\n";
        $out .= "Your current relationships in this session:\n";
        if (empty($lines)) {
            $out .= "- (No strong relational signals yet — still engage explicitly with prior contributions when present.)\n";
        } else {
            foreach ($lines as $line) {
                $out .= '- ' . $line . "\n";
            }
        }

        $out .= "\nCurrent majority position:\n";
        $out .= '- GO: ' . (int)($majority['GO'] ?? 0) . " agents\n";
        $out .= '- NO-GO: ' . (int)($majority['NO-GO'] ?? 0) . " agents\n";
        $out .= '- ITERATE: ' . (int)($majority['ITERATE'] ?? 0) . " agents\n";

        $out .= "\nInstruction:\n";
        $out .= "Use these relationships to shape your response.\n";
        $out .= "You may support allies, challenge opponents, or attempt to shift the majority.\n";
        $out .= "Do not invent personal hostility. Stay focused on the decision.\n";
        $out .= "**Be forceful, not toxic.** Attack reasoning, assumptions and evidence — never the person.\n";

        return $out . "\n";
    }

    /**
     * Aggregates for UI / API convenience.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array{alliances:array<int,string>,conflicts:array<int,string>,most_challenged:?string,most_supported:?string}
     */
    public static function computeHighlights(array $relationships): array {
        /** @var array<string,float> $challengeIn */
        $challengeIn = [];
        /** @var array<string,float> $supportIn */
        $supportIn = [];
        foreach ($relationships as $r) {
            $tgt = (string)($r['target_agent_id'] ?? '');
            if ($tgt === '') continue;
            $challengeIn[$tgt] = ($challengeIn[$tgt] ?? 0) + (float)($r['challenge_count'] ?? 0)
                + (float)($r['attack_count'] ?? 0);
            $supportIn[$tgt] = ($supportIn[$tgt] ?? 0) + (float)($r['support_count'] ?? 0)
                + (float)($r['alliance_count'] ?? 0) * 1.2;
        }
        arsort($challengeIn);
        arsort($supportIn);
        $mostChallenged = null;
        $mostSupported  = null;
        if (!empty($challengeIn)) {
            $mostChallenged = array_key_first($challengeIn);
        }
        if (!empty($supportIn)) {
            $mostSupported = array_key_first($supportIn);
        }

        /** @var array<string,float> $pairAlly */
        $pairAlly = [];
        /** @var array<string,float> $pairConf */
        $pairConf = [];

        foreach ($relationships as $r) {
            $src = (string)($r['source_agent_id'] ?? '');
            $tgt = (string)($r['target_agent_id'] ?? '');
            if ($src === '' || $tgt === '') continue;

            $sym = $src < $tgt ? "{$src}|{$tgt}" : "{$tgt}|{$src}";
            $conf = (float)($r['conflict'] ?? 0);
            if ($conf >= 0.3) {
                $pairConf[$sym] = max($pairConf[$sym] ?? 0, $conf);
            }
            $ally = (float)($r['affinity'] ?? 0) + ($r['alliance_count'] ?? 0) * 0.15;
            if ($ally >= 0.25) {
                $pairAlly[$sym] = max($pairAlly[$sym] ?? 0, $ally);
            }
        }
        arsort($pairConf);
        arsort($pairAlly);

        $conflicts = [];
        foreach (array_keys(array_slice($pairConf, 0, 5, true)) as $k) {
            [$a, $b] = explode('|', $k, 2);
            $conflicts[] = "{$a} → {$b}";
        }

        $alliances = [];
        foreach (array_keys(array_slice($pairAlly, 0, 5, true)) as $k) {
            [$a, $b] = explode('|', $k, 2);
            $alliances[] = "{$a} ↔ {$b}";
        }

        return [
            'alliances'        => $alliances,
            'conflicts'        => $conflicts,
            'most_challenged'  => $mostChallenged,
            'most_supported'   => $mostSupported,
        ];
    }
}

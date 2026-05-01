<?php
/**
 * CLI test — Jury Adversarial Mode (unit tests, no LLM calls)
 *
 * Usage (from backend/ folder):
 *   php tools/test_jury_adversarial.php
 *
 * Tests:
 *  A — normalizeAdversarialConfig (defaults + overrides)
 *  B — resolveJuryPhase (phases 1-N per round count)
 *  C — ensureAdversarialCompliance (cross-exam + defense checks)
 *  D — computeDebateQualityScore (challenge ratio, position changes, minority bonus)
 *  E — buildConstraintBlock (NO_CONSENSUS must force constraint text)
 *  F — identifyMinorityAgents (majority vs minority vote detection)
 *  G — computePositionChangers (first vs last stance comparison)
 *  H — resolveEdgeType improvements (new keywords, headers)
 *  I — computeAdversarialWarnings (weak quality → warnings)
 */

$base = __DIR__ . '/../src';

// Bootstrap — minimal requires (no Composer, no npm)
require_once $base . '/Infrastructure/Persistence/Database.php';
require_once $base . '/Infrastructure/Persistence/DebateRepository.php';
require_once $base . '/Infrastructure/Persistence/MessageRepository.php';
require_once $base . '/Infrastructure/Persistence/VoteRepository.php';
require_once $base . '/Infrastructure/Persistence/SnapshotRepository.php';
require_once $base . '/Infrastructure/Persistence/SessionRepository.php';
require_once $base . '/Infrastructure/Persistence/ContextDocumentRepository.php';
require_once $base . '/Infrastructure/Persistence/EvidenceRepository.php';
require_once $base . '/Infrastructure/Persistence/RiskProfileRepository.php';
require_once $base . '/Infrastructure/Logging/Logger.php';
require_once $base . '/Infrastructure/Markdown/FrontMatterParser.php';
require_once $base . '/Infrastructure/Markdown/MarkdownFileLoader.php';
require_once $base . '/Domain/Agents/Persona.php';
require_once $base . '/Domain/Agents/Agent.php';
require_once $base . '/Domain/Agents/AgentAssembler.php';
require_once $base . '/Domain/Orchestration/DebateMemoryService.php';
require_once $base . '/Domain/DecisionReliability/ReliabilityConfig.php';

// Stub out complex dependencies to avoid deep require chains
if (!class_exists('Domain\Providers\ProviderRouter')) {
    eval('namespace Domain\Providers; class ProviderRouter { public function chat(array $m, $a): array { return ["content" => "stub", "provider_id" => "stub", "provider_name" => "stub", "model" => "stub", "fallback_used" => false, "fallback_reason" => null, "requested_provider_id" => null, "requested_model" => null]; } }');
}
if (!class_exists('Domain\Vote\VoteParser')) {
    eval('namespace Domain\Vote; class VoteParser { public function parse(string $c): ?array { return null; } }');
}
if (!class_exists('Domain\Vote\VoteAggregator')) {
    eval('namespace Domain\Vote; class VoteAggregator { public function __construct($r = null) {} public function recompute(string $s, float $t = 0.55): ?array { return null; } }');
}
if (!class_exists('Domain\DecisionReliability\DecisionReliabilityService')) {
    eval('namespace Domain\DecisionReliability; class DecisionReliabilityService { public function buildEnvelope(...$args): array { return ["context_quality" => ["level" => "weak", "score" => 0.3], "raw_decision" => null, "adjusted_decision" => [], "reliability_cap" => 1.0, "false_consensus_risk" => "low", "false_consensus" => [], "reliability_warnings" => [], "decision_reliability_summary" => null, "context_clarification" => null, "risk_threshold_info" => null]; } }');
}
if (!class_exists('Domain\DecisionReliability\FalseConsensusDetector')) {
    eval('namespace Domain\DecisionReliability; class FalseConsensusDetector { public function detect(...$args): array { return ["false_consensus_risk" => "low"]; } public function shouldForceChallengeNextRound(...$a): bool { return false; } }');
}
if (!class_exists('Domain\SocialDynamics\SocialDynamicsService')) {
    eval('namespace Domain\SocialDynamics; class SocialDynamicsService { public function clearSession(string $s): void {} public function ingestAgentResponse(...$a): void {} public static function summarizeMajority(...$a): string { return ""; } }');
}
if (!class_exists('Domain\SocialDynamics\SocialPromptContextBuilder')) {
    eval('namespace Domain\SocialDynamics; class SocialPromptContextBuilder { public function buildUserBlock(...$a): string { return ""; } }');
}
if (!class_exists('Domain\Evidence\EvidenceReportService')) {
    eval('namespace Domain\Evidence; class EvidenceReportService { public function generateAndPersist(...$a): ?array { return null; } }');
}
if (!class_exists('Domain\Risk\RiskProfileAnalyzer')) {
    eval('namespace Domain\Risk; class RiskProfileAnalyzer { public function analyzeAndPersist(...$a): ?array { return null; } }');
}
if (!class_exists('Domain\Orchestration\RoundPolicy')) {
    eval('namespace Domain\Orchestration; class RoundPolicy { const ROUND_OPENING = "opening"; const ROUND_CHALLENGE = "challenge"; public function getRoundType(int $r, int $t): string { return "challenge"; } public function getRoundTypeDirective(string $t, bool $f = false): string { return "Be critical."; } }');
}

require_once $base . '/Domain/Orchestration/JuryRunner.php';

// ── Helpers ───────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_eq(string $label, $got, $expected): void {
    global $pass, $fail;
    if ($got === $expected) {
        echo "\033[32m✓\033[0m {$label}\n";
        $pass++;
    } else {
        echo "\033[31m✗\033[0m {$label}\n";
        echo "   expected: " . json_encode($expected) . "\n";
        echo "   got:      " . json_encode($got) . "\n";
        $fail++;
    }
}

function assert_contains(string $label, $haystack, $needle): void {
    global $pass, $fail;
    $found = is_string($haystack)
        ? str_contains($haystack, $needle)
        : in_array($needle, (array)$haystack, true);
    if ($found) {
        echo "\033[32m✓\033[0m {$label}\n";
        $pass++;
    } else {
        echo "\033[31m✗\033[0m {$label}\n";
        echo "   needle not found: " . json_encode($needle) . "\n";
        echo "   in: " . json_encode(is_string($haystack) ? mb_substr($haystack, 0, 300) : $haystack) . "\n";
        $fail++;
    }
}

function assert_not_contains(string $label, $haystack, $needle): void {
    global $pass, $fail;
    $found = is_string($haystack)
        ? str_contains($haystack, $needle)
        : in_array($needle, (array)$haystack, true);
    if (!$found) {
        echo "\033[32m✓\033[0m {$label}\n";
        $pass++;
    } else {
        echo "\033[31m✗\033[0m {$label}\n";
        echo "   unexpected needle found: " . json_encode($needle) . "\n";
        $fail++;
    }
}

function priv(object $obj, string $method, array $args = []) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}

$runner       = new Domain\Orchestration\JuryRunner();
$debateMemory = new Domain\Orchestration\DebateMemoryService();

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== A — normalizeAdversarialConfig ===\n";

$cfg = priv($runner, 'normalizeAdversarialConfig', [[]]);
assert_eq('defaults: enabled = true',                       $cfg['enabled'],                       true);
assert_eq('defaults: min_challenges_per_round = 2',         $cfg['min_challenges_per_round'],      2);
assert_eq('defaults: block_weak_debate_decision = true',    $cfg['block_weak_debate_decision'],    true);
assert_eq('defaults: debate_quality_min_score = 50',        $cfg['debate_quality_min_score'],      50);
assert_eq('defaults: require_minority_report = true',       $cfg['require_minority_report'],       true);
assert_eq('defaults: force_agent_references = true',        $cfg['force_agent_references'],        true);

$cfg2 = priv($runner, 'normalizeAdversarialConfig', [[
    'jury_adversarial_enabled'  => false,
    'min_challenges_per_round'  => 10,   // clamped to 5
    'debate_quality_min_score'  => 150,  // clamped to 100
]]);
assert_eq('override: enabled = false',                  $cfg2['enabled'],                  false);
assert_eq('override: min_challenges clamped to 5',      $cfg2['min_challenges_per_round'], 5);
assert_eq('override: quality_min_score clamped to 100', $cfg2['debate_quality_min_score'], 100);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== B — resolveJuryPhase ===\n";

$cfgOn  = priv($runner, 'normalizeAdversarialConfig', [[]]);
$cfgOff = priv($runner, 'normalizeAdversarialConfig', [['jury_adversarial_enabled' => false]]);

assert_eq('round 1 → jury-opening (adv on)',             priv($runner, 'resolveJuryPhase', [1, 4, $cfgOn]),  'jury-opening');
assert_eq('round 2 → jury-cross-examination (adv on)',   priv($runner, 'resolveJuryPhase', [2, 4, $cfgOn]),  'jury-cross-examination');
assert_eq('round 3, totalRounds=4 → jury-defense',       priv($runner, 'resolveJuryPhase', [3, 4, $cfgOn]),  'jury-defense');
assert_eq('round 4, totalRounds=5 → jury-deliberation',  priv($runner, 'resolveJuryPhase', [4, 5, $cfgOn]),  'jury-deliberation');
assert_eq('round 3, totalRounds=3 → jury-deliberation',  priv($runner, 'resolveJuryPhase', [3, 3, $cfgOn]),  'jury-deliberation');
assert_eq('round 1 → jury-opening (adv off)',            priv($runner, 'resolveJuryPhase', [1, 3, $cfgOff]), 'jury-opening');
assert_eq('round 2 → jury-cross-exam (adv off)',         priv($runner, 'resolveJuryPhase', [2, 3, $cfgOff]), 'jury-cross-examination');
assert_eq('round 3 → jury-deliberation (adv off)',       priv($runner, 'resolveJuryPhase', [3, 5, $cfgOff]), 'jury-deliberation');

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== C — ensureAdversarialCompliance ===\n";

$prevMessages = [
    ['agent_id' => 'pm',        'content' => 'We should launch now.'],
    ['agent_id' => 'architect', 'content' => 'Architecture is sound.'],
];
$cfg = priv($runner, 'normalizeAdversarialConfig', [[]]);

// ✅ Good cross-examination
$goodCrossExam = "## Target Agent\npm\n\n## Challenge\n- Claim challenged: PM said we should launch now\n- Why it is weak: Market data is missing\n- Evidence or assumption missing: Competitive analysis absent\n- What would change your mind: Show validated user research\n\nI disagree with pm on the timeline because no data supports this.\n\n## Vote\nno-go\n## Confidence\n7\n## Rationale\nNo evidence supports launch timeline";
$issue1 = priv($runner, 'ensureAdversarialCompliance', [$goodCrossExam, 'jury-cross-examination', 'critic', $prevMessages, $cfg]);
assert_eq('valid cross-exam → no compliance issue', $issue1, null);

// ❌ Missing ## Target Agent
$missingTarget = "I challenge pm's claim. The market data is not there. Missing evidence for assumptions.\n\n## Challenge\n- Claim: go\n- Weak: no data\n## Vote\nno-go\n## Confidence\n6\n## Rationale\nWeak argument without evidence provided to back up the claim at all";
$issue2 = priv($runner, 'ensureAdversarialCompliance', [$missingTarget, 'jury-cross-examination', 'critic', $prevMessages, $cfg]);
assert_contains('missing ## Target Agent → issue detected', $issue2 ?? '', 'missing ## Target Agent');

// ✅ Good defense (references 'pm' which is in prevMessages)
$goodDefense = "## Response To Challenges\n- Challenge acknowledged: pm challenged my risk assessment on market timing\n- Defense: My analysis is based on Q3 validated data from 3 studies\n- Revision: I refine scope to B2B only\n- Position changed: yes\n\nI maintain that the risks cited by pm can be mitigated with the given budget and timeline.\n\n## Vote\nreduce-scope\n## Confidence\n6\n## Rationale\nRevised after acknowledging the challenge from pm";
$issue3 = priv($runner, 'ensureAdversarialCompliance', [$goodDefense, 'jury-defense', 'architect', $prevMessages, $cfg]);
assert_eq('valid defense → no compliance issue', $issue3, null);

// ❌ Defense missing section
$badDefense = "I think the situation is nuanced. The challenges raised are interesting but my position remains correct. pm and architect need to reconsider the full context before voting.\n\n## Vote\ngo\n## Confidence\n5\n## Rationale\nStill confident in original assessment";
$issue4 = priv($runner, 'ensureAdversarialCompliance', [$badDefense, 'jury-defense', 'pm', $prevMessages, $cfg]);
assert_contains('missing defense section → issue detected', $issue4 ?? '', 'missing ## Response To Challenges');

// ❌ Too short
$tooShort = "## Target Agent\npm\n## Challenge\n- Ok\n## Vote\ngo";
$issue5 = priv($runner, 'ensureAdversarialCompliance', [$tooShort, 'jury-cross-examination', 'critic', $prevMessages, $cfg]);
assert_contains('too short → issue detected', $issue5 ?? '', 'too short');

// ✅ Adversarial disabled → always null
$cfgOff = priv($runner, 'normalizeAdversarialConfig', [['jury_adversarial_enabled' => false]]);
$issue6 = priv($runner, 'ensureAdversarialCompliance', [$missingTarget, 'jury-cross-examination', 'critic', $prevMessages, $cfgOff]);
assert_eq('adversarial disabled → always null', $issue6, null);

// ✅ Opening phase → no reference check (but must be long enough)
$openingMsg = "I believe we should proceed with caution. The market conditions are highly uncertain at this stage. We lack validated user research and competitive analysis. Before committing resources, we need to understand the true total addressable market and validate our core assumptions.\n\n## Vote\nneeds-more-info\n## Confidence\n5\n## Rationale\nInsufficient context to decide — need market validation data";
$issue7 = priv($runner, 'ensureAdversarialCompliance', [$openingMsg, 'jury-opening', 'pm', [], $cfg]);
assert_eq('opening phase → no issues (long enough, no prev messages)', $issue7, null);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== D — computeDebateQualityScore ===\n";

$state = [
    'edges' => [
        ['edge_type' => 'challenge', 'source_agent_id' => 'critic',    'target_agent_id' => 'pm'],
        ['edge_type' => 'challenge', 'source_agent_id' => 'architect', 'target_agent_id' => 'critic'],
        ['edge_type' => 'support',   'source_agent_id' => 'pm',        'target_agent_id' => 'architect'],
        ['edge_type' => 'neutral',   'source_agent_id' => 'critic',    'target_agent_id' => 'architect'],
    ],
    'positions' => [
        ['agent_id' => 'pm',        'round' => 1, 'stance' => 'support'],
        ['agent_id' => 'pm',        'round' => 3, 'stance' => 'reduce-scope'],  // changed
        ['agent_id' => 'architect', 'round' => 1, 'stance' => 'support'],
        ['agent_id' => 'architect', 'round' => 3, 'stance' => 'support'],       // unchanged
        ['agent_id' => 'critic',    'round' => 1, 'stance' => 'oppose'],
        ['agent_id' => 'critic',    'round' => 3, 'stance' => 'oppose'],         // unchanged
    ],
];

$q = priv($runner, 'computeDebateQualityScore', [$state, 3, false]);
assert_eq('challenge_count = 2',          $q['challenge_count'],           2);
assert_eq('challenge_ratio = 0.5',        $q['challenge_ratio'],           0.5);
assert_eq('position_changes = 1 (pm)',    $q['position_changes'],          1);
assert_eq('minority_report_present = F',  $q['minority_report_present'],   false);
assert_eq('challenge_score = 20',         $q['challenge_score'],           20);
assert_eq('position_score = 7',           $q['position_score'],            7);
assert_eq('minority_score = 0',           $q['minority_score'],            0);

$qM = priv($runner, 'computeDebateQualityScore', [$state, 3, true]);
assert_eq('with minority: minority_score = 20',     $qM['minority_score'],           20);
assert_eq('with minority: total higher',            $qM['score'] > $q['score'],      true);
assert_eq('with minority: minority_present = T',    $qM['minority_report_present'],  true);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== E — buildConstraintBlock (NO_CONSENSUS) ===\n";

$decNoConsensus = [
    'vote_summary'    => ['winning_label' => 'no-consensus', 'decision_scores' => ['no-consensus' => 0.4, 'go' => 0.35]],
    'decision_label'  => 'no-consensus',
    'confidence_level'=> 'low',
    'threshold_used'  => 0.55,
];
$decGo = [
    'vote_summary'    => ['winning_label' => 'go', 'decision_scores' => ['go' => 0.72]],
    'decision_label'  => 'go',
    'confidence_level'=> 'high',
    'threshold_used'  => 0.55,
];
$weakQuality = [
    'score'                   => 28,
    'challenge_ratio'         => 0.1,
    'position_changes'        => 0,
    'interaction_density'     => 0.2,
    'minority_report_present' => false,
];
$strongQuality = [
    'score'                   => 72,
    'challenge_ratio'         => 0.6,
    'position_changes'        => 2,
    'interaction_density'     => 0.7,
    'minority_report_present' => true,
];

$block1 = priv($runner, 'buildConstraintBlock', [$decNoConsensus, $weakQuality]);
assert_contains('NO_CONSENSUS block: contains "no-consensus"',          $block1, 'no-consensus');
assert_contains('NO_CONSENSUS block: MUST NOT claim GO',                $block1, 'MUST NOT claim a clear GO');
assert_contains('NO_CONSENSUS block: explicitly state failure',         $block1, 'explicitly state that the committee failed');
assert_contains('NO_CONSENSUS block: weak quality note',                $block1, 'debate quality was weak');
assert_contains('NO_CONSENSUS block: required structure header',        $block1, '## Final Jury Judgment');
assert_contains('NO_CONSENSUS block: must align with aggregated',       $block1, 'align your final recommendation');

$block2 = priv($runner, 'buildConstraintBlock', [$decGo, $strongQuality]);
assert_not_contains('GO block: no MUST NOT claim GO',                   $block2, 'MUST NOT claim a clear GO');
assert_not_contains('GO block: no weak quality note',                   $block2, 'debate quality was weak');
assert_contains('GO block: still has required structure',               $block2, '## Final Jury Judgment');

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== F — identifyMinorityAgents ===\n";

$votes = [
    ['agent_id' => 'pm',        'vote' => 'go',    'round' => 1],
    ['agent_id' => 'pm',        'vote' => 'go',    'round' => 2],
    ['agent_id' => 'architect', 'vote' => 'go',    'round' => 2],
    ['agent_id' => 'critic',    'vote' => 'no-go', 'round' => 2],
];
$debateAgents = ['pm', 'architect', 'critic'];
$minority = priv($runner, 'identifyMinorityAgents', [$votes, $debateAgents]);
assert_eq('critic voted no-go vs majority go → minority',    $minority, ['critic']);

$votes2 = [
    ['agent_id' => 'pm',        'vote' => 'go', 'round' => 1],
    ['agent_id' => 'architect', 'vote' => 'go', 'round' => 1],
    ['agent_id' => 'critic',    'vote' => 'go', 'round' => 1],
];
$minority2 = priv($runner, 'identifyMinorityAgents', [$votes2, $debateAgents]);
assert_eq('all same votes → no minority', $minority2, []);

$votes3 = [];
$minority3 = priv($runner, 'identifyMinorityAgents', [$votes3, $debateAgents]);
assert_eq('empty votes → no minority', $minority3, []);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== G — computePositionChangers ===\n";

$positions = [
    ['agent_id' => 'pm',          'round' => 1, 'stance' => 'support'],
    ['agent_id' => 'pm',          'round' => 3, 'stance' => 'reduce-scope'],
    ['agent_id' => 'architect',   'round' => 1, 'stance' => 'support'],
    ['agent_id' => 'architect',   'round' => 3, 'stance' => 'support'],
    ['agent_id' => 'synthesizer', 'round' => 1, 'stance' => 'needs-more-info'],
];
$changers = priv($runner, 'computePositionChangers', [$positions]);
assert_eq('pm changed position',             isset($changers['pm']),           true);
assert_eq('pm from = support',               $changers['pm']['from'] ?? '',    'support');
assert_eq('pm to = reduce-scope',            $changers['pm']['to'] ?? '',      'reduce-scope');
assert_eq('architect did not change',        isset($changers['architect']),    false);
assert_eq('synthesizer excluded',            isset($changers['synthesizer']),  false);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== H — resolveEdgeType improvements ===\n";

$et = fn(string $c) => priv($debateMemory, 'resolveEdgeType', [$c]);

assert_eq('## Challenge header → challenge',           $et("## Challenge\n- Claim: pm said go\n- Why weak: no data"), 'challenge');
assert_eq('## Minority Report header → challenge',     $et("## Minority Report\nI disagree with the majority."),    'challenge');
assert_eq('## Response To Challenges → defense',       $et("## Response To Challenges\n- Ack: critic's objection"), 'defense');
assert_eq('## Alliance header → support',              $et("## Alliance\nI align with architect's position."),      'support');
assert_eq('"weak assumption" keyword → challenge',     $et("There is a weak assumption in that argument."),         'challenge');
assert_eq('"missing evidence" keyword → challenge',    $et("The claim lacks missing evidence for market size."),    'challenge');
assert_eq('"unsupported claim" keyword → challenge',   $et("This is an unsupported claim without data."),           'challenge');
assert_eq('"i challenge" keyword → challenge',         $et("I challenge the validity of this position."),           'challenge');
assert_eq('"i disagree" keyword → challenge',          $et("I disagree with the previous assessment."),             'challenge');
assert_eq('"invalid assumption" keyword → challenge',  $et("This relies on an invalid assumption about demand."),   'challenge');
assert_eq('"agree" keyword → support',                 $et("I agree with the architect's analysis here."),          'support');
assert_eq('"concur" keyword → support',                $et("I concur with the previous assessment."),               'support');
assert_eq('neutral descriptive → neutral',             $et("The market has grown 15% in the past year."),           'neutral');
assert_eq('"je réfute" → challenge',                   $et("Je réfute cette hypothèse sur le marché."),             'challenge');
assert_eq('"en désaccord" → challenge',                $et("Je suis en désaccord avec cette affirmation."),         'challenge');

// ══════════════════════════════════════════════════════════════════════════════

echo "\n=== I — computeAdversarialWarnings ===\n";

$cfg = priv($runner, 'normalizeAdversarialConfig', [[]]);
$cfgOff = priv($runner, 'normalizeAdversarialConfig', [['jury_adversarial_enabled' => false]]);

$relHigh = ['false_consensus_risk' => 'high', 'reliability_warnings' => []];
$relLow  = ['false_consensus_risk' => 'low',  'reliability_warnings' => []];
$decNoC  = ['decision_label' => 'no-consensus'];
$decGo   = ['decision_label' => 'go'];
$weak    = ['score' => 28, 'challenge_ratio' => 0.1, 'challenge_count' => 1];
$strong  = ['score' => 75, 'challenge_ratio' => 0.6, 'challenge_count' => 8];

$w1 = priv($runner, 'computeAdversarialWarnings', [$weak, $cfg, $relHigh, $decNoC, [['a' => 1]]]);
assert_contains('weak quality → weak_debate_quality',          $w1, 'weak_debate_quality');
assert_contains('low challenge → insufficient_challenge',       $w1, 'insufficient_challenge');
assert_contains('retries → parallel_answers_detected',          $w1, 'parallel_answers_detected');
assert_contains('false_consensus high → warning',               $w1, 'false_consensus_risk_high');
assert_contains('no-consensus → no_consensus_reached',          $w1, 'no_consensus_reached');
assert_contains('synthesis_constrained always present',         $w1, 'synthesis_constrained_by_vote');

$w2 = priv($runner, 'computeAdversarialWarnings', [$strong, $cfg, $relLow, $decGo, []]);
assert_not_contains('strong quality → no weak_debate_quality',   $w2, 'weak_debate_quality');
assert_not_contains('no retries → no parallel_answers',          $w2, 'parallel_answers_detected');
assert_not_contains('low fc risk → no fc warning',               $w2, 'false_consensus_risk_high');
assert_contains('synthesis_constrained always present',          $w2, 'synthesis_constrained_by_vote');

$w3 = priv($runner, 'computeAdversarialWarnings', [$weak, $cfgOff, $relHigh, $decNoC, []]);
assert_eq('adversarial disabled → empty warnings', $w3, []);

// ══════════════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat('─', 50) . "\n";
$total = $pass + $fail;
echo "Résultats : {$pass}/{$total} passés";
if ($fail > 0) {
    echo " \033[31m({$fail} échoués)\033[0m\n";
} else {
    echo " \033[32m— tous OK\033[0m\n";
}

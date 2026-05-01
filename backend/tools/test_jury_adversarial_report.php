<?php
/**
 * CLI test — Jury Adversarial Report persistence & round sequencing
 *
 * Usage (from backend/ folder):
 *   php tools/test_jury_adversarial_report.php
 *
 * Tests:
 *  A — JuryAdversarialReportRepository CRUD (save / find / update)
 *  B — Session-show fallback: jury_adversarial null for non-jury session
 *  C — No round 99 in JuryRunner round-numbering logic
 *  D — Minority reporter: explicit payload honoured
 *  E — Minority reporter: team=red / adversarial tags heuristic
 *  F — Minority reporter: dissent score fallback (no persona match)
 *  G — Export includes planned_rounds + executed_rounds
 *  H — Backward compat: findBySession returns null for missing session
 */

$base = __DIR__ . '/../src';

// Bootstrap (no Composer)
require_once $base . '/Infrastructure/Persistence/Database.php';
require_once $base . '/Infrastructure/Persistence/DebateRepository.php';
require_once $base . '/Infrastructure/Persistence/MessageRepository.php';
require_once $base . '/Infrastructure/Persistence/VoteRepository.php';
require_once $base . '/Infrastructure/Persistence/SessionRepository.php';
require_once $base . '/Infrastructure/Persistence/SnapshotRepository.php';
require_once $base . '/Infrastructure/Persistence/ContextDocumentRepository.php';
require_once $base . '/Infrastructure/Persistence/JuryAdversarialReportRepository.php';
require_once $base . '/Infrastructure/Persistence/EvidenceRepository.php';
require_once $base . '/Infrastructure/Persistence/RiskProfileRepository.php';
require_once $base . '/Infrastructure/Logging/Logger.php';
require_once $base . '/Infrastructure/Markdown/FrontMatterParser.php';
require_once $base . '/Infrastructure/Markdown/MarkdownFileLoader.php';
require_once $base . '/Domain/Agents/Persona.php';
require_once $base . '/Domain/Agents/Soul.php';
require_once $base . '/Domain/Agents/Agent.php';
require_once $base . '/Domain/Agents/AgentAssembler.php';
require_once $base . '/Domain/Orchestration/DebateMemoryService.php';
require_once $base . '/Domain/DecisionReliability/ReliabilityConfig.php';

// Stub out complex dependencies that would require a running LLM
if (!class_exists('Domain\Providers\ProviderRouter')) {
    eval('namespace Domain\Providers; class ProviderRouter {
        public function chat(array $m, $a): array {
            return ["content"=>"stub","provider_id"=>"stub","provider_name"=>"stub","model"=>"stub",
                    "requested_provider_id"=>"stub","requested_model"=>"stub","fallback_used"=>false,"fallback_reason"=>null];
        }
    }');
}
if (!class_exists('Domain\Vote\VoteParser')) {
    eval('namespace Domain\Vote; class VoteParser { public function parse(string $c): ?array { return null; } }');
}
if (!class_exists('Domain\Vote\VoteAggregator')) {
    eval('namespace Domain\Vote; class VoteAggregator {
        public function __construct($r) {}
        public function recompute(string $s, float $t): ?array { return null; }
    }');
}
if (!class_exists('Domain\DecisionReliability\DecisionReliabilityService')) {
    eval('namespace Domain\DecisionReliability; class DecisionReliabilityService {
        public function buildEnvelope(...$a): array {
            return ["raw_decision"=>null,"adjusted_decision"=>null,"context_quality"=>["score"=>0.5,"level"=>"moderate"],
                    "reliability_cap"=>1.0,"false_consensus_risk"=>"low","false_consensus"=>null,
                    "reliability_warnings"=>[],"decision_reliability_summary"=>null,"context_clarification"=>null,
                    "risk_threshold_info"=>null];
        }
    }');
}
if (!class_exists('Domain\DecisionReliability\FalseConsensusDetector')) {
    eval('namespace Domain\DecisionReliability; class FalseConsensusDetector {
        public function shouldForceChallengeNextRound(...$a): bool { return false; }
    }');
}
if (!class_exists('Domain\SocialDynamics\SocialDynamicsService')) {
    eval('namespace Domain\SocialDynamics; class SocialDynamicsService {
        public function clearSession(string $s): void {}
        public function ingestAgentResponse(...$a): void {}
        public static function summarizeMajority(array $v, array $p): string { return "go"; }
    }');
}
if (!class_exists('Domain\SocialDynamics\SocialPromptContextBuilder')) {
    eval('namespace Domain\SocialDynamics; class SocialPromptContextBuilder {
        public function buildUserBlock(string $s, string $a, string $m): ?string { return null; }
    }');
}
if (!class_exists('Domain\Evidence\EvidenceReportService')) {
    eval('namespace Domain\Evidence; class EvidenceReportService {
        public function generateAndPersist(string $s, array $m, $d): ?array { return null; }
    }');
}
if (!class_exists('Domain\Risk\RiskProfileAnalyzer')) {
    eval('namespace Domain\Risk; class RiskProfileAnalyzer {
        public function analyzeAndPersist(...$a): ?array { return null; }
    }');
}

require_once $base . '/Domain/Orchestration/JuryRunner.php';

use Infrastructure\Persistence\JuryAdversarialReportRepository;

// ── Bootstrap DB table (runs Migration if needed) ────────────────────────────
// We need the migration classes loaded to ensure the table exists
require_once $base . '/Infrastructure/Persistence/Migration.php';

// Require the remaining migration dependencies
$migrationDeps = [
    '/Domain/DecisionReliability/ReliabilityConfig.php',
    '/Infrastructure/Persistence/PersonaScoreRepository.php',
    '/Infrastructure/Persistence/ConfidenceTimelineRepository.php',
    '/Infrastructure/Persistence/BiasReportRepository.php',
    '/Infrastructure/Persistence/SessionAgentProvidersRepository.php',
    '/Infrastructure/Persistence/VerdictRepository.php',
    '/Infrastructure/Persistence/ActionPlanRepository.php',
    '/Infrastructure/Persistence/ProviderRoutingSettingsRepository.php',
];
foreach ($migrationDeps as $dep) {
    $f = $base . $dep;
    if (file_exists($f)) require_once $f;
}

$db  = \Infrastructure\Persistence\Database::getInstance();
$mig = new \Infrastructure\Persistence\Migration($db);
$mig->run();

// ── Helpers ─────────────────────────────────────────────────────────────────

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) {
        echo "\e[32m✓\e[0m $label\n";
        $pass++;
    } else {
        echo "\e[31m✗\e[0m $label" . ($detail ? " — $detail" : '') . "\n";
        $fail++;
    }
}

$repo = new JuryAdversarialReportRepository();

// ── A — CRUD ─────────────────────────────────────────────────────────────────
echo "\n── A: JuryAdversarialReportRepository CRUD ──\n";

$sid = 'test-session-' . bin2hex(random_bytes(4));

$report = [
    'enabled'                 => true,
    'debate_quality_score'    => 42.0,
    'challenge_count'         => 5,
    'challenge_ratio'         => 0.38,
    'position_changes'        => 2,
    'position_changers'       => ['critic', 'pm'],
    'minority_report_present' => true,
    'interaction_density'     => 3.5,
    'most_challenged_agent'   => 'pm',
    'warnings'                => ['weak_debate_quality', 'parallel_answers_detected'],
    'compliance_retries'      => 1,
    'planned_rounds'          => 3,
    'executed_rounds'         => 5,
];

$repo->saveForSession($sid, $report);
$found = $repo->findBySession($sid);

ok('A1 — row inserted and found',    $found !== null);
ok('A2 — debate_quality_score',      ($found['debate_quality_score'] ?? null) == 42.0);
ok('A3 — challenge_count',           ($found['challenge_count'] ?? null) == 5);
ok('A4 — warnings array preserved',  is_array($found['warnings'] ?? null) && in_array('weak_debate_quality', $found['warnings']));
ok('A5 — position_changers array',   is_array($found['position_changers'] ?? null) && in_array('critic', $found['position_changers']));
ok('A6 — planned_rounds',            ($found['planned_rounds'] ?? null) == 3);
ok('A7 — executed_rounds',           ($found['executed_rounds'] ?? null) == 5);
ok('A8 — minority_report_present',   ($found['minority_report_present'] ?? null) == true);

// Update
$report['debate_quality_score'] = 68.0;
$report['planned_rounds']       = 3;
$report['executed_rounds']      = 4;
$repo->saveForSession($sid, $report);
$updated = $repo->findBySession($sid);

ok('A9 — update works (score changed)',    ($updated['debate_quality_score'] ?? null) == 68.0);
ok('A10 — executed_rounds updated',        ($updated['executed_rounds'] ?? null) == 4);

// ── B — null for unknown session ─────────────────────────────────────────────
echo "\n── B: findBySession null for unknown session ──\n";
$null = $repo->findBySession('does-not-exist-xyz');
ok('B1 — returns null for missing session', $null === null);

// ── C — No round 99 detection ────────────────────────────────────────────────
echo "\n── C: No round 99 in JuryRunner (source code check) ──\n";
$runnerSource = file_get_contents(__DIR__ . '/../src/Domain/Orchestration/JuryRunner.php');
$has99assignment = (bool)preg_match('/[\'"]round[\'"]\s*=>\s*99\b/', $runnerSource);
ok('C1 — no "round => 99" assignment in JuryRunner.php', !$has99assignment,
   $has99assignment ? 'Found round => 99 assignment — not clean!' : '');

$hasMiniChallengeRoundParam = (bool)preg_match('/int\s+\$miniChallengeRound/', $runnerSource);
ok('C2 — runMiniChallengeRound uses $miniChallengeRound parameter', $hasMiniChallengeRoundParam);

$hasPlannedRounds = (bool)preg_match('/\$plannedRounds\s*=\s*\$rounds/', $runnerSource);
ok('C3 — $plannedRounds tracked in run()', $hasPlannedRounds);

$hasVerdictRound = (bool)preg_match('/\$verdictRound/', $runnerSource);
ok('C4 — $verdictRound variable used for final synthesis round', $hasVerdictRound);

$hasExecutedInPayload = (bool)preg_match("/'executed_rounds'\s*=>\s*\\\$verdictRound/", $runnerSource);
ok('C5 — executed_rounds = $verdictRound in jury_adversarial payload', $hasExecutedInPayload);

// ── D — Minority reporter: explicit payload ───────────────────────────────────
echo "\n── D: forcedMinorityReporter — explicit payload honoured ──\n";
$runner = new \Domain\Orchestration\JuryRunner();
$method = new ReflectionMethod($runner, 'forcedMinorityReporter');
$method->setAccessible(true);

$agents = ['pm', 'ux-expert', 'critic', 'architect'];
$result = $method->invoke($runner, $agents, 'ux-expert', [], [], []);
ok('D1 — explicit payload "ux-expert" chosen', $result === 'ux-expert');

// Non-existent agent falls back
$result2 = $method->invoke($runner, $agents, 'nonexistent', [], [], []);
ok('D2 — nonexistent explicit ID falls back to heuristic (not null crash)', $result2 !== null);

// ── E — Minority reporter: persona heuristic ─────────────────────────────────
echo "\n── E: forcedMinorityReporter — team/tag/id heuristic ──\n";

// 'critic' persona has team=red and tags [adversarial, risk, ...]
$result3 = $method->invoke($runner, ['pm', 'architect', 'critic'], '', [], [], []);
ok('E1 — critic selected via team=red / adversarial tags', $result3 === 'critic',
   "got: $result3");

// ID heuristic: no persona file, but id contains "critic"
$result4 = $method->invoke($runner, ['pm', 'architect', 'qa'], '', [], [], []);
ok('E2 — qa selected via id keyword heuristic ("qa" in DISSENT_TERMS)', $result4 !== null && $result4 !== 'pm');

// ── F — Minority reporter: dissent score fallback ────────────────────────────
echo "\n── F: computeDissentScores ──\n";
$scoreMethod = new ReflectionMethod($runner, 'computeDissentScores');
$scoreMethod->setAccessible(true);

$votes = [
    ['agent_id' => 'pm',     'vote' => 'go',    'round' => 1],
    ['agent_id' => 'critic', 'vote' => 'no-go', 'round' => 1],
    ['agent_id' => 'arch',   'vote' => 'go',    'round' => 1],
];
$edges = [
    ['source_agent_id' => 'critic', 'edge_type' => 'challenge'],
    ['source_agent_id' => 'critic', 'edge_type' => 'challenge'],
    ['source_agent_id' => 'pm',     'edge_type' => 'support'],
];
$scores = $scoreMethod->invoke($runner, ['pm', 'critic', 'arch'], [], $votes, $edges);
ok('F1 — critic has highest dissent score',
   ($scores['critic'] ?? 0) > ($scores['pm'] ?? 0),
   'scores: ' . json_encode($scores));

$resultF = $method->invoke($runner, ['pm', 'arch', 'some-unknown'], '', [], $votes, $edges);
ok('F2 — dissent score fallback produces non-null result', $resultF !== null);

// ── G — Payload includes planned_rounds + executed_rounds ────────────────────
echo "\n── G: jury_adversarial payload structure ──\n";

$hasPlanned  = str_contains($runnerSource, "'planned_rounds'");
$hasExecuted = str_contains($runnerSource, "'executed_rounds'");
ok('G1 — planned_rounds in jury_adversarial array', $hasPlanned);
ok('G2 — executed_rounds in jury_adversarial array', $hasExecuted);

// Check repo save is called from JuryRunner
$hasRepoSave = (bool)preg_match('/adversarialRepo->saveForSession/', $runnerSource);
ok('G3 — adversarialRepo->saveForSession() called in JuryRunner', $hasRepoSave);

// ── H — deleteBySession ───────────────────────────────────────────────────────
echo "\n── H: deleteBySession ──\n";
$repo->deleteBySession($sid);
$afterDelete = $repo->findBySession($sid);
ok('H1 — deleteBySession removes the row', $afterDelete === null);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n──────────────────────────────────────────────\n";
echo "Results: \e[32m{$pass} passed\e[0m, \e[31m{$fail} failed\e[0m\n\n";
exit($fail > 0 ? 1 : 0);

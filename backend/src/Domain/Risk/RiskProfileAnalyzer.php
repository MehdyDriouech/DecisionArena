<?php

declare(strict_types=1);

namespace Domain\Risk;

use Infrastructure\Persistence\RiskProfileRepository;

/**
 * Main orchestrator for risk analysis — heuristic, V1 (no LLM required).
 *
 * Uses:
 *   - session objective
 *   - session mode
 *   - context document
 *   - agent messages
 *   - evidence report (optional)
 *   - context quality (optional)
 */
class RiskProfileAnalyzer
{
    private ReversibilityAssessmentService $reversibility;
    private RiskAdjustedThresholdService   $thresholdService;
    private RiskProfileRepository          $repo;

    public function __construct()
    {
        $this->reversibility    = new ReversibilityAssessmentService();
        $this->thresholdService = new RiskAdjustedThresholdService();
        $this->repo             = new RiskProfileRepository();
    }

    // ── Keyword maps per category ─────────────────────────────────────────────

    private const LEGAL_SIGNALS = [
        'legal', 'legally', 'contract', 'compliance', 'regulatory', 'regulation',
        'gdpr', 'lawsuit', 'liability', 'law', 'patent', 'trademark', 'copyright',
        'terms of service', 'binding', 'jurisdiction', 'litigation', 'attorney',
        'fine', 'penalty', 'audit', 'violation', 'breach',
    ];

    private const FINANCIAL_SIGNALS = [
        'million', 'billion', 'budget', 'revenue', 'cost', 'investment',
        'funding', 'profit', 'margin', 'cash flow', 'valuation', 'investor',
        'financial', 'balance sheet', 'roi', 'capex', 'opex', 'expense',
        'price', 'pricing', 'salary', 'payroll', 'debt', 'equity',
    ];

    private const REPUTATION_SIGNALS = [
        'reputation', 'brand', 'public', 'media', 'press', 'stakeholder',
        'customer trust', 'user trust', 'PR', 'announcement', 'communication',
        'transparency', 'perception', 'image', 'controversy', 'backlash',
        'social media', 'viral', 'crisis',
    ];

    private const TECHNICAL_SIGNALS = [
        'production', 'deploy', 'deployment', 'migration', 'infrastructure',
        'security', 'data breach', 'vulnerability', 'exploit', 'encryption',
        'database', 'architecture', 'server', 'cloud', 'scaling', 'performance',
        'downtime', 'outage', 'disaster recovery', 'backup',
    ];

    private const OPERATIONAL_SIGNALS = [
        'process', 'workflow', 'team', 'organisation', 'organization',
        'headcount', 'restructure', 'reorg', 'role', 'responsibility',
        'operational', 'logistics', 'vendor', 'supplier', 'partner',
    ];

    // ── High/critical escalation triggers (subset of above) ─────────────────

    private const CRITICAL_TRIGGERS = [
        'legal', 'contract', 'lawsuit', 'liability', 'compliance', 'regulatory',
        'security', 'data breach', 'irreversible', 'binding', 'litigation',
        'fine', 'penalty', 'gdpr', 'violation', 'bankruptcy', 'acquisition',
        'ipo', 'merger',
    ];

    private const HIGH_TRIGGERS = [
        'million', 'billion', 'production', 'deploy', 'migration', 'brand',
        'reputation', 'press', 'announcement', 'strategic', 'enterprise',
        'regulation', 'audit', 'outage', 'disaster', 'breach', 'client',
        'investor', 'funding', 'launch', 'go live',
    ];

    private const LOW_SIGNALS = [
        'test', 'experiment', 'pilot', 'prototype', 'a/b test', 'feature flag',
        'draft', 'preview', 'sandbox', 'staging', 'undo', 'rollback',
        'button', 'colour', 'color', 'typo', 'label', 'wording', 'copy',
        'tooltip', 'icon', 'style',
    ];

    /**
     * Full analysis pipeline: analyze → persist → return report array.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed>|null       $contextDoc
     * @param array<string,mixed>|null       $evidenceReport
     * @param array<string,mixed>|null       $contextQuality
     * @return array<string,mixed>
     */
    public function analyzeAndPersist(
        string  $sessionId,
        string  $objective,
        string  $mode,
        array   $messages,
        ?array  $contextDoc,
        float   $configuredThreshold,
        ?array  $evidenceReport  = null,
        ?array  $contextQuality  = null
    ): array {
        $profile = $this->analyze(
            $objective, $mode, $messages, $contextDoc,
            $configuredThreshold, $evidenceReport, $contextQuality
        );
        $data = $profile->toArray();
        $this->repo->save($sessionId, $data);
        return $data;
    }

    public function analyze(
        string  $objective,
        string  $mode,
        array   $messages,
        ?array  $contextDoc,
        float   $configuredThreshold,
        ?array  $evidenceReport  = null,
        ?array  $contextQuality  = null
    ): DecisionRiskProfile {
        $ctxText    = (string)($contextDoc['content'] ?? '');
        $combined   = mb_strtolower($objective . ' ' . $ctxText, 'UTF-8');

        // Collect text from agent messages (truncated)
        $agentText = '';
        foreach (array_slice($messages, 0, 20) as $m) {
            if (($m['role'] ?? '') === 'assistant') {
                $agentText .= ' ' . mb_substr((string)($m['content'] ?? ''), 0, 500, 'UTF-8');
            }
        }
        $allText = mb_strtolower($combined . ' ' . $agentText, 'UTF-8');

        // Detect categories
        $categories = $this->detectCategories($allText);

        // Determine risk level
        $riskLevel = $this->computeRiskLevel($allText, $categories, $mode, $evidenceReport);

        // Reversibility
        $reversibility = $this->reversibility->assess($objective, $ctxText);

        // Escalate risk if very hard to reverse
        if ($reversibility === DecisionRiskProfile::REV_IRREVERSIBLE && $riskLevel === DecisionRiskProfile::LEVEL_MEDIUM) {
            $riskLevel = DecisionRiskProfile::LEVEL_HIGH;
        }
        if ($reversibility === DecisionRiskProfile::REV_IRREVERSIBLE && $riskLevel === DecisionRiskProfile::LEVEL_LOW) {
            $riskLevel = DecisionRiskProfile::LEVEL_MEDIUM;
        }

        // Estimated error cost
        $errorCost = $this->estimateErrorCost($riskLevel, $categories);

        // Threshold
        $thresholdInfo = $this->thresholdService->compute($riskLevel, $configuredThreshold);
        $recommendedThreshold = $thresholdInfo['risk_adjusted_threshold'];

        // Required process
        $process = match ($riskLevel) {
            DecisionRiskProfile::LEVEL_LOW      => DecisionRiskProfile::PROCESS_QUICK,
            DecisionRiskProfile::LEVEL_MEDIUM   => DecisionRiskProfile::PROCESS_STANDARD,
            DecisionRiskProfile::LEVEL_HIGH,
            DecisionRiskProfile::LEVEL_CRITICAL => DecisionRiskProfile::PROCESS_STRICT,
            default                             => DecisionRiskProfile::PROCESS_STANDARD,
        };

        // Recommendations
        $recs = $this->buildRecommendations($riskLevel, $reversibility, $categories, $mode, $thresholdInfo);

        return new DecisionRiskProfile(
            $riskLevel,
            $reversibility,
            $categories,
            $errorCost,
            $recommendedThreshold,
            $process,
            $recs
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return list<string> */
    private function detectCategories(string $allText): array
    {
        $cats = [];

        $check = function (array $signals, string $category) use ($allText, &$cats): void {
            foreach ($signals as $sig) {
                if (str_contains($allText, $sig)) {
                    $cats[] = $category;
                    return;
                }
            }
        };

        $check(self::LEGAL_SIGNALS,       DecisionRiskProfile::CAT_LEGAL);
        $check(self::FINANCIAL_SIGNALS,   DecisionRiskProfile::CAT_FINANCIAL);
        $check(self::REPUTATION_SIGNALS,  DecisionRiskProfile::CAT_REPUTATION);
        $check(self::TECHNICAL_SIGNALS,   DecisionRiskProfile::CAT_TECHNICAL);
        $check(self::OPERATIONAL_SIGNALS, DecisionRiskProfile::CAT_OPERATIONAL);

        return $cats;
    }

    private function computeRiskLevel(
        string $allText,
        array  $categories,
        string $mode,
        ?array $evidenceReport
    ): string {
        // Explicit low signals
        foreach (self::LOW_SIGNALS as $sig) {
            if (str_contains($allText, $sig)) {
                return DecisionRiskProfile::LEVEL_LOW;
            }
        }

        // Critical triggers
        foreach (self::CRITICAL_TRIGGERS as $sig) {
            if (str_contains($allText, $sig)) {
                return DecisionRiskProfile::LEVEL_CRITICAL;
            }
        }

        // High triggers
        foreach (self::HIGH_TRIGGERS as $sig) {
            if (str_contains($allText, $sig)) {
                return DecisionRiskProfile::LEVEL_HIGH;
            }
        }

        // Multiple categories → escalate
        $catCount = count($categories);
        if ($catCount >= 3) {
            return DecisionRiskProfile::LEVEL_HIGH;
        }
        if ($catCount >= 2) {
            return DecisionRiskProfile::LEVEL_MEDIUM;
        }

        // Evidence gaps escalate
        if ($evidenceReport !== null) {
            $impact = (string)($evidenceReport['decision_impact'] ?? 'low');
            if ($impact === 'high') {
                return DecisionRiskProfile::LEVEL_HIGH;
            }
            if ($impact === 'medium') {
                return DecisionRiskProfile::LEVEL_MEDIUM;
            }
        }

        // Mode heuristic
        if (in_array($mode, ['jury', 'stress-test'], true)) {
            return DecisionRiskProfile::LEVEL_HIGH;
        }

        return DecisionRiskProfile::LEVEL_MEDIUM;
    }

    private function estimateErrorCost(string $riskLevel, array $categories): string
    {
        if (in_array(DecisionRiskProfile::CAT_FINANCIAL, $categories, true)
            || in_array(DecisionRiskProfile::CAT_LEGAL, $categories, true)) {
            return match ($riskLevel) {
                DecisionRiskProfile::LEVEL_CRITICAL => 'high',
                DecisionRiskProfile::LEVEL_HIGH     => 'high',
                DecisionRiskProfile::LEVEL_MEDIUM   => 'medium',
                default                             => 'low',
            };
        }
        return match ($riskLevel) {
            DecisionRiskProfile::LEVEL_CRITICAL => 'high',
            DecisionRiskProfile::LEVEL_HIGH     => 'medium',
            DecisionRiskProfile::LEVEL_MEDIUM   => 'low',
            default                             => 'low',
        };
    }

    /** @return list<string> */
    private function buildRecommendations(
        string $riskLevel,
        string $reversibility,
        array  $categories,
        string $mode,
        array  $thresholdInfo
    ): array {
        $recs = [];

        if ($riskLevel === DecisionRiskProfile::LEVEL_CRITICAL) {
            $recs[] = 'This is a critical-risk decision. Involve legal, financial and executive stakeholders before proceeding.';
        }
        if ($riskLevel === DecisionRiskProfile::LEVEL_HIGH) {
            $recs[] = 'High-risk decision detected. Require explicit sign-off from a domain expert before execution.';
        }
        if ($reversibility === DecisionRiskProfile::REV_IRREVERSIBLE) {
            $recs[] = 'This decision cannot easily be reversed. Run a structured debate (Confrontation or Jury mode) before finalising.';
        }
        if ($reversibility === DecisionRiskProfile::REV_HARD) {
            $recs[] = 'This decision is hard to reverse. Ensure a mitigation or rollback plan exists.';
        }
        if (in_array(DecisionRiskProfile::CAT_LEGAL, $categories, true)) {
            $recs[] = 'Legal implications detected. A qualified legal review is strongly recommended.';
        }
        if (in_array(DecisionRiskProfile::CAT_FINANCIAL, $categories, true) && $riskLevel !== DecisionRiskProfile::LEVEL_LOW) {
            $recs[] = 'Significant financial impact. Validate cost estimates against supporting data.';
        }
        if ($mode === 'quick-decision'
            && in_array($riskLevel, [DecisionRiskProfile::LEVEL_HIGH, DecisionRiskProfile::LEVEL_CRITICAL], true)) {
            $recs[] = 'Quick Decision is not recommended for this risk level. Use Decision Room or Jury mode instead.';
        }
        if ($thresholdInfo['was_adjusted']) {
            $adj = round($thresholdInfo['risk_adjusted_threshold'] * 100);
            $recs[] = "Risk-adjusted consensus threshold: {$adj}%. A higher majority is required before this decision can be marked CONFIDENT.";
        }

        return $recs;
    }
}

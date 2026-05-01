<?php

declare(strict_types=1);

namespace Domain\Risk;

/**
 * Immutable value object carrying a fully-resolved risk profile.
 */
final class DecisionRiskProfile
{
    // ── Risk levels ──────────────────────────────────────────────────────────
    public const LEVEL_LOW      = 'low';
    public const LEVEL_MEDIUM   = 'medium';
    public const LEVEL_HIGH     = 'high';
    public const LEVEL_CRITICAL = 'critical';

    // ── Reversibility ────────────────────────────────────────────────────────
    public const REV_EASY         = 'easy';
    public const REV_MODERATE     = 'moderate';
    public const REV_HARD         = 'hard';
    public const REV_IRREVERSIBLE = 'irreversible';

    // ── Risk categories ──────────────────────────────────────────────────────
    public const CAT_FINANCIAL   = 'financial';
    public const CAT_LEGAL       = 'legal';
    public const CAT_REPUTATION  = 'reputation';
    public const CAT_TECHNICAL   = 'technical';
    public const CAT_OPERATIONAL = 'operational';

    // ── Required processes ───────────────────────────────────────────────────
    public const PROCESS_QUICK    = 'quick';
    public const PROCESS_STANDARD = 'standard';
    public const PROCESS_STRICT   = 'strict';

    // ── Risk-level → minimum adjusted threshold ──────────────────────────────
    public const THRESHOLD_FLOOR = [
        self::LEVEL_LOW      => 0.0,   // keep user threshold
        self::LEVEL_MEDIUM   => 0.60,
        self::LEVEL_HIGH     => 0.70,
        self::LEVEL_CRITICAL => 0.80,
    ];

    public readonly string $riskLevel;
    public readonly string $reversibility;
    /** @var list<string> */
    public readonly array  $riskCategories;
    public readonly string $estimatedErrorCost;
    public readonly float  $recommendedThreshold;
    public readonly string $requiredProcess;
    /** @var list<string> */
    public readonly array  $recommendations;

    /**
     * @param list<string> $riskCategories
     * @param list<string> $recommendations
     */
    public function __construct(
        string $riskLevel,
        string $reversibility,
        array  $riskCategories,
        string $estimatedErrorCost,
        float  $recommendedThreshold,
        string $requiredProcess,
        array  $recommendations
    ) {
        $this->riskLevel            = $riskLevel;
        $this->reversibility        = $reversibility;
        $this->riskCategories       = $riskCategories;
        $this->estimatedErrorCost   = $estimatedErrorCost;
        $this->recommendedThreshold = $recommendedThreshold;
        $this->requiredProcess      = $requiredProcess;
        $this->recommendations      = $recommendations;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'risk_level'            => $this->riskLevel,
            'reversibility'         => $this->reversibility,
            'risk_categories'       => $this->riskCategories,
            'estimated_error_cost'  => $this->estimatedErrorCost,
            'recommended_threshold' => $this->recommendedThreshold,
            'required_process'      => $this->requiredProcess,
            'recommendations'       => $this->recommendations,
        ];
    }
}

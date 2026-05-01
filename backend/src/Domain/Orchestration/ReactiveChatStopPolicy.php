<?php
namespace Domain\Orchestration;

/**
 * Deterministic early-stop policy for Reactive Chat.
 *
 * Criteria (all require turn_index >= turns_min):
 *  1. Average confidence across reactor + primary messages >= threshold.
 *  2. No new arguments from any reactor for >= no_new_arguments_threshold consecutive cycles.
 *
 * Parsers are intentionally simple and robust: they scan for specific
 * Markdown section headers and extract the first value on the following line.
 */
class ReactiveChatStopPolicy
{
    private int   $turnsMin;
    private int   $turnsMax;
    private bool  $earlyStopEnabled;
    private float $confidenceThreshold;
    private int   $noNewArgsThreshold;

    /** @param array{turns_min:int, turns_max:int, early_stop_enabled:bool, early_stop_confidence_threshold:float, no_new_arguments_threshold:int} $config */
    public function __construct(array $config)
    {
        $this->turnsMin             = max(1, min(10, (int)($config['turns_min'] ?? 2)));
        $this->turnsMax             = max(2, min(10, (int)($config['turns_max'] ?? 4)));
        $this->earlyStopEnabled     = (bool)($config['early_stop_enabled'] ?? true);
        $this->confidenceThreshold  = (float)($config['early_stop_confidence_threshold'] ?? 0.85);
        $this->noNewArgsThreshold   = max(1, min(5, (int)($config['no_new_arguments_threshold'] ?? 2)));
    }

    /**
     * Should the loop stop after this turn?
     *
     * @param int   $turnIndex       1-based current turn number
     * @param array $turnMessages    All messages produced in this turn (reactor + primary response)
     * @param array $allTurnsHistory Array of prior turn message arrays (for no-new-args streak)
     * @return array{stop:bool, reason:string|null}
     */
    public function shouldStop(int $turnIndex, array $turnMessages, array $allTurnsHistory): array
    {
        if ($turnIndex >= $this->turnsMax) {
            return ['stop' => true, 'reason' => 'max_turns_reached'];
        }

        if (!$this->earlyStopEnabled || $turnIndex < $this->turnsMin) {
            return ['stop' => false, 'reason' => null];
        }

        // --- Criterion 1: average confidence ---
        $confidences = [];
        foreach ($turnMessages as $msg) {
            $c = $this->parseConfidence($msg['content'] ?? '');
            if ($c !== null) $confidences[] = $c;
        }
        if (!empty($confidences)) {
            $avg = array_sum($confidences) / count($confidences);
            if ($avg >= $this->confidenceThreshold) {
                return ['stop' => true, 'reason' => 'confidence_threshold_reached'];
            }
        }

        // --- Criterion 2: no new arguments streak ---
        // Build recent history: combine past turns + current turn messages
        $allTurns   = array_merge($allTurnsHistory, [$turnMessages]);
        $recentTurns = array_slice($allTurns, -$this->noNewArgsThreshold);
        if (count($recentTurns) >= $this->noNewArgsThreshold) {
            $allNoNew = true;
            foreach ($recentTurns as $turnMsgs) {
                $hasNew = false;
                foreach ($turnMsgs as $msg) {
                    if (($msg['reaction_role'] ?? '') === 'reactor') {
                        $newArg = $this->parseNewArgument($msg['content'] ?? '');
                        if ($newArg !== false) {  // true or null (unknown) → assume has new
                            $hasNew = true;
                            break;
                        }
                    }
                }
                if ($hasNew) { $allNoNew = false; break; }
            }
            if ($allNoNew) {
                return ['stop' => true, 'reason' => 'no_new_arguments'];
            }
        }

        return ['stop' => false, 'reason' => null];
    }

    /** Extract ## Confidence value (0.0–1.0) from message content. */
    public function parseConfidence(string $content): ?float
    {
        if (preg_match('/##\s*Confidence\s*\n+\s*([0-9]+(?:\.[0-9]+)?)/i', $content, $m)) {
            $v = (float)$m[1];
            return ($v >= 0.0 && $v <= 1.0) ? $v : null;
        }
        return null;
    }

    /**
     * Extract ## New Argument yes|no.
     * Returns true = yes, false = no, null = not found.
     */
    public function parseNewArgument(string $content): ?bool
    {
        if (preg_match('/##\s*New\s*Argument\s*\n+\s*(yes|no)/i', $content, $m)) {
            return strtolower(trim($m[1])) === 'yes';
        }
        return null;
    }
}

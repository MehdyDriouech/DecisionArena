<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

final class VectorMath
{
    /** @param list<float> $a @param list<float> $b */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $va = (float)$a[$i];
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        $den = sqrt(max(1e-12, $na)) * sqrt(max(1e-12, $nb));
        return $den > 0 ? ($dot / $den) : 0.0;
    }
}


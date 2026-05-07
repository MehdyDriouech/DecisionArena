<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

/**
 * Deterministic, dependency-free embedding provider for tests/local dev.
 * Not a semantic engine: produces stable vectors from text hashing.
 */
final class DeterministicFakeEmbeddingProvider implements MemoryEmbeddingProviderInterface
{
    private int $dims;
    private ?string $failOnSubstring;

    /** @param array{dimensions?:int,fail_on_substring?:string|null} $options */
    public function __construct(array $options = [])
    {
        $this->dims = max(8, (int)($options['dimensions'] ?? 48));
        $this->failOnSubstring = isset($options['fail_on_substring']) ? (string)$options['fail_on_substring'] : null;
    }

    public function providerName(): string { return 'deterministic_fake'; }
    public function modelName(): string { return 'hash-v1'; }
    public function dimensions(): int { return $this->dims; }
    public function embeddingVersion(): string { return '1'; }

    /** @return list<float> */
    public function embed(string $text): array
    {
        if ($this->failOnSubstring !== null && $this->failOnSubstring !== '' && str_contains($text, $this->failOnSubstring)) {
            throw new \RuntimeException('fake_embedding_provider_failure');
        }

        $seed = hash('sha256', $text, true); // 32 bytes
        $vec = array_fill(0, $this->dims, 0.0);

        // Fill with pseudo-random but deterministic values based on rolling hash bytes.
        $buf = $seed;
        $pos = 0;
        for ($i = 0; $i < $this->dims; $i++) {
            if ($pos + 4 > strlen($buf)) {
                $buf = hash('sha256', $buf, true);
                $pos = 0;
            }
            $b0 = ord($buf[$pos]); $b1 = ord($buf[$pos + 1]); $b2 = ord($buf[$pos + 2]); $b3 = ord($buf[$pos + 3]);
            $pos += 4;
            $u32 = (($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3) & 0xffffffff;
            // Map to [-1, 1] deterministically.
            $vec[$i] = ((($u32 / 0xffffffff) * 2.0) - 1.0);
        }

        // L2 normalize for stable cosine.
        $norm = 0.0;
        foreach ($vec as $v) $norm += $v * $v;
        $norm = sqrt(max(1e-12, $norm));
        foreach ($vec as $i => $v) $vec[$i] = $v / $norm;

        return $vec;
    }
}


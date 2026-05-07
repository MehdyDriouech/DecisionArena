<?php
declare(strict_types=1);

namespace Domain\DecisionMemory;

interface MemoryEmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text): array;

    public function providerName(): string;
    public function modelName(): string;
    public function dimensions(): int;

    /**
     * Bump this when the provider’s embedding algorithm changes (even if model name stays same).
     * Used to avoid mixing vectors across versions.
     */
    public function embeddingVersion(): string;
}


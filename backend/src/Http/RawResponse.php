<?php
declare(strict_types=1);

namespace Http;

/**
 * Raw (non-JSON) HTTP response payload.
 * Used for text/markdown projections such as memory.md snapshots.
 */
final class RawResponse
{
    public function __construct(
        public readonly string $body,
        public readonly string $contentType = 'text/plain; charset=utf-8',
        public readonly int $statusCode = 200
    ) {}
}


<?php
namespace Domain\Agents;

class Soul {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $content,
        public readonly array $meta
    ) {}
}

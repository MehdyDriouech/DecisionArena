<?php
namespace Domain\Agents;

class Persona {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $title,
        public readonly string $icon,
        public readonly string $content,
        public readonly array $meta
    ) {}
}

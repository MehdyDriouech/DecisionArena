<?php
namespace Domain\Agents;

class Agent {
    public function __construct(
        public readonly string $id,
        public readonly Persona $persona,
        public readonly ?Soul $soul,
        public readonly ?string $providerId,
        public readonly ?string $model
    ) {}
}

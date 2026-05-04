<?php
namespace Domain\Agents;

class Agent {
    /**
     * @param array{reputation:float,consensus_resistance:string,evidence_sensitivity:string,risk_tolerance:string} $decisionDynamics
     */
    public function __construct(
        public readonly string $id,
        public readonly Persona $persona,
        public readonly ?Soul $soul,
        public readonly ?string $providerId,
        public readonly ?string $model,
        public readonly array $decisionDynamics = []
    ) {}

    /** @param array<string,mixed> $dynamics */
    public function withDecisionDynamics(array $dynamics): self {
        return new self(
            $this->id,
            $this->persona,
            $this->soul,
            $this->providerId,
            $this->model,
            DecisionDynamics::normalize($dynamics)
        );
    }
}

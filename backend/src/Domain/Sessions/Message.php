<?php
namespace Domain\Sessions;

class Message {
    public function __construct(
        public readonly string $id,
        public readonly string $sessionId,
        public readonly string $role,
        public readonly ?string $agentId,
        public readonly ?string $providerId,
        public readonly ?string $model,
        public readonly ?int $round,
        public readonly string $content,
        public readonly string $createdAt
    ) {}

    public static function fromArray(array $data): self {
        return new self(
            id: $data['id'],
            sessionId: $data['session_id'],
            role: $data['role'],
            agentId: $data['agent_id'] ?? null,
            providerId: $data['provider_id'] ?? null,
            model: $data['model'] ?? null,
            round: isset($data['round']) ? (int)$data['round'] : null,
            content: $data['content'],
            createdAt: $data['created_at']
        );
    }
}

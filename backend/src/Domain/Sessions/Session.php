<?php
namespace Domain\Sessions;

class Session {
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $mode,
        public readonly string $initialPrompt,
        public readonly array $selectedAgents,
        public readonly int $rounds,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {}

    public static function fromArray(array $data): self {
        return new self(
            id: $data['id'],
            title: $data['title'],
            mode: $data['mode'] ?? 'chat',
            initialPrompt: $data['initial_prompt'] ?? '',
            selectedAgents: is_array($data['selected_agents'])
                ? $data['selected_agents']
                : json_decode($data['selected_agents'] ?? '[]', true),
            rounds: (int)($data['rounds'] ?? 2),
            createdAt: $data['created_at'],
            updatedAt: $data['updated_at']
        );
    }
}

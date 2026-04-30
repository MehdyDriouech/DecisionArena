<?php
namespace Domain\Orchestration;

class MentionDetector {
    public function detect(string $message, array $availableAgents): array {
        $mentioned = [];
        foreach ($availableAgents as $agentId) {
            $quoted = preg_quote((string)$agentId, '/');
            // Match exact @agent-id token, case-insensitive, avoiding substring collisions.
            if (preg_match('/(^|[^\w-])@' . $quoted . '(?=$|[^\w-])/i', $message) === 1) {
                $mentioned[] = $agentId;
            }
        }
        return array_values(array_unique($mentioned));
    }
}

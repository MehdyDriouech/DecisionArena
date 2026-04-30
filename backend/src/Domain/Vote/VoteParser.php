<?php
namespace Domain\Vote;

class VoteParser {
    private const ALLOWED_VOTES = ['go', 'no-go', 'reduce-scope', 'needs-more-info', 'pivot'];

    public function parse(string $content): ?array {
        $vote = $this->parseTextValue($content, ['vote']);
        if (!$vote) {
            return null;
        }

        $vote = $this->normalizeVote($vote);
        if (!in_array($vote, self::ALLOWED_VOTES, true)) {
            error_log('[VoteParser] Invalid vote value: ' . $vote);
            return null;
        }

        $confidence = $this->parseScaleValue($content, ['confidence'], 5);
        $impact = $this->parseScaleValue($content, ['impact'], 5);
        $domainWeight = $this->parseScaleValue($content, ['domain weight', 'domain_weight'], 5);
        $rationale = $this->parseTextValue($content, ['rationale']) ?? '';
        $weightScore = round(($confidence + $impact + $domainWeight) / 3, 2);

        return [
            'vote' => $vote,
            'confidence' => $confidence,
            'impact' => $impact,
            'domain_weight' => $domainWeight,
            'weight_score' => $weightScore,
            'rationale' => $this->truncate($rationale, 500),
        ];
    }

    private function parseTextValue(string $content, array $labels): ?string {
        foreach ($labels as $label) {
            $escaped = preg_quote($label, '/');
            if (preg_match('/(?:^|\n)\s*(?:##\s*)?' . $escaped . '\s*\n+([^\n#][^\n]*)/i', $content, $m)) {
                $value = trim($m[1]);
                if ($value !== '') return $value;
            }
            if (preg_match('/' . $escaped . '\s*:\s*([^\n]+)/i', $content, $m)) {
                $value = trim($m[1]);
                if ($value !== '') return $value;
            }
        }
        return null;
    }

    private function parseScaleValue(string $content, array $labels, int $default): int {
        foreach ($labels as $label) {
            $escaped = preg_quote($label, '/');
            if (preg_match('/' . $escaped . '\s*:\s*(\d{1,2})/i', $content, $m) ||
                preg_match('/(?:^|\n)\s*(?:##\s*)?' . $escaped . '\s*\n+\s*(\d{1,2})\b/i', $content, $m)) {
                return min(10, max(0, (int)$m[1]));
            }
        }
        return min(10, max(0, $default));
    }

    private function normalizeVote(string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace('_', '-', $value);
        if (str_contains($value, 'no-go')) return 'no-go';
        if (str_contains($value, 'reduce')) return 'reduce-scope';
        if (str_contains($value, 'needs-more-info')) return 'needs-more-info';
        if (str_contains($value, 'need more')) return 'needs-more-info';
        if (str_contains($value, 'pivot')) return 'pivot';
        if (str_contains($value, 'go')) return 'go';
        return $value;
    }

    private function truncate(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text, 'UTF-8') <= $max) return $text;
        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
}

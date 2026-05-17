<?php
namespace Domain\Demo;

use Infrastructure\Persistence\DemoQuotaRepository;

final class DemoQuotaService {
    public static function usageDateUtc(): string {
        return gmdate('Y-m-d');
    }

    public static function getStatus(?string $userId): array {
        $limit = DemoAuthService::dailyQuotaForUser($userId);
        if ($userId === null || $limit <= 0) {
            return [
                'daily_limit' => $limit,
                'used_today' => 0,
                'remaining' => 0,
            ];
        }
        $repo = new DemoQuotaRepository();
        $used = $repo->getCount($userId, self::usageDateUtc());
        return [
            'daily_limit' => $limit,
            'used_today' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public static function assertHasQuota(?string $userId): void {
        $status = self::getStatus($userId);
        if (($status['remaining'] ?? 0) <= 0) {
            throw new DemoHttpException(
                'Daily demo LLM quota exceeded',
                429,
                'daily_quota_exceeded'
            );
        }
    }

    public static function consumeOne(?string $userId): void {
        if ($userId === null || $userId === '') {
            return;
        }
        $repo = new DemoQuotaRepository();
        $repo->increment($userId, self::usageDateUtc());
    }
}

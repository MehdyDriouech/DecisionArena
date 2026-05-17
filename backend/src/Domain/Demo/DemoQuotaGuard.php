<?php
namespace Domain\Demo;

use Domain\Providers\RuntimeBillingContext;
use Http\Request;

/**
 * billing_mode: exempt | byok | server
 */
final class DemoQuotaGuard {
    public static function beginRun(Request $req): string {
        if (!DemoAuthService::isDemoMode()) {
            return 'exempt';
        }
        if (DemoAuthService::isDemoAuthRequired()) {
            DemoAuthService::requireAuth();
        }
        if (RuntimeBillingContext::usesAnyByok()) {
            return 'byok';
        }
        DemoQuotaService::assertHasQuota(DemoAuthService::currentUserId());
        return 'server';
    }

    public static function completeRun(string $billingMode, bool $success): void {
        if (!DemoAuthService::isDemoMode() || $billingMode !== 'server' || !$success) {
            return;
        }
        DemoQuotaService::consumeOne(DemoAuthService::currentUserId());
    }
}

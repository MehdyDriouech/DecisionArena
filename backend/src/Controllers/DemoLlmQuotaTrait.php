<?php
namespace Controllers;

use Domain\Demo\DemoQuotaGuard;
use Http\Request;

trait DemoLlmQuotaTrait {
    private string $demoBillingMode = 'exempt';

    protected function beginDemoLlmRun(Request $req): void {
        $this->demoBillingMode = DemoQuotaGuard::beginRun($req);
    }

    protected function completeDemoLlmRun(bool $success): void {
        DemoQuotaGuard::completeRun($this->demoBillingMode, $success);
    }

    /** @template T */
    protected function runWithDemoQuota(Request $req, callable $fn) {
        $this->beginDemoLlmRun($req);
        $ok = false;
        try {
            $result = $fn();
            $ok = true;
            return $result;
        } finally {
            $this->completeDemoLlmRun($ok);
        }
    }
}

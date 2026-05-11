<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\CognitiveGovernance\RuntimeWriteGuard;

final class RuntimeAwarePdoStatement extends \PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        RuntimeWriteGuard::inspectSql((string)$this->queryString, 'pdo_statement::execute');

        return parent::execute($params);
    }
}


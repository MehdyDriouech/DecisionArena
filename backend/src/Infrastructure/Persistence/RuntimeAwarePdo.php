<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\CognitiveGovernance\RuntimeWriteGuard;

final class RuntimeAwarePdo extends \PDO
{
    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RuntimeAwarePdoStatement::class]);
    }

    public function exec(string $statement): int|false
    {
        RuntimeWriteGuard::inspectSql($statement, 'pdo::exec');

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        RuntimeWriteGuard::inspectSql($query, 'pdo::query');
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        RuntimeWriteGuard::inspectSql($query, 'pdo::prepare');

        return parent::prepare($query, $options);
    }
}


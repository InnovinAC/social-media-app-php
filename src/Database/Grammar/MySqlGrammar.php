<?php

declare(strict_types=1);

namespace Phpvin\Database\Grammar;

final class MySqlGrammar extends Grammar
{
    protected function delimiter(): string
    {
        return '`';
    }

    /**
     * MySQL performs an implicit commit on DDL, so a migration that fails
     * partway through cannot be undone. Nothing the framework can fix, but
     * it can stop pretending otherwise.
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    /**
     * MySQL has no NULLS FIRST/LAST, so the placement is expressed as an extra
     * sort key: `col IS NULL` is 0 for a value and 1 for a null, which puts
     * nulls last ascending and first descending.
     */
    public function compileOrder(string $quotedColumn, string $direction, ?string $nulls): string
    {
        $term = $quotedColumn . ' ' . $direction;

        if ($nulls === null) {
            return $term;
        }

        return $quotedColumn . ' IS NULL ' . ($nulls === 'first' ? 'DESC' : 'ASC') . ', ' . $term;
    }

    /**
     * GET_LOCK is held by the session and released when it ends, so a killed
     * deploy cannot leave the lock behind. It returns 1 on success and 0 on
     * timeout, which the caller checks.
     */
    public function migrationLock(): MigrationLock
    {
        return new MigrationLock(
            acquire: "SELECT GET_LOCK('phpvin_migrations', 10) AS acquired",
            release: "SELECT RELEASE_LOCK('phpvin_migrations') AS released",
        );
    }
}

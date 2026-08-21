<?php

declare(strict_types=1);

namespace Phpvin\Database\Grammar;

final class PostgresGrammar extends Grammar
{
    protected function delimiter(): string
    {
        return '"';
    }

    /**
     * lastInsertId() on pgsql falls back to lastval(), which is per-session
     * and breaks the moment a trigger touches another sequence. RETURNING is
     * exact, so it is used instead.
     */
    public function supportsReturning(): bool
    {
        return true;
    }

    /** Postgres accepts OFFSET on its own. */
    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = $limit === null ? '' : ' LIMIT ' . $limit;

        return $offset === null ? $sql : $sql . ' OFFSET ' . $offset;
    }

    /**
     * Postgres advisory locks are taken on a bigint rather than a name and are
     * released when the session ends. The non-blocking form is used with the
     * caller's retry loop, so a stuck holder produces a clear timeout rather
     * than a deploy that hangs forever.
     */
    public function migrationLock(): MigrationLock
    {
        return new MigrationLock(
            acquire: 'SELECT pg_try_advisory_lock(3141592653) AS acquired',
            release: 'SELECT pg_advisory_unlock(3141592653) AS released',
        );
    }
}

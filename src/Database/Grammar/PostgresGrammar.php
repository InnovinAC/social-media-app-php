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
}

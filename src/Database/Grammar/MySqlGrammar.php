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
}

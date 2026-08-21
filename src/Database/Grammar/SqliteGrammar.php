<?php

declare(strict_types=1);

namespace Phpvin\Database\Grammar;

final class SqliteGrammar extends Grammar
{
    protected function delimiter(): string
    {
        return '`';
    }
}

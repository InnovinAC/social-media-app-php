<?php

declare(strict_types=1);

namespace Phpvin\Database;

use RuntimeException;

class RecordNotFound extends RuntimeException
{
    public static function for(string $model, mixed $id): self
    {
        return new self(sprintf('No %s found with key [%s].', $model, var_export($id, true)));
    }
}

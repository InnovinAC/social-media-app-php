<?php

declare(strict_types=1);

namespace Phpvin\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self("Nothing bound to [$id] and no such class exists.");
    }
}

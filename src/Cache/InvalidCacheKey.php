<?php

declare(strict_types=1);

namespace Phpvin\Cache;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgument;

final class InvalidCacheKey extends InvalidArgumentException implements PsrInvalidArgument
{
    public static function empty(): self
    {
        return new self('A cache key cannot be empty.');
    }

    public static function reserved(string $key, string $reserved): self
    {
        return new self(sprintf(
            'The cache key [%s] uses a character PSR-16 reserves (%s).',
            $key,
            $reserved,
        ));
    }
}

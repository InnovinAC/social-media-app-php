<?php

declare(strict_types=1);

namespace Phpvin\Cache;

use DateInterval;
use DateTimeImmutable;

/**
 * The bits of PSR-16 that every store has to get right the same way.
 */
final class Ttl
{
    /** Characters PSR-16 reserves; a key containing one is invalid. */
    private const RESERVED = '{}()/\@:';

    /**
     * Normalise a TTL to whole seconds, or null for "keep it".
     */
    public static function seconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        // Via a real date, so months and years come out right rather than
        // being guessed at 30 and 365 days.
        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    /**
     * @throws InvalidCacheKey when the key is empty or uses a reserved character
     */
    public static function validateKey(string $key): void
    {
        if ($key === '') {
            throw InvalidCacheKey::empty();
        }

        if (strpbrk($key, self::RESERVED) !== false) {
            throw InvalidCacheKey::reserved($key, self::RESERVED);
        }
    }
}

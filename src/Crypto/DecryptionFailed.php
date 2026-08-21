<?php

declare(strict_types=1);

namespace Phpvin\Crypto;

use RuntimeException;

/**
 * A payload could not be decrypted.
 *
 * Deliberately says nothing about which check failed. "Bad MAC" and "bad
 * padding" are the difference between a working padding oracle and a useless
 * one, so callers get one indistinguishable answer.
 */
final class DecryptionFailed extends RuntimeException
{
    public static function payload(): self
    {
        return new self('The payload could not be decrypted.');
    }
}

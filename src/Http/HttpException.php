<?php

declare(strict_types=1);

namespace Phpvin\Http;

use RuntimeException;
use Throwable;

/**
 * Thrown to abort a request with a specific status code. The Application
 * turns it into a Response; nothing else needs to know about it.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function unauthorised(string $message = ''): self
    {
        return new self(401, $message);
    }

    public static function pageExpired(string $message = ''): self
    {
        return new self(419, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorised',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            default => 'HTTP Error',
        };
    }
}

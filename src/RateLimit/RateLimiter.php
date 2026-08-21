<?php

declare(strict_types=1);

namespace Phpvin\RateLimit;

use RuntimeException;

/**
 * Counts attempts against a key within a rolling window.
 *
 * File-backed, because that works everywhere PHP does. Swap in Redis or APCu
 * by implementing this class's small surface and binding your version over it
 * in the container.
 *
 *     if ($limiter->tooManyAttempts("login:$email", 5)) {
 *         // locked out for $limiter->availableIn("login:$email") seconds
 *     }
 *
 *     $limiter->hit("login:$email", decaySeconds: 900);
 *     $limiter->clear("login:$email");   // on success
 */
class RateLimiter
{
    public function __construct(private readonly string $storagePath) {}

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Record an attempt and return the running total.
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        // Read, add one, write -- and if two requests interleave between the
        // read and the write, both see the same count and both store the same
        // increment, so one of the attempts never happened. That turns "five
        // tries a minute" into "as many as you can open connections", which is
        // the one thing a rate limiter exists to prevent, and it shows up only
        // under exactly the concurrency an attacker supplies on purpose.
        //
        // The whole read-modify-write is held under one exclusive lock. `c+`
        // creates the file if it is missing and does *not* truncate it, so
        // taking the lock cannot itself destroy the count it is protecting.
        $path = $this->pathFor($key);

        $this->ensureDirectory(dirname($path));

        $handle = @fopen($path, 'c+');

        if ($handle === false) { // mutation:ignore the directory was just created or the open would have thrown
            throw new RuntimeException("Could not open the rate limit record at [$path].");
        }

        try {
            flock($handle, LOCK_EX);

            $record = $this->decode((string) stream_get_contents($handle));
            $now = time();

            if ($record === null || $record['expires'] <= $now) {
                $record = ['count' => 0, 'expires' => $now + $decaySeconds];
            }

            $record['count']++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($record));
            fflush($handle);

            return $record['count'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function attempts(string $key): int
    {
        $record = $this->read($key);

        return $record === null || $record['expires'] <= time() ? 0 : $record['count'];
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /**
     * Seconds until the window resets. Zero when nothing is being held.
     */
    public function availableIn(string $key): int
    {
        $record = $this->read($key);

        return $record === null ? 0 : max(0, $record['expires'] - time());
    }

    public function clear(string $key): void
    {
        @unlink($this->pathFor($key));
    }

    /**
     * @return array{count: int, expires: int}|null
     */
    private function read(string $key): ?array
    {
        $contents = @file_get_contents($this->pathFor($key));

        return $contents === false ? null : $this->decode($contents);
    }

    /**
     * @return array{count: int, expires: int}|null
     */
    private function decode(string $contents): ?array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['count'], $decoded['expires'])) {
            return null;
        }

        return ['count' => (int) $decoded['count'], 'expires' => (int) $decoded['expires']];
    }

    private function ensureDirectory(string $directory): void
    {
        // Trailing is_dir() covers a concurrent create; unreachable by test.
        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) { // mutation:ignore race guard
            throw new RuntimeException("Could not create the rate limit directory [$directory].");
        }
    }

    /**
     * Keys are hashed, so an email address or IP never lands on disk as a
     * filename, and no key can contain a path separator.
     */
    private function pathFor(string $key): string
    {
        return rtrim($this->storagePath, '/') . '/' . hash('xxh128', $key) . '.json';
    }
}

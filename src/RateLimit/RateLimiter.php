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
        $record = $this->read($key);
        $now = time();

        if ($record === null || $record['expires'] <= $now) {
            $record = ['count' => 0, 'expires' => $now + $decaySeconds];
        }

        $record['count']++;

        $this->write($key, $record);

        return $record['count'];
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

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['count'], $decoded['expires'])) {
            return null;
        }

        return ['count' => (int) $decoded['count'], 'expires' => (int) $decoded['expires']];
    }

    /**
     * @param array{count: int, expires: int} $record
     */
    private function write(string $key, array $record): void
    {
        $path = $this->pathFor($key);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the rate limit directory [$directory].");
        }

        @file_put_contents($path, json_encode($record), LOCK_EX);
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
